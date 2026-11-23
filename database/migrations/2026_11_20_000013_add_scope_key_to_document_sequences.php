<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_sequences', function (Blueprint $table) {
            $table->string('scope_key', 40)->default('GLOBAL')->after('key');
        });

        foreach (DB::table('document_sequences')->select('business_unit_id')->distinct()->pluck('business_unit_id') as $unitId) {
            DB::table('document_sequences')
                ->where('business_unit_id', $unitId)
                ->update(['scope_key' => $unitId === null ? 'GLOBAL' : 'UNIT:'.$unitId]);
        }

        Schema::table('document_sequences', function (Blueprint $table) {
            $table->dropUnique(['key', 'business_unit_id', 'period']);
            $table->unique(['key', 'scope_key', 'period']);
        });
    }

    public function down(): void
    {
        Schema::table('document_sequences', function (Blueprint $table) {
            $table->dropUnique(['key', 'scope_key', 'period']);
            $table->unique(['key', 'business_unit_id', 'period']);
            $table->dropColumn('scope_key');
        });
    }
};
