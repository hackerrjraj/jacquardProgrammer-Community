<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ProfileListItem;
use App\Models\User;
use App\Support\ProfileLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Machine + Skill lists (Phase 11d-1, MASTER_SPEC §19) — admin + super_admin (route middleware).
 * Hide instead of delete: a hidden item is not offered for new picks, but profiles keep and show it.
 * Delete = super_admin only (route middleware) and only while no account has the item saved.
 * Every change is written to the audit log.
 */
class ProfileListController extends Controller
{
    /** GET /api/admin/profile-lists — {machines: [item], skills: [item]}, every item (active + hidden) in order. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->lists()]);
    }

    /** POST /api/admin/profile-lists — {type: machine|skill, name_bn, name_en, is_active?}. Added at the end. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', ProfileListItem::TYPES)],
            ...$this->nameRules(false),
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $names = $this->cleanNames($data);
        $this->checkUnique($data['type'], $names);

        $item = DB::transaction(function () use ($request, $data, $names) {
            $item = ProfileListItem::create([
                'type' => $data['type'],
                'key' => ProfileLists::makeKey($data['type'], $names['name_en']),
                ...$names,
                'is_active' => $data['is_active'] ?? true,
                'sort_order' => (ProfileListItem::where('type', $data['type'])->max('sort_order') ?? -1) + 1,
            ]);
            AuditLog::record($request, 'profile_list.created', $item, [
                'type' => $item->type,
                'key' => $item->key,
                'name_bn' => $item->name_bn,
                'name_en' => $item->name_en,
                'is_active' => $item->is_active,
            ]);

            return $item;
        });

        return response()->json([
            'message' => __('messages.list_item_created'),
            'data' => $this->present($item->refresh(), 0),
        ], 201);
    }

    /** PATCH /api/admin/profile-lists/{id} — {name_bn?, name_en?}. The key never changes, so profiles show the new name. */
    public function update(Request $request, int $item): JsonResponse
    {
        $listItem = ProfileListItem::findOrFail($item);
        $names = $this->cleanNames($request->validate($this->nameRules(true)));
        $this->checkUnique($listItem->type, $names, $listItem);

        $listItem->fill($names);
        if ($listItem->isDirty()) {
            DB::transaction(function () use ($request, $listItem) {
                $changes = collect($listItem->getDirty())
                    ->map(fn ($new, string $field) => ['from' => $listItem->getOriginal($field), 'to' => $new])
                    ->all();
                $listItem->save();
                AuditLog::record($request, 'profile_list.updated', $listItem, [
                    'type' => $listItem->type,
                    'key' => $listItem->key,
                    'changes' => $changes,
                ]);
            });
        }

        return response()->json([
            'message' => __('messages.list_item_updated'),
            'data' => $this->present($listItem, $this->usage($listItem)),
        ]);
    }

    /** POST /api/admin/profile-lists/{id}/active — {"is_active": true|false}: show in / hide from new choices. */
    public function setActive(Request $request, int $item): JsonResponse
    {
        $listItem = ProfileListItem::findOrFail($item);
        $active = filter_var($request->validate(['is_active' => ['required', 'boolean']])['is_active'], FILTER_VALIDATE_BOOLEAN);

        // Pressing the same switch twice changes nothing and is not logged again.
        if ($listItem->is_active !== $active) {
            $listItem->is_active = $active;
            DB::transaction(function () use ($request, $listItem, $active) {
                $listItem->save();
                AuditLog::record($request, $active ? 'profile_list.shown' : 'profile_list.hidden', $listItem, [
                    'type' => $listItem->type,
                    'key' => $listItem->key,
                    'name_en' => $listItem->name_en,
                ]);
            });
        }

        return response()->json([
            'message' => __($active ? 'messages.list_item_shown' : 'messages.list_item_hidden'),
            'data' => $this->present($listItem, $this->usage($listItem)),
        ]);
    }

    /** PUT /api/admin/profile-lists/order — {type, ids: [3, 1, 2]}: every item id of that list exactly once. */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', ProfileListItem::TYPES)],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ]);
        $ids = array_map('intval', $data['ids']);

        $allIds = ProfileListItem::where('type', $data['type'])->pluck('id')->all();
        if (count($ids) !== count($allIds) || array_diff($allIds, $ids) !== []) {
            throw ValidationException::withMessages(['ids' => __('messages.list_order_invalid')]);
        }

        DB::transaction(function () use ($request, $data, $ids) {
            foreach ($ids as $position => $id) {
                ProfileListItem::whereKey($id)->where('sort_order', '!=', $position)->update(['sort_order' => $position]);
            }
            app(ProfileLists::class)->flush();
            AuditLog::record($request, 'profile_list.reordered', null, ['type' => $data['type'], 'order' => $ids]);
        });

        return response()->json([
            'message' => __('messages.list_order_saved'),
            'data' => $this->lists(),
        ]);
    }

    /** DELETE /api/admin/profile-lists/{id} — super_admin only (route), and only if no account has it saved. */
    public function destroy(Request $request, int $item): JsonResponse
    {
        $listItem = ProfileListItem::findOrFail($item);

        DB::transaction(function () use ($request, $listItem) {
            // Checked inside the transaction (row lock) so a profile saved at the same moment is not missed.
            ProfileListItem::whereKey($listItem->id)->lockForUpdate()->first();
            $used = $this->usage($listItem);
            if ($used > 0) {
                throw ValidationException::withMessages(['item' => __('messages.list_item_in_use', ['count' => $used])]);
            }

            $listItem->delete();
            AuditLog::record($request, 'profile_list.deleted', $listItem, [
                'type' => $listItem->type,
                'key' => $listItem->key,
                'name_bn' => $listItem->name_bn,
                'name_en' => $listItem->name_en,
            ]);
        });

        return response()->json(['message' => __('messages.list_item_deleted')]);
    }

    /**
     * GET /api/admin/profile-lists/others — the "Other" texts members typed, grouped by text (case and spacing
     * ignored): {machines: [{text, count, members: [name]}], skills: [...]}.
     */
    public function others(): JsonResponse
    {
        return response()->json(['data' => $this->otherLists()]);
    }

    /**
     * POST /api/admin/profile-lists/others/add — {type, text}: "Add to official list". Creates the option (or reuses an
     * existing one with the same name), then moves every member's matching "Other" text into their normal picks.
     */
    public function addOther(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', ProfileListItem::TYPES)],
            'text' => ['required', 'string', 'max:100'],
        ]);
        $text = trim((string) ProfileLists::normalizeSpaces($data['text']));
        $type = $data['type'];
        $column = ProfileListItem::COLUMNS[$type];
        $otherColumn = 'other_'.$type;
        $norm = ProfileLists::normalize($text);

        $item = DB::transaction(function () use ($request, $type, $text, $column, $otherColumn, $norm) {
            $members = User::query()->whereNotNull($otherColumn)->lockForUpdate()->get()
                ->filter(fn (User $u) => ProfileLists::normalize((string) $u->{$otherColumn}) === $norm);
            if ($members->isEmpty()) {
                throw ValidationException::withMessages(['text' => __('messages.other_not_found')]);
            }

            $item = ProfileListItem::where('type', $type)->get()
                ->first(fn (ProfileListItem $i) => ProfileLists::normalize($i->name_en) === $norm || ProfileLists::normalize($i->name_bn) === $norm);
            $created = ! $item;
            $item ??= ProfileListItem::create([
                'type' => $type,
                'key' => ProfileLists::makeKey($type, $text),
                'name_bn' => $text,
                'name_en' => $text,
                'is_active' => true,
                'sort_order' => (ProfileListItem::where('type', $type)->max('sort_order') ?? -1) + 1,
            ]);

            foreach ($members as $member) {
                $keys = array_values(array_unique([...array_filter((array) $member->{$column}, 'is_string'), $item->key]));
                $member->forceFill([$column => $keys, $otherColumn => null])->save();
            }

            AuditLog::record($request, 'profile_list.other_added', $item, [
                'type' => $type,
                'key' => $item->key,
                'text' => $text,
                'created' => $created,
                'members' => $members->count(),
            ]);

            return $item;
        });

        return response()->json([
            'message' => __('messages.list_item_created'),
            'data' => $this->present($item->refresh(), $this->usage($item)),
            'others' => $this->otherLists(),
        ], 201);
    }

    // ---- Helpers ------------------------------------------------------------

    /** @return array{machines: list<array<string, mixed>>, skills: list<array<string, mixed>>} */
    private function lists(): array
    {
        $lists = app(ProfileLists::class);
        $items = ProfileListItem::query()->ordered()->get()->groupBy('type');
        $result = [];
        foreach (ProfileListItem::COLUMNS as $type => $name) {
            $counts = $lists->usageCounts($type);
            $result[$name] = $items->get($type, collect())
                ->map(fn (ProfileListItem $item) => $this->present($item, $counts[$item->key] ?? 0))
                ->values()
                ->all();
        }

        return $result;
    }

    /** @return array{machines: list<array<string, mixed>>, skills: list<array<string, mixed>>} */
    private function otherLists(): array
    {
        $result = [];
        foreach (ProfileListItem::COLUMNS as $type => $name) {
            $column = 'other_'.$type;
            $result[$name] = User::query()->whereNotNull($column)->orderBy('id')->get(['id', 'name', $column])
                ->groupBy(fn (User $u) => ProfileLists::normalize((string) $u->{$column}))
                ->map(fn ($group) => [
                    'text' => (string) $group->first()->{$column},
                    'count' => $group->count(),
                    'members' => $group->pluck('name')->values()->all(),
                ])
                ->values()
                ->all();
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function present(ProfileListItem $item, int $usedBy): array
    {
        return [
            'id' => $item->id,
            'type' => $item->type,
            'key' => $item->key,
            'name_bn' => $item->name_bn,
            'name_en' => $item->name_en,
            'is_active' => $item->is_active,
            'sort_order' => $item->sort_order,
            'used_by' => $usedBy,
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }

    private function usage(ProfileListItem $item): int
    {
        return app(ProfileLists::class)->usageCounts($item->type)[$item->key] ?? 0;
    }

    /** @return array<string, list<string>> */
    private function nameRules(bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        return [
            'name_bn' => [...$required, 'string', 'max:100'],
            'name_en' => [...$required, 'string', 'max:100'],
        ];
    }

    /** Trimmed, single-spaced names; a name that is only spaces is refused like an empty one. */
    private function cleanNames(array $data): array
    {
        $clean = [];
        foreach (['name_bn', 'name_en'] as $field) {
            if (array_key_exists($field, $data)) {
                $clean[$field] = trim((string) preg_replace('/\s+/u', ' ', (string) $data[$field]));
                if ($clean[$field] === '') {
                    throw ValidationException::withMessages([$field => __('validation.required', ['attribute' => __('validation.attributes.'.$field)])]);
                }
            }
        }

        return $clean;
    }

    /** No two items of the same list may share a name (case and spacing ignored), in either language. */
    private function checkUnique(string $type, array $names, ?ProfileListItem $self = null): void
    {
        $others = ProfileListItem::where('type', $type)->when($self, fn ($q) => $q->whereKeyNot($self->id))->get();
        foreach ($names as $field => $name) {
            $norm = ProfileLists::normalize($name);
            if ($others->contains(fn (ProfileListItem $i) => ProfileLists::normalize($i->{$field}) === $norm)) {
                throw ValidationException::withMessages([$field => __('messages.list_item_duplicate')]);
            }
        }
    }
}
