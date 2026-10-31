<?php

namespace App\Livewire;

use App\Models\Activity;
use App\Models\BillingIssue;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

class BillingOperations extends Component
{
    use WithPagination;

    public string $status = 'open';

    public string $search = '';

    public string $resolutionNote = '';

    public function mount(): void
    {
        Gate::authorize('manage-billing');
    }

    public function updatedStatus(): void
    {
        $this->resetPage('issuesPage');
    }

    public function updatedSearch(): void
    {
        $this->resetPage('historyPage');
    }

    public function resolve(int $id): void
    {
        Gate::authorize('manage-billing');
        $this->validate(['resolutionNote' => 'required|string|min:3|max:1000']);
        DB::transaction(function () use ($id): void {
            $issue = BillingIssue::lockForUpdate()->findOrFail($id);
            if ($issue->status === 'open') {
                $issue->update(['status' => 'resolved', 'resolved_at' => now(), 'resolution_note' => $this->resolutionNote]);
            }
        });
        $this->reset('resolutionNote');
        session()->flash('success', 'Issue resolved. An ongoing condition will reopen on the next check.');
    }

    public function render(): View
    {
        Gate::authorize('manage-billing');

        return view('livewire.billing-operations', [
            'issues' => BillingIssue::where('status', $this->status === 'resolved' ? 'resolved' : 'open')
                ->orderByDesc('last_detected_at')->orderByDesc('id')->paginate(15, pageName: 'issuesPage'),
            'activities' => Activity::where(function ($query): void {
                $term = '%'.mb_substr($this->search, 0, 120).'%';
                $query->whereLike('actor_name', $term)->orWhereLike('subject_type', $term)->orWhereLike('action', $term);
            })->latest('id')->paginate(20, pageName: 'historyPage'),
        ])->layout('layouts.workspace');
    }
}
