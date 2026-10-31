# Brightloop Subscription Management

[Deployed application](https://subscription-management-system.antideploy.app)

A working first implementation using PHP 8.5, Laravel 13, the official Livewire starter kit authentication (Fortify + Flux), Livewire 4, Tailwind CSS 4, Vite and PostgreSQL. Database-backed sessions, cache locks and queues are enabled.

## Run on this Windows machine

PHP with the PostgreSQL driver and a local PostgreSQL 18 runtime have been installed under `%LOCALAPPDATA%\SubscriptionManagement`. The database listens only on `127.0.0.1:5433`. Application credentials are in the ignored `.env` file. No Windows service or scheduled tasks were registered automatically.

From this project directory:

```powershell
.\dev.ps1
```

This starts the local database if necessary, serves the app on port 8000, and runs the database queue worker and Laravel scheduler. Press Ctrl+C to stop the web app and workers. PostgreSQL stays running. If the app is already running on port 8000, open it directly.

The local demo seeder creates `admin@brightloop.test` and prints a randomly generated initial password on its first run. To create your own administrator with an interactively entered password:

```powershell
& "$env:LOCALAPPDATA\SubscriptionManagement\php\php.exe" artisan app:create-admin you@example.com --name="Your Name"
```

Self-registration always creates a customer. Only admins can create internal staff. Team members can create customer accounts and run billing; customers can view only their own records.

## Fresh environment

Requirements: PHP 8.3+ with PDO PostgreSQL, Composer, Node.js/npm and PostgreSQL. The local runtime used here is PHP 8.5.12; the PHP bootstrap build from php.new lacks pdo_pgsql, so use the project-local runtime or enable the extension in another PHP installation.

1. Run `composer install` and `npm ci`.
2. Copy `.env.example` to `.env`, set a strong PostgreSQL password and your database connection details, then create the database.
3. Run `php artisan key:generate`, `php artisan migrate`, and `npm run build`.
4. Create an administrator with `php artisan app:create-admin`.
5. Optionally run `php artisan db:seed` in the local environment to create sample plans, customers, subscriptions, partial payments and invoices.
6. Run `php artisan serve`, `php artisan queue:work --tries=3`, and `php artisan schedule:work` in separate terminals, or use `dev.ps1` on Windows.

Set `APP_URL` to the address customers use, `APP_TIMEZONE` for business dates (default Asia/Kolkata), and `BILLING_CURRENCY` before creating billing records (default INR). This first release assumes one currency and a two-decimal minor unit.

## Billing flow

1. An admin adds a product, its tax percentage, and a daily, weekly, monthly or yearly plan.
2. A team member creates a customer subscription, optionally using a quotation template. Add product lines and variants as needed. Unit prices, variant names and per-product tax rates are captured in the draft; all lines repeat on the selected plan's billing interval.
3. Advance Draft → Quotation → Confirmed → Active.
4. Click **Generate due invoices** or run `php artisan billing:generate-invoices`. The scheduler runs this check every minute.
5. Review the generated draft invoice and confirm it. Send it to the queue or print it from its detail page.
6. Record payments already received. Partial payments reduce the balance; a final payment marks the invoice paid.

Invoice generation locks each subscription and records each billing period once, protected by a unique database constraint. It catches up missed periods in order, up to 100 per subscription per invocation; further overdue periods are processed on the next run. Monthly billing preserves the original day across short months. Expiry dates are exclusive: no period starting on or after expiry is generated. Paused subscriptions are not billed; resuming does not back-bill the paused interval. Team members generate due periods before pausing or closing. Customer self-service automatically generates due periods before these actions.

Plan flags control pause, early closure, renewal and automatic closure. Customers can pause, resume, renew or close only their own subscriptions when the plan permits it. Renewal extends expiry by one interval and can reopen a closed subscription. Cancelling an invoice preserves its period, so retrying billing does not recreate it. Invoices with payments cannot be cancelled. Tax is calculated per product line after discounts, rounded half-up to the minor unit.

Admins manage variants, discounts and quotation templates under **Products & plans**. Variant modifiers apply per unit and can increase or reduce the base price, but the resulting unit price cannot be negative. Templates pre-fill a plan, variants, quantities, additional lines and payment terms; quotation validity is recorded when the draft is saved. An expired quotation cannot be confirmed.

Discounts can be fixed amounts or percentages, scoped to a product, a subscription, both, or all records. The best eligible rule applies once per invoice, before tax; rules do not stack. Minimum quantity and purchase requirements use only qualifying lines. Start and end dates are inclusive and are evaluated against each billing period, including catch-up invoices. Fixed savings are capped at the eligible subtotal and allocated proportionally across lines with exact minor-unit reconciliation. Each generated invoice consumes one use; cancellation does not restore it. Row locks and an invoice redemption constraint protect shared usage limits and retries.

Existing subscriptions and invoices are migrated to line records without recalculating historical prices or totals. Optional local examples can be added with `php artisan db:seed --class=BillingExtensionsSeeder`: an Odoo variant, a support add-on, a quotation template and a disabled volume discount. Enable the sample discount explicitly if you want to use it.

Dashboard and report totals exclude draft and cancelled invoices. Report date filters select invoice billing periods; payments are attributed to those invoices regardless of collection date. Active subscriptions are the current count. Lists are paginated and customer queries are scoped by account.

## Email and background processing

The default `MAIL_MAILER=log` writes invoice emails and password-reset messages to `storage/logs/laravel.log`. It does not deliver to an inbox. Configure SMTP in `.env` before using real email delivery. Invoice emails are queued with three attempts and a 60-second backoff. Failed jobs can be inspected with `php artisan queue:failed`.

The scheduler also checks billing problems every five minutes, dispatches billing emails every minute, and checks for payment and renewal reminders hourly. Configure reminder timing with `BILLING_PAYMENT_REMINDER_DAYS` and `BILLING_RENEWAL_REMINDER_DAYS`, and the dispatch rate with `BILLING_EMAILS_PER_MINUTE`. The billing operations page lets authorized team members review activity and resolve detected issues.

The payment form records received payments; it does not charge a card or bank account.

## Windows Task Scheduler

For checks independent of a development terminal, inspect and run:

```powershell
.\register-windows-tasks.ps1
```

This registers two tasks for the current user: `Brightloop-Scheduler` and `Brightloop-Queue`. Both run every minute while the user is signed in and skip overlapping invocations. PostgreSQL must already be running. Use `dev.ps1 -NoWorkers` when these tasks are active. An unattended server deployment requires service supervision and a continuously available database.

## Verification

```powershell
php artisan test --compact
.\test-postgres.ps1
npm run build
php vendor/bin/pint --dirty --format agent
```

The default test suite uses isolated in-memory SQLite. The PostgreSQL script uses a separate database named `subscription_management_test` on the configured PostgreSQL server; create this test database before running it. Never point tests at application data.

## Scope of this first implementation

Included: starter-kit login, registration and password reset; admin/staff/customer authorization; products, variants and plans; multi-product subscription and invoice lines; reusable quotation templates; automatic fixed and percentage discounts with eligibility rules and usage limits; customer subscription self-service; recurring draft invoices and immutable price/tax snapshots; confirmation, cancellation, queued itemized email and print views; partial payments; dashboard and billing-period report filters; scheduler and database queues.

Follow-up work: editing and audit history, payment gateway integration, richer report exports, and performance measurement with thousands of records. Product, plan and customer selection lists currently show up to 200 records. The two-second performance target has not been certified.

Authentication views and Fortify actions were adapted from Laravel's official MIT-licensed Livewire starter kit: https://github.com/laravel/livewire-starter-kit.
