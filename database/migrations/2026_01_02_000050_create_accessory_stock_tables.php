<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accessory_stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->integer('qty_on_hand')->default(0);
            $table->integer('qty_reserved')->default(0);
            $table->decimal('average_cost', 18, 4)->default(0);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamps();

            $table->unique(['business_unit_id', 'product_variant_id']);
            $table->index(['product_variant_id', 'business_unit_id']);
        });

        Schema::create('accessory_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->integer('quantity_delta');
            $table->string('reason', 32);
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->integer('balance_after');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_variant_id', 'business_unit_id', 'created_at'], 'asm_variant_unit_time_idx');
            $table->index(['source_type', 'source_id']);
            $table->index(['reason', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accessory_stock_movements');
        Schema::dropIfExists('accessory_stock_balances');
    }
};
