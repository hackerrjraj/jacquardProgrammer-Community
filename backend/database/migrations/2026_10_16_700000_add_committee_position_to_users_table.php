<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11c: the About page lists committee members automatically. Admins may give each one a short position
 * ("President", "Secretary" …, optional) and an order (1, 2, 3 …; members without one come last, by name).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('committee_position', 60)->nullable()->after('featured_order');
            $table->unsignedSmallInteger('committee_order')->nullable()->after('committee_position');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['committee_position', 'committee_order']);
        });
    }
};
