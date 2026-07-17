'use client'

import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { api, type CatalogProduct, type CatalogVariant } from '../lib/api'
import { cents, formatMoney } from '../lib/money'
import { ModifierSheet } from './ModifierSheet'

const CURRENCY = 'USD'
const fm = (n: number) => formatMoney(cents(n), CURRENCY)

/**
 * Food-mode ordering surface: a category rail plus a tile grid, replacing the idle area
 * below SaleScreen's scan field. Consumes `api.catalog()` only — it knows nothing about
 * orders, mutations, or idempotency; the pick path (open-order-on-first-pick, keyed
 * addLine) lives in SaleScreen, same as the scan path already does.
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

  if (catalog.isLoading) return <p className="muted">Loading menu…</p>
  if (catalog.isError) return <p className="error">Could not load the menu.</p>
  if (categories.length === 0) return <p className="muted">No menu configured for this register.</p>

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

  return (
    <div className="menu-grid">
      <nav className="menu-rail" aria-label="Menu categories">
        {categories.map((cat) => (
          <button
            key={cat.id}
            type="button"
            className={`menu-rail-tab${cat.id === activeCategoryId ? ' active' : ''}`}
            aria-pressed={cat.id === activeCategoryId}
            onClick={() => setActiveCategoryId(cat.id)}
          >
            {cat.name}
          </button>
        ))}
      </nav>

      <div className="menu-tiles">
        {products.map((product) => {
          const variants = variantsFor(product)
          if (variants.length === 0) return null
          if (variants.length === 1) {
            const variant = variants[0]
            return (
              <button key={product.id} type="button" className="menu-tile" onClick={() => handlePick(product, variant)}>
                <span className="menu-tile-name">{product.name}</span>
                <span className="menu-tile-price num">{fm(variant.price_cents)}</span>
              </button>
            )
          }
          return variants.map((variant) => (
            <button key={variant.id} type="button" className="menu-tile" onClick={() => handlePick(product, variant)}>
              <span className="menu-tile-name">
                {product.name} — {variant.name}
              </span>
              <span className="menu-tile-price num">{fm(variant.price_cents)}</span>
            </button>
          ))
        })}
        {products.length === 0 && <p className="muted">Nothing in this category.</p>}
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
