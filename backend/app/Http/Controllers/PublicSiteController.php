<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\HeroOverlayController;
use App\Http\Requests\StoreContactMessageRequest;
use App\Models\ContactMessage;
use App\Models\HeroSlide;
use App\Models\ProfileListItem;
use App\Models\User;
use App\Support\SiteContent;
use App\Support\SiteImages;
use App\Support\SiteInfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

/** Public (no login) endpoints for the Home and Contact pages. */
class PublicSiteController extends Controller
{
    /** GET /api/hero-slides — active slides in display order. */
    public function heroSlides(): JsonResponse
    {
        $slides = HeroSlide::visible()->get()->map(fn (HeroSlide $slide) => [
            'id' => $slide->id,
            'image_url' => $slide->imageUrl(),
            'title_bn' => $slide->title_bn,
            'title_en' => $slide->title_en,
            'subtitle_bn' => $slide->subtitle_bn,
            'subtitle_en' => $slide->subtitle_en,
            'button_text_bn' => $slide->button_text_bn,
            'button_text_en' => $slide->button_text_en,
            'button_link' => $slide->button_link,
            'sort_order' => $slide->sort_order,
        ]);

        return response()->json(['data' => $slides, 'overlay' => HeroOverlayController::current()]);
    }

    /** GET /api/site-stats — numbers for the Home page stats strip. */
    public function siteStats(): JsonResponse
    {
        // PLACEHOLDER values. Real values come later:
        //  - approved_members: Phase 5 (users get an approval status in Phase 3)
        //  (fund money is members-only — see FundReportController)
        // Until users have a `status` column, nobody is an approved member yet, so 0.
        $approvedMembers = Schema::hasTable('users') && Schema::hasColumn('users', 'status')
            ? User::where('status', 'approved')->count()
            : 0;

        return response()->json([
            'data' => [
                'approved_members' => $approvedMembers,
            ],
        ]);
    }

    /** GET /api/districts — the 64 districts for the registration form: [{key, name_bn, name_en}]. */
    public function districts(): JsonResponse
    {
        $districts = collect(config('districts'))
            ->map(fn (array $names, string $key) => ['key' => $key, 'name_bn' => $names['bn'], 'name_en' => $names['en']])
            ->values();

        return response()->json(['data' => $districts]);
    }

    /**
     * GET /api/profile-options — fixed profile choice lists with bn/en labels:
     * {employment_statuses, document_types, portfolio_categories, machines, skills} → [{key, name_bn, name_en}].
     * machines / skills = the ACTIVE items of the admin-managed lists, in the admin's order (Phase 11d-1).
     */
    public function profileOptions(): JsonResponse
    {
        $options = collect(config('profile_options'))->map(
            fn (array $list) => collect($list)
                ->map(fn (array $names, string $key) => ['key' => $key, 'name_bn' => $names['bn'], 'name_en' => $names['en']])
                ->values()
        );
        foreach (ProfileListItem::COLUMNS as $type => $name) {
            $options[$name] = ProfileListItem::query()->active()->where('type', $type)->get()
                ->map(fn (ProfileListItem $item) => $item->toChoice());
        }

        return response()->json(['data' => $options]);
    }

    /**
     * GET /api/public/site-info — contact info + About text for the footer, Contact and About (Phase 11c).
     * Every field may be null (the website then hides that line). Cached until an admin saves a change.
     */
    public function siteInfo(): JsonResponse
    {
        return response()->json(['data' => SiteInfo::forPublic()]);
    }

    /**
     * GET /api/public/site-content — admin-edited page text (Phase 14a): {key: {bn?, en?}}, customised values only.
     * The website falls back to its translation file for every key / language not listed. Cached until a save.
     */
    public function siteContent(): JsonResponse
    {
        return response()->json(['data' => (object) SiteContent::forPublic()]);
    }

    /** GET /api/public/site-images — URLs of the admin-uploaded images only (missing key = built-in design default). */
    public function siteImages(): JsonResponse
    {
        return response()->json(['data' => (object) SiteImages::forPublic()]);
    }

    /**
     * GET /api/public/committee — committee members for the About page (Phase 11c): approved accounts with the
     * committee role, in the admin's order. Only name, photo and position — never mobile, email or anything else.
     */
    public function committee(): JsonResponse
    {
        return response()->json(['data' => SiteInfo::committeeForPublic()]);
    }

    /** POST /api/contact — save a contact-form message (rate limited: 5/hour/IP). */
    public function contact(StoreContactMessageRequest $request): JsonResponse
    {
        ContactMessage::create([
            ...$request->validated(),
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => __('messages.contact_sent')], 201);
    }
}
