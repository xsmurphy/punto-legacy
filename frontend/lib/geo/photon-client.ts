/**
 * Adapter de Photon (https://photon.komoot.io) — geocoding/autocomplete
 * community-run sobre datos OSM. Elegido por el owner como PRIMER proveedor
 * a probar (context/28, decisión 2026-07-28): gratis, sin API key, pensado
 * para typeahead. Si la precisión de barrio en Paraguay no alcanza, se
 * cambia de proveedor ACÁ ADENTRO — nada fuera de este archivo (rutas,
 * hooks, componentes) conoce la forma cruda de Photon, solo el shape
 * normalizado de `lib/geo/types.ts`.
 *
 * Para cambiar de proveedor: reimplementar `photonAutocomplete` /
 * `photonReverse` con la misma firma (query/coords in → `GeoSuggestion[]` /
 * `GeoSuggestion | null` out) contra el proveedor nuevo, y actualizar los
 * tres route handlers de `app/api/geo/*` para importar el adapter nuevo.
 *
 * Photon no tiene un filtro estricto "solo este país" (a diferencia de
 * Nominatim con `countrycodes`) — lo que sí tiene es bias por `lat`/`lon`.
 * Usamos el centro aproximado del país como bias y, adicionalmente,
 * reordenamos resultados que matcheen `countrycode` primero (best-effort,
 * no un filtro duro: si Photon no trae nada del país buscado preferimos
 * mostrar algo a no mostrar nada).
 */

import {
  geoAutocompleteCache,
  geoReverseCache,
  normalizeQueryKey,
  roundCoord,
  throttledCall,
} from "./rate-limited-cache"
import type { GeoSuggestion } from "./types"

const PHOTON_BASE = "https://photon.komoot.io/api/"
const PHOTON_REVERSE = "https://photon.komoot.io/reverse"
const FETCH_TIMEOUT_MS = 4000

// Centro aproximado por país (bias de búsqueda), no un filtro. Agregar países
// acá es aditivo — sin entry, se busca sin bias de coordenadas.
const COUNTRY_BIAS: Record<string, { lat: number; lon: number }> = {
  PY: { lat: -25.2637, lon: -57.5759 }, // Asunción
  AR: { lat: -34.6037, lon: -58.3816 }, // Buenos Aires
  BR: { lat: -15.7801, lon: -47.9292 }, // Brasília
  UY: { lat: -34.9011, lon: -56.1645 }, // Montevideo
  BO: { lat: -16.5, lon: -68.15 }, // La Paz
}

interface PhotonProperties {
  osm_id?: number
  osm_type?: string
  name?: string
  street?: string
  housenumber?: string
  /** Photon rellena esto con el barrio/zona en datos de Paraguay (ej. "Villa
   *  Morra", "La Catedral") — es el campo correcto para "barrio", NO `district`
   *  (verificado 2026-07-29 contra Photon en vivo con direcciones de Asunción). */
  locality?: string
  /** En datos de PY casi siempre duplica `city` ("Asunción" = "Asunción") —
   *  solo sirve como barrio cuando el municipio tiene sub-distritos reales
   *  (ej. district="Primer Barrio", city="Luque"). Se usa como fallback de
   *  `neighborhood` únicamente cuando difiere de `city`. */
  district?: string
  city?: string
  county?: string
  state?: string
  country?: string
  countrycode?: string
  postcode?: string
}

interface PhotonFeature {
  geometry: { coordinates: [number, number] } // [lon, lat]
  properties: PhotonProperties
}

interface PhotonFeatureCollection {
  features: PhotonFeature[]
}

function normalizeFeature(f: PhotonFeature): GeoSuggestion | null {
  const [lon, lat] = f.geometry?.coordinates ?? []
  if (typeof lat !== "number" || typeof lon !== "number") return null

  const p = f.properties ?? {}
  const streetLine = [p.street, p.housenumber].filter(Boolean).join(" ").trim()
  const street = streetLine || p.name || null
  const city = p.city || p.county || p.state || null
  // Ver comentario de `locality`/`district` en `PhotonProperties` — `district`
  // solo cuenta como barrio si no es un duplicado literal de `city`.
  const neighborhood = p.locality || (p.district && p.district !== city ? p.district : null)

  const labelParts = [street, neighborhood, city].filter(Boolean)
  const label = labelParts.length > 0 ? labelParts.join(", ") : (p.name ?? `${lat}, ${lon}`)

  return {
    id: p.osm_type && p.osm_id ? `${p.osm_type}:${p.osm_id}` : `${lat},${lon}`,
    label,
    street,
    city,
    neighborhood,
    lat,
    lng: lon,
  }
}

async function fetchPhoton(url: string): Promise<PhotonFeatureCollection> {
  const res = await fetch(url, {
    signal: AbortSignal.timeout(FETCH_TIMEOUT_MS),
    headers: { "User-Agent": "Punto POS (geo BFF)" },
  })
  if (!res.ok) throw new Error(`Photon respondió ${res.status}`)
  const json = (await res.json()) as unknown
  // Defensivo: si Photon devuelve un shape de error (ej. parámetro inválido)
  // en vez del FeatureCollection esperado, tratarlo como "sin resultados" en
  // vez de reventar más abajo con un `.map`/`.length` sobre `undefined`.
  const features = (json as { features?: unknown }).features
  return { features: Array.isArray(features) ? (features as PhotonFeature[]) : [] }
}

export async function photonAutocomplete(
  query: string,
  // `null` = no se conoce el país del tenant → búsqueda SIN sesgo geográfico.
  // Antes el caller mandaba "PY" en ese caso y le proponía calles de Asunción
  // a cualquier comercio del mundo.
  countryCode: string | null,
): Promise<GeoSuggestion[]> {
  const key = normalizeQueryKey(query, countryCode ?? "")
  const cached = geoAutocompleteCache.get(key) as GeoSuggestion[] | undefined
  if (cached) return cached

  const bias = countryCode ? COUNTRY_BIAS[countryCode.toUpperCase()] : undefined
  // Sin `lang`: Photon solo soporta default/de/en/fr — "es" no existe y la
  // API devuelve un objeto de error sin `features` (verificado 2026-07-29 en
  // vivo). El default de Photon ya devuelve los nombres tal cual están en
  // OSM (en español para Paraguay en la práctica), así que no hace falta.
  const params = new URLSearchParams({ q: query, limit: "8" })
  if (bias) {
    params.set("lat", String(bias.lat))
    params.set("lon", String(bias.lon))
  }

  const data = await throttledCall(() => fetchPhoton(`${PHOTON_BASE}?${params}`))
  let suggestions = data.features.map(normalizeFeature).filter((s): s is GeoSuggestion => s !== null)

  // Reordenar (no filtrar) para que los resultados del país buscado salgan
  // primero — best-effort, Photon no da un filtro estricto por país. Sin país
  // conocido no hay nada que priorizar: se devuelve el orden de relevancia
  // que ya trae Photon.
  if (!countryCode) {
    geoAutocompleteCache.set(key, suggestions)
    return suggestions
  }
  const cc = countryCode.toUpperCase()
  const withCountry = data.features
    .map((f, i) => ({ f, s: suggestions[i] }))
    .filter((x) => x.s)
  suggestions = [
    ...withCountry.filter((x) => x.f.properties.countrycode?.toUpperCase() === cc).map((x) => x.s!),
    ...withCountry.filter((x) => x.f.properties.countrycode?.toUpperCase() !== cc).map((x) => x.s!),
  ]

  geoAutocompleteCache.set(key, suggestions)
  return suggestions
}

export async function photonReverse(lat: number, lng: number): Promise<GeoSuggestion | null> {
  const rLat = roundCoord(lat)
  const rLng = roundCoord(lng)
  const key = `${rLat},${rLng}`
  const cached = geoReverseCache.get(key)
  if (cached !== undefined) return cached as GeoSuggestion | null

  const params = new URLSearchParams({ lat: String(lat), lon: String(lng) })
  const data = await throttledCall(() => fetchPhoton(`${PHOTON_REVERSE}?${params}`))
  const result = data.features.length > 0 ? normalizeFeature(data.features[0]) : null

  geoReverseCache.set(key, result)
  return result
}
