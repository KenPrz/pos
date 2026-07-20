'use client'

import { useMutation, useQuery } from '@tanstack/react-query'
import { useEffect, useRef, useState, type FormEvent } from 'react'
import { ApiError, api, tokens, type CatalogProduct, type CatalogVariant, type Order, type PaymentOutcome, type Receipt } from '../lib/api'
import { cents, formatMoney, parseCentsOrNull, subtract } from '../lib/money'
import { ActionZone } from '@/components/ActionZone'
import { CartLine } from '@/components/CartLine'
import { MoneyText } from '@/components/MoneyText'
import { PrepChip } from '@/components/PrepChip'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'
import { MenuGrid } from './MenuGrid'
import { SplitPrompt, SplitStrip } from './SplitStrip'

const CURRENCY = 'USD'
const fm = (n: number) => formatMoney(cents(n), CURRENCY)

type Driver = 'cash' | 'external_card'

// One paid-off child, kept for the combined done plate once every check has closed.
type PaidChild = { outcome: PaymentOutcome; receipt: Receipt | null }

// Split-into-N-checks (Task 13): `children` are ordinary orders returned by
// api.splitOrder — the original order is voided server-side and is not among them.
// `activeIx` is which child is currently the working `order` for the tender flow;
// `paid` accumulates as each one closes, in order, for the combined done plate.
type SplitState = { children: Order[]; activeIx: number; paid: PaidChild[] }

type Phase =
  | { name: 'scanning' }
  // Minted once when tender is entered and reused for every submit while in this phase,
  // so a re-click after a lost-response timeout replays the same payment instead of
  // risking a double charge. A fresh key is minted each time tender is re-entered
  // (including advancing to the next split child — each child's payment is its own
  // idempotent attempt, distinct from the one key minted per confirmed split itself).
  | { name: 'tender'; key: string }
  | { name: 'done'; outcome: PaymentOutcome; receipt: Receipt | null }
  | { name: 'split-done'; paid: PaidChild[] }

/** The lines/discount/tax/total table shared by the single-order and split done plates.
    Deliberately still on the legacy `.receipt` classes: the printable receipt and its
    @media print CSS survived the Task 9 cutover as src/styles/print.css, preserved
    as-is (plain, functional) — do not restyle. */
function ReceiptCard({ receipt }: { receipt: Receipt }) {
  return (
    <div className="receipt">
      <h3>{receipt.location.header ?? receipt.business.name}</h3>
      <p className="muted">
        {receipt.order.number} · {receipt.order.business_date} · {receipt.order.cashier}
      </p>
      <table>
        <tbody>
          {receipt.lines.map((l, i) => (
            <tr key={i}>
              <td>{l.name}</td>
              <td>{l.qty === '1.000' ? '' : l.qty}</td>
              <td className="num">{fm(l.line_total_cents)}</td>
            </tr>
          ))}
          {receipt.totals.discount_cents > 0 && (
            <tr><td>Discount</td><td /><td className="num">−{fm(receipt.totals.discount_cents)}</td></tr>
          )}
          <tr><td>Tax</td><td /><td className="num">{fm(receipt.totals.tax_cents)}</td></tr>
          <tr className="total"><td>Total</td><td /><td className="num">{fm(receipt.totals.total_cents)}</td></tr>
        </tbody>
      </table>
      {receipt.location.footer && <p className="muted">{receipt.location.footer}</p>}
    </div>
  )
}

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
  const [split, setSplit] = useState<SplitState | null>(null)
  const [splitPromptOpen, setSplitPromptOpen] = useState(false)
  const [splitWays, setSplitWays] = useState(2)
  // Same idiom as scanKeyRef/pickKeyRef: minted once when GO is confirmed and reused
  // across a lost-response retry of that SAME split, so it can't mint a second one.
  const splitKeyRef = useRef<string | null>(null)
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
  // ready → pending is a downgrade out of a fired state; the server gates it on
  // order.line.void (mirrors UpdateLineQty's fired-line rule). Without that permission the
  // cycle stops at ready rather than wrapping back to pending — a supervisor keeps the
  // full loop. `null` means the chip is a dead end for this user (rendered disabled).
  const nextPrep = (state: 'pending' | 'in_progress' | 'ready') =>
    state === 'ready' && !can('order.line.void') ? null : PREP_CYCLE[state]
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
    // Split children always close in one shot: the tender form always submits the
    // child's FULL due (never a partial amount the cashier typed), so a successful
    // payment here always means this child is done — never "partially paid, still open".
    onSuccess: ({ outcome, receipt }) => {
      setTendered('')
      setReference('')
      setError(null)
      if (!split) {
        setPhase({ name: 'done', outcome, receipt })
        setOrder(null)
        return
      }
      const paid = [...split.paid, { outcome, receipt }]
      // Splice the just-closed child's own post-payment copy (due_cents: 0) back into
      // `children` at the index that was just paid — without this, the strip's stale
      // pre-payment snapshot never shows settled/"Paid" for a closed check.
      const children = split.children.map((c, ix) => (ix === split.activeIx ? outcome.order : c))
      const nextIx = split.activeIx + 1
      if (nextIx < children.length) {
        // Next unpaid child becomes the working order, straight into a fresh tender —
        // same code path every other order goes through, just re-entered per child.
        setSplit({ children, activeIx: nextIx, paid })
        setOrder(children[nextIx])
        setPhase({ name: 'tender', key: crypto.randomUUID() })
      } else {
        setSplit(null)
        setOrder(null)
        setPhase({ name: 'split-done', paid })
      }
    },
    onError: (err) => fail(err, 'Payment failed.'),
  })

  // Splits the working order into `ways` new ones (SplitOrderRequest: 2–10). Only ever
  // called on the pre-split original — a child can't be re-split (the tender-phase SPLIT
  // control is hidden once `split` is set), and the server itself refuses once
  // paid_cents > 0, so this only ever runs before any money has been taken.
  const splitOrderMut = useMutation({
    mutationFn: async (ways: number) => {
      const key = splitKeyRef.current ?? crypto.randomUUID()
      splitKeyRef.current = key
      return api.splitOrder(order as Order, ways, key)
    },
    onSuccess: (children) => {
      splitKeyRef.current = null
      setSplit({ children, activeIx: 0, paid: [] })
      setOrder(children[0])
      setSplitPromptOpen(false)
      setSplitWays(2)
      setPhase({ name: 'tender', key: crypto.randomUUID() })
      setTendered('')
      setReference('')
      setError(null)
    },
    onError: (err) => {
      if (!(err instanceof ApiError && err.code === 'network_unreachable')) splitKeyRef.current = null
      fail(err, 'Could not split the order.')
    },
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

  // Shared by the tender form's onSubmit (Enter in the amount field) and the action
  // zone's Take payment button — one guard path, exactly the old submitPay behavior.
  const doPay = () => {
    if (!order || phase.name !== 'tender' || pay.isPending) return
    if (driver === 'cash' && parseCentsOrNull(tendered) === null) return setError('Enter the cash handed over, like 50.00')
    setError(null)
    pay.mutate({ key: phase.key })
  }

  const submitPay = (e: FormEvent) => {
    e.preventDefault()
    doPay()
  }

  const newSale = () => {
    setPhase({ name: 'scanning' })
    setDriver('cash')
    setSplit(null)
    setSplitPromptOpen(false)
    setSplitWays(2)
    setError(null)
    setTimeout(() => scanRef.current?.focus(), 0)
  }

  const lines = order?.lines ?? []
  const appliedDiscounts = order?.discounts ?? []
  // Server-computed (OrderResource.php: max(0, total_cents - paid_cents)) — never
  // derived client-side, same rule the split-child strip already follows.
  const balance = order ? order.due_cents : 0

  const inSale = phase.name === 'scanning' || phase.name === 'tender'

  // ── The context pane (right): scan-first idle / menu grid / tender + split / done ──
  const contextPane =
    phase.name === 'done' ? (() => {
      const { payment } = phase.outcome
      const paidCash = payment.driver === 'cash'
      return (
        <div className="flex flex-col gap-lg">
          <h2 className="type-headline">Payment complete — order {phase.outcome.order.number}</h2>
          <div className="flex flex-col items-center gap-xs border border-hairline bg-surface-1 px-lg py-xl print:border-0 print:bg-transparent">
            <p className="type-caption text-ink-muted">{paidCash ? 'Change' : 'Card'}</p>
            {paidCash ? (
              <MoneyText cents={payment.change_cents ?? 0} currency={CURRENCY} size="total" className="hero-amount" />
            ) : (
              <p className="type-display-md">No change due</p>
            )}
          </div>
          <p className="type-body-sm text-ink-muted">
            {paidCash
              ? `${fm(payment.amount_cents)} paid on ${fm(payment.tendered_cents ?? payment.amount_cents)} tendered`
              : `${fm(payment.amount_cents)} recorded on the card terminal`}
          </p>
          {phase.receipt && <ReceiptCard receipt={phase.receipt} />}
        </div>
      )
    })() : phase.name === 'split-done' ? (
      <div className="flex flex-col gap-lg">
        <h2 className="type-headline">All checks settled — {phase.paid.length} {phase.paid.length === 1 ? 'check' : 'checks'}</h2>
        {phase.paid.map(({ outcome, receipt }, ix) => (
          <div className="flex flex-col gap-sm border-b border-hairline pb-lg print:border-0" key={outcome.order.id}>
            <header className="flex items-center justify-between gap-md">
              <h3 className="type-card-title">Check {ix + 1} — order {outcome.order.number}</h3>
              {receipt && <Button type="button" variant="ghost" className="min-h-[48px]" onClick={() => window.print()}>Print</Button>}
            </header>
            <p className="type-body-sm text-ink-muted">
              {outcome.payment.driver === 'cash'
                ? `${fm(outcome.payment.amount_cents)} paid on ${fm(outcome.payment.tendered_cents ?? outcome.payment.amount_cents)} tendered`
                : `${fm(outcome.payment.amount_cents)} recorded on the card terminal`}
            </p>
            {receipt && <ReceiptCard receipt={receipt} />}
          </div>
        ))}
      </div>
    ) : (
      <div className="flex flex-col gap-lg">
        {/* Visible through scanning AND tender for the active child — the active child's
            own due tracks the live `order` (not the split snapshot), so an edit made to
            its cart before it's paid shows up here immediately. */}
        {split && (
          <SplitStrip
            orders={split.children.map((c, ix) => (ix === split.activeIx ? (order ?? c) : c))}
            activeIx={split.activeIx}
          />
        )}

        <form onSubmit={submitScan}>
          <Input
            ref={scanRef} autoFocus placeholder="Scan or type a barcode…"
            value={barcode} onChange={(e) => setBarcode(e.target.value)}
            // Food mode keeps the scan field (a case of Cheddar arrives with a barcode
            // too) but compact — the grid below, not the barcode reader, is the everyday
            // food-order idiom. Retail gets the full-width scan-first field.
            className={cn('h-[56px] text-[18px]', foodMode && 'max-w-[240px]')}
          />
        </form>

        {/* The grid reuses the exact keyed addLine/open-order-implicit path the scan
            form uses. Migrated to the vocabulary in Task 9 — rendered as-is here. */}
        {foodMode && phase.name === 'scanning' && <MenuGrid onPick={handleMenuPick} />}

        {order && phase.name === 'scanning' && (
          <>
            {discountOpen && (
              <div className="flex flex-col gap-sm border border-hairline p-md">
                <p className="type-body-sm text-ink-muted">Apply discount</p>
                <div className="flex flex-wrap gap-sm">
                  {(discounts.data ?? []).map((d) => (
                    <Button
                      key={d.id} type="button" variant="tertiary" size="lg"
                      disabled={!discountReason.trim() || applyDiscount.isPending}
                      onClick={() => applyDiscount.mutate({ discountId: d.id, reason: discountReason.trim() })}
                    >
                      {d.name}
                    </Button>
                  ))}
                </div>
                <Input
                  placeholder="Reason (required)…" className="min-h-[48px]"
                  value={discountReason} onChange={(e) => setDiscountReason(e.target.value)}
                />
                <div>
                  <Button type="button" variant="ghost" className="min-h-[48px]" onClick={() => setDiscountOpen(false)}>Cancel</Button>
                </div>
              </div>
            )}
            {voidingOrder && (
              <form
                className="flex items-center gap-sm"
                onSubmit={(e) => {
                  e.preventDefault()
                  if (!voidReason.trim() || voidOrder.isPending) return
                  voidOrder.mutate(voidReason.trim())
                }}
              >
                <Input
                  autoFocus placeholder="Reason for voiding the whole order…" className="min-h-[48px]"
                  value={voidReason} onChange={(e) => setVoidReason(e.target.value)}
                />
                <Button type="submit" variant="danger" size="lg">Void order</Button>
                <Button type="button" variant="ghost" size="lg" onClick={() => setVoidingOrder(false)}>Keep</Button>
              </form>
            )}
            {(can('order.discount.apply') || can('order.void')) && (
              <div className="flex flex-wrap gap-sm">
                {can('order.discount.apply') && !discountOpen && (
                  <Button type="button" variant="tertiary" size="lg" onClick={() => setDiscountOpen(true)}>Discount</Button>
                )}
                {can('order.void') && !voidingOrder && (
                  <Button
                    type="button" variant="ghost" size="lg" className="text-error"
                    onClick={() => { setVoidingOrder(true); setVoidReason('') }}
                  >
                    Void order
                  </Button>
                )}
              </div>
            )}
          </>
        )}

        {/* SPLIT ×N: only offered on the pre-split original (never a child — the server
            itself refuses once paid_cents > 0, and re-splitting a child isn't a supported
            shape here) and only before any tender is entered for it. */}
        {order && phase.name === 'tender' && !split && !splitPromptOpen && (
          <div>
            <Button type="button" variant="tertiary" size="lg" onClick={() => setSplitPromptOpen(true)}>
              Split bill
            </Button>
          </div>
        )}
        {order && phase.name === 'tender' && !split && splitPromptOpen && (
          <SplitPrompt
            ways={splitWays}
            totalCents={order.total_cents}
            onWaysChange={setSplitWays}
            onConfirm={() => splitOrderMut.mutate(splitWays)}
            onCancel={() => setSplitPromptOpen(false)}
            pending={splitOrderMut.isPending}
          />
        )}

        {order && phase.name === 'tender' && !splitPromptOpen && (
          <form onSubmit={submitPay} className="flex flex-col gap-md">
            <div className="flex gap-sm" role="group" aria-label="Payment method">
              <Button
                type="button" size="lg" className="flex-1"
                variant={driver === 'cash' ? 'primary' : 'tertiary'}
                aria-pressed={driver === 'cash'}
                onClick={() => setDriver('cash')}
              >
                Cash
              </Button>
              <Button
                type="button" size="lg" className="flex-1"
                variant={driver === 'external_card' ? 'primary' : 'tertiary'}
                aria-pressed={driver === 'external_card'}
                onClick={() => setDriver('external_card')}
              >
                Card
              </Button>
            </div>
            {driver === 'cash' ? (
              <label className="block">
                <span className="type-body-sm text-ink-muted">Cash tendered (owed: {fm(balance)})</span>
                <Input
                  value={tendered} onChange={(e) => setTendered(e.target.value)} inputMode="decimal" autoFocus
                  className="type-money mt-xs h-[56px] text-[24px]"
                />
              </label>
            ) : (
              <label className="block">
                <span className="type-body-sm text-ink-muted">Card terminal reference (owed: {fm(balance)})</span>
                <Input
                  value={reference} onChange={(e) => setReference(e.target.value)} placeholder="auth 004321" autoFocus
                  className="mt-xs h-[56px]"
                />
              </label>
            )}
          </form>
        )}

        {error && <p className="type-body-sm text-error">{error}</p>}
        {notice && <p className="type-body-sm text-ink-muted">{notice}</p>}
      </div>
    )

  // ── The screen: header chrome, cart pane (left) | context pane (right), action zone.
  // Two panes that swap CONTENT between stages while the chrome stays still; height is
  // the viewport minus the 48px top bar, the shell's p-lg, and the 64px action-zone
  // band (reserved even when no primary action is showing, so nothing ever jumps).
  return (
    <section className="flex h-[calc(100dvh-160px)] min-h-[360px] flex-col gap-md print:block print:h-auto">
      <header className="flex shrink-0 items-center justify-between gap-md print:hidden">
        <h2 className="type-headline">{order ? `Order ${order.number}` : 'New sale'}</h2>
        {/* Present through scanning and tender exactly as before; the done plates never
            offered it (they were separate screens pre-rework) and still don't. */}
        {inSale && (
          <Button type="button" variant="ghost" className="min-h-[48px]" onClick={onCloseShift}>Close shift</Button>
        )}
      </header>

      <div className="grid min-h-0 flex-1 grid-cols-[minmax(320px,2fr)_minmax(0,3fr)] gap-lg print:block">
        {/* Cart pane: scrolling line list on top, discounts + totals pinned beneath. */}
        <div className="flex min-h-0 flex-col border border-hairline print:hidden">
          <div className="min-h-0 flex-1 overflow-y-auto">
            {lines.filter((l) => !l.voided_at).map((l) => (
              <CartLine
                key={l.id}
                line={l}
                // Prep chip: pending → in_progress → ready → pending, one tap per step.
                // Lines with no prep tracking (prep_state null — e.g. a bagged retail
                // add-on rung up on a food-mode till) get no chip at all.
                prepChip={
                  foodMode && l.prep_state !== null && phase.name === 'scanning'
                    ? (() => {
                        const next = nextPrep(l.prep_state)
                        return (
                          <PrepChip
                            state={l.prep_state}
                            disabled={setPrep.isPending || next === null}
                            onCycle={() => { if (next !== null) setPrep.mutate({ lineId: l.id, state: next }) }}
                          />
                        )
                      })()
                    : undefined
                }
                onVoid={
                  can('order.line.void') && phase.name === 'scanning' && voidingLineId !== l.id
                    ? () => { setVoidingLineId(l.id); setVoidReason('') }
                    : undefined
                }
              />
            ))}
            {voidingLineId !== null && (
              <form
                className="flex items-center gap-sm border-b border-hairline px-md py-sm"
                onSubmit={(e) => {
                  e.preventDefault()
                  if (!voidReason.trim() || voidLine.isPending) return
                  voidLine.mutate({ lineId: voidingLineId, reason: voidReason.trim() })
                }}
              >
                <Input
                  autoFocus placeholder="Reason for the void…" className="min-h-[48px]"
                  value={voidReason} onChange={(e) => setVoidReason(e.target.value)}
                />
                <Button type="submit" variant="danger" className="min-h-[48px]">Confirm void</Button>
                <Button type="button" variant="ghost" className="min-h-[48px]" onClick={() => setVoidingLineId(null)}>Keep</Button>
              </form>
            )}
          </div>

          {(order !== null || appliedDiscounts.length > 0) && (
            <div className="shrink-0 border-t border-hairline px-md py-sm">
              {appliedDiscounts.map((d) => (
                <div className="flex min-h-[48px] items-center gap-sm" key={d.id}>
                  <span className="type-body-lg min-w-0 flex-1">{d.name}</span>
                  <span className="type-body-lg type-money shrink-0">
                    −<MoneyText cents={d.amount_cents} currency={CURRENCY} size="line" />
                  </span>
                  {can('order.discount.apply') && phase.name === 'scanning' && (
                    <Button
                      type="button" variant="ghost" aria-label={`Remove ${d.name}`}
                      className="min-h-[48px] shrink-0 text-error"
                      onClick={() => removeDiscount.mutate(d.id)}
                    >
                      ✕
                    </Button>
                  )}
                </div>
              ))}
              {order && (
                <dl className="flex flex-col gap-xxs pt-xs">
                  <div className="flex items-baseline justify-between gap-md">
                    <dt className="type-body-sm text-ink-muted">Subtotal</dt>
                    <dd><MoneyText cents={order.subtotal_cents} currency={CURRENCY} size="line" /></dd>
                  </div>
                  {order.discount_cents > 0 && (
                    <div className="flex items-baseline justify-between gap-md">
                      <dt className="type-body-sm text-ink-muted">Discount</dt>
                      <dd className="type-body-lg type-money">−<MoneyText cents={order.discount_cents} currency={CURRENCY} size="line" /></dd>
                    </div>
                  )}
                  <div className="flex items-baseline justify-between gap-md">
                    <dt className="type-body-sm text-ink-muted">Tax</dt>
                    <dd><MoneyText cents={order.tax_cents} currency={CURRENCY} size="line" /></dd>
                  </div>
                  <div className="mt-xs flex items-baseline justify-between gap-md border-t border-hairline pt-xs">
                    <dt className="type-body-sm text-ink-muted">Total</dt>
                    <dd><MoneyText cents={order.total_cents} currency={CURRENCY} size="total" /></dd>
                  </div>
                </dl>
              )}
            </div>
          )}
        </div>

        {/* Context pane. */}
        <div className="min-h-0 overflow-y-auto print:overflow-visible">{contextPane}</div>
      </div>

      {/* The stage's single primary action. SplitPrompt renders its own GO/Cancel
          action zone while it's open, so exactly one zone shows at a time. */}
      {phase.name === 'scanning' && order && (
        <ActionZone>
          {order.total_cents === 0 ? (
            <Button size="xl" disabled={settle.isPending} onClick={() => settle.mutate()}>
              {(order.discounts?.length ?? 0) > 0 ? 'Close — fully comped' : 'Close empty order'}
            </Button>
          ) : (
            <Button size="xl" onClick={() => setPhase({ name: 'tender', key: crypto.randomUUID() })}>
              Pay — {fm(balance)}
            </Button>
          )}
        </ActionZone>
      )}
      {phase.name === 'tender' && order && !splitPromptOpen && (
        <ActionZone>
          <Button size="xl" type="button" disabled={pay.isPending} onClick={doPay}>
            {pay.isPending ? 'Taking payment…' : 'Take payment'}
          </Button>
          <Button size="xl" type="button" variant="ghost" onClick={() => setPhase({ name: 'scanning' })}>Back</Button>
        </ActionZone>
      )}
      {phase.name === 'done' && (
        <ActionZone>
          <Button size="xl" type="button" variant="ghost" onClick={() => window.print()}>Print</Button>
          <Button size="xl" onClick={newSale}>New sale</Button>
        </ActionZone>
      )}
      {phase.name === 'split-done' && (
        <ActionZone>
          <Button size="xl" onClick={newSale}>New sale</Button>
        </ActionZone>
      )}
    </section>
  )
}
