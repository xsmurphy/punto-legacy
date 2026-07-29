/**
 * BFF — resuelve un link corto de Google Maps a su URL larga.
 *
 *   POST /api/geo/resolve-short-link  { url: string }  → { longUrl: string }
 *
 * `maps.app.goo.gl` / `goo.gl/maps` no traen coordenadas — hay que seguir el
 * redirect HTTP para llegar a la URL larga (`.../maps/place/.../@lat,lng,...`).
 * El browser no puede hacer este fetch por CORS; el servidor sí. Sin API key,
 * sin costo — es solo seguir un header `Location`.
 *
 * ⚠ SSRF: este endpoint no autentica al caller (no hace falta, no toca datos
 * de tenant), así que la única defensa es la validación de host ANTES de
 * hacer cualquier fetch — sin esto, sería un proxy abierto para golpear
 * cualquier URL desde la IP del servidor. Se valida el host en CADA hop del
 * redirect (máximo 2), no solo en la URL de entrada, porque Google a veces
 * intercala un hop por `consent.google.com` antes de llegar a la URL final.
 * (Probado contra un short link real: en la práctica resuelve en UN solo
 * salto sin consent screen — el margen de 2 es solo defensivo.)
 *
 * El mapeo corto→largo es un alias fijo de Google que no cambia nunca —se
 * cachea por URL de entrada con TTL largo (`lib/geo/rate-limited-cache.ts`)
 * para no repetir el round-trip a Google en cada carga del mismo link.
 */

import { NextRequest, NextResponse } from "next/server"
import { shortLinkCache, SHORT_LINK_TTL_MS } from "@/lib/geo/rate-limited-cache"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

const ALLOWED_HOSTS = new Set([
  "maps.app.goo.gl",
  "goo.gl",
  "www.google.com",
  "google.com",
  "consent.google.com",
])

const MAX_REDIRECTS = 2
const FETCH_TIMEOUT_MS = 3000

function isAllowedHost(url: URL): boolean {
  if (!ALLOWED_HOSTS.has(url.hostname)) return false
  // goo.gl sirve más que Maps — exigir que el path sea específicamente de maps.
  if (url.hostname === "goo.gl" && !url.pathname.startsWith("/maps")) return false
  return true
}

export async function POST(req: NextRequest) {
  let body: { url?: string }
  try {
    body = await req.json()
  } catch {
    return NextResponse.json({ ok: false, error: "Body inválido" }, { status: 400 })
  }

  const raw = (body.url ?? "").trim()
  if (!raw) {
    return NextResponse.json({ ok: false, error: "Falta 'url'" }, { status: 400 })
  }

  let current: URL
  try {
    current = new URL(raw)
  } catch {
    return NextResponse.json({ ok: false, error: "URL inválida" }, { status: 400 })
  }

  if (current.protocol !== "https:" || !isAllowedHost(current)) {
    return NextResponse.json(
      { ok: false, error: "Solo se aceptan links de Google Maps" },
      { status: 400 },
    )
  }

  const cacheKey = current.toString()
  const cached = shortLinkCache.get(cacheKey)
  if (cached) {
    return NextResponse.json({ longUrl: cached })
  }

  const respond = (longUrl: string) => {
    shortLinkCache.set(cacheKey, longUrl, SHORT_LINK_TTL_MS)
    return NextResponse.json({ longUrl })
  }

  try {
    for (let hop = 0; hop <= MAX_REDIRECTS; hop++) {
      const res = await fetch(current.toString(), {
        redirect: "manual",
        signal: AbortSignal.timeout(FETCH_TIMEOUT_MS),
        headers: { "User-Agent": "Mozilla/5.0 (compatible; PuntoBFF/1.0)" },
      })

      const location = res.headers.get("location")
      if (!location) {
        // Sin redirect: esta URL ya es la final (puede pasar si el link
        // corto resolvió sin más hops que un 200).
        return respond(current.toString())
      }

      const next = new URL(location, current)
      if (next.protocol !== "https:" || !isAllowedHost(next)) {
        // El redirect nos saca del dominio de Google — no lo seguimos, pero
        // devolvemos igual la URL a la que apunta: puede ya traer las
        // coordenadas en la query aunque el host ya no sea "maps".
        return respond(next.toString())
      }
      current = next
    }
    // Se agotaron los hops permitidos — devolvemos la última URL alcanzada.
    return respond(current.toString())
  } catch (err) {
    return NextResponse.json(
      { ok: false, error: err instanceof Error ? err.message : "No se pudo resolver el link" },
      { status: 502 },
    )
  }
}
