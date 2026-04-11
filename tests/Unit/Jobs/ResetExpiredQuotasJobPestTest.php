<?php

declare(strict_types=1);

use Develupers\PlanUsage\Enums\Period;
use Develupers\PlanUsage\Jobs\ResetExpiredQuotasJob;
use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Quota;

describe('ResetExpiredQuotasJob', function () {

    it('resets quotas past their reset date', function () {
        $billable = createBillable();
        $feature = Feature::factory()->create([
            'slug' => 'monthly-credits',
            'type' => 'quota',
            'reset_period' => Period::MONTH->value,
        ]);

        $quota = Quota::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'feature_id' => $feature->id,
            'limit' => 1000,
            'used' => 500,
            'reset_at' => now()->subDay(),
        ]);

        (new ResetExpiredQuotasJob)->handle();

        $quota->refresh();
        expect($quota->used)->toBe(0.0)
            ->and($quota->reset_at->isFuture())->toBeTrue();
    });

    it('does not reset quotas with future reset dates', function () {
        $billable = createBillable();
        $feature = Feature::factory()->create([
            'slug' => 'future-credits',
            'type' => 'quota',
            'reset_period' => Period::MONTH->value,
        ]);

        $quota = Quota::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'feature_id' => $feature->id,
            'limit' => 1000,
            'used' => 500,
            'reset_at' => now()->addWeek(),
        ]);

        (new ResetExpiredQuotasJob)->handle();

        $quota->refresh();
        expect($quota->used)->toBe(500.0);
    });

    it('does not reset quotas with zero usage', function () {
        $billable = createBillable();
        $feature = Feature::factory()->create([
            'slug' => 'zero-credits',
            'type' => 'quota',
            'reset_period' => Period::MONTH->value,
        ]);

        $quota = Quota::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'feature_id' => $feature->id,
            'limit' => 1000,
            'used' => 0,
            'reset_at' => now()->subDay(),
        ]);

        (new ResetExpiredQuotasJob)->handle();

        $quota->refresh();
        expect($quota->used)->toBe(0.0);
    });

    it('does not reset quotas without a reset date', function () {
        $billable = createBillable();
        $feature = Feature::factory()->create([
            'slug' => 'no-reset',
            'type' => 'limit',
            'reset_period' => null,
        ]);

        $quota = Quota::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'feature_id' => $feature->id,
            'limit' => 10,
            'used' => 5,
            'reset_at' => null,
        ]);

        (new ResetExpiredQuotasJob)->handle();

        $quota->refresh();
        expect($quota->used)->toBe(5.0);
    });

    it('resets multiple expired quotas across billables', function () {
        $feature = Feature::factory()->create([
            'slug' => 'multi-credits',
            'type' => 'quota',
            'reset_period' => Period::MONTH->value,
        ]);

        $quotas = collect();
        for ($i = 0; $i < 3; $i++) {
            $billable = createBillable();
            $quotas->push(Quota::create([
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => $billable->getKey(),
                'feature_id' => $feature->id,
                'limit' => 1000,
                'used' => 100 * ($i + 1),
                'reset_at' => now()->subHour(),
            ]));
        }

        (new ResetExpiredQuotasJob)->handle();

        foreach ($quotas as $quota) {
            $quota->refresh();
            expect($quota->used)->toBe(0.0);
        }
    });
});
