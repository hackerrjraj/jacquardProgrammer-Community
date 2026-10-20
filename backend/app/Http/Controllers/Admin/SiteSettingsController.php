<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Support\BdPhone;
use App\Support\SiteInfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Site Settings (Phase 11c) — admin + super_admin (route middleware): contact info + About text (bn / en).
 * Changes are audit-logged by field name only (no values). The committee list is managed in Dashboard → Committee.
 */
class SiteSettingsController extends Controller
{
    /** GET /api/admin/site-settings */
    public function show(): JsonResponse
    {
        return response()->json(['data' => SiteInfo::stored()]);
    }

    /**
     * PUT /api/admin/site-settings — every field optional; empty = not shown on the website.
     * Mobile numbers: Bangladeshi mobile (+880 / spaces / dashes accepted). Facebook: https:// address only.
     */
    public function update(Request $request): JsonResponse
    {
        $input = [];
        foreach (SiteInfo::FIELDS as $field) {
            $value = $request->input($field);
            if (is_string($value)) {
                // Long texts keep their line breaks; only the ends are trimmed.
                $value = trim(str_replace("\r\n", "\n", $value));
                $value = $value === '' ? null : $value;
            }
            $input[$field] = in_array($field, ['phone_1', 'phone_2', 'whatsapp'], true) ? BdPhone::normalize($value) : $value;
        }
        $request->replace($input);

        $phone = ['nullable', 'string', 'regex:'.BdPhone::PATTERN];
        $data = $request->validate([
            'phone_1' => $phone,
            'phone_2' => $phone,
            'whatsapp' => $phone,
            'email' => ['nullable', 'string', 'max:150', 'email:rfc'],
            'facebook_url' => ['nullable', 'string', 'max:255', 'url:https', 'starts_with:https://'],
            'address_bn' => ['nullable', 'string', 'max:300'],
            'address_en' => ['nullable', 'string', 'max:300'],
            'office_hours_bn' => ['nullable', 'string', 'max:150'],
            'office_hours_en' => ['nullable', 'string', 'max:150'],
            'about_bn' => ['nullable', 'string', 'max:5000'],
            'about_en' => ['nullable', 'string', 'max:5000'],
        ], [
            '*.regex' => __('messages.site_phone_invalid'),
            'facebook_url.url' => __('messages.site_url_https'),
            'facebook_url.starts_with' => __('messages.site_url_https'),
        ]);

        $old = SiteInfo::stored();
        $new = array_merge(SiteInfo::defaults(), array_intersect_key($data, $old));
        $changed = array_values(array_filter(SiteInfo::FIELDS, fn ($f) => $old[$f] !== $new[$f]));

        if (! $changed) {
            return response()->json(['message' => __('messages.settings_nothing_changed'), 'data' => $old]);
        }

        DB::transaction(function () use ($request, $new, $changed) {
            Setting::put(Setting::SITE_INFO, json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            AuditLog::record($request, 'site_settings.updated', null, ['fields' => $changed]);
        });
        Cache::forget(SiteInfo::CACHE_KEY);

        return response()->json(['message' => __('messages.site_settings_saved'), 'data' => SiteInfo::stored()]);
    }
}
