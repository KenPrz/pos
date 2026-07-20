// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ConfirmDialog } from './ConfirmDialog'

afterEach(cleanup)

describe('ConfirmDialog', () => {
  it('renders the message', () => {
    render(
      <ConfirmDialog
        open
        onOpenChange={vi.fn()}
        message="Archive this product?"
        confirmLabel="Archive"
        onConfirm={vi.fn()}
      />
    )

    expect(screen.getByText('Archive this product?')).toBeInTheDocument()
  })

  it('cancel fires onOpenChange(false), not onConfirm', () => {
    const onOpenChange = vi.fn()
    const onConfirm = vi.fn()
    render(
      <ConfirmDialog
        open
        onOpenChange={onOpenChange}
        message="Archive this product?"
        confirmLabel="Archive"
        onConfirm={onConfirm}
      />
    )

    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(onOpenChange).toHaveBeenCalledWith(false)
    expect(onConfirm).not.toHaveBeenCalled()
  })

  it('confirm fires onConfirm', () => {
    const onOpenChange = vi.fn()
    const onConfirm = vi.fn()
    render(
      <ConfirmDialog
        open
        onOpenChange={onOpenChange}
        message="Archive this product?"
        confirmLabel="Archive"
        onConfirm={onConfirm}
      />
    )

    fireEvent.click(screen.getByRole('button', { name: 'Archive' }))

    expect(onConfirm).toHaveBeenCalledTimes(1)
  })

  it('uses a custom cancel label when given, defaulting to "Cancel" otherwise', () => {
    render(
      <ConfirmDialog
        open
        onOpenChange={vi.fn()}
        message="Deactivate this user?"
        confirmLabel="Deactivate"
        cancelLabel="Keep active"
        destructive
        onConfirm={vi.fn()}
      />
    )

    expect(screen.getByRole('button', { name: 'Keep active' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Deactivate' })).toBeInTheDocument()
  })
})
