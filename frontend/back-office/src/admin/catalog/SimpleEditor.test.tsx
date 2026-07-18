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
  // leave — so it must never pop the confirm, and Cancel on that confirm must not
  // silently block the create.
  it('does not confirm when creating a new record with Active unchecked', () => {
    const confirmSpy = vi.spyOn(window, 'confirm')
    const onSave = vi.fn()
    render(<SimpleEditor title="New tax rate" fields={FIELDS} initial={null} saving={false} onSave={onSave} onCancel={vi.fn()} />)

    fireEvent.change(screen.getByLabelText(/name/i), { target: { value: 'VAT' } })
    fireEvent.click(screen.getByLabelText(/active/i)) // unchecks (checkbox defaults true on create)
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    expect(confirmSpy).not.toHaveBeenCalled()
    expect(onSave).toHaveBeenCalledWith(expect.objectContaining({ name: 'VAT', is_active: false }))
  })

  // Editing an existing row IS an archive when Active flips to false — confirm still
  // gates that path (this is the case the archive-confirm fix was actually for).
  it('confirms before archiving an existing record', () => {
    vi.spyOn(window, 'confirm').mockReturnValue(false)
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

    expect(window.confirm).toHaveBeenCalledWith(expect.stringContaining('Archive'))
    expect(onSave).not.toHaveBeenCalled()
  })
})
