<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assistance (aid) requests — simple rules of Phase 8a (MASTER_SPEC §29–§32). Money is whole taka in unsigned integers.
 */
return new class extends Migration
{
    public function up(): void
    {
        // §29–§32: one aid request. submitted → approved → paid, or submitted → rejected / cancelled.
        Schema::create('assistance_requests', function (Blueprint $table) {
            $table->id();
            $table->string('case_id', 30)->nullable()->unique();   // JPPHAF-AR-YYYY-NNNN, set right after insert
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            // = user_id while the request is open (submitted / approved), null afterwards → one open request per member.
            $table->unsignedBigInteger('open_key')->nullable()->unique();
            $table->string('reason_type', 20);                      // medical / accident / death / job_loss / other
            $table->string('reason_other', 150)->nullable();
            $table->text('description');
            $table->unsignedInteger('amount_requested');
            $table->string('applying_for_name', 100)->nullable();
            $table->string('applying_for_relation', 50)->nullable();
            $table->boolean('consent_public_name')->default(false);
            $table->string('status', 12)->default('submitted')->index();

            // Decision (admin / super_admin)
            $table->unsignedInteger('amount_approved')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Payout (§32) — one per case
            $table->string('payout_method', 10)->nullable();
            $table->string('payout_transaction_id', 50)->nullable();
            $table->date('payout_date')->nullable();
            $table->string('payout_note', 500)->nullable();
            $table->string('payout_proof_path')->nullable();        // private disk
            $table->string('payout_proof_mime', 100)->nullable();
            $table->unsignedInteger('payout_proof_size')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        // Supporting documents: 0–5 private files per request.
        Schema::create('assistance_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assistance_request_id')->constrained('assistance_requests')->cascadeOnDelete();
            $table->string('path');                                 // private disk, random name
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->timestamps();
        });

        // Advisory Yes/No votes: one per person per request, changeable until the decision.
        Schema::create('assistance_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assistance_request_id')->constrained('assistance_requests')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('in_favour');                            // true = Yes, false = No
            $table->timestamps();

            $table->unique(['assistance_request_id', 'user_id']);
        });

        // §32: the payout's ledger debit points to its case; unique → never two payouts for one case.
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->foreignId('assistance_request_id')->nullable()->unique()->after('contribution_id')
                ->constrained('assistance_requests')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assistance_request_id');
        });
        Schema::dropIfExists('assistance_votes');
        Schema::dropIfExists('assistance_documents');
        Schema::dropIfExists('assistance_requests');
    }
};
