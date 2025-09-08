<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Database\Factories;

use Develupers\PlanUsage\Enums\Interval;
use Develupers\PlanUsage\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        $name = $this->faker->randomElement(['Starter', 'Professional', 'Enterprise']).' Plan';

        return [
            'name' => $name,
            'slug' => $this->faker->unique()->slug(2),
            'description' => $this->faker->sentence(),
            'stripe_product_id' => 'prod_'.$this->faker->unique()->regexify('[A-Za-z0-9]{14}'),
            'stripe_price_id' => 'price_'.$this->faker->unique()->regexify('[A-Za-z0-9]{24}'),
            'price' => $this->faker->randomFloat(2, 10, 500),
            'currency' => 'usd',
            'interval' => $this->faker->randomElement([Interval::MONTHLY->value, Interval::YEARLY->value]),
            'is_active' => true,
            'type' => 'public',
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
            'interval' => Interval::MONTHLY->value,
        ]);
    }

    public function yearly(): static
    {
        return $this->state(fn (array $attributes) => [
            'interval' => Interval::YEARLY->value,
        ]);
    }

    public function legacy(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'legacy',
        ]);
    }

    public function private(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'private',
        ]);
    }
}
