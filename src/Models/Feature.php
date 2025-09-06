<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $type
 * @property string|null $unit
 * @property string $aggregation_method
 * @property string|null $reset_period
 * @property string|null $stripe_meter_id
 * @property bool $is_consumable
 * @property int $sort_order
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Feature extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'type',
        'unit',
        'aggregation_method',
        'reset_period',
        'stripe_meter_id',
        'is_consumable',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'is_consumable' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('plan-usage.tables.features', 'features');
    }

    /**
     * Get the plans that have this feature.
     */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(
            Plan::class,
            config('plan-usage.tables.plan_features', 'plan_features'),
            'feature_id',
            'plan_id'
        )->withPivot('value', 'unit', 'metadata')
            ->withTimestamps();
    }

    /**
     * Get the usage records for this feature.
     */
    public function usage(): HasMany
    {
        return $this->hasMany(Usage::class);
    }

    /**
     * Get the quotas for this feature.
     */
    public function quotas(): HasMany
    {
        return $this->hasMany(Quota::class);
    }

    /**
     * Scope to order features by sort order.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Scope to filter by feature type.
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to get consumable features.
     */
    public function scopeConsumable(Builder $query): Builder
    {
        return $query->where('is_consumable', true);
    }

    /**
     * Check if this is a boolean feature.
     */
    public function isBoolean(): bool
    {
        return $this->type === 'boolean';
    }

    /**
     * Check if this is a limit feature.
     */
    public function isLimit(): bool
    {
        return $this->type === 'limit';
    }

    /**
     * Check if this is a quota feature.
     */
    public function isQuota(): bool
    {
        return $this->type === 'quota';
    }

    /**
     * Check if this feature resets periodically.
     */
    public function resetsperiodically(): bool
    {
        return ! in_array($this->reset_period, [null, 'never']);
    }

    /**
     * Get the next reset date based on the reset period.
     */
    public function getNextResetDate(?\DateTimeInterface $from = null): ?\DateTimeInterface
    {
        if (! $this->resetsperiodically()) {
            return null;
        }

        $from = $from ? \Illuminate\Support\Carbon::instance($from) : now();

        return match ($this->reset_period) {
            'daily' => $from->copy()->addDay()->startOfDay(),
            'weekly' => $from->copy()->addWeek()->startOfWeek(),
            'monthly' => $from->copy()->addMonth()->startOfMonth(),
            'yearly' => $from->copy()->addYear()->startOfYear(),
            default => null,
        };
    }

    /**
     * Get the display label for the feature type.
     */
    public function getTypeLabel(): string
    {
        return config("plan-usage.feature_types.{$this->type}.label", ucfirst($this->type));
    }

    /**
     * Get the display label for the reset period.
     */
    public function getResetPeriodLabel(): string
    {
        if (! $this->reset_period) {
            return 'Never';
        }

        return config("plan-usage.reset_periods.{$this->reset_period}.label", ucfirst($this->reset_period));
    }
}
