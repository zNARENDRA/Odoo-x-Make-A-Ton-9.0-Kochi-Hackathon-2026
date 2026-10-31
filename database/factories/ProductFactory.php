<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->words(3, true), 'type' => 'software', 'sales_price_cents' => 250000, 'cost_price_cents' => 50000, 'tax_basis_points' => 1800];
    }
}
