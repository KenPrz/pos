<?php

declare(strict_types=1);

namespace App\Actions\Admin\Audit;

use Illuminate\Support\Facades\DB;

/**
 * The audit-log viewer, read straight off `audit_log` — no Eloquent model exists for it
 * (see AuditLogger) and none is created here either. Left joins resolve the acting
 * user's and register's names for display; every filter is optional and applied with
 * `when()` so an empty query is simply "everything, newest first".
 *
 * `entity_type`/`entity_id` and `user_id` filters are covered by the table's own indexes
 * (see the audit_log migration); `action` and the date bounds are not, which is an
 * accepted cost for a back-office read at this scale.
 *
 * Read-only: no transaction, no audit entry for reading the audit log.
 */
final class ListAuditLog
{
    private const int PER_PAGE = 50;

    public function execute(ListAuditLogInput $in): object
    {
        $offset = ($in->page - 1) * self::PER_PAGE;

        $rows = DB::table('audit_log as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('registers as r', 'r.id', '=', 'a.register_id')
            ->when($in->entityType, fn ($q, string $v) => $q->where('a.entity_type', $v))
            ->when($in->entityId, fn ($q, string $v) => $q->where('a.entity_id', $v))
            ->when($in->userId, fn ($q, string $v) => $q->where('a.user_id', $v))
            ->when($in->action, fn ($q, string $v) => $q->where('a.action', $v))
            ->when($in->from, fn ($q, string $v) => $q->whereDate('a.created_at', '>=', $v))
            ->when($in->to, fn ($q, string $v) => $q->whereDate('a.created_at', '<=', $v))
            ->orderByDesc('a.created_at')
            ->offset($offset)
            ->limit(self::PER_PAGE + 1)
            ->select([
                'a.id', 'a.created_at', 'a.action', 'a.entity_type', 'a.entity_id', 'a.payload',
                'u.name as user_name', 'r.name as register_name',
            ])
            ->get();

        $hasMore = $rows->count() > self::PER_PAGE;

        return (object) [
            'rows' => $rows->take(self::PER_PAGE)->map(fn (object $row): array => [
                'id' => $row->id,
                'created_at' => $row->created_at,
                'action' => $row->action,
                'entity_type' => $row->entity_type,
                'entity_id' => $row->entity_id,
                'user_name' => $row->user_name,
                'register_name' => $row->register_name,
                'payload' => $row->payload === null ? null : json_decode((string) $row->payload, true),
            ])->values()->all(),
            'page' => $in->page,
            'has_more' => $hasMore,
        ];
    }
}
