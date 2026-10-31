<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SubscriptionPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function permissionMatrix(): array
    {
        return [
            'admin' => ['admin', false, [true, true, true, true, true, false]],
            'staff' => ['staff', false, [true, true, true, true, true, false]],
            'customer owner' => ['customer', true, [false, true, true, true, true, false]],
            'other customer' => ['customer', false, [false, false, false, false, false, false]],
            'unknown role' => ['unknown', true, [false, false, false, false, false, false]],
        ];
    }

    #[DataProvider('permissionMatrix')]
    public function test_transition_permissions_depend_on_role_ownership_and_action(string $role, bool $owns, array $expected): void
    {
        $actor = User::factory()->create(['role' => $role]);
        $subscription = Subscription::factory()->create(['user_id' => $owns ? $actor->id : User::factory()->create()->id]);
        $actual = [];
        foreach (['advance', 'pause', 'resume', 'renew', 'close', 'invalid'] as $action) {
            $actual[] = Gate::forUser($actor)->allows('transition', [$subscription, $action]);
        }
        $this->assertSame($expected, $actual);
    }
}
