<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Database\Factories;

use Develupers\PlanUsage\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement(['Starter', 'Professional', 'Enterprise']) . ' Plan',
            'description' => $this->faker->sentence(),
            'stripe_product_id' => 'prod_' . $this->faker->unique()->alphaNumeric(14),
            'stripe_price_id' => 'price_' . $this->faker->unique()->alphaNumeric(24),
            'price' => $this->faker->randomFloat(2, 10, 500),
            'currency' => 'usd',
            'interval' => $this->faker->randomElement(['monthly', 'yearly']),
            'is_active' => true,
            'metadata' => [
                'features' => $this->faker->words(3),
                'popular' => $this->faker->boolean(),
            ],
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function monthly(): static
    {
        return $this->state(fn (array $attributes) => [
            'interval' => 'monthly',
        ]);
    }

    public function yearly(): static
    {
        return $this->state(fn (array $attributes) => [
            'interval' => 'yearly',
        ]);
    }
}