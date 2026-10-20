<?php

namespace App\Console\Commands;

use App\Models\MemberDocument;
use App\Models\PortfolioItem;
use App\Models\User;
use Database\Seeders\DemoProgrammersSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes the demo programmers made by DemoProgrammersSeeder — only accounts whose email
 * ends with @demo.jpphaf.local — with their photos, portfolio images, documents, work history,
 * sessions and tokens. Real accounts are never touched.
 */
class RemoveDemoProgrammers extends Command
{
    protected $signature = 'jpphaf:remove-demo-programmers';

    protected $description = 'Delete the demo programmers (emails ending with @'.DemoProgrammersSeeder::EMAIL_DOMAIN.') and their files';

    public function handle(): int
    {
        $suffix = '@'.DemoProgrammersSeeder::EMAIL_DOMAIN;

        // LIKE narrows it down; the exact (case-insensitive) suffix check decides.
        $users = User::where('email', 'like', '%'.$suffix)
            ->with(['portfolioItems', 'documents'])
            ->get()
            ->filter(fn (User $u) => str_ends_with(mb_strtolower($u->email), $suffix));

        if ($users->isEmpty()) {
            $this->info('No demo programmers found — nothing to remove.');

            return self::SUCCESS;
        }

        foreach ($users as $user) {
            if ($user->photo_path) {
                Storage::disk('public')->delete($user->photo_path);
            }
            foreach ($user->portfolioItems as $item) {
                Storage::disk(PortfolioItem::DISK)->delete($item->image_path);
            }
            foreach ($user->documents as $document) {
                Storage::disk(MemberDocument::DISK)->delete($document->path);
            }
        }

        $ids = $users->pluck('id');
        DB::transaction(function () use ($ids) {
            DB::table('sessions')->whereIn('user_id', $ids)->delete();
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $ids)->delete();
            // Work history, portfolio rows and documents go with the user (cascade).
            User::whereIn('id', $ids)->delete();
        });

        $this->info("Removed {$users->count()} demo programmers and their files.");

        return self::SUCCESS;
    }
}
