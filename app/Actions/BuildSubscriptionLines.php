<?php

namespace App\Actions;

use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BuildSubscriptionLines
{
    /** @param array<int, array{product_id: int|string, product_variant_id?: int|string|null, quantity: int|string}> $items
     * @return array<int, array<string, int|string|null>>
     */
    public function handle(Plan $plan, int $quantity, ?int $variantId, array $items = []): array
    {
        Validator::make(['items' => $items], [
            'items' => 'array|list|max:49', 'items.*' => 'array:product_id,product_variant_id,quantity',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.product_variant_id' => 'nullable|integer|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1|max:10000',
        ])->validate();
        $plan->loadMissing('product');
        $lines = [$this->line($plan->product, $variantId, $quantity, (int) $plan->price_cents, 0, $plan->name)];
        $products = Product::whereIn('id', array_column($items, 'product_id'))->get()->keyBy('id');
        foreach ($items as $index => $item) {
            $product = $products->get($item['product_id']);
            if (! $product) {
                throw ValidationException::withMessages(['items' => 'A selected product is no longer available.']);
            }
            $lines[] = $this->line($product, empty($item['product_variant_id']) ? null : (int) $item['product_variant_id'],
                (int) $item['quantity'], (int) $product->sales_price_cents, $index + 1);
        }

        return $lines;
    }

    /** @return array<string, int|string|null> */
    private function line(?Product $product, ?int $variantId, int $quantity, int $basePrice, int $position, string $fallback = ''): array
    {
        $variant = $variantId ? ProductVariant::where('product_id', $product?->id)->where('is_active', true)->find($variantId) : null;
        if ($variantId && ! $variant) {
            throw ValidationException::withMessages(['items' => 'Choose an active variant belonging to its selected product.']);
        }
        $price = $basePrice + (int) ($variant?->price_modifier_cents ?? 0);
        if ($price < 0 || $price > 199999998) {
            throw ValidationException::withMessages(['items' => 'The variant produces an invalid unit price.']);
        }

        return ['product_id' => $product?->id, 'product_variant_id' => $variant?->id,
            'description' => $product?->name ?? $fallback, 'variant_name' => $variant?->name,
            'quantity' => $quantity, 'unit_price_cents' => $price, 'tax_basis_points' => $product?->tax_basis_points ?? 0,
            'position' => $position];
    }
}
