<div wire:poll.30s>
    <p class="eyebrow">BUSINESS WORKSPACE</p>
    <h1 class="mt-2 text-3xl font-semibold tracking-tight">Billing operations</h1>
    <p class="mt-2 text-sm text-slate-500">Review billing alerts and the history of important changes. Automatic checks run every five minutes.</p>
    @if(session('success'))<p role="status" class="mt-5 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('success') }}</p>@endif
    @error('resolutionNote')<p role="alert" class="mt-4 text-sm text-rose-700">{{ $message }}</p>@enderror
    <section class="panel mt-7 p-5">
        <div class="flex flex-wrap items-center justify-between gap-4"><h2 class="text-lg font-semibold">Billing alerts</h2><div><label for="issue-status" class="sr-only">Issue status</label><select id="issue-status" class="input" wire:model.live="status"><option value="open">Open issues</option><option value="resolved">Resolved issues</option></select></div></div>
        <p class="mt-2 text-sm text-slate-500">Corrected conditions resolve automatically. An unresolved condition reopens on the next check.</p>
        @if($status !== 'resolved')<div class="mt-4"><label for="resolution-note" class="label">Resolution note</label><input id="resolution-note" class="input" maxlength="1000" wire:model="resolutionNote" placeholder="Describe the investigation or correction before resolving an issue."></div>@endif
        <div class="mt-5 space-y-4">
            @forelse($issues as $issue)
            <article wire:key="issue-{{ $issue->id }}" class="rounded-xl border border-slate-200 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><h3 class="font-semibold">{{ $issue->title }}</h3><p class="mt-1 text-xs text-slate-500">{{ $issue->subject_type }} #{{ $issue->subject_id }} · {{ ucfirst($issue->status) }}</p></div>@if($issue->status === 'open')<button class="btn-secondary" wire:click="resolve({{ $issue->id }})" wire:loading.attr="disabled">Resolve issue</button>@endif</div>
                <p class="mt-3 text-sm text-slate-600">{{ $issue->detail }}</p>
                <p class="mt-3 text-xs text-slate-400">First detected {{ $issue->first_detected_at->format('d M Y H:i') }} · Last detected {{ $issue->last_detected_at->format('d M Y H:i') }}</p>
                @if($issue->resolved_at)<p class="mt-2 text-xs text-slate-500">Resolved {{ $issue->resolved_at->format('d M Y H:i') }} · {{ $issue->resolution_note }}</p>@endif
            </article>
            @empty<p class="py-8 text-center text-sm text-slate-500">No {{ $status === 'resolved' ? 'resolved' : 'open' }} billing issues.</p>@endforelse
        </div>
        <div class="mt-5">{{ $issues->links() }}</div>
    </section>
    <section class="panel mt-7 overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-4 p-5"><div><h2 class="text-lg font-semibold">Activity history</h2><p class="mt-1 text-xs text-slate-500">Recorded from this update onward. History is read-only.</p></div><div><label for="activity-search" class="sr-only">Search actor, record type or action</label><input id="activity-search" class="input" placeholder="Search actor, record type or action…" wire:model.live.debounce.350ms="search"></div></div>
        <div class="overflow-x-auto"><table class="data-table"><thead><tr><th>When</th><th>Who</th><th>Record</th><th>Action</th><th>Changes</th></tr></thead><tbody>
        @forelse($activities as $activity)
        <tr wire:key="activity-{{ $activity->id }}"><td class="whitespace-nowrap">{{ $activity->created_at->format('d M Y H:i:s') }}</td><td>{{ $activity->actor_name }}<p class="mt-1 text-xs text-slate-400">{{ ucfirst($activity->actor_role) }}{{ $activity->actor_id ? ' #'.$activity->actor_id : '' }}</p></td><td>{{ $activity->subject_type }} #{{ $activity->subject_id }}</td><td>{{ ucfirst($activity->action) }}</td><td><details><summary class="link">View before and after</summary><div class="mt-3 grid min-w-64 gap-3 text-xs"><div><p class="font-semibold">Before</p><pre class="mt-1 whitespace-pre-wrap break-all">{{ json_encode($activity->before_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div><div><p class="font-semibold">After</p><pre class="mt-1 whitespace-pre-wrap break-all">{{ json_encode($activity->after_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div></div></details></td></tr>
        @empty<tr><td colspan="5" class="!py-10 text-center text-slate-500">No matching activity recorded.</td></tr>@endforelse
        </tbody></table></div><div class="p-5">{{ $activities->links() }}</div>
    </section>
</div>
