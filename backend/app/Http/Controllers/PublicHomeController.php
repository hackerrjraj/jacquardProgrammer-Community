<?php

namespace App\Http\Controllers;

use App\Http\Resources\ExpertiseCardResource;
use App\Models\ExpertiseCard;
use App\Models\GalleryImage;
use App\Models\Notice;
use App\Models\User;
use Database\Seeders\DemoProgrammersSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;

/** Public (no login) data for the rebuilt Home / About pages (MASTER_SPEC §13, §14). */
class PublicHomeController extends Controller
{
    public const STATS_CACHE_KEY = 'public_home_stats';

    /** The numbers are kept for 10 minutes, so a change shows on the Home page within 10 minutes. */
    public const STATS_CACHE_SECONDS = 600;

    /** GET /api/public/expertise-cards — published cards in display order. */
    public function expertiseCards(): AnonymousResourceCollection
    {
        return ExpertiseCardResource::collection(ExpertiseCard::query()->published()->ordered()->get());
    }

    /**
     * GET /api/public/stats — counted from real data only (§13 "Decided"), never typed in:
     * members (approved accounts), programmers (approved + programmer profile = the public directory),
     * companies (distinct workplaces) and locations (distinct districts) of approved members,
     * max_experience_years (highest among listed programmers), live public notices, published gallery photos.
     * Pending / rejected / suspended accounts and the demo programmers are never counted.
     * No fund money here: the Fund Report is members-only (MASTER_SPEC §33), so no totals are in any public API.
     */
    public function stats(): JsonResponse
    {
        $stats = Cache::remember(self::STATS_CACHE_KEY, self::STATS_CACHE_SECONDS, fn () => $this->countStats());

        return response()->json(['data' => $stats]);
    }

    /** @return array<string, int|string|null> */
    private function countStats(): array
    {
        $members = fn (): Builder => User::query()
            ->where('status', User::STATUS_APPROVED)
            ->where(fn (Builder $q) => $q->whereNull('email')->orWhere('email', 'not like', '%@'.DemoProgrammersSeeder::EMAIL_DOMAIN));

        return [
            'members' => $members()->count(),
            'programmers' => $members()->inDirectory()->count(),
            // Same workplace typed with different spaces / capital letters counts once.
            'companies' => $members()->whereNotNull('workplace')->where('workplace', '!=', '')
                ->pluck('workplace')
                ->map(fn (string $w) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($w))))
                ->filter()
                ->unique()
                ->count(),
            'locations' => $members()->whereNotNull('district')->distinct()->count('district'),
            'max_experience_years' => (int) $members()->inDirectory()->max('experience_years'),
            'notices' => Notice::query()->live()->publicOnly()->count(),
            'gallery_photos' => GalleryImage::query()->published()->count(),
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
