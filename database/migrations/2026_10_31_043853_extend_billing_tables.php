<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type')->default('software');
            $table->unsignedBigInteger('sales_price_cents');
            $table->unsignedBigInteger('cost_price_cents')->default(0);
            $table->unsignedInteger('tax_basis_points')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::table('plans', function (Blueprint $table): void {
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('available_from')->nullable();
            $table->date('available_until')->nullable();
            $table->boolean('can_pause')->default(true);
            $table->boolean('can_close')->default(true);
            $table->boolean('can_renew')->default(true);
            $table->boolean('auto_close')->default(true);
        });
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->date('expires_on')->nullable();
            $table->unsignedInteger('payment_terms_days')->default(14);
            $table->string('billing_interval')->default('monthly');
            $table->unsignedInteger('tax_basis_points')->default(0);
            $table->index(['status', 'next_billing_on']);
            $table->index(['user_id', 'id']);
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('total_cents')->change();
            $table->unsignedBigInteger('subtotal_cents')->default(0);
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->string('currency', 3)->default('INR');
            $table->json('snapshot')->nullable();
            $table->index(['status', 'due_on']);
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('amount_cents')->change();
            $table->index('invoice_id');
            $table->index('paid_on');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['invoice_id']);
            $table->dropIndex(['paid_on']);
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex(['status', 'due_on']);
            $table->dropColumn(['subtotal_cents', 'tax_cents', 'snapshot', 'currency']);
        });
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropIndex(['status', 'next_billing_on']);
            $table->dropIndex(['user_id', 'id']);
            $table->dropColumn(['expires_on', 'payment_terms_days', 'billing_interval', 'tax_basis_points']);
        });
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn(['available_from', 'available_until', 'can_pause', 'can_close', 'can_renew', 'auto_close']);
        });
        Schema::dropIfExists('products');
    }
};
