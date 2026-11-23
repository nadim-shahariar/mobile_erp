<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('serialized_unit_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('cycle_no');

            $table->string('source_type', 40);
            $table->string('source_ref_type')->nullable();
            $table->unsignedBigInteger('source_ref_id')->nullable();
            $table->string('source_label')->nullable();

            $table->decimal('acquisition_value', 18, 2);
            $table->decimal('allocated_direct_cost', 18, 2)->default(0);
            $table->decimal('effective_cost_basis', 18, 2);

            $table->foreignId('initial_unit_id')->constrained('business_units')->restrictOnDelete();
            $table->timestamp('acquired_at');
            $table->foreignId('acquired_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamp('closed_at')->nullable();
            $table->string('closing_type', 32)->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->text('notes')->nullable();

            // 1 while the cycle is open, NULL once closed. The unique index
            // makes "at most one active owned cycle per handset" a database
            // rule on MySQL, MariaDB and SQLite alike (NULLs are distinct).
            $table->unsignedTinyInteger('active_cycle_guard')->nullable()->default(1);

            $table->timestamps();

            $table->unique(['serialized_unit_id', 'cycle_no']);
            $table->unique(['serialized_unit_id', 'active_cycle_guard']);
            $table->index(['initial_unit_id', 'closed_at']);
            $table->index(['source_ref_type', 'source_ref_id']);
            $table->index(['closed_at', 'initial_unit_id']);
        });

        Schema::create('inventory_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('serialized_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_cycle_id')->nullable()->constrained()->restrictOnDelete();

            $table->string('event_type', 40);
            $table->string('from_custody', 32)->nullable();
            $table->string('to_custody', 32)->nullable();
            $table->foreignId('from_unit_id')->nullable()->constrained('business_units')->restrictOnDelete();
            $table->foreignId('to_unit_id')->nullable()->constrained('business_units')->restrictOnDelete();

            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('happened_at');

            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->text('note')->nullable();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->foreignId('reversal_of_event_id')->nullable()->constrained('inventory_events')->nullOnDelete();
            $table->json('metadata')->nullable();

            // Append-only ledger: no updated_at column by design.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['serialized_unit_id', 'id']);
            $table->index(['event_type', 'happened_at']);
            $table->index(['from_unit_id', 'happened_at']);
            $table->index(['to_unit_id', 'happened_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_events');
        Schema::dropIfExists('inventory_cycles');
    }
};
