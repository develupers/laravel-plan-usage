<?php

declare(strict_types=1);

use Develupers\PlanUsage\Actions\Subscription\DeleteSubscriptionAction;
use Develupers\PlanUsage\Actions\Subscription\SyncPlanWithBillableAction;
use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\Usage;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->action = new DeleteSubscriptionAction;
});

it('clears plan and deletes quotas on execute', function () {
    $billable = createBillable([
        'plan_id' => 1,
        'plan_price_id' => 100,
        'plan_changed_at' => now()->subDay(),
    ]);

    // Mock quotas relationship
    $quotaQuery = Mockery::mock();
    $quotaQuery->shouldReceive('delete')->once()->andReturn(3);

    $billable = Mockery::mock(Billable::class);
    $billable->shouldReceive('getKey')->andReturn(1);
    $billable->shouldReceive('quotas')->once()->andReturn($quotaQuery);
    $billable->plan_id = 1;
    $billable->plan_price_id = 100;
    $billable->shouldReceive('save')->once();

    Log::shouldReceive('info')->once()->withArgs(function ($message, $context) {
        return $message === 'Deleted subscription and cleared quotas for billable' &&
               $context['billable_id'] === 1 &&
               $context['old_plan_id'] === 1 &&
               $context['old_plan_price_id'] === 100 &&
               $context['quotas_deleted'] === 3;
    });

    $this->action->execute($billable);

    expect($billable->plan_id)->toBeNull();
    expect($billable->plan_price_id)->toBeNull();
});

it('handles quota deletion failure gracefully', function () {
    $billable = Mockery::mock(Billable::class);
    $billable->shouldReceive('getKey')->andReturn(1);
    $billable->plan_id = 1;
    $billable->plan_price_id = 100;

    $quotaQuery = Mockery::mock();
    $quotaQuery->shouldReceive('delete')->once()->andThrow(new Exception('Database error'));
    $billable->shouldReceive('quotas')->once()->andReturn($quotaQuery);
    $billable->shouldReceive('save')->once();

    Log::shouldReceive('error')->once()->withArgs(function ($message, $context) {
        return $message === 'Failed to clear quotas during subscription deletion' &&
               $context['error'] === 'Database error';
    });

    $this->action->execute($billable);

    expect($billable->plan_id)->toBeNull();
    expect($billable->plan_price_id)->toBeNull();
});

it('sets default plan when configured', function () {
    Config::set('plan-usage.subscription.default_plan_id', 999);

    $defaultPlan = Plan::factory()->create(['id' => 999]);

    $billable = Mockery::mock(Billable::class);
    $billable->shouldReceive('getKey')->andReturn(1);

    $syncAction = Mockery::mock(SyncPlanWithBillableAction::class);
    $syncAction->shouldReceive('execute')
        ->with($billable, Mockery::on(function ($plan) {
            return $plan->id === 999;
        }))
        ->once()
        ->andReturn(true);

    $this->app->instance(SyncPlanWithBillableAction::class, $syncAction);

    Log::shouldReceive('info')->once()->withArgs(function ($message, $context) {
        return $message === 'Moved billable to default plan after subscription deletion' &&
               $context['default_plan_id'] === 999;
    });

    $this->action->executeWithDefaultPlan($billable);
});

it('falls back to execute when default plan not found', function () {
    Config::set('plan-usage.subscription.default_plan_id', 999);

    // Plan doesn't exist
    $billable = Mockery::mock(Billable::class);
    $billable->shouldReceive('getKey')->andReturn(1);
    $billable->plan_id = 1;
    $billable->plan_price_id = 100;

    $quotaQuery = Mockery::mock();
    $quotaQuery->shouldReceive('delete')->once()->andReturn(0);
    $billable->shouldReceive('quotas')->once()->andReturn($quotaQuery);
    $billable->shouldReceive('save')->once();

    Log::shouldReceive('warning')->once()->withArgs(function ($message, $context) {
        return $message === 'Default plan ID from config not found, clearing subscription' &&
               $context['default_plan_id'] === 999;
    });

    Log::shouldReceive('info')->once();

    $this->action->executeWithDefaultPlan($billable);

    expect($billable->plan_id)->toBeNull();
});

it('executes normally when no default plan configured', function () {
    Config::set('plan-usage.subscription.default_plan_id', null);

    $billable = Mockery::mock(Billable::class);
    $billable->shouldReceive('getKey')->andReturn(1);
    $billable->plan_id = 1;
    $billable->plan_price_id = 100;

    $quotaQuery = Mockery::mock();
    $quotaQuery->shouldReceive('delete')->once()->andReturn(0);
    $billable->shouldReceive('quotas')->once()->andReturn($quotaQuery);
    $billable->shouldReceive('save')->once();

    Log::shouldReceive('info')->once();

    $this->action->executeWithDefaultPlan($billable);

    expect($billable->plan_id)->toBeNull();
});

it('clears usage records when configured in complete deletion', function () {
    Config::set('plan-usage.subscription.clear_usage_on_delete', true);
    Config::set('plan-usage.subscription.clear_stripe_on_delete', false);

    $billable = Mockery::mock(Billable::class);
    $billable->shouldReceive('getKey')->andReturn(1);
    $billable->plan_id = 1;
    $billable->plan_price_id = 100;

    $quotaQuery = Mockery::mock();
    $quotaQuery->shouldReceive('delete')->once()->andReturn(5);
    $billable->shouldReceive('quotas')->once()->andReturn($quotaQuery);

    $usageQuery = Mockery::mock();
    $usageQuery->shouldReceive('delete')->once()->andReturn(10);
    $billable->shouldReceive('usage')->once()->andReturn($usageQuery);

    $billable->shouldReceive('save')->once();

    Log::shouldReceive('info')->twice(); // Once for quotas, once for usage

    $this->action->executeComplete($billable);

    expect($billable->plan_id)->toBeNull();
});

it('clears Stripe data when configured in complete deletion', function () {
    Config::set('plan-usage.subscription.clear_usage_on_delete', false);
    Config::set('plan-usage.subscription.clear_stripe_on_delete', true);

    $billable = Mockery::mock(Billable::class);
    $billable->shouldReceive('getKey')->andReturn(1);
    $billable->plan_id = 1;
    $billable->plan_price_id = 100;
    $billable->stripe_id = 'cus_test123';
    $billable->pm_type = 'card';
    $billable->pm_last_four = '4242';
    $billable->trial_ends_at = now();

    $quotaQuery = Mockery::mock();
    $quotaQuery->shouldReceive('delete')->once()->andReturn(0);
    $billable->shouldReceive('quotas')->once()->andReturn($quotaQuery);
    $billable->shouldReceive('save')->twice(); // Once for plan clear, once for Stripe clear

    Log::shouldReceive('info')->twice(); // Once for quotas, once for Stripe

    $this->action->executeComplete($billable);

    expect($billable->plan_id)->toBeNull();
    expect($billable->stripe_id)->toBeNull();
    expect($billable->pm_type)->toBeNull();
    expect($billable->pm_last_four)->toBeNull();
    expect($billable->trial_ends_at)->toBeNull();
});

it('handles usage deletion failure gracefully', function () {
    Config::set('plan-usage.subscription.clear_usage_on_delete', true);

    $billable = Mockery::mock(Billable::class);
    $billable->shouldReceive('getKey')->andReturn(1);
    $billable->plan_id = 1;
    $billable->plan_price_id = 100;

    $quotaQuery = Mockery::mock();
    $quotaQuery->shouldReceive('delete')->once()->andReturn(0);
    $billable->shouldReceive('quotas')->once()->andReturn($quotaQuery);

    $usageQuery = Mockery::mock();
    $usageQuery->shouldReceive('delete')->once()->andThrow(new Exception('Usage deletion failed'));
    $billable->shouldReceive('usage')->once()->andReturn($usageQuery);

    $billable->shouldReceive('save')->once();

    Log::shouldReceive('info')->once();
    Log::shouldReceive('error')->once()->withArgs(function ($message, $context) {
        return $message === 'Failed to clear usage during subscription deletion' &&
               $context['error'] === 'Usage deletion failed';
    });

    $this->action->executeComplete($billable);
});

it('gets plan ID from billable using property', function () {
    $billable = createBillable(['plan_id' => 42]);

    $reflection = new ReflectionClass($this->action);
    $method = $reflection->getMethod('getBillablePlanId');
    $method->setAccessible(true);

    $result = $method->invoke($this->action, $billable);

    expect($result)->toBe(42);
});

it('gets plan ID from billable using method', function () {
    // Create a mock that doesn't have the plan_id property but has the method
    $billable = new class implements Billable
    {
        use \Develupers\PlanUsage\Traits\HasPlanFeatures;
        use \Laravel\Cashier\Billable;

        public function getPlanId(): ?int
        {
            return 99;
        }

        public function getKey()
        {
            return 1;
        }

        public function getMorphClass()
        {
            return 'Test\\Billable';
        }

        public function save(array $options = [])
        {
            return true;
        }

        public function quotas()
        {
            return null;
        }

        public function usage()
        {
            return null;
        }

        public function plan()
        {
            return null;
        }
    };

    $reflection = new ReflectionClass($this->action);
    $method = $reflection->getMethod('getBillablePlanId');
    $method->setAccessible(true);

    $result = $method->invoke($this->action, $billable);

    expect($result)->toBe(99);
});

it('returns null when billable has no plan ID', function () {
    $billable = Mockery::mock(Billable::class);

    $reflection = new ReflectionClass($this->action);
    $method = $reflection->getMethod('getBillablePlanId');
    $method->setAccessible(true);

    $result = $method->invoke($this->action, $billable);

    expect($result)->toBeNull();
});
