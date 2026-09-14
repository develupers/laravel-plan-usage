<?php

declare(strict_types=1);

use Danestves\LaravelPolar\Billable as PolarBillable;
use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Providers\Polar\PolarProvider;
use Develupers\PlanUsage\Traits\HasPlanFeatures;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Polar\Models\Components\Subscription;
use Polar\Models\Components\SubscriptionStatus;

class ReconcilePolarTestBillable extends Model implements Billable
{
    use HasPlanFeatures;
    use PolarBillable;

    public $timestamps = false;

    protected $table = 'test_billables';

    protected $guarded = [];
}

beforeEach(function () {
    config()->set('plan-usage.billing.provider', 'polar');
    config()->set('plan-usage.models.billable', ReconcilePolarTestBillable::class);

    $this->plan = Plan::factory()->create(['slug' => 'reconcile-polar-plan']);
    $this->planPrice = $this->plan->defaultPrice()->firstOrFail();
    $this->planPrice->update(['polar_product_id' => 'prod_reconcile_polar']);
});

it('never touches Polar billables without any subscription rows (lifetime purchases)', function () {
    $billable = ReconcilePolarTestBillable::query()->create([
        'plan_id' => $this->plan->id,
        'plan_price_id' => $this->planPrice->id,
    ]);

    $this->artisan('subscriptions:reconcile', ['--force' => true])
        ->assertSuccessful();

    expect($billable->fresh()->plan_id)->toBe($this->plan->id)
        ->and($billable->fresh()->plan_price_id)->toBe($this->planPrice->id);
});

it('skips instead of revoking when a Polar billable has no default subscription', function () {
    $billable = ReconcilePolarTestBillable::query()->create([
        'plan_id' => $this->plan->id,
        'plan_price_id' => $this->planPrice->id,
    ]);
    // A custom-typed subscription passes the whereHas filter but leaves
    // subscription('default') null inside the reconcile loop.
    $billable->subscriptions()->create([
        'type' => 'secondary',
        'polar_id' => 'sub_reconcile_custom',
        'status' => 'active',
        'product_id' => 'prod_reconcile_polar',
    ]);

    $this->artisan('subscriptions:reconcile', ['--force' => true])
        ->expectsOutputToContain('Skipped: no default subscription to reconcile against')
        ->assertSuccessful();

    expect($billable->fresh()->plan_id)->toBe($this->plan->id)
        ->and($billable->fresh()->quotas()->count())->toBeGreaterThanOrEqual(0);
});

class ReconcilePolarTrashedBillable extends ReconcilePolarTestBillable
{
    use SoftDeletes;
}

/**
 * A remote Polar subscription as the SDK returns it. Mockery subclasses the
 * component, so its public typed properties can be assigned directly without
 * building the customer/product graph the real constructor requires.
 */
function remotePolarSubscription(array $overrides = []): Subscription
{
    $remote = Mockery::mock(Subscription::class);
    $remote->id = 'sub_reconcile_polar';
    $remote->status = SubscriptionStatus::Active;
    $remote->productId = 'prod_reconcile_polar';
    $remote->currentPeriodStart = new DateTime('2026-07-01 00:00:00');
    $remote->currentPeriodEnd = new DateTime('2026-08-01 00:00:00');
    $remote->cancelAtPeriodEnd = false;
    $remote->trialEnd = null;
    $remote->endsAt = null;
    $remote->endedAt = null;
    $remote->pendingUpdate = null;

    foreach ($overrides as $property => $value) {
        $remote->{$property} = $value;
    }

    return $remote;
}

function bindPolarProviderReturning(Subscription $remote): void
{
    $provider = Mockery::mock(PolarProvider::class)->makePartial();
    $provider->shouldReceive('fetchSubscription')->andReturn($remote);
    app()->instance(PolarProvider::class, $provider);
    app()->instance(BillingProvider::class, $provider);
}

it('converges the local subscription row to the remote state before deciding entitlements', function () {
    // Webhooks write the row in delivery order with no ordering guard: a lost
    // or late `revoked` leaves it `active` forever, hasLiveSubscription()
    // stays true, and the customer can never re-subscribe.
    $billable = ReconcilePolarTestBillable::query()->create([
        'plan_id' => $this->plan->id,
        'plan_price_id' => $this->planPrice->id,
    ]);
    $billable->subscriptions()->create([
        'type' => 'default',
        'polar_id' => 'sub_reconcile_polar',
        'status' => 'active',
        'product_id' => 'prod_reconcile_polar',
        'current_period_end' => '2026-08-01 00:00:00',
    ]);
    bindPolarProviderReturning(remotePolarSubscription([
        'status' => SubscriptionStatus::Canceled,
        'endsAt' => new DateTime('2026-06-01 00:00:00'),
        'endedAt' => new DateTime('2026-06-01 00:00:00'),
    ]));

    $this->artisan('subscriptions:reconcile', ['--force' => true])
        ->expectsOutputToContain('Synced local subscription row (status active → canceled)')
        ->assertSuccessful();

    $row = $billable->subscriptions()->firstOrFail();

    expect($billable->fresh()->plan_id)->toBeNull()
        ->and($row->status->value)->toBe('canceled')
        ->and($row->ends_at?->toDateTimeString())->toBe('2026-06-01 00:00:00')
        ->and($billable->fresh()->subscriptionHasEnded())->toBeTrue()
        ->and($billable->fresh()->hasLiveSubscription())->toBeFalse();
});

it('reports the row sync in dry-run mode without writing it', function () {
    $billable = ReconcilePolarTestBillable::query()->create([
        'plan_id' => $this->plan->id,
        'plan_price_id' => $this->planPrice->id,
    ]);
    $billable->subscriptions()->create([
        'type' => 'default',
        'polar_id' => 'sub_reconcile_polar',
        'status' => 'active',
        'product_id' => 'prod_reconcile_polar',
    ]);
    bindPolarProviderReturning(remotePolarSubscription([
        'status' => SubscriptionStatus::PastDue,
    ]));

    $this->artisan('subscriptions:reconcile', ['--force' => true, '--dry-run' => true])
        ->expectsOutputToContain('[DRY-RUN] Would sync local subscription row (status active → past_due)')
        ->assertSuccessful();

    expect($billable->subscriptions()->firstOrFail()->status->value)->toBe('active')
        ->and($billable->fresh()->plan_id)->toBe($this->plan->id);
});

it('leaves an already converged row alone', function () {
    $billable = ReconcilePolarTestBillable::query()->create([
        'plan_id' => $this->plan->id,
        'plan_price_id' => $this->planPrice->id,
    ]);
    $billable->subscriptions()->create([
        'type' => 'default',
        'polar_id' => 'sub_reconcile_polar',
        'status' => 'active',
        'product_id' => 'prod_reconcile_polar',
        'current_period_end' => '2026-08-01 00:00:00',
    ]);
    bindPolarProviderReturning(remotePolarSubscription());

    $this->artisan('subscriptions:reconcile', ['--force' => true])
        ->doesntExpectOutputToContain('Synced local subscription row')
        ->assertSuccessful();

    expect($billable->fresh()->plan_id)->toBe($this->plan->id);
});

it('reconciles soft-deleted billables so a restore cannot revive a revoked plan', function () {
    Schema::table('test_billables', function (Blueprint $table): void {
        $table->timestamp('deleted_at')->nullable();
    });
    config()->set('plan-usage.models.billable', ReconcilePolarTrashedBillable::class);

    $billable = ReconcilePolarTrashedBillable::query()->create([
        'plan_id' => $this->plan->id,
        'plan_price_id' => $this->planPrice->id,
    ]);
    $billable->subscriptions()->create([
        'type' => 'default',
        'polar_id' => 'sub_reconcile_polar',
        'status' => 'active',
        'product_id' => 'prod_reconcile_polar',
    ]);
    $billable->delete();
    bindPolarProviderReturning(remotePolarSubscription([
        'status' => SubscriptionStatus::Canceled,
        'endsAt' => new DateTime('2026-06-01 00:00:00'),
        'endedAt' => new DateTime('2026-06-01 00:00:00'),
    ]));

    $this->artisan('subscriptions:reconcile', ['--force' => true])
        ->expectsOutputToContain('Removed plan from billable')
        ->assertSuccessful();

    expect(ReconcilePolarTrashedBillable::withTrashed()->findOrFail($billable->getKey())->plan_id)->toBeNull();
});

it('records the actual end, not a stale scheduled one, when the remote subscription has ended early', function () {
    // A period-end cancellation that was then revoked early carries both a
    // future ends_at and a past ended_at. Keeping the future date locally
    // would read as a grace period — live — and block a fresh checkout.
    $billable = ReconcilePolarTestBillable::query()->create([
        'plan_id' => $this->plan->id,
        'plan_price_id' => $this->planPrice->id,
    ]);
    $billable->subscriptions()->create([
        'type' => 'default',
        'polar_id' => 'sub_reconcile_polar',
        'status' => 'active',
        'product_id' => 'prod_reconcile_polar',
        'ends_at' => now()->addMonth(),
    ]);
    bindPolarProviderReturning(remotePolarSubscription([
        'status' => SubscriptionStatus::Canceled,
        'endsAt' => new DateTime(now()->addMonth()->toDateTimeString()),
        'endedAt' => new DateTime(now()->subDay()->toDateTimeString()),
    ]));

    $this->artisan('subscriptions:reconcile', ['--force' => true])->assertSuccessful();

    expect($billable->fresh()->plan_id)->toBeNull()
        ->and($billable->subscriptions()->firstOrFail()->ends_at->isPast())->toBeTrue()
        ->and($billable->fresh()->hasLiveSubscription())->toBeFalse();
});
