<?php

namespace Database\Factories;

use App\Models\Activity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_name' => 'System', 'actor_role' => 'system', 'subject_type' => 'Subscription',
            'subject_id' => 1, 'action' => 'created', 'before_values' => [], 'after_values' => ['status' => 'active'],
        ];
    }
}
