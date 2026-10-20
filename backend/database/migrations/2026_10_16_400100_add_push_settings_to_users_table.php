<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web push on/off switch (Phase 10c-1, MASTER_SPEC §36). push_locale = the language the member's phone / desktop
 * notifications are written in (taken from the website language when they subscribe or change the setting).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('push_enabled')->default(false);
            $table->string('push_locale', 2)->default('bn');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['push_enabled', 'push_locale']);
        });
    }
};
