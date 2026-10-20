<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hero_slides', function (Blueprint $table) {
            $table->id();
            $table->string('image_path'); // relative to the "public" disk, e.g. slides/slide-1.svg
            $table->string('title_bn');
            $table->string('title_en');
            $table->string('subtitle_bn', 500)->nullable();
            $table->string('subtitle_en', 500)->nullable();
            $table->string('button_text_bn', 100)->nullable();
            $table->string('button_text_en', 100)->nullable();
            $table->string('button_link')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hero_slides');
    }
};
