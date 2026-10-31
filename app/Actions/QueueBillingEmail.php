<?php

namespace App\Actions;

use App\Models\BillingEmail;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Support\Str;

class QueueBillingEmail
{
    public function subscription(Subscription $subscription, bool $created = false): void
    {
        if (! $created && ! $subscription->wasChanged('status')) {
            return;
        }
        $kind = match (true) {
            $subscription->status === 'active' && $subscription->getRawOriginal('status') !== 'paused' => 'subscription_confirmation',
            $subscription->status === 'closed' => 'cancellation_confirmation',
            default => null,
        };
        if ($kind) {
            BillingEmail::create(['event_key' => (string) Str::uuid(), 'kind' => $kind, 'subscription_id' => $subscription->id]);
        }
    }

    public function invoice(Invoice $invoice, bool $created = false): void
    {
        if (in_array($invoice->status, ['confirmed', 'paid'], true)
            && ($created || ($invoice->wasChanged('status') && $invoice->getRawOriginal('status') === 'draft'))) {
            $this->invoiceNotice($invoice, 'invoice_notification');
        }
    }

    public function invoiceNotice(Invoice $invoice, string $kind): void
    {
        $email = BillingEmail::firstOrCreate(['event_key' => $kind.':'.$invoice->id], [
            'kind' => $kind, 'invoice_id' => $invoice->id, 'subscription_id' => $invoice->subscription_id,
        ]);
        BillingEmail::whereKey($email->id)->whereNull('sent_at')->whereNotNull('suppressed_at')
            ->update(['suppressed_at' => null, 'queued_at' => null]);
    }

    public function payment(Payment $payment): void
    {
        BillingEmail::firstOrCreate(['event_key' => 'payment_confirmation:'.$payment->id], [
            'kind' => 'payment_confirmation', 'payment_id' => $payment->id, 'invoice_id' => $payment->invoice_id,
        ]);
    }
}
