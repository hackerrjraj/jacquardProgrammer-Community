<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ProfileController;
use App\Http\Requests\AdminUpdateMemberRequest;
use App\Http\Resources\MemberListResource;
use App\Http\Resources\ProfileResource;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\Contribution;
use App\Models\User;
use App\Support\Dues;
use App\Support\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Member management for admin / super_admin (MASTER_SPEC §18).
 * Every change is written to the audit log.
 */
class MemberController extends Controller
{
    /**
     * GET /api/admin/members?status=pending&employment_status=&district=&search=&dues=with|without&sort=newest|name|due&per_page=20
     * Read-only: admin, super_admin and committee. Each row has a `payments` block (Phase 7d) for accounts that owe dues:
     * total paid, total due, months due, last paid month. dues=with / without = owes something now / owes nothing;
     * sort=due = most due first.
     * Phase 7f month filter: month=YYYY-MM adds each row's `month_status` (paid = in an approved payment, pending = waiting
     * for check, due = owes it and not claimed, null = owes nothing for that month) and `month_counts` {paid, due, pending}
     * over the same filters; month_status=paid|due|pending then narrows the list. Without `month` nothing changes.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(User::STATUSES)],
            'role' => ['nullable', Rule::in(User::ROLES)],
            'employment_status' => ['nullable', Rule::in(array_keys(config('profile_options.employment_statuses')))],
            'district' => ['nullable', Rule::in(array_keys(config('districts')))],
            'search' => ['nullable', 'string', 'max:100'],
            'dues' => ['nullable', Rule::in(['with', 'without'])],
            'sort' => ['nullable', Rule::in(['newest', 'name', 'due'])],
            'month' => ['nullable', 'date_format:Y-m'],
            'month_status' => ['nullable', Rule::in(['paid', 'due', 'pending'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $chargeable = Dues::chargeableMonths();
        $monthCount = $chargeable->count();
        $expected = (int) $chargeable->sum();
        $claimedCount = fn () => Dues::claimSub('count(*)', chargeable: true);

        $base = User::query()
            // Committee sees only the Programmers List population: approved + suspended accounts (Phase 12c).
            ->when(! $request->user()->isAdmin(), fn ($q) => $q->whereIn('users.status', User::DUES_STATUSES))
            ->when(($filters['dues'] ?? null) === 'with', fn ($q) => $q->owesDues()->where($claimedCount(), '<', $monthCount))
            ->when(($filters['dues'] ?? null) === 'without', fn ($q) => $q->owesDues()->where($claimedCount(), '>=', $monthCount))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when($filters['employment_status'] ?? null, fn ($q, $value) => $q->where('employment_status', $value))
            ->when($filters['district'] ?? null, fn ($q, $value) => $q->where('district', $value))
            ->when(trim($filters['search'] ?? ''), function ($q, $search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($q) => $q->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('workplace', 'like', $like));
            });

        // Month filter (Phase 7f): a claimed month of the outer `users` row inside the chosen month.
        $month = isset($filters['month']) ? CarbonImmutable::createFromFormat('!Y-m', $filters['month']) : null;
        $monthClaims = fn (?string $status = null) => DB::table('contribution_months as cm')
            ->join('contributions as c', 'c.id', '=', 'cm.contribution_id')
            ->whereColumn('cm.user_id', 'users.id')
            ->where('cm.claim', true)
            ->whereBetween('cm.month', [$month->toDateString(), $month->endOfMonth()->toDateString()])
            ->when($status, fn ($q) => $q->where('c.status', $status));
        // Only months from the fund start month to now can be due.
        $monthOwed = $month && $month->betweenIncluded(Dues::startMonth(), Dues::currentMonth());
        $byMonthStatus = fn ($q, string $status) => match ($status) {
            'paid' => $q->whereExists($monthClaims(Contribution::STATUS_APPROVED)),
            'pending' => $q->whereExists($monthClaims(Contribution::STATUS_PENDING)),
            'due' => $monthOwed ? $q->owesDues()->whereNotExists($monthClaims()) : $q->whereRaw('1 = 0'),
        };
        $monthCounts = $month ? collect(['paid', 'due', 'pending'])
            ->mapWithKeys(fn (string $status) => [$status => $byMonthStatus($base->clone(), $status)->count()])
            ->prepend($month->format('Y-m'), 'month') : null;

        $members = $base
            ->select('users.*')
            ->selectSub(Dues::claimSub('coalesce(sum(cm.amount), 0)', approvedOnly: true), 'paid_total')
            ->selectSub(Dues::claimSub('max(cm.month)', approvedOnly: true), 'last_paid_month')
            ->selectSub(Dues::claimSub('coalesce(sum(cm.amount), 0)', chargeable: true), 'claimed_amount')
            ->selectSub($claimedCount(), 'claimed_count')
            ->when($month, fn ($q) => $q->selectSub($monthClaims()->select('c.status')->limit(1), 'month_claim_status'))
            ->when($month ? ($filters['month_status'] ?? null) : null, $byMonthStatus)
            ->when($filters['sort'] ?? 'newest', fn ($q, string $sort) => match ($sort) {
                'name' => $q->orderBy('name')->orderBy('id'),
                // Most due first = least claimed first; accounts that owe nothing (pending / rejected) last.
                'due' => $q->orderByRaw("users.status in ('".implode("','", User::DUES_STATUSES)."') desc")
                    ->orderBy(Dues::claimSub('coalesce(sum(cm.amount), 0)', chargeable: true))
                    ->orderBy('name'),
                default => $q->latest('id'),
            })
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        // The money block per row (null for accounts that owe no dues).
        $members->getCollection()->each(fn (User $m) => $m->setAttribute('payments_summary', $m->owesDues() ? [
            'total_paid' => (int) $m->paid_total,
            'total_due' => max(0, $expected - (int) $m->claimed_amount),
            'months_due' => max(0, $monthCount - (int) $m->claimed_count),
            'last_paid_month' => $m->last_paid_month ? substr((string) $m->last_paid_month, 0, 7) : null,
        ] : null));
        if ($month) {
            $members->getCollection()->each(fn (User $m) => $m->setAttribute('month_status', match ($m->month_claim_status) {
                Contribution::STATUS_APPROVED => 'paid',
                Contribution::STATUS_PENDING => 'pending',
                default => $monthOwed && $m->owesDues() ? 'due' : null,
            }));
        }

        return MemberListResource::collection($members)->additional(['month_counts' => $monthCounts]);
    }

    /** GET /api/admin/members/{member}/profile — full profile incl. documents (view only; also committee). */
    public function profile(Request $request, User $member): ProfileResource
    {
        self::abortUnlessVisible($request, $member);

        return new ProfileResource($member->load(ProfileController::WITH));
    }

    /** Committee may only open approved / suspended members (the Programmers List population) — others 404 (Phase 12c). */
    public static function abortUnlessVisible(Request $request, User $member): void
    {
        abort_unless($request->user()->isAdmin() || $member->owesDues(), 404);
    }

    /** PATCH /api/admin/members/{member} — name and/or email only. */
    public function updateDetails(AdminUpdateMemberRequest $request, User $member): JsonResponse
    {
        if ($denied = $this->deny($request, $member)) {
            return $denied;
        }

        DB::transaction(function () use ($request, $member) {
            $member->fill($request->validated());
            $changes = [];
            foreach ($member->getDirty() as $field => $new) {
                $changes[$field] = ['from' => $member->getOriginal($field), 'to' => $new];
            }
            $member->save();

            AuditLog::record($request, 'member.details_changed', $member, ['changes' => $changes]);
        });

        return response()->json([
            'message' => __('messages.member_details_changed'),
            'data' => new ProfileResource($member->load(ProfileController::WITH)),
        ]);
    }

    /** POST /api/admin/members/{member}/approve — also re-activates rejected or suspended accounts. */
    public function approve(Request $request, User $member): JsonResponse
    {
        if ($denied = $this->deny($request, $member)) {
            return $denied;
        }
        if ($member->isApproved()) {
            return $this->wrongStatus();
        }

        $from = $member->status;
        DB::transaction(function () use ($request, $member, $from) {
            $member->status = User::STATUS_APPROVED;
            $member->approved_at = now();
            $member->approved_by = $request->user()->id;
            $member->rejection_reason = null;
            $member->save();

            AuditLog::record($request, 'member.approved', $member, ['from_status' => $from]);
        });
        Notifier::memberApproved($member);

        return $this->done('messages.member_approved', $member);
    }

    /** POST /api/admin/members/{member}/reject — reason required; only pending registrations. */
    public function reject(Request $request, User $member): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        if ($denied = $this->deny($request, $member)) {
            return $denied;
        }
        if ($member->status !== User::STATUS_PENDING) {
            return $this->wrongStatus();
        }

        $reason = trim($data['reason']);
        DB::transaction(function () use ($request, $member, $reason) {
            $member->status = User::STATUS_REJECTED;
            $member->rejection_reason = $reason;
            $member->save();

            AuditLog::record($request, 'member.rejected', $member, [
                'from_status' => User::STATUS_PENDING,
                'reason' => $reason,
            ]);
        });

        return $this->done('messages.member_rejected', $member);
    }

    /** POST /api/admin/members/{member}/suspend — optional reason; only approved members. */
    public function suspend(Request $request, User $member): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        if ($denied = $this->deny($request, $member)) {
            return $denied;
        }
        if (! $member->isApproved()) {
            return $this->wrongStatus();
        }

        DB::transaction(function () use ($request, $member, $data) {
            $member->status = User::STATUS_SUSPENDED;
            $member->save();

            AuditLog::record($request, 'member.suspended', $member, [
                'from_status' => User::STATUS_APPROVED,
                'reason' => isset($data['reason']) ? trim($data['reason']) : null,
            ]);
        });

        return $this->done('messages.member_suspended', $member);
    }

    /**
     * POST /api/admin/members/{member}/reset-password
     * Returns a temporary password ONCE; the member must change it at next login.
     */
    public function resetPassword(Request $request, User $member): JsonResponse
    {
        if ($denied = $this->deny($request, $member)) {
            return $denied;
        }

        $temporary = Str::password(10, symbols: false);

        DB::transaction(function () use ($request, $member, $temporary) {
            $member->password = $temporary;
            $member->must_change_password = true;
            $member->setRememberToken(Str::random(60));
            $member->save();

            // The password itself is never logged.
            AuditLog::record($request, 'member.password_reset', $member);
        });

        return response()->json([
            'message' => __('messages.password_reset_done'),
            'temporary_password' => $temporary,
            'data' => new UserResource($member),
        ])->header('Cache-Control', 'no-store');
    }

    /** POST /api/admin/members/{member}/role — Super Admin only (route middleware). */
    public function changeRole(Request $request, User $member): JsonResponse
    {
        $data = $request->validate(['role' => ['required', Rule::in(User::ROLES)]]);

        if ($denied = $this->deny($request, $member)) {
            return $denied;
        }
        if (! $member->isApproved()) {
            return response()->json(['message' => __('messages.must_be_approved')], 422);
        }
        if ($member->role === $data['role']) {
            return response()->json(['message' => __('messages.role_unchanged')], 422);
        }

        $from = $member->role;
        DB::transaction(function () use ($request, $member, $data, $from) {
            $member->role = $data['role'];
            $member->save();

            AuditLog::record($request, 'member.role_changed', $member, ['from_role' => $from, 'to_role' => $data['role']]);
        });

        return $this->done('messages.role_changed', $member);
    }

    private function deny(Request $request, User $member): ?JsonResponse
    {
        if ($request->user()->is($member)) {
            return response()->json(['message' => __('messages.cannot_manage_self')], 403);
        }
        if (! $request->user()->canManage($member)) {
            return response()->json(['message' => __('messages.cannot_manage_user')], 403);
        }

        return null;
    }

    private function wrongStatus(): JsonResponse
    {
        return response()->json(['message' => __('messages.wrong_status')], 422);
    }

    private function done(string $messageKey, User $member): JsonResponse
    {
        return response()->json([
            'message' => __($messageKey),
            'data' => new UserResource($member),
        ]);
    }
}
