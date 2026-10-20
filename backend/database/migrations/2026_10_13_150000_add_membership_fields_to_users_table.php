<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3a: members log in with their mobile number; email becomes optional.
 * Adds registration fields (MASTER_SPEC §16), role + status (§9) and admin approval data (§18).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();

            // Nullable only so existing dev rows survive; the API always requires it.
            $table->string('phone', 11)->nullable()->unique()->after('name');
            $table->string('district', 40)->nullable()->after('email');
            $table->string('workplace', 150)->nullable()->after('district');
            $table->unsignedTinyInteger('experience_years')->nullable()->after('workplace');
            $table->json('machines')->nullable()->after('experience_years');
            $table->json('skills')->nullable()->after('machines');

            $table->string('role', 20)->default('member')->index()->after('skills');
            $table->string('status', 20)->default('pending')->index()->after('role');
            $table->timestamp('approved_at')->nullable()->after('status');
            $table->foreignId('approved_by')->nullable()->after('approved_at')
                ->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable()->after('approved_by');
            $table->boolean('must_change_password')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropUnique(['phone']);
            $table->dropIndex(['role']);
            $table->dropIndex(['status']);
            $table->dropColumn([
                'phone', 'district', 'workplace', 'experience_years', 'machines', 'skills',
                'role', 'status', 'approved_at', 'rejection_reason', 'must_change_password',
            ]);
        });
    }
};
