'use client'

import { useMutation, useQuery } from '@tanstack/react-query'
import { useEffect, useRef, useState, type FormEvent } from 'react'
import { ApiError, api, tokens, type CatalogProduct, type CatalogVariant, type Order, type PaymentOutcome, type Receipt } from '../lib/api'
import { cents, formatMoney, parseCentsOrNull, subtract } from '../lib/money'
import { MenuGrid } from './MenuGrid'

const CURRENCY = 'USD'
const fm = (n: number) => formatMoney(cents(n), CURRENCY)

type Driver = 'cash' | 'external_card'

type Phase =
  | { name: 'scanning' }
  // Minted once when tender is entered and reused for every submit while in this phase,
  // so a re-click after a lost-response timeout replays the same payment instead of
  // risking a double charge. A fresh key is minted each time tender is re-entered.
  | { name: 'tender'; key: string }
  | { name: 'done'; outcome: PaymentOutcome; receipt: Receipt | null }

export function SaleScreen({ can, registerId, initialOrder, onOrderChange, onCloseShift, onSessionExpired }: {
  can: (permission: string) => boolean
  registerId: string
  // Set by the floor screen (Task 12) when staff resume a tab: seeds `order` on the
  // sale screen that stays mounted-hidden underneath it. Identity-compared, not
  // deep-compared — a fresh object with the same id (e.g. a floor re-poll) must NOT
  // re-seed and stomp whatever the till has done to the order since.
  initialOrder?: Order
  // Lifted so the floor screen can tell "my own tab, already in progress elsewhere"
  // apart from every other card and disable resuming it out from under itself.
  onOrderChange?: (order: Order | null) => void
  onCloseShift: () => void
  onSessionExpired: () => void
}) {
  const [order, setOrder] = useState<Order | null>(null)
  const [phase, setPhase] = useState<Phase>({ name: 'scanning' })
  const [barcode, setBarcode] = useState('')
  const [driver, setDriver] = useState<Driver>('cash')
  const [tendered, setTendered] = useState('')
  const [reference, setReference] = useState('')
  const [voidingLineId, setVoidingLineId] = useState<string | null>(null)
  const [voidReason, setVoidReason] = useState('')
  const [discountOpen, setDiscountOpen] = useState(false)
  const [discountReason, setDiscountReason] = useState('')
  const [voidingOrder, setVoidingOrder] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const scanRef = useRef<HTMLInputElement>(null)
  // Read once per render, not cached in state: SaleScreen only ever mounts after
  // Register's own bootstrap effect has already resolved tokens client-side (it's gated
  // behind stage transitions that themselves require a mount), so there's no SSR/hydration
  // mismatch to guard against here the way Register.tsx has to for its own first paint.
  const foodMode = tokens.registerInfo()?.mode === 'food'

  // Resuming a tab from the floor screen (Task 12): seed `order` whenever a NEW
  // initialOrder arrives. Keyed on id, not object identity — the floor list re-polls
  // every 10s and hands a fresh Order object with the SAME id on every tick; only an
  // actual "you tapped a different card" transition should reset scanning/phase here.
  const initialOrderId = initialOrder?.id ?? null
  useEffect(() => {
    if (!initialOrder) return
    setOrder(initialOrder)
    setPhase({ name: 'scanning' })
    setError(null)
    setNotice(`Resumed order ${initialOrder.number}.`)
    // eslint-disable-next-line react-hooks/exhaustive-deps -- deliberately keyed on the id (see comment above), not the initialOrder object
  }, [initialOrderId])

  // The floor screen (mounted alongside this, hidden) needs to know whether a DIFFERENT
  // order is already in progress here, to disable resuming other tabs out from under it.
  useEffect(() => {
    onOrderChange?.(order)
  }, [order, onOrderChange])

  const fail = (err: unknown, fallback: string) => {
    if (err instanceof ApiError && err.status === 401) return onSessionExpired()
    setError(err instanceof ApiError ? err.message : fallback)
  }

  // The menu of applicable discounts; fetched once per screen, only when the picker can
  // ever be shown. Order-scope only — line-scope pickers are more clutter than a
  // supervisor needs at the till.
  const discounts = useQuery({
    queryKey: ['catalog-discounts'],
    queryFn: () => api.catalog(),
    enabled: can('order.discount.apply'),
    staleTime: Infinity,
    select: (catalog) => catalog.discounts.filter((d) => d.scope === 'order'),
  })

  // A lost response (network_unreachable) may have succeeded server-side, so a rescan
  // of the SAME barcode right after one reuses the same idempotency key and replays
  // instead of double-adding. Any other outcome clears it: a domain refusal rolled
  // back, and a deliberate second scan of the same item must be a new line.
  const scanKeyRef = useRef<{ code: string; key: string } | null>(null)

  // Recovery: a reload or re-login can leave this register's open order alive only on
  // the server, where it silently blocks shift close. One shot per mount, this
  // register's orders only (a sibling till's tab is not ours to grab — that's M5).
  const openOrders = useQuery({
    queryKey: ['open-orders', registerId],
    queryFn: () => api.findOrders({ status: 'open' }),
    staleTime: Infinity,
  })
  const resumed = useRef(false)
  useEffect(() => {
    if (resumed.current || !openOrders.data) return
    resumed.current = true
    const mine = openOrders.data.filter((o) => o.register_id === registerId)
    if (mine.length > 0) {
      setOrder((existing) => existing ?? mine[0])
      setNotice(`Resumed open order ${mine[0].number}.`)
    }
  }, [openOrders.data, registerId])

  const scan = useMutation({
    mutationFn: async ({ code, key }: { code: string; key: string }) => {
      const { variant } = await api.lookupBarcode(code)
      let current = order // retail opens implicitly on first scan
      if (!current) {
        current = await api.openOrder({ idempotencyKey: key }) // same key: a lost response won't mint a twin
        setOrder(current)
      }
      return api.addLine(current, variant.id, '1', key)
    },
    onSuccess: (next) => {
      scanKeyRef.current = null
      setOrder(next)
      setBarcode('')
      setError(null)
    },
    onError: (err) => {
      if (!(err instanceof ApiError && err.code === 'network_unreachable')) scanKeyRef.current = null
      setBarcode('')
      fail(err, 'Scan failed.')
    },
    onSettled: () => scanRef.current?.focus(),
  })

  // Same idiom as `scan` above (implicit open-order-on-first-pick, keyed addLine so a
  // lost-response retry replays instead of double-adding) — signature-keyed rather than
  // barcode-keyed, since the grid has no barcode. A second tap of the *same* variant with
  // the *same* modifiers right after one is still read as a fresh replay attempt, exactly
  // like a rescanned barcode; a genuinely new order for the same item mints its own key
  // once the previous attempt has settled (scanKeyRef's own comment explains why: a
  // deliberate repeat pick must be a new line, not a merge).
  const pickKeyRef = useRef<{ signature: string; key: string } | null>(null)

  const pick = useMutation({
    mutationFn: async ({ variant, modifierIds, key }: { variant: CatalogVariant; modifierIds?: string[]; key: string }) => {
      let current = order
      if (!current) {
        current = await api.openOrder({ idempotencyKey: key })
        setOrder(current)
      }
      return api.addLine(current, variant.id, '1', key, modifierIds)
    },
    onSuccess: (next) => {
      pickKeyRef.current = null
      setOrder(next)
      setError(null)
    },
    onError: (err) => {
      if (!(err instanceof ApiError && err.code === 'network_unreachable')) pickKeyRef.current = null
      fail(err, 'Could not add item.')
    },
  })

  const handleMenuPick = (variant: CatalogVariant, _product: CatalogProduct, modifierIds?: string[]) => {
    // Mirrors submitScan's guard above: scan and pick both implicitly open an order on a
    // null `order`, so only one of them may be in flight at a time or a race orphans a
    // second open order server-side.
    if (pick.isPending || scan.isPending) return
    setError(null)
    setNotice(null)
    const signature = `${variant.id}:${(modifierIds ?? []).join(',')}`
    const previous = pickKeyRef.current
    const key = previous && previous.signature === signature ? previous.key : crypto.randomUUID()
    pickKeyRef.current = { signature, key }
    pick.mutate({ variant, modifierIds, key })
  }

  const voidLine = useMutation({
    mutationFn: ({ lineId, reason }: { lineId: string; reason: string }) =>
      api.voidLine(order as Order, lineId, reason),
    onSuccess: (next) => {
      setOrder(next)
      setVoidingLineId(null)
      setVoidReason('')
      setError(null)
    },
    onError: (err) => fail(err, 'Void failed.'),
  })

  // Kitchen prep chips (Task 12, food mode only). No If-Match: the server doesn't bump
  // the order's version for a prep-state change (SetLinePrepStateRequest carries none —
  // see api.ts's setLinePrep comment), so the response is applied directly.
  const PREP_CYCLE = { pending: 'in_progress', in_progress: 'ready', ready: 'pending' } as const
  const setPrep = useMutation({
    mutationFn: ({ lineId, state }: { lineId: string; state: 'pending' | 'in_progress' | 'ready' }) =>
      api.setLinePrep((order as Order).id, lineId, state),
    onSuccess: (next) => {
      setOrder(next)
      setError(null)
    },
    onError: (err) => fail(err, 'Could not update prep status.'),
  })

  const voidOrder = useMutation({
    mutationFn: (reason: string) => api.voidOrder(order as Order, reason),
    onSuccess: () => {
      setOrder(null)
      setVoidingOrder(false)
      setVoidReason('')
      setError(null)
      setTimeout(() => scanRef.current?.focus(), 0)
    },
    onError: (err) => fail(err, 'Void failed.'),
  })

  const settle = useMutation({
    mutationFn: () => api.settleOrder(order as Order),
    onSuccess: (closed) => {
      setOrder(null)
      setError(null)
      setNotice(`Order ${closed.number} closed — nothing to pay.`)
      setTimeout(() => scanRef.current?.focus(), 0)
    },
    onError: (err) => fail(err, 'Could not close the order.'),
  })

  const applyDiscount = useMutation({
    mutationFn: ({ discountId, reason }: { discountId: string; reason: string }) =>
      api.applyDiscount(order as Order, discountId, reason),
    onSuccess: (next) => {
      setOrder(next)
      setDiscountOpen(false)
      setDiscountReason('')
      setError(null)
    },
    onError: (err) => fail(err, 'Discount failed.'),
  })

  const removeDiscount = useMutation({
    mutationFn: (orderDiscountId: string) => api.removeDiscount(order as Order, orderDiscountId),
    onSuccess: (next) => {
      setOrder(next)
      setError(null)
    },
    onError: (err) => fail(err, 'Could not remove the discount.'),
  })

  const pay = useMutation({
    mutationFn: async ({ key }: { key: string }) => {
      const current = order as Order
      const amount = subtract(cents(current.total_cents), cents(current.paid_cents))
      const outcome =
        driver === 'cash'
          ? await api.takePayment(current, amount, 'cash', key, { tenderedCents: parseCentsOrNull(tendered) ?? 0 })
          : await api.takePayment(current, amount, 'external_card', key, { reference: reference.trim() || undefined })
      const receipt = await api.receipt(outcome.order.id).catch(() => null)
      return { outcome, receipt }
    },
    onSuccess: ({ outcome, receipt }) => {
      setPhase({ name: 'done', outcome, receipt })
      setOrder(null)
      setTendered('')
      setReference('')
      setError(null)
    },
    onError: (err) => fail(err, 'Payment failed.'),
  })

  const submitScan = (e: FormEvent) => {
    e.preventDefault()
    // Also blocked while `pick` is in flight: both mutations independently open an order
    // implicitly when `order` is still null, and letting scan and a grid tap race would let
    // each see the stale null and each mint its own order — the second one orphaned (open
    // orders block shift close). One order-opening path in flight at a time.
    if (!barcode.trim() || scan.isPending || pick.isPending) return
    setError(null)
    setNotice(null)
    const code = barcode.trim()
    const previous = scanKeyRef.current
    const key = previous && previous.code === code ? previous.key : crypto.randomUUID()
    scanKeyRef.current = { code, key }
    scan.mutate({ code, key })
  }

  const submitPay = (e: FormEvent) => {
    e.preventDefault()
    if (!order || phase.name !== 'tender' || pay.isPending) return
    if (driver === 'cash' && parseCentsOrNull(tendered) === null) return setError('Enter the cash handed over, like 50.00')
    setError(null)
    pay.mutate({ key: phase.key })
  }

  const newSale = () => {
    setPhase({ name: 'scanning' })
    setDriver('cash')
    setError(null)
    setTimeout(() => scanRef.current?.focus(), 0)
  }

  if (phase.name === 'done') {
    const { payment } = phase.outcome
    const paidCash = payment.driver === 'cash'
    return (
      <section className="form-panel ok">
        <h2>Payment complete — order {phase.outcome.order.number}</h2>
        {paidCash ? (
          <div className="hero-panel">
            <p className="hero-eyebrow">Change</p>
            <p className="hero-amount">{fm(payment.change_cents ?? 0)}</p>
          </div>
        ) : (
          <div className="hero-panel">
            <p className="hero-eyebrow">Card</p>
            <p className="hero-amount">No change due</p>
          </div>
        )}
        <p className="muted">
          {paidCash
            ? `${fm(payment.amount_cents)} paid on ${fm(payment.tendered_cents ?? payment.amount_cents)} tendered`
            : `${fm(payment.amount_cents)} recorded on the card terminal`}
        </p>
        {phase.receipt && (
          <div className="receipt">
            <h3>{phase.receipt.location.header ?? phase.receipt.business.name}</h3>
            <p className="muted">
              {phase.receipt.order.number} · {phase.receipt.order.business_date} · {phase.receipt.order.cashier}
            </p>
            <table>
              <tbody>
                {phase.receipt.lines.map((l, i) => (
                  <tr key={i}>
                    <td>{l.name}</td>
                    <td>{l.qty === '1.000' ? '' : l.qty}</td>
                    <td className="num">{fm(l.line_total_cents)}</td>
                  </tr>
                ))}
                {phase.receipt.totals.discount_cents > 0 && (
                  <tr><td>Discount</td><td /><td className="num">−{fm(phase.receipt.totals.discount_cents)}</td></tr>
                )}
                <tr><td>Tax</td><td /><td className="num">{fm(phase.receipt.totals.tax_cents)}</td></tr>
                <tr className="total"><td>Total</td><td /><td className="num">{fm(phase.receipt.totals.total_cents)}</td></tr>
              </tbody>
            </table>
            {phase.receipt.location.footer && <p className="muted">{phase.receipt.location.footer}</p>}
          </div>
        )}
        <div className="btn-row">
          <button className="btn btn-utility" onClick={() => window.print()}>Print</button>
          <button className="btn btn-submit" onClick={newSale}>New sale</button>
        </div>
      </section>
    )
  }

  const lines = order?.lines ?? []
  const appliedDiscounts = order?.discounts ?? []
  const balance = order ? order.total_cents - order.paid_cents : 0

  return (
    <section className="form-panel">
      <header className="row">
        <h2>{order ? `Order ${order.number}` : 'New sale'}</h2>
        <button type="button" className="btn btn-secondary" onClick={onCloseShift}>Close shift</button>
      </header>

      <form onSubmit={submitScan} className={foodMode ? 'scan-form-compact' : undefined}>
        <input
          ref={scanRef} autoFocus placeholder="Scan or type a barcode…"
          value={barcode} onChange={(e) => setBarcode(e.target.value)}
        />
      </form>

      {/* Food mode keeps the scan field above (a case of Cheddar arrives with a barcode
          too), but the grid — not the barcode reader — is the everyday food-order idiom.
          It reuses the exact keyed addLine/open-order-implicit path the scan form uses. */}
      {foodMode && phase.name === 'scanning' && <MenuGrid onPick={handleMenuPick} />}

      {lines.length > 0 && (
        <div className="cart">
          {lines.filter((l) => !l.voided_at).map((l) => (
            <div className="cart-row" key={l.id}>
              {/* A <div>, not a <span>: it wraps the modifier <ul> below, and <ul> is
                  flow content — invalid inside a <span>, which only permits phrasing
                  content. */}
              <div className="cart-row-main">
                <span className="cart-row-name">{l.name}</span>
                {l.modifiers && l.modifiers.length > 0 && (
                  <ul className="cart-row-modifiers">
                    {l.modifiers.map((m, i) => (
                      <li key={i}>
                        {m.name}
                        {m.price_delta_cents !== 0 && <span className="num"> {fm(m.price_delta_cents)}</span>}
                      </li>
                    ))}
                  </ul>
                )}
              </div>
              {l.qty !== '1.000' && <span className="cart-row-qty">{l.qty}</span>}
              <span className="cart-row-price num">{fm(l.line_total_cents)}</span>
              {/* Prep chip: pending → in_progress → ready → pending, one tap per step. Lines
                  with no prep tracking (prep_state null — e.g. a bagged retail add-on rung
                  up on a food-mode till) get no chip at all. */}
              {foodMode && l.prep_state !== null && phase.name === 'scanning' && (
                <button
                  type="button"
                  className={`chip prep-chip prep-${l.prep_state}`}
                  disabled={setPrep.isPending}
                  onClick={() => setPrep.mutate({ lineId: l.id, state: PREP_CYCLE[l.prep_state as 'pending' | 'in_progress' | 'ready'] })}
                >
                  {l.prep_state === 'pending' ? 'Pending' : l.prep_state === 'in_progress' ? 'Cooking' : 'Ready'}
                </button>
              )}
              {can('order.line.void') && phase.name === 'scanning' && voidingLineId !== l.id && (
                <button type="button" className="btn btn-void btn-chip" onClick={() => { setVoidingLineId(l.id); setVoidReason('') }}>
                  Void
                </button>
              )}
            </div>
          ))}
          {voidingLineId !== null && (
            <form
              className="inline-reason"
              onSubmit={(e) => {
                e.preventDefault()
                if (!voidReason.trim() || voidLine.isPending) return
                voidLine.mutate({ lineId: voidingLineId, reason: voidReason.trim() })
              }}
            >
              <input
                autoFocus placeholder="Reason for the void…"
                value={voidReason} onChange={(e) => setVoidReason(e.target.value)}
              />
              <button type="submit" className="btn btn-void">Confirm void</button>
              <button type="button" className="btn btn-secondary" onClick={() => setVoidingLineId(null)}>Keep</button>
            </form>
          )}
        </div>
      )}

      {appliedDiscounts.length > 0 && (
        <div className="cart">
          {appliedDiscounts.map((d) => (
            <div className="cart-row discount-row" key={d.id}>
              <span className="cart-row-name">{d.name}</span>
              <span className="cart-row-price num">−{fm(d.amount_cents)}</span>
              {can('order.discount.apply') && phase.name === 'scanning' && (
                <button
                  type="button" className="btn btn-secondary btn-chip" aria-label={`Remove ${d.name}`}
                  onClick={() => removeDiscount.mutate(d.id)}
                >
                  ✕
                </button>
              )}
            </div>
          ))}
        </div>
      )}

      {order && (
        <dl className="totals">
          <dt>Subtotal</dt><dd>{fm(order.subtotal_cents)}</dd>
          {order.discount_cents > 0 && (<><dt>Discount</dt><dd>−{fm(order.discount_cents)}</dd></>)}
          <dt>Tax</dt><dd>{fm(order.tax_cents)}</dd>
          <dt>Total</dt><dd className="grand">{fm(order.total_cents)}</dd>
        </dl>
      )}

      {order && phase.name === 'scanning' && (
        <>
          {discountOpen && (
            <div className="discount-picker">
              <p className="picker-label">Apply discount</p>
              {(discounts.data ?? []).map((d) => (
                <button
                  key={d.id} type="button" className="btn btn-utility"
                  disabled={!discountReason.trim() || applyDiscount.isPending}
                  onClick={() => applyDiscount.mutate({ discountId: d.id, reason: discountReason.trim() })}
                >
                  {d.name}
                </button>
              ))}
              <input
                placeholder="Reason (required)…"
                value={discountReason} onChange={(e) => setDiscountReason(e.target.value)}
              />
              <button type="button" className="btn btn-secondary" onClick={() => setDiscountOpen(false)}>Cancel</button>
            </div>
          )}
          {voidingOrder && (
            <form
              className="inline-reason"
              onSubmit={(e) => {
                e.preventDefault()
                if (!voidReason.trim() || voidOrder.isPending) return
                voidOrder.mutate(voidReason.trim())
              }}
            >
              <input
                autoFocus placeholder="Reason for voiding the whole order…"
                value={voidReason} onChange={(e) => setVoidReason(e.target.value)}
              />
              <button type="submit" className="btn btn-void">Void order</button>
              <button type="button" className="btn btn-secondary" onClick={() => setVoidingOrder(false)}>Keep</button>
            </form>
          )}
          <div className="btn-row">
            {order.total_cents === 0 ? (
              <button className="btn btn-submit" disabled={settle.isPending} onClick={() => settle.mutate()}>
                {(order.discounts?.length ?? 0) > 0 ? 'Close — fully comped' : 'Close empty order'}
              </button>
            ) : (
              <button
                className="btn btn-submit"
                onClick={() => setPhase({ name: 'tender', key: crypto.randomUUID() })}
              >
                Pay — {fm(balance)}
              </button>
            )}
            {can('order.discount.apply') && !discountOpen && (
              <button type="button" className="btn btn-utility" onClick={() => setDiscountOpen(true)}>Discount</button>
            )}
            {can('order.void') && !voidingOrder && (
              <button type="button" className="btn btn-void" onClick={() => { setVoidingOrder(true); setVoidReason('') }}>
                Void order
              </button>
            )}
          </div>
        </>
      )}

      {order && phase.name === 'tender' && (
        <form onSubmit={submitPay}>
          <div className="btn-row" role="group" aria-label="Payment method">
            <button
              type="button"
              className={`btn ${driver === 'cash' ? 'btn-submit' : 'btn-secondary'}`}
              aria-pressed={driver === 'cash'}
              onClick={() => setDriver('cash')}
            >
              Cash
            </button>
            <button
              type="button"
              className={`btn ${driver === 'external_card' ? 'btn-submit' : 'btn-secondary'}`}
              aria-pressed={driver === 'external_card'}
              onClick={() => setDriver('external_card')}
            >
              Card
            </button>
          </div>
          {driver === 'cash' ? (
            <label>
              Cash tendered (owed: {fm(balance)})
              <input value={tendered} onChange={(e) => setTendered(e.target.value)} inputMode="decimal" autoFocus />
            </label>
          ) : (
            <label>
              Card terminal reference (owed: {fm(balance)})
              <input value={reference} onChange={(e) => setReference(e.target.value)} placeholder="auth 004321" autoFocus />
            </label>
          )}
          <hr className="dotted-divider" />
          <div className="btn-row">
            <button type="submit" className="btn btn-submit" disabled={pay.isPending}>
              {pay.isPending ? 'Taking payment…' : 'Take payment'}
            </button>
            <button type="button" className="btn btn-secondary" onClick={() => setPhase({ name: 'scanning' })}>Back</button>
          </div>
        </form>
      )}

      {error && <p className="error">{error}</p>}
      {notice && <p className="muted">{notice}</p>}
    </section>
  )
}
