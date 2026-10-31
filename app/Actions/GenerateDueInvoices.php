<?php

namespace App\Actions;

use App\Models\BillingIssue;
use App\Models\Subscription;
use Throwable;

class GenerateDueInvoices
{
    public function __construct(private GenerateRenewalInvoices $generator) {}

    /** @return array{created: int, failed: int} */
    public function handle(): array
    {
        $result = ['created' => 0, 'failed' => 0];
        Subscription::dueForBilling()->chunkById(100, function ($subscriptions) use (&$result): void {
            foreach ($subscriptions as $subscription) {
                try {
                    $result['created'] += $this->generator->handle($subscription);
                    BillingIssue::record('invoice_generation_failed', 'Subscription', $subscription->id, 'Invoice generation failed', '', false);
                } catch (Throwable $exception) {
                    report($exception);
                    BillingIssue::record('invoice_generation_failed', 'Subscription', $subscription->id, 'Invoice generation failed',
                        'The billing attempt failed. Review the subscription and server logs before retrying. Error type: '.class_basename($exception));
                    $result['failed']++;
                }
            }
        });

        return $result;
    }
}
