import { useRef, useState, type FormEvent } from 'react'
import { ApiError, api, type Order, type PaymentOutcome, type Receipt } from '../lib/api'
import { cents, formatMoney } from '../lib/money'

const CURRENCY = 'USD'
const fm = (n: number) => formatMoney(cents(n), CURRENCY)

function toCents(input: string): number | null {
  const m = /^(\d+)(?:\.(\d{1,2}))?$/.exec(input.trim())
  if (!m) return null
  return Number(m[1]) * 100 + Number((m[2] ?? '0').padEnd(2, '0'))
}

type Phase =
  | { name: 'scanning' }
  // Minted once when tender is entered and reused for every submit while in this phase,
  // so a re-click after a lost-response timeout replays the same payment instead of
  // risking a double charge. A fresh key is minted each time tender is re-entered.
  | { name: 'tender'; key: string }
  | { name: 'done'; outcome: PaymentOutcome; receipt: Receipt | null }

export function SaleScreen({ onCloseShift, onSessionExpired }: { onCloseShift: () => void; onSessionExpired: () => void }) {
  const [order, setOrder] = useState<Order | null>(null)
  const [phase, setPhase] = useState<Phase>({ name: 'scanning' })
  const [barcode, setBarcode] = useState('')
  const [tendered, setTendered] = useState('')
  const [error, setError] = useState<string | null>(null)
  const scanRef = useRef<HTMLInputElement>(null)

  const scan = async (e: FormEvent) => {
    e.preventDefault()
    if (!barcode.trim()) return
    setError(null)
    try {
      const { variant } = await api.lookupBarcode(barcode.trim())
      let current = order   // retail opens implicitly on first scan
      if (!current) {
        current = await api.openOrder()
        setOrder(current)
      }
      // A key per submit attempt: a re-submit after an error is a new scan (the failed
      // attempt rolled back), so it needs a new key — this only protects against a lost
      // response within a single submission, which fetch doesn't auto-retry.
      setOrder(await api.addLine(current, variant.id, '1', crypto.randomUUID()))
      setBarcode('')
    } catch (err) {
      setBarcode('')
      if (err instanceof ApiError && err.status === 401) {
        onSessionExpired()
        return
      }
      setError(err instanceof ApiError ? err.message : 'Scan failed.')
    }
    scanRef.current?.focus()
  }

  const pay = async (e: FormEvent) => {
    e.preventDefault()
    if (!order) return
    if (phase.name !== 'tender') return
    const handed = toCents(tendered)
    if (handed === null) return setError('Enter the cash handed over, like 50.00')
    setError(null)
    try {
      const outcome = await api.takePayment(order, order.total_cents - order.paid_cents, handed, phase.key)
      const receipt = await api.receipt(outcome.order.id).catch(() => null)
      setPhase({ name: 'done', outcome, receipt })
      setOrder(null)
      setTendered('')
    } catch (err) {
      if (err instanceof ApiError && err.status === 401) {
        onSessionExpired()
        return
      }
      setError(err instanceof ApiError ? err.message : 'Payment failed.')
    }
  }

  const newSale = () => {
    setPhase({ name: 'scanning' })
    setError(null)
    setTimeout(() => scanRef.current?.focus(), 0)
  }

  if (phase.name === 'done') {
    const { payment } = phase.outcome
    return (
      <section className="form-panel ok">
        <h2>Payment complete — order {phase.outcome.order.number}</h2>
        <div className="hero-panel">
          <p className="hero-eyebrow">Change</p>
          <p className="hero-amount">{fm(payment.change_cents ?? 0)}</p>
        </div>
        <p className="muted">
          {fm(payment.amount_cents)} paid on {fm(payment.tendered_cents ?? payment.amount_cents)} tendered
        </p>
        {phase.receipt && (
          <div className="receipt">
            <h3>{phase.receipt.location.header ?? phase.receipt.business.name}</h3>
            <p className="muted">{phase.receipt.order.number} · {phase.receipt.order.business_date} · {phase.receipt.order.cashier}</p>
            <table>
              <tbody>
                {phase.receipt.lines.map((l, i) => (
                  <tr key={i}>
                    <td>{l.name}</td>
                    <td>{l.qty === '1.000' ? '' : l.qty}</td>
                    <td className="num">{fm(l.line_total_cents)}</td>
                  </tr>
                ))}
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
  const balance = order ? order.total_cents - order.paid_cents : 0

  return (
    <section className="form-panel">
      <header className="row">
        <h2>{order ? `Order ${order.number}` : 'New sale'}</h2>
        <button type="button" className="btn btn-secondary" onClick={onCloseShift}>Close shift</button>
      </header>

      <form onSubmit={scan}>
        <input
          ref={scanRef} autoFocus placeholder="Scan or type a barcode…"
          value={barcode} onChange={(e) => setBarcode(e.target.value)}
        />
      </form>

      {lines.length > 0 && (
        <div className="cart">
          {lines.filter((l) => !l.voided_at).map((l) => (
            <div className="cart-row" key={l.id}>
              <span className="cart-row-name">{l.name}</span>
              {l.qty !== '1.000' && <span className="cart-row-qty">{l.qty}</span>}
              <span className="cart-row-price num">{fm(l.line_total_cents)}</span>
            </div>
          ))}
        </div>
      )}

      {order && (
        <dl className="totals">
          <dt>Subtotal</dt><dd>{fm(order.subtotal_cents)}</dd>
          <dt>Tax</dt><dd>{fm(order.tax_cents)}</dd>
          <dt>Total</dt><dd className="grand">{fm(order.total_cents)}</dd>
        </dl>
      )}

      {order && phase.name === 'scanning' && (
        <button
          className="btn btn-submit"
          disabled={order.total_cents === 0}
          onClick={() => setPhase({ name: 'tender', key: crypto.randomUUID() })}
        >
          Pay cash — {fm(balance)}
        </button>
      )}

      {order && phase.name === 'tender' && (
        <form onSubmit={pay}>
          <label>
            Cash tendered (owed: {fm(balance)})
            <input value={tendered} onChange={(e) => setTendered(e.target.value)} inputMode="decimal" autoFocus />
          </label>
          <hr className="dotted-divider" />
          <div className="btn-row">
            <button type="submit" className="btn btn-submit">Take payment</button>
            <button type="button" className="btn btn-secondary" onClick={() => setPhase({ name: 'scanning' })}>Back</button>
          </div>
        </form>
      )}

      {error && <p className="error">{error}</p>}
    </section>
  )
}
