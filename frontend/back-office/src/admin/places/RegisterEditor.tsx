'use client'

import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { ApiError, api, type IssuedActivationCode, type Location, type Register, type RegisterActivation } from '../../lib/api'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { Divider } from '../../components/Divider'
import { FieldRow } from '../../components/FieldRow'
import { StatusPill, type StatusPillTone } from '../../components/StatusPill'
import { Button } from '../../components/ui/button'
import { Card, CardTitle } from '../../components/ui/card'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select'

const ACTIVATION_TONE: Record<RegisterActivation['state'], StatusPillTone> = {
  enrolled: 'success',
  code_pending: 'info',
  code_expired: 'warning',
  not_enrolled: 'neutral',
}

function activationLabel(activation: RegisterActivation): string {
  switch (activation.state) {
    case 'enrolled': return 'Enrolled'
    case 'code_pending': return `Code pending — expires ${activation.code_expires_at?.slice(0, 10) ?? ''}`
    case 'code_expired': return 'Code expired'
    case 'not_enrolled': return 'Not enrolled'
  }
}

/**
 * Name, mode (retail/food chips), active toggle, and the ISSUE ACTIVATION CODE action.
 * `location_id` is a create-time-only decision (UpdateRegisterRequest marks it
 * `prohibited` on PATCH — see the comment there), so the location picker only shows up
 * for a brand-new register; editing one shows its location as plain text.
 *
 * Issuing a code revokes the register's device token AND its staff sessions in the same
 * transaction (IssueActivationCode.php) — confirmed behind a warning because the till
 * holding the old credential goes dark the instant this succeeds — and the fresh code is
 * shown exactly once in a copy-me panel. It lives only in this component's state: never
 * written to the cache, never persisted, gone the moment this editor closes.
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
  const [issuedCode, setIssuedCode] = useState<IssuedActivationCode | null>(null)
  // Archive-style confirm (brief's global constraint) — set only when Save would
  // otherwise deactivate; the dialog's Confirm re-plays the exact body already computed.
  const [pendingDeactivate, setPendingDeactivate] = useState<Record<string, unknown> | null>(null)
  const [pendingIssue, setPendingIssue] = useState(false)

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

  const issue = useMutation({
    mutationFn: () => api.registers.issueActivationCode(register?.id ?? ''),
    onSuccess: (code) => {
      setIssuedCode(code)
      setError(null)
      invalidate()
    },
    onError: (err) => fail(err, 'Could not issue an activation code.'),
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
          <div className="mb-md flex items-center justify-between gap-md">
            <CardTitle>Activation</CardTitle>
            <StatusPill tone={ACTIVATION_TONE[register.activation.state]}>
              {activationLabel(register.activation)}
            </StatusPill>
          </div>
          <p className="type-body-sm text-ink-muted mb-md">
            Issuing a new activation code locks this terminal out immediately; it comes back
            when someone enters the new code on the till.
          </p>
          <Button type="button" variant="secondary" disabled={issue.isPending} onClick={() => setPendingIssue(true)}>
            {issue.isPending ? 'Issuing…' : 'Issue activation code'}
          </Button>
          {issuedCode && (
            <Card elevated className="mt-md">
              <p className="type-body-sm text-ink-muted mb-xs">
                Activation code — single use, valid for 7 days. Copy it now, it will not be shown again:
              </p>
              <code className="type-money block select-all break-all text-ink">{issuedCode.activation_code}</code>
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
        open={pendingIssue}
        onOpenChange={setPendingIssue}
        message={`Issue a new activation code for ${register?.name ?? 'this register'}? The current till goes dark immediately.`}
        confirmLabel="Issue code"
        destructive
        onConfirm={() => {
          setPendingIssue(false)
          issue.mutate()
        }}
      />
    </Card>
  )
}
