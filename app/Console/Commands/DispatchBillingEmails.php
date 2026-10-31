<?php

namespace App\Console\Commands;

use App\Jobs\SendBillingEmail;
use App\Models\BillingEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchBillingEmails extends Command
{
    protected $signature = 'billing:dispatch-emails';

    protected $description = 'Queue committed billing email records for background delivery';

    public function handle(): int
    {
        BillingEmail::whereNull('queued_at')->whereNull('sent_at')->whereNull('suppressed_at')
            ->chunkById(100, function ($emails): void {
                foreach ($emails as $email) {
                    DB::transaction(function () use ($email): void {
                        $email = BillingEmail::lockForUpdate()->findOrFail($email->id);
                        if ($email->queued_at || $email->sent_at || $email->suppressed_at) {
                            return;
                        }
                        $email->update(['queued_at' => now()]);
                        SendBillingEmail::dispatch($email->id)->beforeCommit();
                    });
                }
            });
        $this->info('Billing emails queued.');

        return self::SUCCESS;
    }
}
