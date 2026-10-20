<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Audit log viewer (Phase 11a, MASTER_SPEC §34, §37) — admin + super_admin (route middleware), read-only.
 * There are no edit / delete endpoints; the model also refuses changes.
 */
class AuditLogController extends Controller
{
    public const PER_PAGE = 25;

    /** GET /api/admin/audit-log?actor=&action=&from=&to=&page= — newest first. Dates are days in the app timezone (Dhaka). */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'actor' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $actor = strtr(trim($filters['actor'] ?? ''), ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);

        $page = AuditLog::query()
            ->with('actor:id,name,phone,role')
            ->when($actor !== '', function (Builder $q) use ($actor) {
                $like = '%'.addcslashes($actor, '%_\\').'%';
                $q->whereHas('actor', fn (Builder $u) => $u->where('name', 'like', $like)->orWhere('phone', 'like', $like));
            })
            ->when($filters['action'] ?? null, fn (Builder $q, string $a) => $q->where('action', $a))
            ->when($filters['from'] ?? null, fn (Builder $q, string $d) => $q->where('created_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($filters['to'] ?? null, fn (Builder $q, string $d) => $q->where('created_at', '<=', Carbon::parse($d)->endOfDay()))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Member names for entries about a member (target "User"), loaded in one query.
        $userIds = $page->getCollection()->where('target_type', 'User')->pluck('target_id')->filter()->unique();
        $names = $userIds->isEmpty() ? collect() : User::whereIn('id', $userIds)->pluck('name', 'id');

        return response()->json([
            'data' => $page->getCollection()->map(fn (AuditLog $log) => self::entry($log, $names))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** GET /api/admin/audit-log/actions — every action name used so far, for the filter dropdown. */
    public function actions(): JsonResponse
    {
        return response()->json(['data' => AuditLog::query()->distinct()->orderBy('action')->pluck('action')]);
    }

    /** Safety net: never show password / token values, even if an older entry stored one. */
    private static function withoutSecrets(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/password|token|secret/i', $key)) {
                $value[$key] = '••••';
            } elseif (is_array($item)) {
                $value[$key] = self::withoutSecrets($item);
            }
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private static function entry(AuditLog $log, $names): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'actor' => $log->actor ? [
                'id' => $log->actor->id,
                'name' => $log->actor->name,
                'phone' => $log->actor->phone,
                'role' => $log->actor->role,
            ] : null,
            'target_type' => $log->target_type,
            'target_id' => $log->target_id,
            'target_name' => $log->target_type === 'User' ? ($names[$log->target_id] ?? null) : null,
            'details' => self::withoutSecrets($log->details),
            'ip_address' => $log->ip_address,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }
}
