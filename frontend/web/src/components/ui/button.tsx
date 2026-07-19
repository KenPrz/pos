import * as React from 'react'
import { cva, type VariantProps } from 'class-variance-authority'
import { cn } from '@/lib/utils'

// Carbon button — five variants, radius 0, no shadow, 2px primary focus outline.
// Paddings/colors per DESIGN.md's button component specs.
const buttonVariants = cva(
  'inline-flex items-center justify-center gap-xs whitespace-nowrap rounded-none font-normal ' +
    'transition-colors outline-none disabled:pointer-events-none disabled:opacity-50 ' +
    'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
  {
    variants: {
      variant: {
        primary: 'bg-primary text-on-primary hover:bg-blue-hover active:bg-blue-80',
        secondary: 'bg-ink text-inverse-ink hover:opacity-90',
        tertiary: 'bg-canvas text-primary border border-primary hover:bg-surface-1',
        ghost: 'bg-transparent text-primary hover:bg-surface-1',
        danger: 'bg-error text-on-primary hover:opacity-90',
      },
      size: {
        default: 'px-md py-sm text-[14px] leading-[1.29] tracking-[0.16px]',
        lg: 'px-md py-sm min-h-[56px] text-[14px] leading-[1.29] tracking-[0.16px]',
        xl: 'px-lg py-sm min-h-[64px] text-[18px] leading-[1.5] tracking-normal',
      },
    },
    defaultVariants: {
      variant: 'primary',
      size: 'default',
    },
  }
)

export interface ButtonProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement>,
    VariantProps<typeof buttonVariants> {}

const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
  ({ className, variant, size, ...props }, ref) => {
    return (
      <button ref={ref} className={cn(buttonVariants({ variant, size }), className)} {...props} />
    )
  }
)
Button.displayName = 'Button'

export { Button, buttonVariants }
