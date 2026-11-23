<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('idempotency_key')->unique();
            $table->string('reference_no')->unique();
            $table->foreignId('business_unit_id')->constrained();
            $table->foreignId('serialized_unit_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->string('recipient_type', 20);
            $table->decimal('agreed_price', 18, 2);
            $table->decimal('deposit_amount', 18, 2)->default(0);
            $table->string('payment_method', 30);
            $table->string('status', 20)->default('OUT');
            $table->unsignedTinyInteger('active_guard')->nullable()->default(1);
            $table->foreignId('sale_id')->nullable()->unique()->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['serialized_unit_id', 'active_guard'], 'external_active_unit_unique');
        });
        Schema::table('sales', fn (Blueprint $table) => $table->decimal('deposit_credit', 18, 2)->default(0));
    }

    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('deposit_credit'));
        Schema::dropIfExists('external_deliveries');
    }
};
