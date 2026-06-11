# syntax=docker/dockerfile:1.6
# ─────────────────────────────────────────────────────────────────────────────
# Punto Panel Next — React + Next.js 16 (standalone output)
#
# Container separado del stack PHP (api/app/panel legacy). Coolify lo deploya
# como recurso aparte con subdomain panel.punto.la (ver context/12-panel-rewrite.md).
#
# Build context: este directorio (panel-next/), NO el root del repo.
#
# Uso: docker build -t punto-panel-next .
# ─────────────────────────────────────────────────────────────────────────────

# ─── Stage 1: install deps ───────────────────────────────────────────────────
FROM node:22-alpine AS deps

WORKDIR /app

COPY package.json package-lock.json ./
# `--include=dev` fuerza la instalación de devDependencies incluso si
# Coolify (u otro runner) inyecta NODE_ENV=production al docker build.
# Sin esto, `@tailwindcss/postcss` y `typescript` no se instalan y el
# `next build` del stage siguiente revienta con "Cannot find module".
RUN npm ci --ignore-scripts --include=dev


# ─── Stage 2: build ──────────────────────────────────────────────────────────
FROM node:22-alpine AS builder

WORKDIR /app

COPY --from=deps /app/node_modules ./node_modules
COPY . .

# El API URL público se inlinea en el bundle del cliente en build time.
# Coolify pasa este ARG; default = api.punto.la para builds locales.
ARG NEXT_PUBLIC_API_URL=https://api.punto.la
ENV NEXT_PUBLIC_API_URL=${NEXT_PUBLIC_API_URL}

RUN npm run build


# ─── Stage 3: runtime ────────────────────────────────────────────────────────
FROM node:22-alpine AS runner

WORKDIR /app

ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1
ENV PORT=3000
ENV HOSTNAME=0.0.0.0

RUN addgroup --system --gid 1001 nodejs \
 && adduser --system --uid 1001 nextjs

# `output: 'standalone'` produce un bundle mínimo en .next/standalone con su
# propio node_modules. Public y .next/static se copian aparte (Next no los
# incluye en standalone — son archivos servidos directamente).
COPY --from=builder --chown=nextjs:nodejs /app/.next/standalone ./
COPY --from=builder --chown=nextjs:nodejs /app/.next/static ./.next/static
COPY --from=builder --chown=nextjs:nodejs /app/public ./public

USER nextjs

EXPOSE 3000

CMD ["node", "server.js"]
