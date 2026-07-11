<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Providers\Polar;

use Carbon\CarbonImmutable;
use Danestves\LaravelPolar\Events\WebhookHandled;
use Develupers\PlanUsage\Actions\Subscription\ConfirmPendingPlanChangeAction;
use Develupers\PlanUsage\Actions\Subscription\DeleteSubscriptionAction;
use Develupers\PlanUsage\Actions\Subscription\SyncPlanWithBillableAction;
use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Enums\Interval;
use Develupers\PlanUsage\Events\SubscriptionPlanChangeCancelled;
use Develupers\PlanUsage\Models\BillingWebhookEvent;
use Develupers\PlanUsage\Models\PlanPrice;
use Develupers\PlanUsage\Models\SubscriptionPlanChange;
use Develupers\PlanUsage\Support\EntitlementStatusPolicy;
use Develupers\PlanUsage\Support\ProviderSubscriptionChange;
use Develupers\PlanUsage\Support\SubscriptionStateLock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

class PolarWebhookListener
{
    public function __construct(
        private PolarProvider $provider,
        private SyncPlanWithBillableAction $syncPlanWithBillable,
        private DeleteSubscriptionAction $deleteSubscription,
        private ConfirmPendingPlanChangeAction $confirmPendingChange,
        private SubscriptionStateLock $stateLock,
    ) {}

    public function handle(WebhookHandled $event): void
    {
        $payload = $event->payload;
        $eventType = (string) ($payload['type'] ?? '');

        if (! $this->shouldHandle($eventType)) {
            return;
        }

        $data = $payload['data'] ?? null;

        if (! is_array($data) || ! is_string($data['id'] ?? null)) {
            Log::warning('Invalid Polar webhook payload', [
                'event_type' => $eventType,
            ]);

            return;
        }

        $providerEventId = $this->providerEventId($payload);
        $occurredAt = $this->occurredAt($payload);
        $billingEvent = $this->billingEvent($providerEventId, $eventType, $data, $occurredAt);

        if ($billingEvent->processed_at !== null || $billingEvent->ignored_at !== null) {
            return;
        }

        $billable = $this->findBillable($data);

        if ($billable === null) {
            Log::warning('No billable found for Polar subscription webhook', [
                'subscription_id' => $data['id'],
                'customer_id' => $data['customer_id'] ?? null,
                'event_type' => $eventType,
            ]);
            $billingEvent->update(['ignored_at' => now()]);

            return;
        }

        // Serialize against the plan-change/cancel actions and against other
        // webhook deliveries for the same billable: without this, a second
        // event can pass the out-of-order check while an earlier one is still
        // uncommitted, or a webhook can apply a pending change while the
        // action that created it is mid-flight.
        try {
            $this->stateLock->block($billable, function () use ($billingEvent, $billable, $eventType, $data, $occurredAt): void {
                // The state lock serializes every delivery for this billable
                // (a subscription/order lineage always resolves to a single
                // billable), so a fresh re-read replaces the previous
                // row-lock transaction. Nothing here may run inside an
                // ambient DB transaction: DeleteSubscriptionAction's
                // fail-closed ordering (the plan-clear commits before
                // cleanup) would otherwise be rolled back by an outer
                // rollback when cleanup fails — restoring the paid plan.
                $billingEvent = $billingEvent->fresh();

                if ($billingEvent === null
                    || $billingEvent->processed_at !== null
                    || $billingEvent->ignored_at !== null) {
                    return;
                }

                // Pre-lock Eloquent state may predate a concurrent plan
                // change or cancellation that held this lock first.
                $billable->refresh();

                if ($this->isOutOfOrder($data['id'], $occurredAt, $billingEvent->id, $eventType)) {
                    $billingEvent->update(['ignored_at' => now()]);

                    return;
                }

                if (str_starts_with($eventType, 'order.')) {
                    $this->processOrderEvent($billable, $data, $eventType, $billingEvent);

                    return;
                }

                // Only the configured default-type subscription controls the
                // plan: an add-on subscription's lifecycle must not revoke or
                // replace the main entitlement.
                $subscriptionName = config('plan-usage.subscription.default_name', 'default');
                $billable->unsetRelation('subscriptions');
                $localSubscription = $billable->subscription($subscriptionName);

                if ($localSubscription === null || $localSubscription->polar_id !== $data['id']) {
                    Log::info('Ignoring Polar webhook for a non-default subscription', [
                        'billable_id' => $billable->getKey(),
                        'subscription_id' => $data['id'],
                        'expected_subscription_id' => $localSubscription->polar_id ?? null,
                    ]);
                    $billingEvent->update(['ignored_at' => now()]);

                    return;
                }

                if ($eventType === 'subscription.revoked' || $eventType === 'subscription.paused') {
                    $this->deleteSubscription->execute($billable);
                    $this->cancelPendingChanges($data['id'], $billable);
                    $billingEvent->update(['processed_at' => now()]);

                    return;
                }

                // Shared policy on the subscription's status. Polar's
                // 'canceled' is a grace period (KEEP) — terminal revocation
                // arrives as subscription.revoked. Non-holding statuses
                // (incomplete, unpaid, past_due under the revoke policy)
                // REVOKE any stale plan instead of being silently ignored.
                $decision = EntitlementStatusPolicy::decide('polar', (string) ($data['status'] ?? ''));

                if ($decision === EntitlementStatusPolicy::REVOKE) {
                    $this->deleteSubscription->execute($billable);
                    $this->cancelPendingChanges($data['id'], $billable);
                    $billingEvent->update(['processed_at' => now()]);

                    return;
                }

                $this->syncPendingChange($billable, $data);

                if ($decision === EntitlementStatusPolicy::GRANT) {
                    $this->syncCurrentPlan($billable, $data, $eventType);
                }

                $billingEvent->update(['processed_at' => now(), 'last_error' => null]);
            });
        } catch (LockTimeoutException $exception) {
            $billingEvent->update(['last_error' => 'Timed out waiting for the subscription state lock.']);

            throw $exception;
        } catch (\Throwable $exception) {
            $billingEvent->update(['last_error' => $exception->getMessage()]);

            throw $exception;
        }
    }

    private function shouldHandle(string $eventType): bool
    {
        return in_array($eventType, [
            'subscription.created',
            'subscription.updated',
            'subscription.active',
            'subscription.canceled',
            'subscription.uncanceled',
            'subscription.past_due',
            'subscription.paused',
            'subscription.resumed',
            'subscription.revoked',
            // One-time (lifetime) purchases complete through order events —
            // Polar creates no subscription for ProductCreateOneTime products.
            'order.created',
            'order.updated',
            'order.paid',
            'order.refunded',
        ], true);
    }

    /**
     * Handle a one-time (lifetime) purchase order.
     *
     * @param  Model&Billable  $billable
     * @param  array<string, mixed>  $data
     */
    private function processOrderEvent(Model $billable, array $data, string $eventType, BillingWebhookEvent $billingEvent): void
    {
        // Orders attached to a subscription (creation, renewal, proration) are
        // fully handled by the subscription.* events — never assign from them.
        $billingReason = $data['billing_reason'] ?? null;

        if (! empty($data['subscription_id'])
            || (is_string($billingReason) && str_starts_with($billingReason, 'subscription_'))) {
            $billingEvent->update(['ignored_at' => now()]);

            return;
        }

        $status = (string) ($data['status'] ?? '');
        $planPrice = $this->planPriceByPolarProduct($data['product_id'] ?? null);

        // A fully refunded one-time purchase revokes the plan it granted.
        if ($status === 'refunded') {
            if ($planPrice !== null && (int) $billable->getAttribute('plan_price_id') === $planPrice->id) {
                $this->deleteSubscription->execute($billable);
            }

            $billingEvent->update(['processed_at' => now()]);

            return;
        }

        if ($status !== 'paid') {
            $billingEvent->update(['ignored_at' => now()]);

            return;
        }

        if ($planPrice === null) {
            Log::warning('No plan price found for Polar order product', [
                'product_id' => $data['product_id'] ?? null,
                'order_id' => $data['id'],
                'event_type' => $eventType,
            ]);
            $billingEvent->update(['ignored_at' => now()]);

            return;
        }

        // Only lifetime prices map to one-time Polar products; a paid order
        // for a recurring price is unexpected and must not assign the plan.
        if ($planPrice->interval !== Interval::LIFETIME) {
            Log::warning('Polar order product does not map to a lifetime plan price', [
                'product_id' => $data['product_id'],
                'order_id' => $data['id'],
                'interval' => $planPrice->interval->value,
            ]);
            $billingEvent->update(['ignored_at' => now()]);

            return;
        }

        $this->syncPlanWithBillable->execute($billable, $planPrice);
        $billingEvent->update(['processed_at' => now(), 'last_error' => null]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return (Model&Billable)|null
     */
    private function findBillable(array $data): ?Model
    {
        $customerId = $data['customer_id'] ?? null;

        if (is_string($customerId) && $customerId !== '') {
            $billable = $this->provider->findBillableByCustomerId($customerId);

            if ($billable instanceof Billable) {
                return $billable;
            }
        }

        $metadata = $data['customer']['metadata'] ?? [];
        $billableType = $metadata['billable_type'] ?? null;
        $billableId = $metadata['billable_id'] ?? null;

        if (! is_string($billableType) || $billableType === '' || ! class_exists($billableType) || $billableId === null) {
            return null;
        }

        $billable = $billableType::query()->find($billableId);

        return $billable instanceof Model && $billable instanceof Billable ? $billable : null;
    }

    /**
     * @param  Model&Billable  $billable
     * @param  array<string, mixed>  $data
     */
    private function syncPendingChange(Model $billable, array $data): void
    {
        $pendingUpdate = $data['pending_update'] ?? null;

        $this->confirmPendingChange->execute(
            $billable,
            provider: 'polar',
            providerSubscriptionId: (string) $data['id'],
            currentProductId: is_string($data['product_id'] ?? null) ? $data['product_id'] : null,
            pendingUpdate: is_array($pendingUpdate) && is_string($pendingUpdate['product_id'] ?? null) ? [
                'id' => $pendingUpdate['id'] ?? null,
                'product_id' => $pendingUpdate['product_id'],
                'applies_at' => $pendingUpdate['applies_at'] ?? null,
            ] : null,
            providerChange: fn (PlanPrice $target) => $this->providerChangeFromWebhook($data, $target),
            lockForUpdate: true,
        );
    }

    /**
     * @param  Model&Billable  $billable
     * @param  array<string, mixed>  $data
     */
    private function syncCurrentPlan(Model $billable, array $data, string $eventType): void
    {
        $productId = $data['product_id'] ?? null;

        if (! is_string($productId) || $productId === '') {
            Log::warning('Polar subscription webhook is missing product_id', [
                'subscription_id' => $data['id'],
                'event_type' => $eventType,
            ]);

            return;
        }

        $planPrice = $this->planPriceByPolarProduct($productId);

        if ($planPrice === null) {
            Log::warning('No plan price found for Polar product', [
                'product_id' => $productId,
                'subscription_id' => $data['id'],
            ]);

            return;
        }

        if ((int) $billable->getAttribute('plan_price_id') === $planPrice->id) {
            return;
        }

        $this->syncPlanWithBillable->execute($billable, $planPrice);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function providerChangeFromWebhook(array $data, PlanPrice $targetPlanPrice): ProviderSubscriptionChange
    {
        // Payloads can omit or null the period fields; an unguarded read would
        // abort the webhook (retry loop) or collapse the period to "now" and
        // immediately expire the freshly granted quota.
        $periodStart = $this->parseWebhookDate($data['current_period_start'] ?? null);
        $periodEnd = $this->parseWebhookDate($data['current_period_end'] ?? null);

        if ($periodStart === null || $periodEnd === null) {
            Log::warning('Polar subscription webhook is missing billing period fields, using fallbacks', [
                'subscription_id' => $data['id'],
                'current_period_start' => $data['current_period_start'] ?? null,
                'current_period_end' => $data['current_period_end'] ?? null,
            ]);
        }

        $periodStart ??= CarbonImmutable::now();

        return new ProviderSubscriptionChange(
            providerSubscriptionId: (string) $data['id'],
            currentProductId: (string) $data['product_id'],
            pendingProductId: null,
            periodStart: $periodStart,
            periodEnd: $periodEnd ?? $this->addInterval($periodStart, $targetPlanPrice->interval),
        );
    }

    private function parseWebhookDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function addInterval(CarbonImmutable $start, Interval $interval): CarbonImmutable
    {
        return match ($interval) {
            Interval::DAY => $start->addDay(),
            Interval::WEEK => $start->addWeek(),
            Interval::MONTH => $start->addMonthNoOverflow(),
            Interval::YEAR => $start->addYearNoOverflow(),
            Interval::LIFETIME => $start->addYears(100),
        };
    }

    private function cancelPendingChanges(string $subscriptionId, Model $billable): void
    {
        $this->planChangeModel()::query()
            ->pending()
            ->where('provider', 'polar')
            ->where('provider_subscription_id', $subscriptionId)
            ->lockForUpdate()
            ->get()
            ->each(function (SubscriptionPlanChange $planChange) use ($billable): void {
                $planChange->markCancelled();
                Event::dispatch(new SubscriptionPlanChangeCancelled($billable, $planChange));
            });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function providerEventId(array $payload): string
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $identity = [
            'type' => $payload['type'] ?? null,
            'timestamp' => $data['modified_at'] ?? $payload['timestamp'] ?? null,
            'subscription_id' => $data['id'] ?? null,
            'status' => $data['status'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'pending_update' => $data['pending_update'] ?? null,
        ];

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function occurredAt(array $payload): CarbonImmutable
    {
        $value = $payload['data']['modified_at'] ?? $payload['timestamp'] ?? now();

        return CarbonImmutable::parse($value);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function billingEvent(
        string $providerEventId,
        string $eventType,
        array $data,
        CarbonImmutable $occurredAt
    ): BillingWebhookEvent {
        return $this->webhookEventModel()::query()->firstOrCreate(
            [
                'provider' => 'polar',
                'provider_event_id' => $providerEventId,
            ],
            [
                'event_type' => $eventType,
                'provider_subscription_id' => $data['id'] ?? null,
                'occurred_at' => $occurredAt,
            ]
        );
    }

    /**
     * Events that end entitlements. Ordering lineages are keyed per
     * subscription id — or per order id for order.* events — so a processed
     * terminal event only dominates later events of its own lineage.
     */
    private const TERMINAL_EVENTS = ['subscription.revoked', 'subscription.paused', 'order.refunded'];

    private function isOutOfOrder(string $subscriptionId, CarbonImmutable $occurredAt, int $currentEventId, string $eventType): bool
    {
        // Compare at microsecond precision as a pre-formatted string: the query
        // grammar would otherwise truncate Carbon bindings to whole seconds.
        $occurred = $occurredAt->format('Y-m-d H:i:s.u');

        $stale = $this->webhookEventModel()::query()
            ->where('provider', 'polar')
            ->where('provider_subscription_id', $subscriptionId)
            ->where('id', '!=', $currentEventId)
            ->where(function ($query): void {
                $query->whereNotNull('processed_at')->orWhereNotNull('ignored_at');
            })
            ->where('occurred_at', '>', $occurred)
            ->exists();

        if ($stale || in_array($eventType, self::TERMINAL_EVENTS, true)) {
            return $stale;
        }

        // Terminal dominance: once a revoked/paused event has been processed,
        // a non-terminal event with an equal (ambiguous) timestamp must never
        // restore entitlements.
        return $this->webhookEventModel()::query()
            ->where('provider', 'polar')
            ->where('provider_subscription_id', $subscriptionId)
            ->where('id', '!=', $currentEventId)
            ->whereNotNull('processed_at')
            ->whereIn('event_type', self::TERMINAL_EVENTS)
            ->where('occurred_at', '>=', $occurred)
            ->exists();
    }

    private function planPriceByPolarProduct(mixed $productId): ?PlanPrice
    {
        if (! is_string($productId) || $productId === '') {
            return null;
        }

        /** @var class-string<PlanPrice> $planPriceModel */
        $planPriceModel = config('plan-usage.models.plan_price', PlanPrice::class);

        return $planPriceModel::query()->where('polar_product_id', $productId)->first();
    }

    /**
     * @return class-string<SubscriptionPlanChange>
     */
    private function planChangeModel(): string
    {
        return config('plan-usage.models.subscription_plan_change', SubscriptionPlanChange::class);
    }

    /**
     * @return class-string<BillingWebhookEvent>
     */
    private function webhookEventModel(): string
    {
        return config('plan-usage.models.billing_webhook_event', BillingWebhookEvent::class);
    }
}
