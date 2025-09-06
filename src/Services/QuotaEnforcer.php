<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Services;

use Develupers\PlanUsage\Events\QuotaExceeded;
use Develupers\PlanUsage\Events\QuotaWarning;
use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Quota;
use Develupers\PlanUsage\Traits\ManagesCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class QuotaEnforcer
{
    use ManagesCache;
    protected string $quotaModel;

    protected string $featureModel;

    protected PlanManager $planManager;

    protected UsageTracker $usageTracker;

    public function __construct()
    {
        $this->quotaModel = config('plan-usage.models.quota');
        $this->featureModel = config('plan-usage.models.feature');
        $this->planManager = app('plan-usage.manager');
        $this->usageTracker = app('plan-usage.tracker');
    }

    /**
     * Check if a billable can use a feature
     */
    public function canUse(Model $billable, string $featureSlug, float $amount = 1): bool
    {
        $quota = $this->getOrCreateQuota($billable, $featureSlug);

        if (! $quota) {
            return false;
        }

        // Unlimited quota
        if (is_null($quota->limit)) {
            return true;
        }

        // Check if adding amount would exceed limit (with grace)
        $graceAmount = $this->getGraceAmount($quota);

        return ($quota->used + $amount) <= ($quota->limit + $graceAmount);
    }

    /**
     * Enforce quota for a feature
     */
    public function enforce(Model $billable, string $featureSlug, float $amount = 1): bool
    {
        if (! $this->canUse($billable, $featureSlug, $amount)) {
            $quota = $this->getQuota($billable, $featureSlug);
            $feature = $this->featureModel::where('slug', $featureSlug)->first();
            Event::dispatch(new QuotaExceeded($billable, $feature, $quota));

            return false;
        }

        $this->increment($billable, $featureSlug, $amount);

        return true;
    }

    /**
     * Get or create a quota for a billable
     */
    public function getOrCreateQuota(Model $billable, string $featureSlug): ?Quota
    {
        $feature = $this->featureModel::where('slug', $featureSlug)->first();

        if (! $feature) {
            return null;
        }

        // Check if billable has a plan with this feature
        $featureValue = null;
        $hasFeature = false;

        if (isset($billable->plan_id)) {
            $plan = $this->planManager->findPlan($billable->plan_id);
            if ($plan && $plan->hasFeature($featureSlug)) {
                $hasFeature = true;
                $featureValue = $this->planManager->getFeatureValue($billable->plan_id, $featureSlug);
            }
        }

        // If billable doesn't have the feature in their plan, return null
        if (isset($billable->plan_id) && ! $hasFeature) {
            return null;
        }

        return $this->quotaModel::firstOrCreate(
            [
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => $billable->getKey(),
                'feature_id' => $feature->id,
            ],
            [
                'limit' => $featureValue, // Can be null for unlimited
                'used' => 0,
                'reset_at' => $this->calculateResetTime($feature),
            ]
        );
    }

    /**
     * Get quota for a billable
     */
    public function getQuota(Model $billable, string $featureSlug): ?Quota
    {
        $feature = $this->featureModel::where('slug', $featureSlug)->first();

        if (! $feature) {
            return null;
        }

        return $this->quotaModel::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->where('feature_id', $feature->id)
            ->first();
    }

    /**
     * Get all quotas for a billable
     */
    public function getAllQuotas(Model $billable): \Illuminate\Support\Collection
    {
        $cacheKey = "plan-usage.billable.{$billable->getMorphClass()}.{$billable->getKey()}.quotas";
        $tags = $this->getQuotaCacheTags($billable->getMorphClass(), $billable->getKey());

        return $this->cacheRemember($cacheKey, $tags, function () use ($billable) {
            return $this->quotaModel::query()
                ->where('billable_type', $billable->getMorphClass())
                ->where('billable_id', $billable->getKey())
                ->with('feature')
                ->get();
        }, 'quotas');
    }

    /**
     * Increment quota usage
     */
    public function increment(Model $billable, string $featureSlug, float $amount = 1): void
    {
        $quota = $this->getOrCreateQuota($billable, $featureSlug);

        if (! $quota) {
            return;
        }

        // Check if quota needs reset
        if ($this->shouldReset($quota)) {
            $this->resetQuota($quota);
        }

        $quota->increment('used', $amount);

        // Check for warning threshold
        $this->checkWarningThreshold($billable, $featureSlug, $quota);

        // Clear cache
        $this->clearQuotaCache($billable);
    }

    /**
     * Decrement quota usage
     */
    public function decrement(Model $billable, string $featureSlug, float $amount = 1): void
    {
        $quota = $this->getQuota($billable, $featureSlug);

        if (! $quota) {
            return;
        }

        $quota->decrement('used', $amount);

        // Ensure used doesn't go below 0
        if ($quota->used < 0) {
            $quota->used = 0;
            $quota->save();
        }

        $this->clearQuotaCache($billable);
    }

    /**
     * Reset quota for a billable
     */
    public function reset(Model $billable, string $featureSlug): void
    {
        $quota = $this->getQuota($billable, $featureSlug);

        if ($quota) {
            $this->resetQuota($quota);
            $this->clearQuotaCache($billable);
        }
    }

    /**
     * Reset all quotas for a billable
     */
    public function resetAll(Model $billable): void
    {
        $this->quotaModel::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->update([
                'used' => 0,
                'reset_at' => DB::raw('NOW()'),
            ]);

        $this->clearQuotaCache($billable);
    }

    /**
     * Get remaining quota
     */
    public function getRemaining(Model $billable, string $featureSlug): ?float
    {
        $quota = $this->getQuota($billable, $featureSlug);

        if (! $quota) {
            return null;
        }

        if (is_null($quota->limit)) {
            return null; // Unlimited
        }

        return max(0, $quota->limit - $quota->used);
    }

    /**
     * Get quota usage percentage
     */
    public function getUsagePercentage(Model $billable, string $featureSlug): ?float
    {
        $quota = $this->getQuota($billable, $featureSlug);

        if (! $quota || is_null($quota->limit) || $quota->limit == 0) {
            return null;
        }

        return round(($quota->used / $quota->limit) * 100, 2);
    }

    /**
     * Check if quota should be reset
     */
    protected function shouldReset(Quota $quota): bool
    {
        if (! $quota->reset_at) {
            return false;
        }

        return Carbon::parse($quota->reset_at)->isPast();
    }

    /**
     * Reset a quota
     */
    protected function resetQuota(Quota $quota): void
    {
        $quota->used = 0;
        $quota->reset_at = $this->calculateResetTime($quota->feature);
        $quota->save();
    }

    /**
     * Calculate next reset time
     */
    protected function calculateResetTime(Feature $feature): ?Carbon
    {
        if (! $feature->reset_period) {
            return null;
        }

        return match ($feature->reset_period) {
            'hourly' => now()->addHour()->startOfHour(),
            'daily' => now()->addDay()->startOfDay(),
            'weekly' => now()->addWeek()->startOfWeek(),
            'monthly' => now()->addMonth()->startOfMonth(),
            'yearly' => now()->addYear()->startOfYear(),
            default => null,
        };
    }

    /**
     * Get grace amount for soft limits
     */
    protected function getGraceAmount(Quota $quota): float
    {
        if (! config('plan-usage.quota.soft_limit', false)) {
            return 0;
        }

        $gracePercentage = config('plan-usage.quota.grace_percentage', 10);

        if (is_null($quota->limit)) {
            return 0;
        }

        return $quota->limit * ($gracePercentage / 100);
    }

    /**
     * Check if warning threshold is reached
     */
    protected function checkWarningThreshold(Model $billable, string $featureSlug, Quota $quota): void
    {
        if (is_null($quota->limit)) {
            return;
        }

        $warningThresholds = config('plan-usage.quota.warning_thresholds', [80, 100]);
        $usagePercentage = ($quota->used / $quota->limit) * 100;

        // Check if any warning threshold is crossed
        foreach ($warningThresholds as $threshold) {
            if ($usagePercentage >= $threshold && $usagePercentage < ($threshold + 0.01)) {
                $feature = $this->featureModel::where('slug', $featureSlug)->first();
                Event::dispatch(new QuotaWarning($billable, $feature, (int) $usagePercentage, $quota));
                break;
            }
        }
    }

    /**
     * Clear quota cache for a billable
     */
    protected function clearQuotaCache(Model $billable): void
    {
        if (!config('plan-usage.cache.enabled', true)) {
            return;
        }

        // Clear using tags if supported
        if ($this->supportsCacheTags()) {
            $tags = $this->getQuotaCacheTags($billable->getMorphClass(), $billable->getKey());
            $this->cacheFlushTags($tags);
        } else {
            // Fallback to manual clearing
            $cacheKey = "plan-usage.billable.{$billable->getMorphClass()}.{$billable->getKey()}.quotas";
            Cache::forget($cacheKey);
        }
    }

    /**
     * Sync quotas with plan features
     */
    public function syncWithPlan(Model $billable): void
    {
        if (! isset($billable->plan_id)) {
            return;
        }

        $planFeatures = $this->planManager->getPlanFeatures($billable->plan_id);

        foreach ($planFeatures as $planFeature) {
            $quota = $this->getOrCreateQuota($billable, $planFeature->feature->slug);

            if ($quota && $quota->limit != $planFeature->value) {
                $quota->limit = is_numeric($planFeature->value) ? (float) $planFeature->value : null;
                $quota->save();
            }
        }

        $this->clearQuotaCache($billable);
    }
}
