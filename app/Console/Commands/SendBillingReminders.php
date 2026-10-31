<?php

namespace App\Console\Commands;

use App\Actions\QueueBillingEmail;
use App\Models\BillingEmail;
use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendBillingReminders extends Command
{
    protected $signature = 'billing:send-reminders';

    protected $description = 'Prepare payment, overdue and renewal reminders once per invoice or billing period';

    public function handle(QueueBillingEmail $emails): int
    {
        $paymentDays = max(0, config('billing.payment_reminder_days'));
        Invoice::where('status', 'confirmed')->whereDate('due_on', '<=', today()->addDays($paymentDays))
            ->chunkById(100, function ($invoices) use ($emails): void {
                foreach ($invoices as $invoice) {
                    if ($invoice->balanceCents() > 0) {
                        $emails->invoiceNotice($invoice, $invoice->due_on->lt(today()) ? 'overdue_alert' : 'payment_reminder');
                    }
                }
            });

        $renewalDays = max(0, config('billing.renewal_reminder_days'));
        Subscription::where('status', 'active')->whereDate('next_billing_on', '>', today())
            ->whereDate('next_billing_on', '<=', today()->addDays($renewalDays))
            ->where(fn ($query) => $query->whereNull('expires_on')->orWhereColumn('next_billing_on', '<', 'expires_on'))
            ->chunkById(100, function ($subscriptions): void {
                foreach ($subscriptions as $subscription) {
                    DB::transaction(function () use ($subscription): void {
                        $email = BillingEmail::firstOrCreate([
                            'event_key' => 'renewal_reminder:'.$subscription->id.':'.$subscription->next_billing_on->toDateString(),
                        ], ['kind' => 'renewal_reminder', 'subscription_id' => $subscription->id, 'billing_on' => $subscription->next_billing_on]);
                        BillingEmail::whereKey($email->id)->whereNull('sent_at')->whereNotNull('suppressed_at')
                            ->update(['suppressed_at' => null, 'queued_at' => null]);
                    });
                }
            });
        $this->info('Billing reminders prepared.');

        return self::SUCCESS;
    }
}
