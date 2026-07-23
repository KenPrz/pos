import { describe, expect, it } from 'vitest'
import { SECTION_DEFS, parsePath, pathForSection, resolveSection, type Section } from './navigation'

describe('parsePath', () => {
  it('maps / to today with no rest', () => {
    expect(parsePath('/')).toEqual({ section: 'today', rest: [] })
  })

  it('round-trips every registered section path', () => {
    for (const section of Object.keys(SECTION_DEFS) as Section[]) {
      expect(parsePath(pathForSection(section))).toEqual({ section, rest: [] })
    }
  })

  it('extracts rest segments belonging to the section', () => {
    expect(parsePath('/reports/stock')).toEqual({ section: 'reports', rest: ['stock'] })
    expect(parsePath('/catalog/products/123')).toEqual({ section: 'catalog', rest: ['products', '123'] })
  })

  it('tolerates trailing slashes', () => {
    expect(parsePath('/catalog/')).toEqual({ section: 'catalog', rest: [] })
  })

  it('returns null for unknown slugs, including /today', () => {
    expect(parsePath('/nope').section).toBeNull()
    expect(parsePath('/today').section).toBeNull()
  })
})

describe('resolveSection', () => {
  it('resolves a held section', () => {
    expect(resolveSection('/settings', ['settings.manage'])).toBe('settings')
  })

  it('falls back to today for an unheld section', () => {
    expect(resolveSection('/settings', ['catalog.manage'])).toBe('today')
  })

  it('falls back to today for unknown slugs', () => {
    expect(resolveSection('/nope', ['catalog.manage'])).toBe('today')
  })

  it('today needs no permission', () => {
    expect(resolveSection('/', [])).toBe('today')
  })

  it('grants a composite section on ANY of its permissions (OR-semantics)', () => {
    expect(resolveSection('/users', ['role.manage'])).toBe('users')
    expect(resolveSection('/reports', ['report.stock.view'])).toBe('reports')
    expect(resolveSection('/locations', ['register.enroll'])).toBe('locations')
  })
})
