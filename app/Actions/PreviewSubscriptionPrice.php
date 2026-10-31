<?php

namespace App\Actions;

use App\Models\Plan;
use App\Models\Subscription;

class PreviewSubscriptionPrice
{
    public function __construct(private BuildSubscriptionLines $buildLines, private CalculateInvoiceTotals $totals) {}

    /** @return array<string, mixed> */
    public function handle(Plan $plan, int $quantity, ?int $variantId, bool $lockRules = false): array
    {
        $lines = $this->buildLines->handle($plan, $quantity, $variantId);
        $subscription = new Subscription(['starts_on' => today(), 'next_billing_on' => today(), 'billing_interval' => $plan->interval]);
        $first = $this->totals->handle($subscription, today()->toImmutable(), $lines, $lockRules);
        $nextDate = $subscription->followingBillingDate();
        $additionalUses = $first['discount'] ? [$first['discount']->id => 1] : [];
        $next = $this->totals->handle($subscription, $nextDate, $lines, $lockRules, $additionalUses);
        $fingerprint = hash('sha256', json_encode([
            $plan->id, $plan->interval, $lines, $first['discount']?->id, $first['amount'],
            $first['total_cents'], today()->toDateString(),
        ], JSON_THROW_ON_ERROR));

        return ['first' => $first, 'next' => $next, 'next_billing_on' => $nextDate, 'due_on' => today()->addDays(14), 'fingerprint' => $fingerprint];
    }
}
