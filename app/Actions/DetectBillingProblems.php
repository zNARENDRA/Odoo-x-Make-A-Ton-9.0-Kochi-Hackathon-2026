<?php

namespace App\Actions;

use App\Models\BillingIssue;
use App\Models\Invoice;
use App\Models\Subscription;

class DetectBillingProblems
{
    public function handle(): void
    {
        Subscription::chunkById(100, function ($subscriptions): void {
            foreach ($subscriptions as $subscription) {
                $invalid = $subscription->status === 'active' && (
                    ! $subscription->next_billing_on || $subscription->next_billing_on->lt($subscription->starts_on)
                    || ! in_array($subscription->billing_interval, ['daily', 'weekly', 'monthly', 'yearly'], true)
                );
                BillingIssue::record('invalid_schedule', 'Subscription', $subscription->id, 'Invalid billing schedule',
                    'An active subscription has a missing or invalid next billing date or billing interval.', $invalid);
                $late = ! $invalid && $subscription->status === 'active'
                    && $subscription->next_billing_on->lte(today())
                    && $subscription->created_at->lte(now()->subMinutes(15))
                    && (! $subscription->expires_on || $subscription->next_billing_on->lt($subscription->expires_on));
                BillingIssue::record('billing_overdue', 'Subscription', $subscription->id, 'Subscription billing is overdue',
                    'A billing period is due and the billing cursor has not advanced. Check invoice generation and the scheduler.', $late);
            }
        });
        Invoice::withSum('payments', 'amount_cents')->chunkById(100, function ($invoices): void {
            foreach ($invoices as $invoice) {
                $paid = (int) $invoice->payments_sum_amount_cents;
                $invalid = $paid < 0 || $paid > $invoice->total_cents
                    || ($invoice->status === 'paid' && $paid !== (int) $invoice->total_cents)
                    || ($invoice->status === 'confirmed' && $paid >= $invoice->total_cents)
                    || (in_array($invoice->status, ['draft', 'cancelled'], true) && $paid !== 0)
                    || $invoice->payments()->where(fn ($query) => $query->where('amount_cents', '<=', 0)->orWhereDate('paid_on', '>', today()))->exists();
                BillingIssue::record('payment_inconsistent', 'Invoice', $invoice->id, 'Payment records need review',
                    'Recorded payments or invoice status do not reconcile. Review the payment history against the invoice and bank records.', $invalid);
            }
        });
    }
}
