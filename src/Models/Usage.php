<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Usage extends Model
{
    use HasFactory;
    protected $fillable = [
        'billable_type',
        'billable_id',
        'feature_id',
        'used',
        'period_start',
        'period_end',
        'metadata',
    ];

    protected $casts = [
        'used' => 'float',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'metadata' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('plan-usage.tables.usages', 'usages');
    }

    /**
     * Get the billable model that owns the usage.
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the feature associated with this usage.
     */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    /**
     * Scope to filter by billable.
     */
    public function scopeForBillable(Builder $query, Model $billable): Builder
    {
        return $query->where('billable_type', get_class($billable))
            ->where('billable_id', $billable->getKey());
    }

    /**
     * Scope to filter by feature.
     */
    public function scopeForFeature(Builder $query, $feature): Builder
    {
        if ($feature instanceof Feature) {
            return $query->where('feature_id', $feature->id);
        }

        return $query->whereHas('feature', function ($q) use ($feature) {
            $q->where('slug', $feature);
        });
    }

    /**
     * Scope to filter by period.
     */
    public function scopeForPeriod(Builder $query, $start, $end = null): Builder
    {
        $query->where(function ($q) use ($start, $end) {
            if ($start instanceof \DateTimeInterface) {
                $q->where('period_start', '>=', $start);
            } else {
                $q->where('period_start', '>=', Carbon::parse($start));
            }

            if ($end) {
                if ($end instanceof \DateTimeInterface) {
                    $q->where('period_end', '<=', $end);
                } else {
                    $q->where('period_end', '<=', Carbon::parse($end));
                }
            }
        });

        return $query;
    }

    /**
     * Scope to get current period usage.
     */
    public function scopeCurrentPeriod(Builder $query): Builder
    {
        $now = now();

        return $query->where('period_start', '<=', $now)
            ->where(function ($q) use ($now) {
                $q->where('period_end', '>=', $now)
                    ->orWhereNull('period_end');
            });
    }

    /**
     * Get the total usage for a specific period.
     */
    public static function getTotalUsage(Model $billable, $feature, $start = null, $end = null): float
    {
        $query = static::forBillable($billable)->forFeature($feature);

        if ($start || $end) {
            $query->forPeriod($start, $end);
        }

        return (float) $query->sum('used');
    }

    /**
     * Record new usage.
     */
    public static function record(Model $billable, $feature, float $amount, array $metadata = []): self
    {
        $featureModel = $feature instanceof Feature
            ? $feature
            : Feature::where('slug', $feature)->firstOrFail();

        // Determine period based on feature reset period
        $periodStart = now();
        $periodEnd = $featureModel->getNextResetDate($periodStart);

        return static::create([
            'billable_type' => get_class($billable),
            'billable_id' => $billable->getKey(),
            'feature_id' => $featureModel->id,
            'used' => $amount,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Tick up usage for the current period.
     */
    public static function tickUp(Model $billable, $feature, float $amount = 1, array $metadata = []): self
    {
        $featureModel = $feature instanceof Feature
            ? $feature
            : Feature::where('slug', $feature)->firstOrFail();

        // Try to find existing usage record for current period
        $usage = static::forBillable($billable)
            ->forFeature($featureModel)
            ->currentPeriod()
            ->first();

        if ($usage) {
            $usage->increment('used', $amount);
            if (! empty($metadata)) {
                $usage->update(['metadata' => array_merge($usage->metadata ?? [], $metadata)]);
            }

            return $usage;
        }

        // Create new usage record if none exists
        return static::record($billable, $featureModel, $amount, $metadata);
    }
}
