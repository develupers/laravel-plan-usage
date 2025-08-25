<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Builder;
use Develupers\PlanUsage\Exceptions\QuotaExceededException;

class Quota extends Model
{
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
        'limit' => 'decimal:4',
        'used' => 'decimal:4',
        'reset_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('plan-feature-usage.tables.quotas', 'quotas');
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
     * Check if the quota has been exceeded.
     */
    public function isExceeded(): bool
    {
        if ($this->limit === null) {
            return false; // Unlimited
        }

        $gracePercentage = config('plan-feature-usage.quota.grace_period', 0);
        $graceAmount = $this->limit * ($gracePercentage / 100);

        return $this->used > ($this->limit + $graceAmount);
    }

    /**
     * Check if the quota is at a warning threshold.
     */
    public function isAtWarningThreshold(): ?int
    {
        if ($this->limit === null) {
            return null;
        }

        $thresholds = config('plan-feature-usage.quota.warning_thresholds', [80, 90, 100]);
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
        if ($this->limit === null || $this->limit == 0) {
            return null;
        }

        return min(100, ($this->used / $this->limit) * 100);
    }

    /**
     * Check if quota needs to be reset.
     */
    public function needsReset(): bool
    {
        if (!$this->reset_at) {
            return false;
        }

        return $this->reset_at->isPast();
    }

    /**
     * Reset the quota.
     */
    public function reset(): self
    {
        if (!$this->feature || !$this->feature->resetsperiodically()) {
            return $this;
        }

        $this->used = 0;
        $this->reset_at = $this->feature->getNextResetDate();
        $this->save();

        return $this;
    }

    /**
     * Use some of the quota.
     */
    public function use(float $amount = 1): self
    {
        if ($this->limit !== null && ($this->used + $amount) > $this->limit) {
            if (config('plan-feature-usage.quota.throw_exception', true)) {
                throw new QuotaExceededException(
                    "Quota exceeded for feature {$this->feature->name}. Used: {$this->used}, Limit: {$this->limit}",
                    $this->feature->slug,
                    $this->limit,
                    $this->used
                );
            }
        }

        $this->increment('used', $amount);

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

        $gracePercentage = config('plan-feature-usage.quota.grace_period', 0);
        $graceAmount = $this->limit * ($gracePercentage / 100);

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

        if (!$quota) {
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