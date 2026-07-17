'use client'

import { useQuery } from '@tanstack/react-query'
import { useEffect, useState, type FormEvent } from 'react'
import { ApiError, api, type Shift, type ShiftCloseResult } from '../lib/api'
import { cents, formatMoney, parseCentsOrNull } from '../lib/money'

const CURRENCY = 'USD' // display only; the server owns all arithmetic
const fm = (n: number) => formatMoney(cents(n), CURRENCY)

export function OpenShiftScreen({ onOpened, onSessionExpired }: {
  onOpened: (shift: Shift) => void
  onSessionExpired: () => void
}) {
  const [float, setFloat] = useState('200.00')
  const [error, setError] = useState<string | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    const amount = parseCentsOrNull(float)
    if (amount === null) return setError('Enter an amount like 200.00')
    try {
      onOpened(await api.openShift(amount))
    } catch (err) {
      if (err instanceof ApiError && err.status === 401) {
        onSessionExpired()
        return
      }
      setError(err instanceof ApiError ? err.message : 'Could not open the shift.')
    }
  }

  return (
    <section className="form-panel">
      <h2>Open shift</h2>
      <form onSubmit={submit}>
        <label>
          Opening float
          <input value={float} onChange={(e) => setFloat(e.target.value)} inputMode="decimal" autoFocus />
        </label>
        <hr className="dotted-divider" />
        <button type="submit" className="btn btn-submit">Open drawer</button>
      </form>
      {error && <p className="error">{error}</p>}
    </section>
  )
}

/**
 * The drawer's whole day, from the ledgers. Fetched while the cashier is still counting
 * (the close revokes this register's staff sessions, so afterwards would be a 401) —
 * sales, refunds, and movements are already final by the time the drawer is being
 * counted, so the running Z is the closing Z.
 */
function ZReportPanel({ z }: { z: ReturnType<typeof useZReport> }) {
  if (z.isPending) return <p className="muted">Loading the Z-report…</p>
  if (z.isError) return <p className="muted">Z-report unavailable — it can be pulled later from /reports/z.</p>

  const r = z.data
  return (
    <div className="zreport">
      <p className="picker-label">Z-report</p>
      <dl>
        {Object.entries(r.sales_by_driver).map(([driver, amount]) => (
          <span key={driver} className="zrow"><dt>Sales — {driver}</dt><dd>{fm(amount)}</dd></span>
        ))}
        {Object.entries(r.refunds_by_driver).map(([driver, amount]) => (
          <span key={driver} className="zrow"><dt>Refunds — {driver}</dt><dd>−{fm(amount)}</dd></span>
        ))}
        <span className="zrow"><dt>Paid in</dt><dd>{fm(r.movements.paid_in)}</dd></span>
        <span className="zrow"><dt>Payouts</dt><dd>−{fm(r.movements.payout)}</dd></span>
        <span className="zrow"><dt>Drops</dt><dd>−{fm(r.movements.drop)}</dd></span>
        <span className="zrow"><dt>Orders closed</dt><dd>{r.orders_closed}</dd></span>
        <span className="zrow"><dt>Orders voided</dt><dd>{r.orders_voided}</dd></span>
      </dl>
    </div>
  )
}

function useZReport(shiftId: string) {
  return useQuery({
    queryKey: ['z-report', shiftId],
    queryFn: () => api.zReport(shiftId),
  })
}

export function CloseShiftScreen({ shiftId, onClosed, onCancel, onSessionExpired }: {
  shiftId: string
  onClosed: (result: ShiftCloseResult) => void
  onCancel: () => void
  onSessionExpired: () => void
}) {
  const zReport = useZReport(shiftId)   // while the session is still alive — see ZReportPanel
  const [counted, setCounted] = useState('')

  // 401 anywhere clears the staff session and returns to PIN — including this fetch.
  useEffect(() => {
    if (zReport.error instanceof ApiError && zReport.error.status === 401) onSessionExpired()
  }, [zReport.error, onSessionExpired])
  const [result, setResult] = useState<ShiftCloseResult | null>(null)
  const [error, setError] = useState<string | null>(null)
  // Minted once for the life of this screen and reused across submits, so a re-click
  // after a lost-response timeout replays the same close instead of risking a second one.
  const [idempotencyKey] = useState(() => crypto.randomUUID())

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    const amount = parseCentsOrNull(counted)
    if (amount === null) return setError('Enter the counted cash, like 487.50')
    try {
      setResult(await api.closeShift(shiftId, amount, idempotencyKey))
    } catch (err) {
      if (err instanceof ApiError && err.status === 401) {
        onSessionExpired()
        return
      }
      setError(err instanceof ApiError ? err.message : 'Could not close the shift.')
    }
  }

  if (result) {
    return (
      <section className={`form-panel ${result.variance_cents === 0 ? 'ok' : 'bad'}`}>
        <h2>Drawer reconciled</h2>
        <dl>
          <dt>Expected</dt><dd>{fm(result.expected_cash_cents)}</dd>
          <dt>Counted</dt><dd>{fm(result.shift.counted_cash_cents ?? 0)}</dd>
          <dt>Variance</dt><dd>{fm(result.variance_cents)}</dd>
        </dl>
        {result.requires_approval && <p className="error">Variance exceeds the threshold — needs supervisor approval.</p>}
        <hr className="dotted-divider" />
        <ZReportPanel z={zReport} />
        <div className="btn-row">
          <button className="btn btn-utility" onClick={() => window.print()}>Print</button>
          <button className="btn btn-submit" onClick={() => onClosed(result)}>Done</button>
        </div>
      </section>
    )
  }

  return (
    <section className="form-panel">
      <h2>Close shift — count the drawer</h2>
      <form onSubmit={submit}>
        <label>
          Counted cash
          <input value={counted} onChange={(e) => setCounted(e.target.value)} inputMode="decimal" autoFocus />
        </label>
        <hr className="dotted-divider" />
        <div className="btn-row">
          <button type="submit" className="btn btn-submit">Close</button>
          <button type="button" className="btn btn-secondary" onClick={onCancel}>Back</button>
        </div>
      </form>
      {error && <p className="error">{error}</p>}
    </section>
  )
}
