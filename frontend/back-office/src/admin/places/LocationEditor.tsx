'use client'

import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { ApiError, api, type Location } from '../../lib/api'
import { parseCentsOrNull } from '../../lib/money'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { FieldRow } from '../../components/FieldRow'
import { Button } from '../../components/ui/button'
import { Card, CardTitle } from '../../components/ui/card'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { MoneyField } from '../catalog/MoneyField'

// A stock-quantity threshold, same numeric family `lib/money.ts`'s `quantity()` accepts
// (up to 3 decimal places) but non-negative only — a "below this, it's low" line never
// makes sense as a negative amount, unlike a signed money delta elsewhere in this app.
const QUANTITY_RE = /^\d+(\.\d{1,3})?$/

// Intl.supportedValuesOf('timeZone') is the canonical IANA zone list the runtime knows
// about — computed once at module load (it never changes mid-session) rather than per
// render, so every LocationEditor mount reuses the same datalist options.
const TIMEZONES = Intl.supportedValuesOf('timeZone')

/**
 * Name, code (create-only in practice — the server allows a PATCH but the brief calls
 * it required-on-create, so this only enforces the DB's NOT NULL/unique via the same
 * server error path as everything else), timezone, prices_include_tax, receipt
 * header/footer. Field names verified against AdminLocationResource.php.
 */
export function LocationEditor({
  location,
  onDone,
  onCancel,
  onUnauthorized,
}: {
  location: Location | null
  onDone: () => void
  onCancel: () => void
  onUnauthorized: () => void
}) {
  const queryClient = useQueryClient()
  const [name, setName] = useState(location?.name ?? '')
  const [code, setCode] = useState(location?.code ?? '')
  const [timezone, setTimezone] = useState(location?.timezone ?? '')
  const [pricesIncludeTax, setPricesIncludeTax] = useState(location?.prices_include_tax ?? false)
  const [receiptHeader, setReceiptHeader] = useState(location?.receipt_header ?? '')
  const [receiptFooter, setReceiptFooter] = useState(location?.receipt_footer ?? '')
  // Per-location threshold overrides (Task 8/11): raw string state, same optional-numeric
  // pattern as VariantEditor's cost field — empty is a real, valid "use the config
  // default" choice, distinct from an invalid non-empty value.
  const [varianceThresholdInput, setVarianceThresholdInput] = useState(
    location?.variance_approval_threshold_cents != null
      ? (location.variance_approval_threshold_cents / 100).toFixed(2)
      : '',
  )
  const [lowStockThresholdInput, setLowStockThresholdInput] = useState(location?.low_stock_threshold ?? '')
  const [isActive, setIsActive] = useState(location?.is_active ?? true)
  const [error, setError] = useState<string | null>(null)
  // Archive-style confirm (brief's global constraint) — set only when Save would
  // otherwise deactivate; the dialog's Confirm re-plays the exact body already computed.
  const [pendingDeactivate, setPendingDeactivate] = useState<Record<string, unknown> | null>(null)

  const save = useMutation({
    mutationFn: (body: Record<string, unknown>) =>
      location ? api.locations.update(location.id, body) : api.locations.create(body),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'locations'] })
      onDone()
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 401) return onUnauthorized()
      setError(err instanceof ApiError ? err.message : 'Could not save the location.')
    },
  })

  // Blank means "use the config default" (null on the wire); a non-empty value that
  // doesn't parse must block save rather than silently drop the threshold.
  const varianceThresholdCents = varianceThresholdInput === '' ? null : parseCentsOrNull(varianceThresholdInput)
  const varianceThresholdInvalid = varianceThresholdInput !== '' && varianceThresholdCents === null
  const lowStockThresholdInvalid = lowStockThresholdInput !== '' && !QUANTITY_RE.test(lowStockThresholdInput)

  const submit = (e: FormEvent) => {
    e.preventDefault()
    setError(null)

    if (timezone.trim() === '' || !TIMEZONES.includes(timezone)) {
      setError('Pick a timezone from the list.')
      return
    }

    if (varianceThresholdInvalid || lowStockThresholdInvalid) {
      setError('Enter a valid threshold (e.g. 25.00 or 5.000), or leave it blank for the default.')
      return
    }

    const body: Record<string, unknown> = {}
    const put = (key: string, value: unknown, original: unknown) => {
      if (location === null || value !== original) body[key] = value
    }
    put('name', name, location?.name)
    put('code', code, location?.code)
    put('timezone', timezone, location?.timezone)
    put('prices_include_tax', pricesIncludeTax, location?.prices_include_tax)
    put('receipt_header', receiptHeader || null, location?.receipt_header)
    put('receipt_footer', receiptFooter || null, location?.receipt_footer)
    put('variance_approval_threshold_cents', varianceThresholdCents, location?.variance_approval_threshold_cents ?? null)
    put(
      'low_stock_threshold',
      lowStockThresholdInput === '' ? null : lowStockThresholdInput,
      location?.low_stock_threshold ?? null,
    )
    if (location) put('is_active', isActive, location.is_active)

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
        <CardTitle>{location ? 'Edit location' : 'New location'}</CardTitle>
        <Button type="button" variant="tertiary" onClick={onCancel}>
          Back
        </Button>
      </div>

      <form onSubmit={submit} className="flex flex-col gap-md">
        <FieldRow label="Name">
          <Input id="location-name" value={name} onChange={(e) => setName(e.target.value)} />
        </FieldRow>
        <FieldRow label="Code">
          <Input id="location-code" value={code} onChange={(e) => setCode(e.target.value)} />
        </FieldRow>
        <FieldRow label="Timezone">
          <>
            <Input
              id="location-timezone"
              list="location-timezone-options"
              value={timezone}
              onChange={(e) => setTimezone(e.target.value)}
              placeholder="America/Chicago"
            />
            <datalist id="location-timezone-options">
              {TIMEZONES.map((tz) => (
                <option key={tz} value={tz} />
              ))}
            </datalist>
          </>
        </FieldRow>
        <FieldRow label="Prices include tax">
          <Checkbox checked={pricesIncludeTax} onCheckedChange={(checked) => setPricesIncludeTax(Boolean(checked))} />
        </FieldRow>
        <p className="type-body-sm text-ink-muted">
          Applies to future orders only — orders already open keep the pricing basis they started with.
        </p>
        <FieldRow label="Receipt header">
          <Input id="location-receipt-header" value={receiptHeader} onChange={(e) => setReceiptHeader(e.target.value)} />
        </FieldRow>
        <FieldRow label="Receipt footer">
          <Input id="location-receipt-footer" value={receiptFooter} onChange={(e) => setReceiptFooter(e.target.value)} />
        </FieldRow>
        <MoneyField
          id="location-variance-threshold"
          label="Variance approval threshold (optional)"
          value={varianceThresholdInput}
          onChange={setVarianceThresholdInput}
          invalid={varianceThresholdInvalid}
        />
        <FieldRow label="Low stock threshold (optional)">
          <Input
            id="location-low-stock-threshold"
            type="text"
            inputMode="decimal"
            placeholder="0.000"
            value={lowStockThresholdInput}
            aria-invalid={lowStockThresholdInvalid || undefined}
            onChange={(e) => setLowStockThresholdInput(e.target.value)}
          />
        </FieldRow>
        {location && (
          <FieldRow label="Active">
            <Checkbox checked={isActive} onCheckedChange={(checked) => setIsActive(Boolean(checked))} />
          </FieldRow>
        )}
        <div>
          <Button type="submit" variant="primary" disabled={save.isPending}>
            {save.isPending ? 'Saving…' : 'Save'}
          </Button>
        </div>
      </form>
      {error && <p className="type-body-sm mt-md text-error">{error}</p>}

      <ConfirmDialog
        open={pendingDeactivate !== null}
        onOpenChange={(open) => {
          if (!open) setPendingDeactivate(null)
        }}
        message={`Deactivate ${name}? Its history stays, but staff can no longer sign in there.`}
        confirmLabel="Deactivate"
        destructive
        onConfirm={() => {
          if (!pendingDeactivate) return
          save.mutate(pendingDeactivate)
          setPendingDeactivate(null)
        }}
      />
    </Card>
  )
}
