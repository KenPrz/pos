'use client'

import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { ApiError, api, type Discount } from '../../lib/api'
import { parseCentsOrNull } from '../../lib/money'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { FieldRow } from '../../components/FieldRow'
import { Button } from '../../components/ui/button'
import { Card, CardTitle } from '../../components/ui/card'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select'
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
  // Archive behind a confirm (brief's global constraint) — set only when Save would
  // otherwise archive; the dialog's Confirm re-plays the exact body already computed.
  const [pendingArchive, setPendingArchive] = useState<Record<string, unknown> | null>(null)

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
    if (body.is_active === false) {
      setPendingArchive(body)
      return
    }
    save.mutate(body)
  }

  return (
    <Card>
      <div className="mb-lg flex items-center justify-between gap-md">
        <CardTitle>{discount ? 'Edit discount' : 'New discount'}</CardTitle>
        <Button type="button" variant="tertiary" onClick={onCancel}>
          Back
        </Button>
      </div>

      <form onSubmit={submit} className="flex flex-col gap-md">
        <FieldRow label="Name">
          <Input value={name} onChange={(e) => setName(e.target.value)} />
        </FieldRow>
        <FieldRow label="Kind">
          <Select value={kind} onValueChange={(v) => setKind(v as 'percent' | 'fixed')}>
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="percent">Percent</SelectItem>
              <SelectItem value="fixed">Fixed amount</SelectItem>
            </SelectContent>
          </Select>
        </FieldRow>
        {kind === 'percent' ? (
          <FieldRow label="Percent">
            <Input inputMode="decimal" placeholder="8.25" value={percentInput} onChange={(e) => setPercentInput(e.target.value)} />
          </FieldRow>
        ) : (
          <MoneyField id="discount-amount" label="Amount" value={amountInput} onChange={setAmountInput} />
        )}
        <FieldRow label="Scope">
          <Select value={scope} onValueChange={(v) => setScope(v as 'order' | 'line')}>
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="order">Order</SelectItem>
              <SelectItem value="line">Line</SelectItem>
            </SelectContent>
          </Select>
        </FieldRow>
        <FieldRow label="Requires supervisor">
          <Checkbox checked={requiresSupervisor} onCheckedChange={(checked) => setRequiresSupervisor(Boolean(checked))} />
        </FieldRow>
        {discount && (
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
