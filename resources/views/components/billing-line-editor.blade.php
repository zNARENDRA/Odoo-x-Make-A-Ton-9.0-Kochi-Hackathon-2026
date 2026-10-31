@props(['items', 'products', 'model', 'addAction', 'removeAction'])
<div class="mt-5 rounded-xl border border-slate-200 p-4">
    <div class="flex flex-wrap items-center justify-between gap-3"><div><h3 class="text-sm font-semibold">Additional products & add-ons</h3><p class="mt-1 text-xs text-slate-500">Each line repeats on this plan’s billing interval. Current product prices and variant modifiers are captured in the draft.</p></div><button type="button" class="btn-secondary" wire:click="{{ $addAction }}">+ Add line</button></div>
    <div class="mt-4 flex flex-col gap-4">
    @foreach($items as $index => $row)
        @php($selected = $products->firstWhere('id', $row['product_id'] ?? null))
        <div wire:key="{{ $model }}-{{ $index }}" class="grid items-end gap-3 rounded-lg bg-slate-50 p-3 md:grid-cols-[2fr_2fr_1fr_auto]">
            <div><label class="label" for="{{ $model }}-{{ $index }}-product">Product</label><select id="{{ $model }}-{{ $index }}-product" class="input" wire:model.live="{{ $model }}.{{ $index }}.product_id" required><option value="">Choose product</option>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }} · {{ config('billing.currency') }} {{ number_format($product->sales_price_cents / 100, 2) }}</option>@endforeach</select></div>
            <div><label class="label" for="{{ $model }}-{{ $index }}-variant">Variant (optional)</label><select id="{{ $model }}-{{ $index }}-variant" class="input" wire:model="{{ $model }}.{{ $index }}.product_variant_id"><option value="">Standard</option>@foreach($selected?->variants ?? [] as $variant)@if($variant->is_active)<option value="{{ $variant->id }}">{{ $variant->name }} ({{ $variant->price_modifier_cents >= 0 ? '+' : '' }}{{ number_format($variant->price_modifier_cents / 100, 2) }})</option>@endif @endforeach</select></div>
            <div><label class="label" for="{{ $model }}-{{ $index }}-qty">Quantity</label><input id="{{ $model }}-{{ $index }}-qty" class="input" type="number" min="1" max="10000" wire:model="{{ $model }}.{{ $index }}.quantity" required></div>
            <button type="button" wire:click="{{ $removeAction }}({{ $index }})" class="btn-secondary" aria-label="Remove additional line {{ $index + 1 }}">Remove</button>
        </div>
    @endforeach
    </div>
</div>
