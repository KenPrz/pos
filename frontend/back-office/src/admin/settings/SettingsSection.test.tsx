// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { SettingsSection } from './SettingsSection'
import { ApiError, api, type Setting } from '../../lib/api'

afterEach(cleanup)

beforeEach(() => {
  vi.clearAllMocks()
})

vi.mock('../../lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, settings: { get: vi.fn(), update: vi.fn() } },
  }
})

const SETTINGS: Setting[] = [
  { key: 'business.name', value: 'Manila Trading', source: 'db' },
  { key: 'business.address', value: '123 Rizal Ave', source: 'config' },
  { key: 'business.tax_id', value: null, source: 'config' },
]

function renderSection(onUnauthorized = vi.fn()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <SettingsSection onUnauthorized={onUnauthorized} />
    </QueryClientProvider>,
  )
  return { onUnauthorized }
}

describe('SettingsSection', () => {
  it('renders the three registry fields seeded from api.settings.get', async () => {
    vi.mocked(api.settings.get).mockResolvedValue(SETTINGS)
    renderSection()

    expect(await screen.findByLabelText(/business name/i)).toHaveValue('Manila Trading')
    expect(screen.getByLabelText(/business address/i)).toHaveValue('123 Rizal Ave')
    expect(screen.getByLabelText(/tax id/i)).toHaveValue('')
  })

  it('surfaces the config source as helper text, and none for a db-set value', async () => {
    vi.mocked(api.settings.get).mockResolvedValue(SETTINGS)
    renderSection()

    await screen.findByLabelText(/business name/i)

    // business.address and business.tax_id are 'config' sourced — both get the helper
    // text; business.name is 'db' sourced (an existing override) and gets none.
    expect(screen.getAllByText(/from config — saving stores an override/i)).toHaveLength(2)
  })

  it('saves only the changed key', async () => {
    vi.mocked(api.settings.get).mockResolvedValue(SETTINGS)
    vi.mocked(api.settings.update).mockResolvedValue(SETTINGS)
    renderSection()

    await screen.findByLabelText(/business name/i)

    fireEvent.change(screen.getByLabelText(/business name/i), { target: { value: 'Cebu Trading' } })
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    await waitFor(() => expect(api.settings.update).toHaveBeenCalledWith({ 'business.name': 'Cebu Trading' }))
  })

  // Emptying a field must PATCH `null` (the server's "clear the override" signal), never
  // an empty string that would pin the value forever with no path back to config.
  it('clears an emptied field to null rather than an empty string', async () => {
    vi.mocked(api.settings.get).mockResolvedValue(SETTINGS)
    vi.mocked(api.settings.update).mockResolvedValue(SETTINGS)
    renderSection()

    await screen.findByLabelText(/business name/i)

    fireEvent.change(screen.getByLabelText(/business name/i), { target: { value: '' } })
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    await waitFor(() => expect(api.settings.update).toHaveBeenCalledWith({ 'business.name': null }))
  })

  it('a 401 while loading calls onUnauthorized', async () => {
    vi.mocked(api.settings.get).mockRejectedValue(new ApiError('unauthenticated', 'Unauthenticated.', 401))
    const { onUnauthorized } = renderSection()

    await waitFor(() => expect(onUnauthorized).toHaveBeenCalledTimes(1))
  })
})
