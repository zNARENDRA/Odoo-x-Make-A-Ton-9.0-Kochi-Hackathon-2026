<?php

namespace App\Console\Commands;

use App\Actions\DetectBillingProblems;
use Illuminate\Console\Command;

class CheckBillingProblems extends Command
{
    protected $signature = 'billing:check-problems';

    protected $description = 'Detect overdue billing, invalid schedules and inconsistent payment records';

    public function handle(DetectBillingProblems $detector): int
    {
        $detector->handle();
        $this->info('Billing problem checks completed.');

        return self::SUCCESS;
    }
}
