<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'plan_id', 'quantity', 'unit_price_cents', 'status', 'starts_on', 'next_billing_on', 'expires_on', 'payment_terms_days', 'billing_interval', 'tax_basis_points', 'quotation_template_id', 'quotation_expires_on'];

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'next_billing_on' => 'immutable_date', 'expires_on' => 'immutable_date', 'quotation_expires_on' => 'immutable_date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeDueForBilling(Builder $query): void
    {
        $query->whereIn('status', ['active', 'paused'])->where(function ($due): void {
            $due->whereDate('next_billing_on', '<=', today())->orWhereDate('expires_on', '<=', today());
        });
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SubscriptionLine::class)->orderBy('position');
    }

    public function number(): string
    {
        return 'SUB-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function followingBillingDate(): CarbonImmutable
    {
        $date = $this->next_billing_on;
        $next = match ($this->billing_interval) {
            'daily' => $date->addDay(),
            'weekly' => $date->addWeek(),
            'monthly' => $date->addMonthNoOverflow(),
            'yearly' => $date->addYearNoOverflow()->month($this->starts_on->month),
        };
        if (in_array($this->billing_interval, ['monthly', 'yearly'], true)) {
            $next = $next->day(min($this->starts_on->day, $next->daysInMonth));
        }

        return $next;
    }
}
