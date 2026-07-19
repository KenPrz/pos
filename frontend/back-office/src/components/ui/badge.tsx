import * as React from 'react'
import { cva, type VariantProps } from 'class-variance-authority'
import { cn } from '@/lib/utils'

// Badge is the ONE sanctioned pill in the system — every other primitive stays
// radius 0. Caption type, semantic color variants.
const badgeVariants = cva(
  'inline-flex items-center rounded-full px-xs py-xxs text-[12px] leading-[1.33] tracking-[0.32px] font-normal',
  {
    variants: {
      variant: {
        success: 'bg-success/15 text-success',
        warning: 'bg-warning/20 text-warning-ink',
        error: 'bg-error/15 text-error',
        info: 'bg-primary/15 text-primary',
        neutral: 'bg-surface-2 text-ink-muted',
      },
    },
    defaultVariants: { variant: 'neutral' },
  }
)

export interface BadgeProps extends React.HTMLAttributes<HTMLSpanElement>, VariantProps<typeof badgeVariants> {}

function Badge({ className, variant, ...props }: BadgeProps) {
  return <span className={cn(badgeVariants({ variant }), className)} {...props} />
}

export { Badge, badgeVariants }
