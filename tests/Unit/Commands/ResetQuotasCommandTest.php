<?php

declare(strict_types=1);

use Develupers\PlanUsage\Contracts\Billable as BillableContract;
use Develupers\PlanUsage\Enums\Period;
use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\Quota;
use Develupers\PlanUsage\Traits\HasPlanFeatures;
use Illuminate\Database\Eloquent\Model;

/**
 * Real attributes-backed billable: Quota::reset() resolves the plan through
 * the persisted plan_id column.
 */
class ResetQuotasCommandTestBillable extends Model implements BillableContract
{
    use HasPlanFeatures;

    public $timestamps = false;

    protected $table = 'test_billables';

    protected $guarded = [];
}

it('resets zero-usage expired quotas so stale limits are trued up', function () {
    // A quota can be untouched (used = 0) and still carry a prorated or
    // grandfathered limit that renewal must true up to the plan allowance —
    // filtering on used > 0 skipped these entirely.
    $feature = Feature::factory()->create([
        'slug' => 'zero-usage-credits',
        'type' => 'quota',
        'reset_period' => Period::MONTH->value,
    ]);
    $plan = Plan::factory()->create();
    $plan->features()->attach($feature->id, ['value' => '1000']);
    $billable = ResetQuotasCommandTestBillable::query()->create(['plan_id' => $plan->id]);

    $quota = Quota::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'feature_id' => $feature->id,
        'limit' => 5000,
        'used' => 0,
        'reset_at' => now()->subDay(),
    ]);

    $this->artisan('plan-usage:reset-quotas')
        ->expectsOutputToContain('Found 1 expired quota(s). Resetting...')
        ->assertSuccessful();

    $quota->refresh();
    expect($quota->limit)->toBe(1000.0)
        ->and($quota->used)->toBe(0.0)
        ->and($quota->reset_at->isFuture())->toBeTrue();
});
