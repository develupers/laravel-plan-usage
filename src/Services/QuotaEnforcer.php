<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Services;

use Carbon\CarbonInterface;
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
use Illuminate\Support\Facades\Log;

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
     * Validate that amount is positive.
     */
    protected function validateAmount(float $amount): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be a positive number.');
        }
    }

    /**
     * Check if a billable can use a feature
     */
    public function canUse(Model $billable, string $featureSlug, float $amount = 1): bool
    {
        $this->validateAmount($amount);
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
     * Enforce quota for a feature (atomic check-and-increment)
     */
    public function enforce(Model $billable, string $featureSlug, float $amount = 1): bool
    {
        $this->validateAmount($amount);

        $quota = $this->getOrCreateQuota($billable, $featureSlug);

        if (! $quota) {
            return false;
        }

        return DB::transaction(function () use ($billable, $featureSlug, $amount, $quota) {
            $lockedQuota = $this->quotaModel::lockForUpdate()->find($quota->id);

            if (! $lockedQuota) {
                return false;
            }

            // Check if quota needs reset under lock
            if ($this->shouldReset($lockedQuota)) {
                $lockedQuota->used = 0;
                $lockedQuota->reset_at = $this->calculateResetTime($lockedQuota->feature);
                $lockedQuota->save();
            }

            // Unlimited quota
            if (is_null($lockedQuota->limit)) {
                $lockedQuota->increment('used', $amount);
                $this->clearQuotaCache($billable);

                return true;
            }

            // Check with grace
            $graceAmount = $this->getGraceAmount($lockedQuota);
            $effectiveLimit = $lockedQuota->limit + $graceAmount;

            if (($lockedQuota->used + $amount) > $effectiveLimit) {
                $feature = $this->featureModel::where('slug', $featureSlug)->first();
                Event::dispatch(new QuotaExceeded($billable, $feature, $lockedQuota));

                return false;
            }

            $lockedQuota->increment('used', $amount);
            $this->checkWarningThreshold($billable, $featureSlug, $lockedQuota->fresh());
            $this->clearQuotaCache($billable);

            return true;
        });
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

        if (! empty($billable->plan_id)) {
            $plan = $this->planManager->findPlan($billable->plan_id);
            if (! $plan || ! $plan->hasFeature($featureSlug)) {
                return null;
            }
            $featureValue = $this->planManager->getFeatureValue($billable->plan_id, $featureSlug);
        }

        // Check for an existing quota first
        $existingQuota = $this->quotaModel::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->where('feature_id', $feature->id)
            ->first();

        if ($existingQuota) {
            return $existingQuota;
        }

        // Don't create new quotas without a plan
        if (empty($billable->plan_id)) {
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
     * Increment quota usage (atomic with row locking)
     */
    public function increment(Model $billable, string $featureSlug, float $amount = 1): void
    {
        $this->validateAmount($amount);

        $quota = $this->getOrCreateQuota($billable, $featureSlug);

        if (! $quota) {
            Log::warning('Cannot increment quota: feature not available for billable', [
                'feature' => $featureSlug,
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => $billable->getKey(),
            ]);

            return;
        }

        DB::transaction(function () use ($quota, $billable, $featureSlug, $amount) {
            $lockedQuota = $this->quotaModel::lockForUpdate()->find($quota->id);

            if (! $lockedQuota) {
                return;
            }

            // Check if quota needs reset under lock
            if ($this->shouldReset($lockedQuota)) {
                $lockedQuota->used = 0;
                $lockedQuota->reset_at = $this->calculateResetTime($lockedQuota->feature);
                $lockedQuota->save();
            }

            $lockedQuota->increment('used', $amount);

            // Check for warning threshold
            $this->checkWarningThreshold($billable, $featureSlug, $lockedQuota->fresh());
        });

        // Clear cache
        $this->clearQuotaCache($billable);
    }

    /**
     * Decrement quota usage (atomic, floors at 0)
     */
    public function decrement(Model $billable, string $featureSlug, float $amount = 1): void
    {
        $this->validateAmount($amount);

        $quota = $this->getQuota($billable, $featureSlug);

        if (! $quota) {
            Log::warning('Cannot decrement quota: no quota found for feature', [
                'feature' => $featureSlug,
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => $billable->getKey(),
            ]);

            return;
        }

        $this->quotaModel::where('id', $quota->id)
            ->update(['used' => DB::raw('MAX(used - '.(float) $amount.', 0)')]);

        $this->clearQuotaCache($billable);
    }

    /**
     * Reset quota for a billable (explicit/user-initiated reset)
     */
    public function reset(Model $billable, string $featureSlug): void
    {
        $quota = $this->getQuota($billable, $featureSlug);

        if (! $quota) {
            return;
        }

        DB::transaction(function () use ($quota) {
            $locked = $this->quotaModel::lockForUpdate()->find($quota->id);

            if (! $locked) {
                return;
            }

            $locked->used = 0;
            $locked->reset_at = $this->calculateResetTime($locked->feature);
            $locked->save();
        });

        $quota->refresh();
        $this->clearQuotaCache($billable);
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
     * Get used quota amount
     */
    public function getUsed(Model $billable, string $featureSlug): float
    {
        $quota = $this->getQuota($billable, $featureSlug);

        return $quota ? $quota->used : 0;
    }

    /**
     * Get quota limit
     */
    public function getLimit(Model $billable, string $featureSlug): ?float
    {
        $quota = $this->getQuota($billable, $featureSlug);

        return $quota ? $quota->limit : null;
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

        if (! $quota || is_null($quota->limit)) {
            return null;
        }

        if ($quota->limit == 0) {
            return 100.0;
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
     * Reset a quota (atomic with row locking)
     */
    protected function resetQuota(Quota $quota): void
    {
        DB::transaction(function () use ($quota) {
            $locked = $this->quotaModel::lockForUpdate()->find($quota->id);

            if (! $locked) {
                return;
            }

            // Re-check under lock — another process may have already reset
            if (! $this->shouldReset($locked)) {
                return;
            }

            $locked->used = 0;
            $locked->reset_at = $this->calculateResetTime($locked->feature);
            $locked->save();
        });

        $quota->refresh();
    }

    /**
     * Calculate the next reset time
     */
    protected function calculateResetTime(Feature $feature): ?CarbonInterface
    {
        if (! $feature->reset_period) {
            return null;
        }

        return $feature->reset_period->getNextResetDate();
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

        // Check the highest crossed threshold (sort descending)
        rsort($warningThresholds);

        foreach ($warningThresholds as $threshold) {
            if ($usagePercentage >= $threshold) {
                $feature = $this->featureModel::where('slug', $featureSlug)->first();
                Event::dispatch(new QuotaWarning($billable, $feature, (int) $usagePercentage, $quota));
                break;
            }
        }
    }

    /**
     * Clear quota cache for a billable
     */
    public function clearQuotaCache(Model $billable): void
    {
        if (! config('plan-usage.cache.enabled', true)) {
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
