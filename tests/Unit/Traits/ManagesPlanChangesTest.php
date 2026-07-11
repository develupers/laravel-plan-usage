<?php

declare(strict_types=1);

use Danestves\LaravelPolar\Billable as PolarBillable;
use Develupers\PlanUsage\Actions\Subscription\CancelPendingPlanChangeAction;
use Develupers\PlanUsage\Actions\Subscription\ChangeSubscriptionPlanAction;
use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Enums\SubscriptionChangeStatus;
use Develupers\PlanUsage\Enums\SubscriptionChangeTiming;
use Develupers\PlanUsage\Facades\PlanUsage;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\SubscriptionPlanChange;
use Develupers\PlanUsage\Traits\HasPlanFeatures;
use Illuminate\Database\Eloquent\Model;

class PlanChangeSurfaceTestBillable extends Model implements Billable
{
    use HasPlanFeatures;
    use PolarBillable;

    public $timestamps = false;

    protected $table = 'test_billables';

    protected $guarded = [];
}

class CustomSubscriptionPlanChange extends SubscriptionPlanChange {}

beforeEach(function () {
    config()->set('plan-usage.billing.provider', 'polar');
    config()->set('plan-usage.models.billable', PlanChangeSurfaceTestBillable::class);

    $this->plan = Plan::factory()->create(['slug' => 'surface-growth']);
    $this->planPrice = $this->plan->defaultPrice()->firstOrFail();
    $this->billable = PlanChangeSurfaceTestBillable::query()->create();
});

it('delegates changePlan to the change subscription action', function () {
    $planChange = new SubscriptionPlanChange;
    $action = Mockery::mock(ChangeSubscriptionPlanAction::class);
    $action->shouldReceive('execute')
        ->once()
        ->with(
            Mockery::on(fn ($billable) => $billable->is($this->billable)),
            Mockery::on(fn ($price) => $price->is($this->planPrice)),
            SubscriptionChangeTiming::Immediate,
            'default'
        )
        ->andReturn($planChange);
    app()->instance(ChangeSubscriptionPlanAction::class, $action);

    expect($this->billable->changePlan($this->planPrice))->toBe($planChange);
});

it('coerces string timing values to the enum', function () {
    $action = Mockery::mock(ChangeSubscriptionPlanAction::class);
    $action->shouldReceive('execute')
        ->once()
        ->with(Mockery::any(), Mockery::any(), SubscriptionChangeTiming::NextPeriod, 'default')
        ->andReturn(new SubscriptionPlanChange);
    app()->instance(ChangeSubscriptionPlanAction::class, $action);

    $this->billable->changePlan($this->planPrice, 'next_period');
});

it('delegates cancelPendingPlanChange to the cancel action', function () {
    $planChange = new SubscriptionPlanChange;
    $action = Mockery::mock(CancelPendingPlanChangeAction::class);
    $action->shouldReceive('execute')
        ->once()
        ->with(Mockery::on(fn ($billable) => $billable->is($this->billable)), 'default')
        ->andReturn($planChange);
    app()->instance(CancelPendingPlanChangeAction::class, $action);

    expect($this->billable->cancelPendingPlanChange())->toBe($planChange);
});

it('returns the latest pending plan change for the current provider', function () {
    $pending = SubscriptionPlanChange::query()->create([
        'billable_type' => $this->billable->getMorphClass(),
        'billable_id' => $this->billable->getKey(),
        'provider' => 'polar',
        'subscription_type' => 'default',
        'provider_subscription_id' => 'sub_surface_123',
        'to_plan_price_id' => $this->planPrice->id,
        'timing' => SubscriptionChangeTiming::NextPeriod,
        'status' => SubscriptionChangeStatus::Pending,
    ]);
    SubscriptionPlanChange::query()->create([
        'billable_type' => $this->billable->getMorphClass(),
        'billable_id' => $this->billable->getKey(),
        'provider' => 'polar',
        'subscription_type' => 'default',
        'provider_subscription_id' => 'sub_surface_123',
        'to_plan_price_id' => $this->planPrice->id,
        'timing' => SubscriptionChangeTiming::Immediate,
        'status' => SubscriptionChangeStatus::Applied,
    ]);

    expect($this->billable->pendingPlanChange()?->is($pending))->toBeTrue()
        ->and($this->billable->pendingPlanChange('other'))->toBeNull();
});

it('honors the subscription_plan_change model override from config', function () {
    config()->set('plan-usage.models.subscription_plan_change', CustomSubscriptionPlanChange::class);
    SubscriptionPlanChange::query()->create([
        'billable_type' => $this->billable->getMorphClass(),
        'billable_id' => $this->billable->getKey(),
        'provider' => 'polar',
        'subscription_type' => 'default',
        'provider_subscription_id' => 'sub_override_123',
        'to_plan_price_id' => $this->planPrice->id,
        'timing' => SubscriptionChangeTiming::NextPeriod,
        'status' => SubscriptionChangeStatus::Pending,
    ]);

    expect($this->billable->pendingPlanChange())->toBeInstanceOf(CustomSubscriptionPlanChange::class);
});

it('returns null from pendingPlanChange when the billing provider cannot be resolved', function () {
    app()->forgetInstance(BillingProvider::class);
    config()->set('plan-usage.billing.provider', 'not-a-real-provider');

    expect($this->billable->pendingPlanChange())->toBeNull();
});

it('fails loud instead of granting a plan when the provider subscription cannot be created directly', function () {
    $this->planPrice->update(['polar_product_id' => 'prod_surface_direct']);

    // Polar billables expose subscribed() but not newSubscription(): the
    // subscription must be created through checkout, so subscribeToPlan must
    // throw BEFORE granting any local entitlements.
    expect(fn () => $this->billable->subscribeToPlan($this->plan))
        ->toThrow(LogicException::class, 'checkout flow');

    expect($this->billable->fresh()->plan_id)->toBeNull()
        ->and($this->billable->fresh()->plan_price_id)->toBeNull()
        ->and($this->billable->quotas()->count())->toBe(0);
});

it('exposes changePlan and cancelPendingPlanChange through the facade', function () {
    $planChange = new SubscriptionPlanChange;
    $change = Mockery::mock(ChangeSubscriptionPlanAction::class);
    $change->shouldReceive('execute')
        ->once()
        ->with(Mockery::any(), Mockery::any(), SubscriptionChangeTiming::Immediate, 'default')
        ->andReturn($planChange);
    app()->instance(ChangeSubscriptionPlanAction::class, $change);
    $cancel = Mockery::mock(CancelPendingPlanChangeAction::class);
    $cancel->shouldReceive('execute')->once()->andReturn($planChange);
    app()->instance(CancelPendingPlanChangeAction::class, $cancel);

    expect(PlanUsage::changePlan($this->billable, $this->planPrice))->toBe($planChange)
        ->and(PlanUsage::cancelPendingPlanChange($this->billable))->toBe($planChange);
});
