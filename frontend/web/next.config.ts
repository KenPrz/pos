import type { NextConfig } from 'next'

const nextConfig: NextConfig = {
  // This repo uses TypeScript 7 (the native compiler), whose API Next's built-in
  // type-check step can't drive — it misreads it as "typescript not installed" and
  // crashes the build worker. Type-checking still gates the build: `npm run typecheck`
  // runs tsc --noEmit directly and CI runs it alongside the tests.
  typescript: { ignoreBuildErrors: true },
  // Same single-origin trick the Vite proxy provided: the browser talks to Next, Next
  // forwards /api to Laravel, and CORS never enters the picture.
  async rewrites() {
    return [
      {
        source: '/api/:path*',
        destination: 'http://127.0.0.1:8000/api/:path*',
      },
    ]
  },
}

export default nextConfig
