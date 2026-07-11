<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $provider
 * @property string $provider_event_id
 * @property string $event_type
 * @property string|null $provider_subscription_id
 * @property Carbon|null $occurred_at
 * @property Carbon|null $processed_at
 * @property Carbon|null $ignored_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BillingWebhookEvent extends Model
{
    /**
     * Microsecond precision so out-of-order detection can distinguish events
     * emitted within the same second (all columns use dateTime(6)).
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'provider',
        'provider_event_id',
        'event_type',
        'provider_subscription_id',
        'occurred_at',
        'processed_at',
        'ignored_at',
        'last_error',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'processed_at' => 'datetime',
        'ignored_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('plan-usage.tables.billing_webhook_events', 'billing_webhook_events');
    }

    public function scopeProcessed(Builder $query): Builder
    {
        return $query->whereNotNull('processed_at');
    }
}
