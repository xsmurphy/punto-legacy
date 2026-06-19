import { parsePhoneNumber, type CountryCode } from "libphonenumber-js"
import { DEFAULT_COUNTRY } from "@/lib/countries"

/**
 * Convierte un número de teléfono a formato nacional para mostrar al usuario.
 * Acepta E.164 ("+595981234567") o formato nacional ("0981 234 567").
 * Si el parsing falla (número parcial, inválido), retorna el input tal cual.
 *
 * Convención del proyecto: front muestra nacional, backend almacena E.164.
 */
export function formatPhone(
  phone: string | null | undefined,
  fallback: CountryCode = DEFAULT_COUNTRY,
): string {
  if (!phone) return ""
  try {
    return parsePhoneNumber(phone, fallback).formatNational()
  } catch {
    return phone
  }
}
