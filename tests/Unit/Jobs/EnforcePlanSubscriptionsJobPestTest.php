<?php

declare(strict_types=1);

use Develupers\PlanUsage\Events\PlanRevoked;
use Develupers\PlanUsage\Jobs\EnforcePlanSubscriptionsJob;
use Develupers\PlanUsage\Models\Plan;
use Illuminate\Support\Facades\Event;

describe('EnforcePlanSubscriptionsJob', function () {

    it('does nothing when billable model is not configured', function () {
        Event::fake();

        config(['plan-usage.models.billable' => null]);
        config(['cashier.model' => null]);

        (new EnforcePlanSubscriptionsJob)->handle();

        Event::assertNotDispatched(PlanRevoked::class);
    });

    it('identifies lifetime plans as exempt', function () {
        $lifetimePlan = Plan::factory()->create([
            'slug' => 'growth-lifetime-test',
            'is_lifetime' => true,
        ]);

        $regularPlan = Plan::factory()->create([
            'slug' => 'basic-test',
            'is_lifetime' => false,
        ]);

        expect($lifetimePlan->isLifetime())->toBeTrue()
            ->and($regularPlan->isLifetime())->toBeFalse();

        // Lifetime plans should be excluded from enforcement queries
        $lifetimeIds = Plan::where('is_lifetime', true)->pluck('id');
        expect($lifetimeIds)->toContain($lifetimePlan->id)
            ->and($lifetimeIds)->not->toContain($regularPlan->id);
    });

    it('creates PlanRevoked event with correct data', function () {
        $plan = Plan::factory()->create(['slug' => 'revoke-test']);
        $billable = createBillable();

        $event = new PlanRevoked($billable, $plan, 'no_active_subscription');

        expect($event->billable)->toBe($billable)
            ->and($event->previousPlan->id)->toBe($plan->id)
            ->and($event->reason)->toBe('no_active_subscription');
    });

    it('creates PlanRevoked event with custom reason', function () {
        $plan = Plan::factory()->create(['slug' => 'custom-reason-test']);
        $billable = createBillable();

        $event = new PlanRevoked($billable, $plan, 'payment_failed');

        expect($event->reason)->toBe('payment_failed');
    });

    it('scopes queries to exclude lifetime plans', function () {
        Plan::factory()->create(['slug' => 'public-test', 'is_lifetime' => false]);
        Plan::factory()->create(['slug' => 'lifetime-test', 'is_lifetime' => true]);

        $requiresSubscription = Plan::requiresSubscription()->pluck('slug');
        $lifetime = Plan::lifetime()->pluck('slug');

        expect($requiresSubscription)->toContain('public-test')
            ->and($requiresSubscription)->not->toContain('lifetime-test')
            ->and($lifetime)->toContain('lifetime-test')
            ->and($lifetime)->not->toContain('public-test');
    });
});
