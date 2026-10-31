<x-mail::message>
# {{ $title }}

Hello {{ $greeting }},

{{ $intro }}

<table width="100%" cellpadding="8" cellspacing="0">
@foreach($details as $label => $value)
<tr><th align="left">{{ $label }}</th><td align="right">{{ $value }}</td></tr>
@endforeach
</table>

<x-mail::button :url="$url">View in your portal</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
