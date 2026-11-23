<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_unit_id')->constrained();
            $table->string('name', 180);
            $table->string('normalized_name', 180);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['business_unit_id', 'normalized_name']);
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('staff_member_id')->nullable()->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', fn (Blueprint $table) => $table->dropConstrainedForeignId('staff_member_id'));
        Schema::dropIfExists('staff_members');
    }
};
