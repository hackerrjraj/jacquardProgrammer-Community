<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Home "Expertise" cards (MASTER_SPEC §13): admin-editable bn/en title + short text, icon name, optional image,
 * order, published on/off. Default cards come from ExpertiseCardSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expertise_cards', function (Blueprint $table) {
            $table->id();
            $table->string('icon', 50)->nullable();   // icon name the frontend draws, e.g. "cpu"
            $table->string('title_bn', 100);
            $table->string('title_en', 100)->nullable(); // optional English, falls back to Bengali (§6)
            $table->string('description_bn', 500);
            $table->string('description_en', 500)->nullable();
            $table->string('image_path')->nullable();  // public disk, random name
            $table->integer('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->index(['is_published', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expertise_cards');
    }
};
