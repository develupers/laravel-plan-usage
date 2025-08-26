<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Traits;

use Develupers\PlanUsage\Events\QuotaExceeded;
use Develupers\PlanUsage\Events\QuotaWarning;
use Develupers\PlanUsage\Exceptions\QuotaExceededException;
use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Quota;
use Illuminate\Support\Facades\Cache;

trait EnforcesQuotas
{
    /**
     * Check if a feature quota is exceeded.
     */
    public function isQuotaExceeded(string $featureSlug): bool
    {
        $quota = $this->getQuotaForFeature($featureSlug);

        return $quota ? $quota->isExceeded() : false;
    }

    /**
     * Check if a feature quota can be used.
     */
    public function canUseQuota(string $featureSlug, float $amount = 1): bool
    {
        $quota = $this->getQuotaForFeature($featureSlug);

        return $quota ? $quota->canUse($amount) : true;
    }

    /**
     * Use quota for a feature.
     */
    public function useQuota(string $featureSlug, float $amount = 1): Quota
    {
        $quota = $this->getOrCreateQuotaForFeature($featureSlug);

        // Check if quota can be used
        if (! $quota->canUse($amount)) {
            if (config('plan-usage.quota.throw_exception', true)) {
                throw new QuotaExceededException(
                    "Quota exceeded for feature {$featureSlug}",
                    $featureSlug,
                    $quota->limit,
                    $quota->used
                );
            }
        }

        // Use the quota
        $quota->use($amount);

        // Check for warning thresholds
        $this->checkQuotaWarnings($quota);

        // Clear cache
        $this->clearQuotaCache($featureSlug);

        return $quota;
    }

    /**
     * Get quota for a feature.
     */
    public function getQuotaForFeature(string $featureSlug): ?Quota
    {
        $feature = Feature::where('slug', $featureSlug)->first();

        if (! $feature) {
            return null;
        }

        // Try to get from cache first
        if (config('plan-usage.cache.enabled')) {
            $cacheKey = $this->getQuotaCacheKey($featureSlug);

            return Cache::remember($cacheKey, config('plan-usage.cache.ttl'), function () use ($feature) {
                return $this->quotas()->where('feature_id', $feature->id)->first();
            });
        }

        return $this->quotas()->where('feature_id', $feature->id)->first();
    }

    /**
     * Get or create quota for a feature.
     */
    public function getOrCreateQuotaForFeature(string $featureSlug): Quota
    {
        $feature = Feature::where('slug', $featureSlug)->firstOrFail();

        $quota = $this->quotas()->firstOrCreate(
            ['feature_id' => $feature->id],
            [
                'limit' => $this->plan ? $this->plan->getFeatureValue($featureSlug) : null,
                'used' => 0,
                'reset_at' => $feature->getNextResetDate(),
            ]
        );

        // Reset if needed
        if ($quota->needsReset()) {
            $quota->reset();
        }

        return $quota;
    }

    /**
     * Set quota limit for a feature.
     */
    public function setQuotaLimit(string $featureSlug, ?float $limit): Quota
    {
        $quota = $this->getOrCreateQuotaForFeature($featureSlug);

        $quota->update(['limit' => $limit]);

        // Clear cache
        $this->clearQuotaCache($featureSlug);

        return $quota;
    }

    /**
     * Increase quota limit for a feature.
     */
    public function increaseQuotaLimit(string $featureSlug, float $amount): Quota
    {
        $quota = $this->getOrCreateQuotaForFeature($featureSlug);

        if ($quota->limit !== null) {
            $quota->increment('limit', $amount);
        }

        // Clear cache
        $this->clearQuotaCache($featureSlug);

        return $quota;
    }

    /**
     * Reset quota for a feature.
     */
    public function resetQuota(string $featureSlug): Quota
    {
        $quota = $this->getOrCreateQuotaForFeature($featureSlug);

        $quota->reset();

        // Clear cache
        $this->clearQuotaCache($featureSlug);

        return $quota;
    }

    /**
     * Reset all quotas.
     */
    public function resetAllQuotas(): void
    {
        $this->quotas->each(function ($quota) {
            $quota->reset();
        });

        // Clear all quota cache
        if (config('plan-usage.cache.enabled')) {
            Cache::flush();
        }
    }

    /**
     * Check and fire warning events for quota thresholds.
     */
    protected function checkQuotaWarnings(Quota $quota): void
    {
        $threshold = $quota->isAtWarningThreshold();

        if ($threshold && config('plan-usage.events.enabled')) {
            event(new QuotaWarning($this, $quota->feature, $threshold, $quota));
        }

        if ($quota->isExceeded() && config('plan-usage.events.enabled')) {
            event(new QuotaExceeded($this, $quota->feature, $quota));
        }
    }

    /**
     * Get cache key for a feature quota.
     */
    protected function getQuotaCacheKey(string $featureSlug): string
    {
        $prefix = config('plan-usage.cache.prefix', 'plan_feature_usage');
        $billableKey = get_class($this).':'.$this->getKey();

        return "{$prefix}:quota:{$billableKey}:{$featureSlug}";
    }

    /**
     * Clear quota cache for a feature.
     */
    protected function clearQuotaCache(string $featureSlug): void
    {
        if (config('plan-usage.cache.enabled')) {
            Cache::forget($this->getQuotaCacheKey($featureSlug));
        }
    }

    /**
     * Enforce quota for a feature (throws exception if exceeded).
     */
    public function enforceQuota(string $featureSlug, float $amount = 1): void
    {
        if (! $this->canUseQuota($featureSlug, $amount)) {
            $quota = $this->getQuotaForFeature($featureSlug);

            throw new QuotaExceededException(
                "Quota exceeded for feature {$featureSlug}. Limit: {$quota->limit}, Used: {$quota->used}, Requested: {$amount}",
                $featureSlug,
                $quota->limit,
                $quota->used
            );
        }
    }

    /**
     * Get all quotas with their status.
     */
    public function getQuotasStatus(): \Illuminate\Support\Collection
    {
        return $this->quotas->map(function ($quota) {
            return [
                'feature' => $quota->feature->slug,
                'name' => $quota->feature->name,
                'limit' => $quota->limit,
                'used' => $quota->used,
                'remaining' => $quota->remaining(),
                'percentage' => $quota->usagePercentage(),
                'exceeded' => $quota->isExceeded(),
                'warning_threshold' => $quota->isAtWarningThreshold(),
                'reset_at' => $quota->reset_at,
            ];
        });
    }
}
