<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Providers\Paddle;

use Develupers\PlanUsage\Actions\Subscription\DeleteSubscriptionAction;
use Develupers\PlanUsage\Actions\Subscription\SyncPlanWithBillableAction;
use Develupers\PlanUsage\Support\EntitlementStatusPolicy;
use Develupers\PlanUsage\Support\SubscriptionStateLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Paddle\Cashier;
use Laravel\Paddle\Events\WebhookHandled;
use Laravel\Paddle\Events\WebhookReceived;

/**
 * Handles Paddle webhook events for plan synchronization.
 *
 * Subscribes to WebhookHandled — fired AFTER Cashier Paddle has processed the
 * webhook — so the local subscription row exists for identity validation.
 * Cashier fires it for subscription created/updated/paused/canceled;
 * subscription.resumed has no Cashier handler, and a resume is covered by the
 * subscription.updated events Paddle emits alongside it.
 *
 * Paddle does not guarantee delivery order, so the payload is treated only as
 * a trigger: the subscription's CURRENT state is re-fetched from the Paddle
 * API inside the shared per-billable lock, and status + price are derived
 * from that authoritative response. Any event therefore converges the local
 * plan to remote truth, regardless of delivery order.
 *
 * Only the configured default-type subscription controls entitlements —
 * events for other named subscriptions are deliberately ignored.
 */
class PaddleWebhookListener
{
    /**
     * How long to wait for the shared subscription-state lock.
     */
    protected int $lockWaitSeconds = 10;

    /**
     * Create the event listener.
     */
    public function __construct(
        private SyncPlanWithBillableAction $syncPlanWithBillable,
        private DeleteSubscriptionAction $deleteSubscription,
        private SubscriptionStateLock $stateLock,
    ) {}

    /**
     * Handle the event.
     */
    public function handle(WebhookHandled $event): void
    {
        $payload = $event->payload;

        // Only handle subscription-related events
        if (! $this->shouldHandle($payload['event_type'] ?? '')) {
            return;
        }

        $this->process($payload);
    }

    /**
     * Cashier Paddle only dispatches WebhookHandled for events it has a
     * handler for — subscription.past_due has none, so it must be picked up
     * from WebhookReceived. Only Cashier-UNHANDLED events are accepted here;
     * everything else flows through handle() to avoid double-processing.
     */
    public function handleReceived(WebhookReceived $event): void
    {
        $payload = $event->payload;

        if (($payload['event_type'] ?? '') !== 'subscription.past_due') {
            return;
        }

        $this->process($payload);
    }

    /**
     * Deduplicate, converge, and keep failures retryable.
     */
    private function process(array $payload): void
    {
        $eventType = $payload['event_type'] ?? '';

        // Deduplicate webhook events using Paddle event ID
        $eventId = $payload['event_id'] ?? null;
        $dedupeKey = $eventId ? "plan-usage:webhook:paddle:{$eventId}" : null;
        if ($dedupeKey && ! Cache::add($dedupeKey, true, 3600)) {
            Log::debug('Skipping duplicate Paddle webhook event', ['event_id' => $eventId]);

            return;
        }

        try {
            $this->handleSubscriptionEvent($payload);
        } catch (\Throwable $e) {
            Log::error('Failed to sync plan from Paddle webhook', [
                'error' => $e->getMessage(),
                'event_type' => $eventType,
                'payload' => $payload,
            ]);

            // Release the dedupe key and rethrow: swallowing returns HTTP 200,
            // so Paddle would never redeliver and a transient failure would
            // permanently lose the sync (until reconciliation).
            if ($dedupeKey) {
                Cache::forget($dedupeKey);
            }

            throw $e;
        }
    }

    /**
     * Determine if this event should be handled.
     *
     * subscription.resumed is intentionally absent: Cashier Paddle has no
     * handler for it, so WebhookHandled never fires — the resume is picked up
     * by the accompanying subscription.updated events instead.
     */
    private function shouldHandle(string $eventType): bool
    {
        return in_array($eventType, [
            'subscription.created',
            'subscription.updated',
            'subscription.canceled',
            'subscription.paused',
        ]);
    }

    /**
     * Converge the billable's plan to the subscription's current remote state.
     */
    private function handleSubscriptionEvent(array $payload): void
    {
        $data = $payload['data'] ?? [];

        if (! is_array($data) || empty($data)) {
            Log::warning('Invalid or empty data object in Paddle webhook');

            return;
        }

        $customerId = $data['customer_id'] ?? null;
        $subscriptionId = $data['id'] ?? null;

        if (! is_string($customerId) || $customerId === '' || ! is_string($subscriptionId) || $subscriptionId === '') {
            Log::warning('Invalid customer ID or subscription ID in Paddle webhook', [
                'customer_id' => $customerId,
                'subscription_id' => $subscriptionId,
            ]);

            return;
        }

        $billable = $this->findBillableByPaddleId($customerId);

        if (! $billable) {
            Log::warning('No billable found for Paddle customer', [
                'customer_id' => $customerId,
            ]);

            return;
        }

        // Backfill the paddle_id column when the billable was resolved through
        // Cashier Paddle's customers table (e.g. the first webhook for a newly
        // created customer) so subsequent lookups hit the fast path.
        if (Schema::hasColumn($billable->getTable(), 'paddle_id') && empty($billable->paddle_id)) {
            $billable->paddle_id = $customerId;
            $billable->save();
        }

        $subscriptionName = config('plan-usage.subscription.default_name', 'default');

        // Checkout stamps the intended subscription type into custom_data —
        // an event that declares a different type can be skipped outright.
        $customType = $data['custom_data']['subscription_type'] ?? null;

        if (is_string($customType) && $customType !== $subscriptionName) {
            Log::info('Ignoring Paddle webhook for a non-default subscription type', [
                'billable_id' => $billable->getKey(),
                'subscription_type' => $customType,
            ]);

            return;
        }

        // Serialize with plan changes, cancellation, and other webhook
        // deliveries for this billable: without the lock, a fast webhook can
        // interleave with ChangeSubscriptionPlanAction and replace a prorated
        // allowance with the full target-plan allowance.
        $this->stateLock->block($billable, function () use ($billable, $subscriptionId, $subscriptionName): void {
            // Pre-lock Eloquent state may predate a concurrent plan change
            // that held this lock first — without a refresh, the same-plan
            // guard reads stale ids and quota sync can replace a prorated
            // allowance with the full target allowance.
            $billable->refresh();

            // Identity is validated INSIDE the lock, against a fresh read:
            // only the default-type subscription controls the plan, and by
            // the time the lock is held Cashier may have replaced it (an
            // event for the old subscription must not pass a stale check).
            $billable->unsetRelation('subscriptions');
            $localSubscription = $billable->subscription($subscriptionName);

            if ($localSubscription === null || $localSubscription->paddle_id !== $subscriptionId) {
                Log::info('Ignoring Paddle webhook for a non-default subscription', [
                    'billable_id' => $billable->getKey(),
                    'subscription_id' => $subscriptionId,
                    'expected_subscription_id' => $localSubscription->paddle_id ?? null,
                ]);

                return;
            }

            $remote = $this->fetchPaddleSubscription($subscriptionId);
            $status = (string) ($remote['status'] ?? '');
            $priceId = $remote['price_id'] ?? null;

            // Shared policy: grant on active/trialing, past_due configurable,
            // everything else revokes.
            $decision = EntitlementStatusPolicy::decide('paddle', $status);

            if ($decision === EntitlementStatusPolicy::GRANT) {
                if (is_string($priceId) && $priceId !== '') {
                    $this->syncPlanWithBillable->execute($billable, $priceId);
                }

                return;
            }

            if ($decision === EntitlementStatusPolicy::KEEP) {
                return;
            }

            $this->deleteSubscription->execute($billable);
        }, $this->lockWaitSeconds);
    }

    /**
     * Fetch the subscription's current state from the Paddle API.
     *
     * @return array{status: string, price_id: string|null}
     */
    protected function fetchPaddleSubscription(string $subscriptionId): array
    {
        $data = Cashier::api('GET', "subscriptions/{$subscriptionId}")->json('data') ?? [];

        return [
            'status' => (string) ($data['status'] ?? ''),
            'price_id' => $data['items'][0]['price']['id'] ?? null,
        ];
    }

    /**
     * Find a billable entity by Paddle customer ID.
     */
    private function findBillableByPaddleId(string $paddleId)
    {
        // Get the billable model class from config
        $billableClass = config('plan-usage.models.billable')
            ?? config('cashier.model');

        if (! $billableClass || ! class_exists($billableClass)) {
            Log::error('Billable model class not configured or does not exist', [
                'configured_class' => $billableClass,
            ]);

            return null;
        }

        // Fast path: a paddle_id column on the billable table itself.
        $table = (new $billableClass)->getTable();
        if (Schema::hasColumn($table, 'paddle_id')) {
            $billable = $billableClass::where('paddle_id', $paddleId)->first();

            if ($billable !== null) {
                return $billable;
            }
        }

        // Fallback: resolve through Cashier Paddle's polymorphic customers
        // table. Cashier Paddle writes paddle_id to its own customers table —
        // never to the billable — so the first webhook for a freshly created
        // customer can only be resolved this way.
        $billable = Cashier::findBillable($paddleId);

        if ($billable instanceof $billableClass) {
            return $billable;
        }

        return null;
    }
}
