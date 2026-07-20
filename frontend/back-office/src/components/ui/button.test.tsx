// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { Button } from './button'

afterEach(cleanup)

describe('Button', () => {
  it.each([
    ['primary', 'bg-primary'],
    ['secondary', 'bg-ink'],
    ['tertiary', 'border-primary'],
    ['ghost', 'text-primary'],
    ['danger', 'bg-error'],
  ] as const)('renders the %s variant with its label and radius-0', (variant, colorClass) => {
    render(
      <Button variant={variant} onClick={() => {}}>
        Save
      </Button>
    )

    const button = screen.getByRole('button', { name: 'Save' })
    expect(button).toBeInTheDocument()
    expect(button.className).toContain('rounded-none')
    expect(button.className).toContain(colorClass)
  })

  it('supports the register-floor sizes', () => {
    render(
      <>
        <Button size="default">Default</Button>
        <Button size="lg">Large</Button>
        <Button size="xl">Extra large</Button>
      </>
    )

    expect(screen.getByRole('button', { name: 'Large' }).className).toContain('min-h-[56px]')
    expect(screen.getByRole('button', { name: 'Extra large' }).className).toContain('min-h-[64px]')
  })
})
