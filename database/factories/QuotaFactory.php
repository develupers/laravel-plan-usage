<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Database\Factories;

use Develupers\PlanUsage\Models\Quota;
use Develupers\PlanUsage\Models\Feature;
use Illuminate\Database\Eloquent\Factories\Factory;
use Carbon\Carbon;

class QuotaFactory extends Factory
{
    protected $model = Quota::class;

    public function definition(): array
    {
        $limit = $this->faker->boolean(80) ? $this->faker->randomFloat(0, 100, 10000) : null;
        
        return [
            'billable_type' => 'App\\Models\\Account',
            'billable_id' => $this->faker->numberBetween(1, 100),
            'feature_id' => Feature::factory(),
            'limit' => $limit,
            'used' => $limit ? $this->faker->randomFloat(2, 0, $limit * 0.8) : $this->faker->randomFloat(2, 0, 1000),
            'reset_at' => $this->faker->boolean(70) ? Carbon::now()->addDays($this->faker->numberBetween(1, 30)) : null,
            'metadata' => [
                'last_warning_sent' => $this->faker->boolean(20) ? $this->faker->dateTimeThisMonth() : null,
                'grace_used' => $this->faker->boolean(10),
            ],
        ];
    }

    public function forBillable(string $type, int $id): static
    {
        return $this->state(fn (array $attributes) => [
            'billable_type' => $type,
            'billable_id' => $id,
        ]);
    }

    public function forFeature(int $featureId): static
    {
        return $this->state(fn (array $attributes) => [
            'feature_id' => $featureId,
        ]);
    }

    public function unlimited(): static
    {
        return $this->state(fn (array $attributes) => [
            'limit' => null,
        ]);
    }

    public function withLimit(float $limit): static
    {
        return $this->state(fn (array $attributes) => [
            'limit' => $limit,
            'used' => $this->faker->randomFloat(2, 0, $limit * 0.8),
        ]);
    }

    public function nearLimit(): static
    {
        return $this->state(function (array $attributes) {
            $limit = $attributes['limit'] ?? 100;
            return [
                'limit' => $limit,
                'used' => $limit * 0.9,
            ];
        });
    }

    public function exceeded(): static
    {
        return $this->state(function (array $attributes) {
            $limit = $attributes['limit'] ?? 100;
            return [
                'limit' => $limit,
                'used' => $limit * 1.1,
            ];
        });
    }
}