<div class="panel overflow-hidden">
    <div class="border-b border-slate-100 p-5"><h2 class="font-semibold">Subscription register <span class="ml-2 text-xs font-normal text-slate-400">{{ $subscriptions->total() }} total</span></h2></div>
    <div class="overflow-x-auto"><table class="data-table"><thead><tr><th>Subscription / Customer</th><th>Plan & products</th><th>Recurring subtotal</th><th>Next billing</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    @forelse($subscriptions as $item)
    <tr wire:key="subscription-{{ $item->id }}">
        <td><p class="font-semibold text-slate-800">{{ $item->number() }}</p><p class="mt-1 text-xs text-slate-500">{{ $item->user->name }}</p>@if($item->quotation_expires_on && in_array($item->status,['draft','quotation']))<p class="mt-1 text-xs text-amber-700">Quote valid through {{ $item->quotation_expires_on->format('d M Y') }}</p>@endif</td>
        <td><p class="font-medium">{{ $item->plan->name }}</p><p class="mt-1 text-xs capitalize text-slate-400">{{ $item->billing_interval }} · starts {{ $item->starts_on->format('d M Y') }}</p>
            @if($item->lines->isNotEmpty())<details class="mt-2 max-w-sm"><summary class="text-xs text-blue-600">{{ $item->lines->count() }} product line(s)</summary><ul class="mt-2 flex flex-col gap-2 text-xs text-slate-500">@foreach($item->lines as $line)<li>{{ $line->description }}{{ $line->variant_name ? ' · '.$line->variant_name : '' }}<br>{{ $line->quantity }} × <x-money :cents="$line->unit_price_cents" /> · {{ number_format($line->tax_basis_points / 100, 2) }}% tax</li>@endforeach</ul></details>@endif
        </td>
        <td class="whitespace-nowrap"><x-money :cents="$item->lines->isEmpty() ? $item->quantity * $item->unit_price_cents : $item->lines->sum(fn ($line) => $line->quantity * $line->unit_price_cents)" /><p class="mt-1 text-xs text-slate-400">Before discounts & tax</p></td>
        <td class="whitespace-nowrap"><p>{{ $item->next_billing_on->format('d M Y') }}</p>@if($item->expires_on)<p class="mt-1 text-xs text-slate-400">Expires {{ $item->expires_on->format('d M Y') }}</p>@endif</td>
        <td><x-status :status="$item->status" /></td>
        <td><div class="flex flex-wrap items-center gap-x-3 gap-y-2">
            @if($team && in_array($item->status,['draft','quotation','confirmed']))<button wire:click="changeSubscriptionStatus({{ $item->id }}, 'advance')" wire:loading.attr="disabled" class="link">{{ ['draft'=>'Quote','quotation'=>'Confirm','confirmed'=>'Activate'][$item->status] }} →</button>@endif
            @if($item->status === 'active' && $item->plan->can_pause)@can('transition',[$item,'pause'])<button wire:click="changeSubscriptionStatus({{ $item->id }}, 'pause')" wire:loading.attr="disabled" wire:confirm="Pause this subscription? Any billing periods already due will remain payable." class="link">Pause</button>@endcan @endif
            @if($item->status === 'paused' && ($team || $item->plan->can_pause))@can('transition',[$item,'resume'])<button wire:click="changeSubscriptionStatus({{ $item->id }}, 'resume')" wire:loading.attr="disabled" class="link">Resume</button>@endcan @endif
            @if(in_array($item->status,['active','paused']) && $item->plan->can_close)@can('transition',[$item,'close'])<button wire:click="changeSubscriptionStatus({{ $item->id }}, 'close')" wire:loading.attr="disabled" wire:confirm="Close this subscription? Future recurring billing will stop. Any billing periods already due remain payable." class="text-xs font-medium text-slate-500">Close</button>@endcan @endif
            @if(in_array($item->status,['active','paused','closed']) && $item->plan->can_renew && ($item->expires_on || $item->status === 'closed'))@can('transition',[$item,'renew'])<button wire:click="changeSubscriptionStatus({{ $item->id }}, 'renew')" wire:loading.attr="disabled" wire:confirm="Renew this subscription for another billing period?" class="link">Renew</button>@endcan @endif
        </div></td>
    </tr>
    @empty<tr><td colspan="6" class="!py-16 text-center"><p class="font-medium text-slate-700">No subscriptions found</p><p class="mt-2 text-sm text-slate-400">{{ $team ? 'Create a customer account and a plan to get started.' : 'Your subscriptions will appear here when your team sets them up.' }}</p></td></tr>@endforelse
    </tbody></table></div><div class="border-t border-slate-100 px-5 py-4">{{ $subscriptions->links() }}</div>
</div>
