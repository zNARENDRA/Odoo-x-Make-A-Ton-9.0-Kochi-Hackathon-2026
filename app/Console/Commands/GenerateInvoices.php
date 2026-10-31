<?php

namespace App\Console\Commands;

use App\Actions\GenerateDueInvoices;
use Illuminate\Console\Command;

class GenerateInvoices extends Command
{
    protected $signature = 'billing:generate-invoices';

    protected $description = 'Generate missing draft invoices for all due active subscriptions';

    public function handle(GenerateDueInvoices $generator): int
    {
        $result = $generator->handle();
        $this->info("Generated {$result['created']} draft invoice(s).");
        if ($result['failed'] > 0) {
            $this->error($result['failed'].' subscription(s) failed. Review Billing operations.');
        }

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
