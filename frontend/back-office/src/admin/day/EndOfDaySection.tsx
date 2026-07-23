'use client'

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { EmptyState } from '../../components/EmptyState'
import { FieldRow } from '../../components/FieldRow'
import { SectionHeader } from '../../components/SectionHeader'
import { StatusPill } from '../../components/StatusPill'
import { Button } from '../../components/ui/button'
import { Card, CardTitle } from '../../components/ui/card'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { ApiError, api, type BusinessDayStatus } from '../../lib/api'
import { getCurrency } from '../../lib/currency'
import { cents, formatMoney, parseCentsOrNull } from '../../lib/money'
import { MoneyField } from '../catalog/MoneyField'

// display only; the server owns all arithmetic
const fm = (n: number) => formatMoney(cents(n), getCurrency())

/** True when a query genuinely failed — a 401 is handled separately (`onUnauthorized`). */
function failed(query: { isError: boolean; error: unknown }): boolean {
  return query.isError && !(query.error instanceof ApiError && query.error.status === 401)
}

/**
 * End of Day (Task 11, RBAC v2): the consolidated close-day workflow for one location +
 * business date. Gated behind `day.close` by `Shell` before this ever mounts.
 *
 * `ConfirmDialog` (the real component) takes `message`/`onOpenChange`/`onConfirm` and no
 * `children` — the reopen REASON can't live inside the dialog the way an earlier draft
 * assumed, so it's captured as an inline `Input` in the closed-day panel instead, kept in
 * state, and the "Reopen day" button stays disabled until it's non-empty; the dialog then
 * only has to confirm the already-captured reason.
 *
 * `date` state (final-review FIX A): `null` means "let the server pick" — the browser's
 * own clock is never a source of truth for a business date (every business date in this
 * system is the LOCATION's local day, and the browser's UTC offset from it can put the
 * two calendar days apart). `null` skips the `date` query param entirely, so the very
 * first fetch lands on `GetBusinessDayRequest`'s own default: the location's local today
 * (`now($tz)->toDateString()`). Once that response lands, the date picker's displayed
 * value falls back to `status.business_date` (what the server actually resolved), and its
 * `max` is pinned to `status.location_today` — the location's current local date,
 * returned on every response regardless of which date was queried, so `max` never drifts
 * even after the user has picked an earlier date. Switching location resets `date` back
 * to `null` so the next fetch re-derives both from the new location's own clock.
 */
export function EndOfDaySection({
  locationId,
  isAdmin,
  onUnauthorized,
}: {
  locationId: string | null
  isAdmin: boolean
  onUnauthorized: () => void
}) {
  const [date, setDate] = useState<string | null>(null)
  const [depositInput, setDepositInput] = useState('')
  const [cashDrop, setCashDrop] = useState(false)
  const [spoilage, setSpoilage] = useState('')
  const [nextDayNote, setNextDayNote] = useState('')
  const [note, setNote] = useState('')
  const [confirmClose, setConfirmClose] = useState(false)
  const [confirmReopen, setConfirmReopen] = useState(false)
  const [reopenReason, setReopenReason] = useState('')
  const [closeError, setCloseError] = useState<string | null>(null)
  const [reopenError, setReopenError] = useState<string | null>(null)
  const queryClient = useQueryClient()

  // A new location has its own clock and its own selected date never carries over —
  // re-derive from scratch (see the class doc above).
  useEffect(() => {
    setDate(null)
  }, [locationId])

  const query = useQuery({
    queryKey: ['admin', 'day', locationId, date],
    queryFn: () => api.day.get(locationId as string, date ?? undefined),
    enabled: locationId !== null,
  })

  useEffect(() => {
    if (query.error instanceof ApiError && query.error.status === 401) onUnauthorized()
  }, [query.error, onUnauthorized])

  // Checklist/deposit form state is local, not derived from the fetched record — reset it
  // whenever the selected date or location changes so a value typed for one day never
  // gets carried over (and possibly submitted) against a different one.
  useEffect(() => {
    setDepositInput('')
    setCashDrop(false)
    setSpoilage('')
    setNextDayNote('')
    setNote('')
    setReopenReason('')
    setCloseError(null)
    setReopenError(null)
  }, [date, locationId])

  const closeMutation = useMutation({
    mutationFn: () =>
      api.day.close(locationId as string, {
        date: date ?? undefined,
        deposit_cents: parseCentsOrNull(depositInput) ?? 0,
        checklist: { cash_drop_confirmed: cashDrop, spoilage_note: spoilage, next_day_note: nextDayNote },
        note: note === '' ? null : note,
      }),
    onMutate: () => setCloseError(null),
    onSuccess: () => {
      setCloseError(null)
      queryClient.invalidateQueries({ queryKey: ['admin', 'day', locationId] })
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 401) return onUnauthorized()
      setCloseError(err instanceof ApiError ? err.message : 'Could not close the day.')
    },
  })

  const reopenMutation = useMutation({
    mutationFn: () => api.day.reopen(locationId as string, { date: date ?? undefined, reason: reopenReason }),
    onMutate: () => setReopenError(null),
    onSuccess: () => {
      setReopenReason('')
      setReopenError(null)
      queryClient.invalidateQueries({ queryKey: ['admin', 'day', locationId] })
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 401) return onUnauthorized()
      setReopenError(err instanceof ApiError ? err.message : 'Could not reopen the day.')
    },
  })

  if (locationId === null) {
    return <EmptyState title="Select a location" description="Pick a location to close its day." />
  }

  const status: BusinessDayStatus | undefined = query.data
  const record = status?.record ?? null
  const closed = record !== null
  const depositInvalid = depositInput !== '' && parseCentsOrNull(depositInput) === null
  const pickerDate = date ?? status?.business_date ?? ''

  return (
    <div className="flex flex-col gap-lg">
      <SectionHeader
        title="End of Day"
        subline="Review the day's totals, then close it out."
        action={
          <Input
            type="date"
            value={pickerDate}
            max={status?.location_today}
            aria-label="Business date"
            onChange={(e) => setDate(e.target.value)}
          />
        }
      />

      {query.isLoading && <p className="type-body-sm text-ink-muted">Loading…</p>}
      {failed(query) && <p className="type-body-sm text-error">Could not load the day's status.</p>}

      {status && (
        <>
          <div className="flex flex-wrap items-center gap-md">
            {closed && <StatusPill tone="success">Day closed</StatusPill>}
            {!closed && status.open_shifts.length > 0 && (
              <StatusPill tone="warning">{status.open_shifts.length} open shift(s) — close them first</StatusPill>
            )}
            {!closed && status.open_orders_count > 0 && (
              <StatusPill tone="warning">{status.open_orders_count} open order(s)</StatusPill>
            )}
            {!closed && status.unapproved_variance_count > 0 && (
              <StatusPill tone="info">{status.unapproved_variance_count} unapproved variance(s)</StatusPill>
            )}
          </div>

          <Card>
            <CardTitle className="mb-md">Consolidated totals</CardTitle>
            <div className="grid grid-cols-2 gap-md md:grid-cols-3">
              <FieldRow label="Net sales">{fm(status.totals.net_sales_cents)}</FieldRow>
              <FieldRow label="Tax">{fm(status.totals.tax_cents)}</FieldRow>
              <FieldRow label="Expected cash">{fm(status.totals.expected_cash_cents)}</FieldRow>
              <FieldRow label="Counted cash">{fm(status.totals.counted_cash_cents)}</FieldRow>
              <FieldRow label="Variance">{fm(status.totals.variance_cents)}</FieldRow>
              <FieldRow label="Shifts">{status.totals.shift_count}</FieldRow>
            </div>
          </Card>

          {closed && record ? (
            <Card>
              <CardTitle className="mb-md">Close checklist</CardTitle>
              <div className="flex flex-col gap-md">
                <FieldRow label="Deposit">{fm(record.deposit_cents)}</FieldRow>
                <FieldRow label="Cash drop confirmed">{record.checklist.cash_drop_confirmed ? 'Yes' : 'No'}</FieldRow>
                <FieldRow label="Spoilage / waste">{record.checklist.spoilage_note ?? '—'}</FieldRow>
                <FieldRow label="Note for tomorrow">{record.checklist.next_day_note ?? '—'}</FieldRow>
                <FieldRow label="Note">{record.note ?? '—'}</FieldRow>

                {isAdmin && (
                  <>
                    <FieldRow label="Reason for reopening">
                      <Input
                        value={reopenReason}
                        placeholder="Why is this day being reopened?"
                        aria-label="Reason for reopening"
                        onChange={(e) => setReopenReason(e.target.value)}
                      />
                    </FieldRow>
                    <div>
                      <Button
                        type="button"
                        variant="secondary"
                        disabled={reopenReason.trim() === '' || reopenMutation.isPending}
                        onClick={() => setConfirmReopen(true)}
                      >
                        {reopenMutation.isPending ? 'Reopening…' : 'Reopen day'}
                      </Button>
                    </div>
                    {reopenError && <p className="type-body-sm text-error">{reopenError}</p>}
                  </>
                )}
              </div>
            </Card>
          ) : (
            <Card>
              <CardTitle className="mb-md">Close checklist</CardTitle>
              <div className="flex flex-col gap-md">
                <FieldRow label="Cash drop confirmed">
                  <Checkbox checked={cashDrop} onCheckedChange={(checked) => setCashDrop(Boolean(checked))} />
                </FieldRow>
                <MoneyField
                  id="day-deposit"
                  label="Deposit"
                  value={depositInput}
                  onChange={setDepositInput}
                  invalid={depositInvalid}
                />
                <FieldRow label="Spoilage / waste">
                  <Input value={spoilage} onChange={(e) => setSpoilage(e.target.value)} />
                </FieldRow>
                <FieldRow label="Note for tomorrow">
                  <Input value={nextDayNote} onChange={(e) => setNextDayNote(e.target.value)} />
                </FieldRow>
                <FieldRow label="Note">
                  <Input value={note} onChange={(e) => setNote(e.target.value)} />
                </FieldRow>
                <div>
                  <Button
                    type="button"
                    disabled={!status.closable || depositInvalid || closeMutation.isPending}
                    onClick={() => setConfirmClose(true)}
                  >
                    {closeMutation.isPending ? 'Closing…' : 'Close day'}
                  </Button>
                </div>
                {closeError && <p className="type-body-sm text-error">{closeError}</p>}
              </div>
            </Card>
          )}
        </>
      )}

      <ConfirmDialog
        open={confirmClose}
        onOpenChange={setConfirmClose}
        message="Close this business day? This freezes the day's totals and blocks new shifts until an admin reopens it."
        confirmLabel="Close day"
        onConfirm={() => {
          if (closeMutation.isPending) return
          setConfirmClose(false)
          closeMutation.mutate()
        }}
      />
      <ConfirmDialog
        open={confirmReopen}
        onOpenChange={setConfirmReopen}
        message="Reopen this business day? Shifts will be able to open on this date again."
        confirmLabel="Reopen day"
        destructive
        onConfirm={() => {
          if (reopenMutation.isPending) return
          setConfirmReopen(false)
          reopenMutation.mutate()
        }}
      />
    </div>
  )
}
