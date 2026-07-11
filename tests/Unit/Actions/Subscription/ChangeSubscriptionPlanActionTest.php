<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Danestves\LaravelPolar\Billable as PolarBillable;
use Develupers\PlanUsage\Actions\Subscription\ApplyPlanChangeAction;
use Develupers\PlanUsage\Actions\Subscription\CancelPendingPlanChangeAction;
use Develupers\PlanUsage\Actions\Subscription\ChangeSubscriptionPlanAction;
use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Contracts\SubscriptionLifecycleProvider;
use Develupers\PlanUsage\Enums\Interval;
use Develupers\PlanUsage\Enums\Period;
use Develupers\PlanUsage\Enums\SubscriptionChangeStatus;
use Develupers\PlanUsage\Enums\SubscriptionChangeTiming;
use Develupers\PlanUsage\Events\SubscriptionPlanChangeCancelled;
use Develupers\PlanUsage\Events\SubscriptionPlanChanged;
use Develupers\PlanUsage\Events\SubscriptionPlanChangeScheduled;
use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\SubscriptionPlanChange;
use Develupers\PlanUsage\Services\QuotaEnforcer;
use Develupers\PlanUsage\Support\ProviderSubscriptionChange;
use Develupers\PlanUsage\Support\SubscriptionStateLock;
use Develupers\PlanUsage\Traits\HasPlanFeatures;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

class PlanChangeTestBillable extends Model implements Billable
{
    use HasPlanFeatures;
    use PolarBillable;

    public $timestamps = false;

    protected $table = 'test_billables';

    protected $guarded = [];
}

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-07-16 12:00:00');
    config()->set('plan-usage.billing.provider', 'polar');
    config()->set('plan-usage.models.billable', PlanChangeTestBillable::class);

    $this->credits = Feature::factory()->quota()->create([
        'slug' => 'credits',
        'reset_period' => Period::MONTH,
    ]);
    $this->starter = Plan::factory()->create(['slug' => 'starter']);
    $this->growth = Plan::factory()->create(['slug' => 'growth']);
    $this->starterPrice = $this->starter->defaultPrice()->first();
    $this->starterPrice->update([
        'interval' => Interval::YEAR,
        'polar_product_id' => 'prod_starter_yearly',
    ]);
    $this->growthPrice = $this->growth->defaultPrice()->first();
    $this->growthPrice->update([
        'interval' => Interval::YEAR,
        'polar_product_id' => 'prod_growth_yearly',
    ]);
    $this->starter->features()->attach($this->credits->id, ['value' => '1000']);
    $this->growth->features()->attach($this->credits->id, ['value' => '5000']);

    $this->billable = PlanChangeTestBillable::query()->create([
        'plan_id' => $this->starter->id,
        'plan_price_id' => $this->starterPrice->id,
    ]);
    $this->billable->subscriptions()->create([
        'type' => 'default',
        'polar_id' => 'sub_polar_123',
        'status' => 'active',
        'product_id' => 'prod_starter_yearly',
        'current_period_end' => '2027-07-01 00:00:00',
    ]);
    $this->billable->load('plan.features');
    $this->billable->syncQuotasWithPlan();

    $this->provider = Mockery::mock(BillingProvider::class.', '.SubscriptionLifecycleProvider::class);
    $this->provider->shouldReceive('name')->andReturn('polar')->byDefault();
    $this->applyAction = new ApplyPlanChangeAction(app(QuotaEnforcer::class));
    $this->action = new ChangeSubscriptionPlanAction($this->provider, $this->applyAction, new SubscriptionStateLock);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('applies an immediate upgrade only after provider confirmation and prorates monthly credits', function () {
    Event::fake();
    $this->provider->shouldReceive('changeSubscription')
        ->once()
        ->with(
            Mockery::on(fn ($billable) => $billable->is($this->billable)),
            'prod_growth_yearly',
            SubscriptionChangeTiming::Immediate,
            'default'
        )
        ->andReturn(new ProviderSubscriptionChange(
            providerSubscriptionId: 'sub_polar_123',
            currentProductId: 'prod_growth_yearly',
            pendingProductId: null,
            periodStart: CarbonImmutable::parse('2026-07-01 00:00:00'),
            periodEnd: CarbonImmutable::parse('2027-07-01 00:00:00'),
        ));

    $planChange = $this->action->execute(
        $this->billable,
        $this->growthPrice,
        SubscriptionChangeTiming::Immediate
    );

    $quota = $this->billable->fresh()->quotas()->where('feature_id', $this->credits->id)->firstOrFail();

    expect($planChange->status)->toBe(SubscriptionChangeStatus::Applied)
        ->and($this->billable->fresh()->plan_id)->toBe($this->growth->id)
        ->and($quota->limit)->toBe(3000.0)
        ->and($quota->used)->toBe(0.0)
        ->and($quota->reset_at?->toDateTimeString())->toBe('2026-08-01 00:00:00');

    Event::assertDispatched(SubscriptionPlanChanged::class);
});

it('prorates stacked same-period upgrades from the plan allowance, not the stored limit', function () {
    // starter(1000) → growth(5000) at 50% remaining = 3000, then
    // growth(5000) → mega(15000) at 50% = 3000 + (15000-5000)×0.5 = 8000.
    // Using the stored prorated limit as the delta reference would give 9000.
    $mega = Plan::factory()->create(['slug' => 'mega']);
    $megaPrice = $mega->defaultPrice()->first();
    $megaPrice->update(['interval' => Interval::YEAR, 'polar_product_id' => 'prod_mega_yearly']);
    $mega->features()->attach($this->credits->id, ['value' => '15000']);

    $providerChange = fn (string $productId) => new ProviderSubscriptionChange(
        providerSubscriptionId: 'sub_polar_123',
        currentProductId: $productId,
        pendingProductId: null,
        periodStart: CarbonImmutable::parse('2026-07-01 00:00:00'),
        periodEnd: CarbonImmutable::parse('2027-07-01 00:00:00'),
    );
    $this->provider->shouldReceive('changeSubscription')
        ->twice()
        ->andReturn($providerChange('prod_growth_yearly'), $providerChange('prod_mega_yearly'));

    $this->action->execute($this->billable, $this->growthPrice, SubscriptionChangeTiming::Immediate);
    $this->billable->refresh()->unsetRelation('plan');
    $this->action->execute($this->billable, $megaPrice, SubscriptionChangeTiming::Immediate);

    $quota = $this->billable->fresh()->quotas()->where('feature_id', $this->credits->id)->firstOrFail();

    expect($quota->limit)->toBe(8000.0);
});

it('keeps a grandfathered allowance when re-upgrading below it', function () {
    // Billable on growth (5000) downgraded quota stays grandfathered at 5000;
    // an immediate change to a 3000-value plan must not grant more credits.
    $mid = Plan::factory()->create(['slug' => 'mid']);
    $midPrice = $mid->defaultPrice()->first();
    $midPrice->update(['interval' => Interval::YEAR, 'polar_product_id' => 'prod_mid_yearly']);
    $mid->features()->attach($this->credits->id, ['value' => '3000']);

    // Simulate: on starter (1000) but grandfathered period limit of 5000.
    $this->billable->quotas()->where('feature_id', $this->credits->id)->update(['limit' => 5000]);

    $this->provider->shouldReceive('changeSubscription')
        ->once()
        ->andReturn(new ProviderSubscriptionChange(
            providerSubscriptionId: 'sub_polar_123',
            currentProductId: 'prod_mid_yearly',
            pendingProductId: null,
            periodStart: CarbonImmutable::parse('2026-07-01 00:00:00'),
            periodEnd: CarbonImmutable::parse('2027-07-01 00:00:00'),
        ));

    $this->action->execute($this->billable, $midPrice, SubscriptionChangeTiming::Immediate);

    $quota = $this->billable->fresh()->quotas()->where('feature_id', $this->credits->id)->firstOrFail();

    expect($quota->limit)->toBe(5000.0);
});

it('grants the full target allowance for non-resetting quotas on immediate upgrade', function () {
    // Lifetime (non-resetting) allowances have no renewal that would ever
    // true a prorated grant up to the target, so the upgrade must grant it all.
    $lifetimeCredits = Feature::factory()->quota()->create([
        'slug' => 'lifetime-credits',
        'reset_period' => null,
    ]);
    $this->starter->features()->attach($lifetimeCredits->id, ['value' => '100']);
    $this->growth->features()->attach($lifetimeCredits->id, ['value' => '1000']);
    $this->billable->unsetRelation('plan');
    $this->billable->syncQuotasWithPlan();
    $this->billable->quotas()
        ->where('feature_id', $lifetimeCredits->id)
        ->update(['used' => 20]);

    $this->provider->shouldReceive('changeSubscription')
        ->once()
        ->andReturn(new ProviderSubscriptionChange(
            providerSubscriptionId: 'sub_polar_123',
            currentProductId: 'prod_growth_yearly',
            pendingProductId: null,
            periodStart: CarbonImmutable::parse('2026-07-01 00:00:00'),
            periodEnd: CarbonImmutable::parse('2027-07-01 00:00:00'),
        ));

    $this->action->execute($this->billable, $this->growthPrice, SubscriptionChangeTiming::Immediate);

    $quota = $this->billable->fresh()->quotas()->where('feature_id', $lifetimeCredits->id)->firstOrFail();

    expect($quota->limit)->toBe(1000.0)
        ->and($quota->used)->toBe(20.0)
        ->and($quota->reset_at)->toBeNull();
});

it('schedules a next-period downgrade without changing current entitlements', function () {
    $this->billable->update([
        'plan_id' => $this->growth->id,
        'plan_price_id' => $this->growthPrice->id,
    ]);
    $this->billable->unsetRelation('plan');
    $this->billable->syncQuotasWithPlan();
    Event::fake();

    $this->provider->shouldReceive('changeSubscription')
        ->once()
        ->andReturn(new ProviderSubscriptionChange(
            providerSubscriptionId: 'sub_polar_123',
            currentProductId: 'prod_growth_yearly',
            pendingProductId: 'prod_starter_yearly',
            periodStart: CarbonImmutable::parse('2026-07-01 00:00:00'),
            periodEnd: CarbonImmutable::parse('2027-07-01 00:00:00'),
            effectiveAt: CarbonImmutable::parse('2027-07-01 00:00:00'),
            providerChangeId: 'pending_123',
        ));

    $planChange = $this->action->execute(
        $this->billable,
        $this->starterPrice,
        SubscriptionChangeTiming::NextPeriod
    );

    expect($planChange->status)->toBe(SubscriptionChangeStatus::Pending)
        ->and($planChange->effective_at?->toDateTimeString())->toBe('2027-07-01 00:00:00')
        ->and($this->billable->fresh()->plan_id)->toBe($this->growth->id)
        ->and($this->billable->fresh()->plan_price_id)->toBe($this->growthPrice->id);

    Event::assertDispatched(SubscriptionPlanChangeScheduled::class);
    Event::assertNotDispatched(SubscriptionPlanChanged::class);
});

it('keeps local entitlements unchanged when the immediate provider charge fails', function () {
    $this->provider->shouldReceive('changeSubscription')
        ->once()
        ->andThrow(new RuntimeException('Payment required'));

    expect(fn () => $this->action->execute(
        $this->billable,
        $this->growthPrice,
        SubscriptionChangeTiming::Immediate
    ))->toThrow(RuntimeException::class, 'Payment required');

    expect($this->billable->fresh()->plan_id)->toBe($this->starter->id)
        ->and(SubscriptionPlanChange::query()->latest('id')->first()?->status)
        ->toBe(SubscriptionChangeStatus::Failed);
});

it('cancels a pending provider change and its local record', function () {
    $planChange = SubscriptionPlanChange::query()->create([
        'billable_type' => $this->billable->getMorphClass(),
        'billable_id' => $this->billable->getKey(),
        'provider' => 'polar',
        'subscription_type' => 'default',
        'provider_subscription_id' => 'sub_polar_123',
        'from_plan_price_id' => $this->starterPrice->id,
        'to_plan_price_id' => $this->growthPrice->id,
        'timing' => SubscriptionChangeTiming::NextPeriod,
        'status' => SubscriptionChangeStatus::Pending,
    ]);
    Event::fake();
    $this->provider->shouldReceive('cancelPendingSubscriptionChange')
        ->once()
        ->with(Mockery::on(fn ($billable) => $billable->is($this->billable)), 'default');

    $cancelled = (new CancelPendingPlanChangeAction($this->provider, new SubscriptionStateLock))->execute($this->billable);

    expect($cancelled->is($planChange))->toBeTrue()
        ->and($cancelled->status)->toBe(SubscriptionChangeStatus::Cancelled)
        ->and($cancelled->cancelled_at)->not->toBeNull();

    Event::assertDispatched(SubscriptionPlanChangeCancelled::class);
});
