<?php

namespace App\Models;

use Database\Factories\DiscountRedemptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscountRedemption extends Model
{
    /** @use HasFactory<DiscountRedemptionFactory> */
    use HasFactory;

    protected $fillable = ['discount_id', 'invoice_id', 'amount_cents'];

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
