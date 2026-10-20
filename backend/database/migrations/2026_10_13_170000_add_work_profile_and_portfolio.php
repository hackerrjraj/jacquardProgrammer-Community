<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4a-extra (MASTER_SPEC §19): employment status, designation, work history,
 * portfolio items, and a type for member documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // running_job | jobless — null until the member fills it in.
            $table->string('employment_status', 20)->nullable()->index()->after('workplace');
            $table->string('designation', 100)->nullable()->after('employment_status');
        });

        Schema::create('work_experiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('company', 150);
            $table->string('position', 100);
            $table->unsignedSmallInteger('start_year');
            $table->unsignedSmallInteger('end_year')->nullable(); // null = present
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('portfolio_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('image_path');
            $table->string('category', 20);
            $table->string('title', 100);
            $table->string('description', 300)->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('member_documents', function (Blueprint $table) {
            // Existing rows (uploaded before types existed) become "other".
            $table->string('type', 30)->default('other')->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('member_documents', function (Blueprint $table) {
            $table->dropColumn('type');
        });
        Schema::dropIfExists('portfolio_items');
        Schema::dropIfExists('work_experiences');
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['employment_status']);
            $table->dropColumn(['employment_status', 'designation']);
        });
    }
};
