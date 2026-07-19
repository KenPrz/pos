// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { ActionZone } from './ActionZone'
import { Button } from './ui/button'

afterEach(cleanup)

describe('ActionZone', () => {
  it('renders the one xl primary action inside a fixed bottom bar', () => {
    render(
      <ActionZone>
        <Button size="xl">Take payment</Button>
      </ActionZone>
    )

    const button = screen.getByRole('button', { name: 'Take payment' })
    expect(button).toBeInTheDocument()
    // xl size: the 64px action-zone floor baked into the Button size class.
    expect(button.className).toContain('min-h-[64px]')

    const bar = button.parentElement as HTMLElement
    expect(bar.className).toContain('fixed')
    expect(bar.className).toContain('bottom-0')
    expect(bar.className).toContain('min-h-[64px]')
  })
})
