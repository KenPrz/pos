'use client'

import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { ApiError, api, type Location, type Register } from '../../lib/api'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { Divider } from '../../components/Divider'
import { FieldRow } from '../../components/FieldRow'
import { Button } from '../../components/ui/button'
import { Card, CardTitle } from '../../components/ui/card'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select'

/**
 * Name, mode (retail/food chips), active toggle, and the REISSUE TOKEN action.
 * `location_id` is a create-time-only decision (UpdateRegisterRequest marks it
 * `prohibited` on PATCH — see the comment there), so the location picker only shows up
 * for a brand-new register; editing one shows its location as plain text.
 *
 * Reissuing revokes every existing token for the register immediately
 * (ReissueDeviceToken.php) — confirmed behind a warning because the till holding the old
 * one goes dark the instant this succeeds — and the fresh token is shown exactly once in
 * a copy-me plate. It lives only in this component's state: never written to the cache,
 * never persisted, gone the moment this editor closes.
 */
export function RegisterEditor({
  register,
  locations,
  onDone,
  onCancel,
  onUnauthorized,
}: {
  register: Register | null
  locations: Location[]
  onDone: () => void
  onCancel: () => void
  onUnauthorized: () => void
}) {
  const queryClient = useQueryClient()
  const [locationId, setLocationId] = useState(register?.location_id ?? locations[0]?.id ?? '')
  const [name, setName] = useState(register?.name ?? '')
  const [mode, setMode] = useState<'retail' | 'food'>(register?.mode ?? 'retail')
  const [isActive, setIsActive] = useState(register?.is_active ?? true)
  const [error, setError] = useState<string | null>(null)
  const [reissuedToken, setReissuedToken] = useState<string | null>(null)
  // Archive-style confirm (brief's global constraint) — set only when Save would
  // otherwise deactivate; the dialog's Confirm re-plays the exact body already computed.
  const [pendingDeactivate, setPendingDeactivate] = useState<Record<string, unknown> | null>(null)
  const [pendingReissue, setPendingReissue] = useState(false)

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['admin', 'registers'] })
  const fail = (err: unknown, fallback: string) => {
    if (err instanceof ApiError && err.status === 401) return onUnauthorized()
    setError(err instanceof ApiError ? err.message : fallback)
  }

  const save = useMutation({
    mutationFn: (body: Record<string, unknown>) =>
      register ? api.registers.update(register.id, body) : api.registers.create(body),
    onSuccess: () => {
      invalidate()
      onDone()
    },
    onError: (err) => fail(err, 'Could not save the register.'),
  })

  const reissue = useMutation({
    mutationFn: () => api.registers.reissueToken(register?.id ?? ''),
    onSuccess: (token) => {
      setReissuedToken(token)
      setError(null)
    },
    onError: (err) => fail(err, 'Could not reissue the token.'),
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    setError(null)

    const body: Record<string, unknown> = {}
    const put = (key: string, value: unknown, original: unknown) => {
      if (register === null || value !== original) body[key] = value
    }
    if (register === null) body.location_id = locationId
    put('name', name, register?.name)
    put('mode', mode, register?.mode)
    if (register) put('is_active', isActive, register.is_active)

    // Deactivation behind a confirm (brief's global constraint) — same as every other
    // is_active:false transition in this app.
    if (body.is_active === false) {
      setPendingDeactivate(body)
      return
    }
    save.mutate(body)
  }

  return (
    <Card>
      <div className="mb-lg flex items-center justify-between gap-md">
        <CardTitle>{register ? 'Edit register' : 'New register'}</CardTitle>
        <Button type="button" variant="tertiary" onClick={onCancel}>
          Back
        </Button>
      </div>

      <form onSubmit={submit} className="flex flex-col gap-md">
        {register ? (
          <p className="type-body-sm text-ink-muted">
            Location: {locations.find((l) => l.id === register.location_id)?.name ?? register.location_id}
          </p>
        ) : (
          <FieldRow label="Location">
            <Select value={locationId} onValueChange={setLocationId}>
              <SelectTrigger id="register-location">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {locations.map((l) => (
                  <SelectItem key={l.id} value={l.id}>
                    {l.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </FieldRow>
        )}
        <FieldRow label="Name">
          <Input id="register-name" value={name} onChange={(e) => setName(e.target.value)} />
        </FieldRow>
        <div className="flex flex-col gap-xxs">
          <span className="type-body-sm text-ink-muted">Mode</span>
          {/* secondary (selected) vs tertiary (not) — a visual toggle without reaching
              for a second primary button; Save below stays this form's one primary action. */}
          <div className="flex gap-xs" role="group" aria-label="Mode">
            <Button
              type="button"
              variant={mode === 'retail' ? 'secondary' : 'tertiary'}
              aria-pressed={mode === 'retail'}
              onClick={() => setMode('retail')}
            >
              Retail
            </Button>
            <Button
              type="button"
              variant={mode === 'food' ? 'secondary' : 'tertiary'}
              aria-pressed={mode === 'food'}
              onClick={() => setMode('food')}
            >
              Food
            </Button>
          </div>
        </div>
        {register && (
          <FieldRow label="Active">
            <Checkbox checked={isActive} onCheckedChange={(checked) => setIsActive(Boolean(checked))} />
          </FieldRow>
        )}
        <div>
          <Button type="submit" variant="primary" disabled={save.isPending || (register === null && locationId === '')}>
            {save.isPending ? 'Saving…' : 'Save'}
          </Button>
        </div>
      </form>
      {error && <p className="type-body-sm mt-md text-error">{error}</p>}

      {register && (
        <>
          <Divider />
          <CardTitle className="mb-md">Device token</CardTitle>
          <p className="type-body-sm text-ink-muted mb-md">
            Lost or stolen terminal? Reissuing kills the old token immediately and mints a new one.
          </p>
          <Button type="button" variant="secondary" disabled={reissue.isPending} onClick={() => setPendingReissue(true)}>
            {reissue.isPending ? 'Reissuing…' : 'Reissue token'}
          </Button>
          {reissuedToken && (
            <Card elevated className="mt-md">
              <p className="type-body-sm text-ink-muted mb-xs">New token — copy it now, it will not be shown again:</p>
              <code className="type-money block select-all break-all text-ink">{reissuedToken}</code>
            </Card>
          )}
        </>
      )}

      <ConfirmDialog
        open={pendingDeactivate !== null}
        onOpenChange={(open) => {
          if (!open) setPendingDeactivate(null)
        }}
        message={`Deactivate ${name}? It can no longer clock in a shift.`}
        confirmLabel="Deactivate"
        destructive
        onConfirm={() => {
          if (!pendingDeactivate) return
          save.mutate(pendingDeactivate)
          setPendingDeactivate(null)
        }}
      />

      <ConfirmDialog
        open={pendingReissue}
        onOpenChange={setPendingReissue}
        message={`Reissue ${register?.name ?? 'this register'}'s token? The current till goes dark immediately.`}
        confirmLabel="Reissue"
        destructive
        onConfirm={() => {
          setPendingReissue(false)
          reissue.mutate()
        }}
      />
    </Card>
  )
}
