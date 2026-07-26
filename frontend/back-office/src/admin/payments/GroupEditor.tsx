'use client'

import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { ApiError, api, type Location, type PaymentDriverCode, type PaymentMethodGroup } from '../../lib/api'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { FieldRow } from '../../components/FieldRow'
import { Button } from '../../components/ui/button'
import { Card, CardTitle } from '../../components/ui/card'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'

const CODE_RE = /^[A-Z0-9_]+$/

/**
 * A group is the behavioural bucket: it names ONE driver, and its methods are variants
 * that behave identically. `code` and `driver` are immutable after create — changing
 * either would change how every method under it behaves and retroactively re-bucket
 * history — so on edit they render read-only rather than disappearing.
 */
export function GroupEditor({
  group,
  location,
  onDone,
  onCancel,
  onUnauthorized,
}: {
  group: PaymentMethodGroup | null
  location: Location
  onDone: () => void
  onCancel: () => void
  onUnauthorized: () => void
}) {
  const queryClient = useQueryClient()
  const [code, setCode] = useState(group?.code ?? '')
  const [name, setName] = useState(group?.name ?? '')
  const [driver, setDriver] = useState<PaymentDriverCode>(group?.driver ?? 'cash')
  const [sortOrder, setSortOrder] = useState(String(group?.sort_order ?? 0))
  const [isActive, setIsActive] = useState(group?.is_active ?? true)
  const [error, setError] = useState<string | null>(null)
  const [pendingArchive, setPendingArchive] = useState<Record<string, unknown> | null>(null)

  const save = useMutation({
    mutationFn: (body: Record<string, unknown>) =>
      group ? api.paymentMethodGroups.update(group.id, body) : api.paymentMethodGroups.create(body),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'payment-method-groups', location.id] })
      onDone()
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 401) return onUnauthorized()
      setError(err instanceof ApiError ? err.message : 'Could not save the group.')
    },
  })

  const normalizedCode = code.trim().toUpperCase()
  const codeInvalid = !group && !CODE_RE.test(normalizedCode)

  const submit = (event: FormEvent) => {
    event.preventDefault()
    setError(null)
    if (codeInvalid) return setError('A group code is letters, digits and underscores, like EWALLET.')
    if (name.trim() === '') return setError('Give the group a name.')

    // Only what the server accepts: an update never sends code or driver.
    const body: Record<string, unknown> = group
      ? { name: name.trim(), sort_order: Number(sortOrder) || 0, is_active: isActive }
      : {
          location_id: location.id,
          code: normalizedCode,
          name: name.trim(),
          driver,
          sort_order: Number(sortOrder) || 0,
          is_active: isActive,
        }

    // Archiving a group hides every method under it — worth confirming out loud.
    if (group?.is_active && !isActive) return setPendingArchive(body)
    save.mutate(body)
  }

  return (
    <Card>
      <CardTitle>{group ? `Group ${group.code}` : 'New group'}</CardTitle>
      <form onSubmit={submit} className="flex flex-col gap-md pt-md">
        {/* FieldRow wraps its children in the <label> itself — no htmlFor/id needed, and
            getByLabelText still finds the input through that implicit association. */}
        <FieldRow label="Group code">
          <Input
            value={group ? group.code : code}
            onChange={(e) => setCode(e.target.value)}
            readOnly={group !== null}
            placeholder="EWALLET"
          />
        </FieldRow>

        <FieldRow label="Name">
          <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="E-wallets" />
        </FieldRow>

        <FieldRow label="Driver">
          {group ? (
            <Input value={driverLabel(group.driver)} readOnly />
          ) : (
            <select
              className="w-full rounded-none border-0 border-b border-hairline bg-surface-1 px-md py-[11px] text-[16px] leading-[1.5] tracking-[0.16px] text-ink outline-none focus:border-b-2 focus:border-primary"
              value={driver}
              onChange={(e) => setDriver(e.target.value as PaymentDriverCode)}
            >
              <option value="cash">Cash — opens the drawer, computes change, refundable</option>
              <option value="external_card">External card — recorded only, not refundable here</option>
            </select>
          )}
        </FieldRow>

        <FieldRow label="Sort order">
          <Input value={sortOrder} onChange={(e) => setSortOrder(e.target.value)} inputMode="numeric" />
        </FieldRow>

        <label className="flex items-center gap-sm">
          <Checkbox checked={isActive} onCheckedChange={(v) => setIsActive(v === true)} />
          <span className="type-body-sm">Active</span>
        </label>

        {error && <p className="type-body-sm text-error">{error}</p>}

        <div className="flex gap-sm">
          <Button type="submit" disabled={save.isPending}>
            {save.isPending ? 'Saving…' : 'Save'}
          </Button>
          <Button type="button" variant="tertiary" onClick={onCancel}>
            Cancel
          </Button>
        </div>
      </form>

      {/* ConfirmDialog takes a single `message` (it renders as the DialogTitle), and
          cancelling is onOpenChange(false) — there is no separate onCancel. */}
      <ConfirmDialog
        open={pendingArchive !== null}
        onOpenChange={(open) => {
          if (!open) setPendingArchive(null)
        }}
        message="Archive this group? Every method in it stops appearing at the till. Their rows are kept, so reactivating the group brings them all back."
        confirmLabel="Archive"
        destructive
        onConfirm={() => {
          const body = pendingArchive
          setPendingArchive(null)
          if (body) save.mutate(body)
        }}
      />
    </Card>
  )
}

export function driverLabel(driver: PaymentDriverCode): string {
  return driver === 'cash' ? 'Cash' : 'External card'
}
