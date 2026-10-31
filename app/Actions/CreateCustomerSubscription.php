<?php

namespace App\Actions;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateCustomerSubscription
{
    public function __construct(private GenerateRenewalInvoices $generateInvoices, private PreviewSubscriptionPrice $preview) {}

    public function handle(User $customer, int $planId, int $quantity, ?int $variantId = null, ?string $quoteFingerprint = null): Subscription
    {
        abort_unless($customer->role === 'customer', 403);

        return DB::transaction(function () use ($customer, $planId, $quantity, $variantId, $quoteFingerprint): Subscription {
            User::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $plan = Plan::available()->with('product')->lockForUpdate()->find($planId);
            if (! $plan) {
                throw ValidationException::withMessages(['planId' => 'This plan is no longer available. Please choose another plan.']);
            }
            if ($quantity < $plan->minimum_quantity || $quantity > 10000) {
                throw ValidationException::withMessages(['quantity' => 'Choose between '.$plan->minimum_quantity.' and 10,000 units.']);
            }
            if ($customer->subscriptions()->where('plan_id', $plan->id)->where('status', '!=', 'closed')->exists()) {
                throw ValidationException::withMessages(['planId' => 'You already have a subscription to this plan. Manage it under Subscriptions.']);
            }
            $quote = $this->preview->handle($plan, $quantity, $variantId, true);
            if ($quoteFingerprint !== null && ! hash_equals($quoteFingerprint, $quote['fingerprint'])) {
                throw ValidationException::withMessages(['accepted' => 'The price or discount has changed. Review the updated totals and accept the terms again.']);
            }
            $lines = array_map(fn (array $line): array => Arr::except($line, ['subtotal_cents', 'discount_cents', 'tax_cents', 'total_cents']), $quote['first']['lines']);
            $subscription = $customer->subscriptions()->create([
                'plan_id' => $plan->id, 'quantity' => $quantity, 'unit_price_cents' => $lines[0]['unit_price_cents'],
                'tax_basis_points' => $lines[0]['tax_basis_points'], 'billing_interval' => $plan->interval,
                'starts_on' => today(), 'next_billing_on' => today(), 'payment_terms_days' => 14, 'status' => 'active',
            ]);
            $subscription->lines()->createMany($lines);
            $this->generateInvoices->handle($subscription);
            $firstInvoice = $subscription->invoices()->firstOrFail();
            if ($firstInvoice->total_cents !== $quote['first']['total_cents'] || $firstInvoice->discount_cents !== $quote['first']['amount']) {
                throw ValidationException::withMessages(['accepted' => 'The price or discount has changed. Review the updated totals and accept the terms again.']);
            }
            $subscription->invoices()->where('status', 'draft')->get()->each(function ($invoice): void {
                $invoice->update(['status' => $invoice->total_cents === 0 ? 'paid' : 'confirmed']);
            });

            return $subscription->refresh();
        }, 3);
    }
}
