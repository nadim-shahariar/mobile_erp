<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('document_no')->unique();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('business_unit_id')->constrained();
            $table->date('expense_date')->index();
            $table->decimal('amount', 18, 2);
            $table->string('payment_method', 30);
            $table->string('recipient_type', 20);
            $table->foreignId('staff_id')->nullable()->constrained('users');
            $table->foreignId('supplier_id')->nullable()->constrained();
            $table->string('recipient_name', 180);
            $table->text('recipient_details')->nullable();
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('journal_entry_id')->nullable()->constrained();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
