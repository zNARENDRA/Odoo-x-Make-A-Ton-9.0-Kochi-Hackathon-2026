<?php

namespace App\Actions;

use App\Models\DiscountRedemption;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\SubscriptionLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class GenerateRenewalInvoices
{
    public function __construct(private ApplyInvoiceDiscount $discounts) {}

    public function handle(Subscription $subscription, ?CarbonImmutable $through = null): int
    {
        $through ??= CarbonImmutable::today();

        return DB::transaction(function () use ($subscription, $through): int {
            $subscription = Subscription::with(['user', 'plan.product', 'lines'])->lockForUpdate()->findOrFail($subscription->id);
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
                $exists = $subscription->invoices()->whereDate('period_start', $subscription->next_billing_on)->exists();
                if (! $exists) {
                    $lines = $subscription->lines->map(fn (SubscriptionLine $line): array => $line->only([
                        'product_id', 'product_variant_id', 'description', 'variant_name', 'quantity', 'unit_price_cents', 'tax_basis_points', 'position',
                    ]))->all();
                    if (! $lines) {
                        $lines = [['product_id' => $subscription->plan->product_id, 'product_variant_id' => null,
                            'description' => $subscription->plan->product?->name ?? $subscription->plan->name, 'variant_name' => null,
                            'quantity' => $subscription->quantity, 'unit_price_cents' => $subscription->unit_price_cents,
                            'tax_basis_points' => $subscription->tax_basis_points, 'position' => 0]];
                    }
                    foreach ($lines as &$line) {
                        $line['subtotal_cents'] = (int) $line['unit_price_cents'] * (int) $line['quantity'];
                    }
                    unset($line);
                    $result = $this->discounts->handle($subscription, $subscription->next_billing_on, $lines);
                    $lines = $result['lines'];
                    foreach ($lines as &$line) {
                        $net = $line['subtotal_cents'] - $line['discount_cents'];
                        $line['tax_cents'] = intdiv($net * (int) $line['tax_basis_points'] + 5000, 10000);
                        $line['total_cents'] = $net + $line['tax_cents'];
                    }
                    unset($line);
                    $invoice = Invoice::create([
                        'subscription_id' => $subscription->id, 'period_start' => $subscription->next_billing_on,
                        'due_on' => $subscription->next_billing_on->addDays($subscription->payment_terms_days),
                        'subtotal_cents' => array_sum(array_column($lines, 'subtotal_cents')),
                        'discount_cents' => $result['amount'], 'tax_cents' => array_sum(array_column($lines, 'tax_cents')),
                        'total_cents' => array_sum(array_column($lines, 'total_cents')),
                        'status' => 'draft', 'currency' => config('billing.currency'),
                        'snapshot' => [
                            'customer' => $subscription->user->name, 'email' => $subscription->user->email,
                            'plan' => $subscription->plan->name, 'product' => $lines[0]['description'],
                            'quantity' => $lines[0]['quantity'], 'unit_price_cents' => $lines[0]['unit_price_cents'],
                            'tax_basis_points' => $lines[0]['tax_basis_points'], 'lines' => $lines,
                            'discount' => $result['discount']?->only(['name', 'type', 'value']),
                        ],
                    ]);
                    $invoice->lines()->createMany($lines);
                    if ($result['discount']) {
                        DiscountRedemption::create(['discount_id' => $result['discount']->id, 'invoice_id' => $invoice->id, 'amount_cents' => $result['amount']]);
                        $result['discount']->increment('uses_count');
                    }
                    $created++;
                }
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
