/**
 * Ranking del command palette.
 *
 * cmdk trae de fábrica `command-score`, un matcher de SUBSECUENCIA: le alcanza
 * con que las letras de lo que escribís aparezcan en ese orden, salteando lo
 * que haga falta. Sobre un índice de ~150 rutas eso devuelve puntaje > 0 para
 * casi cualquier cosa, así que buscar "sucursales" listaba primero "Facturas
 * recurrentes" y dejaba "Sucursales" tercero. Un buscador que muestra de más
 * es peor que uno que muestra de menos: el usuario deja de confiar y vuelve al
 * menú.
 *
 * Acá el criterio es al revés — restrictivo y explicable:
 *
 *  1. CADA palabra que escribió el usuario tiene que aparecer en el título o
 *     en los sinónimos de la entrada. Si falta una, la entrada NO se muestra.
 *  2. "Aparecer" significa arrancar una palabra (`suc` → `Sucursales`), no
 *     estar desparramado por el texto. Desde 4 letras también vale adentro de
 *     una palabra (`cursal` → `Sucursales`), que es donde el prefijo solo se
 *     queda corto.
 *  3. Entre las que pasan, gana la que matchea en el TÍTULO antes que la que
 *     matchea solo por sinónimo, y el título más corto antes que el más largo
 *     (menos ruido alrededor del término).
 *
 * El puntaje es un número cualquiera: cmdk solo lo usa para ordenar de mayor a
 * menor y para esconder lo que da 0.
 */

/** Sin acentos, sin mayúsculas y sin los separadores del título (`·`, `/`, `-`). */
export function normalizeSearchText(text: string): string {
  return text
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .toLowerCase()
    .replace(/[^a-z0-9ñ]+/g, " ")
    .trim()
}

/** Palabras de un texto ya normalizado. */
function words(normalized: string): string[] {
  return normalized.length === 0 ? [] : normalized.split(" ")
}

/**
 * Cuánto matchea un término contra una lista de palabras.
 *
 * `0` = no matchea. El prefijo pesa más que el fragmento interno porque es lo
 * que la gente tipea: se escriben las primeras letras, no el medio.
 */
function matchWords(candidates: string[], term: string): number {
  let best = 0
  for (const word of candidates) {
    if (word === term) {
      best = Math.max(best, 3)
    } else if (word.startsWith(term)) {
      best = Math.max(best, 2)
    } else if (term.length >= 4 && word.includes(term)) {
      best = Math.max(best, 1)
    } else if (word.length >= 4 && term.startsWith(word) && term.length - word.length <= 2) {
      // Plural contra singular: "cajas" tiene que encontrar "Caja", y
      // "sucursales" tiene que encontrar "Sucursal". Dos letras de tolerancia
      // cubren -s/-es sin abrir la puerta a matches largos por casualidad.
      best = Math.max(best, 1)
    }
  }
  return best
}

export interface PaletteSearchable {
  /** Título tal como se ve en pantalla (puede venir con prefijo de grupo). */
  title: string
  /** Sinónimos y alias. Cómo lo llama la gente, no cómo lo llamamos nosotros. */
  keywords?: string[]
}

/**
 * Puntaje de una entrada contra lo que escribió el usuario.
 *
 * Devuelve `0` cuando la entrada no debe aparecer. Con la búsqueda vacía todas
 * valen lo mismo (`1`): cmdk pide un puntaje aunque no haya nada tipeado, y
 * ahí el orden que manda es el del registro de rutas.
 */
export function paletteScore(entry: PaletteSearchable, search: string): number {
  const query = normalizeSearchText(search)
  if (query === "") return 1

  const title = normalizeSearchText(entry.title)
  const titleWords = words(title)
  const keywordWords = words(normalizeSearchText((entry.keywords ?? []).join(" ")))

  let score = 0
  for (const term of words(query)) {
    const inTitle = matchWords(titleWords, term)
    const inKeywords = matchWords(keywordWords, term)
    // Regla 1: un término sin match en ningún lado descarta la entrada entera.
    if (inTitle === 0 && inKeywords === 0) return 0
    score += inTitle * 100 + inKeywords * 20
  }

  // Lo escrito es el título completo, o su arranque: es lo que el usuario
  // estaba buscando, no algo que lo contiene de casualidad.
  if (title === query) score += 10000
  else if (title.startsWith(query)) score += 4000
  else if (titleWords.some((word) => word === query)) score += 2000

  // Desempate: a igual match, el título más corto es el más específico
  // ("Sucursales" antes que "Reportes · Sucursales por período"). Es una
  // fracción — nunca da vuelta una diferencia de match real.
  return score + 1 / (1 + title.length)
}
