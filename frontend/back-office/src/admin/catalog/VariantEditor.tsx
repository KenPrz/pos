'use client'

import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { ApiError, api, type Product, type TaxRate, type Variant } from '../../lib/api'
import { parseCentsOrNull } from '../../lib/money'
import { MoneyField } from './MoneyField'

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

    save.mutate(body)
  }

  return (
    <section className="form-panel">
      <header className="row">
        <h2>{variant ? 'Edit variant' : 'New variant'}</h2>
        <button type="button" className="btn btn-secondary" onClick={onCancel}>
          Back
        </button>
      </header>

      <form onSubmit={submit}>
        {variant === null && (
          <label htmlFor="variant-product">
            Product
            <select id="variant-product" value={productId} onChange={(e) => setProductId(e.target.value)}>
              {products.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </label>
        )}
        <label htmlFor="variant-name">
          Name
          <input id="variant-name" value={name} onChange={(e) => setName(e.target.value)} />
        </label>
        <label htmlFor="variant-sku">
          SKU
          <input id="variant-sku" value={sku} onChange={(e) => setSku(e.target.value)} />
        </label>
        <label htmlFor="variant-barcode">
          Barcode
          <input id="variant-barcode" value={barcode} onChange={(e) => setBarcode(e.target.value)} />
        </label>
        <MoneyField id="variant-price" label="Price" value={priceInput} onChange={setPriceInput} invalid={priceInvalid} />
        <MoneyField id="variant-cost" label="Cost (optional)" value={costInput} onChange={setCostInput} invalid={costInvalid} />
        <label htmlFor="variant-tax-rate">
          Tax rate
          <select id="variant-tax-rate" value={taxRateId} onChange={(e) => setTaxRateId(e.target.value)}>
            <option value="">None</option>
            {taxRates.map((t) => (
              <option key={t.id} value={t.id}>
                {t.name}
              </option>
            ))}
          </select>
        </label>
        <label htmlFor="variant-track-inventory">
          Track inventory
          <input
            id="variant-track-inventory"
            type="checkbox"
            checked={trackInventory}
            onChange={(e) => setTrackInventory(e.target.checked)}
          />
        </label>
        {variant && (
          <label htmlFor="variant-active">
            Active
            <input id="variant-active" type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
          </label>
        )}
        <button type="submit" className="btn btn-submit" disabled={save.isPending}>
          {save.isPending ? 'Saving…' : 'Save'}
        </button>
      </form>
      {error && <p className="error">{error}</p>}
    </section>
  )
}
