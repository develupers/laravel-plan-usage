<?php

declare(strict_types=1);

use Develupers\PlanUsage\Actions\Subscription\CreateStripeCheckoutSessionAction;
use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanPrice;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->action = new CreateStripeCheckoutSessionAction;
});

it('throws exception when billable already has subscription', function () {
    $billable = Mockery::mock(Billable::class);
    $billable->shouldAllowMockingProtectedMethods();
    $billable->shouldReceive('subscribed')->with('default')->once()->andReturn(true);

    $this->action->execute($billable, 'price_test123');
})->throws(ValidationException::class, 'You already have an active subscription');

it('throws exception when price not found in system', function () {
    $billable = Mockery::mock(Billable::class);
    $billable->shouldAllowMockingProtectedMethods();
    $billable->shouldReceive('subscribed')->with('default')->once()->andReturn(false);

    $this->action->execute($billable, 'price_nonexistent');
})->throws(ValidationException::class, 'The selected price is not available');

it('throws exception when plan not available for purchase', function () {
    $plan = Plan::factory()->create(['type' => 'legacy', 'is_active' => true]);
    $planPrice = PlanPrice::factory()->create([
        'plan_id' => $plan->id,
        'stripe_price_id' => 'price_legacy123',
    ]);

    $billable = Mockery::mock(Billable::class);
    $billable->shouldAllowMockingProtectedMethods();
    $billable->shouldReceive('subscribed')->with('default')->once()->andReturn(false);

    $this->action->execute($billable, 'price_legacy123');
})->throws(ValidationException::class, 'The selected plan is not available for purchase');

it('throws exception when plan price has no stripe id', function () {
    $plan = Plan::factory()->create();
    $planPrice = PlanPrice::factory()->create([
        'plan_id' => $plan->id,
        'stripe_price_id' => null,
    ]);

    $billable = Mockery::mock(Billable::class);
    $billable->shouldAllowMockingProtectedMethods();

    $this->action->executeForPlanPrice($billable, $planPrice);
})->throws(ValidationException::class, 'The selected price is not configured for Stripe');
