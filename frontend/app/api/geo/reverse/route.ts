/**
 * BFF — reverse geocoding: coordenadas → dirección/ciudad/barrio.
 *
 *   GET /api/geo/reverse?lat=<n>&lng=<n>  → { provider, result: GeoSuggestion | null }
 *
 * Mismo proveedor y mismas reglas de caché/throttle que `/api/geo/autocomplete`
 * — ver `lib/geo/photon-client.ts` y `lib/geo/rate-limited-cache.ts`.
 */

import { NextRequest, NextResponse } from "next/server"
import { photonReverse } from "@/lib/geo/photon-client"
import type { GeoReverseResponse } from "@/lib/geo/types"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

export async function GET(req: NextRequest) {
  const lat = Number(req.nextUrl.searchParams.get("lat"))
  const lng = Number(req.nextUrl.searchParams.get("lng"))

  if (!Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) {
    return NextResponse.json({ ok: false, error: "lat/lng inválidos" }, { status: 400 })
  }

  try {
    const result = await photonReverse(lat, lng)
    return NextResponse.json<GeoReverseResponse>({ provider: "photon", result })
  } catch (err) {
    console.error("[geo/reverse] falló", err instanceof Error ? err.message : err)
    return NextResponse.json<GeoReverseResponse>({ provider: "photon", result: null })
  }
}
