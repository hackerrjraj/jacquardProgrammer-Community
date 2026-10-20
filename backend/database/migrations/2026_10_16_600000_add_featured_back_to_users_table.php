<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11b-2 (owner, Option A): admins pick featured programmers again; they are shown first in the
 * Home "Our Programmers" rows, in featured_order (1, 2, 3 … kept gap-free). The 6d-5 migration dropped these columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('designation');
            $table->unsignedSmallInteger('featured_order')->nullable()->after('is_featured');
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
