<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aligns the handset record with the shop's existing Excel row:
 * buying party, per-unit asking price, free-text status reason and the
 * adapter/cable bundle that travels with the phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serialized_units', function (Blueprint $table) {
            $table->decimal('expected_selling_price', 18, 2)->nullable();
            $table->string('status_note', 255)->nullable();
        });

        Schema::table('inventory_cycles', function (Blueprint $table) {
            $table->string('buying_party_name', 180)->nullable();
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->string('buying_party_name', 180)->nullable();
        });

        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->string('buying_party_name', 180)->nullable();
            $table->decimal('expected_selling_price', 18, 2)->nullable();
            $table->string('status_note', 255)->nullable();
            $table->date('received_at')->nullable();
        });

        Schema::create('serialized_unit_accessories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('serialized_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_cycle_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('accessory_type', 20);
            $table->string('name', 120);
            $table->decimal('price', 18, 2)->default(0);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['serialized_unit_id', 'accessory_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serialized_unit_accessories');

        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->dropColumn(['buying_party_name', 'expected_selling_price', 'status_note', 'received_at']);
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn('buying_party_name');
        });

        Schema::table('inventory_cycles', function (Blueprint $table) {
            $table->dropColumn('buying_party_name');
        });

        Schema::table('serialized_units', function (Blueprint $table) {
            $table->dropColumn(['expected_selling_price', 'status_note']);
        });
    }
};
