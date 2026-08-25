/**
 * BFF — autocomplete de direcciones (typeahead), sesgado al país del comercio.
 *
 *   GET /api/geo/autocomplete?q=<texto>  → { provider, suggestions: GeoSuggestion[] }
 *
 * Proveedor detrás: Photon (`lib/geo/photon-client.ts`) — ver ese archivo
 * para qué tocar si se cambia de proveedor. Esta ruta NO conoce el shape
 * crudo de Photon, solo llama al adapter y devuelve `GeoSuggestion[]`
 * (`lib/geo/types.ts`).
 *
 * Caché + throttle hacia el proveedor: `lib/geo/rate-limited-cache.ts`.
 * País: `lib/geo/get-tenant-country.ts` (fail-open a sin bias).
 */

import { NextRequest, NextResponse } from "next/server"
import { photonAutocomplete } from "@/lib/geo/photon-client"
import { getTenantCountry } from "@/lib/geo/get-tenant-country"
import type { GeoAutocompleteResponse } from "@/lib/geo/types"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

const MIN_QUERY_LENGTH = 3

export async function GET(req: NextRequest) {
  const q = (req.nextUrl.searchParams.get("q") ?? "").trim()
  if (q.length < MIN_QUERY_LENGTH) {
    return NextResponse.json<GeoAutocompleteResponse>({ provider: "photon", suggestions: [] })
  }

  const cookie = req.headers.get("cookie") ?? ""
  const countryParam = req.nextUrl.searchParams.get("country")?.trim()
  // Sin `|| "PY"`: si no se conoce el país del tenant, el typeahead va SIN
  // sesgo geográfico en vez de sesgarse a Paraguay. `photonAutocomplete` ya
  // acepta el caso "sin país" (fail-open documentado en get-tenant-country.ts)
  // — un resultado global es peor que uno local, pero mucho mejor que
  // proponerle calles de Asunción a un comercio de São Paulo.
  const country = countryParam || (await getTenantCountry(cookie)) || null

  try {
    const suggestions = await photonAutocomplete(q, country)
    return NextResponse.json<GeoAutocompleteResponse>({ provider: "photon", suggestions })
  } catch (err) {
    console.error("[geo/autocomplete] falló", err instanceof Error ? err.message : err)
    // Fail-soft: el typeahead es un accesorio, nunca debe tirar error duro al
    // form (el operador siempre puede seguir escribiendo a mano).
    return NextResponse.json<GeoAutocompleteResponse>({ provider: "photon", suggestions: [] })
  }
}
