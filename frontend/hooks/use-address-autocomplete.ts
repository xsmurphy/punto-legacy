"use client"

/**
 * Typeahead de dirección — pega contra `/api/geo/autocomplete` (BFF, Photon
 * detrás, ver `lib/geo/photon-client.ts`). Compartido por el form de
 * dirección del POS (`delivery-address-dialog.tsx`) y del panel
 * (`contact-detail-view.tsx`) vía `<AddressAutocompleteInput>`.
 *
 * ⚠ El POS opera offline por diseño (memoria `project_offline_scope`): este
 * hook NUNCA debe tirar un error que bloquee el form. Sin red o con el BFF
 * caído, `suggestions` queda vacío y el cajero sigue escribiendo a mano —
 * el fetch fallido se traga en silencio (no hay toast, no hay bloqueo).
 *
 * Debounce 350ms + mínimo 3 caracteres + `AbortController` por request: sin
 * el abort, una respuesta vieja que llega después de una más nueva pisaría
 * la lista de sugerencias con resultados de una query anterior.
 */

import * as React from "react"
import { api } from "@/lib/api-client"
import type { GeoAutocompleteResponse, GeoSuggestion } from "@/lib/geo/types"

export const ADDRESS_AUTOCOMPLETE_MIN_LENGTH = 3
const DEBOUNCE_MS = 350

export function useAddressAutocomplete(query: string) {
  const [suggestions, setSuggestions] = React.useState<GeoSuggestion[]>([])
  const [isLoading, setIsLoading] = React.useState(false)
  const abortRef = React.useRef<AbortController | null>(null)

  React.useEffect(() => {
    const trimmed = query.trim()
    abortRef.current?.abort()
    const tooShort = trimmed.length < ADDRESS_AUTOCOMPLETE_MIN_LENGTH

    // El reset por query corta también se dispara adentro del timer (async),
    // nunca sincrónicamente en el cuerpo del effect — evita el patrón de
    // cascading renders que marca `react-hooks/set-state-in-effect`.
    const timer = setTimeout(async () => {
      if (tooShort) {
        setSuggestions([])
        setIsLoading(false)
        return
      }
      const controller = new AbortController()
      abortRef.current = controller
      setIsLoading(true)
      try {
        // Vía `api` y no `fetch` crudo: `/api/geo/autocomplete` resuelve el país
        // del tenant contra `/v1/settings`, que necesita la credencial del panel
        // (Bearer, context/54 F1). Con fetch directo el país salía null y el
        // typeahead perdía el sesgo geográfico en silencio.
        const json = await api.get<GeoAutocompleteResponse>(
          `/geo/autocomplete?q=${encodeURIComponent(trimmed)}`,
          { signal: controller.signal },
        )
        setSuggestions(json?.suggestions ?? [])
      } catch {
        // Abort (query superada) o sin red — el form sigue funcionando con
        // escritura libre, no hay nada que reportar acá.
        setSuggestions([])
      } finally {
        setIsLoading(false)
      }
    }, DEBOUNCE_MS)

    return () => {
      clearTimeout(timer)
      abortRef.current?.abort()
    }
  }, [query])

  return { suggestions, isLoading }
}
