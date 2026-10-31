<?php

namespace App\Models;

use Database\Factories\QuotationTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuotationTemplate extends Model
{
    /** @use HasFactory<QuotationTemplateFactory> */
    use HasFactory;

    protected $fillable = ['name', 'plan_id', 'product_variant_id', 'quantity', 'validity_days', 'payment_terms_days', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationTemplateLine::class)->orderBy('position');
    }
}
