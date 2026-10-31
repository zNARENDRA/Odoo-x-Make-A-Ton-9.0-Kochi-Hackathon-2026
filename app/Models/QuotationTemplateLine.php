<?php

namespace App\Models;

use Database\Factories\QuotationTemplateLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationTemplateLine extends Model
{
    /** @use HasFactory<QuotationTemplateLineFactory> */
    use HasFactory;

    protected $fillable = ['quotation_template_id', 'product_id', 'product_variant_id', 'quantity', 'position'];

    public function quotationTemplate(): BelongsTo
    {
        return $this->belongsTo(QuotationTemplate::class);
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
