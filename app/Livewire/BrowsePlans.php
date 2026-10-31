<?php

namespace App\Livewire;

use App\Actions\CreateCustomerSubscription;
use App\Models\Plan;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class BrowsePlans extends Component
{
    use WithPagination;

    public ?int $planId = null;

    public int|string|null $quantity = 1;

    public string $variantId = '';

    public bool $accepted = false;

    public string $search = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->role === 'customer', 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function choosePlan(int $id): void
    {
        abort_unless(Auth::user()->role === 'customer', 403);
        $plan = Plan::available()->findOrFail($id);
        $this->reset('variantId', 'accepted');
        $this->planId = $plan->id;
        $this->quantity = $plan->minimum_quantity;
        $this->resetValidation();
    }

    public function updatedQuantity(): void
    {
        $this->accepted = false;
    }

    public function updatedVariantId(): void
    {
        $this->accepted = false;
    }

    public function subscribe(CreateCustomerSubscription $create): void
    {
        abort_unless(Auth::user()->role === 'customer', 403);
        $this->validate([
            'planId' => 'required|integer', 'quantity' => 'required|integer|min:1|max:10000',
            'variantId' => 'nullable|integer', 'accepted' => 'accepted',
        ]);
        $subscription = $create->handle(Auth::user(), $this->planId, (int) $this->quantity, $this->variantId === '' ? null : (int) $this->variantId);
        session()->flash('success', 'Subscription '.$subscription->number().' is active. Your first invoice is ready under Invoices.');
        $this->redirectRoute('subscriptions', navigate: true);
    }

    public function render(): View
    {
        abort_unless(Auth::user()->role === 'customer', 403);
        $selected = $this->planId ? Plan::available()->with('product.variants')->find($this->planId) : null;
        $variant = $selected?->product?->variants->firstWhere('id', $this->variantId);
        $unitPrice = $selected ? (int) $selected->price_cents + (int) ($variant?->price_modifier_cents ?? 0) : 0;
        $subtotal = max(0, $unitPrice) * max(1, min(10000, (int) $this->quantity));
        $tax = intdiv($subtotal * (int) ($selected?->product?->tax_basis_points ?? 0) + 5000, 10000);

        return view('livewire.browse-plans', [
            'plans' => Plan::available()->with('product')->whereLike('name', '%'.mb_substr($this->search, 0, 120).'%')->orderBy('name')->orderBy('id')->paginate(12),
            'selected' => $selected, 'subtotal' => $subtotal, 'tax' => $tax,
        ])->layout('layouts.workspace');
    }
}
