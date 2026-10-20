<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger corrections (MASTER_SPEC §34, Phase 12b). Adds columns / indexes only — existing ledger rows are not changed.
 * - direction: "in" / "out" for an adjustment (amounts stay unsigned); null on every other type.
 * - reverses_id unique: an entry can be reversed only once (also checked in the app).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->string('direction', 3)->nullable()->after('amount');
            $table->unique('reverses_id', 'ledger_entries_reverses_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropUnique('ledger_entries_reverses_id_unique');
            $table->dropColumn('direction');
        });
    }
};
