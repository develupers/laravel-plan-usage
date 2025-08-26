<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Plan extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'slug',
        'display_name',
        'description',
        'stripe_product_id',
        'stripe_price_id',
        'price',
        'currency',
        'interval',
        'trial_days',
        'sort_order',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'price' => 'float',
        'trial_days' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('plan-usage.tables.plans', 'plans');
    }

    /**
     * Get the features for the plan.
     */
    public function features(): BelongsToMany
    {
        return $this->belongsToMany(
            Feature::class,
            config('plan-usage.tables.plan_features', 'plan_features'),
            'plan_id',
            'feature_id'
        )->withPivot('value', 'unit', 'metadata')
            ->withTimestamps();
    }

    /**
     * Get the plan features pivot records.
     */
    public function planFeatures(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    /**
     * Scope to only include active plans.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to order plans by sort order.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('price');
    }

    /**
     * Check if the plan has a specific feature.
     */
    public function hasFeature(string $featureSlug): bool
    {
        return $this->features()->where('slug', $featureSlug)->exists();
    }

    /**
     * Get the value for a specific feature.
     */
    public function getFeatureValue(string $featureSlug): mixed
    {
        $feature = $this->features()->where('slug', $featureSlug)->first();

        if (! $feature) {
            return null;
        }

        $value = $feature->pivot->value;

        // Handle different feature types
        return match ($feature->type) {
            'boolean' => (bool) $value,
            'limit', 'quota' => $value === null ? null : (float) $value,
            default => $value,
        };
    }

    /**
     * Get all feature values as a collection.
     */
    public function getFeatureValues(): Collection
    {
        return $this->features->mapWithKeys(function ($feature) {
            $value = $this->getFeatureValue($feature->slug);

            return [$feature->slug => $value];
        });
    }

    /**
     * Check if this plan is free.
     */
    public function isFree(): bool
    {
        return $this->price == 0;
    }

    /**
     * Check if this plan has a trial period.
     */
    public function hasTrial(): bool
    {
        return $this->trial_days > 0;
    }

    /**
     * Get a feature by slug.
     */
    public function getFeature(string $slug): ?Feature
    {
        return $this->features()->where('slug', $slug)->first();
    }
    
    /**
     * Scope to monthly plans.
     */
    public function scopeMonthly(Builder $query): Builder
    {
        return $query->where('interval', 'monthly');
    }
    
    /**
     * Scope to yearly plans.
     */
    public function scopeYearly(Builder $query): Builder
    {
        return $query->where('interval', 'yearly');
    }
    
    /**
     * Check if plan is monthly.
     */
    public function isMonthly(): bool
    {
        return $this->interval === 'monthly';
    }
    
    /**
     * Check if plan is yearly.
     */
    public function isYearly(): bool
    {
        return $this->interval === 'yearly';
    }

    /**
     * Get the display price with currency.
     */
    public function getFormattedPriceAttribute(): string
    {
        $symbol = match ($this->currency) {
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            default => $this->currency.' ',
        };

        return $symbol.number_format($this->price, 2);
    }

    /**
     * Get the billing period label.
     */
    public function getBillingPeriodLabelAttribute(): string
    {
        return match ($this->billing_period) {
            'daily' => 'per day',
            'weekly' => 'per week',
            'monthly' => 'per month',
            'yearly' => 'per year',
            'lifetime' => 'one-time',
            default => $this->billing_period,
        };
    }
}
