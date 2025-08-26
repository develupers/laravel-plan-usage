<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Traits;

use Carbon\Carbon;
use Develupers\PlanUsage\Events\UsageRecorded;
use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Usage;
use Illuminate\Support\Facades\DB;

trait TracksUsage
{
    /**
     * Record usage for a feature.
     */
    public function recordUsage(string $featureSlug, float $amount = 1, array $metadata = []): Usage
    {
        $feature = Feature::where('slug', $featureSlug)->firstOrFail();

        // Create usage record
        $usage = $this->usage()->create([
            'feature_id' => $feature->id,
            'used' => $amount,
            'period_start' => now(),
            'period_end' => $feature->getNextResetDate(),
            'metadata' => $metadata,
        ]);

        // Update quota if it exists
        if (in_array($feature->type, ['limit', 'quota'])) {
            $quota = $this->quotas()->where('feature_id', $feature->id)->first();
            if ($quota) {
                $quota->use($amount);
            }
        }

        // Fire event
        if (config('plan-usage.events.enabled')) {
            event(new UsageRecorded($this, $feature, $amount, $usage));
        }

        // Report to Stripe if enabled
        if (config('plan-usage.stripe.report_usage') && $feature->is_consumable) {
            $this->reportUsageToStripe($feature, $amount);
        }

        return $usage;
    }

    /**
     * Increment usage for a feature.
     */
    public function incrementUsage(string $featureSlug, float $amount = 1, array $metadata = []): Usage
    {
        return DB::transaction(function () use ($featureSlug, $amount, $metadata) {
            $feature = Feature::where('slug', $featureSlug)->firstOrFail();

            // Try to find existing usage record for current period
            $usage = $this->usage()
                ->where('feature_id', $feature->id)
                ->where('period_start', '<=', now())
                ->where(function ($query) {
                    $query->where('period_end', '>=', now())
                        ->orWhereNull('period_end');
                })
                ->first();

            if ($usage) {
                $usage->increment('used', $amount);
                if (! empty($metadata)) {
                    $usage->update([
                        'metadata' => array_merge($usage->metadata ?? [], $metadata),
                    ]);
                }
            } else {
                $usage = $this->recordUsage($featureSlug, $amount, $metadata);
            }

            return $usage;
        });
    }

    /**
     * Decrement usage for a feature.
     */
    public function decrementUsage(string $featureSlug, float $amount = 1): bool
    {
        return DB::transaction(function () use ($featureSlug, $amount) {
            $feature = Feature::where('slug', $featureSlug)->firstOrFail();

            // Update quota
            if (in_array($feature->type, ['limit', 'quota'])) {
                $quota = $this->quotas()->where('feature_id', $feature->id)->first();
                if ($quota && $quota->used >= $amount) {
                    $quota->decrement('used', $amount);

                    return true;
                }
            }

            return false;
        });
    }

    /**
     * Get usage for a feature within a period.
     */
    public function getUsage(string $featureSlug, $start = null, $end = null): float
    {
        $feature = Feature::where('slug', $featureSlug)->first();

        if (! $feature) {
            return 0;
        }

        $query = $this->usage()->where('feature_id', $feature->id);

        if ($start) {
            $query->where('period_start', '>=', Carbon::parse($start));
        }

        if ($end) {
            $query->where('period_end', '<=', Carbon::parse($end));
        }

        return (float) $query->sum('used');
    }

    /**
     * Get current period usage for a feature.
     */
    public function getCurrentUsage(string $featureSlug): float
    {
        $feature = Feature::where('slug', $featureSlug)->first();

        if (! $feature) {
            return 0;
        }

        // If feature has a quota, use that for current usage
        if (in_array($feature->type, ['limit', 'quota'])) {
            $quota = $this->quotas()->where('feature_id', $feature->id)->first();

            return $quota ? $quota->used : 0;
        }

        // Otherwise sum usage records for current period
        return (float) $this->usage()
            ->where('feature_id', $feature->id)
            ->where('period_start', '<=', now())
            ->where(function ($query) {
                $query->where('period_end', '>=', now())
                    ->orWhereNull('period_end');
            })
            ->sum('used');
    }

    /**
     * Get usage history for a feature.
     */
    public function getUsageHistory(string $featureSlug, int $limit = 10): \Illuminate\Support\Collection
    {
        $feature = Feature::where('slug', $featureSlug)->first();

        if (! $feature) {
            return collect();
        }

        return $this->usage()
            ->where('feature_id', $feature->id)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get aggregated usage by period.
     */
    public function getAggregatedUsage(string $featureSlug, string $period = 'daily', int $days = 30): \Illuminate\Support\Collection
    {
        $feature = Feature::where('slug', $featureSlug)->first();

        if (! $feature) {
            return collect();
        }

        $query = $this->usage()
            ->where('feature_id', $feature->id)
            ->where('created_at', '>=', now()->subDays($days));

        $groupBy = match ($period) {
            'hourly' => DB::raw('DATE_FORMAT(created_at, "%Y-%m-%d %H:00:00")'),
            'daily' => DB::raw('DATE(created_at)'),
            'weekly' => DB::raw('YEARWEEK(created_at)'),
            'monthly' => DB::raw('DATE_FORMAT(created_at, "%Y-%m")'),
            default => DB::raw('DATE(created_at)'),
        };

        return $query->select([
            $groupBy.' as period',
            DB::raw('SUM(used) as total_used'),
            DB::raw('COUNT(*) as count'),
            DB::raw('AVG(used) as average_used'),
        ])
            ->groupBy('period')
            ->orderBy('period')
            ->get();
    }

    /**
     * Report usage to Stripe for metered billing.
     */
    protected function reportUsageToStripe(Feature $feature, float $amount): void
    {
        if (! $this->subscribed() || ! method_exists($this, 'reportMeterEvent')) {
            return;
        }

        try {
            $this->reportMeterEvent($feature->slug, $amount);
        } catch (\Exception $e) {
            // Log error but don't fail the usage recording
            \Log::error('Failed to report usage to Stripe', [
                'feature' => $feature->slug,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Clear all usage for a feature.
     */
    public function clearUsage(string $featureSlug): void
    {
        $feature = Feature::where('slug', $featureSlug)->first();

        if (! $feature) {
            return;
        }

        // Clear usage records
        $this->usage()->where('feature_id', $feature->id)->delete();

        // Reset quota if exists
        if (in_array($feature->type, ['limit', 'quota'])) {
            $quota = $this->quotas()->where('feature_id', $feature->id)->first();
            if ($quota) {
                $quota->update(['used' => 0]);
            }
        }
    }
}
