/**
 * parseCoordinates — extractor de lat/lng compartido (context/27-delivery-sla-plan.md
 * PARTE D.5). Antes vivía inline en `contact-detail-view.tsx` (AddressMapParser,
 * ~L1085); se extrajo a este módulo y luego el componente de UI se extrajo a
 * `components/geo/address-map-parser.tsx` para que lo consuma también el
 * selector de dirección del POS — "atacar el wrapper compartido, no duplicar
 * el call-site" (context/28 — alta de dirección del POS, 2026-07-28).
 *
 * Caminos soportados, en el orden en que se intentan:
 *   1. `!3d<lat>!4d<lng>` — URL de PLACE de Google Maps (compartir un local
 *      buscado por nombre). Se prueba ANTES que `@lat,lng` porque esa misma
 *      URL suele traer las dos cosas y no son lo mismo: `@lat,lng` es el
 *      centro del viewport del mapa, `!3d!4d` es la coordenada real del pin.
 *   2. `@lat,lng` — URL de Google Maps centrada en un punto.
 *   3. `?q=lat,lng` / `&q=lat,lng` — URL de búsqueda por coordenadas.
 *   4. `lat,lng` pelado — lo que queda al copiar solo el par de coordenadas.
 *
 * Geocoding automático (reverse geocoding lat/lng → ciudad/barrio) NO: coherente
 * con la decisión de no depender de una API externa en el camino operativo
 * (§B.7) — el proyecto no tiene ningún servicio de geocoding hoy. Lo único que
 * se extrae es el texto de dirección que Google ya deja adentro de los links
 * largos `/maps/place/<texto>/@...` — es texto de la URL, no una consulta a un
 * servicio de terceros. Cambio ADITIVO: `ParsedCoordinates.address` es opcional
 * y los callers que solo leen `{lat, lng}` no se rompen.
 */

const COORDINATE_PATTERNS: RegExp[] = [
  /!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/,
  /@(-?\d+\.\d+),(-?\d+\.\d+)/,
  /[?&]q=(-?\d+\.\d+),(-?\d+\.\d+)/,
  /^(-?\d+\.\d+)\s*,\s*(-?\d+\.\d+)$/,
]

const SHORT_LINK_PATTERN = /maps\.app\.goo\.gl|goo\.gl\/maps/i

export interface ParsedCoordinates {
  lat: number
  lng: number
  /**
   * Texto de dirección tal cual aparece en la URL (`/maps/place/<texto>/@...`),
   * si el link lo trae. Solo los links de "place" (buscar un lugar/dirección
   * por nombre) lo incluyen — un link `@lat,lng` centrado en el mapa no trae
   * texto. `undefined` si no se pudo extraer; no es una garantía de que el
   * texto sea una dirección completa o correctamente segmentada.
   */
  address?: string
}

/**
 * Un link corto de Google Maps (generado por "Compartir ubicación" en varias
 * versiones de WhatsApp) no contiene las coordenadas — hay que seguir el
 * redirect, y eso no se puede hacer desde el browser (CORS). Se detecta el
 * patrón y se tira este error específico en vez de devolver `null` genérico,
 * así la UI puede mostrar un mensaje accionable en vez de "no encontré nada".
 */
export class ShortMapsLinkError extends Error {
  constructor() {
    super(
      "Los links cortos no traen las coordenadas: abrilo en el navegador y pegá el link largo.",
    )
    this.name = "ShortMapsLinkError"
  }
}

function isValidLat(n: number): boolean {
  return Number.isFinite(n) && n >= -90 && n <= 90
}

function isValidLng(n: number): boolean {
  return Number.isFinite(n) && n >= -180 && n <= 180
}

const PLACE_TEXT_PATTERN = /\/maps\/place\/([^/@]+)/i

/**
 * Extrae el texto de dirección que Google Maps mete en la URL cuando el link
 * es de un "place" buscado por nombre/dirección, ej.
 * `/maps/place/Av.+Mariscal+Lopez+1234,+Asunci%C3%B3n/@-25.28,...`. Google
 * codifica espacios como `+` (no `%20`) en este segmento, así que hay que
 * reemplazarlos a mano antes de `decodeURIComponent`.
 */
function extractPlaceText(text: string): string | undefined {
  const match = text.match(PLACE_TEXT_PATTERN)
  if (!match) return undefined
  try {
    const decoded = decodeURIComponent(match[1].replace(/\+/g, " ")).trim()
    return decoded || undefined
  } catch {
    return undefined
  }
}

/**
 * Divide el texto de dirección de un link de Maps en `address` (calle/número)
 * y `city` (última porción separada por coma), de forma conservadora:
 * Google típicamente da "Calle y número, Ciudad" — nunca incluye barrio/zona,
 * así que esto NUNCA intenta adivinar un barrio. Sin coma → todo es `address`
 * y `city` queda `undefined` (no inventar un valor).
 */
export function splitPlaceAddress(text: string): { address: string; city?: string } {
  const parts = text.split(",").map((p) => p.trim()).filter(Boolean)
  if (parts.length <= 1) return { address: text.trim() }
  const city = parts[parts.length - 1]
  const address = parts.slice(0, -1).join(", ")
  return { address, city: city || undefined }
}

/**
 * Parsea lat/lng de texto pegado por el operador (link de Google Maps o
 * "lat,lng" pelado). Función pura — sin side effects, sin fetch.
 *
 * @throws {ShortMapsLinkError} si el texto es un link corto sin coordenadas.
 * @returns las coordenadas encontradas, o `null` si no matcheó ningún patrón
 *          o los valores no son válidos (fuera de rango, no finitos).
 */
export function parseCoordinates(text: string): ParsedCoordinates | null {
  const trimmed = text.trim()
  if (!trimmed) return null

  // ⚠ El chequeo de link corto va DESPUÉS de intentar los patrones, no antes.
  // Un `maps.app.goo.gl/...?q=-25.28,-57.64` YA trae las coordenadas en la
  // query y se resuelve sin seguir ningún redirect — es un caso que el parser
  // de sucursales soportaba y que rechazar de entrada rompió al unificar
  // (regresión 2026-07-28). Solo es un callejón sin salida el link corto que
  // NO trae coordenadas en ninguna forma.
  for (const pattern of COORDINATE_PATTERNS) {
    const match = trimmed.match(pattern)
    if (!match) continue

    const lat = Number(match[1])
    const lng = Number(match[2])
    if (isValidLat(lat) && isValidLng(lng)) {
      const address = extractPlaceText(trimmed)
      return address ? { lat, lng, address } : { lat, lng }
    }
  }

  if (SHORT_LINK_PATTERN.test(trimmed)) {
    throw new ShortMapsLinkError()
  }

  return null
}
