<?php

use App\Http\Controllers\InvoiceController;
use App\Livewire\BillingWorkspace;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => to_route(auth()->check() ? 'dashboard' : 'login'))->name('home');
Route::middleware('auth')->group(function (): void {
    foreach (['dashboard', 'subscriptions', 'invoices', 'catalog', 'customers', 'reports'] as $section) {
        Route::get('/'.$section, BillingWorkspace::class)->defaults('section', $section)->name($section);
    }
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
});
