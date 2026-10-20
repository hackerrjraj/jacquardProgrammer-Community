<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\FeaturedProgrammerController;
use App\Http\Resources\PublicProgrammerProfileResource;
use App\Http\Resources\PublicProgrammerResource;
use App\Models\ProfileListItem;
use App\Models\User;
use App\Support\ProfileLists;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Public (no login, read-only) Programmers directory + profile (MASTER_SPEC §21–§22).
 * Only approved members with a programmer profile (User::inDirectory). Rate limited 60/min/IP.
 */
class PublicProgrammerController extends Controller
{
    public const PER_PAGE = 12;

    /** Home section: at most this many programmers (featured first, then the newest). */
    public const HOME_LIMIT = 6;

    public const HOME_CACHE_KEY = 'public_home_programmers';

    public const HOME_CACHE_SECONDS = 300;

    /** Experience filter → [min, max] years (max null = no upper limit). "10+" = 11 and up (§21). */
    public const EXPERIENCE_RANGES = [
        '0-5' => [0, 5],
        '6-10' => [6, 10],
        '10+' => [11, null],
    ];

    public const SORTS = ['newest', 'name', 'experience'];

    /**
     * GET /api/public/programmers?q=&experience=0-5|6-10|10+&district=&machine=&employment_status=&sort=newest|name|experience&page=
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'experience' => ['nullable', Rule::in(array_keys(self::EXPERIENCE_RANGES))],
            'district' => ['nullable', Rule::in(array_keys(config('districts')))],
            'machine' => ['nullable', 'string', 'max:100'],
            'employment_status' => ['nullable', Rule::in(array_keys(config('profile_options.employment_statuses')))],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = User::query()->inDirectory()
            ->withCount('portfolioItems')
            ->when(trim($filters['q'] ?? ''), fn (Builder $q, string $search) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->when($filters['experience'] ?? null, function (Builder $q, string $range) {
                [$min, $max] = self::EXPERIENCE_RANGES[$range];
                $q->where('experience_years', '>=', $min)
                    ->when($max !== null, fn (Builder $q) => $q->where('experience_years', '<=', $max));
            })
            ->when($filters['district'] ?? null, fn (Builder $q, string $district) => $q->where('district', $district))
            ->when($filters['employment_status'] ?? null, fn (Builder $q, string $status) => $q->where('employment_status', $status))
            // Machine = a list key (Phase 11d-1). A hidden item still finds the profiles that have it.
            ->when(trim($filters['machine'] ?? ''), fn (Builder $q, string $machine) => $q->whereJsonContains('machines', $this->machineKey($machine)));

        match ($filters['sort'] ?? 'newest') {
            'name' => $query->orderBy('name')->orderBy('id'),
            'experience' => $query->orderByDesc('experience_years')->orderBy('name')->orderBy('id'),
            default => $query->orderByDesc('approved_at')->orderByDesc('id'),
        };

        return PublicProgrammerResource::collection($query->paginate(self::PER_PAGE)->withQueryString());
    }

    /**
     * GET /api/public/programmers/home — programmers for the Home carousel (MASTER_SPEC §13):
     * up to HOME_LIMIT directory members (never demo): featured first, then the newest; same card fields + visibility as the list.
     * Only the random pick of ids is cached (HOME_CACHE_SECONDS); the members are loaded fresh on every request,
     * so someone suspended meanwhile drops out at once and "members only" fields follow the visitor's login.
     * Phase 11b-2: featured programmers (admin's pick) come first, in featured_order, never cached; the random
     * rest fills up to HOME_LIMIT and never repeats a featured one. The featured flag is not part of the card.
     */
    public function home(): AnonymousResourceCollection
    {
        $featured = FeaturedProgrammerController::featuredQuery()->onHome()
            ->withCount('portfolioItems')
            ->limit(self::HOME_LIMIT)
            ->get();

        $rest = User::query()->onHome()
            ->where('is_featured', false)
            ->withCount('portfolioItems')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(max(0, self::HOME_LIMIT - $featured->count()))
            ->get();

        return PublicProgrammerResource::collection($featured->concat($rest)->values());
    }

    /** GET /api/public/programmers/{id} — public profile; anyone not in the directory → the same 404. */
    public function show(string $id): PublicProgrammerProfileResource
    {
        $programmer = ctype_digit($id)
            ? User::query()->inDirectory()
                ->with(['workExperiences', 'portfolioItems'])
                ->withCount('portfolioItems')
                ->find((int) $id)
            : null;

        // Plain {message} even in debug mode, so the reply never hints why the profile is missing.
        if ($programmer === null) {
            throw new HttpResponseException(response()->json(['message' => __('messages.programmer_not_found')], 404));
        }

        return new PublicProgrammerProfileResource($programmer);
    }

    /**
     * GET /api/public/programmers/filters — choices for the filter dropdowns:
     * {districts: [{key, name_bn, name_en}] (of directory members only), machines: [{key, name_bn, name_en}]}.
     * Machines = the active items of the admin's Machine list, in the admin's order (Phase 11d-1).
     */
    public function filters(): JsonResponse
    {
        $districtNames = config('districts');

        $districts = User::query()->inDirectory()
            ->distinct()
            ->pluck('district')
            ->filter(fn (string $key) => isset($districtNames[$key]))
            ->map(fn (string $key) => ['key' => $key, 'name_bn' => $districtNames[$key]['bn'], 'name_en' => $districtNames[$key]['en']])
            ->sortBy('name_en')
            ->values();

        $machines = ProfileListItem::query()->active()->where('type', ProfileListItem::TYPE_MACHINE)->get()
            ->map(fn (ProfileListItem $item) => $item->toChoice());

        return response()->json(['data' => ['districts' => $districts, 'machines' => $machines]]);
    }

    /** A machine key; an old link that still uses a name ("?machine=STOLL") is matched to that item's key. */
    private function machineKey(string $value): string
    {
        $items = app(ProfileLists::class)->items(ProfileListItem::TYPE_MACHINE);
        if ($items->has($value)) {
            return $value;
        }
        $norm = ProfileLists::normalize($value);
        $item = $items->first(fn (ProfileListItem $i) => ProfileLists::normalize($i->name_en) === $norm || ProfileLists::normalize($i->name_bn) === $norm);

        return $item?->key ?? $value;
    }
}
