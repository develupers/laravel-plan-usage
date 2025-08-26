<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Database\Factories;

use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanFeature;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlanFeatureFactory extends Factory
{
    protected $model = PlanFeature::class;

    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'feature_id' => Feature::factory(),
            'value' => $this->faker->randomElement(['1', '100', '1000', 'unlimited', null]),
            'unit' => null, // Usually inherits from feature
            'metadata' => [
                'custom_label' => $this->faker->boolean(20) ? $this->faker->words(3, true) : null,
                'tooltip' => $this->faker->boolean(30) ? $this->faker->sentence() : null,
            ],
        ];
    }

    public function forPlan(int $planId): static
    {
        return $this->state(fn (array $attributes) => [
            'plan_id' => $planId,
        ]);
    }

    public function forFeature(int $featureId): static
    {
        return $this->state(fn (array $attributes) => [
            'feature_id' => $featureId,
        ]);
    }

    public function withValue($value): static
    {
        return $this->state(fn (array $attributes) => [
            'value' => $value,
        ]);
    }

    public function unlimited(): static
    {
        return $this->state(fn (array $attributes) => [
            'value' => null,
        ]);
    }

    public function boolean(bool $enabled = true): static
    {
        return $this->state(fn (array $attributes) => [
            'value' => $enabled ? '1' : '0',
        ]);
    }
}
