<?php

namespace Database\Factories;

use App\Models\BillingEmail;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingEmail>
 */
class BillingEmailFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_key' => fake()->uuid(),
            'kind' => 'subscription_confirmation',
            'subscription_id' => Subscription::factory(),
        ];
    }
}
