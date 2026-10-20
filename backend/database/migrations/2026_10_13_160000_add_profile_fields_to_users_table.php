<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4a: own profile (MASTER_SPEC §19) — photo, bio and contact visibility (§20).
 * District, workplace, experience, machines and skills already exist (Phase 3a).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('name');
            $table->text('bio')->nullable()->after('skills');

            // public | members | hidden — defaults from MASTER_SPEC §20.
            $table->string('phone_visibility', 10)->default('members')->after('bio');
            $table->string('email_visibility', 10)->default('hidden')->after('phone_visibility');
            $table->string('workplace_visibility', 10)->default('public')->after('email_visibility');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['photo_path', 'bio', 'phone_visibility', 'email_visibility', 'workplace_visibility']);
        });
    }
};
