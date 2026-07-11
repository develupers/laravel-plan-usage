<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Actions\Subscription;

use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Enums\SubscriptionChangeTiming;
use Develupers\PlanUsage\Events\SubscriptionPlanChangeCancelled;
use Develupers\PlanUsage\Events\SubscriptionPlanChanged;
use Develupers\PlanUsage\Models\PlanPrice;
use Develupers\PlanUsage\Models\SubscriptionPlanChange;
use Develupers\PlanUsage\Support\ProviderSubscriptionChange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

/**
 * Reconciles the latest pending plan change against observed provider state.
 *
 * Webhook processing and reconciliation both funnel through here so the
 * confirmation policy lives in exactly one place:
 *
 * - the provider still reports a pending update  → refresh the local record
 * - the provider swapped to the target product   → apply it (entitlements)
 * - the provider swapped to anything else        → cancel the local record
 *
 * Callers are expected to hold the billable's SubscriptionStateLock.
 */
class ConfirmPendingPlanChangeAction
{
    public function __construct(
        private ApplyPlanChangeAction $applyPlanChange,
    ) {}

    /**
     * @param  Model&Billable  $billable
     * @param  string|null  $currentProductId  The product the provider subscription is on now
     * @param  array{id?: string|null, product_id?: string|null, applies_at?: mixed}|null  $pendingUpdate  Provider-side scheduled update, if still present
     * @param  callable(PlanPrice): ProviderSubscriptionChange  $providerChange  Built lazily — only when the change applies
     * @return SubscriptionPlanChange|null The pending change in its final state, or null when none exists
     */
    public function execute(
        Model $billable,
        string $provider,
        string $providerSubscriptionId,
        ?string $currentProductId,
        ?array $pendingUpdate,
        callable $providerChange,
        bool $lockForUpdate = false,
    ): ?SubscriptionPlanChange {
        $query = $this->planChangeModel()::query()
            ->pending()
            ->where('provider', $provider)
            ->where('provider_subscription_id', $providerSubscriptionId)
            ->latest('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $pendingChange = $query->first();

        if ($pendingChange === null) {
            return null;
        }

        $targetProductId = $this->targetProductId($pendingChange->toPlanPrice, $provider);

        if ($pendingUpdate !== null) {
            $pendingProductId = $pendingUpdate['product_id'] ?? null;

            // Refresh only when the provider's pending update is (or may be)
            // ours; a pending update for a DIFFERENT product was made out of
            // band — leave the record alone and let a later event resolve it.
            if ($pendingProductId === null || $pendingProductId === $targetProductId) {
                $pendingChange->update([
                    'provider_change_id' => $pendingUpdate['id'] ?? $pendingChange->provider_change_id,
                    'effective_at' => $pendingUpdate['applies_at'] ?? $pendingChange->effective_at,
                    'metadata' => $pendingProductId === null
                        ? $pendingChange->metadata
                        : array_merge($pendingChange->metadata ?? [], [
                            'pending_product_id' => $pendingProductId,
                        ]),
                ]);
            }

            return $pendingChange;
        }

        if ($currentProductId !== $targetProductId) {
            $pendingChange->markCancelled();
            Event::dispatch(new SubscriptionPlanChangeCancelled($billable, $pendingChange));

            return $pendingChange;
        }

        // Only scheduled (next-period) changes start a fresh period whose usage
        // resets. An Immediate pending record here means the action that created
        // it did not finish (crash after the provider call) — repair it with
        // immediate semantics: prorate the limit, preserve current usage.
        $adjustments = $this->applyPlanChange->execute(
            $billable,
            $pendingChange->toPlanPrice,
            $providerChange($pendingChange->toPlanPrice),
            resetUsage: $pendingChange->timing === SubscriptionChangeTiming::NextPeriod,
        );
        $pendingChange->markApplied();

        Event::dispatch(new SubscriptionPlanChanged($billable, $pendingChange, $adjustments));

        return $pendingChange;
    }

    /**
     * The provider-side identifier the target PlanPrice maps to. Resolved by
     * the provider the change was recorded against — NOT the globally
     * configured provider, which reconciliation can override per run.
     */
    private function targetProductId(PlanPrice $planPrice, string $provider): ?string
    {
        return match ($provider) {
            'paddle' => $planPrice->paddle_price_id,
            'polar' => $planPrice->polar_product_id,
            default => $planPrice->stripe_price_id,
        };
    }

    /**
     * @return class-string<SubscriptionPlanChange>
     */
    private function planChangeModel(): string
    {
        return config('plan-usage.models.subscription_plan_change', SubscriptionPlanChange::class);
    }
}
