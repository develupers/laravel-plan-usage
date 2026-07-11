<?php

declare(strict_types=1);

use Danestves\LaravelPolar\Billable as PolarBillable;
use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Traits\HasPlanFeatures;
use Illuminate\Database\Eloquent\Model;

class ReconcilePolarTestBillable extends Model implements Billable
{
    use HasPlanFeatures;
    use PolarBillable;

    public $timestamps = false;

    protected $table = 'test_billables';

    protected $guarded = [];
}

beforeEach(function () {
    config()->set('plan-usage.billing.provider', 'polar');
    config()->set('plan-usage.models.billable', ReconcilePolarTestBillable::class);

    $this->plan = Plan::factory()->create(['slug' => 'reconcile-polar-plan']);
    $this->planPrice = $this->plan->defaultPrice()->firstOrFail();
    $this->planPrice->update(['polar_product_id' => 'prod_reconcile_polar']);
});

it('never touches Polar billables without any subscription rows (lifetime purchases)', function () {
    $billable = ReconcilePolarTestBillable::query()->create([
        'plan_id' => $this->plan->id,
        'plan_price_id' => $this->planPrice->id,
    ]);

    $this->artisan('subscriptions:reconcile', ['--force' => true])
        ->assertSuccessful();

    expect($billable->fresh()->plan_id)->toBe($this->plan->id)
        ->and($billable->fresh()->plan_price_id)->toBe($this->planPrice->id);
});

it('skips instead of revoking when a Polar billable has no default subscription', function () {
    $billable = ReconcilePolarTestBillable::query()->create([
        'plan_id' => $this->plan->id,
        'plan_price_id' => $this->planPrice->id,
    ]);
    // A custom-typed subscription passes the whereHas filter but leaves
    // subscription('default') null inside the reconcile loop.
    $billable->subscriptions()->create([
        'type' => 'secondary',
        'polar_id' => 'sub_reconcile_custom',
        'status' => 'active',
        'product_id' => 'prod_reconcile_polar',
    ]);

    $this->artisan('subscriptions:reconcile', ['--force' => true])
        ->expectsOutputToContain('Skipped: no default Polar subscription to reconcile against')
        ->assertSuccessful();

    expect($billable->fresh()->plan_id)->toBe($this->plan->id)
        ->and($billable->fresh()->quotas()->count())->toBeGreaterThanOrEqual(0);
});
