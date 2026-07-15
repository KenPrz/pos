<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes the audit trail.
 *
 * Everything a supervisor role gates writes a row here — that list of actions and this
 * table are the same design viewed from two sides. Rows are never updated or deleted.
 * See docs/05-rbac.md.
 *
 * Deliberately not an Eloquent model: there is nothing to read here at runtime, and a
 * model invites someone to update or delete a row.
 */
final class AuditLogger
{
    /** @param array<string, mixed> $payload */
    public function record(
        string $action,
        Model|string $entity,
        ?string $actorId = null,
        array $payload = [],
        ?string $registerId = null,
        ?string $ip = null,
    ): void {
        [$entityType, $entityId] = $entity instanceof Model
            ? [class_basename($entity), $entity->getKey()]
            : [$entity, null];

        DB::table('audit_log')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $actorId,
            'register_id' => $registerId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload' => $payload === [] ? null : json_encode($payload),
            'ip' => $ip,
            'created_at' => now(),
        ]);
    }
}
