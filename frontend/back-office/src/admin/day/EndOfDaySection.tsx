'use client'

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useMemo, useState } from 'react'
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
import { isoDate } from '../../lib/date'
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
  const today = useMemo(() => isoDate(new Date()), [])
  const [date, setDate] = useState(today)
  const [depositInput, setDepositInput] = useState('')
  const [cashDrop, setCashDrop] = useState(false)
  const [spoilage, setSpoilage] = useState('')
  const [nextDayNote, setNextDayNote] = useState('')
  const [confirmClose, setConfirmClose] = useState(false)
  const [confirmReopen, setConfirmReopen] = useState(false)
  const [reopenReason, setReopenReason] = useState('')
  const queryClient = useQueryClient()

  const query = useQuery({
    queryKey: ['admin', 'day', locationId, date],
    queryFn: () => api.day.get(locationId as string, date),
    enabled: locationId !== null,
  })

  useEffect(() => {
    if (query.error instanceof ApiError && query.error.status === 401) onUnauthorized()
  }, [query.error, onUnauthorized])

  // Checklist/deposit form state is local, not derived from the fetched record — reset it
  // whenever the selected date changes so a value typed for one day never gets carried
  // over (and possibly submitted) against a different one.
  useEffect(() => {
    setDepositInput('')
    setCashDrop(false)
    setSpoilage('')
    setNextDayNote('')
    setReopenReason('')
  }, [date])

  const closeMutation = useMutation({
    mutationFn: () =>
      api.day.close(locationId as string, {
        date,
        deposit_cents: parseCentsOrNull(depositInput) ?? 0,
        checklist: { cash_drop_confirmed: cashDrop, spoilage_note: spoilage, next_day_note: nextDayNote },
      }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'day', locationId] }),
    onError: (err) => {
      if (err instanceof ApiError && err.status === 401) onUnauthorized()
    },
  })

  const reopenMutation = useMutation({
    mutationFn: () => api.day.reopen(locationId as string, { date, reason: reopenReason }),
    onSuccess: () => {
      setReopenReason('')
      queryClient.invalidateQueries({ queryKey: ['admin', 'day', locationId] })
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 401) onUnauthorized()
    },
  })

  if (locationId === null) {
    return <EmptyState title="Select a location" description="Pick a location to close its day." />
  }

  const status: BusinessDayStatus | undefined = query.data
  const record = status?.record ?? null
  const closed = record !== null
  const depositInvalid = depositInput !== '' && parseCentsOrNull(depositInput) === null

  return (
    <div className="flex flex-col gap-lg">
      <SectionHeader
        title="End of Day"
        subline="Review the day's totals, then close it out."
        action={
          <Input
            type="date"
            value={date}
            max={today}
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
                <FieldRow label="Spoilage / waste">{record.checklist.spoilage_note ?? '—'}</FieldRow>
                <FieldRow label="Note for tomorrow">{record.checklist.next_day_note ?? '—'}</FieldRow>

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
                        disabled={reopenReason.trim() === ''}
                        onClick={() => setConfirmReopen(true)}
                      >
                        Reopen day
                      </Button>
                    </div>
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
                <div>
                  <Button
                    type="button"
                    disabled={!status.closable || depositInvalid}
                    onClick={() => setConfirmClose(true)}
                  >
                    Close day
                  </Button>
                </div>
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
          setConfirmReopen(false)
          reopenMutation.mutate()
        }}
      />
    </div>
  )
}
