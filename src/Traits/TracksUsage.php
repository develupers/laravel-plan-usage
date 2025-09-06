<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Traits;

use Develupers\PlanUsage\Models\Usage;

/**
 * Trait TracksUsage
 *
 * Provides convenient methods for usage tracking on billable models.
 * All methods delegate to the UsageTracker service for consistency.
 */
trait TracksUsage
{
    /**
     * Get the usage tracker service instance.
     */
    protected function usageTracker(): \Develupers\PlanUsage\Services\UsageTracker
    {
        return app('plan-usage.tracker');
    }

    /**
     * Record usage for a feature.
     */
    public function recordUsage(string $featureSlug, float $amount = 1, ?array $metadata = null): Usage
    {
        // Record usage through service
        $usage = $this->usageTracker()->record($this, $featureSlug, $amount, $metadata);

        // Also update quota through the quota enforcer
        if (method_exists($this, 'incrementQuotaUsage')) {
            $this->incrementQuotaUsage($featureSlug, $amount);
        }

        return $usage;
    }

    /**
     * Get usage for a feature within a period.
     */
    public function getUsage(string $featureSlug, ?\Carbon\Carbon $from = null, ?\Carbon\Carbon $to = null): float
    {
        return $this->usageTracker()->getUsage($this, $featureSlug, $from, $to);
    }

    /**
     * Get current period usage for a feature.
     */
    public function getCurrentUsage(string $featureSlug): float
    {
        return $this->usageTracker()->getCurrentPeriodUsage($this, $featureSlug);
    }

    /**
     * Get usage history for a feature.
     */
    public function getUsageHistory(?string $featureSlug = null, ?int $limit = 10): \Illuminate\Support\Collection
    {
        return $this->usageTracker()->getHistory($this, $featureSlug, $limit);
    }

    /**
     * Get usage for a specific period.
     */
    public function getUsageForPeriod(string $featureSlug, \Carbon\Carbon $from, \Carbon\Carbon $to): float
    {
        return $this->usageTracker()->getUsage($this, $featureSlug, $from, $to);
    }

    /**
     * Get aggregated usage statistics.
     */
    public function getUsageStatistics(
        string $featureSlug,
        \Carbon\Carbon $from,
        \Carbon\Carbon $to,
        string $groupBy = 'day'
    ): \Illuminate\Support\Collection {
        return $this->usageTracker()->getStatistics($this, $featureSlug, $from, $to, $groupBy);
    }

    /**
     * Reset usage for a feature.
     */
    public function resetUsage(string $featureSlug, ?\Carbon\Carbon $periodStart = null): void
    {
        $this->usageTracker()->resetUsage($this, $featureSlug, $periodStart);

        // Also reset quota if applicable
        if (method_exists($this, 'resetQuota')) {
            $this->resetQuota($featureSlug);
        }
    }

    /**
     * Report usage to Stripe (if configured).
     */
    public function reportUsageToStripe(string $featureSlug, float $amount): void
    {
        $this->usageTracker()->reportToStripe($this, $featureSlug, $amount);
    }
}
