@props(['status'])
@php
$color = match($status) {
    'active', 'paid' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/15',
    'confirmed' => 'bg-blue-50 text-blue-700 ring-blue-600/15',
    'quotation', 'paused' => 'bg-amber-50 text-amber-700 ring-amber-600/15',
    'overdue' => 'bg-rose-50 text-rose-700 ring-rose-600/15',
    default => 'bg-slate-100 text-slate-600 ring-slate-600/10',
};
@endphp
<span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset {{ $color }}"><span class="size-1.5 rounded-full bg-current"></span>{{ ucfirst($status) }}</span>
