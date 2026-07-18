'use client'

import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { ApiError, api, type Category, type ModifierGroup, type Product } from '../../lib/api'

/**
 * Product fields plus the modifier-group attach list. Two independent actions, two
 * independent mutations: editing name/description/category/kind/active is a PATCH on
 * `products/{id}` (changed keys only); attaching modifier groups is a full-set PUT on
 * `products/{id}/modifier-groups` (ordered ids, brief's "ordered checkboxes"). Neither
 * touches the other's endpoint, so a staff member can fix a typo in the name without
 * accidentally re-sending (and thus reordering) the attach list, and vice versa.
 *
 * The attach checkboxes seed from `product.modifier_group_ids` (Task 9 gap fix —
 * AdminProductResource now always carries this, ordered, on every product response
 * including the plain list), so opening an existing product's editor shows exactly
 * what's attached today rather than starting blank and risking a silent full-set wipe
 * on save.
 */
export function ProductEditor({
  product,
  categories,
  modifierGroups,
  onDone,
  onCancel,
  onUnauthorized,
}: {
  product: Product | null
  categories: Category[]
  modifierGroups: ModifierGroup[]
  onDone: () => void
  onCancel: () => void
  onUnauthorized: () => void
}) {
  const queryClient = useQueryClient()
  const [name, setName] = useState(product?.name ?? '')
  const [description, setDescription] = useState(product?.description ?? '')
  const [categoryId, setCategoryId] = useState(product?.category_id ?? '')
  const [kind, setKind] = useState<'goods' | 'service'>(product?.kind ?? 'goods')
  const [isActive, setIsActive] = useState(product?.is_active ?? true)
  const [attachedIds, setAttachedIds] = useState<string[]>(product?.modifier_group_ids ?? [])
  const [error, setError] = useState<string | null>(null)

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['admin', 'products'] })

  // A 401 on any mutation drops the whole shell back to login (AdminApp's shared
  // convention) instead of just showing a message the retry can never get past.
  const fail = (err: unknown, fallback: string) => {
    if (err instanceof ApiError && err.status === 401) return onUnauthorized()
    setError(err instanceof ApiError ? err.message : fallback)
  }

  const save = useMutation({
    mutationFn: (body: Record<string, unknown>) =>
      product ? api.products.update(product.id, body) : api.products.create(body),
    onSuccess: () => {
      invalidate()
      onDone()
    },
    onError: (err) => fail(err, 'Could not save the product.'),
  })

  const attach = useMutation({
    mutationFn: (groupIds: string[]) => api.setProductModifierGroups(product?.id ?? '', groupIds),
    onSuccess: () => invalidate(),
    onError: (err) => fail(err, 'Could not update modifier groups.'),
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    const body: Record<string, unknown> = {}
    const put = (key: string, value: unknown, original: unknown) => {
      if (product === null || value !== original) body[key] = value
    }
    put('name', name, product?.name)
    put('description', description || null, product?.description)
    put('category_id', categoryId || null, product?.category_id)
    put('kind', kind, product?.kind)
    if (product) put('is_active', isActive, product.is_active)
    save.mutate(body)
  }

  const toggleGroup = (groupId: string) => {
    setAttachedIds((ids) => (ids.includes(groupId) ? ids.filter((id) => id !== groupId) : [...ids, groupId]))
  }

  return (
    <section className="form-panel">
      <header className="row">
        <h2>{product ? 'Edit product' : 'New product'}</h2>
        <button type="button" className="btn btn-secondary" onClick={onCancel}>
          Back
        </button>
      </header>

      <form onSubmit={submit}>
        <label htmlFor="product-name">
          Name
          <input id="product-name" value={name} onChange={(e) => setName(e.target.value)} />
        </label>
        <label htmlFor="product-description">
          Description
          <input id="product-description" value={description} onChange={(e) => setDescription(e.target.value)} />
        </label>
        <label htmlFor="product-category">
          Category
          <select id="product-category" value={categoryId} onChange={(e) => setCategoryId(e.target.value)}>
            <option value="">—</option>
            {categories.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
        </label>
        <label htmlFor="product-kind">
          Kind
          <select id="product-kind" value={kind} onChange={(e) => setKind(e.target.value as 'goods' | 'service')}>
            <option value="goods">Goods</option>
            <option value="service">Service</option>
          </select>
        </label>
        {product && (
          <label htmlFor="product-active">
            Active
            <input id="product-active" type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
          </label>
        )}
        <button type="submit" className="btn btn-submit" disabled={save.isPending}>
          {save.isPending ? 'Saving…' : 'Save'}
        </button>
      </form>

      <hr className="dotted-divider" />

      <h3>Modifier groups</h3>
      {product === null ? (
        <p className="muted">Save the product first to attach modifier groups.</p>
      ) : modifierGroups.length === 0 ? (
        <p className="muted">No modifier groups defined yet.</p>
      ) : (
        <>
          <div className="modifier-chips">
            {modifierGroups.map((g) => {
              const position = attachedIds.indexOf(g.id)
              return (
                <label key={g.id} className={`chip${position >= 0 ? ' selected' : ''}`}>
                  <input
                    type="checkbox"
                    checked={position >= 0}
                    onChange={() => toggleGroup(g.id)}
                    style={{ minHeight: 0, width: 14, height: 14 }}
                  />
                  {g.name}
                  {position >= 0 && <span className="chip-count"> #{position + 1}</span>}
                </label>
              )
            })}
          </div>
          <button
            type="button"
            className="btn btn-utility"
            disabled={attach.isPending}
            onClick={() => attach.mutate(attachedIds)}
          >
            {attach.isPending ? 'Saving…' : 'Save modifier groups'}
          </button>
        </>
      )}

      {error && <p className="error">{error}</p>}
    </section>
  )
}
