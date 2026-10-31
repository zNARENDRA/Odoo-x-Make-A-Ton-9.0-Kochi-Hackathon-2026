<?php

namespace Database\Factories;

use App\Models\Discount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Discount> */
class DiscountFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->words(3, true), 'type' => 'percentage', 'value' => 1000, 'minimum_quantity' => 1, 'minimum_purchase_cents' => 0, 'is_active' => true];
    }
}
