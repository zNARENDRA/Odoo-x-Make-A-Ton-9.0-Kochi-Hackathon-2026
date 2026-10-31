<?php

namespace Tests\Feature;

use App\Actions\GenerateRenewalInvoices;
use App\Livewire\BillingWorkspace;
use App\Mail\InvoiceReady;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BillingWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-31 12:00:00'));
    }

    private function team(string $role = 'admin'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    public function test_catches_up_all_billing_periods_once_and_keeps_month_end_anchor(): void
    {
        $subscription = Subscription::factory()->create(['starts_on' => '2026-01-31', 'next_billing_on' => '2026-01-31']);
        $generator = app(GenerateRenewalInvoices::class);
        $this->assertSame(10, $generator->handle($subscription));
        $this->assertSame(0, $generator->handle($subscription));
        $this->assertSame(590000, Invoice::where('subscription_id', $subscription->id)->whereDate('period_start', '2026-02-28')->firstOrFail()->total_cents);
        $this->assertSame(1, Invoice::where('subscription_id', $subscription->id)->whereDate('period_start', '2026-03-31')->count());
        $this->assertSame('2026-11-30', $subscription->fresh()->next_billing_on->toDateString());
    }

    public static function intervals(): array
    {
        return ['daily' => ['daily', '2026-11-01'], 'weekly' => ['weekly', '2026-11-07'], 'monthly' => ['monthly', '2026-11-30'], 'yearly' => ['yearly', '2027-10-31']];
    }

    #[DataProvider('intervals')]
    public function test_billing_advances_by_the_subscription_interval(string $interval, string $next): void
    {
        $subscription = Subscription::factory()->create(['billing_interval' => $interval]);
        $this->assertSame(1, app(GenerateRenewalInvoices::class)->handle($subscription));
        $this->assertSame($next, $subscription->fresh()->next_billing_on->toDateString());
    }

    public function test_snapshot_and_tax_rounding_do_not_change_after_catalog_edits(): void
    {
        $subscription = Subscription::factory()->create(['quantity' => 3, 'unit_price_cents' => 999, 'tax_basis_points' => 1800]);
        app(GenerateRenewalInvoices::class)->handle($subscription);
        $invoice = Invoice::firstOrFail();
        $this->assertSame(2997, $invoice->subtotal_cents);
        $this->assertSame(539, $invoice->tax_cents);
        $this->assertSame(3536, $invoice->total_cents);
        $subscription->plan->update(['name' => 'Changed name', 'price_cents' => 1]);
        $this->assertNotSame('Changed name', $invoice->fresh()->snapshot['plan']);
    }

    public function test_expiry_bills_only_prior_periods_and_closes_automatically(): void
    {
        $subscription = Subscription::factory()->create(['starts_on' => '2026-08-31', 'next_billing_on' => '2026-08-31', 'expires_on' => '2026-10-31']);
        $this->assertSame(2, app(GenerateRenewalInvoices::class)->handle($subscription));
        $this->assertSame('closed', $subscription->fresh()->status);
        $this->assertDatabaseMissing('invoices', ['subscription_id' => $subscription->id, 'period_start' => '2026-10-31']);
    }

    public function test_paused_and_future_subscriptions_are_not_billed(): void
    {
        $paused = Subscription::factory()->create(['status' => 'paused']);
        Subscription::factory()->create(['starts_on' => '2026-11-01', 'next_billing_on' => '2026-11-01']);
        $this->artisan('billing:generate-invoices')->assertSuccessful();
        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame(0, app(GenerateRenewalInvoices::class)->handle($paused));
    }

    public function test_scheduler_closes_expired_active_and_paused_subscriptions_before_next_billing(): void
    {
        $active = Subscription::factory()->create(['starts_on' => '2026-10-01', 'next_billing_on' => '2026-11-01', 'expires_on' => '2026-10-20']);
        $paused = Subscription::factory()->create(['status' => 'paused', 'next_billing_on' => '2026-11-01', 'expires_on' => '2026-10-20']);
        $manual = Subscription::factory()->for(Plan::factory()->state(['auto_close' => false]))->create(['next_billing_on' => '2026-11-01', 'expires_on' => '2026-10-20']);
        $this->artisan('billing:generate-invoices')->assertSuccessful();
        $this->assertSame('closed', $active->fresh()->status);
        $this->assertSame('closed', $paused->fresh()->status);
        $this->assertSame('active', $manual->fresh()->status);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_admin_creates_product_and_plan_with_an_end_date_only(): void
    {
        $component = Livewire::actingAs($this->team())->test(BillingWorkspace::class, ['section' => 'catalog'])
            ->set('product', ['name' => 'New product', 'type' => 'service', 'sales_price' => '99.99', 'cost_price' => '10.00', 'tax_percent' => '18.50', 'notes' => 'Service notes'])
            ->call('saveProduct')->assertHasNoErrors();
        $product = Product::where('name', 'New product')->firstOrFail();
        $this->assertSame(9999, $product->sales_price_cents);
        $this->assertSame(1850, $product->tax_basis_points);
        $component->set('plan.name', 'Limited plan')->set('plan.product_id', $product->id)
            ->set('plan.price', '90.50')->set('plan.available_until', '2026-12-31')
            ->call('savePlan')->assertHasNoErrors();
        $this->assertDatabaseHas('plans', ['name' => 'Limited plan', 'price_cents' => 9050, 'product_id' => $product->id]);
    }

    public function test_plan_availability_and_customer_role_are_enforced_on_subscriptions(): void
    {
        $plan = Plan::factory()->create(['available_from' => '2026-11-01']);
        $customer = User::factory()->create();
        $component = Livewire::actingAs($this->team())->test(BillingWorkspace::class, ['section' => 'subscriptions'])
            ->set('subscription.user_id', $customer->id)->set('subscription.plan_id', $plan->id)
            ->call('saveSubscription')->assertHasErrors('subscription.starts_on');
        $component->set('subscription.starts_on', '2026-11-01')->set('subscription.user_id', $this->team('staff')->id)
            ->call('saveSubscription')->assertHasErrors('subscription.user_id');
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_plan_flags_prevent_pause_and_renewal(): void
    {
        $subscription = Subscription::factory()->for(Plan::factory()->state(['can_pause' => false, 'can_renew' => false]))->create(['next_billing_on' => '2026-11-30']);
        $component = Livewire::actingAs($this->team())->test(BillingWorkspace::class, ['section' => 'subscriptions']);
        $component->call('changeSubscriptionStatus', $subscription->id, 'pause')->assertHasErrors('subscription');
        $component->call('changeSubscriptionStatus', $subscription->id, 'renew')->assertHasErrors('subscription');
        $this->assertSame('active', $subscription->fresh()->status);
    }

    public function test_renewal_reopens_closed_subscription_and_resuming_does_not_bill_paused_time(): void
    {
        $closed = Subscription::factory()->create(['status' => 'closed', 'expires_on' => '2026-09-30', 'next_billing_on' => '2026-10-01']);
        $component = Livewire::actingAs($this->team())->test(BillingWorkspace::class, ['section' => 'subscriptions']);
        $component->call('changeSubscriptionStatus', $closed->id, 'renew')->assertHasNoErrors();
        $this->assertSame('active', $closed->fresh()->status);
        $this->assertSame('2026-11-30', $closed->fresh()->expires_on->toDateString());
        $paused = Subscription::factory()->create(['status' => 'paused', 'starts_on' => '2026-08-31', 'next_billing_on' => '2026-09-30']);
        $component->call('changeSubscriptionStatus', $paused->id, 'resume');
        $this->assertSame('2026-10-31', $paused->fresh()->next_billing_on->toDateString());
        $this->assertSame(1, app(GenerateRenewalInvoices::class)->handle($paused));
    }

    public function test_report_end_date_can_be_used_without_a_start_date(): void
    {
        Invoice::factory()->create(['period_start' => '2026-10-15']);
        Invoice::factory()->create(['period_start' => '2026-10-31']);
        Livewire::actingAs($this->team())->test(BillingWorkspace::class, ['section' => 'reports'])
            ->set('until', '2026-10-20')->assertHasNoErrors()
            ->assertViewHas('invoices', fn ($invoices) => $invoices->total() === 1)
            ->call('clearDates')->assertViewHas('invoices', fn ($invoices) => $invoices->total() === 2);
    }

    public function test_open_ended_subscription_renewal_does_not_introduce_an_expiry_date(): void
    {
        $subscription = Subscription::factory()->create(['next_billing_on' => '2026-11-30']);
        $component = Livewire::actingAs($this->team())->test(BillingWorkspace::class, ['section' => 'subscriptions']);
        $component->call('changeSubscriptionStatus', $subscription->id, 'renew')->assertHasErrors('subscription');
        $component->call('changeSubscriptionStatus', $subscription->id, 'close')->assertHasNoErrors();
        $component->call('changeSubscriptionStatus', $subscription->id, 'renew')->assertHasNoErrors();
        $this->assertSame('active', $subscription->fresh()->status);
        $this->assertNull($subscription->fresh()->expires_on);
    }

    public function test_invoice_period_is_not_recreated_after_cancellation_or_cursor_retry(): void
    {
        $subscription = Subscription::factory()->create();
        $generator = app(GenerateRenewalInvoices::class);
        $generator->handle($subscription);
        Invoice::firstOrFail()->update(['status' => 'cancelled']);
        $subscription->update(['next_billing_on' => today()]);
        $this->assertSame(0, $generator->handle($subscription));
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_partial_and_final_payments_update_balance_and_paid_status(): void
    {
        $admin = $this->team();
        $invoice = Invoice::factory()->create();
        Livewire::actingAs($admin)->test(BillingWorkspace::class, ['section' => 'invoices'])
            ->call('selectPayment', $invoice->id)->set('paymentAmount', '1000.00')->set('paymentNote', 'Bank ref 123')->call('savePayment')->assertHasNoErrors();
        $this->assertDatabaseHas('payments', ['invoice_id' => $invoice->id, 'amount_cents' => 100000, 'note' => 'Bank ref 123']);
        $this->assertSame(490000, $invoice->fresh()->balanceCents());
        Livewire::actingAs($admin)->test(BillingWorkspace::class, ['section' => 'invoices'])
            ->call('selectPayment', $invoice->id)->call('savePayment')->assertHasNoErrors();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(0, $invoice->fresh()->balanceCents());
    }

    public function test_overpayment_and_payments_on_drafts_are_rejected_without_writes(): void
    {
        $invoice = Invoice::factory()->create();
        $component = Livewire::actingAs($this->team())->test(BillingWorkspace::class, ['section' => 'invoices'])
            ->call('selectPayment', $invoice->id)->set('paymentAmount', '5900.01')->call('savePayment')->assertHasErrors('paymentAmount');
        $invoice->update(['status' => 'draft']);
        $component->set('paymentAmount', '1.00')->call('savePayment')->assertHasErrors('paymentAmount');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_customer_cannot_view_or_mutate_another_customers_billing(): void
    {
        $customer = User::factory()->create();
        $own = Invoice::factory()->for(Subscription::factory()->for($customer))->create();
        $other = Invoice::factory()->create();
        $this->actingAs($customer)->get(route('invoices'))->assertOk()->assertSee($own->number())->assertDontSee($other->number());
        $this->get(route('invoices.show', $other))->assertNotFound();
        $this->get(route('invoices.show', $own))->assertOk();
        Livewire::actingAs($customer)->test(BillingWorkspace::class, ['section' => 'invoices'])
            ->call('confirmInvoice', $other->id)->assertForbidden();
        $this->get(route('catalog'))->assertForbidden();
    }

    public function test_only_admin_can_create_staff_or_catalog_records(): void
    {
        $staff = $this->team('staff');
        $this->assertFalse(Gate::forUser($staff)->allows('manage-catalog'));
        $this->assertTrue(Gate::forUser($staff)->allows('manage-billing'));
        Livewire::actingAs($staff)->test(BillingWorkspace::class, ['section' => 'catalog'])->call('savePlan')->assertForbidden();
        Livewire::actingAs($staff)->test(BillingWorkspace::class, ['section' => 'customers'])
            ->set('person', ['name' => 'New Staff', 'email' => 'new@example.test', 'password' => 'StrongPass!', 'role' => 'staff'])->call('savePerson')->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
    }

    public function test_subscription_creation_enforces_minimum_and_snapshots_plan_terms(): void
    {
        $plan = Plan::factory()->create(['minimum_quantity' => 3]);
        $customer = User::factory()->create();
        $component = Livewire::actingAs($this->team())->test(BillingWorkspace::class, ['section' => 'subscriptions'])
            ->set('subscription', ['user_id' => $customer->id, 'plan_id' => $plan->id, 'quantity' => 2, 'starts_on' => '2026-10-31', 'expires_on' => '', 'payment_terms_days' => 14])
            ->call('saveSubscription')->assertHasErrors('subscription.quantity');
        $component->set('subscription.quantity', 3)->call('saveSubscription')->assertHasNoErrors();
        $this->assertDatabaseHas('subscriptions', ['user_id' => $customer->id, 'plan_id' => $plan->id, 'status' => 'draft', 'unit_price_cents' => 250000, 'tax_basis_points' => 1800, 'billing_interval' => 'monthly']);
    }

    public function test_lifecycle_rejects_invalid_actions_and_requires_billing_before_pause(): void
    {
        $admin = $this->team();
        $subscription = Subscription::factory()->create(['status' => 'draft']);
        $component = Livewire::actingAs($admin)->test(BillingWorkspace::class, ['section' => 'subscriptions']);
        $component->call('changeSubscriptionStatus', $subscription->id, 'pause')->assertHasErrors('subscription');
        foreach (['quotation', 'confirmed', 'active'] as $expected) {
            $component->call('changeSubscriptionStatus', $subscription->id, 'advance');
            $this->assertSame($expected, $subscription->fresh()->status);
        }
        $component->call('changeSubscriptionStatus', $subscription->id, 'pause')->assertHasErrors('subscription');
        app(GenerateRenewalInvoices::class)->handle($subscription);
        $component->call('changeSubscriptionStatus', $subscription->id, 'pause');
        $this->assertSame('paused', $subscription->fresh()->status);
        $component->call('changeSubscriptionStatus', $subscription->id, 'resume');
        $this->assertSame('active', $subscription->fresh()->status);
    }

    public function test_confirm_send_and_cancel_enforce_invoice_rules(): void
    {
        Mail::fake();
        $invoice = Invoice::factory()->create(['status' => 'draft']);
        $component = Livewire::actingAs($this->team())->test(BillingWorkspace::class, ['section' => 'invoices']);
        $component->call('sendInvoice', $invoice->id)->assertHasErrors('invoice');
        $component->call('confirmInvoice', $invoice->id)->call('sendInvoice', $invoice->id);
        Mail::assertQueued(InvoiceReady::class, fn (InvoiceReady $mail) => $mail->invoice->id === $invoice->id && $mail->hasTo('acme@example.test'));
        Payment::factory()->for($invoice)->create();
        $component->call('cancelInvoice', $invoice->id)->assertHasErrors('invoice');
        $this->assertSame('confirmed', $invoice->fresh()->status);
    }

    public function test_dashboard_totals_exclude_draft_and_cancelled_invoices(): void
    {
        $admin = $this->team();
        $invoice = Invoice::factory()->create(['due_on' => '2026-10-20']);
        Payment::factory()->for($invoice)->create(['amount_cents' => 100000]);
        Invoice::factory()->create(['status' => 'draft']);
        Invoice::factory()->create(['status' => 'cancelled']);
        Livewire::actingAs($admin)->test(BillingWorkspace::class)
            ->assertViewHas('metrics', fn (array $metrics) => $metrics['received'] == 100000 && $metrics['outstanding'] === 490000 && $metrics['overdue'] === 1);
    }

    public function test_database_worker_processes_a_queued_invoice_email(): void
    {
        config(['queue.default' => 'database', 'mail.default' => 'log']);
        $invoice = Invoice::factory()->create();
        Livewire::actingAs($this->team())->test(BillingWorkspace::class, ['section' => 'invoices'])
            ->call('sendInvoice', $invoice->id)->assertHasNoErrors();
        $this->assertDatabaseCount('jobs', 1);
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_invoice_snapshot_and_payment_notes_are_html_escaped(): void
    {
        $invoice = Invoice::factory()->create(['snapshot' => ['customer' => '<script>alert(1)</script>', 'email' => 'safe@example.test', 'product' => '<img src=x onerror=alert(2)>', 'plan' => 'Plan', 'quantity' => 1, 'unit_price_cents' => 1]]);
        Payment::factory()->for($invoice)->create(['note' => '<script>alert(3)</script>']);
        $this->actingAs($this->team())->get(route('invoices.show', $invoice))->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false)
            ->assertDontSee('<script>alert(3)</script>', false);
    }

    public function test_workspace_pages_require_authentication(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('subscriptions'))->assertRedirect(route('login'));
    }
}
