<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accessory_stock_movements', fn (Blueprint $table) => $table->foreignId('supplier_id')->nullable()->constrained());
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('emi_rate', 7, 2)->default(0);
            $table->decimal('emi_charge', 18, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('accessory_stock_movements', fn (Blueprint $table) => $table->dropConstrainedForeignId('supplier_id'));
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn(['emi_rate', 'emi_charge']));
    }
};
