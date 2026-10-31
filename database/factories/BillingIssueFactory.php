<?php

namespace Database\Factories;

use App\Models\BillingIssue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingIssue>
 */
class BillingIssueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'issue_key' => fake()->uuid(), 'kind' => 'billing_overdue', 'subject_type' => 'Subscription',
            'subject_id' => 1, 'title' => 'Subscription billing is overdue', 'detail' => 'A billing period is due.',
            'status' => 'open', 'first_detected_at' => now(), 'last_detected_at' => now(),
        ];
    }
}
