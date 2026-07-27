import * as React from 'react'

export interface ActionZoneProps {
  children: React.ReactNode
}

// The register's fixed bottom action bar — the one place the stage's primary action
// lives (spec §register: action-zone bar 64px). Consumers pass ONE `Button size="xl"`
// (primary or danger) plus at most an optional ghost secondary; the bar stretches its
// children to fill the full width so the primary target is impossible to miss.
//
// `bottom-[var(--screen-keyboard-h,0px)]` rather than `bottom-0`: the on-screen keyboard
// dock (ScreenKeyboardHost) is also `position: fixed; bottom: 0`, so it would otherwise
// sit directly on top of this bar with no way for either fixed element to push the other
// out of the way. ScreenKeyboardHost publishes its own height on `--screen-keyboard-h`
// while open and removes the property on dismiss/unmount, so this bar rides up above the
// dock when it's open and falls back flush to the bottom of the screen when it's not.
export function ActionZone({ children }: ActionZoneProps) {
  return (
    <div className="fixed inset-x-0 bottom-[var(--screen-keyboard-h,0px)] z-40 flex min-h-[64px] items-stretch border-t border-hairline bg-canvas *:flex-1 print:hidden">
      {children}
    </div>
  )
}
