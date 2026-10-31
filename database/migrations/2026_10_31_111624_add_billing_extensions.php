<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->bigInteger('price_modifier_cents')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['product_id', 'name']);
        });
        Schema::create('quotation_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('validity_days')->default(30);
            $table->unsignedInteger('payment_terms_days')->default(14);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('quotation_template_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('position');
            $table->timestamps();
            $table->unique(['quotation_template_id', 'position'], 'quotation_lines_position_unique');
        });
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->foreignId('quotation_template_id')->nullable()->constrained()->nullOnDelete();
            $table->date('quotation_expires_on')->nullable();
            $table->unsignedBigInteger('unit_price_cents')->change();
        });
        Schema::create('subscription_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->string('variant_name', 120)->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_cents');
            $table->unsignedInteger('tax_basis_points')->default(0);
            $table->unsignedInteger('position');
            $table->timestamps();
            $table->unique(['subscription_id', 'position']);
        });
        Schema::create('discounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('type');
            $table->unsignedBigInteger('value');
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('minimum_quantity')->default(1);
            $table->unsignedBigInteger('minimum_purchase_cents')->default(0);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('discount_cents')->default(0);
        });
        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->string('variant_name', 120)->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_cents');
            $table->unsignedInteger('tax_basis_points')->default(0);
            $table->unsignedBigInteger('subtotal_cents');
            $table->unsignedBigInteger('discount_cents')->default(0);
            $table->unsignedBigInteger('tax_cents');
            $table->unsignedBigInteger('total_cents');
            $table->unsignedInteger('position');
            $table->timestamps();
            $table->unique(['invoice_id', 'position']);
        });
        Schema::create('discount_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('discount_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->timestamps();
            $table->unique('invoice_id');
        });
        $this->backfillLines();
    }

    private function backfillLines(): void
    {
        DB::table('subscriptions')->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->leftJoin('products', 'products.id', '=', 'plans.product_id')
            ->select('subscriptions.*', 'plans.product_id', 'plans.name as plan_name', 'products.name as product_name')
            ->chunkById(200, function ($subscriptions): void {
                $rows = [];
                foreach ($subscriptions as $subscription) {
                    $rows[] = [
                        'subscription_id' => $subscription->id, 'product_id' => $subscription->product_id,
                        'description' => $subscription->product_name ?? $subscription->plan_name,
                        'quantity' => $subscription->quantity, 'unit_price_cents' => $subscription->unit_price_cents,
                        'tax_basis_points' => $subscription->tax_basis_points, 'position' => 0,
                        'created_at' => $subscription->created_at, 'updated_at' => $subscription->updated_at,
                    ];
                }
                if ($rows) {
                    DB::table('subscription_lines')->insert($rows);
                }
            }, 'subscriptions.id', 'id');
        DB::table('invoices')->chunkById(200, function ($invoices): void {
            $rows = [];
            foreach ($invoices as $invoice) {
                $snapshot = json_decode($invoice->snapshot ?? '{}', true) ?? [];
                $quantity = max(1, (int) ($snapshot['quantity'] ?? 1));
                $rows[] = [
                    'invoice_id' => $invoice->id, 'description' => $snapshot['product'] ?? $snapshot['plan'] ?? 'Subscription',
                    'quantity' => $quantity, 'unit_price_cents' => $snapshot['unit_price_cents'] ?? intdiv($invoice->subtotal_cents, $quantity),
                    'tax_basis_points' => $snapshot['tax_basis_points'] ?? 0, 'position' => 0,
                    'subtotal_cents' => $invoice->subtotal_cents, 'tax_cents' => $invoice->tax_cents,
                    'total_cents' => $invoice->total_cents, 'created_at' => $invoice->created_at, 'updated_at' => $invoice->updated_at,
                ];
            }
            if ($rows) {
                DB::table('invoice_lines')->insert($rows);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_redemptions');
        Schema::dropIfExists('invoice_lines');
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('discount_cents'));
        Schema::dropIfExists('discounts');
        Schema::dropIfExists('subscription_lines');
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('quotation_template_id');
            $table->dropColumn('quotation_expires_on');
        });
        Schema::dropIfExists('quotation_template_lines');
        Schema::dropIfExists('quotation_templates');
        Schema::dropIfExists('product_variants');
    }
};
