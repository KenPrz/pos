import type { NextConfig } from 'next'

const nextConfig: NextConfig = {
  // Type-checking is deliberately not Next's job here: the gate is `npm run
  // typecheck` (tsgo --noEmit, the native TS7 compiler), run locally and in CI.
  // The stable `typescript` devDep exists so Next's build-time TS detection is
  // satisfied — without it, Next falls back to a mid-build `npm install` that
  // breaks on CI runners. Keeping ignoreBuildErrors avoids double type-checking.
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
