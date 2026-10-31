<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = ['name', 'type', 'sales_price_cents', 'cost_price_cents', 'tax_basis_points', 'notes'];

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }
}
