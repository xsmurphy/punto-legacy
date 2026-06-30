import * as Sentry from "@sentry/nextjs"

// Init server/edge Sentry. Gateado por SENTRY_DSN: si no hay DSN, no se
// inicializa nada (cero impacto). tracesSampleRate=0 → solo errores.
export function register() {
  const dsn = process.env.SENTRY_DSN
  if (!dsn) return

  if (process.env.NEXT_RUNTIME === "nodejs") {
    Sentry.init({
      dsn,
      environment: process.env.NODE_ENV,
      tracesSampleRate: 0,
    })
  }

  if (process.env.NEXT_RUNTIME === "edge") {
    Sentry.init({
      dsn,
      environment: process.env.NODE_ENV,
      tracesSampleRate: 0,
    })
  }
}

// Captura errores de Server Components / route handlers (Next 15+).
export const onRequestError = Sentry.captureRequestError
