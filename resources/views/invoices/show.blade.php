<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $invoice->number() }} · Brightloop</title>@vite(['resources/css/app.css','resources/js/app.js'])</head>
<body class="bg-slate-50 p-5 md:p-10">
<div class="no-print mx-auto mb-6 flex max-w-5xl justify-between"><a href="{{ route('invoices') }}" class="btn-secondary">← Back to invoices</a><button onclick="window.print()" class="btn">Print invoice</button></div>
<main class="print-sheet panel mx-auto max-w-5xl p-6 md:p-12">
    <div class="flex flex-wrap justify-between gap-6"><x-brand class="text-2xl" /><div class="text-right"><p class="eyebrow">INVOICE</p><h1 class="mt-1 text-2xl font-semibold">{{ $invoice->number() }}</h1><div class="mt-3"><x-status :status="$invoice->status" /></div></div></div>
    <div class="mt-12 grid grid-cols-2 gap-6 border-t border-slate-100 pt-8"><div><p class="eyebrow">BILL TO</p><p class="mt-3 font-semibold">{{ $invoice->snapshot['customer'] ?? 'Customer' }}</p><p class="mt-1 text-sm text-slate-500">{{ $invoice->snapshot['email'] ?? '' }}</p></div><div class="text-right text-sm"><p><span class="text-slate-400">Billing period</span> · {{ $invoice->period_start->format('d M Y') }}</p><p class="mt-2"><span class="text-slate-400">Due date</span> · {{ $invoice->due_on->format('d M Y') }}</p><p class="mt-2 text-slate-400">{{ $invoice->subscription->number() }}</p></div></div>
    <div class="mt-10 overflow-x-auto">
        <table class="data-table"><thead><tr><th>Description</th><th>Qty</th><th>Unit price</th><th>Discount</th><th>Tax</th><th>Total</th></tr></thead><tbody>
        @forelse($invoice->lines as $line)
            <tr><td><p class="font-medium">{{ $line->description }}</p><p class="mt-1 text-xs text-slate-400">{{ $line->variant_name ?? '' }}</p></td><td>{{ $line->quantity }}</td><td class="whitespace-nowrap"><x-money :cents="$line->unit_price_cents" :currency="$invoice->currency" /></td><td class="whitespace-nowrap"><x-money :cents="$line->discount_cents" :currency="$invoice->currency" /></td><td><x-money :cents="$line->tax_cents" :currency="$invoice->currency" /><span class="mt-1 block text-xs text-slate-400">{{ number_format($line->tax_basis_points / 100, 2) }}%</span></td><td class="whitespace-nowrap"><x-money :cents="$line->total_cents" :currency="$invoice->currency" /></td></tr>
        @empty
            <tr><td><p class="font-medium">{{ $invoice->snapshot['product'] ?? 'Subscription' }}</p><p class="mt-1 text-xs text-slate-400">{{ $invoice->snapshot['plan'] ?? '' }}</p></td><td>{{ $invoice->snapshot['quantity'] ?? 1 }}</td><td><x-money :cents="$invoice->snapshot['unit_price_cents'] ?? $invoice->subtotal_cents" :currency="$invoice->currency" /></td><td><x-money :cents="$invoice->discount_cents ?? 0" :currency="$invoice->currency" /></td><td><x-money :cents="$invoice->tax_cents" :currency="$invoice->currency" /></td><td><x-money :cents="$invoice->total_cents" :currency="$invoice->currency" /></td></tr>
        @endforelse
        </tbody></table>
    </div>
    <dl class="mt-8 ml-auto flex max-w-sm flex-col gap-3 text-sm">
        <div class="flex justify-between"><dt class="text-slate-500">Subtotal</dt><dd><x-money :cents="$invoice->subtotal_cents" :currency="$invoice->currency" /></dd></div>
        @if($invoice->discount_cents > 0)<div class="flex justify-between gap-4 text-emerald-700"><dt>Discount · {{ $invoice->snapshot['discount']['name'] ?? 'Automatic discount' }}</dt><dd class="shrink-0">−<x-money :cents="$invoice->discount_cents" :currency="$invoice->currency" /></dd></div>@endif
        <div class="flex justify-between"><dt class="text-slate-500">Tax (calculated per line)</dt><dd><x-money :cents="$invoice->tax_cents" :currency="$invoice->currency" /></dd></div>
        <div class="flex justify-between border-t border-slate-200 pt-4 text-lg font-semibold"><dt>Total</dt><dd><x-money :cents="$invoice->total_cents" :currency="$invoice->currency" /></dd></div>
        <div class="flex justify-between"><dt class="text-slate-500">Payments received</dt><dd><x-money :cents="$invoice->payments_sum_amount_cents ?? 0" :currency="$invoice->currency" /></dd></div>
        <div class="flex justify-between rounded-lg bg-slate-50 p-3 font-semibold"><dt>{{ $invoice->status === 'cancelled' ? 'Amount due (cancelled)' : 'Balance due' }}</dt><dd><x-money :cents="$invoice->status === 'cancelled' ? 0 : $invoice->balanceCents()" :currency="$invoice->currency" /></dd></div>
    </dl>
    @if($invoice->payments->isNotEmpty())
    <h2 class="mt-12 font-semibold">Payment history</h2><div class="mt-4 overflow-x-auto"><table class="data-table"><thead><tr><th>Date</th><th>Method</th><th>Reference</th><th>Amount</th></tr></thead><tbody>@foreach($invoice->payments as $payment)<tr><td>{{ $payment->paid_on->format('d M Y') }}</td><td>{{ ucwords(str_replace('_',' ',$payment->method)) }}</td><td>{{ $payment->note ?? '—' }}</td><td><x-money :cents="$payment->amount_cents" :currency="$invoice->currency" /></td></tr>@endforeach</tbody></table></div>
    @endif
    <p class="mt-12 border-t border-slate-100 pt-6 text-xs leading-6 text-slate-400">Thank you for choosing Brightloop. Please quote {{ $invoice->number() }} when making a payment. Contact your account team with any billing questions.</p>
</main></body></html>
