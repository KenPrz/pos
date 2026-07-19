'use client'

import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { ApiError, api, type Category, type ModifierGroup, type Product } from '../../lib/api'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { Divider } from '../../components/Divider'
import { FieldRow } from '../../components/FieldRow'
import { Badge } from '../../components/ui/badge'
import { Button } from '../../components/ui/button'
import { Card, CardTitle } from '../../components/ui/card'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select'

// Radix `Select.Item` rejects an empty-string value — see SimpleEditor's identical
// sentinel. Category is the one optional select here (a product needn't have one).
const NONE_CATEGORY = '__none__'

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
  // Archive behind a confirm (brief's global constraint) — set only when Save would
  // otherwise archive; the dialog's Confirm re-plays the exact body already computed.
  const [pendingArchive, setPendingArchive] = useState<Record<string, unknown> | null>(null)

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

    // Archive behind a confirm (brief's global constraint) — unchecking Active and
    // hitting Save must not silently archive. UNARCHIVE (the table action) needs none.
    if (body.is_active === false) {
      setPendingArchive(body)
      return
    }
    save.mutate(body)
  }

  const toggleGroup = (groupId: string) => {
    setAttachedIds((ids) => (ids.includes(groupId) ? ids.filter((id) => id !== groupId) : [...ids, groupId]))
  }

  return (
    <Card>
      <div className="mb-lg flex items-center justify-between gap-md">
        <CardTitle>{product ? 'Edit product' : 'New product'}</CardTitle>
        <Button type="button" variant="tertiary" onClick={onCancel}>
          Back
        </Button>
      </div>

      <form onSubmit={submit} className="flex flex-col gap-md">
        <FieldRow label="Name">
          <Input value={name} onChange={(e) => setName(e.target.value)} />
        </FieldRow>
        <FieldRow label="Description">
          <Input value={description} onChange={(e) => setDescription(e.target.value)} />
        </FieldRow>
        <FieldRow label="Category">
          <Select
            value={categoryId || NONE_CATEGORY}
            onValueChange={(v) => setCategoryId(v === NONE_CATEGORY ? '' : v)}
          >
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={NONE_CATEGORY}>—</SelectItem>
              {categories.map((c) => (
                <SelectItem key={c.id} value={c.id}>
                  {c.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </FieldRow>
        <FieldRow label="Kind">
          <Select value={kind} onValueChange={(v) => setKind(v as 'goods' | 'service')}>
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="goods">Goods</SelectItem>
              <SelectItem value="service">Service</SelectItem>
            </SelectContent>
          </Select>
        </FieldRow>
        {product && (
          <FieldRow label="Active">
            <Checkbox checked={isActive} onCheckedChange={(checked) => setIsActive(Boolean(checked))} />
          </FieldRow>
        )}
        <div>
          <Button type="submit" variant="primary" disabled={save.isPending}>
            {save.isPending ? 'Saving…' : 'Save'}
          </Button>
        </div>
      </form>

      <Divider />

      <CardTitle className="mb-md">Modifier groups</CardTitle>
      {product === null ? (
        <p className="type-body-sm text-ink-muted">Save the product first to attach modifier groups.</p>
      ) : modifierGroups.length === 0 ? (
        <p className="type-body-sm text-ink-muted">No modifier groups defined yet.</p>
      ) : (
        <>
          <div className="mb-md flex flex-wrap gap-xs">
            {modifierGroups.map((g) => {
              const position = attachedIds.indexOf(g.id)
              const selected = position >= 0
              return (
                <label
                  key={g.id}
                  className={`type-body-sm flex items-center gap-xs border px-sm py-xs ${
                    selected ? 'border-primary bg-surface-1' : 'border-hairline'
                  }`}
                >
                  <Checkbox checked={selected} onCheckedChange={() => toggleGroup(g.id)} />
                  {g.name}
                  {selected && <Badge variant="info">#{position + 1}</Badge>}
                </label>
              )
            })}
          </div>
          <Button type="button" variant="tertiary" disabled={attach.isPending} onClick={() => attach.mutate(attachedIds)}>
            {attach.isPending ? 'Saving…' : 'Save modifier groups'}
          </Button>
        </>
      )}

      {error && <p className="type-body-sm mt-md text-error">{error}</p>}

      <ConfirmDialog
        open={pendingArchive !== null}
        onOpenChange={(open) => {
          if (!open) setPendingArchive(null)
        }}
        message={`Archive ${name}? It leaves the register catalog but stays in history.`}
        confirmLabel="Archive"
        destructive
        onConfirm={() => {
          if (!pendingArchive) return
          save.mutate(pendingArchive)
          setPendingArchive(null)
        }}
      />
    </Card>
  )
}
