<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1-to-1 chat (MASTER_SPEC §35, Phase 9a). One conversation per pair of members (user_one_id < user_two_id),
 * each side keeps the id of the last message it has read → unread counts and "read" ticks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_one_id')->constrained('users')->restrictOnDelete(); // always the smaller user id
            $table->foreignId('user_two_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('user_one_last_read_id')->nullable();
            $table->unsignedBigInteger('user_two_last_read_id')->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['user_one_id', 'user_two_id']);
            $table->index('user_two_id');
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('conversations');
    }
};
