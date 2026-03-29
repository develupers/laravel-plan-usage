<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Models;

use Develupers\PlanUsage\Exceptions\QuotaExceededException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $billable_type
 * @property int $billable_id
 * @property int $feature_id
 * @property float|null $limit
 * @property float $used
 * @property \Illuminate\Support\Carbon|null $reset_at
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Model $billable
 * @property-read Feature $feature
 */
class Quota extends Model
{
    use HasFactory;

    protected $fillable = [
        'billable_type',
        'billable_id',
        'feature_id',
        'limit',
        'used',
        'reset_at',
        'metadata',
    ];

    protected $casts = [
        'limit' => 'float',
        'used' => 'float',
        'reset_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('plan-usage.tables.quotas', 'quotas');
    }

    /**
     * Get the billable model that owns the quota.
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the feature associated with this quota.
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
     * Check if the quota limit has been reached (at or beyond limit).
     */
    public function isLimitReached(): bool
    {
        if ($this->limit === null) {
            return false; // Unlimited
        }

        $graceAmount = 0;
        if (config('plan-usage.quota.soft_limit', false)) {
            $gracePercentage = config('plan-usage.quota.grace_percentage', 10);
            $graceAmount = $this->limit * ($gracePercentage / 100);
        }

        return $this->used >= ($this->limit + $graceAmount);
    }

    /**
     * Check if the quota is at a warning threshold.
     */
    public function isAtWarningThreshold(): ?int
    {
        if ($this->limit === null) {
            return null;
        }

        $thresholds = config('plan-usage.quota.warning_thresholds', [80, 100]);
        $percentage = ($this->used / $this->limit) * 100;

        foreach (array_reverse($thresholds) as $threshold) {
            if ($percentage >= $threshold) {
                return $threshold;
            }
        }

        return null;
    }

    /**
     * Get the remaining quota.
     */
    public function remaining(): ?float
    {
        if ($this->limit === null) {
            return null; // Unlimited
        }

        return max(0, $this->limit - $this->used);
    }

    /**
     * Get the usage percentage.
     */
    public function usagePercentage(): ?float
    {
        if ($this->limit === null) {
            return null; // Unlimited
        }

        if ($this->limit == 0) {
            return 100.0;
        }

        return min(100, ($this->used / $this->limit) * 100);
    }

    /**
     * Check if quota needs to be reset.
     */
    public function needsReset(): bool
    {
        if (! $this->reset_at) {
            return false;
        }

        return $this->reset_at->isPast();
    }

    /**
     * Reset the quota.
     */
    public function reset(): self
    {
        if (! $this->feature || ! $this->feature->resetsPeriodically()) {
            return $this;
        }

        $this->used = 0;
        $this->reset_at = $this->feature->getNextResetDate();
        $this->save();

        return $this;
    }

    /**
     * Use some of the quota (atomic check-and-increment).
     */
    public function use(float $amount = 1): self
    {
        if ($this->limit !== null) {
            $effectiveLimit = $this->limit;
            if (config('plan-usage.quota.soft_limit', false)) {
                $gracePercentage = config('plan-usage.quota.grace_percentage', 10);
                $effectiveLimit += $this->limit * ($gracePercentage / 100);
            }

            // Atomic conditional increment — only succeeds if within limit
            $affected = static::where('id', $this->id)
                ->whereRaw('(used + ?) <= ?', [$amount, $effectiveLimit])
                ->update(['used' => DB::raw('used + '.(float) $amount)]);

            if ($affected === 0) {
                if (config('plan-usage.quota.throw_exception', true)) {
                    throw new QuotaExceededException(
                        "Quota exceeded for feature {$this->feature->name}. Used: {$this->used}, Limit: {$this->limit}",
                        $this->feature->slug,
                        $this->limit,
                        $this->used
                    );
                }

                return $this;
            }

            $this->refresh();
        } else {
            // Unlimited — just increment
            $this->increment('used', $amount);
        }

        return $this;
    }

    /**
     * Check if amount can be used without exceeding quota.
     */
    public function canUse(float $amount = 1): bool
    {
        if ($this->limit === null) {
            return true; // Unlimited
        }

        $graceAmount = 0;
        if (config('plan-usage.quota.soft_limit', false)) {
            $gracePercentage = config('plan-usage.quota.grace_percentage', 10);
            $graceAmount = $this->limit * ($gracePercentage / 100);
        }

        return ($this->used + $amount) <= ($this->limit + $graceAmount);
    }

    /**
     * Get or create quota for a billable and feature.
     */
    public static function getOrCreate(Model $billable, $feature, ?float $limit = null): self
    {
        $featureModel = $feature instanceof Feature
            ? $feature
            : Feature::where('slug', $feature)->firstOrFail();

        $quota = static::forBillable($billable)
            ->forFeature($featureModel)
            ->first();

        if (! $quota) {
            // If no limit specified, try to get from plan
            if ($limit === null && method_exists($billable, 'plan')) {
                $plan = $billable->plan;
                if ($plan) {
                    $limit = $plan->getFeatureValue($featureModel->slug);
                }
            }

            $quota = static::create([
                'billable_type' => get_class($billable),
                'billable_id' => $billable->getKey(),
                'feature_id' => $featureModel->id,
                'limit' => $limit,
                'used' => 0,
                'reset_at' => $featureModel->getNextResetDate(),
            ]);
        }

        // Reset if needed
        if ($quota->needsReset()) {
            $quota->reset();
        }

        return $quota;
    }
}
