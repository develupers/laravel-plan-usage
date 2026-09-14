<?php

declare(strict_types=1);

use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanFeature;

it('reports whether a quota row was actually incremented', function () {
    $plan = Plan::factory()->create();
    $feature = Feature::factory()->create(['slug' => 'increment-credits', 'type' => 'quota']);
    PlanFeature::create(['plan_id' => $plan->id, 'feature_id' => $feature->id, 'value' => '100']);
    $billable = createBillable(['plan_id' => $plan->id]);
    $billable->setRelation('plan', $plan);

    expect($billable->incrementQuotaUsage('increment-credits'))->toBeTrue()
        ->and($billable->getFeatureUsage('increment-credits')['used'])->toBe(1.0);
});

it('reports false when the billable has no plan granting the feature', function () {
    // Callers that record a charge elsewhere (e.g. a metered session) must
    // not treat a silently skipped increment as billed.
    Feature::factory()->create(['slug' => 'ungranted-increment', 'type' => 'quota']);
    $billable = createBillable(['plan_id' => null]);

    expect($billable->incrementQuotaUsage('ungranted-increment'))->toBeFalse();
});
