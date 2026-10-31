<?php

namespace Tests\Feature;

use App\Actions\CreateCustomerSubscription;
use App\Actions\RecordPayment;
use App\Actions\TransitionSubscription;
use App\Jobs\SendBillingEmail;
use App\Mail\BillingUpdate;
use App\Models\BillingEmail;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BillingEmailTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(12, 0));
        Mail::fake();
    }

    public function test_checkout_prepares_confirmation_and_invoice_then_delivers_each_once(): void
    {
        $customer = User::factory()->create();
        $subscription = app(CreateCustomerSubscription::class)->handle($customer, Plan::factory()->create()->id, 1);
        $subscription->update(['next_billing_on' => today()->addMonth()]);

        $this->artisan('billing:dispatch-emails')->assertSuccessful();
        $this->artisan('billing:dispatch-emails')->assertSuccessful();
        foreach (BillingEmail::all() as $email) {
            (new SendBillingEmail($email->id))->handle();
        }

        $this->assertDatabaseCount('billing_emails', 2);
        Mail::assertSent(BillingUpdate::class, 2);
        Mail::assertSent(BillingUpdate::class, fn ($mail) => $mail->hasTo($customer->email)
            && $mail->title === 'Your invoice is ready'
            && $mail->details['Invoice total'] === 'INR 2,950.00'
            && $mail->details['Due date'] === '14 Nov 2026');
        $this->assertSame(2, BillingEmail::whereNotNull('sent_at')->count());
    }

    public function test_drafts_do_not_notify_and_posting_an_invoice_notifies_automatically(): void
    {
        $invoice = Invoice::factory()->create(['status' => 'draft']);
        $this->assertDatabaseMissing('billing_emails', ['kind' => 'invoice_notification']);

        $invoice->update(['status' => 'confirmed']);
        $invoice->update(['due_on' => today()->addDays(10)]);
        $this->artisan('billing:dispatch-emails')->assertSuccessful();

        Mail::assertSent(BillingUpdate::class, fn ($mail) => $mail->title === 'Your invoice is ready');
        $this->assertSame(1, BillingEmail::where('kind', 'invoice_notification')->count());
    }

    public function test_rolled_back_changes_leave_no_email_to_dispatch(): void
    {
        $plan = Plan::factory()->create();
        $customer = User::factory()->create();
        DB::beginTransaction();
        app(CreateCustomerSubscription::class)->handle($customer, $plan->id, 1);
        DB::rollBack();

        $this->artisan('billing:dispatch-emails')->assertSuccessful();

        $this->assertDatabaseCount('billing_emails', 0);
        Mail::assertNothingSent();
    }

    public function test_seller_activation_and_reopening_send_confirmations_but_drafts_do_not(): void
    {
        $subscription = Subscription::factory()->create(['status' => 'draft', 'next_billing_on' => today()->addDay()]);
        $subscription->plan->update(['can_close' => true, 'can_renew' => true]);
        $staff = User::factory()->create(['role' => 'staff']);
        $transition = app(TransitionSubscription::class);
        $this->assertDatabaseCount('billing_emails', 0);
        $transition->handle($staff, $subscription, 'advance');
        $transition->handle($staff, $subscription, 'advance');
        $this->assertDatabaseCount('billing_emails', 0);
        $transition->handle($staff, $subscription, 'advance');
        $transition->handle($staff, $subscription, 'close');
        $transition->handle($staff, $subscription, 'renew');

        $this->artisan('billing:dispatch-emails')->assertSuccessful();

        $this->assertSame(2, BillingEmail::where('kind', 'subscription_confirmation')->count());
        $this->assertSame(1, BillingEmail::where('kind', 'cancellation_confirmation')->count());
        Mail::assertSent(BillingUpdate::class, 3);
    }

    public function test_reminder_windows_and_repeated_runs_do_not_spam_customers(): void
    {
        $due = Invoice::factory()->create(['due_on' => today()->addDays(3)]);
        $overdue = Invoice::factory()->create(['due_on' => today()->subDay()]);
        Invoice::factory()->create(['due_on' => today()->addDays(4)]);
        Invoice::factory()->create(['due_on' => today(), 'status' => 'paid']);
        Invoice::factory()->create(['due_on' => today(), 'status' => 'draft']);
        Subscription::factory()->create(['next_billing_on' => today()->addDays(7)]);
        Subscription::factory()->create(['next_billing_on' => today()->addDays(8)]);
        Subscription::factory()->create(['status' => 'paused', 'next_billing_on' => today()->addDays(2)]);
        Subscription::factory()->create(['next_billing_on' => today()->addDays(2), 'expires_on' => today()->addDays(2)]);

        $this->artisan('billing:send-reminders')->assertSuccessful();
        $this->artisan('billing:send-reminders')->assertSuccessful();

        $this->assertSame(1, BillingEmail::where('kind', 'payment_reminder')->count());
        $this->assertSame(1, BillingEmail::where('kind', 'overdue_alert')->count());
        $this->assertSame(1, BillingEmail::where('kind', 'renewal_reminder')->count());
        $this->assertDatabaseHas('billing_emails', ['kind' => 'payment_reminder', 'invoice_id' => $due->id]);
        $this->assertDatabaseHas('billing_emails', ['kind' => 'overdue_alert', 'invoice_id' => $overdue->id]);
    }

    public function test_payment_receipts_show_partial_amounts_and_reminders_use_current_balance(): void
    {
        $invoice = Invoice::factory()->create(['due_on' => today()->addDays(2)]);
        $this->artisan('billing:send-reminders')->assertSuccessful();
        $staff = User::factory()->create(['role' => 'staff']);
        app(RecordPayment::class)->handle($staff, $invoice, ['amount_cents' => 100000, 'method' => 'upi', 'paid_on' => today()->toDateString()]);

        $this->artisan('billing:dispatch-emails')->assertSuccessful();

        Mail::assertSent(BillingUpdate::class, fn ($mail) => $mail->title === 'Payment received'
            && $mail->details['Payment received'] === 'INR 1,000.00'
            && $mail->details['Outstanding balance'] === 'INR 4,900.00');
        Mail::assertSent(BillingUpdate::class, fn ($mail) => $mail->title === 'Payment due soon'
            && $mail->details['Outstanding balance'] === 'INR 4,900.00');
    }

    public function test_paid_invoices_and_paused_renewals_suppress_queued_reminders(): void
    {
        $invoice = Invoice::factory()->create(['due_on' => today()->subDay()]);
        $subscription = Subscription::factory()->create(['next_billing_on' => today()->addDays(5)]);
        $this->artisan('billing:send-reminders')->assertSuccessful();
        app(RecordPayment::class)->handle(User::factory()->create(['role' => 'staff']), $invoice,
            ['amount_cents' => 590000, 'method' => 'bank_transfer', 'paid_on' => today()->toDateString()]);
        $subscription->update(['status' => 'paused']);

        $this->artisan('billing:dispatch-emails')->assertSuccessful();

        Mail::assertNotSent(BillingUpdate::class, fn ($mail) => in_array($mail->title, ['Payment overdue', 'Subscription renewal approaching']));
        $this->assertSame(2, BillingEmail::whereNotNull('suppressed_at')->count());
    }

    public function test_closing_a_subscription_emails_confirmation_and_resuming_does_not_repeat_signup(): void
    {
        $subscription = Subscription::factory()->create(['next_billing_on' => today()->addMonth()]);
        $subscription->plan->update(['can_pause' => true, 'can_close' => true]);
        $actor = $subscription->user;
        $transition = app(TransitionSubscription::class);
        $transition->handle($actor, $subscription, 'pause');
        $transition->handle($actor, $subscription, 'resume');
        $transition->handle($actor, $subscription, 'close');

        $this->artisan('billing:dispatch-emails')->assertSuccessful();

        $this->assertSame(1, BillingEmail::where('kind', 'subscription_confirmation')->count());
        Mail::assertSent(BillingUpdate::class, fn ($mail) => $mail->title === 'Subscription cancellation confirmed'
            && $mail->hasTo($actor->email));
    }

    public function test_resuming_before_renewal_restores_a_reminder_suppressed_while_paused(): void
    {
        $subscription = Subscription::factory()->create(['next_billing_on' => today()->addDays(5)]);
        $this->artisan('billing:send-reminders')->assertSuccessful();
        $subscription->update(['status' => 'paused']);
        $this->artisan('billing:dispatch-emails')->assertSuccessful();
        Mail::assertNotSent(BillingUpdate::class, fn ($mail) => $mail->title === 'Subscription renewal approaching');
        $subscription->update(['status' => 'active']);

        $this->artisan('billing:send-reminders')->assertSuccessful();
        $this->artisan('billing:dispatch-emails')->assertSuccessful();

        Mail::assertSent(BillingUpdate::class, fn ($mail) => $mail->title === 'Subscription renewal approaching');
        $this->assertSame(1, BillingEmail::where('kind', 'renewal_reminder')->whereNotNull('sent_at')->count());
    }

    public function test_database_queue_dispatch_is_idempotent_and_failed_delivery_can_be_retried(): void
    {
        config(['queue.default' => 'database']);
        $subscription = Subscription::factory()->create();
        $email = BillingEmail::where('subscription_id', $subscription->id)->firstOrFail();

        $this->artisan('billing:dispatch-emails')->assertSuccessful();
        $this->artisan('billing:dispatch-emails')->assertSuccessful();

        $this->assertDatabaseCount('jobs', 1);
        $job = new SendBillingEmail($email->id);
        $job->failed(new \RuntimeException('Mail unavailable'));
        $job->handle();
        $this->assertNotNull($email->fresh()->sent_at);
        $this->assertNull($email->fresh()->failed_at);
    }

    public function test_resend_uses_the_configured_relay_and_reuses_its_idempotency_key_on_retry(): void
    {
        Http::preventStrayRequests();
        config(['mail.default' => 'resend', 'services.resend.url' => 'https://relay.example.test', 'services.resend.key' => 'test-key']);
        Http::fake(['https://relay.example.test/emails' => Http::sequence()->push(['message' => 'Temporary failure'], 503)->push(['id' => 'test-message'])]);
        $subscription = Subscription::factory()->create();
        $email = BillingEmail::where('subscription_id', $subscription->id)->firstOrFail();
        $job = new SendBillingEmail($email->id);
        try {
            $job->handle();
            $this->fail('Expected the mail provider failure to be reported.');
        } catch (RequestException) {
            $this->assertNull($email->fresh()->sent_at);
        }

        $job->handle();
        $job->handle();

        Http::assertSentCount(2);
        $requests = Http::recorded();
        $this->assertSame($requests[0][0]->header('Idempotency-Key'), $requests[1][0]->header('Idempotency-Key'));
        $this->assertNotNull($email->fresh()->sent_at);
    }

    public function test_email_bursts_are_delayed_and_delivered_after_the_rate_limit_window(): void
    {
        config(['queue.default' => 'database', 'billing.emails_per_minute' => 1]);
        Subscription::factory()->count(2)->create();
        $this->artisan('billing:dispatch-emails')->assertSuccessful();

        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();

        Mail::assertSent(BillingUpdate::class, 1);
        $this->assertDatabaseCount('jobs', 1);
        $this->travel(65)->seconds();
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
        Mail::assertSent(BillingUpdate::class, 2);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_email_content_escapes_customer_and_plan_names_and_links_to_the_portal(): void
    {
        $mail = new BillingUpdate('Subscription confirmed', '<script>alert(1)</script>', 'Your subscription has been activated.',
            ['Plan' => '<script>alert(2)</script>'], route('subscriptions'));

        $mail->assertDontSeeInHtml('<script>', false);
        $mail->assertSeeInHtml('&lt;script&gt;alert(2)&lt;/script&gt;', false);
        $mail->assertSeeInHtml(route('subscriptions'));
    }
}
