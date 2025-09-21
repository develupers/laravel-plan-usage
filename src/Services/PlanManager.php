<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Services;

use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanPrice;
use Develupers\PlanUsage\Traits\ManagesCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PlanManager
{
    use ManagesCache;

    protected string $planModel;

    protected string $featureModel;

    protected string $planFeatureModel;

    public function __construct()
    {
        $this->planModel = config('plan-usage.models.plan');
        $this->featureModel = config('plan-usage.models.feature');
        $this->planFeatureModel = config('plan-usage.models.plan_feature');
    }

    /**
     * Get all available plans
     */
    public function getAllPlans(): Collection
    {
        return $this->cacheRemember(
            'plan-usage.plans',
            $this->getPlanCacheTags(),
            fn () => $this->planModel::with(['features', 'prices'])->get(),
            'plans'
        );
    }

    /**
     * Get a plan by ID or stripe_price_id (via PlanPrice)
     */
    public function findPlan(string|int $identifier): ?Plan
    {
        $planId = is_numeric($identifier) ? (int) $identifier : null;

        return $this->cacheRemember(
            "plan-usage.plan.{$identifier}",
            $this->getPlanCacheTags($planId),
            function () use ($identifier) {
                if (is_numeric($identifier)) {
                    return $this->planModel::with(['features', 'prices'])->find($identifier);
                }

                // Find plan by stripe_price_id through PlanPrice
                $planPrice = PlanPrice::where('stripe_price_id', $identifier)->first();

                if ($planPrice) {
                    return $this->planModel::with(['features', 'prices'])->find($planPrice->plan_id);
                }

                return null;
            },
            'plans'
        );
    }

    /**
     * Get features for a specific plan
     */
    public function getPlanFeatures(int $planId): Collection
    {
        return $this->cacheRemember(
            "plan-usage.plan.{$planId}.features",
            $this->getPlanCacheTags($planId),
            fn () => $this->planFeatureModel::where('plan_id', $planId)
                ->with('feature')
                ->get(),
            'features'
        );
    }

    /**
     * Check if a plan has a specific feature
     */
    public function planHasFeature(int $planId, string $featureSlug): bool
    {
        $tags = array_merge(
            $this->getPlanCacheTags($planId),
            $this->getFeatureCacheTags($featureSlug)
        );

        return $this->cacheRemember(
            "plan-usage.plan.{$planId}.has.{$featureSlug}",
            $tags,
            function () use ($planId, $featureSlug) {
                return $this->planFeatureModel::query()
                    ->where('plan_id', $planId)
                    ->whereHas('feature', fn ($q) => $q->where('slug', $featureSlug))
                    ->exists();
            },
            'features'
        );
    }

    /**
     * Get feature value for a plan
     */
    public function getFeatureValue(int $planId, string $featureSlug): mixed
    {
        $tags = array_merge(
            $this->getPlanCacheTags($planId),
            $this->getFeatureCacheTags($featureSlug)
        );

        return $this->cacheRemember(
            "plan-usage.plan.{$planId}.feature.{$featureSlug}",
            $tags,
            function () use ($planId, $featureSlug) {
                $planFeature = $this->planFeatureModel::query()
                    ->where('plan_id', $planId)
                    ->whereHas('feature', fn ($q) => $q->where('slug', $featureSlug))
                    ->with('feature')
                    ->first();

                if (! $planFeature) {
                    return null;
                }

                // Return value based on feature type
                return match ($planFeature->feature->type) {
                    'boolean' => (bool) $planFeature->value,
                    'limit', 'quota' => is_numeric($planFeature->value) ? (float) $planFeature->value : null,
                    default => $planFeature->value
                };
            },
            'features'
        );
    }

    /**
     * Compare two plans
     */
    public function comparePlans(int $planId1, int $planId2): array
    {
        $plan1 = $this->findPlan($planId1);
        $plan2 = $this->findPlan($planId2);

        if (! $plan1 || ! $plan2) {
            return [];
        }

        $allFeatures = $this->featureModel::all();
        $comparison = [];

        foreach ($allFeatures as $feature) {
            $value1 = $this->getFeatureValue($planId1, $feature->slug);
            $value2 = $this->getFeatureValue($planId2, $feature->slug);

            $comparison[$feature->slug] = [
                'feature' => $feature->name,
                'plan1' => $value1,
                'plan2' => $value2,
                'difference' => $this->calculateDifference($value1, $value2, $feature->type),
            ];
        }

        return $comparison;
    }

    /**
     * Calculate difference between feature values
     */
    protected function calculateDifference(mixed $value1, mixed $value2, string $type): mixed
    {
        if ($type === 'boolean') {
            return $value1 === $value2 ? 'same' : 'different';
        }

        if (in_array($type, ['limit', 'quota'])) {
            if (is_null($value1) || is_null($value2)) {
                return null;
            }

            return $value2 - $value1;
        }

        return null;
    }

    /**
     * Clear plan cache
     */
    public function clearCache(?int $planId = null): void
    {
        if (! config('plan-usage.cache.enabled', true)) {
            return;
        }

        if ($planId) {
            // Clear specific plan cache using tags if supported
            if ($this->supportsCacheTags()) {
                $this->cacheFlushTags($this->getPlanCacheTags($planId));
            } else {
                // Fallback to manual clearing
                Cache::forget("plan-usage.plan.{$planId}");
                Cache::forget("plan-usage.plan.{$planId}.features");

                // Clear all feature values for this plan
                $features = $this->featureModel::all();
                foreach ($features as $feature) {
                    Cache::forget("plan-usage.plan.{$planId}.feature.{$feature->slug}");
                    Cache::forget("plan-usage.plan.{$planId}.has.{$feature->slug}");
                }
            }
        } else {
            // Clear all plans cache
            if ($this->supportsCacheTags()) {
                $this->cacheFlushTags(['plan-usage', 'plans']);
            } else {
                Cache::forget('plan-usage.plans');

                // Clear all individual plan caches
                $plans = $this->planModel::all();
                foreach ($plans as $plan) {
                    $this->clearCache($plan->id);
                }
            }
        }
    }

    /**
     * Sync a billable model to a new plan
     */
    public function syncBillableToPlan(Model $billable, int $planId): void
    {
        $plan = $this->findPlan($planId);

        if (! $plan) {
            throw new \InvalidArgumentException("Plan with ID {$planId} not found");
        }

        // Update the billable's plan
        $billable->plan_id = $planId;
        $billable->plan_changed_at = now();
        $billable->save();

        // Clear cached quotas for this billable
        if (config('plan-usage.cache.enabled', true)) {
            Cache::forget("plan-usage.billable.{$billable->getMorphClass()}.{$billable->getKey()}.quotas");
        }
    }
}
