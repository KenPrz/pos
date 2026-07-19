import * as React from 'react'
import { cn } from '@/lib/utils'

// Carbon text input — surface-1 fill, no side borders, 1px bottom hairline; focus
// swaps to a 2px primary bottom border; aria-invalid swaps to 2px error. Radius 0.
const Input = React.forwardRef<HTMLInputElement, React.InputHTMLAttributes<HTMLInputElement>>(
  ({ className, ...props }, ref) => {
    return (
      <input
        ref={ref}
        className={cn(
          'w-full rounded-none border-0 border-b border-hairline bg-surface-1 px-md py-[11px]',
          'text-[16px] leading-[1.5] tracking-[0.16px] text-ink placeholder:text-ink-subtle',
          'outline-none focus:border-b-2 focus:border-primary',
          'aria-invalid:border-b-2 aria-invalid:border-error',
          'disabled:pointer-events-none disabled:opacity-50',
          className
        )}
        {...props}
      />
    )
  }
)
Input.displayName = 'Input'

export { Input }
