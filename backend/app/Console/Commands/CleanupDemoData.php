<?php

namespace App\Console\Commands;

use App\Models\AssistanceRequest;
use App\Models\CommitteeChatMessage;
use App\Models\Contribution;
use App\Models\ExpertiseCard;
use App\Models\HeroSlide;
use App\Models\MemberDocument;
use App\Models\PortfolioItem;
use App\Models\User;
use Database\Seeders\DemoProgrammersSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 13b — removes ONLY the known demo / test data (never real members, settings, site content).
 * Always run with --dry-run first. Ledger rows are never deleted: if any ledger entry points at
 * something that would be removed, the command stops and changes nothing.
 */
class CleanupDemoData extends Command
{
    private const TEST_PHONES = ['01800000081', '01800000082', '01800000083'];

    private const TEST_CASE_ID = 'JPPHAF-AR-2026-0001';

    protected $signature = 'jpphaf:cleanup-demo
        {--dry-run : Only list what would be deleted}
        {--include-card : Also delete the APNADER expertise card (only after the owner confirmed it is test data)}
        {--include-user=* : Extra user ids to delete (test registrations the owner confirmed)}';

    protected $description = 'Delete the demo / test accounts, test aid case, demo programmers and placeholder slides (use --dry-run first)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $suffix = '@'.DemoProgrammersSeeder::EMAIL_DOMAIN;
        $testUsers = User::whereIn('phone', self::TEST_PHONES)->get();
        $demoUsers = User::where('email', 'like', '%'.$suffix)->get()
            ->filter(fn (User $u) => str_ends_with(mb_strtolower($u->email), $suffix))->values();
        $extraIds = array_map('intval', (array) $this->option('include-user'));
        $extraUsers = User::whereIn('id', $extraIds)->get();

        $users = $testUsers->concat($demoUsers)->concat($extraUsers)->unique('id')->values();
        $userIds = $users->pluck('id')->all();
        if ($users->contains(fn (User $u) => $u->role === User::ROLE_SUPER_ADMIN)) {
            $this->error('Refusing: a Super Admin account is in the list.');

            return self::FAILURE;
        }

        $requests = AssistanceRequest::where('case_id', self::TEST_CASE_ID)
            ->orWhereIn('user_id', $userIds)->get();
        $requestIds = $requests->pluck('id')->all();
        $contributions = Contribution::whereIn('user_id', $userIds)->get();
        $contributionIds = $contributions->pluck('id')->all();
        $conversationIds = DB::table('conversations')
            ->whereIn('user_one_id', $userIds)->orWhereIn('user_two_id', $userIds)->pluck('id')->all();
        $slides = HeroSlide::where('image_path', 'like', 'slides/slide-%.svg')->get();
        $card = ExpertiseCard::where('title_bn', 'APNADER')->first();

        // ---- Report ----
        $this->line($dry ? '=== DRY RUN — nothing will be deleted ===' : '=== REAL RUN ===');
        $this->section('Test accounts', $testUsers);
        $this->section('Demo programmers ('.$demoUsers->count().')', $demoUsers);
        if ($extraUsers->isNotEmpty()) {
            $this->section('Extra accounts (--include-user)', $extraUsers);
        }
        $this->info('Assistance cases:');
        foreach ($requests as $r) {
            $this->line("  {$r->case_id} — user {$r->user_id}, status {$r->status}, requested {$r->amount_requested}, approved ".($r->amount_approved ?? '—'));
        }
        $counts = [
            'contributions' => count($contributionIds),
            'contribution months' => DB::table('contribution_months')->whereIn('user_id', $userIds)->count(),
            'aid documents' => DB::table('assistance_documents')->whereIn('assistance_request_id', $requestIds)->count(),
            'aid votes (on these cases or by these users)' => DB::table('assistance_votes')->whereIn('assistance_request_id', $requestIds)->orWhereIn('user_id', $userIds)->count(),
            'conversations (incl. their messages)' => count($conversationIds),
            'chat messages' => DB::table('chat_messages')->whereIn('conversation_id', $conversationIds)->orWhereIn('sender_id', $userIds)->count(),
            'chat reports' => DB::table('chat_reports')->whereIn('reporter_id', $userIds)->orWhereIn('reported_user_id', $userIds)->count(),
            'chat blocks' => DB::table('chat_blocks')->whereIn('blocker_id', $userIds)->orWhereIn('blocked_id', $userIds)->count(),
            'committee chat messages' => DB::table('committee_chat_messages')->whereIn('sender_id', $userIds)->count(),
            'notifications' => DB::table('user_notifications')->whereIn('user_id', $userIds)->count(),
            'push subscriptions' => DB::table('push_subscriptions')->where('subscribable_type', User::class)->whereIn('subscribable_id', $userIds)->count(),
            'member documents' => DB::table('member_documents')->whereIn('user_id', $userIds)->count(),
            'portfolio items' => DB::table('portfolio_items')->whereIn('user_id', $userIds)->count(),
            'work experiences' => DB::table('work_experiences')->whereIn('user_id', $userIds)->count(),
            'committee memberships' => DB::table('committee_members')->whereIn('user_id', $userIds)->count(),
            'sessions + tokens' => DB::table('sessions')->whereIn('user_id', $userIds)->count()
                + DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $userIds)->count(),
        ];
        $this->info('Related rows:');
        foreach ($counts as $label => $n) {
            $this->line("  $label: $n");
        }
        $this->info('Placeholder hero slides:');
        foreach ($slides as $s) {
            $this->line("  #{$s->id} \"{$s->title_en}\" — {$s->image_path}");
        }
        if ($slides->isEmpty()) {
            $this->line('  (none)');
        }
        $this->info('APNADER expertise card '.($this->option('include-card') ? '(WILL be deleted)' : '(NOT deleted unless --include-card)').':');
        if ($card) {
            $this->line("  #{$card->id} title_bn={$card->title_bn} title_en=".($card->title_en ?? '—')." desc_bn={$card->description_bn} desc_en=".($card->description_en ?? '—')." image={$card->image_path} published=".(int) $card->is_published.' created '.$card->created_at);
        } else {
            $this->line('  (not found)');
        }
        $this->line('Audit-log rows are kept (their actor link becomes empty). Real members, settings and site content are untouched.');

        // ---- Safety: ledger is never edited ----
        $ledger = DB::table('ledger_entries')
            ->whereIn('user_id', $userIds)
            ->orWhereIn('contribution_id', $contributionIds)
            ->orWhereIn('assistance_request_id', $requestIds)->count();
        if ($ledger > 0) {
            $this->error("STOP: $ledger ledger entries point at data that would be deleted. The ledger must never be edited — nothing was changed.");

            return self::FAILURE;
        }
        $this->info('Ledger check: no ledger entries are affected.');

        if ($dry) {
            return self::SUCCESS;
        }

        // ---- Collect files before the rows go ----
        $files = [
            'public' => [],
            'private' => [],
        ];
        foreach ($users as $u) {
            $u->photo_path && $files['public'][] = $u->photo_path;
        }
        foreach (DB::table('portfolio_items')->whereIn('user_id', $userIds)->pluck('image_path') as $p) {
            $files[PortfolioItem::DISK][] = $p;
        }
        foreach (DB::table('member_documents')->whereIn('user_id', $userIds)->pluck('path') as $p) {
            $files[MemberDocument::DISK][] = $p;
        }
        foreach ($contributions as $c) {
            $c->proof_path && $files[Contribution::PROOF_DISK][] = $c->proof_path;
        }
        foreach (DB::table('assistance_documents')->whereIn('assistance_request_id', $requestIds)->pluck('path') as $p) {
            $files[AssistanceRequest::FILE_DISK][] = $p;
        }
        foreach ($requests as $r) {
            $r->payout_proof_path && $files[AssistanceRequest::FILE_DISK][] = $r->payout_proof_path;
        }
        foreach (DB::table('committee_chat_messages')->whereIn('sender_id', $userIds)->whereNotNull('attachment_path')->pluck('attachment_path') as $p) {
            $files[CommitteeChatMessage::DISK][] = $p;
        }
        foreach ($slides as $s) {
            $files[HeroSlide::DISK][] = $s->image_path;
        }
        if ($this->option('include-card') && $card?->image_path) {
            $files[ExpertiseCard::DISK][] = $card->image_path;
        }

        DB::transaction(function () use ($userIds, $requestIds, $contributionIds, $conversationIds, $slides, $card) {
            DB::table('chat_reports')->where(fn ($q) => $q->whereIn('reporter_id', $userIds)->orWhereIn('reported_user_id', $userIds)
                ->orWhereIn('conversation_id', $conversationIds))->delete();
            DB::table('conversations')->whereIn('id', $conversationIds)->delete(); // messages cascade
            DB::table('chat_messages')->whereIn('sender_id', $userIds)->delete();
            DB::table('committee_chat_messages')->whereIn('sender_id', $userIds)->delete();
            DB::table('push_subscriptions')->where('subscribable_type', User::class)->whereIn('subscribable_id', $userIds)->delete();
            DB::table('sessions')->whereIn('user_id', $userIds)->delete();
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $userIds)->delete();
            DB::table('assistance_requests')->whereIn('id', $requestIds)->delete(); // documents + votes cascade
            DB::table('contributions')->whereIn('id', $contributionIds)->delete(); // months cascade
            DB::table('contribution_months')->whereIn('user_id', $userIds)->delete();
            User::whereIn('id', $userIds)->delete(); // notifications, documents, portfolio, work history, committee rows cascade
            HeroSlide::whereIn('id', $slides->pluck('id'))->delete();
            if ($this->option('include-card') && $card) {
                $card->delete();
            }
        });

        $removed = 0;
        foreach ($files as $disk => $paths) {
            foreach (array_unique($paths) as $path) {
                if (Storage::disk($disk)->delete($path)) {
                    $removed++;
                }
            }
        }
        $this->info("Done. Rows deleted in one transaction; $removed files removed.");

        return self::SUCCESS;
    }

    private function section(string $title, $users): void
    {
        $this->info($title.':');
        foreach ($users as $u) {
            $this->line("  #{$u->id} {$u->name} — ".($u->phone ?? '—').' — '.($u->email ?? '—')." — {$u->role}/{$u->status}");
        }
        if ($users->isEmpty()) {
            $this->line('  (none)');
        }
    }
}
