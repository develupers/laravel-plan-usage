<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Models;

use Develupers\PlanUsage\Enums\SubscriptionChangeStatus;
use Develupers\PlanUsage\Enums\SubscriptionChangeTiming;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $billable_type
 * @property int|string $billable_id
 * @property string $provider
 * @property string $subscription_type
 * @property string $provider_subscription_id
 * @property string|null $provider_change_id
 * @property int|null $from_plan_price_id
 * @property int $to_plan_price_id
 * @property SubscriptionChangeTiming $timing
 * @property SubscriptionChangeStatus $status
 * @property Carbon|null $effective_at
 * @property Carbon|null $applied_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $failed_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model $billable
 * @property-read PlanPrice|null $fromPlanPrice
 * @property-read PlanPrice $toPlanPrice
 */
class SubscriptionPlanChange extends Model
{
    protected $fillable = [
        'billable_type',
        'billable_id',
        'provider',
        'subscription_type',
        'provider_subscription_id',
        'provider_change_id',
        'from_plan_price_id',
        'to_plan_price_id',
        'timing',
        'status',
        'effective_at',
        'applied_at',
        'cancelled_at',
        'failed_at',
        'metadata',
    ];

    protected $casts = [
        'timing' => SubscriptionChangeTiming::class,
        'status' => SubscriptionChangeStatus::class,
        'effective_at' => 'datetime',
        'applied_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'failed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('plan-usage.tables.subscription_plan_changes', 'subscription_plan_changes');
    }

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    public function fromPlanPrice(): BelongsTo
    {
        return $this->belongsTo(
            config('plan-usage.models.plan_price', PlanPrice::class),
            'from_plan_price_id'
        );
    }

    public function toPlanPrice(): BelongsTo
    {
        return $this->belongsTo(
            config('plan-usage.models.plan_price', PlanPrice::class),
            'to_plan_price_id'
        );
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', SubscriptionChangeStatus::Pending->value);
    }

    public function markApplied(): void
    {
        $this->update([
            'status' => SubscriptionChangeStatus::Applied,
            'applied_at' => now(),
        ]);
    }

    public function markCancelled(): void
    {
        $this->update([
            'status' => SubscriptionChangeStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }

    public function markFailed(array $metadata = []): void
    {
        $this->update([
            'status' => SubscriptionChangeStatus::Failed,
            'failed_at' => now(),
            'metadata' => array_merge($this->metadata ?? [], $metadata),
        ]);
    }
}
