<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Support\RegistrationTerms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Registration terms (admin + super_admin by route middleware). Every real change raises the version, stores
 * updated_at, clears the public cache and is audit-logged by field name only (no text).
 */
class RegistrationTermsController extends Controller
{
    /** GET /api/registration-terms — public, cached. */
    public function publicShow(): JsonResponse
    {
        return response()->json(['data' => RegistrationTerms::forPublic()]);
    }

    /** GET /api/admin/registration-terms */
    public function show(): JsonResponse
    {
        return response()->json(['data' => RegistrationTerms::stored()]);
    }

    /** PUT /api/admin/registration-terms */
    public function update(Request $request): JsonResponse
    {
        $rules = ['enabled' => ['required', 'boolean']];
        $messages = [];
        foreach (RegistrationTerms::FIELDS as $field => $max) {
            $rules[$field] = ['nullable', 'string', 'max:'.$max];
            $messages["$field.max"] = __('messages.terms_too_long', ['max' => $max]);
        }
        $data = $request->validate($rules, $messages);

        $old = RegistrationTerms::stored();
        $new = ['enabled' => (bool) $data['enabled']];
        foreach (array_keys(RegistrationTerms::FIELDS) as $field) {
            $value = trim(str_replace("\r\n", "\n", (string) ($data[$field] ?? '')));
            // An empty heading / checkbox label means "use the default text" (stored() fills it in).
            $new[$field] = $value !== '' ? $value : (RegistrationTerms::DEFAULTS[$field] ?? '');
        }

        $changed = array_values(array_filter(array_keys($new), fn ($f) => $old[$f] !== $new[$f]));
        if (! $changed) {
            return response()->json(['message' => __('messages.settings_nothing_changed'), 'data' => $old]);
        }

        DB::transaction(function () use ($request, $new, $old, $changed) {
            $stored = $new + ['version' => $old['version'] + 1, 'updated_at' => now()->toIso8601String()];
            Setting::put(Setting::REGISTRATION_TERMS, json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            AuditLog::record($request, 'registration_terms.updated', null, ['fields' => $changed, 'version' => $stored['version']]);
        });
        Cache::forget(RegistrationTerms::CACHE_KEY);

        return response()->json(['message' => __('messages.terms_saved'), 'data' => RegistrationTerms::stored()]);
    }
}
