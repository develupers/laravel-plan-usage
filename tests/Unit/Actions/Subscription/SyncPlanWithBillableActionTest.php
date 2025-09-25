<?php

declare(strict_types=1);

use Develupers\PlanUsage\Actions\Subscription\SyncPlanWithBillableAction;
use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanPrice;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->action = new SyncPlanWithBillableAction;
});

it('syncs plan from PlanPrice object', function () {
    $plan = Plan::factory()->create();
    $planPrice = PlanPrice::factory()->create(['plan_id' => $plan->id]);

    $billable = createBillable();
    $billable->plan_id = null;
    $billable->plan_price_id = null;

    Log::shouldReceive('info')->twice(); // Once for sync, once for quotas

    $result = $this->action->execute($billable, $planPrice);

    expect($result)->toBeTrue();
    expect($billable->plan_id)->toBe($plan->id);
    expect($billable->plan_price_id)->toBe($planPrice->id);
    expect($billable->plan_changed_at)->not->toBeNull();
});

it('syncs plan from Stripe price ID string', function () {
    $plan = Plan::factory()->create();
    $planPrice = PlanPrice::factory()->create([
        'plan_id' => $plan->id,
        'stripe_price_id' => 'price_test123',
    ]);

    $billable = createBillable();

    Log::shouldReceive('info')->twice();

    $result = $this->action->execute($billable, 'price_test123');

    expect($result)->toBeTrue();
    expect($billable->plan_id)->toBe($plan->id);
    expect($billable->plan_price_id)->toBe($planPrice->id);
});

it('returns false when plan not found', function () {
    $billable = createBillable();

    Log::shouldReceive('warning')->once()->withArgs(function ($message, $context) {
        return $message === 'No matching plan or plan price found for subscription sync' &&
               $context['identifier'] === 'price_nonexistent';
    });

    $result = $this->action->execute($billable, 'price_nonexistent');

    expect($result)->toBeFalse();
    expect($billable->plan_id)->toBeNull();
});

it('syncs quotas using billable method when available', function () {
    $plan = Plan::factory()->create();
    $planPrice = PlanPrice::factory()->create(['plan_id' => $plan->id]);

    $billable = Mockery::mock(Billable::class);
    $billable->shouldReceive('getKey')->andReturn(1);
    $billable->plan_id = null;
    $billable->plan_price_id = null;
    $billable->shouldReceive('save')->once();
    $billable->shouldReceive('syncQuotasWithPlan')->once();

    Log::shouldReceive('info')->twice();

    $result = $this->action->execute($billable, $planPrice);

    expect($result)->toBeTrue();
});

it('handles quota sync failure gracefully', function () {
    $plan = Plan::factory()->create();
    $planPrice = PlanPrice::factory()->create(['plan_id' => $plan->id]);

    $billable = Mockery::mock(Billable::class);
    $billable->shouldReceive('getKey')->andReturn(1);
    $billable->plan_id = null;
    $billable->plan_price_id = null;
    $billable->shouldReceive('save')->once();
    $billable->shouldReceive('syncQuotasWithPlan')->once()->andThrow(new Exception('Quota sync failed'));

    Log::shouldReceive('info')->once();
    Log::shouldReceive('error')->once()->withArgs(function ($message, $context) {
        return str_contains($message, 'Failed to sync quotas') &&
               $context['exception'] instanceof Exception;
    });

    $result = $this->action->execute($billable, $planPrice);

    expect($result)->toBeTrue(); // Still returns true as plan was synced
});

it('syncs multiple billables', function () {
    $plan = Plan::factory()->create();
    $planPrice = PlanPrice::factory()->create(['plan_id' => $plan->id]);

    $billable1 = createBillable(['id' => 1]);
    $billable2 = createBillable(['id' => 2]);
    $billable3 = createBillable(['id' => 3]);

    Log::shouldReceive('info')->times(6); // 2 per billable

    $results = $this->action->executeMany([$billable1, $billable2, $billable3], $planPrice);

    expect($results)->toHaveCount(3);
    expect($results[1]['success'])->toBeTrue();
    expect($results[2]['success'])->toBeTrue();
    expect($results[3]['success'])->toBeTrue();
    expect($results[1]['billable'])->toBe($billable1);
});

it('syncs by plan slug', function () {
    $plan = Plan::factory()->create(['slug' => 'premium-plan']);
    $planPrice = PlanPrice::factory()->create([
        'plan_id' => $plan->id,
        'is_default' => true,
    ]);

    $billable = createBillable();

    Log::shouldReceive('info')->twice();

    $result = $this->action->executeBySlug($billable, 'premium-plan');

    expect($result)->toBeTrue();
    expect($billable->plan_id)->toBe($plan->id);
});

it('returns false when plan slug not found', function () {
    $billable = createBillable();

    Log::shouldReceive('warning')->once()->withArgs(function ($message, $context) {
        return $message === 'Plan not found by slug for subscription sync' &&
               $context['plan_slug'] === 'nonexistent-plan';
    });

    $result = $this->action->executeBySlug($billable, 'nonexistent-plan');

    expect($result)->toBeFalse();
});

it('syncs with specific price', function () {
    $plan = Plan::factory()->create();
    $planPrice1 = PlanPrice::factory()->create(['plan_id' => $plan->id]);
    $planPrice2 = PlanPrice::factory()->create(['plan_id' => $plan->id]);

    $billable = createBillable();

    Log::shouldReceive('info')->twice();

    $result = $this->action->executeWithSpecificPrice($billable, $plan, $planPrice2);

    expect($result)->toBeTrue();
    expect($billable->plan_price_id)->toBe($planPrice2->id);
});

it('rejects mismatched plan and price', function () {
    $plan1 = Plan::factory()->create();
    $plan2 = Plan::factory()->create();
    $planPrice = PlanPrice::factory()->create(['plan_id' => $plan2->id]);

    $billable = createBillable();

    Log::shouldReceive('error')->once()->withArgs(function ($message, $context) {
        return $message === 'Plan price does not belong to the specified plan';
    });

    $result = $this->action->executeWithSpecificPrice($billable, $plan1, $planPrice);

    expect($result)->toBeFalse();
});

it('logs old and new plan information', function () {
    $oldPlan = Plan::factory()->create();
    $oldPrice = PlanPrice::factory()->create(['plan_id' => $oldPlan->id]);
    $newPlan = Plan::factory()->create(['name' => 'Premium Plan']);
    $newPrice = PlanPrice::factory()->create(['plan_id' => $newPlan->id]);

    $billable = createBillable([
        'plan_id' => $oldPlan->id,
        'plan_price_id' => $oldPrice->id,
    ]);

    Log::shouldReceive('info')
        ->once()
        ->withArgs(function ($message, $context) use ($oldPlan, $newPlan, $oldPrice, $newPrice) {
            return $message === "Synced plan {$newPlan->name} with billable" &&
                   $context['old_plan_id'] === $oldPlan->id &&
                   $context['new_plan_id'] === $newPlan->id &&
                   $context['old_plan_price_id'] === $oldPrice->id &&
                   $context['new_plan_price_id'] === $newPrice->id;
        });

    Log::shouldReceive('info')->once(); // For quota sync

    $result = $this->action->execute($billable, $newPrice);

    expect($result)->toBeTrue();
});
