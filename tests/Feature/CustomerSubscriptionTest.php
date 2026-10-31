<?php

namespace Tests\Feature;

use App\Livewire\BrowsePlans;
use App\Models\Discount;
use App\Models\Plan;
use App\Models\ProductVariant;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerSubscriptionTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->freezeTime();
    }

    public function test_customer_can_find_plans_and_subscribe_with_correct_pricing_and_ownership(): void
    {
        $customer = User::factory()->create();
        $plan = Plan::factory()->create(['minimum_quantity' => 2]);
        $variant = ProductVariant::factory()->for($plan->product)->create(['price_modifier_cents' => 56000]);
        Discount::factory()->create(['value' => 1000]);
        $this->actingAs($customer)->get('/subscriptions')->assertOk()->assertSee('Browse Plans');
        Livewire::actingAs($customer)->test(BrowsePlans::class)
            ->call('choosePlan', $plan->id)->assertSet('quantity', 2)
            ->set('variantId', (string) $variant->id)->set('accepted', true)
            ->call('subscribe')->assertHasNoErrors()->assertRedirect(route('subscriptions'));
        $subscription = Subscription::with('lines', 'invoices')->firstOrFail();
        $this->assertSame($customer->id, $subscription->user_id);
        $this->assertSame('active', $subscription->status);
        $this->assertSame(306000, $subscription->unit_price_cents);
        $this->assertSame(1, $subscription->lines->count());
        $this->assertSame(649944, $subscription->invoices->first()->total_cents);
        $this->assertSame('confirmed', $subscription->invoices->first()->status);
        $this->assertSame(today()->addDays(14)->toDateString(), $subscription->invoices->first()->due_on->toDateString());
    }

    public function test_repeated_submissions_do_not_duplicate_subscriptions_or_invoices(): void
    {
        $customer = User::factory()->create();
        $plan = Plan::factory()->create();
        $component = Livewire::actingAs($customer)->test(BrowsePlans::class)->call('choosePlan', $plan->id)->set('accepted', true);
        $component->call('subscribe')->assertHasNoErrors();
        $component->call('subscribe')->assertHasErrors('planId');
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_unavailable_plans_are_hidden_and_rechecked_at_submission(): void
    {
        $plan = Plan::factory()->create();
        $expired = Plan::factory()->create(['available_until' => today()->subDay()]);
        $future = Plan::factory()->create(['available_from' => today()->addDay()]);
        $component = Livewire::actingAs(User::factory()->create())->test(BrowsePlans::class)
            ->assertSee($plan->name)->assertDontSee($expired->name)->assertDontSee($future->name)
            ->call('choosePlan', $plan->id)->set('accepted', true);
        $plan->update(['available_until' => today()->subDay()]);
        $component->call('subscribe')->assertHasErrors('planId');
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_consent_minimum_quantity_and_variant_ownership_are_enforced(): void
    {
        $plan = Plan::factory()->create(['minimum_quantity' => 3]);
        $foreign = ProductVariant::factory()->create();
        $component = Livewire::actingAs(User::factory()->create())->test(BrowsePlans::class)->call('choosePlan', $plan->id);
        $component->call('subscribe')->assertHasErrors('accepted');
        $component->set('quantity', '')->set('accepted', true)->call('subscribe')->assertHasErrors('quantity');
        $component->set('quantity', 1)->set('accepted', true)->call('subscribe')->assertHasErrors('quantity');
        $component->set('quantity', 3)->set('variantId', (string) $foreign->id)->set('accepted', true)
            ->call('subscribe')->assertHasErrors('items');
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_staff_cannot_use_customer_checkout_and_guests_must_login(): void
    {
        $this->get('/plans')->assertRedirect(route('login'));
        Livewire::actingAs(User::factory()->create(['role' => 'staff']))->test(BrowsePlans::class)->assertForbidden();
    }

    public function test_one_customers_subscription_does_not_block_another_customer(): void
    {
        $plan = Plan::factory()->create();
        Subscription::factory()->for($plan)->create();
        Livewire::actingAs(User::factory()->create())->test(BrowsePlans::class)->call('choosePlan', $plan->id)
            ->set('accepted', true)->call('subscribe')->assertHasNoErrors();
        $this->assertDatabaseCount('subscriptions', 2);
    }
}
