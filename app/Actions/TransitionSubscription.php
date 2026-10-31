<?php

namespace App\Actions;

use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TransitionSubscription
{
    public function handle(User $actor, Subscription $subscription, string $action): void
    {
        Gate::forUser($actor)->authorize('manage-billing');
        DB::transaction(function () use ($subscription, $action): void {
            $subscription = Subscription::with('plan')->lockForUpdate()->findOrFail($subscription->id);
            $next = ['draft' => 'quotation', 'quotation' => 'confirmed', 'confirmed' => 'active'];
            $status = $subscription->status;
            $changes = [];
            if ($action === 'advance' && isset($next[$status])) {
                if ($next[$status] === 'active' && $subscription->expires_on?->lte(CarbonImmutable::today())) {
                    throw ValidationException::withMessages(['subscription' => 'Extend the expiry date before activating this subscription.']);
                }
                $changes['status'] = $next[$status];
            } elseif ($action === 'pause' && $status === 'active' && $subscription->plan->can_pause) {
                if ($subscription->next_billing_on->lte(CarbonImmutable::today())) {
                    throw ValidationException::withMessages(['subscription' => 'Generate all due invoices before pausing this subscription.']);
                }
                $changes['status'] = 'paused';
            } elseif ($action === 'resume' && $status === 'paused') {
                if ($subscription->expires_on?->lte(CarbonImmutable::today())) {
                    throw ValidationException::withMessages(['subscription' => 'Renew the subscription before resuming.']);
                }
                $changes = ['status' => 'active', 'next_billing_on' => max($subscription->next_billing_on, CarbonImmutable::today())];
            } elseif ($action === 'close' && in_array($status, ['active', 'paused'], true) && $subscription->plan->can_close) {
                if ($status === 'active' && $subscription->next_billing_on->lte(CarbonImmutable::today())) {
                    throw ValidationException::withMessages(['subscription' => 'Generate all due invoices before closing this subscription.']);
                }
                $changes['status'] = 'closed';
            } elseif ($action === 'renew' && in_array($status, ['active', 'paused', 'closed'], true) && $subscription->plan->can_renew) {
                if (! $subscription->expires_on && $status !== 'closed') {
                    throw ValidationException::withMessages(['subscription' => 'This subscription has no expiry date and already renews continuously.']);
                }
                if ($subscription->expires_on?->lte(CarbonImmutable::today())
                    && $subscription->next_billing_on->lt($subscription->expires_on) && $status === 'active') {
                    throw ValidationException::withMessages(['subscription' => 'Generate all due invoices before renewing this expired subscription.']);
                }
                $base = max($subscription->expires_on ?? $subscription->starts_on, CarbonImmutable::today());
                $expires = match ($subscription->billing_interval) {
                    'daily' => $base->addDay(), 'weekly' => $base->addWeek(),
                    'monthly' => $base->addMonthNoOverflow(), 'yearly' => $base->addYearNoOverflow(),
                };
                $changes['expires_on'] = $subscription->expires_on ? $expires : null;
                if ($subscription->expires_on?->lte(CarbonImmutable::today())) {
                    $changes['next_billing_on'] = max($subscription->next_billing_on, CarbonImmutable::today());
                }
                if ($status === 'closed') {
                    $changes['status'] = 'active';
                    $changes['next_billing_on'] = max($subscription->next_billing_on, CarbonImmutable::today());
                }
            } else {
                throw ValidationException::withMessages(['subscription' => 'This action is not available for this subscription.']);
            }
            $subscription->update($changes);
        }, 3);
    }
}
