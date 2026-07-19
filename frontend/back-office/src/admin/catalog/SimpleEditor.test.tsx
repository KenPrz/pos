// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { SimpleEditor, type FieldSpec } from './SimpleEditor'

afterEach(cleanup)

beforeEach(() => {
  vi.clearAllMocks()
})

// Mirrors TaxRatesPanel's field config — the one live caller with an `is_active` field.
const FIELDS: FieldSpec[] = [
  { key: 'name', label: 'Name', kind: 'text' },
  { key: 'rate_micros', label: 'Rate (%)', kind: 'percent' },
  { key: 'is_active', label: 'Active', kind: 'checkbox' },
]

describe('SimpleEditor', () => {
  // Follow-up fix: archiving is an edit-time concept. `initial === null` (creating a new
  // row) with Active unchecked isn't archiving anything — there's no prior active row to
  // leave — so it must never pop the confirm dialog, and Save must go straight through.
  it('does not confirm when creating a new record with Active unchecked', () => {
    const onSave = vi.fn()
    render(<SimpleEditor title="New tax rate" fields={FIELDS} initial={null} saving={false} onSave={onSave} onCancel={vi.fn()} />)

    fireEvent.change(screen.getByLabelText(/name/i), { target: { value: 'VAT' } })
    fireEvent.click(screen.getByLabelText(/active/i)) // unchecks (checkbox defaults true on create)
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(onSave).toHaveBeenCalledWith(expect.objectContaining({ name: 'VAT', is_active: false }))
  })

  // UI-rework rewrite (exception #3): the archive confirm moved from `window.confirm` to
  // `ConfirmDialog`, same copy, same cancel-blocks/confirm-proceeds semantics.
  it('cancelling the archive ConfirmDialog blocks the save', () => {
    const onSave = vi.fn()
    render(
      <SimpleEditor
        title="Edit tax rate"
        fields={FIELDS}
        initial={{ name: 'VAT', rate_micros: 200_000, is_active: true }}
        saving={false}
        onSave={onSave}
        onCancel={vi.fn()}
      />,
    )

    fireEvent.click(screen.getByLabelText(/active/i))
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    expect(screen.getByText('Archive VAT? It leaves the register catalog but stays in history.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(onSave).not.toHaveBeenCalled()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('confirming the archive ConfirmDialog proceeds with the save', () => {
    const onSave = vi.fn()
    render(
      <SimpleEditor
        title="Edit tax rate"
        fields={FIELDS}
        initial={{ name: 'VAT', rate_micros: 200_000, is_active: true }}
        saving={false}
        onSave={onSave}
        onCancel={vi.fn()}
      />,
    )

    fireEvent.click(screen.getByLabelText(/active/i))
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))
    fireEvent.click(screen.getByRole('button', { name: 'Archive' }))

    // `name` is untouched, so the PATCH-diff semantics correctly leave it out of the body.
    expect(onSave).toHaveBeenCalledWith({ is_active: false })
  })
})
