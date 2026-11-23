<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->default('PERSON');
            $table->string('name', 180);
            $table->string('business_name', 180)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('address')->nullable();
            $table->decimal('credit_limit', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('phone');
            $table->index(['type', 'is_active']);
            $table->index('name');
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('class', 20);
            $table->string('type', 40);
            $table->foreignId('business_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_payment_account')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['class', 'type']);
            $table->index(['business_unit_id', 'is_payment_account']);
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_no', 40)->unique();
            $table->date('entry_date');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_key', 120)->nullable()->unique();
            $table->string('memo');
            $table->timestamp('posted_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index(['entry_date', 'posted_at']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->string('memo')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'journal_entry_id']);
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable()->after('due_amount')
                ->constrained('journal_entries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
        });

        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('customers');
    }
};
