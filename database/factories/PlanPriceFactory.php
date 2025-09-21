<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Database\Factories;

use Develupers\PlanUsage\Enums\Interval;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlanPriceFactory extends Factory
{
    protected $model = PlanPrice::class;

    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'stripe_price_id' => 'price_'.$this->faker->unique()->regexify('[A-Za-z0-9]{24}'),
            'price' => $this->faker->randomFloat(2, 10, 500),
            'currency' => 'usd',
            'interval' => $this->faker->randomElement([Interval::MONTH->value, Interval::YEAR->value]),
            'is_active' => true,
            'is_default' => false,
            'metadata' => [
                'description' => $this->faker->sentence(),
            ],
        ];
    }

    public function monthly(): static
    {
        return $this->state(fn (array $attributes) => [
            'interval' => Interval::MONTH->value,
        ]);
    }

    public function yearly(): static
    {
        return $this->state(fn (array $attributes) => [
            'interval' => Interval::YEAR->value,
        ]);
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}