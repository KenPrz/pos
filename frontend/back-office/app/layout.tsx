import type { Metadata } from 'next'
import type { ReactNode } from 'react'
import '../src/index.css'
import '../src/styles/carbon.css'
import { Providers } from './providers'

export const metadata: Metadata = {
  title: 'POS — Back Office',
  description: 'Back-office administration console',
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
