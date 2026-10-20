<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fund contributions (MASTER_SPEC §25–§28, §34). Money is whole taka (BDT) in unsigned integers — never float.
 */
return new class extends Migration
{
    public function up(): void
    {
        // §25: monthly amount history. A month's due = the amount effective for that month.
        Schema::create('contribution_rates', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('amount');
            $table->date('effective_from')->unique(); // always the 1st of a month
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Default 200 BDT from the very start (§25).
        DB::table('contribution_rates')->insert([
            'amount' => 200,
            'effective_from' => '2000-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // §26: a member's payment submission.
        Schema::create('contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('method', 10);                 // bkash / nagad / rocket / bank / cash
            $table->string('sender_number', 20)->nullable();
            $table->string('transaction_id', 50)->nullable();
            // "method:TXID" while pending/approved, null once rejected → a TxID can be used only once per method.
            $table->string('txid_key', 64)->nullable()->unique();
            $table->unsignedInteger('amount');
            $table->string('proof_path')->nullable();     // private disk
            $table->string('proof_mime', 100)->nullable();
            $table->unsignedInteger('proof_size')->nullable();
            $table->string('status', 10)->default('pending')->index(); // pending / approved / rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        // §26: month(s) covered by a submission, with the due amount of each month.
        Schema::create('contribution_months', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contribution_id')->constrained('contributions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->date('month');                        // 1st of the month
            $table->unsignedInteger('amount');
            // 1 while the submission is pending/approved, null once rejected → a month is never claimed twice.
            $table->boolean('claim')->nullable()->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'month', 'claim']);
        });

        // §34: append-only fund ledger. Only verified payments create a "contribution" entry (§27).
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->index();          // contribution / disbursement / adjustment / reversal
            $table->unsignedInteger('amount');
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('contribution_id')->nullable()->unique()->constrained('contributions')->restrictOnDelete();
            $table->foreignId('reverses_id')->nullable()->constrained('ledger_entries')->restrictOnDelete();
            $table->string('reason', 500)->nullable();
            $table->date('entry_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('contribution_months');
        Schema::dropIfExists('contributions');
        Schema::dropIfExists('contribution_rates');
    }
};
