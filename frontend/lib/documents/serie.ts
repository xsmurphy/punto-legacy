/**
 * Serie SIFEN (`dSerieNum`) de un talonario fiscal — mig 223.
 *
 * Dos letras mayúsculas (ej. `AA`) o vacía. Es OPCIONAL y NUNCA se precarga
 * (decisión del owner 2026-09-15): se completa solo cuando el sistema de
 * facturación anterior emitía con serie en ese punto de expedición, porque en
 * ese caso SIFEN exige la misma serie en todo documento posterior y sin ella
 * rechaza con `1110 — Serie informada incorrecta`.
 *
 * Es IDENTIDAD de la serie fiscal, igual que el timbrado y el punto: cambiarla
 * abre una numeración nueva (en SIFEN, AA → AB reinicia el correlativo).
 *
 * Espejo de `DocumentSeries::SERIE_PATTERN` / `normalizeSerie()` en PHP y del
 * CHECK `document_sequence_serie_format`. Si cambia uno, cambian los tres.
 */

export const SERIE_PATTERN = /^[A-Z]{2}$/

/** Serie tal como se guarda: sin espacios y en mayúsculas. No valida. */
export function normalizeSerie(value: string | null | undefined): string {
  return (value ?? "").trim().toUpperCase()
}

/**
 * Lo que queda de lo tipeado en el input: solo letras, en mayúsculas, máximo
 * dos. Así el campo no puede contener algo que el backend rechace por forma —
 * lo único que puede faltar es la segunda letra.
 */
export function sanitizeSerieInput(raw: string): string {
  return raw
    .toUpperCase()
    .replace(/[^A-Z]/g, "")
    .slice(0, 2)
}

/** ¿Es guardable? Vacía cuenta como válida: es "sin serie". */
export function isValidSerie(value: string | null | undefined): boolean {
  const serie = normalizeSerie(value)
  return serie === "" || SERIE_PATTERN.test(serie)
}
