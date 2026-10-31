<?php

namespace App\Livewire;

use App\Actions\CreateCustomerSubscription;
use App\Actions\PreviewSubscriptionPrice;
use App\Models\Plan;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class BrowsePlans extends Component
{
    use WithPagination;

    public ?int $planId = null;

    public int|string|null $quantity = 1;

    public string $variantId = '';

    public bool $accepted = false;

    #[Locked]
    public ?string $quoteFingerprint = null;

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
        try {
            $subscription = $create->handle(Auth::user(), $this->planId, (int) $this->quantity, $this->variantId === '' ? null : (int) $this->variantId, $this->quoteFingerprint);
        } catch (ValidationException $exception) {
            $this->accepted = false;
            throw $exception;
        }
        session()->flash('success', 'Subscription '.$subscription->number().' is active. Your first invoice is ready under Invoices.');
        $this->redirectRoute('subscriptions', navigate: true);
    }

    public function render(): View
    {
        abort_unless(Auth::user()->role === 'customer', 403);
        $selected = $this->planId ? Plan::available()->with('product.variants')->find($this->planId) : null;
        $quote = null;
        if ($selected) {
            try {
                $quote = app(PreviewSubscriptionPrice::class)->handle($selected,
                    max(1, min(10000, (int) $this->quantity)), $this->variantId === '' ? null : (int) $this->variantId);
            } catch (ValidationException) {
                $quote = null;
            }
        }
        if ($quote && $this->quoteFingerprint !== null && $this->quoteFingerprint !== $quote['fingerprint']) {
            $this->accepted = false;
        }
        $this->quoteFingerprint = $quote['fingerprint'] ?? null;

        return view('livewire.browse-plans', [
            'plans' => Plan::available()->with('product')->whereLike('name', '%'.mb_substr($this->search, 0, 120).'%')->orderBy('name')->orderBy('id')->paginate(12),
            'selected' => $selected, 'quote' => $quote,
        ])->layout('layouts.workspace');
    }
}
