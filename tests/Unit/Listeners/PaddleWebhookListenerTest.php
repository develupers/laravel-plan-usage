<?php

declare(strict_types=1);

use Develupers\PlanUsage\Actions\Subscription\DeleteSubscriptionAction;
use Develupers\PlanUsage\Actions\Subscription\SyncPlanWithBillableAction;
use Develupers\PlanUsage\Contracts\Billable as BillableContract;
use Develupers\PlanUsage\Providers\Paddle\PaddleWebhookListener;
use Develupers\PlanUsage\Traits\HasPlanFeatures;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Paddle\Billable;
use Laravel\Paddle\Events\WebhookReceived;

/**
 * Concrete billable backed by the shared test_billables table. The listener
 * resolves the class from config and queries it directly, so an alias mock
 * is not enough here — Schema::hasColumn() and Eloquent queries need a real
 * table-backed model.
 */
class PaddleListenerTestBillable extends Model implements BillableContract
{
    use Billable;
    use HasPlanFeatures;

    public $timestamps = false;

    protected $table = 'test_billables';

    protected $guarded = [];
}

beforeEach(function () {
    $this->syncAction = Mockery::mock(SyncPlanWithBillableAction::class);
    $this->deleteAction = Mockery::mock(DeleteSubscriptionAction::class);
    $this->listener = new PaddleWebhookListener($this->syncAction, $this->deleteAction);

    Config::set('plan-usage.models.billable', PaddleListenerTestBillable::class);

    if (! Schema::hasColumn('test_billables', 'paddle_id')) {
        Schema::table('test_billables', function (Blueprint $table) {
            $table->string('paddle_id')->nullable();
        });
    }

    // Cashier Paddle's polymorphic customers table (used by the fallback path).
    if (! Schema::hasTable('customers')) {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            $table->string('paddle_id')->unique();
            $table->string('name');
            $table->string('email');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
        });
    }
});

function paddleSubscriptionPayload(string $eventType, string $customerId, string $priceId = 'pri_test_123', string $eventId = 'evt_test_1'): array
{
    return [
        'event_id' => $eventId,
        'event_type' => $eventType,
        'data' => [
            'id' => 'sub_test_123',
            'customer_id' => $customerId,
            'items' => [
                ['price' => ['id' => $priceId]],
            ],
        ],
    ];
}

it('resolves the billable via its paddle_id column and syncs the plan', function () {
    $billable = PaddleListenerTestBillable::create(['paddle_id' => 'ctm_direct']);

    $this->syncAction->shouldReceive('execute')
        ->once()
        ->withArgs(fn ($resolved, $priceId) => $resolved->getKey() === $billable->getKey()
            && $priceId === 'pri_test_123')
        ->andReturn(true);

    $this->listener->handle(new WebhookReceived(
        paddleSubscriptionPayload('subscription.created', 'ctm_direct')
    ));
});

it('falls back to the cashier customers table and backfills paddle_id', function () {
    $billable = PaddleListenerTestBillable::create(['paddle_id' => null]);

    DB::table('customers')->insert([
        'billable_type' => PaddleListenerTestBillable::class,
        'billable_id' => $billable->getKey(),
        'paddle_id' => 'ctm_via_customers',
        'name' => 'Test',
        'email' => 'test@example.com',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->syncAction->shouldReceive('execute')
        ->once()
        ->withArgs(fn ($resolved, $priceId) => $resolved->getKey() === $billable->getKey())
        ->andReturn(true);

    $this->listener->handle(new WebhookReceived(
        paddleSubscriptionPayload('subscription.created', 'ctm_via_customers', eventId: 'evt_fallback')
    ));

    expect($billable->fresh()->paddle_id)->toBe('ctm_via_customers');
});

it('ignores events for unknown paddle customers', function () {
    $this->syncAction->shouldNotReceive('execute');

    $this->listener->handle(new WebhookReceived(
        paddleSubscriptionPayload('subscription.created', 'ctm_unknown', eventId: 'evt_unknown')
    ));
});

it('revokes the subscription on subscription.canceled', function () {
    $billable = PaddleListenerTestBillable::create(['paddle_id' => 'ctm_cancel']);

    $this->deleteAction->shouldReceive('execute')
        ->once()
        ->withArgs(fn ($resolved) => $resolved->getKey() === $billable->getKey());

    $this->listener->handle(new WebhookReceived(
        paddleSubscriptionPayload('subscription.canceled', 'ctm_cancel', eventId: 'evt_cancel')
    ));
});

it('deduplicates events by paddle event id', function () {
    $billable = PaddleListenerTestBillable::create(['paddle_id' => 'ctm_dedup']);

    $this->syncAction->shouldReceive('execute')->once()->andReturn(true);

    $payload = paddleSubscriptionPayload('subscription.created', 'ctm_dedup', eventId: 'evt_dedup');

    $this->listener->handle(new WebhookReceived($payload));
    $this->listener->handle(new WebhookReceived($payload));
});
