<?php

namespace Database\Factories;

use App\Models\Discount;
use App\Models\DiscountRedemption;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DiscountRedemption> */
class DiscountRedemptionFactory extends Factory
{
    public function definition(): array
    {
        return ['discount_id' => Discount::factory(), 'invoice_id' => Invoice::factory(), 'amount_cents' => 1000];
    }
}
