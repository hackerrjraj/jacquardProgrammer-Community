<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gallery (MASTER_SPEC §23): admin-managed categories + images (title, category, order, published).
 * The 5 initial categories (Decided, owner 2026-10-13) are inserted here so every database has them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery_categories', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique(); // used in URLs / filters, e.g. "events"
            $table->string('name_bn', 100);
            $table->string('name_en', 100);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('gallery_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_category_id')->constrained()->restrictOnDelete();
            $table->string('title_bn', 150)->nullable();
            $table->string('title_en', 150)->nullable();
            $table->string('image_path');  // display version (max 1600 px), public disk
            $table->string('thumb_path');  // thumbnail (max 600 px), public disk
            $table->unsignedInteger('width');  // of the display version
            $table->unsignedInteger('height');
            $table->integer('sort_order')->default(0); // lower first; new uploads go on top
            $table->boolean('is_published')->default(true);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_published', 'sort_order']);
        });

        $now = now();
        DB::table('gallery_categories')->insert([
            ['key' => 'programs', 'name_bn' => 'প্রোগ্রাম', 'name_en' => 'Programs', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'events', 'name_bn' => 'ইভেন্ট', 'name_en' => 'Events', 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'training', 'name_bn' => 'প্রশিক্ষণ', 'name_en' => 'Training', 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'community', 'name_bn' => 'কমিউনিটি', 'name_en' => 'Community', 'sort_order' => 4, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'workplace', 'name_bn' => 'কর্মস্থল', 'name_en' => 'Workplace', 'sort_order' => 5, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_images');
        Schema::dropIfExists('gallery_categories');
    }
};
