<div>
    <p class="eyebrow">CUSTOMER PORTAL</p>
    <h1 class="mt-2 text-3xl font-semibold tracking-tight">Browse plans</h1>
    <p class="mt-2 text-sm text-slate-500">Choose a plan and start your subscription today.</p>
    @if($errors->any())<div role="alert" class="mt-5 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800"><ul class="list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if($selected)
    <form wire:submit="subscribe" class="panel mt-6 p-6" wire:key="checkout-{{ $selected->id }}">
        <h2 class="text-xl font-semibold">Subscribe to {{ $selected->name }}</h2>
        <p class="mt-2 text-sm text-slate-500">{{ $selected->product->name }} · billed {{ $selected->interval }}</p>
        <div class="mt-5 grid gap-4 sm:grid-cols-2">
            <div><label for="subscribe-quantity" class="label">Quantity</label><input id="subscribe-quantity" type="number" class="input" min="{{ $selected->minimum_quantity }}" max="10000" wire:model.live.debounce.300ms="quantity" required></div>
            <div><label for="subscribe-variant" class="label">Product variant</label><select id="subscribe-variant" class="input" wire:model.live="variantId"><option value="">Standard</option>@foreach($selected->product->variants as $variant)@if($variant->is_active)<option value="{{ $variant->id }}">{{ $variant->name }} ({{ $variant->price_modifier_cents >= 0 ? '+' : '' }}{{ number_format($variant->price_modifier_cents / 100, 2) }} per unit)</option>@endif @endforeach</select></div>
        </div>
        @if($quote)
        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl border border-slate-200 p-5"><h3 class="font-semibold">Your first invoice</h3><dl class="mt-4 flex flex-col gap-3 text-sm">
                <div class="flex justify-between gap-3"><dt>Unit price including variant</dt><dd><x-money :cents="$quote['first']['lines'][0]['unit_price_cents']" /></dd></div>
                <div class="flex justify-between gap-3"><dt>Subtotal · {{ $quantity }} unit(s)</dt><dd><x-money :cents="$quote['first']['subtotal_cents']" /></dd></div>
                <div class="flex justify-between gap-3"><dt>Discount{{ $quote['first']['discount'] ? ' · '.$quote['first']['discount']->name : '' }}</dt><dd>−<x-money :cents="$quote['first']['amount']" /></dd></div>
                <div class="flex justify-between gap-3"><dt>Tax · {{ number_format($selected->product->tax_basis_points / 100, 2) }}%</dt><dd><x-money :cents="$quote['first']['tax_cents']" /></dd></div>
                <div class="flex justify-between gap-3 border-t border-slate-200 pt-3 font-semibold"><dt>First invoice total</dt><dd><x-money :cents="$quote['first']['total_cents']" /></dd></div>
                <div class="flex justify-between gap-3"><dt>Payment due</dt><dd>{{ $quote['due_on']->format('d M Y') }}</dd></div>
            </dl></section>
            <section class="rounded-xl border border-slate-200 p-5"><h3 class="font-semibold">Your next billing period</h3><dl class="mt-4 flex flex-col gap-3 text-sm">
                <div class="flex justify-between gap-3"><dt>Next billing date</dt><dd>{{ $quote['next_billing_on']->format('d M Y') }}</dd></div>
                <div class="flex justify-between gap-3"><dt>Estimated subtotal</dt><dd><x-money :cents="$quote['next']['subtotal_cents']" /></dd></div>
                <div class="flex justify-between gap-3"><dt>Estimated discount</dt><dd>−<x-money :cents="$quote['next']['amount']" /></dd></div>
                <div class="flex justify-between gap-3"><dt>Estimated tax</dt><dd><x-money :cents="$quote['next']['tax_cents']" /></dd></div>
                <div class="flex justify-between gap-3 border-t border-slate-200 pt-3 font-semibold"><dt>Expected next payment · estimate</dt><dd><x-money :cents="$quote['next']['total_cents']" /></dd></div>
                <div class="flex justify-between gap-3"><dt>Expected payment due</dt><dd>{{ $quote['next_billing_on']->addDays(14)->format('d M Y') }}</dd></div>
            </dl><p class="mt-4 text-xs leading-5 text-slate-500">Future amounts are estimates. Discount dates and remaining uses are checked again when billing runs. This estimate allows for one discount use on your first invoice.</p></section>
        </div>
        @else<p role="alert" class="mt-5 text-sm text-rose-700">Choose a valid quantity and available variant to review your price.</p>@endif
        <div class="mt-5 rounded-xl bg-slate-50 p-4 text-sm leading-6 text-slate-600">
            <p>Your subscription starts today and repeats {{ $selected->interval }}. Arrange payment with the seller.</p>
            <p>{{ $selected->can_close ? 'You can close this subscription in your portal. Closing stops future recurring billing. Existing invoices remain payable, and closing does not issue a refund.' : 'Early closure is unavailable for this plan. Contact the seller to discuss cancellation.' }}</p>
            <p>{{ $selected->can_pause ? 'Pausing is allowed. Billing stops while paused and resumes without billing the paused interval.' : 'Pausing is unavailable for this plan.' }} Contact the seller to request a plan change.</p>
        </div>
        <label class="mt-5 flex items-start gap-2 text-sm"><input type="checkbox" class="mt-1 accent-blue-600" wire:model="accepted" required><span>I agree to start this recurring subscription at the price and billing frequency shown.</span></label>
        <div class="mt-5 flex flex-wrap gap-3"><button class="btn" wire:loading.attr="disabled" @disabled(!$quote)>Confirm subscription</button><button type="button" class="btn-secondary" wire:click="$set('planId',null)">Cancel</button></div>
    </form>
    @endif
    <div class="mt-6"><label for="plan-search" class="sr-only">Search plans</label><input id="plan-search" class="input sm:max-w-sm" placeholder="Search plans…" wire:model.live.debounce.300ms="search"></div>
    <div class="mt-6 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
        @forelse($plans as $plan)
        <article class="panel flex flex-col p-6" wire:key="plan-{{ $plan->id }}">
            <p class="eyebrow">{{ $plan->product->type }}</p><h2 class="mt-3 text-xl font-semibold">{{ $plan->name }}</h2>
            <p class="mt-2 text-sm text-slate-500">{{ $plan->product->name }}</p>
            <p class="mt-5 text-2xl font-semibold"><x-money :cents="$plan->price_cents" /></p>
            <p class="mt-1 text-sm text-slate-500">per unit, billed {{ $plan->interval }}, before tax</p>
            <p class="mt-4 text-sm text-slate-600">Minimum {{ $plan->minimum_quantity }} unit(s) · {{ number_format($plan->product->tax_basis_points / 100, 2) }}% tax</p>
            <p class="mt-2 text-xs text-slate-500">{{ $plan->can_pause ? 'Pause allowed' : 'Pausing unavailable' }} · {{ $plan->can_close ? 'Early closure allowed' : 'Early closure unavailable' }}</p>
            <button class="btn mt-6" wire:click="choosePlan({{ $plan->id }})" wire:loading.attr="disabled">Subscribe</button>
        </article>
        @empty<p class="panel col-span-full p-10 text-center text-slate-500">No available plans found. Please check again later.</p>@endforelse
    </div>
    <div class="mt-6">{{ $plans->links() }}</div>
</div>
