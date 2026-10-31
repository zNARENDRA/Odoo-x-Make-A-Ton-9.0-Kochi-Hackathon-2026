<?php

namespace App\Actions;

use App\Models\Invoice;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class GenerateRenewalInvoices
{
    public function handle(Subscription $subscription, ?CarbonImmutable $through = null): int
    {
        $through ??= CarbonImmutable::today();

        return DB::transaction(function () use ($subscription, $through): int {
            $subscription = Subscription::with(['user', 'plan.product'])->lockForUpdate()->findOrFail($subscription->id);
            if ($subscription->status === 'paused' && $subscription->expires_on?->lte($through) && $subscription->plan->auto_close) {
                $subscription->update(['status' => 'closed']);
            }
            if ($subscription->status !== 'active') {
                return 0;
            }
            $created = 0;
            for ($period = 0; $period < 100 && $subscription->next_billing_on->lte($through); $period++) {
                if ($subscription->expires_on && $subscription->next_billing_on->gte($subscription->expires_on)) {
                    break;
                }
                $subtotal = (int) $subscription->unit_price_cents * (int) $subscription->quantity;
                $tax = intdiv($subtotal * (int) $subscription->tax_basis_points + 5000, 10000);
                $invoice = Invoice::firstOrCreate([
                    'subscription_id' => $subscription->id,
                    'period_start' => $subscription->next_billing_on->toDateString(),
                ], [
                    'due_on' => $subscription->next_billing_on->addDays($subscription->payment_terms_days),
                    'subtotal_cents' => $subtotal, 'tax_cents' => $tax, 'total_cents' => $subtotal + $tax,
                    'status' => 'draft', 'currency' => config('billing.currency'),
                    'snapshot' => [
                        'customer' => $subscription->user->name, 'email' => $subscription->user->email,
                        'plan' => $subscription->plan->name,
                        'product' => $subscription->plan->product?->name ?? $subscription->plan->name,
                        'quantity' => $subscription->quantity, 'unit_price_cents' => $subscription->unit_price_cents,
                        'tax_basis_points' => $subscription->tax_basis_points,
                    ],
                ]);
                $created += (int) $invoice->wasRecentlyCreated;
                $subscription->next_billing_on = $subscription->followingBillingDate();
                $subscription->save();
            }
            if ($subscription->expires_on && $subscription->expires_on->lte($through)
                && $subscription->next_billing_on->gte($subscription->expires_on) && $subscription->plan->auto_close) {
                $subscription->update(['status' => 'closed']);
            }

            return $created;
        }, 3);
    }
}
