<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Hero slider "Overlay darkness" (admin + super_admin by route middleware): whole percent 0–80, default 40. */
class HeroOverlayController extends Controller
{
    public const DEFAULT = 40;

    public const MAX = 80;

    /** The saved darkness (also sent with the public GET /api/hero-slides). */
    public static function current(): int
    {
        $saved = Setting::valueOf(Setting::HERO_OVERLAY);

        return is_numeric($saved) ? max(0, min(self::MAX, (int) $saved)) : self::DEFAULT;
    }

    /** GET /api/admin/hero-overlay */
    public function show(): JsonResponse
    {
        return response()->json(['data' => ['overlay' => self::current(), 'default' => self::DEFAULT, 'max' => self::MAX]]);
    }

    /** PUT /api/admin/hero-overlay {overlay} */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['overlay' => ['required', 'integer', 'min:0', 'max:'.self::MAX]]);
        $old = self::current();
        $new = (int) $data['overlay'];

        if ($old !== $new) {
            DB::transaction(function () use ($request, $old, $new) {
                Setting::put(Setting::HERO_OVERLAY, (string) $new);
                AuditLog::record($request, 'hero_overlay.updated', null, ['from' => $old, 'to' => $new]);
            });
        }

        return response()->json(['message' => __('messages.hero_overlay_saved'), 'data' => ['overlay' => $new]]);
    }
}
