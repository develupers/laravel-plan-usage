<?php

declare(strict_types=1);

use Develupers\PlanUsage\Actions\Subscription\CreateCheckoutSessionAction;
use Develupers\PlanUsage\Contracts\Billable as BillableContract;
use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Contracts\CheckoutSession;
use Develupers\PlanUsage\Enums\Interval;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanPrice;
use Develupers\PlanUsage\Traits\HasPlanFeatures;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CheckoutActionTestBillable extends Model implements BillableContract
{
    use HasPlanFeatures;

    public $timestamps = false;

    protected $table = 'test_billables';

    protected $guarded = [];
}

beforeEach(function () {
    $this->provider = Mockery::mock(BillingProvider::class);
    $this->action = new CreateCheckoutSessionAction($this->provider);

    $this->lifetimePlan = Plan::factory()->create(['is_lifetime' => true]);
    $this->lifetimePrice = PlanPrice::factory()->create([
        'plan_id' => $this->lifetimePlan->id,
        'interval' => Interval::LIFETIME,
        'stripe_price_id' => 'price_lifetime_checkout',
    ]);
});

it('blocks a repeat checkout for a lifetime plan the billable already holds', function () {
    // Lifetime purchases create no subscription row, so the subscribed() guard
    // cannot catch this. A duplicate purchase double-charges — and refunding
    // the duplicate would revoke the plan the surviving order still grants.
    $billable = CheckoutActionTestBillable::query()->create([
        'plan_id' => $this->lifetimePlan->id,
        'plan_price_id' => $this->lifetimePrice->id,
    ]);

    expect(fn () => $this->action->execute($billable, 'price_lifetime_checkout'))
        ->toThrow(ValidationException::class, 'You already own this plan.');
});

it('allows a lifetime checkout for a billable that does not hold the plan', function () {
    $billable = CheckoutActionTestBillable::query()->create();

    $session = Mockery::mock(CheckoutSession::class);
    $this->provider->shouldReceive('createCheckoutSession')
        ->once()
        ->andReturn($session);

    expect($this->action->execute($billable, 'price_lifetime_checkout'))->toBe($session);
});
