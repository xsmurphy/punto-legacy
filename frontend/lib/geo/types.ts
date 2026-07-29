/**
 * Shapes normalizados de la ruta `geo` del BFF (`app/api/geo/*`). Este es el
 * contrato que consume la UI (typeahead de dirección, reverse geocoding) — es
 * el ÚNICO lugar que le importa la forma de los datos. El proveedor detrás
 * (hoy Photon/OSM) puede cambiarse sin tocar componentes ni hooks, siempre
 * que el adapter nuevo devuelva este mismo shape.
 *
 * Ver `lib/geo/photon-client.ts` para el adapter actual.
 */

export interface GeoSuggestion {
  /** Clave estable para usar como `key` en listas — no es un id persistente. */
  id: string
  /** Línea completa para mostrar en la lista de sugerencias. */
  label: string
  /** Calle y número (o nombre del lugar si no hay calle segmentada). */
  street: string | null
  city: string | null
  /** Barrio / zona — Photon lo expone como `district` en muchos POIs de Paraguay. */
  neighborhood: string | null
  lat: number
  lng: number
}

export interface GeoAutocompleteResponse {
  provider: "photon"
  suggestions: GeoSuggestion[]
}

export interface GeoReverseResponse {
  provider: "photon"
  result: GeoSuggestion | null
}

export interface GeoResolveShortLinkResponse {
  longUrl: string
}

export interface GeoErrorResponse {
  ok: false
  error: string
}
