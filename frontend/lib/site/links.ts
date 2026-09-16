/**
 * Destinos fuera del sitio de marketing.
 *
 * El sitio vive en `punto.la` y la aplicación en `app.punto.la` (mismo
 * container, distinto host — ver `middleware.ts`), así que estos links son
 * absolutos a propósito: un `/signup` relativo se quedaría en el sitio.
 */

export const APP_URL = (
  process.env.NEXT_PUBLIC_APP_URL ?? "https://app.punto.la"
).replace(/\/$/, "")

/** Alta de cuenta — destino de todos los CTA "Empezar". */
export const SIGNUP_URL = `${APP_URL}/signup`

/** Ingreso al panel. */
export const LOGIN_URL = `${APP_URL}/login`

/** Origen público del sitio de marketing — base de canónicas y sitemap. */
export const SITE_URL = (
  process.env.NEXT_PUBLIC_SITE_URL ?? "https://punto.la"
).replace(/\/$/, "")

/**
 * Origen público del sitio de ayuda (`docs.punto.la`) — base de canónicas,
 * sitemap y del redirect que saca la ruta interna `/ayuda` de los hosts del
 * panel (ver `middleware.ts`).
 */
export const DOCS_URL = (
  process.env.NEXT_PUBLIC_DOCS_URL ?? "https://docs.punto.la"
).replace(/\/$/, "")
