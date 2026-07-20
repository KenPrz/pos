// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ActivationScreen } from './ActivationScreen'
import { ApiError } from '../lib/api'

afterEach(cleanup)

describe('ActivationScreen', () => {
  it('activates with the entered code and reports success', async () => {
    const activate = vi.fn(async () => ({}))
    const onActivated = vi.fn()
    render(<ActivationScreen activate={activate} onActivated={onActivated} />)

    fireEvent.change(screen.getByLabelText(/activation code/i), { target: { value: 'ABCDE-FGH23' } })
    fireEvent.click(screen.getByRole('button', { name: /activate/i }))

    await waitFor(() => expect(onActivated).toHaveBeenCalled())
    expect(activate).toHaveBeenCalledWith('ABCDE-FGH23')
  })

  it('shows the server message when the code is rejected, and re-enables the form', async () => {
    const activate = vi.fn(async () => {
      throw new ApiError('invalid_activation_code', 'That activation code is not valid.', 401)
    })
    render(<ActivationScreen activate={activate} onActivated={vi.fn()} />)

    fireEvent.change(screen.getByLabelText(/activation code/i), { target: { value: 'WRONG-WRONG' } })
    fireEvent.click(screen.getByRole('button', { name: /activate/i }))

    expect(await screen.findByText('That activation code is not valid.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /activate/i })).toBeEnabled()
  })

  it('shows the disabled banner with the exact lockout copy, form still available', () => {
    render(<ActivationScreen disabled activate={vi.fn()} onActivated={vi.fn()} />)

    expect(
      screen.getByText('Your activation code has been disabled. Please contact an admin and request a new activation code.'),
    ).toBeInTheDocument()
    expect(screen.getByLabelText(/activation code/i)).toBeInTheDocument()
  })
})
