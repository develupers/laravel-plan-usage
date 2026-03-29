<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Traits;

use Develupers\PlanUsage\Exceptions\QuotaExceededException;
use Develupers\PlanUsage\Models\Quota;

/**
 * Trait EnforcesQuotas
 *
 * Provides convenient methods for quota enforcement on billable models.
 * All methods delegate to the QuotaEnforcer service for consistency.
 */
trait EnforcesQuotas
{
    /**
     * Get the quota enforcer service instance.
     */
    protected function quotaEnforcer(): \Develupers\PlanUsage\Services\QuotaEnforcer
    {
        return app('plan-usage.quota');
    }

    /**
     * Check if a feature's quota limit has been reached.
     */
    public function isLimitReached(string $featureSlug): bool
    {
        $quota = $this->quotaEnforcer()->getQuota($this, $featureSlug);

        if (! $quota) {
            return false;
        }

        return $quota->isLimitReached();
    }

    /**
     * Check if the billable can use a feature (read-only, no side effects).
     *
     * @param  string  $featureSlug  The feature to check
     * @param  float  $amount  The amount to check (default: 1)
     * @return bool
     *
     * Behavior by feature type:
     * - boolean: Always returns true if feature is enabled
     * - quota: Checks if usage + amount <= limit (resets periodically)
     * - limit: Checks if current count + amount <= limit (never resets)
     */
    public function checkQuota(string $featureSlug, float $amount = 1): bool
    {
        return $this->quotaEnforcer()->canUse($this, $featureSlug, $amount);
    }

    /**
     * Consume a feature: enforces limit, increments quota, and logs usage.
     *
     * This is the primary method for using a feature. Returns false if
     * the quota would be exceeded. Use this OR the CheckQuota + ConsumeQuota
     * middleware — not both.
     */
    public function consume(string $featureSlug, float $amount = 1, array $metadata = []): bool
    {
        $allowed = $this->quotaEnforcer()->enforce($this, $featureSlug, $amount);

        if ($allowed) {
            try {
                $this->usageTracker()->record($this, $featureSlug, $amount, $metadata ?: null);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to record usage after quota enforcement', [
                    'feature' => $featureSlug,
                    'amount' => $amount,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $allowed;
    }

    /**
     * Get quota for a feature.
     */
    public function getQuotaForFeature(string $featureSlug): ?Quota
    {
        return $this->quotaEnforcer()->getQuota($this, $featureSlug);
    }

    /**
     * Get or create quota for a feature.
     */
    public function getOrCreateQuotaForFeature(string $featureSlug): ?Quota
    {
        return $this->quotaEnforcer()->getOrCreateQuota($this, $featureSlug);
    }

    /**
     * Get remaining quota for a feature.
     */
    public function getRemainingQuota(string $featureSlug): ?float
    {
        return $this->quotaEnforcer()->getRemaining($this, $featureSlug);
    }

    /**
     * Get quota usage percentage.
     */
    public function getQuotaUsagePercentage(string $featureSlug): ?float
    {
        return $this->quotaEnforcer()->getUsagePercentage($this, $featureSlug);
    }

    /**
     * Reset quota for a feature.
     */
    public function resetQuota(string $featureSlug): void
    {
        $this->quotaEnforcer()->reset($this, $featureSlug);
    }

    /**
     * Reset all quotas.
     */
    public function resetAllQuotas(): void
    {
        $this->quotaEnforcer()->resetAll($this);
    }

    /**
     * Increment quota usage.
     */
    public function incrementQuotaUsage(string $featureSlug, float $amount = 1): void
    {
        $this->quotaEnforcer()->increment($this, $featureSlug, $amount);
    }

    /**
     * Decrement quota usage.
     */
    public function decrementQuotaUsage(string $featureSlug, float $amount = 1): void
    {
        $this->quotaEnforcer()->decrement($this, $featureSlug, $amount);
    }

    /**
     * Enforce quota for a feature (throws exception if exceeded).
     */
    public function enforceQuota(string $featureSlug, float $amount = 1): void
    {
        if (! $this->quotaEnforcer()->enforce($this, $featureSlug, $amount)) {
            $quota = $this->getQuotaForFeature($featureSlug);

            throw new QuotaExceededException(
                "Quota exceeded for feature {$featureSlug}",
                $featureSlug,
                $quota ? $quota->limit : null,
                $quota ? $quota->used : 0
            );
        }
    }

    /**
     * Get all quotas with their status.
     */
    public function getQuotasStatus(): \Illuminate\Support\Collection
    {
        $quotas = $this->quotaEnforcer()->getAllQuotas($this);

        return $quotas->map(function ($quota) {
            return [
                'feature' => $quota->feature->slug,
                'name' => $quota->feature->name,
                'limit' => $quota->limit,
                'used' => $quota->used,
                'remaining' => $this->quotaEnforcer()->getRemaining($this, $quota->feature->slug),
                'percentage' => $this->quotaEnforcer()->getUsagePercentage($this, $quota->feature->slug),
                'limit_reached' => $quota->isLimitReached(),
                'reset_at' => $quota->reset_at,
            ];
        });
    }

    /**
     * Sync quotas with current plan.
     */
    public function syncQuotas(): void
    {
        $this->quotaEnforcer()->syncWithPlan($this);
    }
}
