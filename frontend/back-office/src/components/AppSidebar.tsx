import { cn } from '@/lib/utils'
import { Badge } from './ui/badge'
import { Button } from './ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select'

export interface AppSidebarLocation {
  id: string
  name: string
  code: string
}

export interface AppSidebarNavItem {
  key: string
  label: string
  href: string
  count?: number
}

export interface AppSidebarNavSection {
  eyebrow: string
  items: AppSidebarNavItem[]
}

export interface AppSidebarUser {
  name: string
}

export interface AppSidebarProps {
  sections: AppSidebarNavSection[]
  active: string
  onNavigate: (key: string) => void
  location: AppSidebarLocation | null
  locations: AppSidebarLocation[]
  onLocationChange: (id: string) => void
  user: AppSidebarUser | null
  onSignOut: () => void
}

// Brand, LocationSwitcher, NavGroup/NavItem (eyebrow + 2px blue left indicator +
// optional count badge), footer user slot.
export function AppSidebar({
  sections,
  active,
  onNavigate,
  location,
  locations,
  onLocationChange,
  user,
  onSignOut,
}: AppSidebarProps) {
  return (
    <aside className="flex h-full w-[240px] shrink-0 flex-col border-r border-hairline bg-canvas">
      <div className="border-b border-hairline px-lg py-lg">
        <p className="type-card-title text-ink">POS</p>
        <p className="type-body-sm text-ink-muted">Back Office</p>
      </div>

      <div className="border-b border-hairline p-md">
        <Select value={location?.id} onValueChange={onLocationChange}>
          <SelectTrigger aria-label="Location">
            <SelectValue placeholder="Select a location">
              {location ? `${location.name} · ${location.code}` : undefined}
            </SelectValue>
          </SelectTrigger>
          <SelectContent>
            {locations.map((loc) => (
              <SelectItem key={loc.id} value={loc.id}>
                {loc.name} · {loc.code}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <nav className="flex-1 overflow-y-auto p-md" aria-label="Sections">
        {sections.map((section) => (
          <div key={section.eyebrow} className="mb-lg">
            <p className="type-caption px-xs pb-xs text-ink-subtle">{section.eyebrow}</p>
            <ul className="flex flex-col">
              {section.items.map((item) => {
                const isActive = item.key === active
                return (
                  <li key={item.key}>
                    <a
                      href={item.href}
                      aria-current={isActive ? 'page' : undefined}
                      onClick={(e) => {
                        // Hijack only unmodified left-clicks — modified/middle clicks
                        // keep native open-in-new-tab, which is why href is real.
                        if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return
                        e.preventDefault()
                        onNavigate(item.key)
                      }}
                      className={cn(
                        'flex w-full items-center justify-between gap-xs border-l-2 border-transparent',
                        'px-xs py-sm text-left type-body-sm text-ink-muted hover:bg-surface-1',
                        isActive && 'border-primary bg-surface-1 font-semibold text-ink'
                      )}
                    >
                      <span>{item.label}</span>
                      {typeof item.count === 'number' ? (
                        <Badge variant="info">{item.count}</Badge>
                      ) : null}
                    </a>
                  </li>
                )
              })}
            </ul>
          </div>
        ))}
      </nav>

      <div className="border-t border-hairline p-md">
        {user ? <p className="type-body-sm mb-xs text-ink">{user.name}</p> : null}
        <Button type="button" variant="secondary" onClick={onSignOut} className="w-full">
          Sign out
        </Button>
      </div>
    </aside>
  )
}
