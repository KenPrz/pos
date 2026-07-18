// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { LoginScreen } from './LoginScreen'
import { ApiError, api } from '../lib/api'

afterEach(cleanup)

// Same idiom as the register app's ShiftScreens.test.tsx: keep everything real
// (ApiError, adminToken) except the one endpoint this screen calls.
vi.mock('../lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      login: vi.fn(),
    },
  }
})

describe('LoginScreen', () => {
  it('renders email and password fields', () => {
    render(<LoginScreen onLoggedIn={vi.fn()} />)

    expect(screen.getByLabelText(/email/i)).toBeInTheDocument()
    expect(screen.getByLabelText(/password/i)).toBeInTheDocument()
  })

  it('submits the typed email and password to api.login', async () => {
    vi.mocked(api.login).mockResolvedValue({
      token: 'admin-token-abc',
      user: { id: 'user-1', name: 'Alex Admin', email: 'alex@example.com', is_admin: true },
    })
    render(<LoginScreen onLoggedIn={vi.fn()} />)

    fireEvent.change(screen.getByLabelText(/email/i), { target: { value: 'alex@example.com' } })
    fireEvent.change(screen.getByLabelText(/password/i), { target: { value: 'hunter2' } })
    fireEvent.click(screen.getByRole('button', { name: /sign in/i }))

    expect(api.login).toHaveBeenCalledWith('alex@example.com', 'hunter2')
  })

  it('shows the error envelope message when login fails', async () => {
    vi.mocked(api.login).mockRejectedValue(new ApiError('invalid_credentials', 'Invalid credentials.', 401))
    render(<LoginScreen onLoggedIn={vi.fn()} />)

    fireEvent.change(screen.getByLabelText(/email/i), { target: { value: 'alex@example.com' } })
    fireEvent.change(screen.getByLabelText(/password/i), { target: { value: 'wrong' } })
    fireEvent.click(screen.getByRole('button', { name: /sign in/i }))

    expect(await screen.findByText('Invalid credentials.')).toBeInTheDocument()
  })

  it('calls onLoggedIn with the session on success', async () => {
    const session = {
      token: 'admin-token-abc',
      user: { id: 'user-1', name: 'Alex Admin', email: 'alex@example.com', is_admin: true },
    }
    vi.mocked(api.login).mockResolvedValue(session)
    const onLoggedIn = vi.fn()
    render(<LoginScreen onLoggedIn={onLoggedIn} />)

    fireEvent.change(screen.getByLabelText(/email/i), { target: { value: 'alex@example.com' } })
    fireEvent.change(screen.getByLabelText(/password/i), { target: { value: 'hunter2' } })
    fireEvent.click(screen.getByRole('button', { name: /sign in/i }))

    await screen.findByRole('button', { name: /sign in/i })
    expect(onLoggedIn).toHaveBeenCalledWith(session)
  })
})
