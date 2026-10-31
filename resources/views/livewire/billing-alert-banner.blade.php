<div wire:poll.30s>
    @if($count > 0)
    <a href="{{ route('operations') }}" wire:navigate class="mb-6 block rounded-xl border border-amber-300 bg-amber-50 px-5 py-4 text-sm font-medium text-amber-900" role="status">{{ $count }} open billing issue(s). Review Billing operations →</a>
    @endif
</div>
