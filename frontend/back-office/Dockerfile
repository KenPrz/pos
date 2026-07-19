# syntax=docker/dockerfile:1

FROM node:24-alpine AS deps
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci

FROM node:24-alpine AS build
WORKDIR /app
COPY --from=deps /app/node_modules ./node_modules
COPY . .
ENV NEXT_TELEMETRY_DISABLED=1
RUN npm run build

FROM node:24-alpine AS runner
WORKDIR /app
ENV NODE_ENV=production NEXT_TELEMETRY_DISABLED=1 HOSTNAME=0.0.0.0 PORT=3000
RUN addgroup -S pos && adduser -S pos -G pos
COPY --from=build --chown=pos:pos /app/.next/standalone ./
COPY --from=build --chown=pos:pos /app/.next/static ./.next/static
COPY --from=build --chown=pos:pos /app/public ./public
USER pos
EXPOSE 3000
CMD ["node", "server.js"]

FROM node:24-alpine AS dev
WORKDIR /app
ENV NEXT_TELEMETRY_DISABLED=1
RUN addgroup -S pos && adduser -S pos -G pos && chown pos:pos /app
USER pos
# Source arrives via bind mount; node_modules via named volume (installed by the
# container on first boot — see compose command).
CMD ["sh", "-c", "npm install && npm run dev"]
