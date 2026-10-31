<?php

namespace App\Actions;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateCustomerSubscription
{
    public function __construct(private BuildSubscriptionLines $buildLines, private GenerateRenewalInvoices $generateInvoices) {}

    public function handle(User $customer, int $planId, int $quantity, ?int $variantId = null): Subscription
    {
        abort_unless($customer->role === 'customer', 403);

        return DB::transaction(function () use ($customer, $planId, $quantity, $variantId): Subscription {
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
            $lines = $this->buildLines->handle($plan, $quantity, $variantId);
            $subscription = $customer->subscriptions()->create([
                'plan_id' => $plan->id, 'quantity' => $quantity, 'unit_price_cents' => $lines[0]['unit_price_cents'],
                'tax_basis_points' => $lines[0]['tax_basis_points'], 'billing_interval' => $plan->interval,
                'starts_on' => today(), 'next_billing_on' => today(), 'payment_terms_days' => 14, 'status' => 'active',
            ]);
            $subscription->lines()->createMany($lines);
            $this->generateInvoices->handle($subscription);
            $subscription->invoices()->where('status', 'draft')->get()->each(function ($invoice): void {
                $invoice->update(['status' => $invoice->total_cents === 0 ? 'paid' : 'confirmed']);
            });

            return $subscription->refresh();
        }, 3);
    }
}
