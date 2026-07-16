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
  | { name: 'tender' }
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
      setOrder(await api.addLine(current, variant.id))
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
    const handed = toCents(tendered)
    if (handed === null) return setError('Enter the cash handed over, like 50.00')
    setError(null)
    try {
      const outcome = await api.takePayment(order, order.total_cents - order.paid_cents, handed)
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
      <section className="card ok">
        <h2>Change due: {fm(payment.change_cents ?? 0)}</h2>
        <p className="muted">
          {fm(payment.amount_cents)} paid on {fm(payment.tendered_cents ?? payment.amount_cents)} tendered — order {phase.outcome.order.number}
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
        <button onClick={() => window.print()}>Print</button>
        <button onClick={newSale}>New sale</button>
      </section>
    )
  }

  const lines = order?.lines ?? []
  const balance = order ? order.total_cents - order.paid_cents : 0

  return (
    <section className="card">
      <header className="row">
        <h2>{order ? `Order ${order.number}` : 'New sale'}</h2>
        <button type="button" className="secondary" onClick={onCloseShift}>Close shift</button>
      </header>

      <form onSubmit={scan}>
        <input
          ref={scanRef} autoFocus placeholder="Scan or type a barcode…"
          value={barcode} onChange={(e) => setBarcode(e.target.value)}
        />
      </form>

      {lines.length > 0 && (
        <table className="cart">
          <tbody>
            {lines.filter((l) => !l.voided_at).map((l) => (
              <tr key={l.id}>
                <td>{l.name}</td>
                <td>{l.qty === '1.000' ? '' : l.qty}</td>
                <td className="num">{fm(l.line_total_cents)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {order && (
        <dl className="totals">
          <dt>Subtotal</dt><dd>{fm(order.subtotal_cents)}</dd>
          <dt>Tax</dt><dd>{fm(order.tax_cents)}</dd>
          <dt>Total</dt><dd className="grand">{fm(order.total_cents)}</dd>
        </dl>
      )}

      {order && phase.name === 'scanning' && (
        <button disabled={order.total_cents === 0} onClick={() => setPhase({ name: 'tender' })}>
          Pay cash — {fm(balance)}
        </button>
      )}

      {order && phase.name === 'tender' && (
        <form onSubmit={pay}>
          <label>
            Cash tendered (owed: {fm(balance)})
            <input value={tendered} onChange={(e) => setTendered(e.target.value)} inputMode="decimal" autoFocus />
          </label>
          <button type="submit">Take payment</button>
          <button type="button" className="secondary" onClick={() => setPhase({ name: 'scanning' })}>Back</button>
        </form>
      )}

      {error && <p className="error">{error}</p>}
    </section>
  )
}
