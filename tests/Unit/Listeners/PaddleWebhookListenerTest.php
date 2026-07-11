<?php

declare(strict_types=1);

use Develupers\PlanUsage\Actions\Subscription\DeleteSubscriptionAction;
use Develupers\PlanUsage\Actions\Subscription\SyncPlanWithBillableAction;
use Develupers\PlanUsage\Contracts\Billable as BillableContract;
use Develupers\PlanUsage\Providers\Paddle\PaddleWebhookListener;
use Develupers\PlanUsage\Support\SubscriptionStateLock;
use Develupers\PlanUsage\Traits\HasPlanFeatures;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Laravel\Paddle\Billable;
use Laravel\Paddle\Customer;
use Laravel\Paddle\Events\WebhookHandled;
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

/**
 * Test double: the listener re-fetches authoritative subscription state from
 * the Paddle API — canned here so tests control the "current remote truth"
 * independently of the (possibly stale) payload.
 */
class FakeRefetchPaddleWebhookListener extends PaddleWebhookListener
{
    /** @var array{status: string, price_id: string|null} */
    public array $remoteState = ['status' => 'active', 'price_id' => 'pri_current'];

    public int $fetches = 0;

    protected int $lockWaitSeconds = 0;

    protected function fetchPaddleSubscription(string $subscriptionId): array
    {
        $this->fetches++;

        return $this->remoteState;
    }
}

beforeEach(function () {
    $this->syncAction = Mockery::mock(SyncPlanWithBillableAction::class);
    $this->deleteAction = Mockery::mock(DeleteSubscriptionAction::class);
    $this->listener = new FakeRefetchPaddleWebhookListener($this->syncAction, $this->deleteAction, new SubscriptionStateLock);

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

/**
 * Create a billable with a local default-type Paddle subscription row —
 * WebhookHandled fires after Cashier Paddle created it, so the listener's
 * identity check expects it to exist.
 */
function paddleBillableWithSubscription(string $customerId, string $subscriptionId = 'sub_test_123'): PaddleListenerTestBillable
{
    $billable = PaddleListenerTestBillable::create(['paddle_id' => $customerId]);
    $billable->subscriptions()->forceCreate([
        'type' => 'default',
        'paddle_id' => $subscriptionId,
        'status' => 'active',
    ]);

    return $billable;
}

function paddleSubscriptionPayload(string $eventType, string $customerId, string $priceId = 'pri_test_123', string $eventId = 'evt_test_1', string $subscriptionId = 'sub_test_123', ?string $customType = null): array
{
    return [
        'event_id' => $eventId,
        'event_type' => $eventType,
        'data' => [
            'id' => $subscriptionId,
            'customer_id' => $customerId,
            'custom_data' => $customType !== null ? ['subscription_type' => $customType] : [],
            'items' => [
                ['price' => ['id' => $priceId]],
            ],
        ],
    ];
}

it('resolves the billable via its paddle_id column and syncs from the refetched price', function () {
    $billable = paddleBillableWithSubscription('ctm_direct');
    $this->listener->remoteState = ['status' => 'active', 'price_id' => 'pri_current'];

    // The payload carries an older price — out-of-order delivery. Only the
    // refetched price may be synced.
    $this->syncAction->shouldReceive('execute')
        ->once()
        ->withArgs(fn ($resolved, $priceId) => $resolved->getKey() === $billable->getKey() && $priceId === 'pri_current')
        ->andReturn(true);

    $this->listener->handle(new WebhookHandled(
        paddleSubscriptionPayload('subscription.created', 'ctm_direct', priceId: 'pri_stale')
    ));

    expect($this->listener->fetches)->toBe(1);
});

it('resolves the billable via the cashier customers table and backfills paddle_id', function () {
    $billable = PaddleListenerTestBillable::create([]);
    $billable->subscriptions()->forceCreate([
        'type' => 'default',
        'paddle_id' => 'sub_test_123',
        'status' => 'active',
    ]);
    Customer::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'paddle_id' => 'ctm_via_customers',
        'name' => 'Test',
        'email' => 'test@example.com',
    ]);

    $this->syncAction->shouldReceive('execute')->once()->andReturn(true);

    $this->listener->handle(new WebhookHandled(
        paddleSubscriptionPayload('subscription.created', 'ctm_via_customers', eventId: 'evt_fallback')
    ));

    expect($billable->fresh()->paddle_id)->toBe('ctm_via_customers');
});

it('ignores events for unknown paddle customers', function () {
    $this->syncAction->shouldNotReceive('execute');

    $this->listener->handle(new WebhookHandled(
        paddleSubscriptionPayload('subscription.created', 'ctm_unknown', eventId: 'evt_unknown')
    ));
});

it('ignores events for a subscription that is not the default-type subscription', function () {
    // An add-on (non-default) subscription's webhook must not overwrite the
    // billable's plan.
    paddleBillableWithSubscription('ctm_addon', subscriptionId: 'sub_default_1');

    $this->syncAction->shouldNotReceive('execute');
    $this->deleteAction->shouldNotReceive('execute');

    $this->listener->handle(new WebhookHandled(
        paddleSubscriptionPayload('subscription.updated', 'ctm_addon', eventId: 'evt_addon', subscriptionId: 'sub_addon_9')
    ));

    expect($this->listener->fetches)->toBe(0);
});

it('ignores events that declare a non-default subscription type in custom_data', function () {
    paddleBillableWithSubscription('ctm_custom_type');

    $this->syncAction->shouldNotReceive('execute');

    $this->listener->handle(new WebhookHandled(
        paddleSubscriptionPayload('subscription.created', 'ctm_custom_type', eventId: 'evt_custom', customType: 'addon')
    ));

    expect($this->listener->fetches)->toBe(0);
});

it('revokes the plan when the remote subscription is canceled', function () {
    $billable = paddleBillableWithSubscription('ctm_cancel');
    $this->listener->remoteState = ['status' => 'canceled', 'price_id' => null];

    $this->deleteAction->shouldReceive('execute')
        ->once()
        ->withArgs(fn ($resolved) => $resolved->getKey() === $billable->getKey());
    $this->syncAction->shouldNotReceive('execute');

    $this->listener->handle(new WebhookHandled(
        paddleSubscriptionPayload('subscription.canceled', 'ctm_cancel', eventId: 'evt_cancel')
    ));
});

it('restores the plan when an updated event reveals the subscription is active again', function () {
    // Cashier Paddle never fires WebhookHandled for subscription.resumed —
    // the resume is picked up via the subscription.updated events Paddle
    // sends alongside it, converging to the refetched active state.
    paddleBillableWithSubscription('ctm_resume');
    $this->listener->remoteState = ['status' => 'active', 'price_id' => 'pri_resumed'];

    $this->syncAction->shouldReceive('execute')
        ->once()
        ->withArgs(fn ($resolved, $priceId) => $priceId === 'pri_resumed')
        ->andReturn(true);

    $this->listener->handle(new WebhookHandled(
        paddleSubscriptionPayload('subscription.updated', 'ctm_resume', eventId: 'evt_resume')
    ));
});

it('keeps entitlements on past_due by default and revokes when configured not to', function () {
    paddleBillableWithSubscription('ctm_past_due');
    $this->listener->remoteState = ['status' => 'past_due', 'price_id' => 'pri_current'];

    $this->syncAction->shouldNotReceive('execute');
    $this->deleteAction->shouldNotReceive('execute');
    $this->listener->handle(new WebhookHandled(
        paddleSubscriptionPayload('subscription.updated', 'ctm_past_due', eventId: 'evt_pd_keep')
    ));

    Config::set('plan-usage.paddle.past_due_keeps_entitlements', false);
    $this->deleteAction->shouldReceive('execute')->once();
    $this->listener->handle(new WebhookHandled(
        paddleSubscriptionPayload('subscription.updated', 'ctm_past_due', eventId: 'evt_pd_revoke')
    ));
});

it('deduplicates events by paddle event id', function () {
    paddleBillableWithSubscription('ctm_dedup');

    $this->syncAction->shouldReceive('execute')->once()->andReturn(true);

    $payload = paddleSubscriptionPayload('subscription.created', 'ctm_dedup', eventId: 'evt_dedup');

    $this->listener->handle(new WebhookHandled($payload));
    $this->listener->handle(new WebhookHandled($payload));
});

it('releases the dedupe key on failure so the provider retry is processed', function () {
    paddleBillableWithSubscription('ctm_retry');

    // First delivery fails transiently; the redelivery must succeed. Swallowing
    // would return HTTP 200 (no provider retry), and a dedupe key left behind
    // would block the redelivery for an hour.
    $attempts = 0;
    $this->syncAction->shouldReceive('execute')
        ->twice()
        ->andReturnUsing(function () use (&$attempts) {
            if (++$attempts === 1) {
                throw new Exception('Transient database error');
            }

            return true;
        });

    $payload = paddleSubscriptionPayload('subscription.created', 'ctm_retry', eventId: 'evt_retry');

    expect(fn () => $this->listener->handle(new WebhookHandled($payload)))
        ->toThrow(Exception::class, 'Transient database error');

    $this->listener->handle(new WebhookHandled($payload));

    expect($attempts)->toBe(2);
});

it('handles subscription.past_due via WebhookReceived since Cashier never fires WebhookHandled for it', function () {
    $billable = paddleBillableWithSubscription('ctm_pd_received');
    $this->listener->remoteState = ['status' => 'past_due', 'price_id' => 'pri_current'];

    // Default policy keeps entitlements — no mutation either way.
    $this->syncAction->shouldNotReceive('execute');
    $this->deleteAction->shouldNotReceive('execute');
    $this->listener->handleReceived(new WebhookReceived(
        paddleSubscriptionPayload('subscription.past_due', 'ctm_pd_received', eventId: 'evt_pd_r1')
    ));
    expect($this->listener->fetches)->toBe(1);

    // Revoke policy applies as soon as the past_due event arrives.
    Config::set('plan-usage.paddle.past_due_keeps_entitlements', false);
    $this->deleteAction->shouldReceive('execute')->once();
    $this->listener->handleReceived(new WebhookReceived(
        paddleSubscriptionPayload('subscription.past_due', 'ctm_pd_received', eventId: 'evt_pd_r2')
    ));
});

it('ignores Cashier-handled event types on the WebhookReceived route to avoid double-processing', function () {
    paddleBillableWithSubscription('ctm_recv_dup');

    $this->syncAction->shouldNotReceive('execute');

    $this->listener->handleReceived(new WebhookReceived(
        paddleSubscriptionPayload('subscription.updated', 'ctm_recv_dup', eventId: 'evt_recv_dup')
    ));

    expect($this->listener->fetches)->toBe(0);
});
