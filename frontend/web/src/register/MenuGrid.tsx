'use client'

import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { api, type CatalogProduct, type CatalogVariant } from '../lib/api'
import { getCurrency } from '../lib/currency'
import { MoneyText } from '@/components/MoneyText'
import { TileButton } from '@/components/TileButton'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { ModifierSheet } from './ModifierSheet'

/**
 * Food-mode ordering surface: a category tab strip plus a tile grid, replacing the idle
 * area below SaleScreen's scan field. Consumes `api.catalog()` only — it knows nothing
 * about orders, mutations, or idempotency; the pick path (open-order-on-first-pick,
 * keyed addLine) lives in SaleScreen, same as the scan path already does.
 *
 * A product with exactly one variant is picked directly by product name (the seeder's
 * "Default" variant is deliberately hidden from staff). A product with several variants
 * (a size run, say) shows one tile per variant instead, each carrying the product name so
 * the tile still reads as "Latte — Large" rather than a bare variant SKU name.
 */
export function MenuGrid({
  onPick,
}: {
  onPick: (variant: CatalogVariant, product: CatalogProduct, modifierIds?: string[]) => void
}) {
  const catalog = useQuery({ queryKey: ['catalog'], queryFn: () => api.catalog(), staleTime: 5 * 60_000 })
  const [activeCategoryId, setActiveCategoryId] = useState<string | null>(null)
  const [pending, setPending] = useState<{ variant: CatalogVariant; product: CatalogProduct } | null>(null)

  const categories = [...(catalog.data?.categories ?? [])].sort((a, b) => a.sort_order - b.sort_order)

  // Land on the first category once the catalog loads; a later re-fetch (staleTime
  // notwithstanding) must not yank the staff back to tab one mid-order, hence the guard.
  useEffect(() => {
    if (activeCategoryId === null && categories.length > 0) setActiveCategoryId(categories[0].id)
    // eslint-disable-next-line react-hooks/exhaustive-deps -- only the "haven't picked yet" transition should run this
  }, [categories.length])

  if (catalog.isLoading) return <p className="type-body-sm text-ink-muted">Loading menu…</p>
  if (catalog.isError) return <p className="type-body-sm text-error">Could not load the menu.</p>
  if (categories.length === 0) return <p className="type-body-sm text-ink-muted">No menu configured for this register.</p>

  const products = (catalog.data?.products ?? []).filter((p) => p.category_id === activeCategoryId)
  const variantsFor = (product: CatalogProduct) =>
    (catalog.data?.variants ?? []).filter((v) => v.product_id === product.id).sort((a, b) => a.position - b.position)

  const handlePick = (product: CatalogProduct, variant: CatalogVariant) => {
    if (product.modifier_group_ids.length === 0) {
      onPick(variant, product)
      return
    }
    setPending({ variant, product })
  }

  const pendingGroups = pending
    ? (catalog.data?.modifier_groups ?? []).filter((g) => pending.product.modifier_group_ids.includes(g.id))
    : []
  const pendingGroupIds = new Set(pendingGroups.map((g) => g.id))
  const pendingModifiers = pending ? (catalog.data?.modifiers ?? []).filter((m) => pendingGroupIds.has(m.group_id)) : []

  const tileMeta = (variant: CatalogVariant) => (
    <MoneyText cents={variant.price_cents} currency={getCurrency()} size="line" className="text-ink" />
  )

  return (
    <div className="flex flex-col gap-md">
      <Tabs value={activeCategoryId ?? ''} onValueChange={setActiveCategoryId}>
        <TabsList aria-label="Menu categories" className="flex-wrap">
          {categories.map((cat) => (
            <TabsTrigger key={cat.id} value={cat.id} className="min-h-[48px]">
              {cat.name}
            </TabsTrigger>
          ))}
        </TabsList>
      </Tabs>

      <div className="grid grid-cols-[repeat(auto-fill,minmax(160px,1fr))] gap-sm">
        {products.map((product) => {
          const variants = variantsFor(product)
          if (variants.length === 0) return null
          if (variants.length === 1) {
            const variant = variants[0]
            return (
              <TileButton
                key={product.id}
                title={product.name}
                meta={tileMeta(variant)}
                onClick={() => handlePick(product, variant)}
              />
            )
          }
          return variants.map((variant) => (
            <TileButton
              key={variant.id}
              title={`${product.name} — ${variant.name}`}
              meta={tileMeta(variant)}
              onClick={() => handlePick(product, variant)}
            />
          ))
        })}
        {products.length === 0 && <p className="type-body-sm text-ink-muted">Nothing in this category.</p>}
      </div>

      {pending && (
        <ModifierSheet
          productName={pending.product.name}
          groups={pendingGroups}
          modifiers={pendingModifiers}
          onConfirm={(modifierIds) => {
            onPick(pending.variant, pending.product, modifierIds)
            setPending(null)
          }}
          onCancel={() => setPending(null)}
        />
      )}
    </div>
  )
}
