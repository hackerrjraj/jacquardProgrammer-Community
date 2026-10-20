<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Expenses module: admin-managed categories + the expenses themselves (soft deleted). New tables only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name_bn', 100);
            $table->string('name_en', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->date('expense_date')->index();
            $table->decimal('amount', 12, 2);
            $table->foreignId('category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->text('purpose');
            $table->string('paid_to', 150)->nullable();
            $table->string('receipt_image')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        $defaults = [
            ['অফিস', 'Office'], ['অনুষ্ঠান', 'Event'], ['ছাপা', 'Printing'],
            ['যাতায়াত', 'Transport'], ['ব্যাংক চার্জ', 'Bank charge'], ['অন্যান্য', 'Other'],
        ];
        foreach ($defaults as $i => [$bn, $en]) {
            DB::table('expense_categories')->insert([
                'name_bn' => $bn, 'name_en' => $en, 'is_active' => true, 'sort_order' => $i,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};
