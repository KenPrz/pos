import * as React from 'react'
import * as DialogPrimitive from '@radix-ui/react-dialog'
import { cn } from '@/lib/utils'

// Sheet — right panel, square, hairline left border. Built on the same Radix Dialog
// primitive as Dialog (no separate Radix package): a Sheet is a Dialog anchored right.
const Sheet = DialogPrimitive.Root
const SheetTrigger = DialogPrimitive.Trigger
const SheetClose = DialogPrimitive.Close

const SheetOverlay = React.forwardRef<
  React.ComponentRef<typeof DialogPrimitive.Overlay>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Overlay>
>(({ className, ...props }, ref) => (
  <DialogPrimitive.Overlay
    ref={ref}
    className={cn('fixed inset-0 z-50 bg-[rgba(22,22,22,.5)]', className)}
    {...props}
  />
))
SheetOverlay.displayName = DialogPrimitive.Overlay.displayName

const SheetContent = React.forwardRef<
  React.ComponentRef<typeof DialogPrimitive.Content>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Content>
>(({ className, children, ...props }, ref) => (
  <DialogPrimitive.Portal>
    <SheetOverlay />
    <DialogPrimitive.Content
      ref={ref}
      className={cn(
        // Same collision as Dialog: carbon.css's custom `--spacing-sm` (12px) shadows
        // Tailwind's built-in 24rem container scale for a bare `max-w-sm` — an arbitrary
        // value sidesteps the theme lookup entirely and gets the intended width.
        'fixed inset-y-0 right-0 z-50 h-full w-full max-w-[24rem] overflow-y-auto',
        'rounded-none border-l border-hairline bg-canvas p-lg outline-none',
        className
      )}
      {...props}
    >
      {children}
    </DialogPrimitive.Content>
  </DialogPrimitive.Portal>
))
SheetContent.displayName = DialogPrimitive.Content.displayName

const SheetTitle = React.forwardRef<
  React.ComponentRef<typeof DialogPrimitive.Title>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Title>
>(({ className, ...props }, ref) => (
  <DialogPrimitive.Title ref={ref} className={cn('type-card-title text-ink', className)} {...props} />
))
SheetTitle.displayName = DialogPrimitive.Title.displayName

const SheetDescription = React.forwardRef<
  React.ComponentRef<typeof DialogPrimitive.Description>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Description>
>(({ className, ...props }, ref) => (
  <DialogPrimitive.Description className={cn('type-body-sm text-ink-muted', className)} ref={ref} {...props} />
))
SheetDescription.displayName = DialogPrimitive.Description.displayName

export { Sheet, SheetTrigger, SheetClose, SheetOverlay, SheetContent, SheetTitle, SheetDescription }
