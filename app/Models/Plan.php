<?php

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $fillable = ['name', 'product_id', 'price_cents', 'interval', 'minimum_quantity', 'available_from', 'available_until', 'can_pause', 'can_close', 'can_renew', 'auto_close'];

    protected function casts(): array
    {
        return ['available_from' => 'immutable_date', 'available_until' => 'immutable_date', 'can_pause' => 'boolean', 'can_close' => 'boolean', 'can_renew' => 'boolean', 'auto_close' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeAvailable(Builder $query): void
    {
        $query->whereNotNull('product_id')
            ->where(fn ($dates) => $dates->whereNull('available_from')->orWhereDate('available_from', '<=', today()))
            ->where(fn ($dates) => $dates->whereNull('available_until')->orWhereDate('available_until', '>=', today()));
    }
}
