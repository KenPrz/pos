// @vitest-environment jsdom
/**
 * The sheet's enforcement logic (required-first ordering, repeat-counts-toward-max,
 * CONFIRM gating) is the testable core of Task 11 — MenuGrid's rendering is thin and
 * covered by typecheck + manual verification instead, per the task brief.
 */
import '@testing-library/jest-dom/vitest'
import { render, screen, fireEvent, cleanup } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ModifierSheet } from './ModifierSheet'

// vitest doesn't run with `globals: true` in this project (see api.test.ts's own explicit
// imports), so @testing-library/react's auto-cleanup — which only registers itself when it
// finds a global `afterEach` — never fires. Without this, DOM from one test's render()
// leaks into the next and queries like getByRole start matching multiple elements.
afterEach(cleanup)

const groups = [
  { id: 'g-extras', name: 'Extras', min_select: 0, max_select: 2 },
  { id: 'g-milk', name: 'Milk', min_select: 1, max_select: 1 },
]
const modifiers = [
  { id: 'm-oat', group_id: 'g-milk', name: 'Oat', price_delta_cents: 60, position: 0 },
  { id: 'm-shot', group_id: 'g-extras', name: 'Extra shot', price_delta_cents: 75, position: 0 },
]

describe('ModifierSheet', () => {
  it('keeps CONFIRM disabled until required groups are satisfied, and orders required first', () => {
    const onConfirm = vi.fn()
    render(<ModifierSheet productName="Latte" groups={groups} modifiers={modifiers} onConfirm={onConfirm} onCancel={() => {}} />)

    const headings = screen.getAllByRole('heading').map((h) => h.textContent)
    expect(headings.indexOf('Milk')).toBeLessThan(headings.indexOf('Extras'))

    const confirm = screen.getByRole('button', { name: /add/i })
    expect(confirm).toBeDisabled()
    fireEvent.click(screen.getByRole('button', { name: /oat/i }))
    expect(confirm).toBeEnabled()
  })

  it('counts repeats toward max_select and passes repeated ids through', () => {
    const onConfirm = vi.fn()
    render(<ModifierSheet productName="Latte" groups={groups} modifiers={modifiers} onConfirm={onConfirm} onCancel={() => {}} />)
    fireEvent.click(screen.getByRole('button', { name: /oat/i }))
    fireEvent.click(screen.getByRole('button', { name: /extra shot/i }))
    fireEvent.click(screen.getByRole('button', { name: /extra shot/i }))
    fireEvent.click(screen.getByRole('button', { name: /extra shot/i })) // 3rd exceeds max 2 → ignored
    fireEvent.click(screen.getByRole('button', { name: /add/i }))
    expect(onConfirm).toHaveBeenCalledWith(['m-oat', 'm-shot', 'm-shot'])
  })

  it('calls onCancel when CANCEL is pressed', () => {
    const onCancel = vi.fn()
    render(<ModifierSheet productName="Latte" groups={groups} modifiers={modifiers} onConfirm={() => {}} onCancel={onCancel} />)
    fireEvent.click(screen.getByRole('button', { name: /cancel/i }))
    expect(onCancel).toHaveBeenCalled()
  })
})
