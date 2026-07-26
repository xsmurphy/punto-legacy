"use client"

/**
 * Vista mapa de /pos/ordenes — MapLibre GL + estilos vectoriales de
 * OpenFreeMap (mismo patrón que `AddressMapPreview` en
 * components/domain/contacts/contact-detail-view.tsx: dynamic import, sin API
 * key, positron/fiord según el tema).
 *
 * Pinta el local como PIN fijo (coords de `outlet.lat/lng`, mig 14, expuestas
 * por /v1/bootstrap → BFF) y un PIN por orden con coordenadas del cliente
 * (`customerLat`/`customerLng`, parseadas server-side desde el
 * `contactLatLng` legacy). Las órdenes SIN coordenadas no se pierden: se
 * listan aparte debajo del mapa.
 *
 * Ciclo de vida: el mapa se crea UNA vez al montar y se destruye en el
 * unmount (`map.remove()` + markers). Los markers se reconcilian en un efecto
 * aparte, así cambiar el filtro de estado no recrea el mapa. La página lo
 * mantiene montado (oculto) al cambiar de vista — ver `page.tsx`.
 *
 * Online-only: si el estilo no carga (sin red / tiles caídos) degrada a un
 * mensaje, nunca a pantalla en blanco.
 */

import * as React from "react"
import { useTheme } from "next-themes"
import { MapPin, WifiOff } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"
import { formatMoney } from "@/lib/format-money"
import { useCatalogStore } from "@/lib/catalog/store"
import type { Order } from "@/hooks/use-orders"
import { STATUS_LABEL, STATUS_VARIANT, orderTotal } from "@/lib/orders/order-display"

import type { Map as MapLibreMap, Marker as MapLibreMarker } from "maplibre-gl"

// Estilos de controles/popups de MapLibre (mismo import que AddressMapPreview).
import "maplibre-gl/dist/maplibre-gl.css"

const OFM_STYLE_LIGHT = "https://tiles.openfreemap.org/styles/positron"
const OFM_STYLE_DARK = "https://tiles.openfreemap.org/styles/fiord"

/** Centro de respaldo cuando la sucursal no tiene coordenadas cargadas (Asunción). */
const FALLBACK_CENTER: [number, number] = [-57.6359, -25.2637]

/** Si el estilo no terminó de cargar en este lapso, asumimos que no hay red. */
const STYLE_LOAD_TIMEOUT_MS = 12_000

function hasCoords(o: Order): boolean {
  return (
    typeof o.customerLat === "number" &&
    typeof o.customerLng === "number" &&
    Number.isFinite(o.customerLat) &&
    Number.isFinite(o.customerLng)
  )
}

export function OrdersMapView({
  orders,
  onOpenOrder,
}: {
  orders: Order[]
  onOpenOrder: (order: Order) => void
}) {
  const { resolvedTheme } = useTheme()
  const isDark = resolvedTheme === "dark"
  const config = useCatalogStore((s) => s.config)
  const outlet = useCatalogStore((s) => s.outlet)

  const containerRef = React.useRef<HTMLDivElement | null>(null)
  const mapRef = React.useRef<MapLibreMap | null>(null)
  const markersRef = React.useRef<MapLibreMarker[]>([])
  const didFitRef = React.useRef(false)

  const [loadFailed, setLoadFailed] = React.useState(false)
  const [mapReady, setMapReady] = React.useState(false)

  // El handler de "abrir detalle" se lee por ref desde el listener del popup:
  // el listener se registra una sola vez por marker y no debe capturar una
  // versión vieja de la callback.
  const onOpenOrderRef = React.useRef(onOpenOrder)
  React.useEffect(() => {
    onOpenOrderRef.current = onOpenOrder
  }, [onOpenOrder])

  const outletLat = outlet?.lat ?? null
  const outletLng = outlet?.lng ?? null

  const withCoords = React.useMemo(() => orders.filter(hasCoords), [orders])
  const withoutCoords = React.useMemo(() => orders.filter((o) => !hasCoords(o)), [orders])

  // ── Creación del mapa (una sola vez) ────────────────────────────────────────
  React.useEffect(() => {
    if (!containerRef.current || mapRef.current) return
    let cancelled = false
    const center: [number, number] =
      outletLat !== null && outletLng !== null ? [outletLng, outletLat] : FALLBACK_CENTER

    const timeout = window.setTimeout(() => {
      if (!cancelled && !mapRef.current?.isStyleLoaded()) setLoadFailed(true)
    }, STYLE_LOAD_TIMEOUT_MS)

    void import("maplibre-gl")
      .then((mod) => {
        if (cancelled || !containerRef.current) return
        const maplibregl = mod.default ?? mod
        const map = new maplibregl.Map({
          container: containerRef.current,
          style: isDark ? OFM_STYLE_DARK : OFM_STYLE_LIGHT,
          center,
          zoom: 13,
          attributionControl: { compact: true },
        })
        mapRef.current = map
        map.on("load", () => {
          if (cancelled) return
          window.clearTimeout(timeout)
          setLoadFailed(false)
          setMapReady(true)
        })
      })
      .catch(() => {
        if (!cancelled) setLoadFailed(true)
      })

    return () => {
      cancelled = true
      window.clearTimeout(timeout)
      for (const m of markersRef.current) m.remove()
      markersRef.current = []
      mapRef.current?.remove()
      mapRef.current = null
      setMapReady(false)
    }
    // Deliberadamente sin deps: el mapa se crea una vez y vive hasta el
    // unmount. El tema y las coords se aplican en efectos aparte.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // ── Tema → setStyle (los markers son overlays DOM y sobreviven al cambio) ───
  React.useEffect(() => {
    if (!mapRef.current || !mapReady) return
    mapRef.current.setStyle(isDark ? OFM_STYLE_DARK : OFM_STYLE_LIGHT)
  }, [isDark, mapReady])

  // ── Markers: se reconcilian ante cambios de filtro/datos, sin tocar el mapa ─
  React.useEffect(() => {
    const map = mapRef.current
    if (!map || !mapReady) return
    let cancelled = false

    void import("maplibre-gl").then((mod) => {
      if (cancelled || !mapRef.current) return
      const maplibregl = mod.default ?? mod

      for (const m of markersRef.current) m.remove()
      markersRef.current = []

      const coords: Array<[number, number]> = []

      if (outletLat !== null && outletLng !== null) {
        const el = document.createElement("div")
        el.className =
          "flex size-9 items-center justify-center rounded-full border-2 border-background bg-primary text-primary-foreground shadow-md"
        el.setAttribute("aria-label", "Ubicación del local")
        el.title = outlet?.name ?? "Local"
        el.innerHTML = STORE_ICON_SVG
        const marker = new maplibregl.Marker({ element: el })
          .setLngLat([outletLng, outletLat])
          .addTo(map)
        markersRef.current.push(marker)
        coords.push([outletLng, outletLat])
      }

      for (const order of withCoords) {
        const lng = order.customerLng as number
        const lat = order.customerLat as number

        const el = document.createElement("div")
        // min-w/h-9: touch target de 36px+ — se toca con el dedo en tablet.
        el.className =
          "flex h-9 min-w-9 cursor-pointer items-center justify-center rounded-full border-2 border-background bg-foreground px-2 text-xs font-bold tabular-nums text-background shadow-md"
        el.textContent = order.orderNumber !== null ? `#${order.orderNumber}` : "#—"

        const popup = new maplibregl.Popup({ offset: 18, closeButton: true }).setHTML(
          popupHtml(order, formatMoney(orderTotal(order), config)),
        )
        popup.on("open", () => {
          const node = popup.getElement()?.querySelector<HTMLButtonElement>("[data-order-open]")
          node?.addEventListener("click", () => {
            popup.remove()
            onOpenOrderRef.current(order)
          })
        })

        const marker = new maplibregl.Marker({ element: el })
          .setLngLat([lng, lat])
          .setPopup(popup)
          .addTo(map)
        markersRef.current.push(marker)
        coords.push([lng, lat])
      }

      // Encuadre inicial sobre todos los pines. Solo la primera vez: después
      // el cajero manda sobre el viewport (no le movemos el mapa bajo el dedo
      // cuando cambia el filtro o entra una orden nueva).
      if (!didFitRef.current && coords.length > 0) {
        didFitRef.current = true
        if (coords.length === 1) {
          map.setCenter(coords[0])
          map.setZoom(15)
        } else {
          const bounds = coords.reduce(
            (b, c) => b.extend(c),
            new maplibregl.LngLatBounds(coords[0], coords[0]),
          )
          map.fitBounds(bounds, { padding: 64, maxZoom: 16 })
        }
      }
    })

    return () => {
      cancelled = true
    }
  }, [withCoords, mapReady, outletLat, outletLng, outlet?.name, config])

  return (
    <div className="flex h-full flex-col gap-3">
      <div className="relative min-h-64 flex-1 overflow-hidden rounded-xl border border-border bg-muted">
        <div ref={containerRef} className="size-full" />
        {loadFailed ? (
          <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-muted p-6 text-center">
            <WifiOff className="size-6 text-muted-foreground" aria-hidden />
            <p className="text-sm font-medium text-foreground">No se pudo cargar el mapa</p>
            <p className="text-sm text-muted-foreground">
              El mapa necesita conexión a internet. Usá la vista Cuadros o Lista mientras tanto.
            </p>
          </div>
        ) : null}
        {outletLat === null || outletLng === null ? (
          <p className="absolute inset-x-2 top-2 rounded-md bg-background/90 px-3 py-2 text-sm text-muted-foreground shadow-sm">
            La sucursal no tiene ubicación cargada. Configurala en Ajustes → Sucursales.
          </p>
        ) : null}
      </div>

      {withoutCoords.length > 0 ? (
        <div className="shrink-0 rounded-xl border border-border bg-card p-3">
          <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Sin ubicación ({withoutCoords.length})
          </p>
          <div className="flex max-h-32 flex-col gap-1 overflow-y-auto">
            {withoutCoords.map((order) => (
              <Button
                key={order.id}
                variant="ghost"
                onClick={() => onOpenOrder(order)}
                className={cn("h-11 w-full justify-between gap-2 px-3")}
              >
                <span className="flex min-w-0 items-center gap-2">
                  <MapPin className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
                  <span className="tabular-nums">#{order.orderNumber ?? "—"}</span>
                  <span className="truncate text-muted-foreground">
                    {order.customerName ?? "Sin cliente"}
                  </span>
                </span>
                <Badge variant={STATUS_VARIANT[order.status]}>{STATUS_LABEL[order.status]}</Badge>
              </Button>
            ))}
          </div>
        </div>
      ) : null}
    </div>
  )
}

/** Ícono de local para el marker del outlet (lucide `Store`, inline por ser DOM crudo). */
const STORE_ICON_SVG = `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7"/><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="M15 22v-4a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2v4"/><path d="M2 7h20"/><path d="M22 7v3a2 2 0 0 1-2 2 2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 16 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 12 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 8 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 4 12a2 2 0 0 1-2-2V7"/></svg>`

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
}

/**
 * Contenido del popup. Es HTML crudo (MapLibre no renderiza React acá), así
 * que TODO valor dinámico pasa por `escapeHtml`.
 */
function popupHtml(order: Order, total: string): string {
  const number = order.orderNumber !== null ? `#${order.orderNumber}` : "#—"
  return `
    <div class="flex flex-col gap-1.5 p-1 text-foreground">
      <p class="text-base font-semibold tabular-nums">Orden ${escapeHtml(number)}</p>
      <p class="text-sm text-muted-foreground">${escapeHtml(order.customerName ?? "Sin cliente")}</p>
      <p class="text-sm text-muted-foreground">${escapeHtml(STATUS_LABEL[order.status])}</p>
      <p class="text-sm font-semibold tabular-nums">${escapeHtml(total)}</p>
      <button type="button" data-order-open
        class="mt-1 inline-flex h-9 items-center justify-center rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground">
        Ver detalle
      </button>
    </div>
  `
}
