import type { NextConfig } from 'next'

const nextConfig: NextConfig = {
  output: 'standalone',
  // Type-checking is deliberately not Next's job here: the gate is `npm run
  // typecheck` (tsgo --noEmit, the native TS7 compiler), run locally and in CI.
  // The stable `typescript` devDep exists so Next's build-time TS detection is
  // satisfied — without it, Next falls back to a mid-build `npm install` that
  // breaks on CI runners. Keeping ignoreBuildErrors avoids double type-checking.
  typescript: { ignoreBuildErrors: true },
  async rewrites() {
    return [
      {
        source: '/api/:path*',
        // Native dev talks to artisan/FrankenPHP on localhost; containers set
        // API_ORIGIN=http://api:8000. In prod the edge Caddy routes /api before
        // Next ever sees it — this rewrite is the dev-mode path.
        destination: `${process.env.API_ORIGIN ?? 'http://127.0.0.1:8000'}/api/:path*`,
      },
    ]
  },
}

export default nextConfig
