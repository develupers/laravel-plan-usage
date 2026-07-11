<?php

declare(strict_types=1);

use Develupers\PlanUsage\Actions\Subscription\SyncPlanWithBillableAction;
use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Enums\Period;
use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanPrice;
use Develupers\PlanUsage\Models\Quota;
use Develupers\PlanUsage\Traits\HasPlanFeatures;
use Illuminate\Database\Eloquent\Model;
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

    Log::shouldReceive('info')->once();

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

    Log::shouldReceive('info')->once();

    $result = $this->action->execute($billable, 'price_test123');

    expect($result)->toBeTrue();
    expect($billable->plan_id)->toBe($plan->id);
    expect($billable->plan_price_id)->toBe($planPrice->id);
});

it('skips the re-sync on a real Eloquent billable and preserves prorated quotas', function () {
    // Real Eloquent model: plan ids live in the attributes array, NOT declared
    // properties — property_exists() alone cannot see them.
    $billableClass = new class extends Model implements Billable
    {
        use HasPlanFeatures;

        public $timestamps = false;

        protected $table = 'test_billables';

        protected $guarded = [];
    };

    $feature = Feature::factory()->quota()->create([
        'slug' => 'sync-guard-credits',
        'reset_period' => Period::MONTH,
    ]);
    $plan = Plan::factory()->create();
    $plan->features()->attach($feature->id, ['value' => '5000']);
    $planPrice = PlanPrice::factory()->create(['plan_id' => $plan->id]);

    $billable = $billableClass::query()->create([
        'plan_id' => $plan->id,
        'plan_price_id' => $planPrice->id,
    ]);
    // A mid-cycle upgrade left a prorated limit that a webhook echo must not overwrite.
    $quota = Quota::query()->create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'feature_id' => $feature->id,
        'limit' => 3000,
        'used' => 150,
        'reset_at' => now()->addDays(10),
    ]);

    expect($this->action->execute($billable, $planPrice))->toBeTrue()
        ->and($quota->fresh()->limit)->toBe(3000.0)
        ->and($quota->fresh()->used)->toBe(150.0);
});

it('syncs quotas from the new plan even when the old plan relation was already loaded', function () {
    $billableClass = new class extends Model implements Billable
    {
        use HasPlanFeatures;

        public $timestamps = false;

        protected $table = 'test_billables';

        protected $guarded = [];
    };

    $feature = Feature::factory()->quota()->create([
        'slug' => 'stale-relation-credits',
        'reset_period' => Period::MONTH,
    ]);
    $oldPlan = Plan::factory()->create();
    $oldPlan->features()->attach($feature->id, ['value' => '1000']);
    $oldPrice = PlanPrice::factory()->create(['plan_id' => $oldPlan->id]);
    $newPlan = Plan::factory()->create();
    $newPlan->features()->attach($feature->id, ['value' => '5000']);
    $newPrice = PlanPrice::factory()->create(['plan_id' => $newPlan->id]);

    $billable = $billableClass::query()->create([
        'plan_id' => $oldPlan->id,
        'plan_price_id' => $oldPrice->id,
    ]);

    // App code commonly reads ->plan earlier in the request; Eloquent caches
    // the relation, and it must not survive the plan-id change into quota sync.
    expect($billable->plan->id)->toBe($oldPlan->id);

    Log::shouldReceive('info')->once();

    expect($this->action->execute($billable, $newPrice))->toBeTrue();

    $quota = Quota::query()
        ->where('billable_type', $billable->getMorphClass())
        ->where('billable_id', $billable->getKey())
        ->where('feature_id', $feature->id)
        ->first();

    expect($quota)->not->toBeNull()
        ->and($quota->limit)->toBe(5000.0)
        ->and($billable->plan->id)->toBe($newPlan->id);
});

it('skips the re-sync when the billable is already on the target plan price', function () {
    $plan = Plan::factory()->create();
    $planPrice = PlanPrice::factory()->create(['plan_id' => $plan->id]);

    // Routine subscription.updated webhooks (renewals, the echo of an applied
    // swap) must not re-run syncQuotasWithPlan and overwrite prorated limits.
    $billable = Mockery::mock(Billable::class);
    $billable->shouldReceive('getKey')->andReturn(1);
    $billable->plan_id = $plan->id;
    $billable->plan_price_id = $planPrice->id;
    $billable->shouldNotReceive('save');
    $billable->shouldNotReceive('syncQuotasWithPlan');

    expect($this->action->execute($billable, $planPrice))->toBeTrue();
});

it('returns false when plan not found', function () {
    $billable = createBillable();

    Log::shouldReceive('channel')->andReturnSelf()->byDefault();
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

    Log::shouldReceive('info')->once();

    $result = $this->action->execute($billable, $planPrice);

    expect($result)->toBeTrue();
});

it('rethrows when quota sync fails so the plan assignment rolls back', function () {
    $plan = Plan::factory()->create();
    $planPrice = PlanPrice::factory()->create(['plan_id' => $plan->id]);

    $billable = Mockery::mock(Billable::class);
    $billable->shouldReceive('getKey')->andReturn(1);
    $billable->plan_id = null;
    $billable->plan_price_id = null;
    $billable->shouldReceive('save')->once();
    $billable->shouldReceive('syncQuotasWithPlan')->once()->andThrow(new Exception('Quota sync failed'));

    // Swallowing this would leave plan ids saved with broken quotas, and the
    // same-plan guard would then skip every future webhook repair attempt.
    expect(fn () => $this->action->execute($billable, $planPrice))
        ->toThrow(Exception::class, 'Quota sync failed');
});

it('syncs multiple billables', function () {
    $plan = Plan::factory()->create();
    $planPrice = PlanPrice::factory()->create(['plan_id' => $plan->id]);

    $billable1 = createBillable(['id' => 1]);
    $billable2 = createBillable(['id' => 2]);
    $billable3 = createBillable(['id' => 3]);

    Log::shouldReceive('info')->times(3); // 1 per billable

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

    Log::shouldReceive('info')->once();

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

    Log::shouldReceive('info')->once();

    $result = $this->action->executeWithSpecificPrice($billable, $plan, $planPrice2);

    expect($result)->toBeTrue();
    expect($billable->plan_price_id)->toBe($planPrice2->id);
});

it('rejects mismatched plan and price', function () {
    $plan1 = Plan::factory()->create();
    $plan2 = Plan::factory()->create();
    $planPrice = PlanPrice::factory()->create(['plan_id' => $plan2->id]);

    $billable = createBillable();

    Log::shouldReceive('channel')->andReturnSelf()->byDefault();
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

    $result = $this->action->execute($billable, $newPrice);

    expect($result)->toBeTrue();
});
