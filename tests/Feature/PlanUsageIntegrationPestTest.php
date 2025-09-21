<?php

declare(strict_types=1);

use Develupers\PlanUsage\Enums\Period;
use Develupers\PlanUsage\Events\QuotaExceeded;
use Develupers\PlanUsage\Events\QuotaWarning;
use Develupers\PlanUsage\Events\UsageRecorded;
use Develupers\PlanUsage\Facades\PlanUsage;
use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanFeature;
use Develupers\PlanUsage\Models\PlanPrice;
use Develupers\PlanUsage\Models\Quota;
use Develupers\PlanUsage\Models\Usage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    // Clear cache to ensure test isolation
    Cache::flush();
    $this->billable = createBillable();
});

describe('PlanUsage Integration', function () {

    it('completes full usage workflow', function () {
        // Arrange
        Event::fake();
        $testId = uniqid('test_'); // Unique identifier for this test run

        $plan = Plan::factory()->create([
            'name' => 'Professional Plan',
            'slug' => 'pro-'.$testId,
        ]);

        $planPrice = PlanPrice::factory()
            ->default()
            ->monthly()
            ->create([
                'plan_id' => $plan->id,
                'stripe_price_id' => 'price_'.$testId,
                'price' => 99.99,
            ]);

        $apiCallsFeature = Feature::factory()->create([
            'name' => 'API Calls',
            'slug' => 'api-calls-'.$testId,
            'type' => 'quota',
            'unit' => 'requests',
            'reset_period' => Period::MONTH->value,
        ]);

        $projectsFeature = Feature::factory()->create([
            'name' => 'Projects',
            'slug' => 'projects-'.$testId,
            'type' => 'limit',
            'unit' => 'projects',
        ]);

        $advancedFeature = Feature::factory()->create([
            'name' => 'Advanced Analytics',
            'slug' => 'advanced-analytics-'.$testId,
            'type' => 'boolean',
        ]);

        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $apiCallsFeature->id,
            'value' => '10000',
        ]);

        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $projectsFeature->id,
            'value' => '50',
        ]);

        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $advancedFeature->id,
            'value' => '1',
        ]);

        $this->billable->plan_id = $plan->id;
        $this->billable->plan_price_id = $planPrice->id;

        // Act & Assert
        expect(PlanUsage::findPlan($plan->id)->id)->toBe($plan->id)
            ->and(PlanUsage::plans()->planHasFeature($plan->id, 'api-calls-'.$testId))->toBeTrue()
            ->and(PlanUsage::can($this->billable, 'api-calls-'.$testId, 100))->toBeTrue();

        PlanUsage::record($this->billable, 'api-calls-'.$testId, 100, ['source' => 'api']);
        Event::assertDispatched(UsageRecorded::class);

        $usage = Usage::where('billable_type', $this->billable->getMorphClass())
            ->where('billable_id', $this->billable->getKey())
            ->where('feature_id', $apiCallsFeature->id)
            ->first();

        expect($usage)->not->toBeNull()
            ->and($usage->used)->toBe(100.0);

        $quota = Quota::where('billable_type', $this->billable->getMorphClass())
            ->where('billable_id', $this->billable->getKey())
            ->where('feature_id', $apiCallsFeature->id)
            ->first();

        expect($quota)->not->toBeNull()
            ->and($quota->used)->toBe(100.0)
            ->and(PlanUsage::quotas()->getRemaining($this->billable, 'api-calls-'.$testId))->toBe(9900.0);

        $history = PlanUsage::usage()->getHistory($this->billable, 'api-calls-'.$testId);
        expect($history)->toHaveCount(1);

        $stats = PlanUsage::usage()->getStatistics(
            $this->billable,
            'api-calls-'.$testId,
            now()->subMonth(),
            now(),
            'month'
        );
        expect($stats)->not->toBeEmpty();
    });

    it('triggers quota warning and exceeded events', function () {
        // Arrange
        Event::fake();

        $plan = Plan::factory()->create();
        $feature = Feature::factory()->create([
            'slug' => 'api-calls',
            'type' => 'quota',
        ]);

        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $feature->id,
            'value' => '100',
        ]);

        $this->billable->plan_id = $plan->id;

        // Act & Assert
        PlanUsage::record($this->billable, 'api-calls', 85);
        Event::assertDispatched(QuotaWarning::class);

        $enforced = PlanUsage::quotas()->enforce($this->billable, 'api-calls', 20);
        expect($enforced)->toBeFalse();
        Event::assertDispatched(QuotaExceeded::class);
    });

    it('compares two plans correctly', function () {
        // Arrange
        $starterPlan = Plan::factory()->create(['name' => 'Starter']);
        $proPlan = Plan::factory()->create(['name' => 'Professional']);

        $feature1 = Feature::factory()->create([
            'slug' => 'api-calls',
            'type' => 'limit',
        ]);

        $feature2 = Feature::factory()->create([
            'slug' => 'storage',
            'type' => 'limit',
        ]);

        PlanFeature::create([
            'plan_id' => $starterPlan->id,
            'feature_id' => $feature1->id,
            'value' => '1000',
        ]);

        PlanFeature::create([
            'plan_id' => $proPlan->id,
            'feature_id' => $feature1->id,
            'value' => '10000',
        ]);

        PlanFeature::create([
            'plan_id' => $starterPlan->id,
            'feature_id' => $feature2->id,
            'value' => '10',
        ]);

        PlanFeature::create([
            'plan_id' => $proPlan->id,
            'feature_id' => $feature2->id,
            'value' => '100',
        ]);

        // Act
        $comparison = PlanUsage::plans()->comparePlans($starterPlan->id, $proPlan->id);

        // Assert
        expect($comparison)
            ->toHaveKey('api-calls')
            ->toHaveKey('storage')
            ->and($comparison['api-calls']['difference'])->toBe(9000.0)
            ->and($comparison['storage']['difference'])->toBe(90.0);
    });

    it('resets quotas properly', function () {
        // Arrange
        $plan = Plan::factory()->create();
        $feature = Feature::factory()->create([
            'slug' => 'monthly-reports',
            'type' => 'quota',
            'reset_period' => Period::MONTH->value,
        ]);

        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $feature->id,
            'value' => '10',
        ]);

        $this->billable->plan_id = $plan->id;

        // Act & Assert
        PlanUsage::record($this->billable, 'monthly-reports', 5);

        $quota = PlanUsage::quotas()->getQuota($this->billable, 'monthly-reports');
        expect($quota->used)->toBe(5.0);

        PlanUsage::quotas()->reset($this->billable, 'monthly-reports');

        $quota->refresh();
        expect($quota->used)->toBe(0.0);
    });

    it('handles unlimited features correctly', function () {
        // Arrange
        $plan = Plan::factory()->create();
        $feature = Feature::factory()->create([
            'slug' => 'unlimited-feature',
            'type' => 'quota',
        ]);

        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $feature->id,
            'value' => null,
        ]);

        $this->billable->plan_id = $plan->id;

        // Act & Assert
        expect(PlanUsage::can($this->billable, 'unlimited-feature', 999999))->toBeTrue();

        PlanUsage::record($this->billable, 'unlimited-feature', 999999);

        expect(PlanUsage::can($this->billable, 'unlimited-feature', 999999))->toBeTrue()
            ->and(PlanUsage::quotas()->getRemaining($this->billable, 'unlimited-feature'))->toBeNull();
    });

    it('syncs quotas when plan changes', function () {
        // Arrange
        $plan1 = Plan::factory()->create();
        $feature = Feature::factory()->create(['slug' => 'projects']);

        PlanFeature::create([
            'plan_id' => $plan1->id,
            'feature_id' => $feature->id,
            'value' => '10',
        ]);

        $this->billable->plan_id = $plan1->id;
        PlanUsage::quotas()->getOrCreateQuota($this->billable, 'projects');

        $plan2 = Plan::factory()->create();
        PlanFeature::create([
            'plan_id' => $plan2->id,
            'feature_id' => $feature->id,
            'value' => '50',
        ]);

        // Act
        PlanUsage::plans()->syncBillableToPlan($this->billable, $plan2->id);
        PlanUsage::quotas()->syncWithPlan($this->billable);

        // Assert
        $quota = PlanUsage::quotas()->getQuota($this->billable, 'projects');
        expect($quota->limit)->toBe(50.0);
    });
});

describe('PlanUsage with multiple features', function () {

    it('handles mixed feature types in one plan', function () {
        // Arrange
        $plan = Plan::factory()->create();

        $booleanFeature = Feature::factory()->boolean()->create(['slug' => 'premium-support']);
        $limitFeature = Feature::factory()->limit()->create(['slug' => 'max-users']);
        $quotaFeature = Feature::factory()->quota()->create(['slug' => 'api-requests']);

        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $booleanFeature->id,
            'value' => '1',
        ]);

        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $limitFeature->id,
            'value' => '100',
        ]);

        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $quotaFeature->id,
            'value' => '5000',
        ]);

        $this->billable->plan_id = $plan->id;

        // Act & Assert
        expect(PlanUsage::plans()->getFeatureValue($plan->id, 'premium-support'))->toBeTrue()
            ->and(PlanUsage::plans()->getFeatureValue($plan->id, 'max-users'))->toBe(100.0)
            ->and(PlanUsage::plans()->getFeatureValue($plan->id, 'api-requests'))->toBe(5000.0);
    });

    it('tracks usage across multiple features simultaneously', function () {
        // Arrange
        $testId = uniqid('test_'); // Unique identifier for this test run
        $plan = Plan::factory()->create(['slug' => 'plan-'.$testId]);

        $apiFeature = Feature::factory()->quota()->create(['slug' => 'api-calls-'.$testId]);
        $storageFeature = Feature::factory()->quota()->create(['slug' => 'storage-gb-'.$testId]);

        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $apiFeature->id,
            'value' => '1000',
        ]);

        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $storageFeature->id,
            'value' => '50',
        ]);

        $this->billable->plan_id = $plan->id;
        $this->billable->save();

        // Clear any cached plan data to ensure fresh data
        app('plan-usage.manager')->clearCache($plan->id);

        // Act
        PlanUsage::record($this->billable, 'api-calls-'.$testId, 100);
        PlanUsage::record($this->billable, 'storage-gb-'.$testId, 5);

        // Assert
        expect(PlanUsage::quotas()->getRemaining($this->billable, 'api-calls-'.$testId))->toBe(900.0)
            ->and(PlanUsage::quotas()->getRemaining($this->billable, 'storage-gb-'.$testId))->toBe(45.0)
            ->and(PlanUsage::quotas()->getUsagePercentage($this->billable, 'api-calls-'.$testId))->toBe(10.0)
            ->and(PlanUsage::quotas()->getUsagePercentage($this->billable, 'storage-gb-'.$testId))->toBe(10.0);
    });
});
