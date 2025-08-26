<?php

declare(strict_types=1);

use Develupers\PlanUsage\Services\PlanManager;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\PlanFeature;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->planManager = new PlanManager();
});

describe('PlanManager', function () {
    
    it('can get all plans', function () {
        // Arrange
        Plan::factory()->count(3)->create();
        
        // Act
        $plans = $this->planManager->getAllPlans();
        
        // Assert
        expect($plans)->toHaveCount(3)
            ->and($plans)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    });
    
    it('caches plans for performance', function () {
        // Arrange
        Plan::factory()->count(2)->create();
        
        // Act
        $plans1 = $this->planManager->getAllPlans();
        $plans2 = $this->planManager->getAllPlans();
        
        // Assert
        expect(Cache::has('plan-usage.plans'))->toBeTrue()
            ->and($plans1->toArray())->toBe($plans2->toArray());
    });
    
    it('can find plan by id', function () {
        // Arrange
        $plan = Plan::factory()->create();
        
        // Act
        $foundPlan = $this->planManager->findPlan($plan->id);
        
        // Assert
        expect($foundPlan)->not->toBeNull()
            ->and($foundPlan->id)->toBe($plan->id);
    });
    
    it('can find plan by stripe price id', function () {
        // Arrange
        $plan = Plan::factory()->create([
            'stripe_price_id' => 'price_test123'
        ]);
        
        // Act
        $foundPlan = $this->planManager->findPlan('price_test123');
        
        // Assert
        expect($foundPlan)->not->toBeNull()
            ->and($foundPlan->id)->toBe($plan->id);
    });
    
    it('returns null for non-existent plan', function () {
        // Act
        $foundPlan = $this->planManager->findPlan(999);
        
        // Assert
        expect($foundPlan)->toBeNull();
    });
    
    it('can get plan features', function () {
        // Arrange
        $plan = Plan::factory()->create();
        $feature = Feature::factory()->create();
        
        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $feature->id,
            'value' => '100'
        ]);
        
        // Act
        $features = $this->planManager->getPlanFeatures($plan->id);
        
        // Assert
        expect($features)->toHaveCount(1)
            ->and($features->first()->feature_id)->toBe($feature->id);
    });
    
    it('can check if plan has feature', function () {
        // Arrange
        $plan = Plan::factory()->create();
        $feature = Feature::factory()->create(['slug' => 'test-feature']);
        
        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $feature->id,
            'value' => '1'
        ]);
        
        // Act & Assert
        expect($this->planManager->planHasFeature($plan->id, 'test-feature'))->toBeTrue()
            ->and($this->planManager->planHasFeature($plan->id, 'non-existent'))->toBeFalse();
    });
    
    it('can get feature value with correct type casting', function () {
        // Arrange
        $plan = Plan::factory()->create();
        
        // Boolean feature
        $booleanFeature = Feature::factory()->create([
            'slug' => 'boolean-feature',
            'type' => 'boolean'
        ]);
        
        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $booleanFeature->id,
            'value' => '1'
        ]);
        
        // Limit feature
        $limitFeature = Feature::factory()->create([
            'slug' => 'limit-feature',
            'type' => 'limit'
        ]);
        
        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $limitFeature->id,
            'value' => '500'
        ]);
        
        // Act
        $booleanValue = $this->planManager->getFeatureValue($plan->id, 'boolean-feature');
        $limitValue = $this->planManager->getFeatureValue($plan->id, 'limit-feature');
        $nullValue = $this->planManager->getFeatureValue($plan->id, 'non-existent');
        
        // Assert
        expect($booleanValue)->toBeTrue()
            ->and($limitValue)->toBe(500.0)
            ->and($nullValue)->toBeNull();
    });
    
    it('can compare two plans', function () {
        // Arrange
        $plan1 = Plan::factory()->create();
        $plan2 = Plan::factory()->create();
        
        $feature = Feature::factory()->create([
            'slug' => 'test-feature',
            'type' => 'limit'
        ]);
        
        PlanFeature::create([
            'plan_id' => $plan1->id,
            'feature_id' => $feature->id,
            'value' => '100'
        ]);
        
        PlanFeature::create([
            'plan_id' => $plan2->id,
            'feature_id' => $feature->id,
            'value' => '200'
        ]);
        
        // Act
        $comparison = $this->planManager->comparePlans($plan1->id, $plan2->id);
        
        // Assert
        expect($comparison)->toHaveKey('test-feature')
            ->and($comparison['test-feature']['difference'])->toBe(100.0);
    });
    
    it('can clear cache for specific plan', function () {
        // Arrange
        $plan = Plan::factory()->create();
        $this->planManager->findPlan($plan->id);
        
        // Act & Assert
        expect(Cache::has("plan-usage.plan.{$plan->id}"))->toBeTrue();
        
        $this->planManager->clearCache($plan->id);
        
        expect(Cache::has("plan-usage.plan.{$plan->id}"))->toBeFalse();
    });
    
    it('can clear all cache', function () {
        // Arrange
        $this->planManager->getAllPlans();
        
        // Act & Assert
        expect(Cache::has('plan-usage.plans'))->toBeTrue();
        
        $this->planManager->clearCache();
        
        expect(Cache::has('plan-usage.plans'))->toBeFalse();
    });
    
    it('can sync billable to plan', function () {
        // Arrange
        $plan = Plan::factory()->create();
        $billable = createBillable();
        
        // Act
        $this->planManager->syncBillableToPlan($billable, $plan->id);
        
        // Assert
        expect($billable->plan_id)->toBe($plan->id)
            ->and($billable->plan_changed_at)->not->toBeNull();
    });
    
    it('throws exception when syncing to invalid plan', function () {
        // Arrange
        $billable = createBillable();
        
        // Act & Assert
        expect(fn() => $this->planManager->syncBillableToPlan($billable, 999))
            ->toThrow(\InvalidArgumentException::class, 'Plan with ID 999 not found');
    });
});

describe('PlanManager with datasets', function () {
    
    it('correctly handles different feature types', function (string $type) {
        // Arrange
        $plan = Plan::factory()->create();
        $feature = Feature::factory()->create([
            'slug' => "test-{$type}",
            'type' => $type
        ]);
        
        $value = match($type) {
            'boolean' => '1',
            'limit', 'quota' => '100',
        };
        
        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $feature->id,
            'value' => $value
        ]);
        
        // Act
        $result = $this->planManager->getFeatureValue($plan->id, "test-{$type}");
        
        // Assert
        if ($type === 'boolean') {
            expect($result)->toBeTrue();
        } else {
            expect($result)->toBe(100.0);
        }
    })->with('feature_types');
    
    it('caches plan data with different intervals', function (string $interval) {
        // Arrange
        $plan = Plan::factory()->create(['interval' => $interval]);
        
        // Act
        $foundPlan = $this->planManager->findPlan($plan->id);
        
        // Assert
        expect(Cache::has("plan-usage.plan.{$plan->id}"))->toBeTrue()
            ->and($foundPlan->interval)->toBe($interval);
    })->with('plan_intervals');
});