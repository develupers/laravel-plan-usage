<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Actions\Subscription;

use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Models\Plan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class DeleteSubscriptionAction
{
    /**
     * Handle subscription deletion (after grace period has ended).
     *
     * This is called when a subscription is fully cancelled (not just pending
     * cancellation). At this point, the user has lost access to the paid
     * features. When `subscription.default_plan_id` is configured the billable
     * is moved to that (free) plan — matching EnforcePlanSubscriptionsJob —
     * otherwise the plan is cleared and all quotas are deleted.
     *
     * Fail-closed: failures rethrow so the caller (webhook listener,
     * reconciliation) retries. Swallowing them would leave a planless billable
     * whose surviving quota rows remain consumable.
     *
     * @param  Billable  $billable  The billable entity
     */
    public function execute(Billable $billable): void
    {
        // Get the old plan information before clearing
        $oldPlanId = $this->getBillablePlanId($billable);
        $oldPlanPriceId = $this->getBillablePlanPriceId($billable);

        // Idempotent for repeated lifecycle events: once the billable is on
        // the configured default (free) plan, a second cancellation event or
        // reconciliation run must not delete and recreate its quotas — that
        // would reset free-tier usage to zero on every event.
        $defaultPlanId = (int) (config('plan-usage.subscription.default_plan_id') ?? 0);

        if ($defaultPlanId > 0 && $oldPlanId === $defaultPlanId) {
            return;
        }

        // Step 1 — revoke FIRST, as its own committed write. The paid plan
        // must never survive a later cleanup failure: with plan_id cleared,
        // QuotaEnforcer's plan guard already denies usage even while stale
        // quota rows remain, so every later step can safely fail and retry.
        // (Wrapping revocation and cleanup in one transaction would roll the
        // revocation back on cleanup failure — fail-open on the paid plan.)
        $this->clearBillablePlan($billable);

        if ($billable instanceof Model) {
            app('plan-usage.quota')->clearQuotaCache($billable);
        }

        // Step 2 — cleanup. Failures rethrow so the caller (webhook listener,
        // reconciliation) retries, while the billable stays planless (denied)
        // rather than back on the paid plan.
        $deletedCount = $billable->quotas()->delete();

        Log::info('Deleted subscription and cleared quotas for billable', [
            'billable_type' => get_class($billable),
            'billable_id' => $billable->getKey(),
            'old_plan_id' => $oldPlanId,
            'old_plan_price_id' => $oldPlanPriceId,
            'quotas_deleted' => $deletedCount,
        ]);

        // Step 3 — move to the configured default (free) plan when one is
        // set. A failed sync leaves the billable planless; the retry assigns
        // the default plan.
        $this->assignDefaultPlan($billable);
    }

    /**
     * Move the billable to the configured default plan, when one is set.
     *
     * Runs AFTER the paid plan has been revoked: a failure here (rethrown by
     * the sync) leaves the billable planless and denied, never entitled.
     */
    protected function assignDefaultPlan(Billable $billable): bool
    {
        $defaultPlanId = config('plan-usage.subscription.default_plan_id');

        if (! $defaultPlanId) {
            return false;
        }

        $defaultPlan = Plan::find($defaultPlanId);

        if (! $defaultPlan) {
            Log::warning('Default plan ID from config not found, clearing subscription', [
                'billable_type' => get_class($billable),
                'billable_id' => $billable->getKey(),
                'default_plan_id' => $defaultPlanId,
            ]);

            return false;
        }

        $synced = app(SyncPlanWithBillableAction::class)->execute($billable, $defaultPlan);

        if ($synced) {
            if ($billable instanceof Model) {
                app('plan-usage.quota')->clearQuotaCache($billable);
            }

            Log::info('Moved billable to default plan after subscription deletion', [
                'billable_type' => get_class($billable),
                'billable_id' => $billable->getKey(),
                'default_plan_id' => $defaultPlanId,
            ]);

            return true;
        }

        Log::warning('Failed to set default plan - plan not found, clearing subscription', [
            'billable_type' => get_class($billable),
            'billable_id' => $billable->getKey(),
            'default_plan_id' => $defaultPlanId,
        ]);

        return false;
    }

    /**
     * Set billable to a free/default plan instead of null.
     *
     * @deprecated execute() now applies the configured default plan itself;
     *             this remains for backwards compatibility.
     *
     * @param  Billable  $billable  The billable entity
     */
    public function executeWithDefaultPlan(Billable $billable): void
    {
        $this->execute($billable);
    }

    /**
     * Get the plan ID from the billable entity.
     */
    protected function getBillablePlanId(Billable $billable): ?int
    {
        return $this->billableAttribute($billable, 'plan_id', 'getPlanId');
    }

    /**
     * Get the plan price ID from the billable entity.
     */
    protected function getBillablePlanPriceId(Billable $billable): ?int
    {
        return $this->billableAttribute($billable, 'plan_price_id', 'getPlanPriceId');
    }

    /**
     * Read a plan attribute from any billable shape: custom accessor,
     * declared property, or a real Eloquent attribute. property_exists()
     * alone misses Eloquent columns (they live in the attributes array).
     */
    protected function billableAttribute(Billable $billable, string $attribute, string $accessor): ?int
    {
        $value = match (true) {
            method_exists($billable, $accessor) => $billable->{$accessor}(),
            property_exists($billable, $attribute) => $billable->{$attribute},
            $billable instanceof Model => $billable->getAttribute($attribute),
            default => null,
        };

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Clear the plan from the billable entity.
     */
    protected function clearBillablePlan(Billable $billable): void
    {
        // Use dynamic property setting to be flexible with different models
        if (property_exists($billable, 'plan_id') || method_exists($billable, '__set')) {
            $billable->plan_id = null;
        }

        if (property_exists($billable, 'plan_price_id') || method_exists($billable, '__set')) {
            $billable->plan_price_id = null;
        }

        if (property_exists($billable, 'plan_changed_at') || method_exists($billable, '__set')) {
            $billable->plan_changed_at = now();
        }

        $billable->save();

        // A previously loaded plan relation would otherwise keep serving the
        // revoked plan to hasFeature()/getFeatureValue() on this instance.
        if ($billable instanceof Model) {
            $billable->unsetRelation('plan');
        }
    }

    /**
     * Clear all subscription-related data for a billable.
     *
     * This is a more aggressive cleanup that removes all traces of subscriptions.
     *
     * @param  Billable  $billable  The billable entity
     */
    public function executeComplete(Billable $billable): void
    {
        // First do the standard deletion
        $this->execute($billable);

        // Clear any usage records if configured to do so
        if (config('plan-usage.subscription.clear_usage_on_delete', false)) {
            try {
                $deletedUsage = $billable->usage()->delete();

                Log::info('Cleared all usage records for billable', [
                    'billable_type' => get_class($billable),
                    'billable_id' => $billable->getKey(),
                    'usage_deleted' => $deletedUsage,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to clear usage during subscription deletion', [
                    'billable_type' => get_class($billable),
                    'billable_id' => $billable->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Clear billing provider customer data if configured to do so
        if (config('plan-usage.subscription.clear_provider_data_on_delete', false)
            || config('plan-usage.subscription.clear_stripe_on_delete', false)) {
            $this->clearProviderData($billable);
        }
    }

    /**
     * Clear billing provider customer data from the billable.
     *
     * This handles both Stripe and Paddle customer data.
     */
    protected function clearProviderData(Billable $billable): void
    {
        $clearedProviders = [];

        // Clear Stripe customer data
        if (property_exists($billable, 'stripe_id') || method_exists($billable, '__set')) {
            $hadStripeData = ! empty($billable->stripe_id);
            $billable->stripe_id = null;

            if (property_exists($billable, 'pm_type') || method_exists($billable, '__set')) {
                $billable->pm_type = null;
            }
            if (property_exists($billable, 'pm_last_four') || method_exists($billable, '__set')) {
                $billable->pm_last_four = null;
            }
            if (property_exists($billable, 'trial_ends_at') || method_exists($billable, '__set')) {
                $billable->trial_ends_at = null;
            }

            if ($hadStripeData) {
                $clearedProviders[] = 'stripe';
            }
        }

        // Clear Paddle customer data
        if (property_exists($billable, 'paddle_id') || method_exists($billable, '__set')) {
            $hadPaddleData = ! empty($billable->paddle_id);
            $billable->paddle_id = null;

            if ($hadPaddleData) {
                $clearedProviders[] = 'paddle';
            }
        }

        if (! empty($clearedProviders)) {
            $billable->save();

            Log::info('Cleared billing provider customer data for billable', [
                'billable_type' => get_class($billable),
                'billable_id' => $billable->getKey(),
                'cleared_providers' => $clearedProviders,
            ]);
        }
    }
}
