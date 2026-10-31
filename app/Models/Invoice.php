<?php

namespace App\Models;

use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    protected $fillable = ['subscription_id', 'period_start', 'due_on', 'total_cents', 'subtotal_cents', 'tax_cents', 'discount_cents', 'status', 'snapshot', 'currency'];

    protected function casts(): array
    {
        return ['period_start' => 'immutable_date', 'due_on' => 'immutable_date', 'snapshot' => 'array'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function number(): string
    {
        return 'INV-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }

    public function balanceCents(): int
    {
        return max(0, (int) $this->total_cents - (int) ($this->payments_sum_amount_cents ?? $this->payments()->sum('amount_cents')));
    }
}
