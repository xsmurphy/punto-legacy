"use client"

import { useQuery } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

/** Espejo mínimo de `presentPrinter()` (PrintPoolService.php) — lo que
 *  necesita el picker del binding (transport "station"), no el objeto
 *  completo de `print-station/types.ts` (ese es para la pantalla de estación,
 *  auth por device token). */
export interface StationPrinterOption {
  id: string
  name: string
  kind: string
  transport: string
  /** 1 activa, 0 soft-deleted. */
  status: number
}

/**
 * Impresoras físicas de la Estación de Impresión, de la sucursal de la caja
 * que se está editando — para el Select de `BindingDialog` cuando
 * transport==="station". `GET /v1/station-printers` acepta panel+pos-app
 * (api/v1/station-printers.php); acá siempre panel (cookie, `api` de
 * api-client.ts) — este hook vive en Ajustes, no en el POS.
 */
export function useStationPrinters(outletId: string | undefined) {
  return useQuery<{ printers: StationPrinterOption[] }>({
    queryKey: ["station-printers", outletId],
    queryFn: () => api.get(`/v1/station-printers?${new URLSearchParams({ outletId: outletId ?? "" })}`),
    staleTime: 30 * 1000,
    enabled: !!outletId,
  })
}
