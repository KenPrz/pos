<?php

declare(strict_types=1);

namespace App\Actions\Admin\Reports;

use App\Domain\Money\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Sales report, four ways to slice the same window — see docs/02-data-model.md.
 *
 * `day`, `user` and `payment_method` are LEDGER-basis: money that actually moved, read
 * from `payments` (captured only) and `refunds`, keyed by the location's `business_date`,
 * by whoever took the tender / issued the refund, or by the tender itself. A voided order
 * never contributes: its captured payment was voided along with it (a voided payment's
 * status leaves `captured`, so the join simply stops matching it), and an order split
 * into children carries no payments of its own to begin with.
 *
 * `category` is LINE-basis: the sales mix off non-voided lines of CLOSED orders, joined
 * to the LIVE catalog (variant -> product -> category) for a human-readable category
 * name. A receipt must never do this join — it would print today's category over last
 * year's sale — but a report asking "what sold" is allowed to, because it's read fresh
 * every time rather than printed once and archived.
 *
 * The two bases are not required to reconcile (a discount changes a line's total but not
 * necessarily how it was tendered), which is exactly why the resource carries a `basis`
 * field naming which kind of number a given groupBy produced.
 *
 * Read-only: no transaction, no audit entry.
 */
final class SalesReport
{
    public function execute(SalesReportInput $in): object
    {
        return match ($in->groupBy) {
            'day' => $this->byDay($in),
            'user' => $this->byUser($in),
            'category' => $this->byCategory($in),
            'payment_method' => $this->byPaymentMethod($in),
        };
    }

    private function byDay(SalesReportInput $in): object
    {
        $gross = DB::table('payments as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->where('p.status', 'captured')
            ->where('o.location_id', $in->locationId)
            ->whereBetween('o.business_date', [$in->from, $in->to])
            ->groupBy('o.business_date')
            ->selectRaw('o.business_date as bucket, sum(p.amount_cents) as gross_cents')
            ->pluck('gross_cents', 'bucket');

        $refunds = DB::table('refunds')
            ->where('location_id', $in->locationId)
            ->whereBetween('business_date', [$in->from, $in->to])
            ->groupBy('business_date')
            ->selectRaw('business_date as bucket, sum(amount_cents) as refunds_cents')
            ->pluck('refunds_cents', 'bucket');

        $ordersClosed = DB::table('orders')
            ->where('location_id', $in->locationId)
            ->where('status', 'closed')
            ->whereBetween('business_date', [$in->from, $in->to])
            ->groupBy('business_date')
            ->selectRaw('business_date as bucket, count(*) as n')
            ->pluck('n', 'bucket');

        // pgsql sum()/count() return strings — every aggregate gets an explicit cast.
        $buckets = collect($gross->keys())->merge($refunds->keys())->merge($ordersClosed->keys())->unique()->sort();

        $rows = $buckets->map(fn ($day) => [
            'bucket' => (string) $day,
            'orders_closed' => (int) ($ordersClosed[$day] ?? 0),
            'gross_cents' => (int) ($gross[$day] ?? 0),
            'refunds_cents' => (int) ($refunds[$day] ?? 0),
            'net_cents' => (int) ($gross[$day] ?? 0) - (int) ($refunds[$day] ?? 0),
        ])->values()->all();

        return (object) [
            'rows' => $rows,
            'totals' => [
                'orders_closed' => array_sum(array_column($rows, 'orders_closed')),
                'gross_cents' => array_sum(array_column($rows, 'gross_cents')),
                'refunds_cents' => array_sum(array_column($rows, 'refunds_cents')),
                'net_cents' => array_sum(array_column($rows, 'net_cents')),
            ],
            'basis' => 'ledger',
        ];
    }

    private function byUser(SalesReportInput $in): object
    {
        $gross = DB::table('payments as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->where('p.status', 'captured')
            ->where('o.location_id', $in->locationId)
            ->whereBetween('o.business_date', [$in->from, $in->to])
            ->groupBy('p.user_id')
            ->selectRaw('p.user_id as bucket, sum(p.amount_cents) as gross_cents')
            ->pluck('gross_cents', 'bucket');

        $refunds = DB::table('refunds')
            ->where('location_id', $in->locationId)
            ->whereBetween('business_date', [$in->from, $in->to])
            ->groupBy('user_id')
            ->selectRaw('user_id as bucket, sum(amount_cents) as refunds_cents')
            ->pluck('refunds_cents', 'bucket');

        $userIds = collect($gross->keys())->merge($refunds->keys())->unique()->values();

        // One whereIn query maps every id present in the report to a name — never an
        // N+1 per bucket.
        $names = DB::table('users')->whereIn('id', $userIds)->pluck('name', 'id');

        $rows = $userIds->map(fn ($id) => [
            'bucket' => $names[$id] ?? (string) $id,
            'gross_cents' => (int) ($gross[$id] ?? 0),
            'refunds_cents' => (int) ($refunds[$id] ?? 0),
            'net_cents' => (int) ($gross[$id] ?? 0) - (int) ($refunds[$id] ?? 0),
        ])->sortBy('bucket')->values()->all();

        return (object) [
            'rows' => $rows,
            'totals' => [
                'gross_cents' => array_sum(array_column($rows, 'gross_cents')),
                'refunds_cents' => array_sum(array_column($rows, 'refunds_cents')),
                'net_cents' => array_sum(array_column($rows, 'net_cents')),
            ],
            'basis' => 'ledger',
        ];
    }

    /**
     * LEDGER-basis, like day and user: captured payments and refunds, i.e. money that
     * actually moved, bucketed by the tender it moved on.
     *
     * Grouped on the SNAPSHOT columns so a method renamed since the sale does not
     * retroactively rewrite last month's rows. The group name is joined from the live
     * tables — a method's group_id is immutable, so that join is stable, and a report
     * (unlike a receipt) is read fresh every time and may show today's group label.
     */
    private function byPaymentMethod(SalesReportInput $in): object
    {
        $gross = DB::table('payments as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->where('p.status', 'captured')
            ->where('o.location_id', $in->locationId)
            ->whereBetween('o.business_date', [$in->from, $in->to])
            ->groupBy('p.payment_method_code')
            ->selectRaw('p.payment_method_code as bucket, sum(p.amount_cents) as gross_cents')
            ->pluck('gross_cents', 'bucket');

        $refunds = DB::table('refunds')
            ->where('location_id', $in->locationId)
            ->whereBetween('business_date', [$in->from, $in->to])
            ->groupBy('payment_method_code')
            ->selectRaw('payment_method_code as bucket, sum(amount_cents) as refunds_cents')
            ->pluck('refunds_cents', 'bucket');

        $codes = collect($gross->keys())->merge($refunds->keys())->unique()->values();

        // One query maps every code in the report to its current group — never an N+1 per
        // bucket. Keyed by code, which is unique per location.
        $labels = DB::table('payment_methods as pm')
            ->join('payment_method_groups as g', 'g.id', '=', 'pm.group_id')
            ->where('pm.location_id', $in->locationId)
            ->whereIn('pm.code', $codes)
            ->get(['pm.code', 'g.code as group_code', 'g.name as group_name'])
            ->keyBy('code');

        // The name each tender was SOLD as, off the snapshot, one query for all buckets.
        // max() over the group is deliberate: a code renamed mid-window carries two
        // snapshot names and one row per code must pick one — the later name is the less
        // surprising label.
        $snapshotNames = DB::table('payments as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->where('o.location_id', $in->locationId)
            ->whereBetween('o.business_date', [$in->from, $in->to])
            ->groupBy('p.payment_method_code')
            ->selectRaw('p.payment_method_code as code, max(p.payment_method_name) as name')
            ->pluck('name', 'code');

        $rows = $codes->map(fn (string $code): array => [
            'bucket' => $code,
            'method_code' => $code,
            'method_name' => (string) ($snapshotNames[$code] ?? $code),
            'group_code' => $labels[$code]->group_code ?? null,
            'group_name' => $labels[$code]->group_name ?? null,
            'gross_cents' => (int) ($gross[$code] ?? 0),
            'refunds_cents' => (int) ($refunds[$code] ?? 0),
            'net_cents' => (int) ($gross[$code] ?? 0) - (int) ($refunds[$code] ?? 0),
        ])->sortBy('method_code')->values()->all();

        return (object) [
            'rows' => $rows,
            'totals' => [
                'gross_cents' => array_sum(array_column($rows, 'gross_cents')),
                'refunds_cents' => array_sum(array_column($rows, 'refunds_cents')),
                'net_cents' => array_sum(array_column($rows, 'net_cents')),
            ],
            'basis' => 'ledger',
        ];
    }

    private function byCategory(SalesReportInput $in): object
    {
        $rows = DB::table('order_lines as ol')
            ->join('orders as o', 'o.id', '=', 'ol.order_id')
            ->join('product_variants as pv', 'pv.id', '=', 'ol.variant_id')
            ->join('products as pr', 'pr.id', '=', 'pv.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'pr.category_id')
            ->where('o.status', 'closed')
            ->where('o.location_id', $in->locationId)
            ->whereBetween('o.business_date', [$in->from, $in->to])
            ->whereNull('ol.voided_at')
            ->groupBy('c.id', 'c.name')
            ->selectRaw("coalesce(c.name, 'Uncategorized') as bucket, sum(ol.qty) as qty_sold, sum(ol.line_total_cents) as line_total_cents")
            ->get()
            ->map(fn (object $r): array => [
                'bucket' => $r->bucket,
                // Quantity round-trips the pgsql numeric sum through the same milli
                // arithmetic as everywhere else, so the wire format is always 3 decimals.
                'qty_sold' => (string) Quantity::fromString((string) $r->qty_sold),
                'line_total_cents' => (int) $r->line_total_cents,
            ])
            ->sortBy('bucket')
            ->values();

        $totalQty = $rows->reduce(
            fn (Quantity $carry, array $row): Quantity => $carry->plus(Quantity::fromString($row['qty_sold'])),
            Quantity::zero(),
        );

        return (object) [
            'rows' => $rows->all(),
            'totals' => [
                'qty_sold' => (string) $totalQty,
                'line_total_cents' => (int) $rows->sum('line_total_cents'),
            ],
            'basis' => 'lines',
        ];
    }
}
