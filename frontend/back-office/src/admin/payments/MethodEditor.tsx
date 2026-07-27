'use client'

import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { ApiError, api, type Location, type PaymentMethod, type PaymentMethodGroup } from '../../lib/api'
import { FieldRow } from '../../components/FieldRow'
import { Button } from '../../components/ui/button'
import { Card, CardTitle } from '../../components/ui/card'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { driverLabel } from './GroupEditor'

const CODE_RE = /^[A-Z0-9_]+$/

/**
 * A method is a NAME for behaviour its group already defines. `code` and the group are
 * immutable after create: the code is a wire identifier and a report key, and moving a
 * method between groups would change its driver and re-bucket its history.
 */
export function MethodEditor({
  method,
  group,
  location,
  onDone,
  onCancel,
  onUnauthorized,
}: {
  method: PaymentMethod | null
  group: PaymentMethodGroup
  location: Location
  onDone: () => void
  onCancel: () => void
  onUnauthorized: () => void
}) {
  const queryClient = useQueryClient()
  const [code, setCode] = useState(method?.code ?? '')
  const [name, setName] = useState(method?.name ?? '')
  const [sortOrder, setSortOrder] = useState(String(method?.sort_order ?? 0))
  const [isActive, setIsActive] = useState(method?.is_active ?? true)
  const [error, setError] = useState<string | null>(null)

  const save = useMutation({
    mutationFn: (body: Record<string, unknown>) =>
      method ? api.paymentMethods.update(method.id, body) : api.paymentMethods.create(body),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'payment-method-groups', location.id] })
      onDone()
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 401) return onUnauthorized()
      setError(err instanceof ApiError ? err.message : 'Could not save the method.')
    },
  })

  const normalizedCode = code.trim().toUpperCase()
  const codeInvalid = !method && !CODE_RE.test(normalizedCode)

  const submit = (event: FormEvent) => {
    event.preventDefault()
    setError(null)
    if (codeInvalid) return setError('A method code is letters, digits and underscores, like GCASH.')
    if (name.trim() === '') return setError('Give the method a name.')

    save.mutate(
      method
        ? { name: name.trim(), sort_order: Number(sortOrder) || 0, is_active: isActive }
        : {
            location_id: location.id,
            group_id: group.id,
            code: normalizedCode,
            name: name.trim(),
            sort_order: Number(sortOrder) || 0,
            is_active: isActive,
          },
    )
  }

  return (
    <Card>
      <CardTitle>{method ? `Method ${method.code}` : `New method in ${group.name}`}</CardTitle>
      <p className="type-body-sm text-ink-muted">
        Behaves as {driverLabel(group.driver)}, because that is what the {group.code} group is.
      </p>
      <form onSubmit={submit} className="flex flex-col gap-md pt-md">
        {/* FieldRow supplies the <label> wrapper — no htmlFor/id. */}
        <FieldRow label="Method code">
          <Input
            value={method ? method.code : code}
            onChange={(e) => setCode(e.target.value)}
            readOnly={method !== null}
            placeholder="GCASH"
          />
        </FieldRow>

        <FieldRow label="Name">
          <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="GCash" />
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
    </Card>
  )
}
