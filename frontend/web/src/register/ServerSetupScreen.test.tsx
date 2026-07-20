// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ServerSetupScreen } from './ServerSetupScreen'

// @testing-library/react's auto-cleanup never registers itself in this suite (see
// ModifierSheet.test.tsx), so DOM from one test's render() would otherwise still be
// present for the next — do it by hand.
afterEach(cleanup)

describe('ServerSetupScreen', () => {
  it('saves a valid address and reports success', async () => {
    const onConnected = vi.fn()
    const save = vi.fn(async () => {})
    const check = vi.fn(async () => true)

    render(<ServerSetupScreen onConnected={onConnected} save={save} check={check} />)

    fireEvent.change(screen.getByLabelText('Server address'), {
      target: { value: 'https://pos.example.com' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Connect' }))

    await waitFor(() => expect(onConnected).toHaveBeenCalled())
    expect(save).toHaveBeenCalledWith('https://pos.example.com')
  })

  it('does not save an address it cannot reach', async () => {
    const onConnected = vi.fn()
    const save = vi.fn(async () => {})
    const check = vi.fn(async () => false)

    render(<ServerSetupScreen onConnected={onConnected} save={save} check={check} />)

    fireEvent.change(screen.getByLabelText('Server address'), {
      target: { value: 'https://typo.example.com' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Connect' }))

    expect(await screen.findByText('Cannot reach that server. Check the address and try again.')).toBeTruthy()
    expect(save).not.toHaveBeenCalled()
    expect(onConnected).not.toHaveBeenCalled()
  })
})
