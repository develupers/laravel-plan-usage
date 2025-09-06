<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $plan_id
 * @property int $feature_id
 * @property string|null $value
 * @property string|null $unit
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Plan $plan
 * @property-read Feature $feature
 */
class PlanFeature extends Model
{
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'feature_id',
        'value',
        'unit',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('plan-usage.tables.plan_features', 'plan_features');
    }

    /**
     * Get the plan.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Get the feature.
     */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    /**
     * Get the typed value based on feature type.
     */
    public function getTypedValue(): mixed
    {
        if (! $this->feature) {
            return $this->value;
        }

        return match ($this->feature->type) {
            'boolean' => (bool) $this->value,
            'limit', 'quota' => $this->value === null ? null : (float) $this->value,
            default => $this->value,
        };
    }

    /**
     * Check if the feature is enabled (for boolean features).
     */
    public function isEnabled(): bool
    {
        return (bool) $this->value;
    }

    /**
     * Check if the feature is unlimited (for limit/quota features).
     */
    public function isUnlimited(): bool
    {
        return $this->value === null;
    }
}
