<?php

namespace App\Livewire;

use App\Actions\GenerateRenewalInvoices;
use App\Actions\RecordPayment;
use App\Actions\TransitionSubscription;
use App\Mail\InvoiceReady;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class BillingWorkspace extends Component
{
    use WithPagination;

    #[Locked]
    public string $section = 'dashboard';

    public string $search = '';

    public string $status = '';

    public string $from = '';

    public string $until = '';

    public bool $showForm = false;

    public array $product = ['name' => '', 'type' => 'software', 'sales_price' => '', 'cost_price' => '0', 'tax_percent' => '0', 'notes' => ''];

    public array $plan = ['name' => '', 'product_id' => '', 'price' => '', 'interval' => 'monthly', 'minimum_quantity' => 1, 'available_from' => '', 'available_until' => '', 'can_pause' => true, 'can_close' => true, 'can_renew' => true, 'auto_close' => true];

    public array $subscription = ['user_id' => '', 'plan_id' => '', 'quantity' => 1, 'starts_on' => '', 'expires_on' => '', 'payment_terms_days' => 14];

    public array $person = ['name' => '', 'email' => '', 'password' => '', 'role' => 'customer'];

    public ?int $paymentInvoice = null;

    public string $paymentAmount = '';

    public string $paymentMethod = 'bank_transfer';

    public string $paymentDate = '';

    public string $paymentNote = '';

    public function mount(string $section = 'dashboard'): void
    {
        abort_unless(in_array($section, ['dashboard', 'subscriptions', 'invoices', 'catalog', 'customers', 'reports'], true), 404);
        if (in_array($section, ['catalog', 'customers', 'reports'], true)) {
            Gate::authorize('manage-billing');
        }
        $this->section = $section;
        $this->subscription['starts_on'] = today()->toDateString();
        $this->paymentDate = today()->toDateString();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedFrom(): void
    {
        $this->resetPage();
    }

    public function updatedUntil(): void
    {
        $this->resetPage();
    }

    public function clearDates(): void
    {
        $this->reset('from', 'until');
        $this->resetPage();
    }

    private function moneyRules(): array
    {
        return ['required', 'regex:/^\d{1,6}(\.\d{1,2})?$/'];
    }

    private function cents(string $amount): int
    {
        $parts = explode('.', $amount);

        return ((int) $parts[0] * 100) + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    public function saveProduct(): void
    {
        Gate::authorize('manage-catalog');
        $this->validate([
            'product.name' => 'required|string|max:120',
            'product.type' => 'required|in:software,service,addon',
            'product.sales_price' => $this->moneyRules(), 'product.cost_price' => $this->moneyRules(),
            'product.tax_percent' => ['required', 'numeric', 'min:0', 'max:100', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'product.notes' => 'nullable|string|max:2000',
        ]);
        Product::create([
            'name' => $this->product['name'], 'type' => $this->product['type'],
            'sales_price_cents' => $this->cents($this->product['sales_price']),
            'cost_price_cents' => $this->cents($this->product['cost_price']),
            'tax_basis_points' => $this->cents($this->product['tax_percent']),
            'notes' => $this->product['notes'] ?? null,
        ]);
        $this->reset('product');
        session()->flash('success', 'Product created. You can now add a recurring plan.');
    }

    public function savePlan(): void
    {
        Gate::authorize('manage-catalog');
        $data = $this->validate([
            'plan.name' => 'required|string|max:120', 'plan.product_id' => 'required|exists:products,id',
            'plan.price' => $this->moneyRules(), 'plan.interval' => 'required|in:daily,weekly,monthly,yearly',
            'plan.minimum_quantity' => 'required|integer|min:1|max:10000',
            'plan.available_from' => 'nullable|date_format:Y-m-d',
            'plan.available_until' => ['nullable', 'date_format:Y-m-d', Rule::when(! empty($this->plan['available_from']), 'after_or_equal:plan.available_from')],
            'plan.can_pause' => 'boolean', 'plan.can_close' => 'boolean',
            'plan.can_renew' => 'boolean', 'plan.auto_close' => 'boolean',
        ])['plan'];
        $data['price_cents'] = $this->cents($data['price']);
        unset($data['price']);
        $data['available_from'] = ($data['available_from'] ?? null) ?: null;
        $data['available_until'] = ($data['available_until'] ?? null) ?: null;
        Plan::create($data);
        $this->reset('plan');
        session()->flash('success', 'Plan created.');
    }

    public function saveSubscription(): void
    {
        Gate::authorize('manage-billing');
        $data = $this->validate([
            'subscription.user_id' => ['required', Rule::exists('users', 'id')->where('role', 'customer')],
            'subscription.plan_id' => 'required|exists:plans,id',
            'subscription.quantity' => 'required|integer|min:1|max:10000',
            'subscription.starts_on' => 'required|date_format:Y-m-d',
            'subscription.expires_on' => 'nullable|date_format:Y-m-d|after:subscription.starts_on',
            'subscription.payment_terms_days' => 'required|integer|min:0|max:365',
        ])['subscription'];
        $plan = Plan::with('product')->findOrFail($data['plan_id']);
        if ($data['quantity'] < $plan->minimum_quantity) {
            throw ValidationException::withMessages(['subscription.quantity' => 'This plan requires at least '.$plan->minimum_quantity.' units.']);
        }
        if (($plan->available_from && $data['starts_on'] < $plan->available_from->toDateString())
            || ($plan->available_until && $data['starts_on'] > $plan->available_until->toDateString())) {
            throw ValidationException::withMessages(['subscription.starts_on' => 'Choose a date within the plan availability window.']);
        }
        Subscription::create([
            ...$data, 'expires_on' => ($data['expires_on'] ?? null) ?: null, 'status' => 'draft',
            'unit_price_cents' => $plan->price_cents, 'next_billing_on' => $data['starts_on'],
            'billing_interval' => $plan->interval, 'tax_basis_points' => $plan->product?->tax_basis_points ?? 0,
        ]);
        $this->reset('subscription', 'showForm');
        $this->subscription['starts_on'] = today()->toDateString();
        session()->flash('success', 'Draft subscription created. Advance it through quotation and confirmation to activate billing.');
    }

    public function savePerson(): void
    {
        Gate::authorize('manage-billing');
        $data = $this->validate([
            'person.name' => 'required|string|max:255', 'person.email' => 'required|email|max:255',
            'person.password' => ['required', 'string', Password::defaults()],
            'person.role' => 'required|in:customer,staff',
        ])['person'];
        if ($data['role'] === 'staff') {
            Gate::authorize('manage-catalog');
        }
        $data['email'] = mb_strtolower($data['email']);
        validator($data, ['email' => 'unique:users,email'])->validate();
        $role = $data['role'];
        unset($data['role']);
        $user = new User($data);
        $user->role = $role;
        $user->save();
        $this->reset('person', 'showForm');
        session()->flash('success', 'Account created.');
    }

    public function changeSubscriptionStatus(int $id, string $action, TransitionSubscription $transition): void
    {
        $this->resetValidation();
        $transition->handle(Auth::user(), Subscription::findOrFail($id), $action);
        session()->flash('success', 'Subscription updated.');
    }

    public function generateInvoices(GenerateRenewalInvoices $generator): void
    {
        Gate::authorize('manage-billing');
        $this->resetValidation();
        $count = 0;
        Subscription::dueForBilling()
            ->chunkById(100, function ($subscriptions) use ($generator, &$count): void {
                foreach ($subscriptions as $subscription) {
                    $count += $generator->handle($subscription);
                }
            });
        session()->flash('success', $count.' draft invoice(s) generated. Review and confirm them before sending.');
    }

    public function confirmInvoice(int $id): void
    {
        Gate::authorize('manage-billing');
        $this->resetValidation();
        DB::transaction(function () use ($id): void {
            $invoice = Invoice::lockForUpdate()->findOrFail($id);
            if ($invoice->status !== 'draft') {
                throw ValidationException::withMessages(['invoice' => 'Only draft invoices can be confirmed.']);
            }
            $invoice->update(['status' => $invoice->total_cents === 0 ? 'paid' : 'confirmed']);
        });
        session()->flash('success', 'Invoice confirmed.');
    }

    public function cancelInvoice(int $id): void
    {
        Gate::authorize('manage-billing');
        $this->resetValidation();
        DB::transaction(function () use ($id): void {
            $invoice = Invoice::lockForUpdate()->findOrFail($id);
            if (! in_array($invoice->status, ['draft', 'confirmed'], true) || $invoice->payments()->exists()) {
                throw ValidationException::withMessages(['invoice' => 'Only unpaid invoices without payments can be cancelled.']);
            }
            $invoice->update(['status' => 'cancelled']);
        });
        session()->flash('success', 'Invoice cancelled. Its billing period remains recorded.');
    }

    public function sendInvoice(int $id): void
    {
        Gate::authorize('manage-billing');
        $this->resetValidation();
        $invoice = Invoice::findOrFail($id);
        if (! in_array($invoice->status, ['confirmed', 'paid'], true)) {
            throw ValidationException::withMessages(['invoice' => 'Confirm this invoice before sending it.']);
        }
        Mail::to($invoice->snapshot['email'])->send((new InvoiceReady($invoice))->afterCommit());
        session()->flash('success', 'Invoice email queued.');
    }

    public function selectPayment(int $id): void
    {
        Gate::authorize('manage-billing');
        $invoice = Invoice::withSum('payments', 'amount_cents')->findOrFail($id);
        abort_unless($invoice->status === 'confirmed', 422);
        $this->paymentInvoice = $id;
        $balance = $invoice->balanceCents();
        $this->paymentAmount = intdiv($balance, 100).'.'.str_pad((string) ($balance % 100), 2, '0', STR_PAD_LEFT);
        $this->paymentDate = today()->toDateString();
        $this->resetValidation();
    }

    public function savePayment(RecordPayment $recorder): void
    {
        Gate::authorize('manage-billing');
        $this->validate([
            'paymentInvoice' => 'required|exists:invoices,id', 'paymentAmount' => $this->moneyRules(),
            'paymentMethod' => 'required|in:bank_transfer,cash,card,upi,other',
            'paymentDate' => 'required|date_format:Y-m-d|before_or_equal:today',
            'paymentNote' => 'nullable|string|max:255',
        ]);
        $recorder->handle(Auth::user(), Invoice::findOrFail($this->paymentInvoice), [
            'amount_cents' => $this->cents($this->paymentAmount), 'method' => $this->paymentMethod,
            'paid_on' => $this->paymentDate, 'note' => $this->paymentNote ?: null,
        ]);
        $this->reset('paymentInvoice', 'paymentAmount', 'paymentNote');
        session()->flash('success', 'Payment recorded. The outstanding balance has been updated.');
    }

    private function invoiceQuery(): Builder
    {
        return Invoice::query()->when(! Auth::user()->isTeamMember(),
            fn (Builder $query) => $query->whereHas('subscription', fn (Builder $subscription) => $subscription->where('user_id', Auth::id())));
    }

    public function render(): View
    {
        $this->validate([
            'search' => 'string|max:120', 'status' => 'string|max:30',
            'from' => 'nullable|date_format:Y-m-d', 'until' => ['nullable', 'date_format:Y-m-d', Rule::when((bool) $this->from, 'after_or_equal:from')],
        ]);
        $team = Auth::user()->isTeamMember();
        $invoices = $this->invoiceQuery()->with('subscription.user')->withSum('payments', 'amount_cents');
        $subscriptions = Subscription::with(['user', 'plan'])
            ->when(! $team, fn (Builder $query) => $query->where('user_id', Auth::id()));
        $active = (clone $subscriptions)->where('status', 'active')->count();
        $posted = $this->invoiceQuery()->whereIn('status', ['confirmed', 'paid']);
        if ($this->section === 'reports') {
            $posted->when($this->from, fn (Builder $q) => $q->whereDate('period_start', '>=', $this->from))
                ->when($this->until, fn (Builder $q) => $q->whereDate('period_start', '<=', $this->until));
        }
        $postedIds = (clone $posted)->select('invoices.id');
        $received = Payment::whereIn('invoice_id', $postedIds)->sum('amount_cents');
        $outstanding = (int) (clone $posted)->sum('total_cents') - (int) $received;
        $overdue = (clone $posted)->where('status', 'confirmed')->whereDate('due_on', '<', today())->count();
        if ($this->search !== '') {
            $term = '%'.$this->search.'%';
            $subscriptions->where(fn (Builder $q) => $q->whereHas('user', fn (Builder $u) => $u->whereLike('name', $term))->orWhereHas('plan', fn (Builder $p) => $p->whereLike('name', $term)));
            $invoices->whereHas('subscription.user', fn (Builder $q) => $q->whereLike('name', $term));
        }
        if ($this->status !== '') {
            if ($this->status === 'overdue') {
                $invoices->where('status', 'confirmed')->whereDate('due_on', '<', today());
            } else {
                $invoices->where('status', $this->status);
                $subscriptions->where('status', $this->status);
            }
        }
        $invoices->when($this->from, fn (Builder $q) => $q->whereDate('period_start', '>=', $this->from))
            ->when($this->until, fn (Builder $q) => $q->whereDate('period_start', '<=', $this->until));

        return view('livewire.billing-workspace', [
            'team' => $team, 'metrics' => compact('active', 'received', 'outstanding', 'overdue'),
            'invoices' => in_array($this->section, ['dashboard', 'invoices', 'reports'], true) ? $invoices->latest('id')->paginate(10) : null,
            'subscriptions' => $this->section === 'subscriptions' ? $subscriptions->latest('id')->paginate(10) : null,
            'plans' => in_array($this->section, ['catalog', 'subscriptions'], true) ? Plan::with('product')->orderBy('name')->limit(200)->get() : collect(),
            'products' => $this->section === 'catalog' ? Product::orderBy('name')->limit(200)->get() : collect(),
            'customers' => $team && $this->section === 'subscriptions' ? User::where('role', 'customer')->orderBy('name')->limit(200)->get() : collect(),
            'people' => $this->section === 'customers' ? User::whereIn('role', ['customer', 'staff'])->whereLike('name', '%'.$this->search.'%')->latest('id')->paginate(10) : null,
        ])->layout('layouts.workspace');
    }
}
