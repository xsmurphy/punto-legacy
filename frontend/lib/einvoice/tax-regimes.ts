/**
 * Catálogos CERRADOS del alta del emisor electrónico: régimen tributario y
 * tipo de contribuyente.
 *
 * Viven juntos y en un solo lugar porque los consumen DOS superficies que no
 * se pueden contradecir: el formulario de Configuración → Facturación
 * electrónica y el asistente (`provision_einvoice`). El agente manda estos
 * campos como NÚMEROS, así que sin el catálogo a la vista no tiene forma de
 * saber que "Régimen Contable" es el 8 — y el literal repetido en dos lados es
 * exactamente cómo dos superficies terminan declarando cosas distintas.
 *
 * ── Regímenes tributarios (`tipoRegimen` del documento electrónico) ─────────
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

/**
 * Tipo de contribuyente (`TaxpayerType` del alta del emisor).
 *
 * Dos valores y nada más — pero estaban tipeados a mano dentro del JSX del
 * formulario, así que el agente no tenía de dónde sacarlos. Sin default, por el
 * mismo motivo que el régimen: es una condición fiscal declarada, no un campo
 * que se pueda suponer por el rubro.
 */
export const SIFEN_TAXPAYER_TYPES: readonly TaxRegime[] = [
  { code: 1, label: "Persona física" },
  { code: 2, label: "Persona jurídica" },
] as const

/** Etiqueta del tipo de contribuyente guardado, para la vista del emisor ya provisionado. */
export function taxpayerTypeLabel(code: number | undefined | null): string | null {
  if (typeof code !== "number") return null
  return SIFEN_TAXPAYER_TYPES.find((t) => t.code === code)?.label ?? `Tipo ${code}`
}
