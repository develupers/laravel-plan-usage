<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Actions\Subscription;

use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Facades\PlanUsage;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanPrice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncPlanWithBillableAction
{
    /**
     * Sync the billing provider subscription with the local plan.
     *
     * @param  Billable  $billable  The billable entity to sync
     * @param  string|Plan|PlanPrice  $planOrPrice  The Plan, PlanPrice, or provider price ID
     * @return bool True if successfully synced, false if plan/price not found
     */
    public function execute(Billable $billable, string|Plan|PlanPrice $planOrPrice): bool
    {
        $planPrice = null;
        $plan = null;

        // Determine the plan and price based on input type
        switch (true) {
            case $planOrPrice instanceof PlanPrice:
                $planPrice = $planOrPrice;
                $plan = $planPrice->plan;
                break;

            case $planOrPrice instanceof Plan:
                $plan = $planOrPrice->loadMissing('defaultPrice', 'prices');
                $planPrice = $plan->defaultPrice ?? $plan->prices()
                    ->where('is_active', true)
                    ->orderByDesc('is_default')
                    ->first();
                break;

            case is_string($planOrPrice):
                // Use provider-agnostic lookup for price ID
                $planPrice = PlanPrice::findByProviderPriceId($planOrPrice);
                $plan = $planPrice?->plan;
                break;
        }

        if (! $plan || ! $planPrice) {
            Log::warning('No matching plan or plan price found for subscription sync', [
                'billable_type' => get_class($billable),
                'billable_id' => $billable->getKey(),
                'identifier' => $planOrPrice instanceof Plan || $planOrPrice instanceof PlanPrice
                    ? get_class($planOrPrice).':'.$planOrPrice->id
                    : $planOrPrice,
            ]);

            return false;
        }

        // Store old values for logging
        $oldPlanId = $this->getBillablePlanId($billable);
        $oldPlanPriceId = $this->getBillablePlanPriceId($billable);

        // Already on this exact plan price: nothing to sync. Routine
        // subscription.updated webhooks (renewals, metadata edits, the echo of
        // a swap we already applied) would otherwise re-run syncQuotasWithPlan()
        // and overwrite prorated mid-cycle limits with the full plan allowance.
        if ((int) $oldPlanId === $plan->id && (int) $oldPlanPriceId === $planPrice->id) {
            return true;
        }

        // Assign the plan and sync quotas atomically. Quota failures must roll
        // the plan ids back and rethrow: if the ids were saved while quotas
        // failed, the same-plan guard above would skip every future webhook
        // repair attempt, leaving entitlements permanently broken.
        DB::transaction(function () use ($billable, $plan, $planPrice): void {
            $this->updateBillablePlan($billable, $plan, $planPrice);

            if (method_exists($billable, 'syncQuotasWithPlan')) {
                $billable->syncQuotasWithPlan();
            } else {
                // Use the facade to sync quotas if the model doesn't have the method
                PlanUsage::quotas()->syncWithPlan($billable);
            }
        });

        Log::info("Synced plan {$plan->name} with billable", [
            'billable_type' => get_class($billable),
            'billable_id' => $billable->getKey(),
            'old_plan_id' => $oldPlanId,
            'new_plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'old_plan_price_id' => $oldPlanPriceId,
            'new_plan_price_id' => $planPrice->id,
        ]);

        return true;
    }

    /**
     * Sync multiple billables to a plan.
     *
     * @param  iterable  $billables  Collection of billable entities
     * @param  string|Plan|PlanPrice  $planOrPrice  The Plan, PlanPrice, or Stripe price ID
     * @return array Array of results with billable IDs and success status
     */
    public function executeMany(iterable $billables, string|Plan|PlanPrice $planOrPrice): array
    {
        $results = [];

        foreach ($billables as $billable) {
            $billableId = $billable->getKey();
            $success = $this->execute($billable, $planOrPrice);

            $results[$billableId] = [
                'billable' => $billable,
                'success' => $success,
            ];
        }

        return $results;
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
     * alone misses Eloquent columns (they live in the attributes array),
     * which silently disabled the same-plan guard on real models.
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
     * Update the billable's plan and plan price.
     */
    protected function updateBillablePlan(Billable $billable, Plan $plan, PlanPrice $planPrice): void
    {
        // Use dynamic property setting to be flexible with different models
        if (property_exists($billable, 'plan_id') || method_exists($billable, '__set')) {
            $billable->plan_id = $plan->id;
        }

        if (property_exists($billable, 'plan_price_id') || method_exists($billable, '__set')) {
            $billable->plan_price_id = $planPrice->id;
        }

        if (property_exists($billable, 'plan_changed_at') || method_exists($billable, '__set')) {
            $billable->plan_changed_at = now();
        }

        $billable->save();

        // A previously loaded plan relation still points at the OLD plan after
        // the ids change; syncQuotasWithPlan() would then sync quotas from it,
        // and the same-plan guard would block every future repair.
        if ($billable instanceof Model) {
            $billable->unsetRelation('plan');
        }
    }

    /**
     * Sync a billable to a plan by plan slug.
     *
     * @param  Billable  $billable  The billable entity
     * @param  string  $planSlug  The plan slug
     * @return bool True if successfully synced
     */
    public function executeBySlug(Billable $billable, string $planSlug): bool
    {
        $plan = Plan::where('slug', $planSlug)->first();

        if (! $plan) {
            Log::warning('Plan not found by slug for subscription sync', [
                'billable_type' => get_class($billable),
                'billable_id' => $billable->getKey(),
                'plan_slug' => $planSlug,
            ]);

            return false;
        }

        return $this->execute($billable, $plan);
    }

    /**
     * Sync a billable to a specific plan and price combination.
     *
     * @param  Billable  $billable  The billable entity
     * @param  Plan  $plan  The plan
     * @param  PlanPrice  $planPrice  The specific price
     * @return bool True if successfully synced
     */
    public function executeWithSpecificPrice(Billable $billable, Plan $plan, PlanPrice $planPrice): bool
    {
        // Validate that the price belongs to the plan
        if ($planPrice->plan_id !== $plan->id) {
            Log::error('Plan price does not belong to the specified plan', [
                'plan_id' => $plan->id,
                'plan_price_id' => $planPrice->id,
                'plan_price_plan_id' => $planPrice->plan_id,
            ]);

            return false;
        }

        return $this->execute($billable, $planPrice);
    }
}
