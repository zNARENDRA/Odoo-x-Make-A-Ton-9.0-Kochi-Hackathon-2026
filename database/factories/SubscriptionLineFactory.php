<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubscriptionLine> */
class SubscriptionLineFactory extends Factory
{
    public function definition(): array
    {
        return ['subscription_id' => Subscription::factory(), 'product_id' => Product::factory(), 'description' => 'Additional service', 'quantity' => 1, 'unit_price_cents' => 10000, 'tax_basis_points' => 1800, 'position' => 0];
    }
}
