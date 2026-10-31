<?php

namespace App\Livewire;

use App\Models\BillingIssue;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class BillingAlertBanner extends Component
{
    public function render(): View
    {
        Gate::authorize('manage-billing');

        return view('livewire.billing-alert-banner', ['count' => BillingIssue::where('status', 'open')->count()]);
    }
}
