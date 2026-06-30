import * as Sentry from "@sentry/nextjs"

// Init client-side Sentry. Gateado por NEXT_PUBLIC_SENTRY_DSN: si no hay DSN,
// no se inicializa (cero impacto). tracesSampleRate=0 → solo errores.
const dsn = process.env.NEXT_PUBLIC_SENTRY_DSN
if (dsn) {
  Sentry.init({
    dsn,
    environment: process.env.NODE_ENV,
    tracesSampleRate: 0,
  })
}

// Requerido por Sentry para instrumentar navegaciones del App Router.
export const onRouterTransitionStart = Sentry.captureRouterTransitionStart
