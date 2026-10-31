<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subscription> */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'plan_id' => Plan::factory(), 'quantity' => 2, 'unit_price_cents' => 250000, 'tax_basis_points' => 1800, 'billing_interval' => 'monthly', 'status' => 'active', 'starts_on' => today(), 'next_billing_on' => today(), 'payment_terms_days' => 14];
    }
}
