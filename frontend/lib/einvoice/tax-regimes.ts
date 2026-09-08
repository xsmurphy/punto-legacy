/**
 * Regímenes tributarios de SIFEN (`tipoRegimen` del documento electrónico).
 *
 * La lista sale del catálogo del motor de facturación (`tiposRegimenes` en
 * `constants.service.ts` de FE-PY, que es el que arma el XML), NO de una
 * interpretación de la normativa: son los ocho valores que el generador
 * reconoce. El validador del alta acepta el rango 1-15 —hay huecos
 * reservados—, así que el rango es más ancho que el catálogo y lo que se le
 * ofrece al comercio es el catálogo.
 *
 * Sin default a propósito: el régimen cambia cómo se declara el documento, y
 * elegirlo por el comercio es inventarle una condición fiscal. La pantalla
 * muestra un placeholder y el backend corta si no vino.
 */
export interface TaxRegime {
  code: number
  label: string
}

export const SIFEN_TAX_REGIMES: readonly TaxRegime[] = [
  { code: 1, label: "Régimen de Turismo" },
  { code: 2, label: "Importador" },
  { code: 3, label: "Exportador" },
  { code: 4, label: "Maquila" },
  { code: 5, label: "Ley N° 60/90" },
  { code: 6, label: "Régimen del Pequeño Productor" },
  { code: 7, label: "Régimen del Mediano Productor" },
  { code: 8, label: "Régimen Contable" },
] as const

/** Etiqueta del régimen guardado, para la vista del emisor ya provisionado. */
export function taxRegimeLabel(code: number | undefined | null): string | null {
  if (typeof code !== "number") return null
  return SIFEN_TAX_REGIMES.find((r) => r.code === code)?.label ?? `Régimen ${code}`
}
