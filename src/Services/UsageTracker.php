<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Services;

use Develupers\PlanUsage\Models\Usage;
use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Events\UsageRecorded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Carbon\Carbon;

class UsageTracker
{
    protected string $usageModel;
    protected string $featureModel;

    public function __construct()
    {
        $this->usageModel = config('plan-usage.models.usage');
        $this->featureModel = config('plan-usage.models.feature');
    }

    /**
     * Record usage for a billable model
     */
    public function record(
        Model $billable,
        string $featureSlug,
        float $amount,
        ?array $metadata = null,
        ?Carbon $timestamp = null
    ): Usage {
        $feature = $this->featureModel::where('slug', $featureSlug)->firstOrFail();
        
        // Check if we should aggregate or create new record
        if ($this->shouldAggregate($feature)) {
            return $this->aggregateUsage($billable, $feature, $amount, $metadata, $timestamp);
        }

        return $this->createUsageRecord($billable, $feature, $amount, $metadata, $timestamp);
    }

    /**
     * Create a new usage record
     */
    protected function createUsageRecord(
        Model $billable,
        Feature $feature,
        float $amount,
        ?array $metadata,
        ?Carbon $timestamp
    ): Usage {
        $timestamp = $timestamp ?? now();
        
        $usage = $this->usageModel::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'feature_id' => $feature->id,
            'used' => $amount,
            'period_start' => $this->getPeriodStart($feature, $timestamp),
            'period_end' => $this->getPeriodEnd($feature, $timestamp),
            'metadata' => $metadata,
        ]);

        Event::dispatch(new UsageRecorded($usage));

        return $usage;
    }

    /**
     * Aggregate usage into existing record
     */
    protected function aggregateUsage(
        Model $billable,
        Feature $feature,
        float $amount,
        ?array $metadata,
        ?Carbon $timestamp
    ): Usage {
        $timestamp = $timestamp ?? now();
        $periodStart = $this->getPeriodStart($feature, $timestamp);
        $periodEnd = $this->getPeriodEnd($feature, $timestamp);

        $usage = $this->usageModel::lockForUpdate()->firstOrCreate(
            [
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => $billable->getKey(),
                'feature_id' => $feature->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ],
            [
                'used' => 0,
                'metadata' => $metadata,
            ]
        );

        $usage->increment('used', $amount);

        // Merge metadata if provided
        if ($metadata && config('plan-usage.usage.merge_metadata', false)) {
            $existingMetadata = $usage->metadata ?? [];
            $usage->metadata = array_merge($existingMetadata, $metadata);
            $usage->save();
        }

        Event::dispatch(new UsageRecorded($usage));

        return $usage;
    }

    /**
     * Get usage for a billable within a period
     */
    public function getUsage(
        Model $billable,
        string $featureSlug,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): float {
        $feature = $this->featureModel::where('slug', $featureSlug)->firstOrFail();
        
        $query = $this->usageModel::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->where('feature_id', $feature->id);

        if ($from) {
            $query->where('period_start', '>=', $from);
        }

        if ($to) {
            $query->where('period_end', '<=', $to);
        }

        return (float) $query->sum('used');
    }

    /**
     * Get current period usage
     */
    public function getCurrentPeriodUsage(Model $billable, string $featureSlug): float
    {
        $feature = $this->featureModel::where('slug', $featureSlug)->firstOrFail();
        $now = now();
        
        return $this->getUsage(
            $billable,
            $featureSlug,
            $this->getPeriodStart($feature, $now),
            $this->getPeriodEnd($feature, $now)
        );
    }

    /**
     * Get usage history for a billable
     */
    public function getHistory(
        Model $billable,
        ?string $featureSlug = null,
        ?int $limit = null
    ): \Illuminate\Support\Collection {
        $query = $this->usageModel::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->with('feature');

        if ($featureSlug) {
            $feature = $this->featureModel::where('slug', $featureSlug)->firstOrFail();
            $query->where('feature_id', $feature->id);
        }

        $query->orderBy('created_at', 'desc');

        if ($limit) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * Reset usage for a specific period
     */
    public function resetUsage(
        Model $billable,
        string $featureSlug,
        ?Carbon $periodStart = null
    ): void {
        $feature = $this->featureModel::where('slug', $featureSlug)->firstOrFail();
        
        $query = $this->usageModel::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->where('feature_id', $feature->id);

        if ($periodStart) {
            $query->where('period_start', $periodStart);
        }

        $query->delete();
    }

    /**
     * Get aggregated usage statistics
     */
    public function getStatistics(
        Model $billable,
        string $featureSlug,
        Carbon $from,
        Carbon $to,
        string $groupBy = 'day'
    ): \Illuminate\Support\Collection {
        $feature = $this->featureModel::where('slug', $featureSlug)->firstOrFail();
        
        $dateFormat = match($groupBy) {
            'hour' => '%Y-%m-%d %H:00:00',
            'day' => '%Y-%m-%d',
            'week' => '%Y-%u',
            'month' => '%Y-%m',
            'year' => '%Y',
            default => '%Y-%m-%d',
        };

        return DB::table(config('plan-usage.tables.usage'))
            ->select(DB::raw("DATE_FORMAT(created_at, '{$dateFormat}') as period"), 
                     DB::raw('SUM(used) as total_usage'),
                     DB::raw('COUNT(*) as usage_count'),
                     DB::raw('AVG(used) as average_usage'),
                     DB::raw('MAX(used) as max_usage'),
                     DB::raw('MIN(used) as min_usage'))
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->where('feature_id', $feature->id)
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('period')
            ->orderBy('period')
            ->get();
    }

    /**
     * Determine if usage should be aggregated
     */
    protected function shouldAggregate(Feature $feature): bool
    {
        return in_array($feature->aggregation_method, ['sum', 'count']) &&
               config('plan-usage.usage.aggregate_same_period', true);
    }

    /**
     * Get period start based on reset period
     */
    protected function getPeriodStart(Feature $feature, Carbon $timestamp): Carbon
    {
        return match($feature->reset_period) {
            'hourly' => $timestamp->copy()->startOfHour(),
            'daily' => $timestamp->copy()->startOfDay(),
            'weekly' => $timestamp->copy()->startOfWeek(),
            'monthly' => $timestamp->copy()->startOfMonth(),
            'yearly' => $timestamp->copy()->startOfYear(),
            default => $timestamp->copy()->startOfMonth(),
        };
    }

    /**
     * Get period end based on reset period
     */
    protected function getPeriodEnd(Feature $feature, Carbon $timestamp): Carbon
    {
        return match($feature->reset_period) {
            'hourly' => $timestamp->copy()->endOfHour(),
            'daily' => $timestamp->copy()->endOfDay(),
            'weekly' => $timestamp->copy()->endOfWeek(),
            'monthly' => $timestamp->copy()->endOfMonth(),
            'yearly' => $timestamp->copy()->endOfYear(),
            default => $timestamp->copy()->endOfMonth(),
        };
    }

    /**
     * Report usage to Stripe for metered billing
     */
    public function reportToStripe(Model $billable, string $featureSlug, float $amount): void
    {
        if (!method_exists($billable, 'reportUsage')) {
            return;
        }

        $feature = $this->featureModel::where('slug', $featureSlug)->firstOrFail();
        
        if ($feature->stripe_meter_id) {
            $billable->reportUsage($feature->stripe_meter_id, (int) $amount);
        }
    }
}