<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InvoiceLine> */
class InvoiceLineFactory extends Factory
{
    public function definition(): array
    {
        return ['invoice_id' => Invoice::factory(), 'description' => 'Additional service', 'quantity' => 1, 'unit_price_cents' => 10000, 'tax_basis_points' => 1800, 'subtotal_cents' => 10000, 'discount_cents' => 0, 'tax_cents' => 1800, 'total_cents' => 11800, 'position' => 0];
    }
}
