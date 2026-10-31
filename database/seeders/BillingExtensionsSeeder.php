<?php

namespace Database\Seeders;

use App\Models\Discount;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\QuotationTemplate;
use Illuminate\Database\Seeder;

class BillingExtensionsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }
        $plan = Plan::with('product')->whereNotNull('product_id')->first();
        if (! $plan) {
            return;
        }
        $variant = ProductVariant::firstOrCreate(['product_id' => $plan->product_id, 'name' => 'Odoo brand'], ['price_modifier_cents' => 56000]);
        $support = Product::firstOrCreate(['name' => 'Priority support'], ['type' => 'addon', 'sales_price_cents' => 100000, 'cost_price_cents' => 25000, 'tax_basis_points' => 1800]);
        Discount::firstOrCreate(['name' => 'Volume offer · 10%'], ['type' => 'percentage', 'value' => 1000, 'minimum_quantity' => 5, 'minimum_purchase_cents' => 1000000, 'is_active' => false]);
        $template = QuotationTemplate::firstOrCreate(['name' => 'Workspace + support quotation'], ['plan_id' => $plan->id, 'product_variant_id' => $variant->id, 'quantity' => max(3, $plan->minimum_quantity), 'validity_days' => 30, 'payment_terms_days' => 30]);
        $template->lines()->firstOrCreate(['position' => 1], ['product_id' => $support->id, 'quantity' => 1]);
    }
}
