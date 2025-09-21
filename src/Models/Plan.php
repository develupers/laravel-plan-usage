<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Models;

use Develupers\PlanUsage\Enums\Interval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $display_name
 * @property string|null $description
 * @property string|null $stripe_product_id
 * @property int $trial_days
 * @property int $sort_order
 * @property bool $is_active
 * @property string $type
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Collection<int, Feature> $features
 * @property-read Collection<int, PlanFeature> $planFeatures
 * @property-read Collection<int, PlanPrice> $prices
 * @property-read PlanPrice|null $defaultPrice
 */
class Plan extends Model
{
    use HasFactory;

    // Type constants
    public const TYPE_PUBLIC = 'public';

    public const TYPE_LEGACY = 'legacy';

    public const TYPE_PRIVATE = 'private';

    protected $fillable = [
        'name',
        'slug',
        'display_name',
        'description',
        'stripe_product_id',
        'trial_days',
        'sort_order',
        'is_active',
        'type',
        'metadata',
    ];

    protected $casts = [
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
     * Get the prices for the plan.
     */
    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }

    /**
     * Get the default price for the plan.
     */
    public function defaultPrice(): HasOne
    {
        return $this->hasOne(PlanPrice::class)->where('is_default', true);
    }

    /**
     * Get active prices for the plan.
     */
    public function activePrices(): HasMany
    {
        return $this->hasMany(PlanPrice::class)->where('is_active', true);
    }

    /**
     * Get price by interval.
     */
    public function getPriceByInterval(Interval|string $interval): ?PlanPrice
    {
        if (is_string($interval)) {
            $interval = Interval::from($interval);
        }

        return $this->prices()
            ->where('interval', $interval->value)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Get monthly price.
     */
    public function getMonthlyPrice(): ?PlanPrice
    {
        return $this->getPriceByInterval(Interval::MONTH);
    }

    /**
     * Get yearly price.
     */
    public function getYearlyPrice(): ?PlanPrice
    {
        return $this->getPriceByInterval(Interval::YEAR);
    }

    /**
     * Find plan by any of its Stripe price IDs.
     */
    public static function findByStripePriceId(string $stripePriceId): ?self
    {
        $planPrice = PlanPrice::where('stripe_price_id', $stripePriceId)->first();

        return $planPrice ? $planPrice->plan : null;
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
        return $query->orderBy('sort_order')->orderBy('id');
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
        /** @var Feature|null $feature */
        $feature = $this->features()->where('slug', $featureSlug)->first();

        if (! $feature || ! isset($feature->pivot)) {
            return null;
        }

        /** @var \Illuminate\Database\Eloquent\Relations\Pivot $pivot */
        $pivot = $feature->pivot;
        $value = $pivot->getAttribute('value');

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
        // Check if any price is 0 or if there are no prices
        $defaultPrice = $this->defaultPrice;
        return $defaultPrice ? $defaultPrice->price == 0 : true;
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
        /** @var Feature|null */
        return $this->features()->where('slug', $slug)->first();
    }


    /**
     * Scope to plans of a specific type.
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to public plans.
     */
    public function scopePublicType(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_PUBLIC);
    }

    /**
     * Scope to legacy plans.
     */
    public function scopeLegacy(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_LEGACY);
    }

    /**
     * Scope to plans available for new subscriptions.
     */
    public function scopeAvailableForPurchase(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('type', self::TYPE_PUBLIC);
    }


    /**
     * Check if plan is public.
     */
    public function isPublic(): bool
    {
        return $this->type === self::TYPE_PUBLIC;
    }

    /**
     * Check if plan is legacy.
     */
    public function isLegacy(): bool
    {
        return $this->type === self::TYPE_LEGACY;
    }

    /**
     * Check if plan is private.
     */
    public function isPrivate(): bool
    {
        return $this->type === self::TYPE_PRIVATE;
    }

    /**
     * Check if plan is available for new subscriptions.
     */
    public function isAvailableForPurchase(): bool
    {
        return $this->is_active && $this->type === self::TYPE_PUBLIC;
    }

}
