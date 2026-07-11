<?php

declare(strict_types=1);

use Develupers\PlanUsage\Actions\Subscription\DeleteSubscriptionAction;
use Develupers\PlanUsage\Actions\Subscription\SyncPlanWithBillableAction;
use Develupers\PlanUsage\Contracts\Billable as BillableContract;
use Develupers\PlanUsage\Providers\Stripe\StripeWebhookListener;
use Develupers\PlanUsage\Support\SubscriptionStateLock;
use Develupers\PlanUsage\Traits\HasPlanFeatures;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;

/**
 * Concrete billable backed by the shared test_billables table — the listener
 * resolves the class from config and queries it by stripe_id directly.
 */
class StripeListenerTestBillable extends Model implements BillableContract
{
    use Billable;
    use HasPlanFeatures;

    public $timestamps = false;

    protected $table = 'test_billables';

    protected $guarded = [];

    /**
     * The shared test schema stores subscriptions polymorphically
     * (billable_type/billable_id); Cashier Stripe's default hasMany would
     * look for a per-model foreign key column instead.
     */
    public function subscriptions()
    {
        return $this->morphMany(Cashier::$subscriptionModel, 'billable')->orderByDesc('created_at');
    }
}

/**
 * Test double: the listener re-fetches authoritative subscription state from
 * the Stripe API — canned here so tests control the "current remote truth"
 * independently of the (possibly stale) payload.
 */
class FakeRefetchStripeWebhookListener extends StripeWebhookListener
{
    /** @var array{status: string, price_id: string|null} */
    public array $remoteState = ['status' => 'active', 'price_id' => 'price_wh_current'];

    public int $fetches = 0;

    protected int $lockWaitSeconds = 0;

    protected function fetchStripeSubscription(string $subscriptionId): array
    {
        $this->fetches++;

        return $this->remoteState;
    }
}

beforeEach(function () {
    $this->syncAction = Mockery::mock(SyncPlanWithBillableAction::class);
    $this->deleteAction = Mockery::mock(DeleteSubscriptionAction::class);
    $this->stateLock = new SubscriptionStateLock;
    $this->listener = new FakeRefetchStripeWebhookListener($this->syncAction, $this->deleteAction, $this->stateLock);

    Config::set('plan-usage.models.billable', StripeListenerTestBillable::class);

    if (! Schema::hasColumn('test_billables', 'stripe_id')) {
        Schema::table('test_billables', function (Blueprint $table) {
            $table->string('stripe_id')->nullable();
        });
    }

    $this->billable = StripeListenerTestBillable::create(['stripe_id' => 'cus_wh_test']);
    $this->billable->subscriptions()->forceCreate([
        'type' => 'default',
        'stripe_id' => 'sub_wh_default',
        'stripe_status' => 'active',
    ]);
});

function stripeWebhookPayload(string $eventId, string $subscriptionId = 'sub_wh_default', string $payloadPrice = 'price_wh_stale'): array
{
    return [
        'id' => $eventId,
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => [
                'id' => $subscriptionId,
                'customer' => 'cus_wh_test',
                'status' => 'active',
                'items' => [
                    'data' => [
                        ['price' => ['id' => $payloadPrice]],
                    ],
                ],
            ],
        ],
    ];
}

it('syncs the plan from the refetched remote price, not the possibly stale payload', function () {
    $this->listener->remoteState = ['status' => 'active', 'price_id' => 'price_wh_current'];

    // The payload carries an older price — out-of-order delivery. Only the
    // refetched price may be synced.
    $this->syncAction->shouldReceive('execute')
        ->once()
        ->withArgs(fn ($billable, $priceId) => $priceId === 'price_wh_current')
        ->andReturn(true);

    $this->listener->handle(new WebhookHandled(stripeWebhookPayload('evt_stale_payload', payloadPrice: 'price_wh_stale')));

    expect($this->listener->fetches)->toBe(1);
});

it('ignores events for a subscription that is not the default-type subscription', function () {
    // An add-on (non-default) subscription's webhook must not overwrite the
    // billable's plan.
    $this->syncAction->shouldNotReceive('execute');
    $this->deleteAction->shouldNotReceive('execute');

    $this->listener->handle(new WebhookHandled(stripeWebhookPayload('evt_addon', subscriptionId: 'sub_wh_addon')));

    expect($this->listener->fetches)->toBe(0);
});

it('does not grant entitlements for an incomplete (unpaid) subscription', function () {
    // Stripe creates subscriptions with status=incomplete before the first
    // payment settles — granting here would give a never-paid customer a plan.
    $this->listener->remoteState = ['status' => 'incomplete', 'price_id' => 'price_wh_current'];

    $this->syncAction->shouldNotReceive('execute');
    $this->deleteAction->shouldNotReceive('execute');

    $this->listener->handle(new WebhookHandled(stripeWebhookPayload('evt_incomplete')));
});

it('revokes entitlements when the remote subscription is canceled', function () {
    $this->listener->remoteState = ['status' => 'canceled', 'price_id' => null];

    $this->deleteAction->shouldReceive('execute')->once();
    $this->syncAction->shouldNotReceive('execute');

    $this->listener->handle(new WebhookHandled(stripeWebhookPayload('evt_canceled')));
});

it('keeps entitlements on past_due by default and revokes when configured not to', function () {
    $this->listener->remoteState = ['status' => 'past_due', 'price_id' => 'price_wh_current'];

    $this->syncAction->shouldNotReceive('execute');
    $this->deleteAction->shouldNotReceive('execute');
    $this->listener->handle(new WebhookHandled(stripeWebhookPayload('evt_past_due_keep')));

    Config::set('plan-usage.stripe.past_due_keeps_entitlements', false);
    $this->deleteAction->shouldReceive('execute')->once();
    $this->listener->handle(new WebhookHandled(stripeWebhookPayload('evt_past_due_revoke')));
});

it('processes a duplicate delivery only once', function () {
    $this->syncAction->shouldReceive('execute')->once()->andReturn(true);

    $event = new WebhookHandled(stripeWebhookPayload('evt_dup_1'));

    $this->listener->handle($event);
    $this->listener->handle($event);
});

it('releases the dedupe key on failure so the provider retry is processed', function () {
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

    $event = new WebhookHandled(stripeWebhookPayload('evt_retry_1'));

    expect(fn () => $this->listener->handle($event))
        ->toThrow(Exception::class, 'Transient database error');

    $this->listener->handle($event);

    expect($attempts)->toBe(2);
});

it('waits on the shared subscription-state lock and rethrows on timeout', function () {
    // A plan change (or another webhook) holding the billable's lock must
    // serialize this delivery — the pre-fix race let a fast webhook replace a
    // prorated allowance with the full target-plan allowance.
    $store = Cache::store(config('plan-usage.cache.store'))->getStore();
    $lock = $store->lock($this->stateLock->key($this->billable), 10);
    $lock->get();

    $this->syncAction->shouldNotReceive('execute');

    try {
        expect(fn () => $this->listener->handle(new WebhookHandled(stripeWebhookPayload('evt_locked'))))
            ->toThrow(LockTimeoutException::class);
    } finally {
        $lock->release();
    }

    // The dedupe key was released with the rethrow, so the redelivery after
    // the lock is free processes normally.
    $this->syncAction->shouldReceive('execute')->once()->andReturn(true);
    $this->listener->handle(new WebhookHandled(stripeWebhookPayload('evt_locked')));
});
