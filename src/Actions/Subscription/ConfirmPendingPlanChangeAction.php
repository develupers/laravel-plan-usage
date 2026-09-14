<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Actions\Subscription;

use Carbon\CarbonInterface;
use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Enums\SubscriptionChangeTiming;
use Develupers\PlanUsage\Events\SubscriptionPlanChangeCancelled;
use Develupers\PlanUsage\Events\SubscriptionPlanChanged;
use Develupers\PlanUsage\Models\PlanPrice;
use Develupers\PlanUsage\Models\SubscriptionPlanChange;
use Develupers\PlanUsage\Support\ProviderSubscriptionChange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

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
     * @param  CarbonInterface|null  $observedAt  When the provider state was observed (a webhook's modified_at); null = observed now
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
        ?CarbonInterface $observedAt = null,
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
            // Provider state observed BEFORE this record existed cannot say
            // anything about it: a delayed webhook from the previous change
            // (e.g. the echo of cancelling an earlier pending update) would
            // otherwise cancel a change the provider still holds.
            if ($this->observedBeforeChange($pendingChange, $observedAt)) {
                Log::info('Ignoring provider state older than the pending plan change', [
                    'plan_change_id' => $pendingChange->id,
                    'observed_at' => $observedAt?->toIso8601String(),
                    'created_at' => $pendingChange->created_at?->toIso8601String(),
                ]);

                return $pendingChange;
            }

            $pendingChange->markCancelled();
            Event::dispatch(new SubscriptionPlanChangeCancelled($billable, $pendingChange));

            return $pendingChange;
        }

        // Only scheduled (next-period) changes start a fresh period whose usage
        // resets. An Immediate pending record here means the action that created
        // it did not finish (crash after the provider call) — repair it with
        // immediate semantics: prorate the limit, preserve current usage.
        //
        // Applying the entitlements and marking the record applied commit
        // together: a crash between the two would leave the record pending,
        // and the retry would apply it again — wiping usage accumulated since
        // the first, already-committed application.
        $adjustments = DB::transaction(function () use ($billable, $pendingChange, $providerChange): array {
            $adjustments = $this->applyPlanChange->execute(
                $billable,
                $pendingChange->toPlanPrice,
                $providerChange($pendingChange->toPlanPrice),
                resetUsage: $pendingChange->timing === SubscriptionChangeTiming::NextPeriod,
            );
            $pendingChange->markApplied();

            return $adjustments;
        });

        // ApplyPlanChangeAction cleared the quota cache inside the (nested)
        // transaction; a reader could have repopulated it from the old state
        // before the outer commit. Clear again now that everything is visible.
        app('plan-usage.quota')->clearQuotaCache($billable);

        Event::dispatch(new SubscriptionPlanChanged($billable, $pendingChange, $adjustments));

        return $pendingChange;
    }

    private function observedBeforeChange(SubscriptionPlanChange $pendingChange, ?CarbonInterface $observedAt): bool
    {
        if ($observedAt === null || $pendingChange->created_at === null) {
            return false;
        }

        return $observedAt->lessThan($pendingChange->created_at);
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
