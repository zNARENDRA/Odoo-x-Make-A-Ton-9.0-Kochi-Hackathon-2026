<?php

namespace App\Actions;

use App\Models\Subscription;
use Carbon\CarbonImmutable;

class CalculateInvoiceTotals
{
    public function __construct(private ApplyInvoiceDiscount $discounts) {}

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, int>  $additionalUses
     * @return array<string, mixed>
     */
    public function handle(Subscription $subscription, CarbonImmutable $period, array $lines, bool $lockRules = true, array $additionalUses = []): array
    {
        foreach ($lines as &$line) {
            $line['subtotal_cents'] = (int) $line['unit_price_cents'] * (int) $line['quantity'];
        }
        unset($line);
        $result = $this->discounts->handle($subscription, $period, $lines, $lockRules, $additionalUses);
        $lines = $result['lines'];
        foreach ($lines as &$line) {
            $net = $line['subtotal_cents'] - $line['discount_cents'];
            $line['tax_cents'] = intdiv($net * (int) $line['tax_basis_points'] + 5000, 10000);
            $line['total_cents'] = $net + $line['tax_cents'];
        }
        unset($line);

        return [
            'lines' => $lines, 'discount' => $result['discount'], 'amount' => $result['amount'],
            'subtotal_cents' => array_sum(array_column($lines, 'subtotal_cents')),
            'tax_cents' => array_sum(array_column($lines, 'tax_cents')),
            'total_cents' => array_sum(array_column($lines, 'total_cents')),
        ];
    }
}
