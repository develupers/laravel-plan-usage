<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Services;

use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Plan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PlanManager
{
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
        return Cache::remember('plan-usage.plans',
            config('plan-usage.cache.ttl', 3600),
            fn () => $this->planModel::with('features')->get()
        );
    }

    /**
     * Get a plan by ID or stripe_price_id
     */
    public function findPlan(string|int $identifier): ?Plan
    {
        return Cache::remember("plan-usage.plan.{$identifier}",
            config('plan-usage.cache.ttl', 3600),
            function () use ($identifier) {
                if (is_numeric($identifier)) {
                    return $this->planModel::with('features')->find($identifier);
                }

                return $this->planModel::with('features')
                    ->where('stripe_price_id', $identifier)
                    ->first();
            }
        );
    }

    /**
     * Get features for a specific plan
     */
    public function getPlanFeatures(int $planId): Collection
    {
        return Cache::remember("plan-usage.plan.{$planId}.features",
            config('plan-usage.cache.ttl', 3600),
            fn () => $this->planFeatureModel::where('plan_id', $planId)
                ->with('feature')
                ->get()
        );
    }

    /**
     * Check if a plan has a specific feature
     */
    public function planHasFeature(int $planId, string $featureSlug): bool
    {
        return Cache::remember("plan-usage.plan.{$planId}.has.{$featureSlug}",
            config('plan-usage.cache.ttl', 3600),
            function () use ($planId, $featureSlug) {
                return $this->planFeatureModel::query()
                    ->where('plan_id', $planId)
                    ->whereHas('feature', fn ($q) => $q->where('slug', $featureSlug))
                    ->exists();
            }
        );
    }

    /**
     * Get feature value for a plan
     */
    public function getFeatureValue(int $planId, string $featureSlug): mixed
    {
        return Cache::remember("plan-usage.plan.{$planId}.feature.{$featureSlug}",
            config('plan-usage.cache.ttl', 3600),
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
            }
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
        if ($planId) {
            Cache::forget("plan-usage.plan.{$planId}");
            Cache::forget("plan-usage.plan.{$planId}.features");
            Cache::deleteMultiple(
                Cache::get("plan-usage.plan.{$planId}.feature.*") ?? []
            );
        } else {
            Cache::forget('plan-usage.plans');
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
        Cache::forget("plan-usage.billable.{$billable->getMorphClass()}.{$billable->getKey()}.quotas");
    }
}
