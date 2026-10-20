<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site Content Manager part 2: admin-uploaded site images (logo, favicon, banners, ...). One row per customised
 * image key; no row = the built-in design default (keys + sizes live in config/site_images.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_images', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('path');
            $table->json('files')->nullable(); // favicon: every generated size (name => path)
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_images');
    }
};
