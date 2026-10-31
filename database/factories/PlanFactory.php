<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Plan> */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->words(2, true), 'product_id' => Product::factory(), 'price_cents' => 250000, 'interval' => 'monthly', 'minimum_quantity' => 1];
    }
}
