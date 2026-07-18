'use client'

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import {
  ApiError,
  api,
  type Category,
  type Discount,
  type ModifierGroup,
  type Product,
  type TaxRate,
  type Variant,
} from '../../lib/api'
import { EntityTable } from './EntityTable'
import { SimpleEditor } from './SimpleEditor'
import { ProductEditor } from './ProductEditor'
import { VariantEditor } from './VariantEditor'
import { ModifierGroupEditor } from './ModifierGroupEditor'
import { DiscountEditor } from './DiscountEditor'

type Tab = 'products' | 'variants' | 'categories' | 'modifier-groups' | 'discounts' | 'tax-rates'

const TABS: Array<{ id: Tab; label: string }> = [
  { id: 'products', label: 'Products' },
  { id: 'variants', label: 'Variants' },
  { id: 'categories', label: 'Categories' },
  { id: 'modifier-groups', label: 'Modifier groups' },
  { id: 'discounts', label: 'Discounts' },
  { id: 'tax-rates', label: 'Tax rates' },
]

/** Shared list-query idiom for every tab below: react-query v5 dropped `onError`, so a
 * settled query error is watched the same way the register's FloorScreen/ShiftScreens do. */
function useCatalogList<T>(key: string, queryFn: () => Promise<T[]>, onUnauthorized: () => void) {
  const query = useQuery({ queryKey: ['admin', key], queryFn })
  useEffect(() => {
    if (query.error instanceof ApiError && query.error.status === 401) onUnauthorized()
  }, [query.error, onUnauthorized])
  return query
}

/**
 * The UNARCHIVE action (PATCH `is_active: true`) every table with archived rows offers.
 * A real `useMutation` rather than a bare `.then()` chain so a failure — including a
 * 401, same convention as everywhere else — surfaces instead of vanishing silently.
 */
function useUnarchive<T extends { id: string }>(key: string, update: (id: string, body: Record<string, unknown>) => Promise<T>, onUnauthorized: () => void) {
  const queryClient = useQueryClient()
  const [error, setError] = useState<string | null>(null)
  const mutation = useMutation({
    mutationFn: (id: string) => update(id, { is_active: true }),
    onSuccess: () => {
      setError(null)
      queryClient.invalidateQueries({ queryKey: ['admin', key] })
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 401) return onUnauthorized()
      setError(err instanceof ApiError ? err.message : 'Could not unarchive.')
    },
  })
  return { unarchive: (row: T) => mutation.mutate(row.id), error }
}

/**
 * The back-office catalog screens (Task 9): a tab rail over six entity tables, each
 * swapping to its editor in place when a row (or NEW) is picked — one primary surface at
 * a time, same as the register's phase screens. `onUnauthorized` is threaded down from
 * AdminApp so any list query's 401 drops the whole shell back to the login screen.
 */
export function CatalogSection({ onUnauthorized }: { onUnauthorized: () => void }) {
  const [tab, setTab] = useState<Tab>('products')

  return (
    <div className="menu-grid">
      <nav className="menu-rail" aria-label="Catalog tabs">
        {TABS.map((t) => (
          <button
            key={t.id}
            type="button"
            className={`menu-rail-tab${t.id === tab ? ' active' : ''}`}
            aria-pressed={t.id === tab}
            onClick={() => setTab(t.id)}
          >
            {t.label}
          </button>
        ))}
      </nav>

      <div style={{ flex: 1 }}>
        {tab === 'products' && <ProductsPanel onUnauthorized={onUnauthorized} />}
        {tab === 'variants' && <VariantsPanel onUnauthorized={onUnauthorized} />}
        {tab === 'categories' && <CategoriesPanel onUnauthorized={onUnauthorized} />}
        {tab === 'modifier-groups' && <ModifierGroupsPanel onUnauthorized={onUnauthorized} />}
        {tab === 'discounts' && <DiscountsPanel onUnauthorized={onUnauthorized} />}
        {tab === 'tax-rates' && <TaxRatesPanel onUnauthorized={onUnauthorized} />}
      </div>
    </div>
  )
}

function ProductsPanel({ onUnauthorized }: { onUnauthorized: () => void }) {
  const products = useCatalogList('products', api.products.list, onUnauthorized)
  const categories = useCatalogList('categories', api.categories.list, onUnauthorized)
  const modifierGroups = useCatalogList('modifier-groups', api.modifierGroups.list, onUnauthorized)
  const { unarchive, error: unarchiveError } = useUnarchive('products', api.products.update, onUnauthorized)
  const [editing, setEditing] = useState<Product | 'new' | null>(null)

  if (products.isLoading || categories.isLoading || modifierGroups.isLoading) return <p className="muted">Loading…</p>
  if (products.isError) return <p className="error">Could not load products.</p>

  if (editing !== null) {
    return (
      <ProductEditor
        product={editing === 'new' ? null : editing}
        categories={categories.data ?? []}
        modifierGroups={modifierGroups.data ?? []}
        onDone={() => setEditing(null)}
        onCancel={() => setEditing(null)}
        onUnauthorized={onUnauthorized}
      />
    )
  }

  const categoryName = (id: string | null) => categories.data?.find((c) => c.id === id)?.name ?? '—'

  return (
    <>
      <EntityTable<Product>
        title="Products"
        columns={[
          { header: 'Name', render: (p) => p.name },
          { header: 'Category', render: (p) => categoryName(p.category_id) },
          { header: 'Kind', render: (p) => p.kind },
        ]}
        rows={products.data ?? []}
        onEdit={(p) => setEditing(p)}
        onNew={() => setEditing('new')}
        onUnarchive={unarchive}
        emptyMessage="No products yet."
      />
      {unarchiveError && <p className="error">{unarchiveError}</p>}
    </>
  )
}

function VariantsPanel({ onUnauthorized }: { onUnauthorized: () => void }) {
  const variants = useCatalogList('variants', api.variants.list, onUnauthorized)
  const products = useCatalogList('products', api.products.list, onUnauthorized)
  const taxRates = useCatalogList('tax-rates', api.taxRates.list, onUnauthorized)
  const { unarchive, error: unarchiveError } = useUnarchive('variants', api.variants.update, onUnauthorized)
  const [editing, setEditing] = useState<Variant | 'new' | null>(null)

  if (variants.isLoading || products.isLoading || taxRates.isLoading) return <p className="muted">Loading…</p>
  if (variants.isError) return <p className="error">Could not load variants.</p>

  if (editing !== null) {
    return (
      <VariantEditor
        variant={editing === 'new' ? null : editing}
        products={products.data ?? []}
        taxRates={taxRates.data ?? []}
        onDone={() => setEditing(null)}
        onCancel={() => setEditing(null)}
        onUnauthorized={onUnauthorized}
      />
    )
  }

  const productName = (id: string) => products.data?.find((p) => p.id === id)?.name ?? '—'

  return (
    <>
      <EntityTable<Variant>
        title="Variants"
        columns={[
          { header: 'Product', render: (v) => productName(v.product_id) },
          { header: 'Name', render: (v) => v.name },
          { header: 'SKU', render: (v) => v.sku },
          { header: 'Price', render: (v) => `$${(v.price_cents / 100).toFixed(2)}` },
        ]}
        rows={variants.data ?? []}
        onEdit={(v) => setEditing(v)}
        onNew={() => setEditing('new')}
        onUnarchive={unarchive}
        emptyMessage="No variants yet."
      />
      {unarchiveError && <p className="error">{unarchiveError}</p>}
    </>
  )
}

function CategoriesPanel({ onUnauthorized }: { onUnauthorized: () => void }) {
  const categories = useCatalogList('categories', api.categories.list, onUnauthorized)
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState<Category | 'new' | null>(null)
  const [error, setError] = useState<string | null>(null)

  const save = useMutation({
    mutationFn: ({ id, body }: { id: string | null; body: Record<string, unknown> }) =>
      id ? api.categories.update(id, body) : api.categories.create(body),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'categories'] })
      setEditing(null)
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 401) return onUnauthorized()
      setError(err instanceof ApiError ? err.message : 'Could not save the category.')
    },
  })

  if (categories.isLoading) return <p className="muted">Loading…</p>
  if (categories.isError) return <p className="error">Could not load categories.</p>

  if (editing !== null) {
    const current = editing === 'new' ? null : editing
    return (
      <SimpleEditor
        title={current ? 'Edit category' : 'New category'}
        fields={[
          { key: 'name', label: 'Name', kind: 'text' },
          {
            key: 'parent_id',
            label: 'Parent category',
            kind: 'select',
            options: (categories.data ?? []).filter((c) => c.id !== current?.id).map((c) => ({ value: c.id, label: c.name })),
          },
          { key: 'sort_order', label: 'Sort order', kind: 'number' },
        ]}
        initial={current}
        saving={save.isPending}
        error={error}
        onSave={(body) => {
          setError(null)
          save.mutate({ id: current?.id ?? null, body })
        }}
        onCancel={() => {
          setError(null)
          setEditing(null)
        }}
      />
    )
  }

  const parentName = (id: string | null) => categories.data?.find((c) => c.id === id)?.name ?? '—'

  return (
    <EntityTable<Category>
      title="Categories"
      columns={[
        { header: 'Name', render: (c) => c.name },
        { header: 'Parent', render: (c) => parentName(c.parent_id) },
        { header: 'Sort order', render: (c) => String(c.sort_order) },
      ]}
      rows={categories.data ?? []}
      onEdit={(c) => setEditing(c)}
      onNew={() => setEditing('new')}
      emptyMessage="No categories yet."
    />
  )
}

function ModifierGroupsPanel({ onUnauthorized }: { onUnauthorized: () => void }) {
  const groups = useCatalogList('modifier-groups', api.modifierGroups.list, onUnauthorized)
  const modifiers = useCatalogList('modifiers', api.modifiers.list, onUnauthorized)
  const [editing, setEditing] = useState<ModifierGroup | 'new' | null>(null)

  if (groups.isLoading || modifiers.isLoading) return <p className="muted">Loading…</p>
  if (groups.isError) return <p className="error">Could not load modifier groups.</p>

  if (editing !== null) {
    const current = editing === 'new' ? null : editing
    return (
      <ModifierGroupEditor
        group={current}
        modifiers={current ? (modifiers.data ?? []).filter((m) => m.group_id === current.id) : []}
        onDone={() => setEditing(null)}
        onCancel={() => setEditing(null)}
        onUnauthorized={onUnauthorized}
      />
    )
  }

  return (
    <EntityTable<ModifierGroup>
      title="Modifier groups"
      columns={[
        { header: 'Name', render: (g) => g.name },
        { header: 'Min select', render: (g) => String(g.min_select) },
        { header: 'Max select', render: (g) => (g.max_select == null ? 'Unlimited' : String(g.max_select)) },
      ]}
      rows={groups.data ?? []}
      onEdit={(g) => setEditing(g)}
      onNew={() => setEditing('new')}
      emptyMessage="No modifier groups yet."
    />
  )
}

function DiscountsPanel({ onUnauthorized }: { onUnauthorized: () => void }) {
  const discounts = useCatalogList('discounts', api.discounts.list, onUnauthorized)
  const { unarchive, error: unarchiveError } = useUnarchive('discounts', api.discounts.update, onUnauthorized)
  const [editing, setEditing] = useState<Discount | 'new' | null>(null)

  if (discounts.isLoading) return <p className="muted">Loading…</p>
  if (discounts.isError) return <p className="error">Could not load discounts.</p>

  if (editing !== null) {
    return (
      <DiscountEditor
        discount={editing === 'new' ? null : editing}
        onDone={() => setEditing(null)}
        onCancel={() => setEditing(null)}
        onUnauthorized={onUnauthorized}
      />
    )
  }

  const valueLabel = (d: Discount) =>
    d.kind === 'percent' ? `${((d.percent_micros ?? 0) / 10_000).toFixed(2)}%` : `$${((d.amount_cents ?? 0) / 100).toFixed(2)}`

  return (
    <>
      <EntityTable<Discount>
        title="Discounts"
        columns={[
          { header: 'Name', render: (d) => d.name },
          { header: 'Kind', render: (d) => d.kind },
          { header: 'Value', render: (d) => valueLabel(d) },
          { header: 'Scope', render: (d) => d.scope },
        ]}
        rows={discounts.data ?? []}
        onEdit={(d) => setEditing(d)}
        onNew={() => setEditing('new')}
        onUnarchive={unarchive}
        emptyMessage="No discounts yet."
      />
      {unarchiveError && <p className="error">{unarchiveError}</p>}
    </>
  )
}

function TaxRatesPanel({ onUnauthorized }: { onUnauthorized: () => void }) {
  const taxRates = useCatalogList('tax-rates', api.taxRates.list, onUnauthorized)
  const { unarchive, error: unarchiveError } = useUnarchive('tax-rates', api.taxRates.update, onUnauthorized)
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState<TaxRate | 'new' | null>(null)
  const [error, setError] = useState<string | null>(null)

  const save = useMutation({
    mutationFn: ({ id, body }: { id: string | null; body: Record<string, unknown> }) =>
      id ? api.taxRates.update(id, body) : api.taxRates.create(body),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'tax-rates'] })
      setEditing(null)
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 401) return onUnauthorized()
      setError(err instanceof ApiError ? err.message : 'Could not save the tax rate.')
    },
  })

  if (taxRates.isLoading) return <p className="muted">Loading…</p>
  if (taxRates.isError) return <p className="error">Could not load tax rates.</p>

  if (editing !== null) {
    const current = editing === 'new' ? null : editing
    return (
      <SimpleEditor
        title={current ? 'Edit tax rate' : 'New tax rate'}
        fields={[
          { key: 'name', label: 'Name', kind: 'text' },
          { key: 'rate_micros', label: 'Rate (%)', kind: 'percent' },
          { key: 'is_active', label: 'Active', kind: 'checkbox' },
        ]}
        initial={current}
        saving={save.isPending}
        error={error}
        onSave={(body) => {
          setError(null)
          save.mutate({ id: current?.id ?? null, body })
        }}
        onCancel={() => {
          setError(null)
          setEditing(null)
        }}
      />
    )
  }

  return (
    <>
      <EntityTable<TaxRate>
        title="Tax rates"
        columns={[
          { header: 'Name', render: (t) => t.name },
          { header: 'Rate', render: (t) => `${(t.rate_micros / 10_000).toFixed(2)}%` },
        ]}
        rows={taxRates.data ?? []}
        onEdit={(t) => setEditing(t)}
        onNew={() => setEditing('new')}
        onUnarchive={unarchive}
        emptyMessage="No tax rates yet."
      />
      {unarchiveError && <p className="error">{unarchiveError}</p>}
    </>
  )
}
