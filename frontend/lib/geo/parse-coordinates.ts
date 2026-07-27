/**
 * parseCoordinates — extractor de lat/lng compartido (context/27-delivery-sla-plan.md
 * PARTE D.5). Antes vivía inline en `contact-detail-view.tsx` (AddressMapParser,
 * ~L1085); se extrae acá para que lo consuma también el selector de dirección
 * del POS más adelante — "atacar el wrapper compartido, no duplicar el
 * call-site".
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
 * Geocoding automático NO: coherente con la decisión de no depender de una
 * API externa en el camino operativo (§B.7).
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

  if (SHORT_LINK_PATTERN.test(trimmed)) {
    throw new ShortMapsLinkError()
  }

  for (const pattern of COORDINATE_PATTERNS) {
    const match = trimmed.match(pattern)
    if (!match) continue

    const lat = Number(match[1])
    const lng = Number(match[2])
    if (isValidLat(lat) && isValidLng(lng)) {
      return { lat, lng }
    }
  }

  return null
}
