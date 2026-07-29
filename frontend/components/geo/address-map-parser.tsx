"use client"

/**
 * AddressMapParser — input + botón "Extraer" para pegar un link de Google
 * Maps (o un "lat,lng" pelado) y sacar coordenadas + dirección. Antes vivía
 * duplicado inline en el panel (`contact-detail-view.tsx`); se comparte acá
 * para que el alta de dirección del POS (`delivery-address-dialog.tsx`) y
 * `outlets/[id]/page.tsx` usen el mismo parser — un fix al extractor
 * (`lib/geo/parse-coordinates.ts`) o al reverse geocoding llega a todos los
 * lados sin tocar este componente.
 *
 * `onParsed` recibe `AddressMapParserResult` — TODOS los campos menos
 * lat/lng son opcionales y el caller decide qué hacer con cada uno (mergear
 * conservador, pisar, etc). Ver el comentario de cada campo abajo.
 *
 * Links cortos (`maps.app.goo.gl` / `goo.gl/maps`): `parseCoordinates` tira
 * `ShortMapsLinkError` porque no traen coordenadas en el texto. Antes de
 * mostrar ese error, este componente intenta resolver el link vía el BFF
 * `/api/geo/resolve-short-link` (server-side, sin CORS) y reintenta el parseo
 * con la URL larga que devuelve. Solo si ESO también falla se muestra el
 * mensaje de "abrilo y pegá el largo" — nunca bloquea, es un intento extra.
 *
 * ⚠ Un link de Maps "place" (`/maps/place/<texto>/@...`) trae en `<texto>`
 * lo que el usuario buscó — que puede ser el NOMBRE DE UN COMERCIO ("El Café
 * de Acá"), no una dirección real. Volcar ese texto directo al campo
 * Dirección mete un nombre de local donde va calle y número. Por eso, cuando
 * hay coordenadas, este componente SIEMPRE intenta reverse geocoding
 * (`/api/geo/reverse`, Photon detrás) para completar calle/ciudad/barrio de
 * verdad — el texto crudo del link se expone aparte, como `placeName`, para
 * que el caller lo use como sugerencia de Nombre/Referencia, nunca de
 * Dirección. Si el reverse geocoding no está disponible (offline, Photon
 * caído), cae a un fallback best-effort con el texto del link — igual que el
 * comportamiento previo a esta feature — porque tener algo editable es mejor
 * que bloquear.
 */

import * as React from "react"
import { Input } from "@/components/ui/input"
import { Button } from "@/components/ui/button"
import { Loader2 } from "lucide-react"
import { toast } from "sonner"
import {
  parseCoordinates,
  splitPlaceAddress,
  ShortMapsLinkError,
  type ParsedCoordinates,
} from "@/lib/geo/parse-coordinates"
import type { GeoReverseResponse } from "@/lib/geo/types"

export interface AddressMapParserResult {
  lat: number
  lng: number
  /** Calle y número — de reverse geocoding real, o fallback best-effort del texto del link si no hubo reverse geocoding disponible. */
  address?: string
  city?: string
  /** Barrio / zona. Solo viene de reverse geocoding — el texto del link de Maps nunca lo trae. */
  neighborhood?: string
  /**
   * Nombre del lugar tal cual lo trae el link "place" de Maps (puede ser un
   * comercio buscado por nombre). NUNCA es autoridad para el campo Dirección
   * — el caller lo ofrece como sugerencia de Nombre/Referencia.
   */
  placeName?: string
}

async function resolveShortLink(url: string): Promise<string | null> {
  try {
    const res = await fetch("/api/geo/resolve-short-link", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ url }),
    })
    if (!res.ok) return null
    const json = (await res.json()) as { longUrl?: string }
    return json.longUrl ?? null
  } catch {
    return null
  }
}

async function reverseGeocode(lat: number, lng: number) {
  try {
    const res = await fetch(`/api/geo/reverse?lat=${lat}&lng=${lng}`)
    if (!res.ok) return null
    const json = (await res.json()) as GeoReverseResponse
    return json.result ?? null
  } catch {
    return null
  }
}

/** Enriquece coordenadas parseadas con reverse geocoding, separando SIEMPRE
 *  el nombre de lugar (`placeName`) de la dirección real. */
async function finalize(result: ParsedCoordinates): Promise<AddressMapParserResult> {
  const geocoded = await reverseGeocode(result.lat, result.lng)
  if (geocoded) {
    return {
      lat: result.lat,
      lng: result.lng,
      address: geocoded.street ?? undefined,
      city: geocoded.city ?? undefined,
      neighborhood: geocoded.neighborhood ?? undefined,
      placeName: result.address,
    }
  }
  // Sin reverse geocoding disponible: fallback best-effort al texto del link
  // mismo (comportamiento previo a esta feature). Puede colar un nombre de
  // comercio en Dirección — no ideal, pero editable, y nunca bloquea.
  if (result.address) {
    const fields = splitPlaceAddress(result.address)
    return { lat: result.lat, lng: result.lng, address: fields.address, city: fields.city }
  }
  return { lat: result.lat, lng: result.lng }
}

export function AddressMapParser({
  onParsed,
}: {
  onParsed: (result: AddressMapParserResult) => void
}) {
  const [text, setText] = React.useState("")
  const [resolving, setResolving] = React.useState(false)

  const parse = async () => {
    if (!text.trim()) return
    setResolving(true)
    try {
      let result: ParsedCoordinates | null
      try {
        result = parseCoordinates(text)
      } catch (e) {
        if (!(e instanceof ShortMapsLinkError)) throw e
        const longUrl = await resolveShortLink(text.trim())
        result = longUrl ? parseCoordinates(longUrl) : null
        if (!result) {
          toast.error("Link corto sin coordenadas", { description: e.message })
          return
        }
      }
      if (!result) {
        toast.error("No pude extraer coordenadas", {
          description: "Pegá un link largo de Google Maps o el texto 'lat,lng'.",
        })
        return
      }
      onParsed(await finalize(result))
      setText("")
    } finally {
      setResolving(false)
    }
  }

  return (
    <div className="flex flex-col gap-2 rounded-md border border-dashed p-3">
      <label className="text-xs text-muted-foreground">
        Pegar link de Google Maps o &quot;lat,lng&quot;
      </label>
      <div className="flex gap-2">
        <Input
          value={text}
          onChange={(e) => setText(e.target.value)}
          placeholder="https://www.google.com/maps/@-25.28,-57.64,17z"
          className="text-xs"
        />
        <Button type="button" variant="outline" size="sm" onClick={parse} disabled={resolving}>
          {resolving ? <Loader2 className="size-3.5 animate-spin" aria-hidden /> : null}
          Extraer
        </Button>
      </div>
    </div>
  )
}
