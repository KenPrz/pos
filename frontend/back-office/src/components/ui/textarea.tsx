import * as React from 'react'
import { cn } from '@/lib/utils'

// Same chrome as Input, multi-line.
const Textarea = React.forwardRef<HTMLTextAreaElement, React.TextareaHTMLAttributes<HTMLTextAreaElement>>(
  ({ className, ...props }, ref) => {
    return (
      <textarea
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
Textarea.displayName = 'Textarea'

export { Textarea }
