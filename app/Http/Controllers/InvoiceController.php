<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function show(Request $request, Invoice $invoice): View
    {
        $invoice->load('subscription', 'payments')->loadSum('payments', 'amount_cents');
        abort_unless($request->user()->isTeamMember() || $invoice->subscription->user_id === $request->user()->id, 404);

        return view('invoices.show', compact('invoice'));
    }
}
