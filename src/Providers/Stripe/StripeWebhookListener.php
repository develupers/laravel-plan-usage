<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Providers\Stripe;

use Develupers\PlanUsage\Actions\Subscription\DeleteSubscriptionAction;
use Develupers\PlanUsage\Actions\Subscription\SyncPlanWithBillableAction;
use Develupers\PlanUsage\Support\SubscriptionStateLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;

/**
 * Handles Stripe webhook events for plan synchronization.
 *
 * Stripe does not guarantee delivery order, so the payload is treated only as
 * a trigger: the subscription's CURRENT state is re-fetched from the Stripe
 * API inside the shared per-billable lock, and status + price are derived
 * from that authoritative response. Any event therefore converges the local
 * plan to remote truth, regardless of delivery order.
 *
 * Only the configured default-type subscription controls entitlements —
 * events for other named subscriptions are deliberately ignored.
 */
class StripeWebhookListener
{
    /**
     * How long to wait for the shared subscription-state lock.
     */
    protected int $lockWaitSeconds = 10;

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

        if (! $this->shouldHandle($payload['type'] ?? '')) {
            return;
        }

        // Deduplicate webhook events using Stripe event ID
        $eventId = $payload['id'] ?? null;
        $dedupeKey = $eventId ? "plan-usage:webhook:stripe:{$eventId}" : null;
        if ($dedupeKey && ! Cache::add($dedupeKey, true, 3600)) {
            Log::debug('Skipping duplicate Stripe webhook event', ['event_id' => $eventId]);

            return;
        }

        try {
            $this->handleSubscriptionEvent($payload);
        } catch (\Throwable $e) {
            Log::error('Failed to sync plan from Stripe webhook', [
                'error' => $e->getMessage(),
                'event_type' => $payload['type'],
                'payload' => $payload,
            ]);

            // Release the dedupe key and rethrow: swallowing returns HTTP 200,
            // so Stripe would never redeliver and a transient failure would
            // permanently lose the sync (until reconciliation).
            if ($dedupeKey) {
                Cache::forget($dedupeKey);
            }

            throw $e;
        }
    }

    /**
     * Determine if this event should be handled.
     */
    private function shouldHandle(string $eventType): bool
    {
        return in_array($eventType, [
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
        ]);
    }

    /**
     * Converge the billable's plan to the subscription's current remote state.
     */
    private function handleSubscriptionEvent(array $payload): void
    {
        $subscription = $payload['data']['object'] ?? [];

        if (! is_array($subscription) || empty($subscription)) {
            Log::warning('Invalid or empty subscription object in Stripe webhook');

            return;
        }

        $customerId = $subscription['customer'] ?? null;
        $subscriptionId = $subscription['id'] ?? null;

        if (! is_string($customerId) || $customerId === '' || ! is_string($subscriptionId) || $subscriptionId === '') {
            Log::warning('Invalid customer ID or subscription ID in Stripe webhook', [
                'customer_id' => $customerId,
                'subscription_id' => $subscriptionId,
            ]);

            return;
        }

        $billable = $this->findBillableByStripeId($customerId);

        if (! $billable) {
            Log::warning('No billable found for Stripe customer', [
                'customer_id' => $customerId,
            ]);

            return;
        }

        // Only the default-type subscription controls the plan. Without this,
        // an event for any other named subscription (e.g. an add-on) would
        // overwrite the billable's plan from that subscription's price.
        $subscriptionName = config('plan-usage.subscription.default_name', 'default');
        $localSubscription = $billable->subscription($subscriptionName);

        if ($localSubscription === null || $localSubscription->stripe_id !== $subscriptionId) {
            Log::info('Ignoring Stripe webhook for a non-default subscription', [
                'billable_id' => $billable->getKey(),
                'subscription_id' => $subscriptionId,
                'expected_subscription_id' => $localSubscription->stripe_id ?? null,
            ]);

            return;
        }

        // Serialize with plan changes, cancellation, and other webhook
        // deliveries for this billable: without the lock, a fast webhook can
        // interleave with ChangeSubscriptionPlanAction and replace a prorated
        // allowance with the full target-plan allowance.
        $this->stateLock->block($billable, function () use ($billable, $subscriptionId): void {
            $remote = $this->fetchStripeSubscription($subscriptionId);
            $status = (string) ($remote['status'] ?? '');
            $priceId = $remote['price_id'] ?? null;

            // Entitlement policy by CURRENT remote status:
            // - active/trialing        → grant (sync plan from remote price)
            // - past_due               → configurable; kept by default
            // - incomplete             → never granted; wait for payment outcome
            // - anything else          → revoke (canceled, unpaid,
            //                            incomplete_expired, paused)
            if (in_array($status, ['active', 'trialing'], true)) {
                if (is_string($priceId) && $priceId !== '') {
                    $this->syncPlanWithBillable->execute($billable, $priceId);
                }

                return;
            }

            if ($status === 'past_due' && config('plan-usage.stripe.past_due_keeps_entitlements', true)) {
                return;
            }

            if ($status === 'incomplete') {
                return;
            }

            $this->deleteSubscription->execute($billable);
        }, $this->lockWaitSeconds);
    }

    /**
     * Fetch the subscription's current state from the Stripe API.
     *
     * @return array{status: string, price_id: string|null}
     */
    protected function fetchStripeSubscription(string $subscriptionId): array
    {
        $subscription = Cashier::stripe()->subscriptions->retrieve($subscriptionId);

        return [
            'status' => (string) $subscription->status,
            'price_id' => $subscription->items->data[0]->price->id ?? null,
        ];
    }

    /**
     * Find a billable entity by Stripe customer ID.
     */
    private function findBillableByStripeId(string $stripeId)
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

        return $billableClass::where('stripe_id', $stripeId)->first();
    }
}
