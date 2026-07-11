<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Actions\Subscription;

use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Contracts\SubscriptionPlanChangeProvider;
use Develupers\PlanUsage\Enums\SubscriptionChangeStatus;
use Develupers\PlanUsage\Enums\SubscriptionChangeTiming;
use Develupers\PlanUsage\Events\SubscriptionPlanChanged;
use Develupers\PlanUsage\Events\SubscriptionPlanChangeScheduled;
use Develupers\PlanUsage\Models\PlanPrice;
use Develupers\PlanUsage\Models\SubscriptionPlanChange;
use Develupers\PlanUsage\Support\SubscriptionStateLock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

class ChangeSubscriptionPlanAction
{
    public function __construct(
        private BillingProvider $billingProvider,
        private ApplyPlanChangeAction $applyPlanChange,
        private SubscriptionStateLock $stateLock,
    ) {}

    /**
     * @param  Model&Billable  $billable
     */
    public function execute(
        Model $billable,
        PlanPrice $targetPlanPrice,
        SubscriptionChangeTiming $timing,
        string $subscriptionName = 'default'
    ): SubscriptionPlanChange {
        if (! $this->billingProvider instanceof SubscriptionPlanChangeProvider) {
            throw ValidationException::withMessages([
                'subscription' => ["{$this->billingProvider->name()} does not support managed plan changes."],
            ]);
        }

        // Gate before any state is created: an unsupported timing must not
        // leave a pending (later failed) change record behind.
        if (! $this->billingProvider->supportsTiming($timing)) {
            throw ValidationException::withMessages([
                'subscription' => ["{$this->billingProvider->name()} does not support the '{$timing->value}' plan change timing."],
            ]);
        }

        $targetPlanPrice->loadMissing('plan');
        $productId = $targetPlanPrice->getProviderPriceId();

        if ($productId === null || ! $targetPlanPrice->is_active || ! $targetPlanPrice->plan->isAvailableForPurchase()) {
            throw ValidationException::withMessages([
                'price' => ['The selected plan price is not available.'],
            ]);
        }

        // Shared with the webhook listener and reconciliation so no other
        // subscription-state mutation can interleave with this change.
        return $this->stateLock->block($billable, function () use (
            $billable,
            $targetPlanPrice,
            $timing,
            $subscriptionName,
            $productId
        ): SubscriptionPlanChange {
            $existingChange = $this->pendingChange($billable, $subscriptionName);

            if ($existingChange !== null) {
                throw ValidationException::withMessages([
                    'subscription' => ['Cancel the existing pending plan change before requesting another one.'],
                ]);
            }

            $subscription = $billable->subscription($subscriptionName);

            if ($subscription === null) {
                throw ValidationException::withMessages([
                    'subscription' => ['No active subscription found.'],
                ]);
            }

            $planChange = $this->planChangeModel()::create([
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => $billable->getKey(),
                'provider' => $this->billingProvider->name(),
                'subscription_type' => $subscriptionName,
                'provider_subscription_id' => (string) ($subscription->polar_id ?? $subscription->stripe_id ?? $subscription->paddle_id ?? $subscription->id),
                'from_plan_price_id' => $billable->getAttribute('plan_price_id'),
                'to_plan_price_id' => $targetPlanPrice->id,
                'timing' => $timing,
                'status' => SubscriptionChangeStatus::Pending,
            ]);

            try {
                $providerChange = $this->billingProvider->changeSubscription(
                    $billable,
                    $productId,
                    $timing,
                    $subscriptionName
                );
            } catch (\Throwable $exception) {
                $planChange->markFailed(['error' => $exception->getMessage()]);

                throw $exception;
            }

            $planChange->update([
                'provider_subscription_id' => $providerChange->providerSubscriptionId,
                'provider_change_id' => $providerChange->providerChangeId,
                'effective_at' => $providerChange->effectiveAt,
                'metadata' => [
                    'current_product_id' => $providerChange->currentProductId,
                    'pending_product_id' => $providerChange->pendingProductId,
                    'period_start' => $providerChange->periodStart->toIso8601String(),
                    'period_end' => $providerChange->periodEnd->toIso8601String(),
                ],
            ]);

            if ($timing === SubscriptionChangeTiming::NextPeriod) {
                Event::dispatch(new SubscriptionPlanChangeScheduled($billable, $planChange));

                return $planChange->refresh();
            }

            if ($providerChange->currentProductId !== $productId) {
                $planChange->markFailed(['error' => 'Provider did not apply the immediate product change.']);

                throw ValidationException::withMessages([
                    'subscription' => ['The provider did not confirm the immediate plan change.'],
                ]);
            }

            $adjustments = $this->applyPlanChange->execute(
                $billable,
                $targetPlanPrice,
                $providerChange,
                resetUsage: false,
            );
            $planChange->markApplied();

            Event::dispatch(new SubscriptionPlanChanged($billable, $planChange, $adjustments));

            return $planChange->refresh();
        });
    }

    /**
     * @param  Model&Billable  $billable
     */
    private function pendingChange(Model $billable, string $subscriptionName): ?SubscriptionPlanChange
    {
        // No lockForUpdate: it would be a no-op outside a transaction; the
        // SubscriptionStateLock serializes all pending-change access instead.
        return $this->planChangeModel()::query()
            ->pending()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->where('provider', $this->billingProvider->name())
            ->where('subscription_type', $subscriptionName)
            ->first();
    }

    /**
     * @return class-string<SubscriptionPlanChange>
     */
    private function planChangeModel(): string
    {
        return config('plan-usage.models.subscription_plan_change', SubscriptionPlanChange::class);
    }
}
