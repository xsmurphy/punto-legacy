import { parsePhoneNumber, type CountryCode } from "libphonenumber-js"
import { resolvePhoneCountry, type TenantLocaleConfig } from "@/lib/tenant-locale"

/**
 * Convierte un número de teléfono a formato nacional para mostrar al usuario.
 * Acepta las TRES formas que existen en el proyecto:
 *
 *   "595991742353"   → como lo guarda la BD: E.164 SIN el '+'
 *   "+595991742353"  → E.164 canónico (lo que viaja hacia afuera)
 *   "0991 742353"    → nacional, lo que tipea una persona
 *
 * Si el parsing falla (número parcial, inválido), devuelve el input tal cual.
 *
 * ── Por qué prueba con '+' antes que con el país ─────────────────────────────
 *
 * La convención del proyecto es que la BD guarda E.164 **sin** el '+'
 * (`normalizePhoneForStorage`, `feedback_phone_storage_no_plus`). A
 * `libphonenumber-js` eso le resulta indistinguible de un número nacional de un
 * país desconocido: `parsePhoneNumber("595991742353")` tira INVALID_COUNTRY, el
 * catch devolvía el crudo, y TODOS los listados de contactos, proveedores y
 * usuarios mostraban "595991742353" en vez de "0991 742353" (reporte del owner
 * 2026-08-28). El mismo agujero pintaba el teléfono crudo en el ticket impreso,
 * porque el resolver de bloques también pasa por acá.
 *
 * Re-agregar el '+' antes de parsear es lo que cierra el círculo de la
 * convención: la BD lo saca al guardar, esto lo repone al mostrar.
 *
 * Y va ANTES del país de fallback a propósito, no solo por orden de prueba: con
 * un argentino guardado ("5491123456789") interpretarlo como nacional del
 * tenant paraguayo no falla — devuelve el crudo, que es peor que fallar. Con
 * '+' sale "011 15-2345-6789", correcto y sin que el país del tenant tenga nada
 * que ver. Un número guardado ya trae su prefijo de país adentro; el `fallback`
 * es para lo OTRO, lo que tipea una persona en formato local.
 *
 * `fallback` no tiene default: quien tenga el bootstrap a mano pasa el país del
 * tenant (`formatPhoneForTenant`). Un "PY" cableado acá le rompería el formato
 * a cualquier tenant no paraguayo (`feedback_no_hardcodear_paraguay`).
 */
export function formatPhone(
  phone: string | null | undefined,
  fallback?: CountryCode,
): string {
  if (!phone) return ""

  const trimmed = phone.trim()
  if (trimmed === "") return phone

  for (const candidate of parseCandidates(trimmed, fallback)) {
    try {
      const parsed = parsePhoneNumber(candidate.value, candidate.country)
      if (parsed.isValid()) return parsed.formatNational()
    } catch {
      // Candidato inválido: se prueba el siguiente. El return crudo del final
      // es el único fallback.
    }
  }
  return phone
}

/**
 * Formas en las que vale la pena intentar interpretar el número, en orden.
 *
 * `isValid()` es lo que hace seguro probar varias: sin esa validación,
 * `parsePhoneNumber` acepta cosas que no son teléfonos y el primer candidato
 * ganaría siempre.
 */
function parseCandidates(
  phone: string,
  fallback?: CountryCode,
): { value: string; country?: CountryCode }[] {
  if (phone.startsWith("+")) return [{ value: phone }]

  const candidates: { value: string; country?: CountryCode }[] = []
  // E.164 sin '+' (cómo lo guarda la BD). El leading 0 lo excluye: ahí el 0 es
  // el prefijo de larga distancia nacional, no un código de país.
  if (/^[1-9]\d{6,14}$/.test(phone)) {
    candidates.push({ value: `+${phone}` })
  }
  if (fallback) candidates.push({ value: phone, country: fallback })
  return candidates
}

/**
 * Igual que `formatPhone`, pero tomando el país de referencia de la config del
 * tenant (`Bootstrap` en el panel, `PosConfig` en el POS).
 *
 * Usar esta variante siempre que el bootstrap esté disponible: es la que hace
 * que un tenant no-paraguayo vea sus teléfonos locales bien formateados.
 */
export function formatPhoneForTenant(
  phone: string | null | undefined,
  config: TenantLocaleConfig | null | undefined,
): string {
  return formatPhone(phone, resolvePhoneCountry(config))
}
