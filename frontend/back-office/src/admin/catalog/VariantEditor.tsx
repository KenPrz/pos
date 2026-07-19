'use client'

import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { ApiError, api, type Product, type TaxRate, type Variant } from '../../lib/api'
import { parseCentsOrNull } from '../../lib/money'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { FieldRow } from '../../components/FieldRow'
import { Button } from '../../components/ui/button'
import { Card, CardTitle } from '../../components/ui/card'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select'
import { MoneyField } from './MoneyField'

// Radix `Select.Item` rejects an empty-string value — see SimpleEditor's identical
// sentinel. Tax rate is the one optional select here ("None" is a real choice).
const NONE_TAX_RATE = '__none__'

/**
 * SKU/barcode/price/track_inventory/tax-rate, per the brief. `product_id` is only
 * choosable at create time — UpdateVariantRequest marks it `prohibited` on PATCH (which
 * product a variant belongs to is a create-time decision), so the field is simply not
 * shown once `variant` is non-null.
 */
export function VariantEditor({
  variant,
  products,
  taxRates,
  onDone,
  onCancel,
  onUnauthorized,
}: {
  variant: Variant | null
  products: Product[]
  taxRates: TaxRate[]
  onDone: () => void
  onCancel: () => void
  onUnauthorized: () => void
}) {
  const queryClient = useQueryClient()
  const [productId, setProductId] = useState(variant?.product_id ?? products[0]?.id ?? '')
  const [name, setName] = useState(variant?.name ?? '')
  const [sku, setSku] = useState(variant?.sku ?? '')
  const [barcode, setBarcode] = useState(variant?.barcode ?? '')
  const [priceInput, setPriceInput] = useState(variant ? (variant.price_cents / 100).toFixed(2) : '')
  const [costInput, setCostInput] = useState(variant?.cost_cents != null ? (variant.cost_cents / 100).toFixed(2) : '')
  const [taxRateId, setTaxRateId] = useState(variant?.tax_rate_id ?? '')
  const [trackInventory, setTrackInventory] = useState(variant?.track_inventory ?? true)
  const [isActive, setIsActive] = useState(variant?.is_active ?? true)
  const [error, setError] = useState<string | null>(null)
  // Archive behind a confirm (brief's global constraint) — set only when Save would
  // otherwise archive; the dialog's Confirm re-plays the exact body already computed.
  const [pendingArchive, setPendingArchive] = useState<Record<string, unknown> | null>(null)

  const save = useMutation({
    mutationFn: (body: Record<string, unknown>) =>
      variant ? api.variants.update(variant.id, body) : api.variants.create(body),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'variants'] })
      onDone()
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 401) return onUnauthorized()
      setError(err instanceof ApiError ? err.message : 'Could not save the variant.')
    },
  })

  // Blank must fail validation, not silently save as $0 — price_cents is required (and
  // never nullable) on the wire, so an empty field has to block submission rather than
  // coerce to a value the staff member never typed.
  const priceCents = parseCentsOrNull(priceInput)
  const costCents = costInput === '' ? null : parseCentsOrNull(costInput)
  const priceInvalid = priceCents === null
  const costInvalid = costInput !== '' && costCents === null

  const submit = (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    if (priceInvalid || costInvalid) {
      setError('Enter a valid price (e.g. 4.25).')
      return
    }

    const body: Record<string, unknown> = {}
    const put = (key: string, value: unknown, original: unknown) => {
      if (variant === null || value !== original) body[key] = value
    }
    if (variant === null) body.product_id = productId
    put('name', name, variant?.name)
    put('sku', sku, variant?.sku)
    put('barcode', barcode || null, variant?.barcode)
    put('price_cents', priceCents, variant?.price_cents)
    put('cost_cents', costCents, variant?.cost_cents)
    put('tax_rate_id', taxRateId || null, variant?.tax_rate_id)
    put('track_inventory', trackInventory, variant?.track_inventory)
    if (variant) put('is_active', isActive, variant.is_active)

    // Archive behind a confirm (brief's global constraint) — unchecking Active and
    // hitting Save must not silently archive. UNARCHIVE (the table action) needs none.
    if (body.is_active === false) {
      setPendingArchive(body)
      return
    }
    save.mutate(body)
  }

  return (
    <Card>
      <div className="mb-lg flex items-center justify-between gap-md">
        <CardTitle>{variant ? 'Edit variant' : 'New variant'}</CardTitle>
        <Button type="button" variant="tertiary" onClick={onCancel}>
          Back
        </Button>
      </div>

      <form onSubmit={submit} className="flex flex-col gap-md">
        {variant === null && (
          <FieldRow label="Product">
            <Select value={productId} onValueChange={setProductId}>
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {products.map((p) => (
                  <SelectItem key={p.id} value={p.id}>
                    {p.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </FieldRow>
        )}
        <FieldRow label="Name">
          <Input value={name} onChange={(e) => setName(e.target.value)} />
        </FieldRow>
        <FieldRow label="SKU">
          <Input value={sku} onChange={(e) => setSku(e.target.value)} />
        </FieldRow>
        <FieldRow label="Barcode">
          <Input value={barcode} onChange={(e) => setBarcode(e.target.value)} />
        </FieldRow>
        <MoneyField id="variant-price" label="Price" value={priceInput} onChange={setPriceInput} invalid={priceInvalid} />
        <MoneyField id="variant-cost" label="Cost (optional)" value={costInput} onChange={setCostInput} invalid={costInvalid} />
        <FieldRow label="Tax rate">
          <Select value={taxRateId || NONE_TAX_RATE} onValueChange={(v) => setTaxRateId(v === NONE_TAX_RATE ? '' : v)}>
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={NONE_TAX_RATE}>None</SelectItem>
              {taxRates.map((t) => (
                <SelectItem key={t.id} value={t.id}>
                  {t.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </FieldRow>
        <FieldRow label="Track inventory">
          <Checkbox checked={trackInventory} onCheckedChange={(checked) => setTrackInventory(Boolean(checked))} />
        </FieldRow>
        {variant && (
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
