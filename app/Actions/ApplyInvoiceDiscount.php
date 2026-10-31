<?php

namespace App\Actions;

use App\Models\Discount;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class ApplyInvoiceDiscount
{
    /** Call inside the invoice transaction so rule locks cover redemption.
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{lines: array<int, array<string, mixed>>, discount: ?Discount, amount: int}
     */
    public function handle(Subscription $subscription, CarbonImmutable $period, array $lines): array
    {
        $rules = Discount::where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('subscription_id')->orWhere('subscription_id', $subscription->id))
            ->where(fn (Builder $query) => $query->whereNull('starts_on')->orWhereDate('starts_on', '<=', $period))
            ->where(fn (Builder $query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $period))
            ->orderBy('id')->lockForUpdate()->get();
        $best = null;
        $amount = 0;
        $eligible = [];
        foreach ($rules as $rule) {
            if ($rule->max_uses !== null && $rule->uses_count >= $rule->max_uses) {
                continue;
            }
            $indexes = array_keys(array_filter($lines, fn (array $line): bool => ! $rule->product_id || $line['product_id'] === $rule->product_id));
            $subtotal = 0;
            $quantity = 0;
            foreach ($indexes as $index) {
                $subtotal += $lines[$index]['subtotal_cents'];
                $quantity += $lines[$index]['quantity'];
            }
            if ($quantity < $rule->minimum_quantity || $subtotal < $rule->minimum_purchase_cents) {
                continue;
            }
            $saving = min($subtotal, $rule->type === 'fixed' ? (int) $rule->value : intdiv($subtotal * (int) $rule->value + 5000, 10000));
            if ($saving > $amount) {
                $best = $rule;
                $amount = $saving;
                $eligible = $indexes;
            }
        }
        foreach ($lines as &$line) {
            $line['discount_cents'] = 0;
        }
        unset($line);
        if ($best && $amount > 0) {
            $base = array_sum(array_map(fn (int $index): int => $lines[$index]['subtotal_cents'], $eligible));
            $remainders = [];
            $allocated = 0;
            foreach ($eligible as $index) {
                [$share, $remainder] = $this->proportion($amount, $lines[$index]['subtotal_cents'], $base);
                $lines[$index]['discount_cents'] = $share;
                $allocated += $share;
                $remainders[$index] = $remainder;
            }
            arsort($remainders, SORT_NUMERIC);
            foreach (array_keys($remainders) as $index) {
                if ($allocated >= $amount) {
                    break;
                }
                $lines[$index]['discount_cents']++;
                $allocated++;
            }
        }

        return ['lines' => $lines, 'discount' => $best, 'amount' => $amount];
    }

    /** Exact multiply/divide without overflowing when invoice totals are large.
     * @return array{int, int}
     */
    private function proportion(int $amount, int $part, int $whole): array
    {
        $quotient = 0;
        $remainder = 0;
        $stepQuotient = intdiv($part, $whole);
        $stepRemainder = $part % $whole;
        while ($amount > 0) {
            if ($amount % 2 === 1) {
                $quotient += $stepQuotient;
                $remainder += $stepRemainder;
                if ($remainder >= $whole) {
                    $quotient++;
                    $remainder -= $whole;
                }
            }
            $amount = intdiv($amount, 2);
            if ($amount > 0) {
                $stepQuotient *= 2;
                $stepRemainder *= 2;
                if ($stepRemainder >= $whole) {
                    $stepQuotient++;
                    $stepRemainder -= $whole;
                }
            }
        }

        return [$quotient, $remainder];
    }
}
