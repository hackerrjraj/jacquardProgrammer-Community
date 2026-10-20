<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notices (MASTER_SPEC §24): admin-managed title, body, optional attachment (PDF/image), publish date,
 * pinned on/off, published on/off. Visibility: everyone ("public") or approved members only ("members").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->string('title_bn', 200);
            $table->string('title_en', 200)->nullable(); // optional English, falls back to Bengali (§6)
            $table->text('body_bn');
            $table->text('body_en')->nullable();
            $table->string('visibility', 10)->default('public'); // public | members
            $table->string('attachment_path')->nullable();        // public disk, random name
            $table->string('attachment_name')->nullable();        // original file name (for showing / downloading)
            $table->string('attachment_mime', 100)->nullable();
            $table->unsignedInteger('attachment_size')->nullable(); // bytes
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_published')->default(true);
            $table->dateTime('published_at')->nullable(); // set on first publish; a future date = scheduled
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_published', 'visibility', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notices');
    }
};
