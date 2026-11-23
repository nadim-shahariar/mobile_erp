<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serialized_units', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('inventory_category', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('serialized_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn('inventory_category');
        });
    }
};
