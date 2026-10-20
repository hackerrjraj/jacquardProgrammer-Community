<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Private Committee Chat (one group chat). Separate from the 1-to-1 chat tables, which stay untouched.
 * Members are not stored: access is worked out live from the Committee Panel + admin roles (App\Support\CommitteeChat).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('committee_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->text('body')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->timestamps();
        });

        // Each member's read position (for the unread badge).
        Schema::create('committee_chat_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_id')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('committee_chat_reads');
        Schema::dropIfExists('committee_chat_messages');
    }
};
