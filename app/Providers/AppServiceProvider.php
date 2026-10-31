<?php

namespace App\Providers;

use App\Actions\QueueBillingEmail;
use App\Actions\RecordActivity;
use App\Models\BillingIssue;
use App\Models\Discount;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\QuotationTemplate;
use App\Models\QuotationTemplateLine;
use App\Models\Subscription;
use App\Models\SubscriptionLine;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach ([
            Subscription::class, Invoice::class, Payment::class, Plan::class,
            Product::class, ProductVariant::class, Discount::class,
            SubscriptionLine::class, InvoiceLine::class,
            QuotationTemplate::class, QuotationTemplateLine::class, BillingIssue::class,
        ] as $model) {
            $model::created(fn (Model $record) => app(RecordActivity::class)->handle($record, 'created'));
            $model::updated(fn (Model $record) => app(RecordActivity::class)->handle($record, 'updated'));
            $model::deleted(fn (Model $record) => app(RecordActivity::class)->handle($record, 'deleted'));
        }
        RateLimiter::for('billing-email', fn () => Limit::perMinute(max(1, config('billing.emails_per_minute')))->by('billing-email'));
        Subscription::created(fn (Subscription $subscription) => app(QueueBillingEmail::class)->subscription($subscription, true));
        Subscription::updated(fn (Subscription $subscription) => app(QueueBillingEmail::class)->subscription($subscription));
        Invoice::created(fn (Invoice $invoice) => app(QueueBillingEmail::class)->invoice($invoice, true));
        Invoice::updated(fn (Invoice $invoice) => app(QueueBillingEmail::class)->invoice($invoice));
        Payment::created(fn (Payment $payment) => app(QueueBillingEmail::class)->payment($payment));

        if ($this->app->isProduction()) {
            URL::useOrigin(config('app.url'));
            URL::forceScheme(parse_url(config('app.url'), PHP_URL_SCHEME));
        }

        Password::defaults(fn () => Password::min(9)->mixedCase()->symbols());
        Gate::define('manage-catalog', fn (User $user): bool => $user->role === 'admin');
        Gate::define('manage-billing', fn (User $user): bool => $user->isTeamMember());
        Model::preventLazyLoading(! $this->app->isProduction());
    }
}
