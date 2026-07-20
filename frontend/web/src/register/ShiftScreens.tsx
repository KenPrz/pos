'use client'

import { useMutation, useQuery } from '@tanstack/react-query'
import { useEffect, useState, type FormEvent } from 'react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'
import { ApiError, api, tokens, type Shift, type ShiftCloseResult } from '../lib/api'
import { cents, formatMoney, parseCentsOrNull } from '../lib/money'

const CURRENCY = 'USD' // display only; the server owns all arithmetic
const fm = (n: number) => formatMoney(cents(n), CURRENCY)

// The blind-count mask (Task 13): a cashier who can see the expected figure before
// counting is primed to just type it back rather than count. Presentation only — the
// Z-report is fetched exactly as before, this just controls whether the number is drawn.
const MASK = '•••••'

export function OpenShiftScreen({ onOpened, onSessionExpired }: {
  onOpened: (shift: Shift) => void
  onSessionExpired: (err?: unknown) => void
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
        onSessionExpired(err)
        return
      }
      setError(err instanceof ApiError ? err.message : 'Could not open the shift.')
    }
  }

  return (
    <Card className="mx-auto mt-xxl w-full max-w-[420px]">
      <CardHeader>
        <CardTitle>Open shift</CardTitle>
      </CardHeader>
      <CardContent>
        <form onSubmit={submit} className="flex flex-col gap-lg">
          <label className="block">
            <span className="type-body-sm text-ink-muted">Opening float</span>
            <Input
              value={float} onChange={(e) => setFloat(e.target.value)} inputMode="decimal" autoFocus
              className="type-money mt-xs h-[56px] text-[24px]"
            />
          </label>
          <Button type="submit" size="lg" className="w-full">Open drawer</Button>
        </form>
        {error && <p className="type-body-sm mt-md text-error">{error}</p>}
      </CardContent>
    </Card>
  )
}

/**
 * The drawer's whole day, from the ledgers. Fetched while the cashier is still counting
 * (the close revokes this register's staff sessions, so afterwards would be a 401) —
 * sales, refunds, and movements are already final by the time the drawer is being
 * counted, so the running Z is the closing Z.
 *
 * Rows are Carbon data-table-style hairline rows: label left, tabular figure right,
 * 1px hairline between rows. The dt/dd adjacency inside each row is what the tests
 * (and screen readers) key on — keep dd immediately after dt.
 */
function ZReportPanel({ z }: { z: ReturnType<typeof useZReport> }) {
  if (z.isPending) return <p className="type-body-sm text-ink-muted">Loading the Z-report…</p>
  if (z.isError) return <p className="type-body-sm text-ink-muted">Z-report unavailable — it can be pulled later from /reports/z.</p>

  const r = z.data
  const row = 'flex items-center justify-between gap-md border-b border-hairline py-xs'
  const label = 'type-body-sm text-ink-muted'
  const value = 'type-body-sm type-money m-0 text-ink'
  return (
    <div className="mt-lg">
      <p className="type-body-sm mb-xs text-ink">Z-report</p>
      <dl className="m-0">
        {Object.entries(r.sales_by_driver).map(([driver, amount]) => (
          <div key={driver} className={row}><dt className={label}>Sales — {driver}</dt><dd className={value}>{fm(amount)}</dd></div>
        ))}
        {Object.entries(r.refunds_by_driver).map(([driver, amount]) => (
          <div key={driver} className={row}><dt className={label}>Refunds — {driver}</dt><dd className={value}>−{fm(amount)}</dd></div>
        ))}
        <div className={row}><dt className={label}>Paid in</dt><dd className={value}>{fm(r.movements.paid_in)}</dd></div>
        <div className={row}><dt className={label}>Payouts</dt><dd className={value}>−{fm(r.movements.payout)}</dd></div>
        <div className={row}><dt className={label}>Drops</dt><dd className={value}>−{fm(r.movements.drop)}</dd></div>
        <div className={row}><dt className={label}>Orders closed</dt><dd className={value}>{r.orders_closed}</dd></div>
        <div className={row}><dt className={label}>Orders voided</dt><dd className={value}>{r.orders_voided}</dd></div>
        <div className={row}><dt className={label}>Orders split</dt><dd className={value}>{r.orders_split}</dd></div>
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

export function CloseShiftScreen({ shiftId, can, onClosed, onCancel, onSessionExpired }: {
  shiftId: string
  can: (permission: string) => boolean
  onClosed: (result: ShiftCloseResult) => void
  onCancel: () => void
  onSessionExpired: (err?: unknown) => void
}) {
  const zReport = useZReport(shiftId)   // while the session is still alive — see ZReportPanel
  const [counted, setCounted] = useState('')

  // 401 anywhere clears the staff session and returns to PIN — including this fetch.
  useEffect(() => {
    if (zReport.error instanceof ApiError && zReport.error.status === 401) onSessionExpired(zReport.error)
  }, [zReport.error, onSessionExpired])
  const [result, setResult] = useState<ShiftCloseResult | null>(null)
  // Blind count (Task 13): presentation state only, flipped the moment the close result
  // comes back — never gates the Z-report fetch above, which still runs at mount exactly
  // as before (the session dies at close, so it has to happen while it's still alive).
  const [revealed, setRevealed] = useState(false)
  const [error, setError] = useState<string | null>(null)
  // Minted once for the life of this screen and reused across submits, so a re-click
  // after a lost-response timeout replays the same close instead of risking a second one.
  const [idempotencyKey] = useState(() => crypto.randomUUID())

  // A supervisor sign-off on the result plate's variance, once `result.requires_approval`
  // comes back true. Tracked separately from `result.shift` (which is a snapshot from the
  // close, before any approval) so a successful approve can swap the warning for a line
  // naming who signed off without re-fetching anything.
  const [approvedShift, setApprovedShift] = useState<Shift | null>(null)
  const approveVariance = useMutation({
    mutationFn: () => api.approveVariance(shiftId),
    onSuccess: (shift) => {
      setApprovedShift(shift)
      setError(null)
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 401) {
        onSessionExpired(err)
        return
      }
      setError(err instanceof ApiError ? err.message : 'Could not approve the variance.')
    },
  })

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    const amount = parseCentsOrNull(counted)
    if (amount === null) return setError('Enter the counted cash, like 487.50')
    try {
      const closeResult = await api.closeShift(shiftId, amount, idempotencyKey)
      setResult(closeResult)
      setRevealed(true)
    } catch (err) {
      if (err instanceof ApiError && err.status === 401) {
        onSessionExpired(err)
        return
      }
      setError(err instanceof ApiError ? err.message : 'Could not close the shift.')
    }
  }

  if (result) {
    const row = 'flex items-center justify-between gap-md border-b border-hairline py-xs'
    return (
      <Card
        className={cn(
          // Variance is a semantic state — the 3px top rule is Carbon's inline-notification
          // accent (green = balanced, red = over/short), the only color on the plate.
          'mx-auto mt-xxl w-full max-w-[480px] border-t-[3px]',
          result.variance_cents === 0 ? 'border-t-success' : 'border-t-error'
        )}
      >
        <CardHeader>
          <CardTitle>Drawer reconciled</CardTitle>
        </CardHeader>
        <CardContent>
          <dl className="m-0">
            <div className={row}><dt className="type-body-sm text-ink-muted">Expected</dt><dd className="type-body-lg type-money m-0 text-ink">{revealed ? fm(result.expected_cash_cents) : MASK}</dd></div>
            <div className={row}><dt className="type-body-sm text-ink-muted">Counted</dt><dd className="type-body-lg type-money m-0 text-ink">{fm(result.shift.counted_cash_cents ?? 0)}</dd></div>
            <div className={row}><dt className="type-body-sm text-ink-muted">Variance</dt><dd className="type-body-lg type-money m-0 text-ink">{revealed ? fm(result.variance_cents) : MASK}</dd></div>
          </dl>
          {result.requires_approval && (
            approvedShift?.variance_approved_at ? (
              <p className="type-body-sm mt-md text-ink-muted">
                {/* Authoritative state is the response: variance_approved_at non-null is
                    what gates this line, and its timestamp is what's shown — the API never
                    resolves variance_approved_by (a user id) to a name, so the name here is
                    tokens.staffUser()'s own display-only garnish (the currently-signed-in
                    supervisor is who just clicked Approve), not something the server told us. */}
                Variance approved by {tokens.staffUser()?.name ?? 'supervisor'} at{' '}
                {new Date(approvedShift.variance_approved_at).toLocaleTimeString()}
              </p>
            ) : (
              <>
                <p className="type-body-sm mt-md text-error">Variance exceeds the threshold — needs supervisor approval.</p>
                {can('shift.approve_variance') && (
                  <Button
                    type="button" size="lg" className="mt-sm w-full"
                    disabled={approveVariance.isPending}
                    onClick={() => approveVariance.mutate()}
                  >
                    {approveVariance.isPending ? 'Approving…' : 'Approve variance'}
                  </Button>
                )}
              </>
            )
          )}
          {error && <p className="type-body-sm mt-md text-error">{error}</p>}
          <ZReportPanel z={zReport} />
          <div className="mt-lg flex gap-sm">
            <Button variant="ghost" size="lg" className="flex-1" onClick={() => window.print()}>Print</Button>
            <Button size="lg" className="flex-1" onClick={() => onClosed(result)}>Done</Button>
          </div>
        </CardContent>
      </Card>
    )
  }

  return (
    <Card className="mx-auto mt-xxl w-full max-w-[420px]">
      <CardHeader>
        <CardTitle>Close shift — count the drawer</CardTitle>
      </CardHeader>
      <CardContent>
        <form onSubmit={submit} className="flex flex-col gap-lg">
          <label className="block">
            <span className="type-body-sm text-ink-muted">Counted cash</span>
            <Input
              value={counted} onChange={(e) => setCounted(e.target.value)} inputMode="decimal" autoFocus
              className="type-money mt-xs h-[56px] text-[24px]"
            />
          </label>
          <div className="flex gap-sm">
            <Button type="submit" size="lg" className="flex-1">Close</Button>
            <Button type="button" variant="secondary" size="lg" className="flex-1" onClick={onCancel}>Back</Button>
          </div>
        </form>
        {/* Blind count: the count field above is what the cashier sees first and acts on;
            this is just a standing reminder that expected cash stays hidden until they do. */}
        {zReport.data && (
          <p className="type-body-sm mt-md text-ink-muted">
            Expected cash: <span className="type-money tracking-[2px]">{revealed ? fm(zReport.data.expected_cash_cents) : MASK}</span>
          </p>
        )}
        {error && <p className="type-body-sm mt-md text-error">{error}</p>}
      </CardContent>
    </Card>
  )
}
