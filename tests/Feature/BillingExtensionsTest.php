<?php

namespace Tests\Feature;

use App\Actions\CreateSubscriptionDraft;
use App\Actions\GenerateRenewalInvoices;
use App\Livewire\BillingWorkspace;
use App\Livewire\CatalogExtensions;
use App\Mail\InvoiceReady;
use App\Models\Discount;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\QuotationTemplate;
use App\Models\QuotationTemplateLine;
use App\Models\Subscription;
use App\Models\SubscriptionLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BillingExtensionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-31 12:00:00'));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** @return array<string, mixed> */
    private function draftData(User $customer, Plan $plan): array
    {
        return ['user_id' => $customer->id, 'plan_id' => $plan->id, 'quantity' => 2, 'starts_on' => '2026-10-31', 'expires_on' => '', 'payment_terms_days' => 14];
    }

    public function test_multiple_products_and_variant_modifiers_are_snapshotted_and_billed_with_per_line_tax(): void
    {
        $plan = Plan::factory()->create();
        $variant = ProductVariant::factory()->for($plan->product)->create(['name' => 'Odoo', 'price_modifier_cents' => 56000]);
        $addon = Product::factory()->create(['name' => 'Priority support', 'sales_price_cents' => 100000, 'tax_basis_points' => 500]);
        $addonVariant = ProductVariant::factory()->for($addon)->create(['price_modifier_cents' => 25000]);
        $customer = User::factory()->create();
        $draft = app(CreateSubscriptionDraft::class)->handle($this->admin(), $this->draftData($customer, $plan),
            [['product_id' => $addon->id, 'product_variant_id' => $addonVariant->id, 'quantity' => 3]], $variant->id);
        $this->assertSame(2, $draft->lines()->count());
        $this->assertSame(306000, $draft->unit_price_cents);
        $draft->update(['status' => 'active']);
        app(GenerateRenewalInvoices::class)->handle($draft);
        $invoice = Invoice::with('lines')->firstOrFail();
        $this->assertSame(987000, $invoice->subtotal_cents);
        $this->assertSame(128910, $invoice->tax_cents);
        $this->assertSame(1115910, $invoice->total_cents);
        $this->assertSame(2, $invoice->lines->count());
        $this->assertSame('Odoo', $invoice->lines->first()->variant_name);
        $addon->update(['name' => 'Changed', 'sales_price_cents' => 1]);
        $variant->update(['price_modifier_cents' => 1]);
        $this->assertSame('Priority support', $invoice->fresh()->snapshot['lines'][1]['description']);
        $this->actingAs($customer)->get(route('invoices.show', $invoice))->assertOk()->assertSee('Priority support')->assertSee('Odoo');
        (new InvoiceReady($invoice))->assertSeeInHtml('Priority support');
    }

    public function test_product_discount_is_capped_and_does_not_reduce_other_lines(): void
    {
        $subscription = Subscription::factory()->create();
        $base = SubscriptionLine::factory()->for($subscription)->create(['quantity' => 1, 'unit_price_cents' => 10000]);
        $addon = SubscriptionLine::factory()->for($subscription)->create(['position' => 1, 'quantity' => 1, 'unit_price_cents' => 2000, 'tax_basis_points' => 500]);
        $rule = Discount::factory()->create(['type' => 'fixed', 'value' => 5000, 'product_id' => $addon->product_id]);
        app(GenerateRenewalInvoices::class)->handle($subscription);
        $invoice = Invoice::with('lines')->firstOrFail();
        $this->assertSame(2000, $invoice->discount_cents);
        $this->assertSame(11800, $invoice->total_cents);
        $this->assertSame(0, $invoice->lines[0]->discount_cents);
        $this->assertSame(2000, $invoice->lines[1]->discount_cents);
        $this->assertSame(0, $invoice->lines[1]->tax_cents);
        $this->assertSame(1, $rule->fresh()->uses_count);
    }

    public function test_best_discount_is_applied_once_before_tax(): void
    {
        $subscription = Subscription::factory()->create();
        $fixed = Discount::factory()->create(['type' => 'fixed', 'value' => 10000]);
        $percentage = Discount::factory()->create(['value' => 1250]);
        app(GenerateRenewalInvoices::class)->handle($subscription);
        $invoice = Invoice::firstOrFail();
        $this->assertSame(62500, $invoice->discount_cents);
        $this->assertSame(78750, $invoice->tax_cents);
        $this->assertSame(516250, $invoice->total_cents);
        $this->assertSame(0, $fixed->fresh()->uses_count);
        $this->assertSame(1, $percentage->fresh()->uses_count);
        $this->assertDatabaseHas('discount_redemptions', ['invoice_id' => $invoice->id, 'discount_id' => $percentage->id, 'amount_cents' => 62500]);
    }

    public static function discountEligibility(): array
    {
        return [
            'quantity below minimum' => [['minimum_quantity' => 3], 0],
            'purchase below minimum' => [['minimum_purchase_cents' => 500001], 0],
            'minimums met exactly' => [['minimum_quantity' => 2, 'minimum_purchase_cents' => 500000], 50000],
            'not started' => [['starts_on' => '2026-11-01'], 0],
            'expired' => [['ends_on' => '2026-10-30'], 0],
            'inclusive date boundaries' => [['starts_on' => '2026-10-31', 'ends_on' => '2026-10-31'], 50000],
            'disabled' => [['is_active' => false], 0],
            'exhausted' => [['max_uses' => 1, 'uses_count' => 1], 0],
        ];
    }

    #[DataProvider('discountEligibility')]
    public function test_discount_eligibility_uses_quantity_purchase_dates_and_capacity(array $attributes, int $expected): void
    {
        $subscription = Subscription::factory()->create();
        Discount::factory()->create($attributes);
        app(GenerateRenewalInvoices::class)->handle($subscription);
        $this->assertSame($expected, Invoice::firstOrFail()->discount_cents);
    }

    public function test_scope_thresholds_use_only_qualifying_products_and_subscriptions(): void
    {
        $subscription = Subscription::factory()->create();
        $line = SubscriptionLine::factory()->for($subscription)->create(['quantity' => 1, 'unit_price_cents' => 100]);
        SubscriptionLine::factory()->for($subscription)->create(['quantity' => 100, 'unit_price_cents' => 10000, 'position' => 1]);
        Discount::factory()->create(['product_id' => $line->product_id, 'minimum_quantity' => 2]);
        Discount::factory()->for(Subscription::factory())->create();
        app(GenerateRenewalInvoices::class)->handle($subscription);
        $this->assertSame(0, Invoice::firstOrFail()->discount_cents);
    }

    public function test_usage_limit_is_shared_across_subscriptions_and_retries_do_not_consume_another_use(): void
    {
        $rule = Discount::factory()->create(['max_uses' => 1]);
        $first = Subscription::factory()->create();
        $second = Subscription::factory()->create();
        $generator = app(GenerateRenewalInvoices::class);
        $generator->handle($first);
        $generator->handle($second);
        $this->assertSame(50000, Invoice::where('subscription_id', $first->id)->firstOrFail()->discount_cents);
        $this->assertSame(0, Invoice::where('subscription_id', $second->id)->firstOrFail()->discount_cents);
        Invoice::where('subscription_id', $first->id)->update(['status' => 'cancelled']);
        $first->update(['next_billing_on' => today()]);
        $this->assertSame(0, $generator->handle($first));
        $this->assertSame(1, $rule->fresh()->uses_count);
        $this->assertDatabaseCount('discount_redemptions', 1);
        $this->assertDatabaseCount('invoice_lines', 2);
    }

    public function test_catchup_uses_each_period_date_for_discount_eligibility(): void
    {
        $subscription = Subscription::factory()->create(['starts_on' => '2026-09-30', 'next_billing_on' => '2026-09-30']);
        Discount::factory()->create(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);
        app(GenerateRenewalInvoices::class)->handle($subscription);
        $invoices = Invoice::orderBy('period_start')->get();
        $this->assertSame(50000, $invoices[0]->discount_cents);
        $this->assertSame(0, $invoices[1]->discount_cents);
    }

    public function test_discount_allocation_preserves_minor_units_and_large_integer_totals(): void
    {
        $subscription = Subscription::factory()->create();
        SubscriptionLine::factory()->count(3)->for($subscription)->sequence(['position' => 0], ['position' => 1], ['position' => 2])
            ->create(['quantity' => 10000, 'unit_price_cents' => 100000000, 'tax_basis_points' => 0]);
        Discount::factory()->create(['type' => 'fixed', 'value' => 500000000000]);
        app(GenerateRenewalInvoices::class)->handle($subscription);
        $invoice = Invoice::with('lines')->firstOrFail();
        $this->assertSame(3000000000000, $invoice->subtotal_cents);
        $this->assertSame(2500000000000, $invoice->total_cents);
        $this->assertSame(500000000000, (int) $invoice->lines->sum('discount_cents'));
        $this->assertSame([166666666667, 166666666667, 166666666666], $invoice->lines->pluck('discount_cents')->all());
    }

    public function test_failed_generation_rolls_back_invoice_lines_redemption_and_cursor(): void
    {
        $subscription = Subscription::factory()->create();
        $rule = Discount::factory()->create();
        Subscription::updated(function (): void {
            throw new RuntimeException('Simulated cursor failure');
        });
        try {
            app(GenerateRenewalInvoices::class)->handle($subscription);
            $this->fail('Expected the simulated persistence failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated cursor failure', $exception->getMessage());
        } finally {
            Subscription::flushEventListeners();
        }
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_lines', 0);
        $this->assertDatabaseCount('discount_redemptions', 0);
        $this->assertSame(0, $rule->fresh()->uses_count);
        $this->assertSame('2026-10-31', $subscription->fresh()->next_billing_on->toDateString());
    }

    public function test_template_prefills_lines_variant_and_terms_without_creating_a_subscription_until_saved(): void
    {
        $admin = $this->admin();
        $plan = Plan::factory()->create();
        $variant = ProductVariant::factory()->for($plan->product)->create();
        $template = QuotationTemplate::factory()->for($plan)->create(['product_variant_id' => $variant->id, 'quantity' => 4, 'payment_terms_days' => 30, 'validity_days' => 7]);
        $line = QuotationTemplateLine::factory()->for($template)->create(['quantity' => 2]);
        $customer = User::factory()->create();
        $component = Livewire::actingAs($admin)->test(BillingWorkspace::class, ['section' => 'subscriptions'])
            ->set('subscription.user_id', $customer->id)->set('templateId', (string) $template->id)
            ->assertSet('subscription.plan_id', $plan->id)->assertSet('subscription.quantity', 4)
            ->assertSet('subscription.payment_terms_days', 30)->assertSet('subscriptionVariantId', (string) $variant->id)
            ->assertSet('items.0.product_id', $line->product_id);
        $this->assertDatabaseCount('subscriptions', 0);
        $component->call('saveSubscription')->assertHasNoErrors();
        $subscription = Subscription::firstOrFail();
        $this->assertSame('2026-11-07', $subscription->quotation_expires_on->toDateString());
        $this->assertSame($template->id, $subscription->quotation_template_id);
        $this->assertSame(2, $subscription->lines()->count());
        $this->assertSame('draft', $subscription->status);
    }

    public function test_expired_quotation_cannot_be_confirmed(): void
    {
        $subscription = Subscription::factory()->create(['status' => 'quotation', 'quotation_expires_on' => '2026-10-30']);
        Livewire::actingAs($this->admin())->test(BillingWorkspace::class, ['section' => 'subscriptions'])
            ->call('changeSubscriptionStatus', $subscription->id, 'advance')->assertHasErrors('subscription');
        $this->assertSame('quotation', $subscription->fresh()->status);
    }

    public function test_foreign_or_inactive_variants_and_negative_final_prices_are_rejected_atomically(): void
    {
        $admin = $this->admin();
        $plan = Plan::factory()->create();
        $customer = User::factory()->create();
        $foreign = ProductVariant::factory()->create();
        $component = Livewire::actingAs($admin)->test(BillingWorkspace::class, ['section' => 'subscriptions'])
            ->set('subscription', $this->draftData($customer, $plan))->set('subscriptionVariantId', (string) $foreign->id)
            ->call('saveSubscription')->assertHasErrors('items');
        $invalid = ProductVariant::factory()->for($plan->product)->create(['price_modifier_cents' => -300000]);
        $component->set('subscriptionVariantId', (string) $invalid->id)->call('saveSubscription')->assertHasErrors('items');
        $invalid->update(['price_modifier_cents' => 10, 'is_active' => false]);
        $component->call('saveSubscription')->assertHasErrors('items');
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('subscription_lines', 0);
    }

    public function test_customer_pause_generates_due_invoices_and_resume_does_not_backbill_paused_time(): void
    {
        $customer = User::factory()->create();
        $subscription = Subscription::factory()->for($customer)->create();
        $component = Livewire::actingAs($customer)->test(BillingWorkspace::class, ['section' => 'subscriptions'])
            ->assertSee('Pause')->call('changeSubscriptionStatus', $subscription->id, 'pause')->assertStatus(200)->assertHasNoErrors();
        $this->assertSame('paused', $subscription->fresh()->status);
        $this->assertDatabaseCount('invoices', 1);
        $this->travelTo(CarbonImmutable::parse('2027-01-03 12:00:00'));
        $component->call('changeSubscriptionStatus', $subscription->id, 'resume')->assertHasNoErrors();
        $this->assertSame('2027-01-03', $subscription->fresh()->next_billing_on->toDateString());
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_customer_can_close_and_renew_own_subscription_but_cannot_advance_or_access_another_account(): void
    {
        $customer = User::factory()->create();
        $own = Subscription::factory()->for($customer)->create(['next_billing_on' => '2026-11-30', 'expires_on' => '2026-11-30']);
        $other = Subscription::factory()->create();
        $component = Livewire::actingAs($customer)->test(BillingWorkspace::class, ['section' => 'subscriptions'])
            ->call('changeSubscriptionStatus', $own->id, 'close')->assertStatus(200)->assertHasNoErrors();
        $this->assertSame('closed', $own->fresh()->status);
        $component->call('changeSubscriptionStatus', $own->id, 'renew')->assertHasNoErrors();
        $this->assertSame('active', $own->fresh()->status);
        $this->assertSame('2026-12-30', $own->fresh()->expires_on->toDateString());
        $component->call('changeSubscriptionStatus', $own->id, 'advance')->assertForbidden();
        Livewire::actingAs($customer)->test(BillingWorkspace::class, ['section' => 'subscriptions'])
            ->call('changeSubscriptionStatus', $other->id, 'pause')->assertNotFound();
        $this->assertSame('active', $other->fresh()->status);
    }

    public static function blockedActions(): array
    {
        return ['pause' => ['pause', 'can_pause', 'active'], 'close' => ['close', 'can_close', 'active'], 'renew' => ['renew', 'can_renew', 'closed'], 'resume' => ['resume', 'can_pause', 'paused']];
    }

    #[DataProvider('blockedActions')]
    public function test_customer_actions_obey_plan_flags_without_billing_side_effects(string $action, string $flag, string $status): void
    {
        $customer = User::factory()->create();
        $subscription = Subscription::factory()->for($customer)->for(Plan::factory()->state([$flag => false]))->create(['status' => $status]);
        Livewire::actingAs($customer)->test(BillingWorkspace::class, ['section' => 'subscriptions'])
            ->call('changeSubscriptionStatus', $subscription->id, $action)->assertStatus(200)->assertHasErrors('subscription');
        $this->assertSame($status, $subscription->fresh()->status);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_admin_can_create_variants_discounts_and_templates_through_livewire(): void
    {
        $plan = Plan::factory()->create();
        $addon = Product::factory()->create();
        $component = Livewire::actingAs($this->admin())->test(CatalogExtensions::class)
            ->set('variant', ['name' => 'Odoo', 'product_id' => $plan->product_id, 'modifier' => '560.00'])
            ->call('saveVariant')->assertHasNoErrors();
        $this->assertDatabaseHas('product_variants', ['product_id' => $plan->product_id, 'price_modifier_cents' => 56000]);
        $component->set('discount.name', 'Volume savings')->set('discount.value', '12.50')->set('discount.max_uses', 5)
            ->call('saveDiscount')->assertHasNoErrors();
        $this->assertDatabaseHas('discounts', ['name' => 'Volume savings', 'type' => 'percentage', 'value' => 1250, 'max_uses' => 5, 'uses_count' => 0]);
        $component->set('template.name', 'Reusable bundle')->set('template.plan_id', $plan->id)
            ->set('templateItems', [['product_id' => $addon->id, 'product_variant_id' => '', 'quantity' => 2]])
            ->call('saveTemplate')->assertHasNoErrors();
        $this->assertDatabaseHas('quotation_template_lines', ['product_id' => $addon->id, 'quantity' => 2]);
    }

    public function test_staff_and_customers_cannot_manage_discount_variant_or_template_configuration(): void
    {
        foreach (['staff', 'customer'] as $role) {
            Livewire::actingAs(User::factory()->create(['role' => $role]))->test(CatalogExtensions::class)->assertForbidden();
        }
        $this->assertDatabaseCount('discounts', 0);
    }

    public function test_invalid_discount_configuration_is_rejected(): void
    {
        $component = Livewire::actingAs($this->admin())->test(CatalogExtensions::class)
            ->set('discount.name', 'Bad rule')->set('discount.value', '100.01')
            ->set('discount.starts_on', '2026-11-01')->set('discount.ends_on', '2026-10-01')->set('discount.max_uses', 0)
            ->call('saveDiscount')->assertHasErrors(['discount.value', 'discount.ends_on', 'discount.max_uses']);
        $component->set('discount.type', 'fixed')->set('discount.value', '-1.00')->call('saveDiscount')->assertHasErrors('discount.value');
        $this->assertDatabaseCount('discounts', 0);
    }

    public function test_fixed_discount_reconciles_a_single_minor_unit_across_equal_lines(): void
    {
        $subscription = Subscription::factory()->create();
        SubscriptionLine::factory()->count(3)->for($subscription)->sequence(['position' => 0], ['position' => 1], ['position' => 2])
            ->create(['quantity' => 1, 'unit_price_cents' => 1, 'tax_basis_points' => 1800]);
        Discount::factory()->create(['type' => 'fixed', 'value' => 1]);
        app(GenerateRenewalInvoices::class)->handle($subscription);
        $invoice = Invoice::with('lines')->firstOrFail();
        $this->assertSame(2, $invoice->total_cents);
        $this->assertSame([1, 0, 0], $invoice->lines->pluck('discount_cents')->all());
    }

    public function test_fully_discounted_invoice_has_zero_tax_and_can_be_confirmed_paid(): void
    {
        $subscription = Subscription::factory()->create();
        Discount::factory()->create(['value' => 10000]);
        app(GenerateRenewalInvoices::class)->handle($subscription);
        $invoice = Invoice::firstOrFail();
        $this->assertSame(0, $invoice->tax_cents);
        $this->assertSame(0, $invoice->total_cents);
        Livewire::actingAs($this->admin())->test(BillingWorkspace::class, ['section' => 'invoices'])
            ->call('confirmInvoice', $invoice->id)->assertHasNoErrors();
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_negative_variant_modifier_can_reduce_a_positive_unit_price(): void
    {
        $plan = Plan::factory()->create();
        $variant = ProductVariant::factory()->for($plan->product)->create(['price_modifier_cents' => -50000]);
        $draft = app(CreateSubscriptionDraft::class)->handle($this->admin(), $this->draftData(User::factory()->create(), $plan), [], $variant->id);
        $this->assertSame(200000, $draft->unit_price_cents);
        $this->assertDatabaseHas('subscription_lines', ['subscription_id' => $draft->id, 'unit_price_cents' => 200000]);
    }

    public function test_client_supplied_line_prices_and_non_list_items_are_rejected(): void
    {
        $plan = Plan::factory()->create();
        $product = Product::factory()->create();
        $component = Livewire::actingAs($this->admin())->test(BillingWorkspace::class, ['section' => 'subscriptions'])
            ->set('subscription', $this->draftData(User::factory()->create(), $plan))
            ->set('items', [['product_id' => $product->id, 'quantity' => 1, 'unit_price_cents' => 1]])
            ->call('saveSubscription')->assertHasErrors('items.0');
        $component->set('items', ['invalid-key' => ['product_id' => $product->id, 'quantity' => 1]])
            ->call('saveSubscription')->assertHasErrors('items');
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_new_invoice_fields_escape_product_variant_and_discount_names(): void
    {
        $subscription = Subscription::factory()->create();
        SubscriptionLine::factory()->for($subscription)->create(['description' => '<script>alert(1)</script>', 'variant_name' => '<script>alert(2)</script>']);
        Discount::factory()->create(['name' => '<script>alert(3)</script>']);
        app(GenerateRenewalInvoices::class)->handle($subscription);
        $invoice = Invoice::firstOrFail();
        $this->actingAs($this->admin())->get(route('invoices.show', $invoice))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<script>alert(2)</script>', false)->assertDontSee('<script>alert(3)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        (new InvoiceReady($invoice))->assertDontSeeInHtml('<script>alert(1)</script>', false)->assertDontSeeInHtml('<script>alert(3)</script>', false);
    }

    public function test_migration_backfills_legacy_lines_without_recalculating_invoices(): void
    {
        $previous = DB::getDefaultConnection();
        config(['database.connections.legacy_migration' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('legacy_migration');
        try {
            foreach (['0001_01_01_000000_create_users_table.php', '2026_01_01_000001_create_billing_tables.php', '2026_10_31_043853_extend_billing_tables.php'] as $file) {
                (require database_path('migrations/'.$file))->up();
            }
            $subscription = Subscription::factory()->create();
            $invoice = Invoice::factory()->for($subscription)->create();
            (require database_path('migrations/2026_10_31_111624_add_billing_extensions.php'))->up();
            $this->assertDatabaseHas('subscription_lines', ['subscription_id' => $subscription->id, 'quantity' => 2, 'unit_price_cents' => 250000]);
            $this->assertDatabaseHas('invoice_lines', ['invoice_id' => $invoice->id, 'total_cents' => 590000, 'tax_cents' => 90000]);
            $this->assertSame(590000, $invoice->fresh()->total_cents);
        } finally {
            DB::disconnect('legacy_migration');
            DB::setDefaultConnection($previous);
        }
    }
}
