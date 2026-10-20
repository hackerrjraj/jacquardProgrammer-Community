<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6d-5: the admin-picked "Featured Programmers" (6d-4) was replaced by an automatic Home carousel,
 * so the featured switch is removed again. Old audit rows ("member.featured_changed") stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_featured', 'featured_order']);
            $table->dropColumn(['is_featured', 'featured_order']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('designation');
            $table->unsignedSmallInteger('featured_order')->nullable()->after('is_featured');

            $table->index(['is_featured', 'featured_order']);
        });
    }
};
