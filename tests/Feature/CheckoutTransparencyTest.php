<?php

namespace Tests\Feature;

use App\Actions\CreateCustomerSubscription;
use App\Actions\PreviewSubscriptionPrice;
use App\Livewire\BrowsePlans;
use App\Models\Discount;
use App\Models\DiscountRedemption;
use App\Models\Plan;
use App\Models\ProductVariant;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CheckoutTransparencyTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(12, 0));
    }

    public function test_preview_matches_the_first_invoice_and_does_not_consume_discount_uses(): void
    {
        $plan = Plan::factory()->create();
        $variant = ProductVariant::factory()->for($plan->product)->create(['price_modifier_cents' => 56000]);
        $discount = Discount::factory()->create(['value' => 1000, 'max_uses' => 2]);
        $quote = app(PreviewSubscriptionPrice::class)->handle($plan, 2, $variant->id);
        $this->assertSame(612000, $quote['first']['subtotal_cents']);
        $this->assertSame(61200, $quote['first']['amount']);
        $this->assertSame(99144, $quote['first']['tax_cents']);
        $this->assertSame(649944, $quote['first']['total_cents']);
        $this->assertSame('2026-11-30', $quote['next_billing_on']->toDateString());
        $this->assertSame(0, $discount->fresh()->uses_count);
        $this->assertDatabaseCount('discount_redemptions', 0);

        $subscription = app(CreateCustomerSubscription::class)->handle(User::factory()->create(), $plan->id, 2, $variant->id, $quote['fingerprint']);

        $this->assertSame(649944, $subscription->invoices()->firstOrFail()->total_cents);
        $this->assertSame(1, $discount->fresh()->uses_count);
    }

    public function test_next_payment_accounts_for_expiring_and_exhausted_discounts(): void
    {
        $plan = Plan::factory()->create();
        $discount = Discount::factory()->create(['value' => 1000, 'ends_on' => today()->addDay()]);
        $quote = app(PreviewSubscriptionPrice::class)->handle($plan, 1, null);
        $this->assertSame(265500, $quote['first']['total_cents']);
        $this->assertSame(295000, $quote['next']['total_cents']);
        $discount->update(['ends_on' => null, 'max_uses' => 1]);

        $quote = app(PreviewSubscriptionPrice::class)->handle($plan, 1, null);

        $this->assertSame(265500, $quote['first']['total_cents']);
        $this->assertSame(295000, $quote['next']['total_cents']);
        $this->assertSame(0, DiscountRedemption::count());
    }

    public function test_checkout_requires_renewed_consent_if_a_discount_or_price_changes(): void
    {
        $plan = Plan::factory()->create();
        $discount = Discount::factory()->create(['value' => 1000]);
        $component = Livewire::actingAs(User::factory()->create())->test(BrowsePlans::class)
            ->call('choosePlan', $plan->id)->set('accepted', true);
        $discount->update(['is_active' => false]);

        $component->call('subscribe')->assertHasErrors('accepted')->assertSet('accepted', false);

        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('invoices', 0);
        $component->set('accepted', true)->call('subscribe')->assertHasNoErrors();
        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_customer_sees_discounted_totals_estimates_and_plan_terms(): void
    {
        $plan = Plan::factory()->create(['can_close' => true]);
        Discount::factory()->create(['name' => 'Welcome offer', 'value' => 1000]);

        Livewire::actingAs(User::factory()->create())->test(BrowsePlans::class)->call('choosePlan', $plan->id)
            ->assertSee('First invoice total')->assertSee('Welcome offer')->assertSee('2,655.00')
            ->assertSee('Expected next payment')->assertSee('30 Nov 2026')->assertSee('14 Nov 2026')
            ->assertSee('Closing stops future recurring billing')->assertSee('Contact the seller to request a plan change');
    }

    public function test_subscription_specific_discounts_do_not_leak_into_new_customer_quotes(): void
    {
        $plan = Plan::factory()->create();
        Discount::factory()->create(['subscription_id' => Subscription::factory(), 'value' => 5000]);

        $quote = app(PreviewSubscriptionPrice::class)->handle($plan, 1, null);

        $this->assertSame(0, $quote['first']['amount']);
        $this->assertSame(295000, $quote['first']['total_cents']);
    }
}
