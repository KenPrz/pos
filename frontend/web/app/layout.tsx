import type { Metadata } from 'next'
import type { ReactNode } from 'react'
import '../src/index.css'
import { Providers } from './providers'

export const metadata: Metadata = {
  title: 'POS — Register',
  description: 'Point-of-sale register terminal',
}

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="en">
      <body>
        <Providers>{children}</Providers>
      </body>
    </html>
  )
}
