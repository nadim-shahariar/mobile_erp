<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('timezone', 64)->default('Asia/Dhaka');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('user_business_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_unit_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'business_unit_id']);
        });

        Schema::create('staff_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('code', 30)->unique();
            $table->string('designation')->nullable();
            $table->date('joined_at')->nullable();
            $table->date('left_at')->nullable();
            $table->decimal('monthly_salary', 18, 2)->nullable();
            $table->string('salary_payment_method', 30)->nullable();
            $table->json('salary_config')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 32)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 150)->unique();
            $table->json('value')->nullable();
            $table->string('group', 60)->default('general');
            $table->boolean('is_encrypted')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('group');
        });

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60);
            $table->foreignId('business_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('period', 10)->default('GLOBAL');
            $table->string('prefix', 20)->default('');
            $table->unsignedSmallInteger('padding')->default(6);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['key', 'business_unit_id', 'period']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('business_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 60);
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['event', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key', 190)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 120);
            $table->string('request_hash', 64)->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('expires_at');
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_no', 30)->unique();
            $table->foreignId('business_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('status', 20)->default('UPLOADED');
            $table->json('headers')->nullable();
            $table->json('mapping')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('committed_rows')->default(0);
            $table->json('error_report')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->index(['business_unit_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('staff_profiles');
        Schema::dropIfExists('user_business_units');
        Schema::dropIfExists('business_units');
    }
};
