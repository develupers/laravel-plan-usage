<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

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
 * @property array<string, mixed>|null $payload
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
        'payload',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'processed_at' => 'datetime',
        'ignored_at' => 'datetime',
        'payload' => 'array',
    ];

    /**
     * Memoized per process: the payload column arrived in a later migration
     * and consumers may not have published it yet.
     */
    private static ?bool $supportsPayloadColumn = null;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('plan-usage.tables.billing_webhook_events', 'billing_webhook_events');
    }

    public function scopeProcessed(Builder $query): Builder
    {
        return $query->whereNotNull('processed_at');
    }

    /**
     * Deliveries that are neither processed nor ignored but still carry the
     * payload needed to replay them: deferred (row not yet present) and
     * failed ones.
     */
    public function scopeDeferred(Builder $query): Builder
    {
        return $query
            ->whereNull('processed_at')
            ->whereNull('ignored_at')
            ->whereNotNull('payload');
    }

    public static function supportsPayloadColumn(): bool
    {
        if (self::$supportsPayloadColumn === null) {
            self::$supportsPayloadColumn = Schema::hasColumn((new self)->getTable(), 'payload');
        }

        return self::$supportsPayloadColumn;
    }

    /**
     * Forget the memoized column check (tests rebuild the schema per case).
     */
    public static function flushSchemaCache(): void
    {
        self::$supportsPayloadColumn = null;
    }
}
