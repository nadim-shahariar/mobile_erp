<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_payment_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->unsignedSmallInteger('sequence');
            $table->date('due_date')->index();
            $table->decimal('amount', 18, 2);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->timestamps();
            $table->unique(['sale_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payment_schedules');
    }
};
