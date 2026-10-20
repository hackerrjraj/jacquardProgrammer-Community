<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notifications (Phase 10a, MASTER_SPEC §36). Only the type + its values are stored; the text is built when it
 * is read, so the member always sees it in the language they are using. `notices.notified_at` makes sure a notice
 * notifies members once (also when it was scheduled for later).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 40);
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'id']);
        });

        Schema::table('notices', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable()->after('published_at');
        });

        // Notices that are already out count as notified — nobody gets old notices as "new".
        DB::table('notices')->where('is_published', true)->whereNotNull('published_at')
            ->where('published_at', '<=', now())->update(['notified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('notices', function (Blueprint $table) {
            $table->dropColumn('notified_at');
        });
        Schema::dropIfExists('user_notifications');
    }
};
