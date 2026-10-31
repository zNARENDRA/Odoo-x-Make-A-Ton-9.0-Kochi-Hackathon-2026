<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\QuotationTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QuotationTemplate> */
class QuotationTemplateFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->words(3, true), 'plan_id' => Plan::factory(), 'quantity' => 1, 'validity_days' => 30, 'payment_terms_days' => 14, 'is_active' => true];
    }
}
