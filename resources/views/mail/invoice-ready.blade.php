<x-mail::message>
# Invoice {{ $invoice->number() }}

Hello {{ $invoice->snapshot['customer'] }},

Your {{ $invoice->snapshot['plan'] }} invoice for {{ $invoice->period_start->format('d M Y') }} is ready.

**Total: {{ $invoice->currency }} {{ number_format($invoice->total_cents / 100, 2) }}**  
Due on {{ $invoice->due_on->format('d M Y') }}.

<x-mail::button :url="route('invoices.show', $invoice)">View invoice</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
