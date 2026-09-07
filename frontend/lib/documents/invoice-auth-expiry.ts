/**
 * Vencimiento del TIMBRADO de la caja — umbrales y textos, en un solo lugar.
 *
 * Hermano de `timbrado-warning.ts`, que cubre el OTRO final del mismo talonario:
 * allá se acaban los NÚMEROS, acá se acaba el PLAZO. Los dos se comparten entre
 * el panel (badge en Control de Cajas, `registers-tab.tsx`) y el POS (el pill
 * único de estado y el CTA de cobro), por el mismo motivo: dos umbrales
 * distintos serían dos verdades, y el cajero y el dueño verían cosas que no
 * coinciden.
 *
 * ## Fechas puras, comparadas como strings
 *
 * El vencimiento es una FECHA del tenant (`YYYY-MM-DD`), sin hora y sin zona.
 * Todo acá compara los 10 primeros caracteres: los strings ISO ordenan igual
 * que las fechas y así no se cuela la TZ del browser en un dato que es del
 * comercio. El `today` lo pasa el caller — en el POS con
 * `tenantNow(config.timezone).slice(0, 10)`, que es la zona del comercio y
 * funciona sin conexión.
 *
 * ## Vence al TERMINAR su último día
 *
 * `days === 0` es "vence hoy" y la caja TODAVÍA puede facturar. Recién con
 * `days < 0` el timbrado está caído. Es la misma regla que aplica el servidor
 * (`InvoiceAuthGate::isExpiredOn()`, `api/lib/Sales/InvoiceAuthGate.php`), y
 * tiene que serlo: si el POS bloqueara un día antes, el cajero perdería un día
 * de facturación legal.
 *
 * ## Sin vencimiento cargado no pasa nada
 *
 * `null`/`""` ⇒ `"ok"` y sin bloqueo. Hay comercios que operan sin numeración
 * fiscal y un campo vacío no puede dejarlos sin caja. Mismo criterio que el
 * guard del servidor.
 */
import { formatDate } from "@/lib/format-date"

/** Desde acá el vencimiento se avisa en el indicador de estado del POS. */
export const INVOICE_AUTH_WARN_DAYS = 7

/** Desde acá el panel lo marca como "por vencer" en el listado de cajas. */
export const INVOICE_AUTH_PANEL_WARN_DAYS = 30

export type InvoiceAuthLevel = "ok" | "warn" | "expired"

/** Los 10 primeros caracteres si parecen `YYYY-MM-DD`; `null` si no. */
function day(value: string | null | undefined): string | null {
  const v = (value ?? "").trim()
  if (v === "") return null
  const d = v.slice(0, 10)
  return /^\d{4}-\d{2}-\d{2}$/.test(d) ? d : null
}

/**
 * Días desde `today` hasta el vencimiento. `0` = vence hoy (todavía sirve),
 * negativo = ya venció. `null` si falta alguno de los dos datos.
 *
 * `Date.UTC` y no `new Date(str)`: son fechas puras, y construirlas en la zona
 * local haría que un cambio de horario de verano corriera la cuenta un día.
 */
export function invoiceAuthDaysLeft(
  expiration: string | null | undefined,
  today: string,
): number | null {
  const exp = day(expiration)
  const now = day(today)
  if (exp === null || now === null) return null

  const toUTC = (s: string) => Date.UTC(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10))
  return Math.round((toUTC(exp) - toUTC(now)) / 86_400_000)
}

/**
 * Nivel del vencimiento contra `today`. `warnDays` existe porque el panel avisa
 * con más anticipación que la caja: el dueño puede iniciar el trámite con un
 * mes de aviso, al cajero un mes antes no le sirve de nada y solo le pinta un
 * indicador permanente que después deja de mirar.
 */
export function invoiceAuthLevel(
  expiration: string | null | undefined,
  today: string,
  warnDays: number = INVOICE_AUTH_WARN_DAYS,
): InvoiceAuthLevel {
  const days = invoiceAuthDaysLeft(expiration, today)
  if (days === null) return "ok"
  if (days < 0) return "expired"
  return days <= warnDays ? "warn" : "ok"
}

/**
 * Motivo por el que esta caja NO puede emitir, o `null` si puede.
 *
 * Es el texto del TOOLTIP del control de cobro, así que dice qué pasó, desde
 * cuándo, y dónde se arregla — en una línea. Lo consume
 * `lib/pos/emission-block.ts`.
 */
export function invoiceAuthBlockReason(
  expiration: string | null | undefined,
  today: string,
): string | null {
  if (invoiceAuthLevel(expiration, today) !== "expired") return null
  return `Timbrado vencido el ${formatDate(day(expiration) as string)} — renovalo en el panel, en la config de la caja`
}

/**
 * Texto del aviso de PROXIMIDAD para el indicador de estado del POS, o `null`
 * si no corresponde avisar. Nunca devuelve texto para un timbrado ya vencido:
 * eso es un IMPEDIMENTO y vive en el control que impide, no en el pill de
 * estado (regla del owner, ver el docblock de `offline-status-pill.tsx`).
 */
export function invoiceAuthNoticeLabel(
  expiration: string | null | undefined,
  today: string,
): string | null {
  const days = invoiceAuthDaysLeft(expiration, today)
  if (days === null || days < 0 || days > INVOICE_AUTH_WARN_DAYS) return null

  if (days === 0) return "El timbrado de esta caja vence hoy"
  if (days === 1) return "El timbrado de esta caja vence mañana"
  return `El timbrado de esta caja vence en ${days} días`
}
