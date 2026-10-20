<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CommitteeMember;
use App\Models\CommitteePosition;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Committee panel (Dashboard → Committee) — admin + super_admin only (route middleware). Members are chosen from
 * existing approved accounts; position (from an editable list), display order, term and active flag are kept here.
 * This is also the data behind the public About-page list (App\Support\SiteInfo::committeeForPublic).
 * It never changes an account's role, so the committee login permissions stay exactly as the Super Admin set them.
 * Every change is audit-logged; the reason is optional.
 */
class CommitteeController extends Controller
{
    public const SEARCH_LIMIT = 20;

    /** GET /api/admin/committee — members in display order + the position list. */
    public function index(): JsonResponse
    {
        return response()->json($this->payload());
    }

    /** GET /api/admin/committee/candidates?search= — approved, non-demo accounts not on the panel yet. */
    public function candidates(Request $request): JsonResponse
    {
        $search = strtr(trim((string) ($request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? '')),
            ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);

        $users = User::query()
            ->where('status', User::STATUS_APPROVED)->notDemo()
            ->whereNotIn('id', CommitteeMember::query()->select('user_id'))
            ->when($search !== '', function (Builder $q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('phone', 'like', $like));
            })
            ->orderBy('name')->orderBy('id')
            ->limit(self::SEARCH_LIMIT)
            ->get();

        return response()->json(['data' => $users->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'phone' => $u->phone,
            'photo_url' => $this->photo($u),
            'designation' => $u->designation,
        ])->values()]);
    }

    /** POST /api/admin/committee — {user_id, position_id?, term_start?, term_end?, reason?}: added at the end, active. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'position_id' => ['nullable', 'integer', 'exists:committee_positions,id'],
            ...$this->termRules(),
        ]);
        $user = User::query()->where('status', User::STATUS_APPROVED)->notDemo()->find($data['user_id']);
        if ($user === null) {
            $this->fail('user_id', 'committee_not_eligible');
        }
        if (CommitteeMember::where('user_id', $user->id)->exists()) {
            $this->fail('user_id', 'committee_already');
        }
        $this->checkTerm($data['term_start'] ?? null, $data['term_end'] ?? null);

        $member = DB::transaction(function () use ($request, $user, $data) {
            $member = CommitteeMember::create([
                'user_id' => $user->id,
                'position_id' => $data['position_id'] ?? null,
                'display_order' => (CommitteeMember::max('display_order') ?? 0) + 1,
                'term_start' => $data['term_start'] ?? null,
                'term_end' => $data['term_end'] ?? null,
                'is_active' => true,
            ]);
            AuditLog::record($request, 'committee.member_added', $user, $this->withReason($request, [
                'position' => $member->position?->name_en,
                'term_start' => $data['term_start'] ?? null,
                'term_end' => $data['term_end'] ?? null,
            ]));

            return $member;
        });

        return response()->json(['message' => __('messages.committee_member_added'), 'id' => $member->id, ...$this->payload()], 201);
    }

    /** PATCH /api/admin/committee/{id} — any of position_id (null = none), term_start, term_end, is_active, reason. */
    public function update(Request $request, int $member): JsonResponse
    {
        $row = CommitteeMember::query()->findOrFail($member);
        $data = $request->validate([
            'position_id' => ['sometimes', 'nullable', 'integer', 'exists:committee_positions,id'],
            'is_active' => ['sometimes', 'boolean'],
            ...$this->termRules(),
        ]);
        $this->checkTerm(
            array_key_exists('term_start', $data) ? $data['term_start'] : $row->term_start?->toDateString(),
            array_key_exists('term_end', $data) ? $data['term_end'] : $row->term_end?->toDateString(),
        );

        $before = $this->snapshot($row);
        $row->fill(array_intersect_key($data, array_flip(['position_id', 'term_start', 'term_end', 'is_active'])));
        $changes = [];
        if ($row->isDirty()) {
            $row->save();
            $row->unsetRelation('position');
            foreach ($this->snapshot($row) as $field => $value) {
                if ($before[$field] !== $value) {
                    $changes[$field] = ['old' => $before[$field], 'new' => $value];
                }
            }
        }
        if (! $changes) {
            return response()->json(['message' => __('messages.settings_nothing_changed'), ...$this->payload()]);
        }
        AuditLog::record($request, 'committee.member_updated', $row->user, $this->withReason($request, ['changes' => $changes]));

        return response()->json(['message' => __('messages.committee_member_saved'), ...$this->payload()]);
    }

    /** DELETE /api/admin/committee/{id} — removed from the panel (the account and its role are untouched). */
    public function destroy(Request $request, int $member): JsonResponse
    {
        $row = CommitteeMember::query()->with(['user', 'position'])->findOrFail($member);
        $request->validate(['reason' => ['nullable', 'string', 'max:300']]);

        DB::transaction(function () use ($request, $row) {
            $details = $this->withReason($request, ['position' => $row->position?->name_en, 'was_active' => $row->is_active]);
            $row->delete();
            AuditLog::record($request, 'committee.member_removed', $row->user, $details);
        });

        return response()->json(['message' => __('messages.committee_member_removed'), ...$this->payload()]);
    }

    /** PUT /api/admin/committee/order — {"ids": [...], reason?}: every panel member exactly once. */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);
        $ids = array_map('intval', $data['ids']);
        $all = CommitteeMember::query()->pluck('id')->all();
        if (count($ids) !== count($all) || array_diff($all, $ids) !== []) {
            $this->fail('ids', 'committee_order_invalid');
        }

        DB::transaction(function () use ($request, $ids) {
            foreach ($ids as $i => $id) {
                CommitteeMember::findOrFail($id)->forceFill(['display_order' => $i + 1])->save();
            }
            AuditLog::record($request, 'committee.reordered', null, $this->withReason($request, ['order' => $ids]));
        });

        return response()->json(['message' => __('messages.committee_order_saved'), ...$this->payload()]);
    }

    // ---- Positions ---------------------------------------------------------

    /** POST /api/admin/committee/positions — {name_en, name_bn}: added at the end of the list. */
    public function storePosition(Request $request): JsonResponse
    {
        $data = $this->positionData($request, null);
        $position = DB::transaction(function () use ($request, $data) {
            $position = CommitteePosition::create($data + ['sort_order' => (CommitteePosition::max('sort_order') ?? 0) + 1]);
            AuditLog::record($request, 'committee_position.created', $position, $this->withReason($request, $data));

            return $position;
        });

        return response()->json(['message' => __('messages.committee_position_created'), 'id' => $position->id, ...$this->payload()], 201);
    }

    /** PATCH /api/admin/committee/positions/{id} — rename (both languages). */
    public function updatePosition(Request $request, int $position): JsonResponse
    {
        $row = CommitteePosition::findOrFail($position);
        $data = $this->positionData($request, $row->id);
        $changes = [];
        foreach ($data as $field => $value) {
            if ($row->{$field} !== $value) {
                $changes[$field] = ['old' => $row->{$field}, 'new' => $value];
            }
        }
        if (! $changes) {
            return response()->json(['message' => __('messages.settings_nothing_changed'), ...$this->payload()]);
        }
        DB::transaction(function () use ($request, $row, $data, $changes) {
            $row->update($data);
            AuditLog::record($request, 'committee_position.updated', $row, $this->withReason($request, ['changes' => $changes]));
        });

        return response()->json(['message' => __('messages.committee_position_saved'), ...$this->payload()]);
    }

    /** DELETE /api/admin/committee/positions/{id} — only while nobody holds it. */
    public function destroyPosition(Request $request, int $position): JsonResponse
    {
        $row = CommitteePosition::findOrFail($position);
        $request->validate(['reason' => ['nullable', 'string', 'max:300']]);
        if ($row->members()->exists()) {
            abort(response()->json(['message' => __('messages.committee_position_in_use')], 422));
        }
        DB::transaction(function () use ($request, $row) {
            $row->delete();
            AuditLog::record($request, 'committee_position.deleted', $row, $this->withReason($request, ['name_en' => $row->name_en, 'name_bn' => $row->name_bn]));
        });

        return response()->json(['message' => __('messages.committee_position_deleted'), ...$this->payload()]);
    }

    // ---- Helpers -----------------------------------------------------------

    /** @return array{data: list<array<string, mixed>>, positions: list<array<string, mixed>>} */
    private function payload(): array
    {
        $members = CommitteeMember::query()->with(['user', 'position'])
            ->orderBy('display_order')->orderBy('id')->get()
            ->map(fn (CommitteeMember $m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'name' => $m->user->name,
                'phone' => $m->user->phone,
                'photo_url' => $this->photo($m->user),
                'position_id' => $m->position_id,
                'position' => $m->position ? ['name_en' => $m->position->name_en, 'name_bn' => $m->position->name_bn] : null,
                'display_order' => $m->display_order,
                'term_start' => $m->term_start?->toDateString(),
                'term_end' => $m->term_end?->toDateString(),
                'is_active' => $m->is_active,
                // Shown so admins notice a listed person who can no longer appear publicly (e.g. suspended).
                'account_status' => $m->user->status,
                'account_role' => $m->user->role,
            ])->values()->all();

        $positions = CommitteePosition::query()->withCount('members')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (CommitteePosition $p) => [
                'id' => $p->id, 'name_en' => $p->name_en, 'name_bn' => $p->name_bn, 'in_use' => $p->members_count,
            ])->values()->all();

        return ['data' => $members, 'positions' => $positions];
    }

    /** @return array<string, mixed> */
    private function termRules(): array
    {
        return [
            'term_start' => ['nullable', 'date_format:Y-m-d'],
            'term_end' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:300'],
        ];
    }

    private function checkTerm(?string $start, ?string $end): void
    {
        if ($start && $end && $end < $start) {
            $this->fail('term_end', 'committee_term_invalid');
        }
    }

    /** @return array{position: string|null, term_start: string|null, term_end: string|null, is_active: bool} */
    private function snapshot(CommitteeMember $m): array
    {
        return [
            'position' => $m->position?->name_en,
            'term_start' => $m->term_start?->toDateString(),
            'term_end' => $m->term_end?->toDateString(),
            'is_active' => $m->is_active,
        ];
    }

    /** @return array{name_en: string, name_bn: string} */
    private function positionData(Request $request, ?int $ignoreId): array
    {
        $request->merge(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $request->only(['name_en', 'name_bn'])));
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:60', Rule::unique('committee_positions', 'name_en')->ignore($ignoreId)],
            'name_bn' => ['required', 'string', 'max:60'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        return ['name_en' => $data['name_en'], 'name_bn' => $data['name_bn']];
    }

    /** @param array<string, mixed> $details */
    private function withReason(Request $request, array $details): array
    {
        $reason = trim((string) $request->input('reason', ''));

        return $reason === '' ? $details : $details + ['reason' => $reason];
    }

    private function photo(User $user): ?string
    {
        return $user->photo_path ? Storage::disk('public')->url($user->photo_path) : null;
    }

    private function fail(string $field, string $key): never
    {
        abort(response()->json(['message' => __("messages.$key"), 'errors' => [$field => [__("messages.$key")]]], 422));
    }
}
