'use client'

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react'
import { ApiError, api, type Order } from '../lib/api'
import { cents, formatMoney } from '../lib/money'

const CURRENCY = 'USD'
const fm = (n: number) => formatMoney(cents(n), CURRENCY)

// A stable reference for the "no data yet" case — `openOrders.data ?? []` would mint a
// new array every render, which then re-triggers the `targets` useMemo below every time
// for no reason.
const NO_ORDERS: Order[] = []

function ageLabel(openedAt: string | undefined): string {
  if (!openedAt) return '—'
  const mins = Math.max(0, Math.floor((Date.now() - new Date(openedAt).getTime()) / 60_000))
  if (mins < 60) return `${mins}m`
  return `${Math.floor(mins / 60)}h ${mins % 60}m`
}

/**
 * The floor view (M5, Task 12): every open order across the location, not just this
 * register's (api.openOrders() === findOrders({status:'open'}), unscoped). A plain list
 * of `plate` tab cards — deliberately not a graphical room layout, per the M5 design
 * spec's Out of scope. Register.tsx only mounts this screen while its TABS toggle is
 * active, so the 10s poll runs only while staff are actually looking at it.
 *
 * RESUME and TRANSFER only ever act on cards this register opened (`order.register_id
 * === registerId`): the floor shows every table so staff can see the whole room, but
 * working someone else's tab has to come through THEM handing it off via Transfer —
 * there's no "pull" affordance here, matching the transfer target list itself never
 * offering "me" as a destination.
 */
export function FloorScreen({ registerId, canTransfer, activeOrderId, onResume, onNewTab, onSessionExpired }: {
  registerId: string
  canTransfer: boolean
  // The order currently in progress on the mounted-hidden sale screen, if any — lifted
  // through Register.tsx via SaleScreen's onOrderChange. Resuming a DIFFERENT tab while
  // one is already in progress would strand it, so every other card's resume is
  // disabled until that sale is finished or parked.
  activeOrderId: string | null
  onResume: (order: Order) => void
  onNewTab: (order: Order) => void
  onSessionExpired: () => void
}) {
  const [error, setError] = useState<string | null>(null)
  const [newTabOpen, setNewTabOpen] = useState(false)
  const [tableRefInput, setTableRefInput] = useState('')
  const [transferOpenId, setTransferOpenId] = useState<string | null>(null)
  const queryClient = useQueryClient()

  // Minted once when the NEW TAB pad opens and reused across every submit attempt while
  // it stays open — same idiom as SaleScreen's tender-phase key — so a lost response
  // can't mint a twin order on retry. A fresh key is minted the next time the pad opens.
  const newTabKeyRef = useRef<string | null>(null)

  const fail = (err: unknown, fallback: string) => {
    if (err instanceof ApiError && err.status === 401) return onSessionExpired()
    setError(err instanceof ApiError ? err.message : fallback)
  }

  const openOrders = useQuery({
    queryKey: ['open-orders-floor'],
    queryFn: api.openOrders,
    refetchInterval: 10_000,
  })

  // useQuery (react-query v5) dropped the onError callback — watch the settled error the
  // same way Register.tsx's own shift-load effect does.
  useEffect(() => {
    if (openOrders.error instanceof ApiError && openOrders.error.status === 401) onSessionExpired()
  }, [openOrders.error, onSessionExpired])

  const newTab = useMutation({
    mutationFn: (tableRef: string) =>
      api.openOrder({ tableRef: tableRef || undefined, idempotencyKey: newTabKeyRef.current ?? undefined }),
    onSuccess: (order) => {
      newTabKeyRef.current = null
      setNewTabOpen(false)
      setTableRefInput('')
      setError(null)
      queryClient.invalidateQueries({ queryKey: ['open-orders-floor'] })
      onNewTab(order)
    },
    onError: (err) => fail(err, 'Could not open a new tab.'),
  })

  const transfer = useMutation({
    mutationFn: ({ order, targetRegisterId }: { order: Order; targetRegisterId: string }) =>
      api.transferOrder(order, targetRegisterId),
    onSuccess: () => {
      setTransferOpenId(null)
      setError(null)
      queryClient.invalidateQueries({ queryKey: ['open-orders-floor'] })
    },
    onError: (err) => fail(err, 'Transfer failed.'),
  })

  const orders = openOrders.data ?? NO_ORDERS

  // TRANSFER's destination list: the location's other registers with an open tab right
  // now, derived from this same payload rather than a separate registers endpoint (per
  // the brief). Labeled by that order's opened_by_name — Order carries no register name.
  const targets = useMemo(() => {
    const seen = new Map<string, string>()
    for (const o of orders) {
      if (o.register_id === registerId || seen.has(o.register_id)) continue
      seen.set(o.register_id, o.opened_by_name ?? 'Unknown')
    }
    return Array.from(seen, ([id, label]) => ({ registerId: id, label }))
  }, [orders, registerId])

  const submitNewTab = (e: FormEvent) => {
    e.preventDefault()
    if (newTab.isPending) return
    setError(null)
    newTab.mutate(tableRefInput.trim())
  }

  return (
    <section className="form-panel">
      <h2>Floor</h2>

      {newTabOpen ? (
        <form className="inline-reason" onSubmit={submitNewTab}>
          <input
            autoFocus placeholder="Table (optional)…" maxLength={20}
            value={tableRefInput} onChange={(e) => setTableRefInput(e.target.value)}
          />
          <button type="submit" className="btn btn-submit" disabled={newTab.isPending}>
            {newTab.isPending ? 'Opening…' : 'Open tab'}
          </button>
          <button
            type="button" className="btn btn-secondary"
            onClick={() => { newTabKeyRef.current = null; setNewTabOpen(false) }}
          >
            Cancel
          </button>
        </form>
      ) : (
        <div className="btn-row">
          <button
            type="button" className="btn btn-submit"
            onClick={() => { newTabKeyRef.current = crypto.randomUUID(); setNewTabOpen(true) }}
          >
            New tab
          </button>
        </div>
      )}

      {openOrders.isLoading && <p className="muted">Loading tabs…</p>}
      {openOrders.isError && <p className="error">Could not load the floor.</p>}
      {!openOrders.isLoading && orders.length === 0 && <p className="muted">No open tabs.</p>}

      <div className="floor-grid">
        {orders.map((o) => {
          const mine = o.register_id === registerId
          const blockedByActiveOrder = mine && activeOrderId !== null && activeOrderId !== o.id
          const resumeDisabled = !mine || blockedByActiveOrder
          return (
            <div className="tab-card-wrap" key={o.id}>
              <button
                type="button"
                className={`tab-card${activeOrderId === o.id ? ' active' : ''}`}
                disabled={resumeDisabled}
                title={!mine ? 'Open at another register.' : undefined}
                onClick={() => onResume(o)}
              >
                <span className="tab-card-ref">{o.table_ref ?? o.number}</span>
                <span className="tab-card-meta">{o.opened_by_name ?? 'Unknown'} · {ageLabel(o.opened_at)}</span>
                <span className="tab-card-due num">{fm(o.due_cents)}</span>
              </button>
              {blockedByActiveOrder && (
                <p className="tab-card-hint">Finish or park the current sale to switch tabs.</p>
              )}
              {mine && canTransfer && targets.length > 0 && (
                <div className="tab-card-transfer">
                  <button
                    type="button" className="btn btn-utility btn-chip"
                    onClick={() => setTransferOpenId(transferOpenId === o.id ? null : o.id)}
                  >
                    Transfer
                  </button>
                  {transferOpenId === o.id && (
                    <div className="transfer-picker">
                      <p className="picker-label">Send to</p>
                      {targets.map((t) => (
                        <button
                          key={t.registerId} type="button" className="btn btn-utility"
                          disabled={transfer.isPending}
                          onClick={() => transfer.mutate({ order: o, targetRegisterId: t.registerId })}
                        >
                          {t.label}
                        </button>
                      ))}
                    </div>
                  )}
                </div>
              )}
            </div>
          )
        })}
      </div>

      {error && <p className="error">{error}</p>}
    </section>
  )
}
