<?php

namespace App\Actions;

use App\Models\Plan;
use App\Models\QuotationTemplate;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreateSubscriptionDraft
{
    public function __construct(private BuildSubscriptionLines $buildLines) {}

    /** @param array<string, mixed> $data
     * @param  array<int, array{product_id: int|string, product_variant_id?: int|string|null, quantity: int|string}>  $items
     */
    public function handle(User $actor, array $data, array $items = [], ?int $variantId = null, ?int $templateId = null): Subscription
    {
        Gate::forUser($actor)->authorize('manage-billing');
        $data = Validator::make($data, [
            'user_id' => ['required', Rule::exists('users', 'id')->where('role', 'customer')],
            'plan_id' => 'required|integer|exists:plans,id', 'quantity' => 'required|integer|min:1|max:10000',
            'starts_on' => 'required|date_format:Y-m-d', 'expires_on' => 'nullable|date_format:Y-m-d|after:starts_on',
            'payment_terms_days' => 'required|integer|min:0|max:365',
        ])->validate();

        return DB::transaction(function () use ($data, $items, $variantId, $templateId): Subscription {
            $plan = Plan::with('product')->findOrFail($data['plan_id']);
            if ($data['quantity'] < $plan->minimum_quantity) {
                throw ValidationException::withMessages(['subscription.quantity' => 'This plan requires at least '.$plan->minimum_quantity.' units.']);
            }
            if (($plan->available_from && $data['starts_on'] < $plan->available_from->toDateString())
                || ($plan->available_until && $data['starts_on'] > $plan->available_until->toDateString())) {
                throw ValidationException::withMessages(['subscription.starts_on' => 'Choose a date within the plan availability window.']);
            }
            $template = $templateId ? QuotationTemplate::where('is_active', true)->find($templateId) : null;
            if ($templateId && (! $template || $template->plan_id !== $plan->id)) {
                throw ValidationException::withMessages(['templateId' => 'Choose an active quotation template for the selected plan.']);
            }
            $lines = $this->buildLines->handle($plan, (int) $data['quantity'], $variantId, $items);
            $subscription = Subscription::create([
                ...$data, 'expires_on' => ($data['expires_on'] ?? null) ?: null,
                'status' => 'draft', 'next_billing_on' => $data['starts_on'], 'billing_interval' => $plan->interval,
                'unit_price_cents' => $lines[0]['unit_price_cents'], 'tax_basis_points' => $lines[0]['tax_basis_points'],
                'quotation_template_id' => $template?->id,
                'quotation_expires_on' => $template ? today()->addDays($template->validity_days) : null,
            ]);
            $subscription->lines()->createMany($lines);

            return $subscription;
        });
    }
}
