import { parsePhoneNumber, type CountryCode } from "libphonenumber-js"
import { resolvePhoneCountry, type TenantLocaleConfig } from "@/lib/tenant-locale"

/**
 * Convierte un número de teléfono a formato nacional para mostrar al usuario.
 * Acepta E.164 ("+595981234567") o formato nacional ("0981 234 567").
 * Si el parsing falla (número parcial, inválido), retorna el input tal cual.
 *
 * Convención del proyecto: front muestra nacional, backend almacena E.164.
 *
 * `fallback` es el país con el que se interpretan los números guardados SIN
 * prefijo internacional. Antes era `"PY"` fijo por default de la firma, así
 * que un teléfono brasileño en formato nacional salía crudo, sin formato.
 * Ahora NO hay default: quien tenga el bootstrap a mano pasa el país del
 * tenant (`formatPhoneForTenant`), y quien no lo tenga deja que
 * `libphonenumber-js` resuelva sola desde el prefijo del E.164 — que es el
 * caso normal, porque la BD guarda E.164.
 */
export function formatPhone(
  phone: string | null | undefined,
  fallback?: CountryCode,
): string {
  if (!phone) return ""
  try {
    return parsePhoneNumber(phone, fallback).formatNational()
  } catch {
    return phone
  }
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
