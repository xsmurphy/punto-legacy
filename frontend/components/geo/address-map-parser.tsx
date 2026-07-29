"use client"

/**
 * AddressMapParser — input + botón "Extraer" para pegar un link de Google
 * Maps (o un "lat,lng" pelado) y sacar coordenadas. Antes vivía duplicado
 * inline en el panel (`contact-detail-view.tsx`); se comparte acá para que el
 * alta de dirección del POS (`delivery-address-dialog.tsx`) use el mismo
 * parser — un fix al extractor (`lib/geo/parse-coordinates.ts`) llega a los
 * dos lados sin tocar este componente.
 *
 * Componente puro de UI: no sabe nada de la forma del form que lo consume.
 * `onParsed` recibe `{ lat, lng, address? }` — `address` es el texto de
 * dirección que Google deja en la URL de los links de "place" (no siempre
 * está). El caller decide qué hacer con `address` (ver `splitPlaceAddress` en
 * `lib/geo/parse-coordinates.ts` para separarlo en calle/ciudad).
 */

import * as React from "react"
import { Input } from "@/components/ui/input"
import { Button } from "@/components/ui/button"
import { toast } from "sonner"
import {
  parseCoordinates,
  ShortMapsLinkError,
  type ParsedCoordinates,
} from "@/lib/geo/parse-coordinates"

export function AddressMapParser({
  onParsed,
}: {
  onParsed: (result: ParsedCoordinates) => void
}) {
  const [text, setText] = React.useState("")

  const parse = () => {
    if (!text.trim()) return
    try {
      const result = parseCoordinates(text)
      if (!result) {
        toast.error("No pude extraer coordenadas", {
          description: "Pegá un link largo de Google Maps o el texto 'lat,lng'.",
        })
        return
      }
      onParsed(result)
      setText("")
    } catch (e) {
      if (e instanceof ShortMapsLinkError) {
        toast.error("Link corto sin coordenadas", { description: e.message })
        return
      }
      throw e
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
        <Button type="button" variant="outline" size="sm" onClick={parse}>
          Extraer
        </Button>
      </div>
    </div>
  )
}
