<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Actions\Subscription;

use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Models\Plan;
use Illuminate\Support\Facades\Log;

class DeleteSubscriptionAction
{
    /**
     * Handle subscription deletion (after grace period has ended).
     *
     * This is called when a subscription is fully cancelled (not just pending cancellation).
     * At this point, the user has lost access to the paid features.
     *
     * @param  Billable  $billable  The billable entity
     */
    public function execute(Billable $billable): void
    {
        // Get the old plan information before clearing
        $oldPlanId = $this->getBillablePlanId($billable);
        $oldPlanPriceId = $this->getBillablePlanPriceId($billable);

        // Clear the plan identifiers since subscription is fully cancelled
        $this->clearBillablePlan($billable);

        // Clear all plan-usage quotas since the billable no longer has access
        // This prevents stale quota rows from persisting
        try {
            $deletedCount = $billable->quotas()->delete();

            Log::info('Deleted subscription and cleared quotas for billable', [
                'billable_type' => get_class($billable),
                'billable_id' => $billable->getKey(),
                'old_plan_id' => $oldPlanId,
                'old_plan_price_id' => $oldPlanPriceId,
                'quotas_deleted' => $deletedCount,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to clear quotas during subscription deletion', [
                'billable_type' => get_class($billable),
                'billable_id' => $billable->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Alternative: Set billable to a free/default plan instead of null.
     * Uses the default plan ID from config if available.
     *
     * @param  Billable  $billable  The billable entity
     */
    public function executeWithDefaultPlan(Billable $billable): void
    {
        // Get default plan ID from config
        $defaultPlanId = config('plan-usage.subscription.default_plan_id');

        if ($defaultPlanId) {
            // Load the Plan model by ID
            $defaultPlan = Plan::find($defaultPlanId);

            if (! $defaultPlan) {
                Log::warning('Default plan ID from config not found, clearing subscription', [
                    'billable_type' => get_class($billable),
                    'billable_id' => $billable->getKey(),
                    'default_plan_id' => $defaultPlanId,
                ]);
                $this->execute($billable);

                return;
            }

            // If a default plan is configured, sync to that instead
            $synced = app(SyncPlanWithBillableAction::class)->execute($billable, $defaultPlan);

            if ($synced) {
                Log::info('Moved billable to default plan after subscription deletion', [
                    'billable_type' => get_class($billable),
                    'billable_id' => $billable->getKey(),
                    'default_plan_id' => $defaultPlanId,
                ]);
            } else {
                Log::warning('Failed to set default plan - plan not found, clearing subscription', [
                    'billable_type' => get_class($billable),
                    'billable_id' => $billable->getKey(),
                    'default_plan_id' => $defaultPlanId,
                ]);
                // Fall back to just clearing the subscription
                $this->execute($billable);
            }
        } else {
            // No default plan configured, just clear everything
            $this->execute($billable);
        }
    }

    /**
     * Get the plan ID from the billable entity.
     *
     * @param  Billable  $billable
     * @return int|null
     */
    protected function getBillablePlanId(Billable $billable): ?int
    {
        if (property_exists($billable, 'plan_id')) {
            return $billable->plan_id;
        }

        if (method_exists($billable, 'getPlanId')) {
            return $billable->getPlanId();
        }

        return null;
    }

    /**
     * Get the plan price ID from the billable entity.
     *
     * @param  Billable  $billable
     * @return int|null
     */
    protected function getBillablePlanPriceId(Billable $billable): ?int
    {
        if (property_exists($billable, 'plan_price_id')) {
            return $billable->plan_price_id;
        }

        if (method_exists($billable, 'getPlanPriceId')) {
            return $billable->getPlanPriceId();
        }

        return null;
    }

    /**
     * Clear the plan from the billable entity.
     *
     * @param  Billable  $billable
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

        // Clear Stripe customer ID if configured to do so
        if (config('plan-usage.subscription.clear_stripe_on_delete', false)) {
            if (property_exists($billable, 'stripe_id') || method_exists($billable, '__set')) {
                $billable->stripe_id = null;
                $billable->pm_type = null;
                $billable->pm_last_four = null;
                $billable->trial_ends_at = null;
                $billable->save();

                Log::info('Cleared Stripe customer data for billable', [
                    'billable_type' => get_class($billable),
                    'billable_id' => $billable->getKey(),
                ]);
            }
        }
    }
}