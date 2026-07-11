<?php

declare(strict_types=1);

use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Enums\Period;
use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\Quota;
use Develupers\PlanUsage\Traits\HasPlanFeatures;
use Illuminate\Database\Eloquent\Model;

class QuotaTrueUpTestBillable extends Model implements Billable
{
    use HasPlanFeatures;

    public $timestamps = false;

    protected $table = 'test_billables';

    protected $guarded = [];
}

beforeEach(function () {
    $this->feature = Feature::factory()->quota()->create([
        'slug' => 'true-up-credits',
        'reset_period' => Period::MONTH,
    ]);
    $this->plan = Plan::factory()->create(['slug' => 'true-up-plan']);
    $this->plan->features()->attach($this->feature->id, ['value' => '5000']);
});

it('trues the limit up to the current plan allowance on reset', function () {
    $billable = QuotaTrueUpTestBillable::query()->create(['plan_id' => $this->plan->id]);
    // A mid-cycle upgrade stored a prorated allowance; the next period must
    // grant the full plan value again.
    $quota = Quota::query()->create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'feature_id' => $this->feature->id,
        'limit' => 3000,
        'used' => 120,
        'reset_at' => now()->subDay(),
    ]);

    $quota->reset();

    expect($quota->limit)->toBe(5000.0)
        ->and($quota->used)->toBe(0.0)
        ->and($quota->reset_at?->isFuture())->toBeTrue();
});

it('trues a grandfathered downgrade limit down to the plan allowance on reset', function () {
    $smallerPlan = Plan::factory()->create(['slug' => 'true-up-smaller']);
    $smallerPlan->features()->attach($this->feature->id, ['value' => '1000']);
    $billable = QuotaTrueUpTestBillable::query()->create(['plan_id' => $smallerPlan->id]);
    // An immediate downgrade keeps the higher limit until the period ends.
    $quota = Quota::query()->create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'feature_id' => $this->feature->id,
        'limit' => 5000,
        'used' => 900,
        'reset_at' => now()->subDay(),
    ]);

    $quota->reset();

    expect($quota->limit)->toBe(1000.0)
        ->and($quota->used)->toBe(0.0);
});

it('trues the limit up during lazy reset on the consumption path', function () {
    // The most common reset in production happens inside enforce(), not the
    // scheduled job — it must also true the prorated limit up to the plan.
    $billable = QuotaTrueUpTestBillable::query()->create(['plan_id' => $this->plan->id]);
    Quota::query()->create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'feature_id' => $this->feature->id,
        'limit' => 3000,
        'used' => 2999,
        'reset_at' => now()->subDay(),
    ]);

    $allowed = app('plan-usage.quota')->enforce($billable, 'true-up-credits', 5);
    $quota = $billable->quotas()->where('feature_id', $this->feature->id)->firstOrFail();

    expect($allowed)->toBeTrue()
        ->and($quota->limit)->toBe(5000.0)
        ->and($quota->used)->toBe(5.0)
        ->and($quota->reset_at?->isFuture())->toBeTrue();
});

it('keeps the limit unchanged on reset when the billable has no plan', function () {
    $billable = QuotaTrueUpTestBillable::query()->create(['plan_id' => null]);
    $quota = Quota::query()->create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'feature_id' => $this->feature->id,
        'limit' => 3000,
        'used' => 50,
        'reset_at' => now()->subDay(),
    ]);

    $quota->reset();

    expect($quota->limit)->toBe(3000.0)
        ->and($quota->used)->toBe(0.0);
});
