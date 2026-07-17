'use client'

import { useMutation } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { ApiError, api, type Order, type Refund } from '../lib/api'
import { cents, formatMoney } from '../lib/money'

const CURRENCY = 'USD'
const fm = (n: number) => formatMoney(cents(n), CURRENCY)

type LinePick = { qty: string; restock: boolean }

export function RefundScreen({ onDone, onSessionExpired }: { onDone: () => void; onSessionExpired: () => void }) {
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
    if (err instanceof ApiError && err.status === 401) return onSessionExpired()
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
      <section className="form-panel ok">
        <h2>Refund complete</h2>
        <div className="hero-panel">
          <p className="hero-eyebrow">Refund — cash from the drawer</p>
          <p className="hero-amount">{fm(result.amount_cents)}</p>
        </div>
        <p className="muted">Order {order?.number} · {result.lines.length} line{result.lines.length === 1 ? '' : 's'} · {result.reason}</p>
        <button className="btn btn-submit" onClick={onDone}>Back to register</button>
      </section>
    )
  }

  const refundableLines = (order?.lines ?? []).filter((l) => !l.voided_at)

  return (
    <section className="form-panel">
      <header className="row">
        <h2>Refund a sale</h2>
        <button type="button" className="btn btn-secondary" onClick={onDone}>Back</button>
      </header>

      <form onSubmit={submitLookup}>
        <label>
          Receipt number
          <input
            autoFocus placeholder="DT-20260716-0001"
            value={number} onChange={(e) => setNumber(e.target.value)}
          />
        </label>
        <button type="submit" className="btn btn-utility" disabled={lookup.isPending}>
          {lookup.isPending ? 'Finding…' : 'Find order'}
        </button>
      </form>

      {order && (
        <form onSubmit={submitRefund}>
          <hr className="dotted-divider" />
          <p className="picker-label">Order {order.number} — {fm(order.total_cents)} paid</p>
          <div className="cart">
            {refundableLines.map((l) => {
              const pick = picks[l.id] ?? { qty: '', restock: true }
              return (
                <div className="cart-row refund-row" key={l.id}>
                  <span className="cart-row-name">{l.name}</span>
                  <span className="cart-row-qty">of {l.qty}</span>
                  <input
                    className="refund-qty" inputMode="decimal" placeholder="0"
                    aria-label={`Quantity of ${l.name} to refund`}
                    value={pick.qty}
                    onChange={(e) => setPicks({ ...picks, [l.id]: { ...pick, qty: e.target.value } })}
                  />
                  <label className="restock-check">
                    <input
                      type="checkbox" checked={pick.restock}
                      onChange={(e) => setPicks({ ...picks, [l.id]: { ...pick, restock: e.target.checked } })}
                    />
                    Restock
                  </label>
                </div>
              )
            })}
          </div>
          <label>
            Reason
            <input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Faulty" />
          </label>
          <hr className="dotted-divider" />
          <button type="submit" className="btn btn-submit" disabled={refund.isPending}>
            {refund.isPending ? 'Refunding…' : 'Refund cash'}
          </button>
        </form>
      )}

      {error && <p className="error">{error}</p>}
    </section>
  )
}
