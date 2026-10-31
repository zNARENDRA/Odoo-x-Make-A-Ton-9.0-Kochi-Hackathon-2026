<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return ['invoice_id' => Invoice::factory(), 'amount_cents' => 100000, 'method' => 'bank_transfer', 'paid_on' => today(), 'note' => null];
    }
}
