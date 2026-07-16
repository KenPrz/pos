import { useState, type FormEvent } from 'react'
import { ApiError, api, type Shift, type ShiftCloseResult } from '../lib/api'
import { cents, formatMoney } from '../lib/money'

const CURRENCY = 'USD'   // display only; the server owns all arithmetic

/** Parse a human dollars-and-cents string to integer cents; '' -> null. */
function toCents(input: string): number | null {
  const m = /^(\d+)(?:\.(\d{1,2}))?$/.exec(input.trim())
  if (!m) return null
  return Number(m[1]) * 100 + Number((m[2] ?? '0').padEnd(2, '0'))
}

export function OpenShiftScreen({ onOpened, onSessionExpired }: {
  onOpened: (shift: Shift) => void
  onSessionExpired: () => void
}) {
  const [float, setFloat] = useState('200.00')
  const [error, setError] = useState<string | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    const amount = toCents(float)
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
    <section className="card">
      <h2>Open shift</h2>
      <form onSubmit={submit}>
        <label>
          Opening float
          <input value={float} onChange={(e) => setFloat(e.target.value)} inputMode="decimal" autoFocus />
        </label>
        <button type="submit">Open drawer</button>
      </form>
      {error && <p className="error">{error}</p>}
    </section>
  )
}

export function CloseShiftScreen({ shiftId, onClosed, onCancel, onSessionExpired }: {
  shiftId: string
  onClosed: (result: ShiftCloseResult) => void
  onCancel: () => void
  onSessionExpired: () => void
}) {
  const [counted, setCounted] = useState('')
  const [result, setResult] = useState<ShiftCloseResult | null>(null)
  const [error, setError] = useState<string | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    const amount = toCents(counted)
    if (amount === null) return setError('Enter the counted cash, like 487.50')
    try {
      setResult(await api.closeShift(shiftId, amount))
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
      <section className={`card ${result.variance_cents === 0 ? 'ok' : 'bad'}`}>
        <h2>Drawer reconciled</h2>
        <dl>
          <dt>Expected</dt><dd>{formatMoney(cents(result.expected_cash_cents), CURRENCY)}</dd>
          <dt>Counted</dt><dd>{formatMoney(cents(result.shift.counted_cash_cents ?? 0), CURRENCY)}</dd>
          <dt>Variance</dt><dd>{formatMoney(cents(result.variance_cents), CURRENCY)}</dd>
        </dl>
        {result.requires_approval && <p className="error">Variance exceeds the threshold — needs supervisor approval.</p>}
        <button onClick={() => onClosed(result)}>Done</button>
      </section>
    )
  }

  return (
    <section className="card">
      <h2>Close shift — count the drawer</h2>
      <form onSubmit={submit}>
        <label>
          Counted cash
          <input value={counted} onChange={(e) => setCounted(e.target.value)} inputMode="decimal" autoFocus />
        </label>
        <button type="submit">Close</button>
        <button type="button" className="secondary" onClick={onCancel}>Back</button>
      </form>
      {error && <p className="error">{error}</p>}
    </section>
  )
}
