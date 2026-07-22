'use client'

import { useMutation } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { ApiError, api, type Order, type Refund } from '../lib/api'
import { getCurrency } from '../lib/currency'
import { cents, formatMoney } from '../lib/money'
import { MoneyText } from '@/components/MoneyText'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'

const fm = (n: number) => formatMoney(cents(n), getCurrency())

type LinePick = { qty: string; restock: boolean }

export function RefundScreen({ onDone, onSessionExpired }: { onDone: () => void; onSessionExpired: (err?: unknown) => void }) {
  const [number, setNumber] = useState('')
  const [order, setOrder] = useState<Order | null>(null)
  const [picks, setPicks] = useState<Record<string, LinePick>>({})
  const [reason, setReason] = useState('')
  const [result, setResult] = useState<Refund | null>(null)
  const [error, setError] = useState<string | null>(null)
  // Minted once for the life of this screen: a re-submit after a lost response replays
  // the SAME refund instead of paying it twice.
  const [idempotencyKey] = useState(() => crypto.randomUUID())

  const fail = (err: unknown, fallback: string) => {
    if (err instanceof ApiError && err.status === 401) return onSessionExpired(err)
    setError(err instanceof ApiError ? err.message : fallback)
  }

  const lookup = useMutation({
    mutationFn: (receiptNumber: string) => api.findOrders({ number: receiptNumber }),
    onSuccess: (orders) => {
      const found = orders[0]
      if (!found) return setError('No order with that receipt number at this store.')
      if (found.status !== 'closed') return setError(`Order ${found.number} is ${found.status} — only closed orders can be refunded.`)
      setOrder(found)
      setPicks({})
      setError(null)
    },
    onError: (err) => fail(err, 'Lookup failed.'),
  })

  const refund = useMutation({
    mutationFn: () => {
      const lines = Object.entries(picks)
        .filter(([, p]) => p.qty !== '' && p.qty !== '0')
        .map(([lineId, p]) => ({ original_order_line_id: lineId, qty: p.qty, restock: p.restock }))
      return api.refund((order as Order).id, 'cash', reason.trim(), lines, idempotencyKey)
    },
    onSuccess: (r) => {
      setResult(r)
      setError(null)
    },
    onError: (err) => fail(err, 'Refund failed.'),
  })

  const submitLookup = (e: FormEvent) => {
    e.preventDefault()
    if (!number.trim() || lookup.isPending) return
    setError(null)
    lookup.mutate(number.trim())
  }

  const submitRefund = (e: FormEvent) => {
    e.preventDefault()
    if (refund.isPending) return
    const chosen = Object.values(picks).some((p) => p.qty !== '' && p.qty !== '0')
    if (!chosen) return setError('Pick at least one line quantity to refund.')
    if (!reason.trim()) return setError('A refund needs a reason.')
    setError(null)
    refund.mutate()
  }

  if (result) {
    return (
      <section className="flex max-w-[640px] flex-col gap-lg">
        <h2 className="type-headline">Refund complete</h2>
        {/* Same hero plate as SaleScreen's change-due panel; `hero-amount` stays for
            print parity (print.css pins it to 24px). */}
        <div className="flex flex-col items-center gap-xs border border-hairline bg-surface-1 px-lg py-xl print:border-0 print:bg-transparent">
          <p className="type-caption text-ink-muted">Refund — cash from the drawer</p>
          <MoneyText cents={result.amount_cents} currency={getCurrency()} size="total" className="hero-amount" />
        </div>
        <p className="type-body-sm text-ink-muted">Order {order?.number} · {result.lines.length} line{result.lines.length === 1 ? '' : 's'} · {result.reason}</p>
        <div>
          <Button size="lg" onClick={onDone}>Back to register</Button>
        </div>
      </section>
    )
  }

  const refundableLines = (order?.lines ?? []).filter((l) => !l.voided_at)

  return (
    <section className="flex max-w-[640px] flex-col gap-lg">
      <header className="flex items-center justify-between gap-md">
        <h2 className="type-headline">Refund a sale</h2>
        <Button type="button" variant="secondary" className="min-h-[48px]" onClick={onDone}>Back</Button>
      </header>

      <form onSubmit={submitLookup} className="flex flex-col gap-md">
        <label className="block">
          <span className="type-body-sm text-ink-muted">Receipt number</span>
          <Input
            autoFocus placeholder="DT-20260716-0001"
            value={number} onChange={(e) => setNumber(e.target.value)}
            className="mt-xs h-[56px]"
          />
        </label>
        <div>
          <Button type="submit" variant="tertiary" size="lg" disabled={lookup.isPending}>
            {lookup.isPending ? 'Finding…' : 'Find order'}
          </Button>
        </div>
      </form>

      {order && (
        <form onSubmit={submitRefund} className="flex flex-col gap-md border-t border-hairline pt-lg">
          <p className="type-body-sm text-ink-muted">Order {order.number} — {fm(order.total_cents)} paid</p>
          <div className="border border-hairline">
            {refundableLines.map((l) => {
              const pick = picks[l.id] ?? { qty: '', restock: true }
              return (
                <div className="flex min-h-[56px] items-center gap-sm border-b border-hairline px-md py-sm last:border-b-0" key={l.id}>
                  <span className="type-body-lg min-w-0 flex-1">{l.name}</span>
                  <span className="type-body-sm shrink-0 text-ink-muted">of {l.qty}</span>
                  <Input
                    inputMode="decimal" placeholder="0"
                    aria-label={`Quantity of ${l.name} to refund`}
                    value={pick.qty}
                    onChange={(e) => setPicks({ ...picks, [l.id]: { ...pick, qty: e.target.value } })}
                    className="type-money min-h-[48px] w-[72px] shrink-0 text-right"
                  />
                  <span className="flex min-h-[48px] shrink-0 items-center gap-xs px-xs">
                    <Checkbox
                      id={`restock-${l.id}`} checked={pick.restock}
                      onCheckedChange={(checked) => setPicks({ ...picks, [l.id]: { ...pick, restock: checked === true } })}
                    />
                    {/* The label is the hit area — clicks anywhere on it toggle the checkbox. */}
                    <label htmlFor={`restock-${l.id}`} className="type-body-sm cursor-pointer">Restock</label>
                  </span>
                </div>
              )
            })}
          </div>
          <label className="block">
            <span className="type-body-sm text-ink-muted">Reason</span>
            <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Faulty" className="mt-xs min-h-[48px]" />
          </label>
          <div>
            <Button type="submit" size="lg" disabled={refund.isPending}>
              {refund.isPending ? 'Refunding…' : 'Refund cash'}
            </Button>
          </div>
        </form>
      )}

      {error && <p className="type-body-sm text-error">{error}</p>}
    </section>
  )
}
