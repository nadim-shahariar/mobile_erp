<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchanges', function (Blueprint $table) {
            $table->id();
            $table->string('document_no')->unique();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('business_unit_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('sale_id')->nullable()->unique()->constrained();
            $table->foreignId('serialized_unit_id')->nullable()->constrained();
            $table->foreignId('product_variant_id')->nullable()->constrained();
            $table->string('description');
            $table->string('imei', 32)->nullable();
            $table->json('condition_snapshot');
            $table->decimal('buying_price', 18, 2);
            $table->string('settlement_direction', 20);
            $table->decimal('settlement_amount', 18, 2);
            $table->string('settlement_method', 30);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('trade_in_credit', 18, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('trade_in_credit'));
        Schema::dropIfExists('exchanges');
    }
};
