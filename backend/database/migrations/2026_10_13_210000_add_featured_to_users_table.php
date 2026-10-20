<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Featured Programmers on the Home page (MASTER_SPEC §13, §37): admin picks which directory members are featured
 * and in which order. The directory reads the users table, so the switch lives there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('designation');
            $table->unsignedSmallInteger('featured_order')->nullable()->after('is_featured'); // 1 = first; empty = after the numbered ones

            $table->index(['is_featured', 'featured_order']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_featured', 'featured_order']);
            $table->dropColumn(['is_featured', 'featured_order']);
        });
    }
};
