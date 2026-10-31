<x-mail::message>
# Invoice {{ $invoice->number() }}

Hello {{ $invoice->snapshot['customer'] }},

Your {{ $invoice->snapshot['plan'] }} invoice for {{ $invoice->period_start->format('d M Y') }} is ready.

@if(!empty($invoice->snapshot['lines']))
<x-mail::table>
| Product | Quantity | Total |
| :-- | --: | --: |
@foreach($invoice->snapshot['lines'] as $line)
| {{ $line['description'] }}{{ $line['variant_name'] ? ' · '.$line['variant_name'] : '' }} | {{ $line['quantity'] }} | {{ $invoice->currency }} {{ number_format($line['total_cents'] / 100, 2) }} |
@endforeach
</x-mail::table>
@endif

@if($invoice->discount_cents > 0)
Discount: {{ $invoice->snapshot['discount']['name'] ?? 'Automatic discount' }} · {{ $invoice->currency }} {{ number_format($invoice->discount_cents / 100, 2) }}
@endif

**Total: {{ $invoice->currency }} {{ number_format($invoice->total_cents / 100, 2) }}**  
Due on {{ $invoice->due_on->format('d M Y') }}.

<x-mail::button :url="route('invoices.show', $invoice)">View invoice</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
