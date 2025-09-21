<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Database\Factories;

use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanPrice;
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
            'is_active' => true,
            'type' => 'public',
            'metadata' => [
                'features' => $this->faker->words(3),
                'popular' => $this->faker->boolean(),
            ],
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Plan $plan) {
            if (! $plan->prices()->exists()) {
                PlanPrice::factory()->default()->monthly()->create([
                    'plan_id' => $plan->id,
                ]);
            }
        });
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
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
