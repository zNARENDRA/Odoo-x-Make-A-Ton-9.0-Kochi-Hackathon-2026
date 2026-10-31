<?php

namespace App\Models;

use Database\Factories\InvoiceLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
    /** @use HasFactory<InvoiceLineFactory> */
    use HasFactory;

    protected $fillable = ['invoice_id', 'product_id', 'product_variant_id', 'description', 'variant_name', 'quantity', 'unit_price_cents', 'tax_basis_points', 'position', 'subtotal_cents', 'discount_cents', 'tax_cents', 'total_cents'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
