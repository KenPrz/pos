import path from 'node:path'
import { defineConfig } from 'vitest/config'

// Resolves the `@/*` alias declared in tsconfig.json (Next.js reads that config
// natively; vitest/vite do not, so this mirrors it by hand). No other config here —
// environment is set per-file via the `@vitest-environment jsdom` pragma.
export default defineConfig({
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
})
