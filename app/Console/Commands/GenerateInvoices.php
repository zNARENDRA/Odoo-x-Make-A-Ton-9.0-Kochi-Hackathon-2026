<?php

namespace App\Console\Commands;

use App\Actions\GenerateRenewalInvoices;
use App\Models\Subscription;
use Illuminate\Console\Command;

class GenerateInvoices extends Command
{
    protected $signature = 'billing:generate-invoices';

    protected $description = 'Generate missing draft invoices for all due active subscriptions';

    public function handle(GenerateRenewalInvoices $generator): int
    {
        $count = 0;
        Subscription::dueForBilling()
            ->chunkById(100, function ($subscriptions) use ($generator, &$count): void {
                foreach ($subscriptions as $subscription) {
                    $count += $generator->handle($subscription);
                }
            });
        $this->info("Generated {$count} draft invoice(s).");

        return self::SUCCESS;
    }
}
