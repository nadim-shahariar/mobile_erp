<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_lots', function (Blueprint $t) {
            $t->id();
            $t->string('lot_no')->unique();
            $t->foreignId('supplier_id')->constrained();
            $t->foreignId('business_unit_id')->constrained();
            $t->date('order_date');
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('purchase_lot_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_lot_id')->constrained();
            $t->foreignId('product_variant_id')->constrained();
            $t->string('expected_imei', 32)->nullable()->index();
            $t->string('actual_imei', 32)->nullable()->index();
            $t->string('status')->default('PENDING')->index();
            $t->decimal('unit_cost', 15, 2);
            $t->text('pending_reason')->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->foreignId('purchase_id')->nullable()->constrained();
            $t->timestamp('received_at')->nullable();
            $t->timestamps();
        });
        Schema::create('supplier_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_id')->constrained();
            $t->foreignId('business_unit_id')->constrained();
            $t->decimal('amount', 15, 2);
            $t->string('payment_method');
            $t->string('reference')->nullable();
            $t->json('allocations');
            $t->uuid('idempotency_key')->unique();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('purchase_lot_lines');
        Schema::dropIfExists('purchase_lots');
    }
};
