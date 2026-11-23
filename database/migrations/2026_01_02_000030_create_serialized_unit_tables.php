<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serialized_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();

            // IMEIs are strings: leading zeros matter. Uniqueness across both
            // slots is enforced by serialized_identifiers.normalized_value.
            $table->string('primary_imei', 32)->nullable();
            $table->string('secondary_imei', 32)->nullable();
            $table->string('imei_status', 24)->default('VALID');
            $table->string('imei_flag_reason')->nullable();

            $table->unsignedTinyInteger('battery_health')->nullable();
            $table->unsignedInteger('cycle_count')->nullable();
            $table->string('condition_grade', 20)->nullable();
            $table->string('box_kit', 60)->nullable();

            $table->string('current_custody', 32)->default('IN_UNIT');
            $table->foreignId('current_unit_id')->nullable()->constrained('business_units')->restrictOnDelete();
            $table->string('sellability_status', 24)->default('AVAILABLE');

            $table->unsignedBigInteger('last_event_id')->nullable();
            $table->unsignedBigInteger('version')->default(0);
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('primary_imei');
            $table->index('secondary_imei');
            $table->index(['current_unit_id', 'sellability_status']);
            $table->index(['current_custody', 'sellability_status']);
            $table->index(['product_variant_id', 'sellability_status']);
            $table->index('updated_at');
        });

        Schema::create('serialized_identifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('serialized_unit_id')->constrained()->cascadeOnDelete();
            $table->string('identifier_type', 12);
            $table->string('raw_value', 64);
            $table->string('normalized_value', 64)->unique();
            $table->timestamps();

            $table->unique(['serialized_unit_id', 'identifier_type']);
            $table->index('normalized_value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serialized_identifiers');
        Schema::dropIfExists('serialized_units');
    }
};
