<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 40)->unique();
            $table->foreignId('business_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 20)->default('RETAIL');
            $table->string('status', 20)->default('POSTED');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('line_discount_total', 18, 2)->default(0);
            $table->decimal('invoice_discount_total', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('grand_total', 18, 2)->default(0);
            $table->decimal('paid_total', 18, 2)->default(0);
            $table->decimal('due_total', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('posted_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->timestamps();

            $table->index(['business_unit_id', 'posted_at']);
            $table->index(['customer_id', 'status']);
            $table->index(['status', 'due_total']);
            $table->index('posted_at');
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('line_type', 20);
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('serialized_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('inventory_cycle_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description');
            $table->string('identifier', 64)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('discount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2);
            $table->decimal('unit_cost', 18, 2)->default(0);
            $table->decimal('cost_total', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(['sale_id', 'serialized_unit_id']);
            $table->index('product_variant_id');
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('method', 30);
            $table->decimal('amount', 18, 2);
            $table->string('reference', 120)->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['sale_id', 'method']);
            $table->index(['account_id', 'received_at']);
        });

        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_no', 40)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('business_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->timestamps();

            $table->index(['customer_id', 'received_at']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->unique(['customer_payment_id', 'sale_id']);
            $table->index('sale_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('customer_payments');
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
