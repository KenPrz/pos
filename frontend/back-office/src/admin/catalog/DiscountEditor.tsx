'use client'

import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { ApiError, api, type Discount } from '../../lib/api'
import { parseCentsOrNull } from '../../lib/money'
import { MoneyField } from './MoneyField'

/** '' → null; otherwise a percent typed by a human (e.g. "8.25") to micros. */
function percentToMicros(input: string): number | null {
  if (input.trim() === '') return null
  const n = Number(input)
  return Number.isFinite(n) ? Math.round(n * 10_000) : null
}

/**
 * `kind` decides which of `percent_micros` / `amount_cents` is live — mirrors the
 * `discounts_kind_matches_value` DB CHECK that CreateDiscountRequest/UpdateDiscountRequest
 * enforce with a 400: percent discounts carry percent_micros and null amount_cents, fixed
 * is the inverse. Submitting always sends both keys with their kind-appropriate value (one
 * of them null), so flipping `kind` correctly clears whichever field no longer applies.
 */
export function DiscountEditor({
  discount,
  onDone,
  onCancel,
  onUnauthorized,
}: {
  discount: Discount | null
  onDone: () => void
  onCancel: () => void
  onUnauthorized: () => void
}) {
  const queryClient = useQueryClient()
  const [name, setName] = useState(discount?.name ?? '')
  const [kind, setKind] = useState<'percent' | 'fixed'>(discount?.kind ?? 'percent')
  const [percentInput, setPercentInput] = useState(
    discount?.percent_micros != null ? String(discount.percent_micros / 10_000) : '',
  )
  const [amountInput, setAmountInput] = useState(discount?.amount_cents != null ? (discount.amount_cents / 100).toFixed(2) : '')
  const [scope, setScope] = useState<'order' | 'line'>(discount?.scope ?? 'order')
  const [requiresSupervisor, setRequiresSupervisor] = useState(discount?.requires_supervisor ?? true)
  const [isActive, setIsActive] = useState(discount?.is_active ?? true)
  const [error, setError] = useState<string | null>(null)

  const save = useMutation({
    mutationFn: (body: Record<string, unknown>) =>
      discount ? api.discounts.update(discount.id, body) : api.discounts.create(body),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'discounts'] })
      onDone()
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 401) return onUnauthorized()
      setError(err instanceof ApiError ? err.message : 'Could not save the discount.')
    },
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    setError(null)

    // Blank must fail validation rather than silently save as $0/0% — both fields are
    // required for their respective kind server-side (discounts_kind_matches_value).
    const finalPercent = kind === 'percent' ? percentToMicros(percentInput) : null
    const finalAmount = kind === 'fixed' ? parseCentsOrNull(amountInput) : null
    if (kind === 'percent' && finalPercent === null) {
      setError('Enter a valid percent (e.g. 8.25).')
      return
    }
    if (kind === 'fixed' && finalAmount === null) {
      setError('Enter a valid amount (e.g. 4.25).')
      return
    }

    const body: Record<string, unknown> = {}
    const put = (key: string, value: unknown, original: unknown) => {
      if (discount === null || value !== original) body[key] = value
    }
    put('name', name, discount?.name)
    put('kind', kind, discount?.kind)
    put('percent_micros', finalPercent, discount?.percent_micros)
    put('amount_cents', finalAmount, discount?.amount_cents)
    put('scope', scope, discount?.scope)
    put('requires_supervisor', requiresSupervisor, discount?.requires_supervisor)
    if (discount) put('is_active', isActive, discount.is_active)

    // Archive behind a confirm (brief's global constraint) — unchecking Active and
    // hitting Save must not silently archive. UNARCHIVE (the table action) needs none.
    if (body.is_active === false && !window.confirm(`Archive ${name}? It leaves the register catalog but stays in history.`)) {
      return
    }
    save.mutate(body)
  }

  return (
    <section className="form-panel">
      <header className="row">
        <h2>{discount ? 'Edit discount' : 'New discount'}</h2>
        <button type="button" className="btn btn-secondary" onClick={onCancel}>
          Back
        </button>
      </header>

      <form onSubmit={submit}>
        <label htmlFor="discount-name">
          Name
          <input id="discount-name" value={name} onChange={(e) => setName(e.target.value)} />
        </label>
        <label htmlFor="discount-kind">
          Kind
          <select id="discount-kind" value={kind} onChange={(e) => setKind(e.target.value as 'percent' | 'fixed')}>
            <option value="percent">Percent</option>
            <option value="fixed">Fixed amount</option>
          </select>
        </label>
        {kind === 'percent' ? (
          <label htmlFor="discount-percent">
            Percent
            <input id="discount-percent" inputMode="decimal" placeholder="8.25" value={percentInput} onChange={(e) => setPercentInput(e.target.value)} />
          </label>
        ) : (
          <MoneyField id="discount-amount" label="Amount" value={amountInput} onChange={setAmountInput} />
        )}
        <label htmlFor="discount-scope">
          Scope
          <select id="discount-scope" value={scope} onChange={(e) => setScope(e.target.value as 'order' | 'line')}>
            <option value="order">Order</option>
            <option value="line">Line</option>
          </select>
        </label>
        <label htmlFor="discount-requires-supervisor">
          Requires supervisor
          <input
            id="discount-requires-supervisor"
            type="checkbox"
            checked={requiresSupervisor}
            onChange={(e) => setRequiresSupervisor(e.target.checked)}
          />
        </label>
        {discount && (
          <label htmlFor="discount-active">
            Active
            <input id="discount-active" type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
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
