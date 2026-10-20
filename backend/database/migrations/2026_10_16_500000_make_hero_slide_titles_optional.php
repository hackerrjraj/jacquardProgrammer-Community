<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 11b-1: a hero slide may be just a picture — the titles become optional. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hero_slides', function (Blueprint $table) {
            $table->string('title_bn')->nullable()->change();
            $table->string('title_en')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('hero_slides', function (Blueprint $table) {
            $table->string('title_bn')->nullable(false)->change();
            $table->string('title_en')->nullable(false)->change();
        });
    }
};
