<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use Illuminate\Support\Facades\DB;

/**
 * One denormalized payload, not five REST resources — a register needs the whole menu
 * to render, and five round-trips on a cold start is five chances to half-load it.
 * Prices are resolved for the location HERE; the register never implements pricing.
 * (updated_since delta sync deferred: modifiers has no updated_at to diff on.)
 */
final class GetCatalog
{
    public function execute(string $locationId): CatalogSnapshot
    {
        $groupIdsByProduct = DB::table('product_modifier_groups')
            ->orderBy('position')
            ->get()
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->pluck('group_id')->all());

        $products = DB::table('products')->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'category_id', 'kind'])
            ->map(fn ($p): array => (array) $p + ['modifier_group_ids' => $groupIdsByProduct[$p->id] ?? []])
            ->all();

        $variants = DB::table('product_variants as v')
            ->leftJoin('variant_location_prices as vlp', function ($join) use ($locationId): void {
                $join->on('vlp.variant_id', '=', 'v.id')->where('vlp.location_id', $locationId);
            })
            ->whereNull('v.deleted_at')
            ->where('v.is_active', true)
            ->orderBy('v.position')
            ->get([
                'v.id', 'v.product_id', 'v.name', 'v.sku', 'v.barcode',
                DB::raw('coalesce(vlp.price_cents, v.price_cents) as price_cents'),
                'v.tax_rate_id', 'v.track_inventory', 'v.position',
            ])
            ->map(fn ($v): array => (array) $v)
            ->all();

        return new CatalogSnapshot(
            categories: DB::table('categories')->orderBy('sort_order')
                ->get(['id', 'name', 'parent_id', 'sort_order'])->map(fn ($r): array => (array) $r)->all(),
            products: $products,
            variants: $variants,
            modifierGroups: DB::table('modifier_groups')
                ->get(['id', 'name', 'min_select', 'max_select'])->map(fn ($r): array => (array) $r)->all(),
            modifiers: DB::table('modifiers')->where('is_active', true)->orderBy('position')
                ->get(['id', 'group_id', 'name', 'price_delta_cents', 'position'])->map(fn ($r): array => (array) $r)->all(),
            taxRates: DB::table('tax_rates')->where('is_active', true)
                ->get(['id', 'name', 'rate_micros'])->map(fn ($r): array => (array) $r)->all(),
        );
    }
}
