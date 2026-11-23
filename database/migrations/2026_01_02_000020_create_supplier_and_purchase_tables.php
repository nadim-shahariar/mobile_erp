<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->default('BUSINESS');
            $table->string('name', 180);
            $table->string('phone', 32)->nullable();
            $table->string('address')->nullable();
            $table->string('contact_person')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'name']);
            $table->index('phone');
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('document_no', 40)->unique();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('business_unit_id')->constrained()->restrictOnDelete();
            $table->date('purchase_date');
            $table->string('status', 20)->default('DRAFT');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('other_charges', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->decimal('due_amount', 18, 2)->default(0);
            $table->string('payment_method', 30)->nullable();
            $table->string('reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->timestamps();

            $table->index(['business_unit_id', 'purchase_date']);
            $table->index(['supplier_id', 'status']);
            $table->index(['status', 'purchase_date']);
        });

        Schema::create('purchase_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('line_type', 20);
            // Serial identifiers are captured on the draft line so the Excel
            // entry screen can save a purchase before stock is received. They
            // are only registered as identities when the purchase is received.
            $table->string('imei_1', 32)->nullable();
            $table->string('imei_2', 32)->nullable();
            $table->json('serial_payload')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_cost', 18, 2);
            $table->decimal('line_total', 18, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['product_variant_id', 'line_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_lines');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('suppliers');
    }
};
