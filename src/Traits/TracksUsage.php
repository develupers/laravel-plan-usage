<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Traits;

use Carbon\CarbonInterface;
use Develupers\PlanUsage\Models\Usage;
use Develupers\PlanUsage\Services\UsageTracker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

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
    protected function usageTracker(): UsageTracker
    {
        return app('plan-usage.tracker');
    }

    /**
     * Log usage for a feature (log only, no quota enforcement).
     *
     * This logs to the usage table without checking or incrementing quotas.
     * For full enforcement + logging, use consume() instead.
     *
     * @see EnforcesQuotas::consume()
     */
    public function logUsage(string $featureSlug, float $quantity = 1.0, array $metadata = []): bool
    {
        try {
            $usage = $this->usageTracker()->record($this, $featureSlug, $quantity, $metadata);

            return $usage !== null;
        } catch (\Exception $e) {
            Log::error('Failed to record usage', [
                'feature' => $featureSlug,
                'quantity' => $quantity,
                'billable_type' => $this->getMorphClass(),
                'billable_id' => $this->getKey(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get usage for a feature within a period.
     */
    public function getUsage(string $featureSlug, ?CarbonInterface $from = null, ?CarbonInterface $to = null): float
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
    public function getUsageHistory(?string $featureSlug = null, ?int $limit = 10): Collection
    {
        return $this->usageTracker()->getHistory($this, $featureSlug, $limit);
    }

    /**
     * Get usage for a specific period.
     */
    public function getUsageForPeriod(string $featureSlug, CarbonInterface $from, CarbonInterface $to): float
    {
        return $this->usageTracker()->getUsage($this, $featureSlug, $from, $to);
    }

    /**
     * Get aggregated usage statistics.
     */
    public function getUsageStatistics(
        string $featureSlug,
        CarbonInterface $from,
        CarbonInterface $to,
        string $groupBy = 'day'
    ): Collection {
        return $this->usageTracker()->getStatistics($this, $featureSlug, $from, $to, $groupBy);
    }

    /**
     * Reset usage for a feature.
     */
    public function resetUsage(string $featureSlug, ?CarbonInterface $periodStart = null): void
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
