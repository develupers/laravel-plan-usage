<?php

declare(strict_types=1);

use Develupers\PlanUsage\Facades\PlanUsage;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\PlanFeature;

describe('PlanUsage Facade', function () {
    
    beforeEach(function () {
        $this->billable = createBillable();
    });
    
    it('provides access to plan manager', function () {
        // Arrange
        Plan::factory()->count(3)->create();
        
        // Act
        $plans = PlanUsage::getAllPlans();
        
        // Assert
        expect($plans)->toHaveCount(3)
            ->and(PlanUsage::plans())->toBeInstanceOf(\Develupers\PlanUsage\Services\PlanManager::class);
    });
    
    it('provides access to usage tracker', function () {
        // Assert
        expect(PlanUsage::usage())->toBeInstanceOf(\Develupers\PlanUsage\Services\UsageTracker::class);
    });
    
    it('provides access to quota enforcer', function () {
        // Assert
        expect(PlanUsage::quotas())->toBeInstanceOf(\Develupers\PlanUsage\Services\QuotaEnforcer::class);
    });
    
    it('can check feature availability', function () {
        // Arrange
        $plan = Plan::factory()->create();
        $feature = Feature::factory()->create(['slug' => 'test-feature']);
        
        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $feature->id,
            'value' => '100',
        ]);
        
        $this->billable->plan_id = $plan->id;
        
        // Act
        $canUse = PlanUsage::can($this->billable, 'test-feature', 50);
        
        // Assert
        expect($canUse)->toBeTrue();
    });
    
    it('records usage through facade', function () {
        // Arrange
        $plan = Plan::factory()->create();
        $feature = Feature::factory()->create(['slug' => 'api-calls']);
        
        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $feature->id,
            'value' => '1000',
        ]);
        
        $this->billable->plan_id = $plan->id;
        
        // Act
        PlanUsage::record($this->billable, 'api-calls', 100, ['source' => 'test']);
        
        // Assert
        $usage = \Develupers\PlanUsage\Models\Usage::where('feature_id', $feature->id)->first();
        expect($usage)->not->toBeNull()
            ->and($usage->used)->toBe(100.0);
    });
    
    it('finds plans through facade', function () {
        // Arrange
        $plan = Plan::factory()->create(['name' => 'Test Plan']);
        
        // Act
        $foundPlan = PlanUsage::findPlan($plan->id);
        
        // Assert
        expect($foundPlan)->not->toBeNull()
            ->and($foundPlan->name)->toBe('Test Plan');
    });
});

describe('PlanUsage Facade with complex scenarios', function () {
    
    it('handles multiple billables correctly', function () {
        // Arrange
        $billable1 = createBillable(['id' => 1]);
        $billable2 = createBillable(['id' => 2]);
        
        $plan = Plan::factory()->create();
        $feature = Feature::factory()->create(['slug' => 'storage']);
        
        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $feature->id,
            'value' => '100',
        ]);
        
        $billable1->plan_id = $plan->id;
        $billable2->plan_id = $plan->id;
        
        // Act
        PlanUsage::record($billable1, 'storage', 30);
        PlanUsage::record($billable2, 'storage', 50);
        
        // Assert
        $remaining1 = PlanUsage::quotas()->getRemaining($billable1, 'storage');
        $remaining2 = PlanUsage::quotas()->getRemaining($billable2, 'storage');
        
        expect($remaining1)->toBe(70.0)
            ->and($remaining2)->toBe(50.0);
    });
});