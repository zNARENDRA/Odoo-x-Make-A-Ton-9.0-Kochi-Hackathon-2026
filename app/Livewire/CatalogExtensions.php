<?php

namespace App\Livewire;

use App\Actions\BuildSubscriptionLines;
use App\Models\Discount;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\QuotationTemplate;
use App\Models\Subscription;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;

class CatalogExtensions extends Component
{
    public array $variant = ['product_id' => '', 'name' => '', 'modifier' => '0'];

    public array $discount = ['name' => '', 'type' => 'percentage', 'value' => '', 'product_id' => '', 'subscription_id' => '', 'minimum_quantity' => 1, 'minimum_purchase' => '0', 'starts_on' => '', 'ends_on' => '', 'max_uses' => ''];

    public array $template = ['name' => '', 'plan_id' => '', 'product_variant_id' => '', 'quantity' => 1, 'validity_days' => 30, 'payment_terms_days' => 14];

    public array $templateItems = [];

    public function mount(): void
    {
        Gate::authorize('manage-catalog');
    }

    #[On('catalog-updated')]
    public function refreshCatalog(): void
    {
        Gate::authorize('manage-catalog');
    }

    private function cents(string $value): int
    {
        $negative = str_starts_with($value, '-');
        $parts = explode('.', ltrim($value, '-'));
        $amount = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');

        return $negative ? -$amount : $amount;
    }

    public function saveVariant(): void
    {
        Gate::authorize('manage-catalog');
        $this->validate([
            'variant.product_id' => 'required|integer|exists:products,id',
            'variant.name' => ['required', 'string', 'max:120', Rule::unique('product_variants', 'name')->where('product_id', $this->variant['product_id'] ?? null)],
            'variant.modifier' => ['required', 'regex:/^-?\d{1,6}(\.\d{1,2})?$/'],
        ]);
        ProductVariant::create(['product_id' => $this->variant['product_id'], 'name' => $this->variant['name'], 'price_modifier_cents' => $this->cents($this->variant['modifier'])]);
        $this->reset('variant');
        session()->flash('catalog-success', 'Variant created. The modifier is added to each unit price.');
    }

    public function saveDiscount(): void
    {
        Gate::authorize('manage-catalog');
        $this->validate([
            'discount.name' => 'required|string|max:120', 'discount.type' => 'required|in:fixed,percentage',
            'discount.value' => ['required', 'regex:/^\d{1,6}(\.\d{1,2})?$/', 'numeric', 'gt:0', Rule::when(($this->discount['type'] ?? '') === 'percentage', 'max:100')],
            'discount.product_id' => 'nullable|integer|exists:products,id', 'discount.subscription_id' => 'nullable|integer|exists:subscriptions,id',
            'discount.minimum_quantity' => 'required|integer|min:1|max:500000',
            'discount.minimum_purchase' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'discount.starts_on' => 'nullable|date_format:Y-m-d',
            'discount.ends_on' => ['nullable', 'date_format:Y-m-d', Rule::when(! empty($this->discount['starts_on']), 'after_or_equal:discount.starts_on')],
            'discount.max_uses' => 'nullable|integer|min:1|max:100000000',
        ]);
        Discount::create([
            'name' => $this->discount['name'], 'type' => $this->discount['type'], 'value' => $this->cents($this->discount['value']),
            'product_id' => ($this->discount['product_id'] ?? null) ?: null, 'subscription_id' => ($this->discount['subscription_id'] ?? null) ?: null,
            'minimum_quantity' => $this->discount['minimum_quantity'], 'minimum_purchase_cents' => $this->cents($this->discount['minimum_purchase']),
            'starts_on' => ($this->discount['starts_on'] ?? null) ?: null, 'ends_on' => ($this->discount['ends_on'] ?? null) ?: null,
            'max_uses' => ($this->discount['max_uses'] ?? null) ?: null,
        ]);
        $this->reset('discount');
        session()->flash('catalog-success', 'Discount created. It will be considered automatically for new invoice periods.');
    }

    public function toggleDiscount(int $id): void
    {
        Gate::authorize('manage-catalog');
        DB::transaction(function () use ($id): void {
            $discount = Discount::lockForUpdate()->findOrFail($id);
            $discount->update(['is_active' => ! $discount->is_active]);
        });
    }

    public function updatedTemplate(mixed $value, ?string $key): void
    {
        if ($key === 'plan_id') {
            $this->template['product_variant_id'] = '';
        }
    }

    public function updatedTemplateItems(mixed $value, ?string $key): void
    {
        if ($key && str_ends_with($key, '.product_id')) {
            $this->templateItems[(int) explode('.', $key)[0]]['product_variant_id'] = '';
        }
    }

    public function addTemplateItem(): void
    {
        Gate::authorize('manage-catalog');
        if (count($this->templateItems) >= 49) {
            throw ValidationException::withMessages(['templateItems' => 'A quotation supports up to 49 additional lines.']);
        }
        $this->templateItems[] = ['product_id' => '', 'product_variant_id' => '', 'quantity' => 1];
    }

    public function removeTemplateItem(int $index): void
    {
        Gate::authorize('manage-catalog');
        unset($this->templateItems[$index]);
        $this->templateItems = array_values($this->templateItems);
    }

    public function saveTemplate(BuildSubscriptionLines $buildLines): void
    {
        Gate::authorize('manage-catalog');
        $data = $this->validate([
            'template.name' => 'required|string|max:120', 'template.plan_id' => 'required|integer|exists:plans,id',
            'template.product_variant_id' => 'nullable|integer|exists:product_variants,id',
            'template.quantity' => 'required|integer|min:1|max:10000',
            'template.validity_days' => 'required|integer|min:1|max:365',
            'template.payment_terms_days' => 'required|integer|min:0|max:365',
        ])['template'];
        $plan = Plan::with('product')->findOrFail($data['plan_id']);
        if ($data['quantity'] < $plan->minimum_quantity) {
            throw ValidationException::withMessages(['template.quantity' => 'This plan requires at least '.$plan->minimum_quantity.' units.']);
        }
        $data['product_variant_id'] = ($data['product_variant_id'] ?? null) ?: null;
        $lines = $buildLines->handle($plan, (int) $data['quantity'], $data['product_variant_id'] ? (int) $data['product_variant_id'] : null, $this->templateItems);
        DB::transaction(function () use ($data, $lines): void {
            $template = QuotationTemplate::create($data);
            foreach (array_slice($lines, 1) as $line) {
                $template->lines()->create(array_intersect_key($line, array_flip(['product_id', 'product_variant_id', 'quantity', 'position'])));
            }
        });
        $this->reset('template', 'templateItems');
        session()->flash('catalog-success', 'Quotation template created.');
    }

    public function render(): View
    {
        Gate::authorize('manage-catalog');

        return view('livewire.catalog-extensions', [
            'products' => Product::with('variants')->orderBy('name')->limit(200)->get(),
            'plans' => Plan::with('product.variants')->orderBy('name')->limit(200)->get(),
            'discounts' => Discount::with('product')->latest('id')->limit(100)->get(),
            'templates' => QuotationTemplate::with('plan')->withCount('lines')->latest('id')->limit(100)->get(),
            'subscriptions' => Subscription::with('user')->latest('id')->limit(200)->get(),
        ]);
    }
}
