<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_no', 40)->unique();
            $table->foreignId('from_unit_id')->constrained('business_units')->restrictOnDelete();
            $table->foreignId('to_unit_id')->constrained('business_units')->restrictOnDelete();
            $table->string('status', 20)->default('REQUESTED');
            $table->text('notes')->nullable();

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at');

            $table->foreignId('dispatched_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('dispatched_at')->nullable();

            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at')->nullable();

            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();

            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->timestamps();

            $table->index(['status', 'from_unit_id']);
            $table->index(['status', 'to_unit_id']);
            $table->index(['requested_by', 'requested_at']);
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->string('line_type', 20);
            $table->foreignId('serialized_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity')->nullable();
            $table->decimal('unit_cost', 18, 2)->nullable();
            $table->timestamps();

            $table->unique(['stock_transfer_id', 'serialized_unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
    }
};
