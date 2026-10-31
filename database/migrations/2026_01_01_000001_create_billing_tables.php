<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('customer');
        });
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('price_cents');
            $table->string('interval');
            $table->unsignedInteger('minimum_quantity')->default(1);
            $table->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('plan_id')->constrained();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_price_cents');
            $table->string('status')->default('draft');
            $table->date('starts_on');
            $table->date('next_billing_on');
            $table->timestamps();
        });
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained();
            $table->date('period_start');
            $table->date('due_on');
            $table->unsignedInteger('total_cents');
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->unique(['subscription_id', 'period_start']);
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained();
            $table->unsignedInteger('amount_cents');
            $table->string('method');
            $table->date('paid_on');
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('role'));
    }
};
