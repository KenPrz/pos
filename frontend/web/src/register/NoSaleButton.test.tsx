// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { NoSaleButton } from './NoSaleButton'

// @testing-library/react's auto-cleanup never registers itself in this suite (see
// SplitStrip.test.tsx et al.) — do it by hand or DOM from one test leaks into the next.
afterEach(cleanup)

describe('NoSaleButton', () => {
  it('asks the server first, and only then pulses the drawer', async () => {
    const authorize = vi.fn(async () => {})
    const pulse = vi.fn(async () => {})

    render(<NoSaleButton authorize={authorize} pulse={pulse} />)
    fireEvent.click(screen.getByRole('button', { name: 'No sale' }))
    fireEvent.change(screen.getByPlaceholderText('Reason for opening the drawer…'), {
      target: { value: 'Change for a twenty' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Open drawer' }))

    await waitFor(() => expect(pulse).toHaveBeenCalled())
    expect(authorize).toHaveBeenCalledWith('Change for a twenty')
  })

  it('does not pulse when the server refuses', async () => {
    const authorize = vi.fn(async () => {
      throw new Error('denied')
    })
    const pulse = vi.fn(async () => {})

    render(<NoSaleButton authorize={authorize} pulse={pulse} />)
    fireEvent.click(screen.getByRole('button', { name: 'No sale' }))
    fireEvent.change(screen.getByPlaceholderText('Reason for opening the drawer…'), {
      target: { value: 'Nope' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Open drawer' }))

    expect(await screen.findByText('Could not open the drawer.')).toBeTruthy()
    expect(pulse).not.toHaveBeenCalled()
  })

  it('will not submit an empty reason', () => {
    const authorize = vi.fn(async () => {})
    const pulse = vi.fn(async () => {})

    render(<NoSaleButton authorize={authorize} pulse={pulse} />)
    fireEvent.click(screen.getByRole('button', { name: 'No sale' }))
    fireEvent.click(screen.getByRole('button', { name: 'Open drawer' }))

    expect(authorize).not.toHaveBeenCalled()
  })
})
