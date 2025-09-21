<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Models;

use Develupers\PlanUsage\Enums\Interval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $plan_id
 * @property string|null $stripe_price_id
 * @property float $price
 * @property string $currency
 * @property Interval $interval
 * @property bool $is_active
 * @property bool $is_default
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Plan $plan
 */
class PlanPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'stripe_price_id',
        'price',
        'currency',
        'interval',
        'is_active',
        'is_default',
        'metadata',
    ];

    protected $casts = [
        'price' => 'float',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'metadata' => 'array',
        'interval' => Interval::class,
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('plan-usage.tables.plan_prices', 'plan_prices');
    }

    /**
     * Get the plan that owns the price.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Scope to only include active prices.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get default price for a plan.
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope to prices by interval.
     */
    public function scopeByInterval(Builder $query, Interval|string $interval): Builder
    {
        if (is_string($interval)) {
            $interval = Interval::from($interval);
        }

        return $query->where('interval', $interval->value);
    }

    /**
     * Scope to prices by currency.
     */
    public function scopeByCurrency(Builder $query, string $currency): Builder
    {
        return $query->where('currency', strtoupper($currency));
    }

    /**
     * Check if this is a monthly price.
     */
    public function isMonthly(): bool
    {
        return $this->interval === Interval::MONTH;
    }

    /**
     * Check if this is a yearly price.
     */
    public function isYearly(): bool
    {
        return $this->interval === Interval::YEAR;
    }

    /**
     * Get the full interval description.
     */
    public function getIntervalDescription(): string
    {
        return $this->interval->label();
    }

    /**
     * Calculate the monthly equivalent price.
     */
    public function getMonthlyEquivalent(): float
    {
        return match ($this->interval) {
            Interval::DAY => $this->price * 30,
            Interval::WEEK => $this->price * 4.33,
            Interval::MONTH => $this->price,
            Interval::YEAR => $this->price / 12,
            Interval::LIFETIME => 0, // Cannot calculate monthly for lifetime
        };
    }

    /**
     * Calculate savings compared to another price.
     */
    public function calculateSavings(PlanPrice $comparedTo): float
    {
        $thisMonthly = $this->getMonthlyEquivalent();
        $otherMonthly = $comparedTo->getMonthlyEquivalent();

        if ($thisMonthly === 0.0 || $otherMonthly === 0.0) {
            return 0;
        }

        return (($otherMonthly - $thisMonthly) / $otherMonthly) * 100;
    }

    /**
     * Get the display price with currency.
     */
    public function getFormattedPriceAttribute(): string
    {
        $symbol = match (strtoupper($this->currency)) {
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            default => $this->currency.' ',
        };

        return $symbol.number_format($this->price, 2);
    }

    /**
     * Get the total billing amount for the interval.
     */
    public function getTotalBillingAmount(): float
    {
        return $this->price;
    }

    /**
     * Find a plan price by Stripe price ID.
     */
    public static function findByStripePriceId(string $stripePriceId): ?self
    {
        return static::where('stripe_price_id', $stripePriceId)->first();
    }
}