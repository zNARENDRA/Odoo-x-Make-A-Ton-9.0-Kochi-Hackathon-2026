<?php

namespace Tests\Feature;

use App\Actions\DetectBillingProblems;
use App\Actions\GenerateDueInvoices;
use App\Actions\GenerateRenewalInvoices;
use App\Actions\RecordPayment;
use App\Actions\TransitionSubscription;
use App\Livewire\BillingAlertBanner;
use App\Livewire\BillingOperations;
use App\Models\Activity;
use App\Models\BillingIssue;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class BillingOperationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(12, 0));
    }

    public function test_history_records_the_actor_changed_values_and_automated_changes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $plan = Plan::factory()->create(['price_cents' => 250000]);
        $this->actingAs($admin);

        $plan->update(['price_cents' => 300000]);

        $activity = Activity::where('subject_type', 'Plan')->where('action', 'updated')->firstOrFail();
        $this->assertSame($admin->id, $activity->actor_id);
        $this->assertSame(['price_cents' => 250000], $activity->before_values);
        $this->assertSame(['price_cents' => 300000], $activity->after_values);
        auth()->forgetGuards();
        $plan->update(['price_cents' => 310000]);
        $this->assertDatabaseHas('activities', ['subject_type' => 'Plan', 'action' => 'updated', 'actor_name' => 'System', 'actor_role' => 'system']);
    }

    public function test_failed_transactions_roll_back_their_activity_records(): void
    {
        $plan = Plan::factory()->create();
        $before = Activity::count();
        DB::beginTransaction();
        $plan->update(['price_cents' => 900000]);
        DB::rollBack();

        $this->assertSame($before, Activity::count());
        $this->assertSame(250000, $plan->fresh()->price_cents);
    }

    public function test_customer_pause_and_staff_payment_actions_are_attributed_to_their_actors(): void
    {
        $subscription = Subscription::factory()->create(['next_billing_on' => today()->addMonth()]);
        $subscription->plan->update(['can_pause' => true]);
        $customer = $subscription->user;
        $this->actingAs($customer);
        app(TransitionSubscription::class)->handle($customer, $subscription, 'pause');
        $this->assertDatabaseHas('activities', ['actor_id' => $customer->id, 'subject_type' => 'Subscription', 'action' => 'updated']);
        $invoice = Invoice::factory()->for($subscription)->create();
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff);

        app(RecordPayment::class)->handle($staff, $invoice, ['amount_cents' => 590000, 'method' => 'upi', 'paid_on' => today()->toDateString()]);

        $this->assertDatabaseHas('activities', ['actor_id' => $staff->id, 'subject_type' => 'Payment', 'action' => 'created']);
        $this->assertDatabaseHas('activities', ['actor_id' => $staff->id, 'subject_type' => 'Invoice', 'action' => 'updated']);
    }

    public function test_detector_deduplicates_resolves_and_reopens_real_problems(): void
    {
        $subscription = Subscription::factory()->create(['next_billing_on' => today()->subDay(), 'starts_on' => today()->subMonth(), 'created_at' => now()->subMonth()]);
        $detector = app(DetectBillingProblems::class);
        $detector->handle();
        $detector->handle();
        $this->assertSame(1, BillingIssue::where('status', 'open')->count());
        $subscription->update(['next_billing_on' => today()->addMonth()]);

        $detector->handle();

        $this->assertDatabaseHas('billing_issues', ['kind' => 'billing_overdue', 'status' => 'resolved']);
        $subscription->update(['next_billing_on' => today()->subDay()]);
        $detector->handle();
        $this->assertSame(1, BillingIssue::count());
        $this->assertDatabaseHas('billing_issues', ['kind' => 'billing_overdue', 'status' => 'open', 'resolved_at' => null]);
    }

    public function test_invalid_schedules_are_flagged_and_new_paused_or_expired_subscriptions_do_not_raise_late_billing_alerts(): void
    {
        Subscription::factory()->create(['starts_on' => today()->addDay()]);
        Subscription::factory()->create(['created_at' => now()]);
        Subscription::factory()->create(['status' => 'paused', 'next_billing_on' => today()->subDay()]);
        Subscription::factory()->create(['expires_on' => today(), 'created_at' => now()->subDay()]);

        $this->artisan('billing:check-problems')->assertSuccessful();

        $this->assertSame(1, BillingIssue::count());
        $this->assertDatabaseHas('billing_issues', ['kind' => 'invalid_schedule', 'status' => 'open']);
    }

    public function test_payment_inconsistency_is_detected_and_ordinary_partial_payments_are_valid(): void
    {
        $bad = Invoice::factory()->create(['status' => 'paid']);
        $good = Invoice::factory()->create();
        $good->payments()->create(['amount_cents' => 100000, 'method' => 'cash', 'paid_on' => today()]);

        app(DetectBillingProblems::class)->handle();

        $this->assertSame(1, BillingIssue::count());
        $this->assertDatabaseHas('billing_issues', ['kind' => 'payment_inconsistent', 'subject_id' => $bad->id, 'status' => 'open']);
        $bad->update(['status' => 'confirmed']);
        app(DetectBillingProblems::class)->handle();
        $this->assertDatabaseHas('billing_issues', ['kind' => 'payment_inconsistent', 'status' => 'resolved']);
    }

    public function test_failed_invoice_generation_is_visible_and_does_not_block_other_subscriptions(): void
    {
        $bad = Subscription::factory()->create();
        $good = Subscription::factory()->create();
        $real = app(GenerateRenewalInvoices::class);
        $this->mock(GenerateRenewalInvoices::class)->shouldReceive('handle')->twice()
            ->andReturnUsing(function (Subscription $subscription) use ($bad, $real): int {
                if ($subscription->id === $bad->id) {
                    throw new \RuntimeException('Simulated billing failure');
                }

                return $real->handle($subscription);
            });

        $result = app(GenerateDueInvoices::class)->handle();

        $this->assertSame(['created' => 1, 'failed' => 1], $result);
        $this->assertDatabaseHas('billing_issues', ['kind' => 'invoice_generation_failed', 'subject_id' => $bad->id, 'status' => 'open']);
        $this->assertDatabaseHas('invoices', ['subscription_id' => $good->id]);
        $this->app->instance(GenerateRenewalInvoices::class, $real);
        app(GenerateDueInvoices::class)->handle();
        $this->assertDatabaseHas('billing_issues', ['kind' => 'invoice_generation_failed', 'subject_id' => $bad->id, 'status' => 'resolved']);
        $this->assertDatabaseHas('invoices', ['subscription_id' => $bad->id]);
    }

    public function test_staff_can_review_and_resolve_issues_with_an_audited_note(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $issue = BillingIssue::factory()->create();
        $this->actingAs($staff)->get('/operations')->assertOk()->assertSee('Activity history')->assertSee('open billing issue');

        Livewire::actingAs($staff)->test(BillingOperations::class)->call('resolve', $issue->id)->assertHasErrors('resolutionNote')
            ->set('resolutionNote', 'Reviewed and corrected the billing schedule.')->call('resolve', $issue->id)->assertHasNoErrors()
            ->set('status', 'resolved')->assertSee('Reviewed and corrected the billing schedule.');

        $this->assertDatabaseHas('billing_issues', ['id' => $issue->id, 'status' => 'resolved']);
        $this->assertDatabaseHas('activities', ['subject_type' => 'BillingIssue', 'subject_id' => $issue->id, 'actor_id' => $staff->id, 'action' => 'updated']);
    }

    public function test_customers_and_guests_cannot_view_operations_or_resolve_issues(): void
    {
        $this->get('/operations')->assertRedirect(route('login'));
        $customer = User::factory()->create();
        $this->actingAs($customer)->get('/operations')->assertForbidden();
        Livewire::actingAs($customer)->test(BillingOperations::class)->assertForbidden();
        Livewire::actingAs($customer)->test(BillingAlertBanner::class)->assertForbidden();
        $this->get('/dashboard')->assertOk()->assertDontSee('Billing operations');
    }

    public function test_staff_banner_refreshes_when_a_new_issue_appears(): void
    {
        $banner = Livewire::actingAs(User::factory()->create(['role' => 'staff']))->test(BillingAlertBanner::class)
            ->assertDontSee('open billing issue');
        BillingIssue::factory()->create();

        $banner->call('$refresh')->assertSee('1 open billing issue');
    }

    public function test_activity_history_is_immutable_and_escapes_actor_and_record_values(): void
    {
        $activity = Activity::factory()->create(['actor_name' => '<script>alert(1)</script>', 'after_values' => ['name' => '<script>alert(2)</script>']]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/operations')->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<script>alert(2)</script>', false);

        $this->expectException(\LogicException::class);
        $activity->update(['actor_name' => 'Changed']);
    }
}
