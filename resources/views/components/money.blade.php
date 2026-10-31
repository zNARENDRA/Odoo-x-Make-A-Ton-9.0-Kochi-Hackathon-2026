@props(['cents', 'currency' => null])
<span class="tabular-nums">{{ $currency ?? config('billing.currency') }} {{ number_format($cents / 100, 2) }}</span>
