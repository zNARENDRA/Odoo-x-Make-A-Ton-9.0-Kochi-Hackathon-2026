<?php

namespace App\Actions;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RecordPayment
{
    /** @param array{amount_cents: int, method: string, paid_on: string, note?: ?string} $data */
    public function handle(User $actor, Invoice $invoice, array $data): Payment
    {
        Gate::forUser($actor)->authorize('manage-billing');

        return DB::transaction(function () use ($invoice, $data): Payment {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== 'confirmed') {
                throw ValidationException::withMessages(['paymentAmount' => 'Only confirmed invoices accept payments.']);
            }
            $balance = $invoice->balanceCents();
            if ($data['amount_cents'] < 1 || $data['amount_cents'] > $balance) {
                throw ValidationException::withMessages(['paymentAmount' => 'Payment must be positive and cannot exceed the outstanding balance.']);
            }
            $payment = $invoice->payments()->create($data);
            if ($data['amount_cents'] === $balance) {
                $invoice->update(['status' => 'paid']);
            }

            return $payment;
        }, 3);
    }
}
