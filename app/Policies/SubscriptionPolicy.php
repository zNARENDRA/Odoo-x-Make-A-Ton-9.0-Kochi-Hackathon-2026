<?php

namespace App\Policies;

use App\Models\Subscription;
use App\Models\User;

class SubscriptionPolicy
{
    public function transition(User $user, Subscription $subscription, string $action): bool
    {
        if ($user->isTeamMember()) {
            return in_array($action, ['advance', 'pause', 'resume', 'renew', 'close'], true);
        }

        return $user->role === 'customer' && $subscription->user_id === $user->id
            && in_array($action, ['pause', 'resume', 'renew', 'close'], true);
    }
}
