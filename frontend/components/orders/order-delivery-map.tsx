"use client"

/**
 * Entrega de una orden de delivery — origen, destino y el mapa con los dos.
 *
 * Misma librería y mismo patrón que el resto de los mapas del panel
 * (MapLibre GL + estilos de OpenFreeMap, dynamic import, sin API key,
 * positron/fiord según el tema, degradación a mensaje sin red): ver
 * `components/orders/orders-map-view.tsx` y
 * `components/domain/reports/customers/customers-heatmap.tsx`.
 *
 * ── De dónde sale cada punto ─────────────────────────────────────────────────
 *   ORIGEN  = la sucursal DE LA ORDEN (`outletLat/outletLng`, que trae
 *             `find()`), no la sucursal activa del bootstrap: con el selector
 *             en "Todas" el panel abre órdenes de cualquier sucursal.
 *   DESTINO = el snapshot de la orden (`deliveryLat/deliveryLng`, mig 94),
 *             congelado al crearla — la dirección a la que FUE. Si la orden
 *             no lo guardó, la ubicación actual de la ficha del cliente
 *             (`customerLat/customerLng`), dicho en pantalla: el cliente pudo
 *             haberse mudado. Es la misma precedencia que la vista mapa de
 *             /pos/ordenes (context/27 §B.3/§D.3).
 *
 * ── Lo que falta se dice, no se inventa ─────────────────────────────────────
 * Si falta un punto se avisa arriba del mapa y NO se dibuja nada en su lugar:
 * ni el centro de la ciudad, ni la sucursal activa, ni un "0,0". Con un solo
 * punto el mapa muestra ese punto; sin ninguno, no hay mapa.
 */

import * as React from "react"
import { useTheme } from "next-themes"
import { MapPin, WifiOff } from "lucide-react"

import { Alert, AlertDescription } from "@/components/ui/alert"
import { Button } from "@/components/ui/button"
import type { Order } from "@/hooks/use-orders"
import { resolveNumberLocale, type TenantLocaleConfig } from "@/lib/tenant-locale"

import type { Map as MapLibreMap, Marker as MapLibreMarker } from "maplibre-gl"

import "maplibre-gl/dist/maplibre-gl.css"

const OFM_STYLE_LIGHT = "https://tiles.openfreemap.org/styles/positron"
const OFM_STYLE_DARK = "https://tiles.openfreemap.org/styles/fiord"

/** Si el estilo no terminó de cargar en este lapso, asumimos que no hay red. */
const STYLE_LOAD_TIMEOUT_MS = 12_000

/** Marcadores con tokens del tema: el origen en el color del texto, el destino en el primario. */
const ORIGIN_MARKER_CLASS = "size-4 rounded-full border-2 border-background bg-foreground shadow-md"
const DESTINATION_MARKER_CLASS = "size-5 rounded-full border-2 border-background bg-primary shadow-md"

interface MapPoint {
  kind: "origin" | "destination"
  lat: number
  lng: number
  label: string
}

function isCoord(v: number | null | undefined): v is number {
  return typeof v === "number" && Number.isFinite(v)
}

/** Distancia en línea recta (haversine), en km. NO es el recorrido. */
export function straightLineKm(a: { lat: number; lng: number }, b: { lat: number; lng: number }): number {
  const R = 6371
  const toRad = (d: number) => (d * Math.PI) / 180
  const dLat = toRad(b.lat - a.lat)
  const dLng = toRad(b.lng - a.lng)
  const h =
    Math.sin(dLat / 2) ** 2 + Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.sin(dLng / 2) ** 2
  return 2 * R * Math.asin(Math.sqrt(h))
}

export function OrderDeliverySection({
  order,
  bootstrap,
}: {
  order: Order
  bootstrap: TenantLocaleConfig | null | undefined
}) {
  const origin: MapPoint | null =
    isCoord(order.outletLat) && isCoord(order.outletLng)
      ? {
          kind: "origin",
          lat: order.outletLat,
          lng: order.outletLng,
          label: order.outletName ? `Sucursal ${order.outletName}` : "Sucursal",
        }
      : null

  const hasSnapshot = isCoord(order.deliveryLat) && isCoord(order.deliveryLng)
  const hasContact = isCoord(order.customerLat) && isCoord(order.customerLng)
  const destination: MapPoint | null = hasSnapshot
    ? {
        kind: "destination",
        lat: order.deliveryLat as number,
        lng: order.deliveryLng as number,
        label: order.deliveryAddress || "Destino de la entrega",
      }
    : hasContact
      ? {
          kind: "destination",
          lat: order.customerLat as number,
          lng: order.customerLng as number,
          label: "Ubicación de la ficha del cliente",
        }
      : null

  const km = origin && destination ? straightLineKm(origin, destination) : null
  const kmLabel =
    km === null
      ? null
      : new Intl.NumberFormat(resolveNumberLocale(bootstrap), { maximumFractionDigits: 1 }).format(km)

  return (
    <div className="flex flex-col gap-3">
      {(order.deliveryAddress || order.deliveryReference) && (
        <div className="flex flex-col gap-0.5">
          {order.deliveryAddress && <p className="text-sm font-medium">{order.deliveryAddress}</p>}
          {order.deliveryReference && (
            <p className="text-sm text-muted-foreground">{order.deliveryReference}</p>
          )}
        </div>
      )}

      {!origin && (
        <Alert>
          <MapPin />
          <AlertDescription>
            {order.outletName ? `La sucursal ${order.outletName}` : "La sucursal de esta orden"} no
            tiene ubicación cargada, así que el origen no se dibuja. Se carga desde la ficha de la
            sucursal.
          </AlertDescription>
        </Alert>
      )}
      {!destination && (
        <Alert>
          <MapPin />
          <AlertDescription>
            La orden no guardó la ubicación de entrega y el cliente tampoco tiene una en su ficha,
            así que el destino no se dibuja.
          </AlertDescription>
        </Alert>
      )}
      {destination && !hasSnapshot && (
        <Alert>
          <MapPin />
          <AlertDescription>
            La orden no guardó la ubicación de entrega. El destino es la ubicación ACTUAL de la
            ficha del cliente, que puede no ser la dirección a la que se envió.
          </AlertDescription>
        </Alert>
      )}

      {(origin || destination) && <DeliveryMap origin={origin} destination={destination} />}

      {(origin || destination) && (
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex flex-wrap items-center gap-4 text-sm text-muted-foreground">
            {origin && (
              <span className="flex items-center gap-2">
                <span className={ORIGIN_MARKER_CLASS} aria-hidden />
                {origin.label}
              </span>
            )}
            {destination && (
              <span className="flex items-center gap-2">
                <span className={DESTINATION_MARKER_CLASS} aria-hidden />
                {hasSnapshot ? "Destino" : "Ficha del cliente"}
              </span>
            )}
            {kmLabel && <span className="tabular-nums">{kmLabel} km en línea recta</span>}
          </div>
          {origin && destination && (
            <Button asChild variant="outline" size="sm">
              <a
                href={`https://www.openstreetmap.org/directions?route=${origin.lat},${origin.lng};${destination.lat},${destination.lng}`}
                target="_blank"
                rel="noopener noreferrer"
              >
                Ver recorrido en OpenStreetMap
              </a>
            </Button>
          )}
        </div>
      )}
    </div>
  )
}

function DeliveryMap({ origin, destination }: { origin: MapPoint | null; destination: MapPoint | null }) {
  const { resolvedTheme } = useTheme()
  const isDark = resolvedTheme === "dark"

  const containerRef = React.useRef<HTMLDivElement | null>(null)
  const mapRef = React.useRef<MapLibreMap | null>(null)
  const markersRef = React.useRef<MapLibreMarker[]>([])
  const [loadFailed, setLoadFailed] = React.useState(false)
  const [mapReady, setMapReady] = React.useState(false)

  // Dependencias primitivas: los objetos se rearman en cada render del padre y
  // con ellos como deps los marcadores se recrearían en cada render.
  const points = React.useMemo(
    () => [origin, destination].filter((p): p is MapPoint => p !== null),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [origin?.lat, origin?.lng, origin?.label, destination?.lat, destination?.lng, destination?.label],
  )
  const pointsRef = React.useRef(points)
  React.useEffect(() => {
    pointsRef.current = points
  }, [points])

  // ── Creación del mapa (una sola vez) ──────────────────────────────────────
  React.useEffect(() => {
    if (!containerRef.current || mapRef.current) return
    let cancelled = false

    const timeout = window.setTimeout(() => {
      if (!cancelled && !mapRef.current?.isStyleLoaded()) setLoadFailed(true)
    }, STYLE_LOAD_TIMEOUT_MS)

    void import("maplibre-gl")
      .then((mod) => {
        if (cancelled || !containerRef.current) return
        const maplibregl = mod.default ?? mod
        const first = pointsRef.current[0]
        const map = new maplibregl.Map({
          container: containerRef.current,
          style: isDark ? OFM_STYLE_DARK : OFM_STYLE_LIGHT,
          // El encuadre real lo pone el efecto de marcadores; esto solo evita
          // el flash. `first` existe siempre: sin puntos no se monta el mapa.
          center: first ? [first.lng, first.lat] : [0, 0],
          zoom: 14,
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
    // Deliberadamente sin deps: el mapa vive hasta el unmount. Tema y puntos
    // se aplican en efectos aparte.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // ── Tema → setStyle. Los marcadores son DOM, sobreviven al cambio de estilo.
  React.useEffect(() => {
    if (!mapRef.current || !mapReady) return
    mapRef.current.setStyle(isDark ? OFM_STYLE_DARK : OFM_STYLE_LIGHT)
  }, [isDark, mapReady])

  // ── Puntos → marcadores + encuadre ────────────────────────────────────────
  React.useEffect(() => {
    const map = mapRef.current
    if (!map || !mapReady || points.length === 0) return
    let cancelled = false
    void import("maplibre-gl").then((mod) => {
      if (cancelled || !mapRef.current) return
      const maplibregl = mod.default ?? mod
      for (const m of markersRef.current) m.remove()
      markersRef.current = points.map((p) => {
        const el = document.createElement("div")
        el.className = p.kind === "origin" ? ORIGIN_MARKER_CLASS : DESTINATION_MARKER_CLASS
        el.setAttribute("role", "img")
        el.setAttribute("aria-label", p.label)
        el.title = p.label
        return new maplibregl.Marker({ element: el }).setLngLat([p.lng, p.lat]).addTo(map)
      })
      if (points.length === 1) {
        map.setCenter([points[0].lng, points[0].lat])
        map.setZoom(15)
        return
      }
      const bounds = new maplibregl.LngLatBounds(
        [points[0].lng, points[0].lat],
        [points[0].lng, points[0].lat],
      )
      for (const p of points) bounds.extend([p.lng, p.lat])
      map.fitBounds(bounds, { padding: 56, maxZoom: 16 })
    })
    return () => {
      cancelled = true
    }
  }, [points, mapReady])

  return (
    <div className="relative h-[320px] overflow-hidden rounded-xl border border-border bg-muted">
      <div ref={containerRef} className="size-full" />
      {loadFailed && (
        <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-muted p-6 text-center">
          <WifiOff className="size-6 text-muted-foreground" aria-hidden />
          <p className="text-sm font-medium text-foreground">No se pudo cargar el mapa</p>
          <p className="text-sm text-muted-foreground">
            El mapa necesita conexión a internet. La dirección de arriba se lee igual.
          </p>
        </div>
      )}
    </div>
  )
}
