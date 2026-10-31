<?php

namespace Database\Seeders;

use App\Actions\GenerateRenewalInvoices;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('Demo records are only available in the local environment.');

            return;
        }
        $admin = User::firstOrCreate(['email' => 'admin@brightloop.test'], ['name' => 'Brightloop Admin', 'password' => Str::password(20)]);
        $admin->role = 'admin';
        if ($admin->wasRecentlyCreated) {
            $password = Str::password(20);
            $admin->password = $password;
            $this->command?->info('Demo admin: admin@brightloop.test');
            $this->command?->info('Temporary password: '.$password);
        }
        $admin->save();
        $product = Product::firstOrCreate(['name' => 'Brightloop Workspace'], ['type' => 'software', 'sales_price_cents' => 250000, 'cost_price_cents' => 50000, 'tax_basis_points' => 1800, 'notes' => 'Collaboration software for growing teams.']);
        $plan = Plan::firstOrCreate(['name' => 'Growth monthly'], ['product_id' => $product->id, 'price_cents' => 250000, 'interval' => 'monthly', 'minimum_quantity' => 1]);
        Plan::firstOrCreate(['name' => 'Growth annual'], ['product_id' => $product->id, 'price_cents' => 2500000, 'interval' => 'yearly', 'minimum_quantity' => 1]);
        foreach (['Northstar Studio', 'Orbit Technologies', 'Cedar & Co', 'Maple Digital'] as $index => $name) {
            $customer = User::firstOrCreate(['email' => 'customer'.($index + 1).'@example.test'], ['name' => $name, 'password' => Str::password(24)]);
            $start = today()->subDays(20 - $index);
            $subscription = Subscription::firstOrCreate(['user_id' => $customer->id, 'plan_id' => $plan->id], ['quantity' => $index + 1, 'unit_price_cents' => $plan->price_cents, 'status' => 'active', 'starts_on' => $start, 'next_billing_on' => $start, 'billing_interval' => 'monthly', 'tax_basis_points' => 1800, 'payment_terms_days' => 14]);
            app(GenerateRenewalInvoices::class)->handle($subscription);
            $invoice = Invoice::where('subscription_id', $subscription->id)->first();
            if ($invoice && $invoice->status === 'draft' && $index < 3) {
                $invoice->update(['status' => 'confirmed']);
            }
            if ($invoice && $index === 0 && ! $invoice->payments()->exists()) {
                $invoice->payments()->create(['amount_cents' => $invoice->total_cents, 'method' => 'bank_transfer', 'paid_on' => today()->subDays(3), 'note' => 'Demo settlement']);
                $invoice->update(['status' => 'paid']);
            }
            if ($invoice && $index === 1 && ! $invoice->payments()->exists()) {
                $invoice->payments()->create(['amount_cents' => 100000, 'method' => 'upi', 'paid_on' => today()->subDays(2), 'note' => 'Demo partial payment']);
            }
        }
    }
}
