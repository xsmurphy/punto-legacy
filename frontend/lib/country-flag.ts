/**
 * Banderas de país/moneda — fuente única (funciones puras).
 *
 * EXCEPCIÓN DE EMOJIS PEDIDA POR EL OWNER (2026-08-25): la regla del proyecto
 * prohíbe emojis en UI (context/14 Regla #6, context/20 §5). Acá se usan a
 * pedido explícito del owner: "los montos en otras monedas deben aparecer con
 * sus banderas en emojis para identificar visualmente". El alcance de la
 * excepción es ESE: identificar visualmente una moneda. No la extiendas a
 * otros usos ni la "corrijas" sacando los emojis.
 *
 * Por qué existe el archivo: el mismo algoritmo estaba copiado tres veces
 * (`components/items/currency-price-field.tsx`, `app/(panel)/settings/page.tsx`
 * y `lib/countries.ts`), y el comentario de `currency-price-field.tsx` ya
 * pedía centralizarlo cuando apareciera un tercer consumer. Apareció.
 *
 * Este archivo es `.ts` a propósito, sin JSX: lo consume `lib/countries.ts`,
 * que a su vez cuelga de `lib/phone.ts` y de la cadena de impresión
 * (`lib/hardware/printers/*`). Los componentes viven en
 * `components/ui/country-flag.tsx`.
 */

/** Distancia entre 'A' (U+0041) y el regional indicator 🇦 (U+1F1E6). */
const REGIONAL_INDICATOR_OFFSET = 127397

/** Se muestra cuando no hay un país que represente al código. */
export const UNKNOWN_FLAG = "🌐"

/**
 * Bandera desde un código ISO 3166-1 alpha-2 ("PY" → 🇵🇾).
 *
 * Cada letra A-Z se mapea a un regional indicator symbol. El glyph final lo
 * decide el OS: si no tiene la bandera dibuja las dos letras, nunca falla.
 */
export function countryFlagEmoji(alpha2: string | null | undefined): string {
  const code = alpha2?.trim().toUpperCase()
  if (!code || !/^[A-Z]{2}$/.test(code)) return UNKNOWN_FLAG
  return code.replace(/./g, (c) =>
    String.fromCodePoint(REGIONAL_INDICATOR_OFFSET + c.charCodeAt(0)),
  )
}

/**
 * Bandera desde un código ISO 4217 ("USD" → 🇺🇸, "PYG" → 🇵🇾).
 *
 * Criterio para monedas sin un país único — es el estándar, no una invención:
 * ISO 4217 construye el código con las DOS primeras letras del país ISO 3166
 * emisor más una inicial de la moneda (PYG = PY + Guaraní, BRL = BR + Real).
 * Así que el país sale de los dos primeros caracteres, sin tabla que mantener.
 *
 * Los dos casos que no cierran con esa regla:
 *
 *  - **EUR** → 🇪🇺. "EU" no es un país sino un código ISO 3166 reservado, pero
 *    Unicode sí define la secuencia de regional indicators y los sistemas
 *    dibujan la bandera de la Unión Europea. Sale por el mismo camino que el
 *    resto, no necesita caso especial.
 *  - **Códigos supranacionales que arrancan con X** (XAF/XOF/XCD francos y
 *    dólar del Caribe, XAU/XAG metales, XDR derechos especiales de giro): la
 *    "X" marca justamente que NO hay país emisor. `countryFlagEmoji` los
 *    frenaría igual porque "XA"/"XO" no son países, pero se cortan explícito
 *    acá para dejar escrito el porqué. Muestran el globo.
 *
 * Un código inválido o vacío también cae en el globo — nunca una bandera
 * equivocada, que sería peor que ninguna.
 */
export function currencyFlagEmoji(currencyCode: string | null | undefined): string {
  const code = currencyCode?.trim().toUpperCase()
  if (!code || !/^[A-Z]{3}$/.test(code)) return UNKNOWN_FLAG
  if (code.startsWith("X")) return UNKNOWN_FLAG
  return countryFlagEmoji(code.slice(0, 2))
}

/** Nombre del país en español desde ISO 3166-1 alpha-2 ("PY" → "Paraguay"). */
export function countryName(alpha2: string | null | undefined): string {
  const code = alpha2?.trim()
  if (!code) return ""
  try {
    const dn = new Intl.DisplayNames(["es"], { type: "region" })
    return dn.of(code.toUpperCase()) || code
  } catch {
    return code
  }
}
