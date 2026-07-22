<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use PDO;
use PDOException;
use Tests\TestCase;
use Throwable;

/**
 * DeleteRole's "count assigned users, then delete the materialized `roles` rows"
 * invariant from app/Actions/Admin/Roles/DeleteRole.php: the count and the delete must
 * take FOR UPDATE on the materialized roles rows before either, or a concurrent grant
 * (RoleAssignments::sync()'s insert into model_has_roles, which only holds an FK
 * reference) can commit between the count and the delete and be silently destroyed by
 * the cascade.
 *
 * Same classic-PHPUnit-class-not-Pest reasoning as
 * tests/Feature/Stock/ConcurrentSaleTest.php: a second connection needs to see rows the
 * first committed, which RefreshDatabase's single wrapping transaction would hide. It
 * also repeats the raw SQL DeleteRole/RoleAssignments issue rather than calling the
 * Actions from two Laravel connections — a single-process test would pass with or
 * without FOR UPDATE, which is worse than no test at all.
 */
final class RoleDeleteConcurrencyTest extends TestCase
{
    private function rawPdo(): PDO
    {
        $cfg = config('database.connections.pgsql');

        return new PDO(
            "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']}",
            $cfg['username'], $cfg['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    public function test_a_concurrent_grant_blocks_on_the_roles_row_instead_of_being_silently_destroyed(): void
    {
        $a = $this->rawPdo();
        $b = $this->rawPdo();

        $ids = [];
        $ids['location'] = $a->query(
            "insert into locations (name, code, timezone) values ('RDCtest', 'RD-'||substr(md5(random()::text),1,6), 'UTC') returning id"
        )->fetchColumn();
        $ids['template'] = $a->query(
            "insert into role_templates (name, is_system) values ('raced-'||substr(md5(random()::text),1,6), false) returning id, name"
        )->fetchColumn();
        // Re-fetch the generated name for later lookups (fetchColumn() above only grabbed the first column).
        $templateName = $a->query("select name from role_templates where id = '{$ids['template']}'")->fetchColumn();
        $ids['role'] = $a->query(
            "insert into roles (location_id, name, guard_name) values ('{$ids['location']}', '{$templateName}', 'web') returning id"
        )->fetchColumn();
        $ids['user'] = $a->query(
            "insert into users (name, pin_hash) values ('Racer', 'x') returning id"
        )->fetchColumn();

        try {
            // Connection A: the DeleteRole action's new lock step — take FOR UPDATE on
            // the materialized roles row(s) for this template before counting.
            $a->exec('begin');
            $lockedRoleId = $a->query(
                "select id from roles where name = '{$templateName}' and location_id is not null for update"
            )->fetchColumn();
            $this->assertSame($ids['role'], $lockedRoleId);

            // Connection B: RoleAssignments::sync()'s grant. Inserting a row that
            // references roles.id takes FOR KEY SHARE on the parent row first, which
            // conflicts with A's FOR UPDATE — this must block, not silently succeed.
            // A short lock_timeout turns "blocks" into a catchable error instead of
            // hanging the test.
            $b->exec('begin');
            $b->exec("set local lock_timeout = '200ms'");
            $blocked = false;
            try {
                $b->exec(
                    "insert into model_has_roles (role_id, model_type, model_id, location_id) ".
                    "values ('{$ids['role']}', 'App\\\\Models\\\\User', '{$ids['user']}', '{$ids['location']}')"
                );
            } catch (PDOException $e) {
                // lock_timeout cancellation surfaces the same SQLSTATE as NOWAIT:
                // lock_not_available. Assert the specific code, not just "it threw" — a
                // typo above would raise a different error and still look like a pass.
                $blocked = $e->getCode() === '55P03' || ($e->errorInfo[0] ?? null) === '55P03';
            }
            $b->exec('rollback');
            $this->assertTrue($blocked, 'expected the grant insert to block on the FOR UPDATE-held roles row');

            // Back in A: the count (now provably consistent, not stale) sees the
            // rolled-back grant as if it never happened, so the delete is safe to run —
            // exactly the "completes before the lock" ordering: A held the lock the
            // whole time, so nothing raced it.
            $assigned = (int) $a->query(
                "select count(*) from model_has_roles where role_id = '{$lockedRoleId}'"
            )->fetchColumn();
            $this->assertSame(0, $assigned);

            $a->exec("delete from roles where id = '{$lockedRoleId}'");
            $a->exec("delete from role_templates where id = '{$ids['template']}'");
            $a->exec('commit');

            // Proves the cascade actually ran and nothing was left orphaned.
            $stillThere = (int) $a->query("select count(*) from roles where id = '{$lockedRoleId}'")->fetchColumn();
            $this->assertSame(0, $stillThere);
        } finally {
            foreach ([$a, $b] as $pdo) {
                try {
                    $pdo->exec('rollback');
                } catch (Throwable) {
                    // no-op: nothing to roll back on this connection.
                }
            }
            $a->exec("delete from model_has_roles where model_id = '{$ids['user']}'");
            $a->exec("delete from users where id = '{$ids['user']}'");
            $a->exec("delete from roles where id = '{$ids['role']}'");
            $a->exec("delete from role_templates where id = '{$ids['template']}'");
            $a->exec("delete from locations where id = '{$ids['location']}'");
        }
    }
}
