<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_repairs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_unit_id')->constrained();
            $t->foreignId('serialized_unit_id')->constrained();
            $t->foreignId('inventory_cycle_id')->constrained();
            $t->string('status')->default('OUT')->index();
            $t->string('vendor_name')->nullable();
            $t->text('notes')->nullable();
            $t->text('return_notes')->nullable();
            $t->decimal('cost', 18, 2)->default(0);
            $t->string('payment_method')->nullable();
            $t->uuid('idempotency_key')->unique();
            $t->foreignId('sent_by')->constrained('users');
            $t->foreignId('received_by')->nullable()->constrained('users');
            $t->timestamp('sent_at');
            $t->timestamp('received_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_repairs');
    }
};
