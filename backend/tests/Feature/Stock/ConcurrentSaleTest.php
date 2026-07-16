<?php

declare(strict_types=1);

namespace Tests\Feature\Stock;

use PDO;
use PDOException;
use Tests\TestCase;
use Throwable;

/**
 * The "two cashiers sell the last unit" invariant from docs/01-architecture.md.
 *
 * This cannot run under RefreshDatabase: a second connection needs to see rows the first
 * committed, and RefreshDatabase wraps the whole test in one transaction that every
 * Eloquent/DB::connection() call shares. A plain PHPUnit test class extending
 * Tests\TestCase (no Pest it()/test() syntax) is not a Pest-managed test case, so it
 * never passes through tests/Pest.php's `pest()->extend(TestCase::class)
 * ->use(RefreshDatabase::class)->in('Feature')` binding — that binding only rewrites
 * files that go through Pest's functional syntax into an anonymous TestCase subclass.
 * A classic class extending Tests\TestCase directly is used exactly as written, so
 * TestCase boots (DB config, container) with no wrapping transaction. Verified empirically:
 * `DB::transactionLevel()` is 0 inside this class, and >0 inside a Pest it() in the same
 * directory.
 *
 * It also deliberately repeats the ledger's raw SQL rather than calling StockLedger from
 * two Laravel connections — a single-process Laravel test would pass with or without
 * FOR UPDATE, which is worse than no test at all.
 */
final class ConcurrentSaleTest extends TestCase
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

    public function test_it_serializes_concurrent_sellers_of_the_last_unit_with_for_update(): void
    {
        $a = $this->rawPdo();
        $b = $this->rawPdo();

        // Committed fixture rows (cleaned up below).
        $ids = [];
        foreach ([
            'location' => "insert into locations (name, code, timezone) values ('Ctest', 'CT-'||substr(md5(random()::text),1,6), 'UTC') returning id",
            'product' => "insert into products (name) values ('Ctest') returning id",
        ] as $k => $sql) {
            $ids[$k] = $a->query($sql)->fetchColumn();
        }
        $ids['variant'] = $a->query("insert into product_variants (product_id, name, sku, price_cents) values ('{$ids['product']}', 'Default', 'CTEST-'||substr(md5(random()::text),1,6), 100) returning id")->fetchColumn();
        $a->exec("insert into stock_levels (variant_id, location_id, qty) values ('{$ids['variant']}', '{$ids['location']}', 1)");

        try {
            // Cashier A locks the level row mid-sale.
            $a->exec('begin');
            $qtyA = $a->query("select qty from stock_levels where variant_id = '{$ids['variant']}' and location_id = '{$ids['location']}' for update")->fetchColumn();
            $this->assertSame(1.0, (float) $qtyA);

            // Cashier B cannot even read the row for update while A holds it.
            $b->exec('begin');
            $blocked = false;
            try {
                $b->query("select qty from stock_levels where variant_id = '{$ids['variant']}' and location_id = '{$ids['location']}' for update nowait");
            } catch (PDOException) {
                $blocked = true;   // 55P03 lock_not_available
            }
            $b->exec('rollback');
            $this->assertTrue($blocked);

            // A completes the sale and commits.
            $a->exec("update stock_levels set qty = qty - 1 where variant_id = '{$ids['variant']}' and location_id = '{$ids['location']}'");
            $a->exec('commit');

            // B retries: sees 0, must refuse — one winner, one insufficient_stock.
            $qtyB = $b->query("select qty from stock_levels where variant_id = '{$ids['variant']}' and location_id = '{$ids['location']}' for update")->fetchColumn();
            $this->assertSame(0.0, (float) $qtyB);
        } finally {
            foreach ([$a, $b] as $pdo) {
                try {
                    $pdo->exec('rollback');
                } catch (Throwable) {
                    // no-op: nothing to roll back on this connection.
                }
            }
            $a->exec("delete from stock_levels where variant_id = '{$ids['variant']}'");
            $a->exec("delete from product_variants where id = '{$ids['variant']}'");
            $a->exec("delete from products where id = '{$ids['product']}'");
            $a->exec("delete from locations where id = '{$ids['location']}'");
        }
    }
}
