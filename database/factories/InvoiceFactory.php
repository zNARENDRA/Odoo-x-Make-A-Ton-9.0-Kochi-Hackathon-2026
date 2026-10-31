<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Invoice> */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        return ['subscription_id' => Subscription::factory(), 'period_start' => today(), 'due_on' => today()->addDays(14), 'subtotal_cents' => 500000, 'tax_cents' => 90000, 'total_cents' => 590000, 'currency' => 'INR', 'status' => 'confirmed', 'snapshot' => ['customer' => 'Acme Team', 'email' => 'acme@example.test', 'product' => 'Workspace', 'plan' => 'Growth monthly', 'quantity' => 2, 'unit_price_cents' => 250000, 'tax_basis_points' => 1800]];
    }
}
