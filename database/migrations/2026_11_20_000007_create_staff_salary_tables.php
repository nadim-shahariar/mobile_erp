<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_salary_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_unit_id')->constrained();
            $table->string('recipient_key', 50);
            $table->string('effective_month', 7);
            $table->decimal('monthly_salary', 18, 2);
            $table->timestamps();
            $table->unique(['business_unit_id', 'recipient_key', 'effective_month'], 'salary_rate_month_unique');
        });
        Schema::create('salary_months', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_unit_id')->constrained();
            $table->string('recipient_key', 50);
            $table->foreignId('staff_id')->nullable()->constrained('users');
            $table->foreignId('staff_member_id')->nullable()->constrained();
            $table->string('staff_name', 180);
            $table->string('salary_month', 7);
            $table->decimal('salary_amount', 18, 2)->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained();
            $table->foreignId('finalized_by')->nullable()->constrained('users');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->unique(['business_unit_id', 'recipient_key', 'salary_month'], 'salary_person_month_unique');
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('salary_month_id')->nullable()->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', fn (Blueprint $table) => $table->dropConstrainedForeignId('salary_month_id'));
        Schema::dropIfExists('salary_months');
        Schema::dropIfExists('staff_salary_rates');
    }
};
