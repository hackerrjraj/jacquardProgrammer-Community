<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat block + report (Phase 9d-1, MASTER_SPEC §35). A block stops messages in both directions; a report keeps a text
 * copy of the one reported message, so admins never need to open the conversation itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blocker_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['blocker_id', 'blocked_id']);
            $table->index('blocked_id');
        });

        Schema::create('chat_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reported_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->text('message_snapshot');
            $table->enum('reason', ['harassment', 'spam', 'fraud_or_money_request', 'other']);
            $table->string('note', 500)->nullable();
            $table->enum('status', ['open', 'reviewed', 'dismissed'])->default('open');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['reporter_id', 'message_id']);
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_reports');
        Schema::dropIfExists('chat_blocks');
    }
};
