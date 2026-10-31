<?php

namespace App\Models;

use Database\Factories\SubscriptionLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionLine extends Model
{
    /** @use HasFactory<SubscriptionLineFactory> */
    use HasFactory;

    protected $fillable = ['subscription_id', 'product_id', 'product_variant_id', 'description', 'variant_name', 'quantity', 'unit_price_cents', 'tax_basis_points', 'position'];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
