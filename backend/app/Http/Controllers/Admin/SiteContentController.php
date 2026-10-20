<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SiteContent as SiteContentRow;
use App\Support\SiteContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Site Content (Phase 14a) — admin + super_admin (route middleware): edit the public-page texts (Bangla + English).
 * Plain text only (no < or >). A value that is empty or equal to the default is stored as "default". Every change
 * is audit-logged (key, language, before → after); the public cache is cleared on save.
 */
class SiteContentController extends Controller
{
    /** GET /api/admin/site-content — every editable field: group, limits, defaults, and the custom value (null = default). */
    public function index(): JsonResponse
    {
        $stored = SiteContent::stored();
        $items = [];
        foreach (SiteContent::registry() as $key => $def) {
            $items[] = [
                'key' => $key,
                'group' => $def['group'],
                'max' => $def['max'],
                'multiline' => $def['multiline'],
                'default_bn' => $def['bn'],
                'default_en' => $def['en'],
                'value_bn' => $stored[$key]['bn'] ?? null,
                'value_en' => $stored[$key]['en'] ?? null,
            ];
        }

        return response()->json(['data' => $items]);
    }

    /** PUT /api/admin/site-content {items: {key: {bn, en}}} — only the keys sent are touched. */
    public function update(Request $request): JsonResponse
    {
        $registry = SiteContent::registry();
        $items = $request->input('items');
        if (! is_array($items) || $items === []) {
            throw ValidationException::withMessages(['items' => __('messages.site_content_invalid')]);
        }

        $errors = [];
        $clean = [];
        foreach ($items as $key => $pair) {
            if (! is_string($key) || ! isset($registry[$key]) || ! is_array($pair)) {
                throw ValidationException::withMessages(['items' => __('messages.site_content_invalid')]);
            }
            foreach (['bn', 'en'] as $lang) {
                $value = $pair[$lang] ?? null;
                if ($value !== null && ! is_string($value)) {
                    throw ValidationException::withMessages(['items' => __('messages.site_content_invalid')]);
                }
                $value = $value === null ? '' : trim(str_replace("\r\n", "\n", $value));
                if (! $registry[$key]['multiline']) {
                    $value = trim((string) preg_replace('/\s+/u', ' ', $value));
                }
                if (preg_match('/[<>]/', $value)) {
                    $errors["items.$key.$lang"] = __('messages.site_content_plain_text');
                } elseif (mb_strlen($value) > $registry[$key]['max']) {
                    $errors["items.$key.$lang"] = __('messages.site_content_too_long', ['max' => $registry[$key]['max']]);
                }
                // Empty or same as the default → "use the default".
                $clean[$key][$lang] = ($value === '' || $value === $registry[$key][$lang]) ? null : $value;
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $changed = $this->save($request, $clean);

        return response()->json(['message' => $changed ? __('messages.site_content_saved') : __('messages.settings_nothing_changed')]);
    }

    /** POST /api/admin/site-content/reset {key} — back to the translation-file default (both languages). */
    public function reset(Request $request): JsonResponse
    {
        $key = $request->input('key');
        if (! is_string($key) || ! isset(SiteContent::registry()[$key])) {
            throw ValidationException::withMessages(['key' => __('messages.site_content_invalid')]);
        }
        $this->save($request, [$key => ['bn' => null, 'en' => null]], 'site_content.reset');

        return response()->json(['message' => __('messages.site_content_reset')]);
    }

    /**
     * @param  array<string, array{bn: ?string, en: ?string}>  $clean
     * @return int number of keys that changed
     */
    private function save(Request $request, array $clean, string $action = 'site_content.updated'): int
    {
        $registry = SiteContent::registry();
        $stored = SiteContent::stored();
        $changed = 0;

        DB::transaction(function () use ($request, $clean, $registry, $stored, $action, &$changed) {
            foreach ($clean as $key => $new) {
                $old = ['bn' => $stored[$key]['bn'] ?? null, 'en' => $stored[$key]['en'] ?? null];
                if ($old === $new) {
                    continue;
                }
                $changed++;
                if ($new['bn'] === null && $new['en'] === null) {
                    SiteContentRow::query()->where('key', $key)->delete();
                } else {
                    SiteContentRow::query()->updateOrCreate(['key' => $key], [
                        'value_bn' => $new['bn'], 'value_en' => $new['en'], 'updated_by' => $request->user()->id,
                    ]);
                }
                $details = ['key' => $key, 'group' => $registry[$key]['group']];
                foreach (['bn', 'en'] as $lang) {
                    if ($old[$lang] !== $new[$lang]) {
                        $details[$lang] = [
                            'from' => $old[$lang] ?? $registry[$key][$lang],
                            'to' => $new[$lang] ?? $registry[$key][$lang],
                        ];
                    }
                }
                AuditLog::record($request, $action, null, $details);
            }
        });
        if ($changed) {
            SiteContent::forget();
        }

        return $changed;
    }
}
