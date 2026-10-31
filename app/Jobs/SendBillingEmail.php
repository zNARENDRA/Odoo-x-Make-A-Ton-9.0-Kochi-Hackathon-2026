<?php

namespace App\Jobs;

use App\Mail\BillingUpdate;
use App\Models\BillingEmail;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscription;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendBillingEmail implements ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 3;

    public int $timeout = 45;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(public int $emailId) {}

    /** @return array<int, RateLimited> */
    public function middleware(): array
    {
        return [new RateLimited('billing-email')];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            $email = BillingEmail::lockForUpdate()->find($this->emailId);
            if (! $email || $email->sent_at || $email->suppressed_at) {
                return;
            }
            $invoice = Invoice::with('subscription.user', 'subscription.plan')->lockForUpdate()->find($email->invoice_id);
            $subscription = $invoice?->subscription ?? Subscription::with('user', 'plan')->lockForUpdate()->find($email->subscription_id);
            $payment = Payment::find($email->payment_id);
            if (! $subscription || ! $this->isRelevant($email, $subscription, $invoice, $payment)) {
                $email->update(['suppressed_at' => now()]);

                return;
            }
            $mail = $this->message($email, $subscription, $invoice, $payment);
            $recipient = $subscription->user->email;
            if (config('mail.default') === 'resend') {
                $html = $mail->render();
                Http::withToken(config('services.resend.key'))->timeout(20)->withHeaders([
                    'Idempotency-Key' => 'billing-'.$email->id.'-'.hash('sha256', $recipient.$html),
                ])->post(rtrim(config('services.resend.url'), '/').'/emails', [
                    'from' => config('mail.from.name').' <'.config('mail.from.address').'>',
                    'to' => [$recipient], 'subject' => $mail->title, 'html' => $html,
                ])->throw();
            } else {
                Mail::to($recipient)->send($mail);
            }
            $email->update(['sent_at' => now(), 'failed_at' => null]);
        });
    }

    private function isRelevant(BillingEmail $email, Subscription $subscription, ?Invoice $invoice, ?Payment $payment): bool
    {
        return match ($email->kind) {
            'invoice_notification' => $invoice && in_array($invoice->status, ['confirmed', 'paid'], true),
            'payment_confirmation' => $invoice && $payment,
            'payment_reminder' => $invoice && $invoice->status === 'confirmed' && $invoice->balanceCents() > 0
                && $invoice->due_on->gte(today()) && $invoice->due_on->lte(today()->addDays(max(0, config('billing.payment_reminder_days')))),
            'overdue_alert' => $invoice && $invoice->status === 'confirmed' && $invoice->balanceCents() > 0 && $invoice->due_on->lt(today()),
            'renewal_reminder' => $subscription->status === 'active' && $subscription->next_billing_on->equalTo($email->billing_on)
                && $subscription->next_billing_on->gt(today()) && (! $subscription->expires_on || $subscription->next_billing_on->lt($subscription->expires_on)),
            'subscription_confirmation', 'cancellation_confirmation' => true,
            default => false,
        };
    }

    private function message(BillingEmail $email, Subscription $subscription, ?Invoice $invoice, ?Payment $payment): BillingUpdate
    {
        [$title, $intro] = match ($email->kind) {
            'subscription_confirmation' => ['Subscription confirmed', 'Your subscription has been activated.'],
            'cancellation_confirmation' => ['Subscription cancellation confirmed', 'Your subscription has been closed. Future recurring billing has stopped. Any invoices already due remain payable.'],
            'invoice_notification' => ['Your invoice is ready', 'A new invoice is ready. Please review the amount and due date below.'],
            'payment_confirmation' => ['Payment received', 'Thank you. We have recorded your payment.'],
            'payment_reminder' => ['Payment due soon', 'Your invoice is approaching its due date. Please arrange payment with the seller.'],
            'overdue_alert' => ['Payment overdue', 'Your invoice is past its due date and has an outstanding balance. Please arrange payment with the seller.'],
            'renewal_reminder' => ['Subscription renewal approaching', 'Your subscription is scheduled to renew. The next invoice will include applicable discounts and tax.'],
        };
        $details = ['Subscription' => $subscription->number(), 'Plan' => $subscription->plan->name];
        if ($invoice) {
            $details += [
                'Invoice' => $invoice->number(),
                'Invoice total' => $this->money($invoice->total_cents, $invoice->currency),
                'Due date' => $invoice->due_on->format('d M Y'),
                'Outstanding balance' => $this->money($invoice->balanceCents(), $invoice->currency),
            ];
        } elseif ($email->kind !== 'cancellation_confirmation') {
            $details['Billing frequency'] = ucfirst($subscription->billing_interval);
            $details['Next billing date'] = $subscription->next_billing_on->format('d M Y');
        }
        if ($payment) {
            $details += [
                'Receipt reference' => 'PAY-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT),
                'Payment received' => $this->money($payment->amount_cents, $invoice->currency),
                'Payment date' => $payment->paid_on->format('d M Y'),
                'Payment method' => ucwords(str_replace('_', ' ', $payment->method)),
            ];
        }

        return new BillingUpdate($title, $subscription->user->name, $intro, $details,
            $invoice ? route('invoices.show', $invoice) : route('subscriptions'));
    }

    private function money(int $cents, string $currency): string
    {
        return $currency.' '.number_format($cents / 100, 2);
    }

    public function failed(?Throwable $exception): void
    {
        BillingEmail::whereKey($this->emailId)->whereNull('sent_at')->update(['failed_at' => now()]);
    }
}
