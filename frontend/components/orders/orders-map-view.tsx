"use client"

/**
 * Vista mapa de /pos/ordenes — MapLibre GL + estilos vectoriales de
 * OpenFreeMap (mismo patrón que `AddressMapPreview` en
 * components/domain/contacts/contact-detail-view.tsx: dynamic import, sin API
 * key, positron/fiord según el tema).
 *
 * Pinta el local como PIN fijo (coords de `outlet.lat/lng`, mig 14, expuestas
 * por /v1/bootstrap → BFF) y un PIN por orden de DELIVERY, con las coords
 * SNAPSHOTEADAS en la propia orden (`deliveryLat`/`deliveryLng`, mig 94) — NO
 * las del contacto (`customerLat`/`customerLng`): el cliente puede mudarse
 * después de pedir, y esta orden fue a la dirección de ese momento
 * (context/27-delivery-sla-plan.md §B.3/§D.3).
 *
 * El filtro correcto es `fulfillment==='delivery'` (§B.4), no "tiene
 * coordenadas": una orden de mostrador nunca pertenece a este mapa, tenga o
 * no un cliente con ubicación cargada en su ficha. `page.tsx` ya filtra
 * antes de pasar `orders` acá. Dentro de los envíos, los que no tienen pin
 * (dirección pegada como texto, sin link de mapa — §D.5) NO se pierden: se
 * muestran como chip contador flotante con un Popover listando el detalle
 * (mismo tratamiento que el aviso de "sucursal sin ubicación").
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
import { createRoot, type Root } from "react-dom/client"
import { useTheme } from "next-themes"
import { MapPin, WifiOff } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { cn } from "@/lib/utils"
import { formatMoney } from "@/lib/format-money"
import { useCatalogStore } from "@/lib/catalog/store"
import type { Order } from "@/hooks/use-orders"
import { STATUS_VARIANT, orderTotal, statusLabelFor } from "@/lib/orders/order-display"
import { formatRelativeShort, parseNaive } from "@/lib/format-date"
import { KDS_TIER_ACCENT } from "@/lib/kds/kds-visuals"
import type { ElapsedTier } from "@/hooks/use-elapsed"

import type { Map as MapLibreMap, Marker as MapLibreMarker } from "maplibre-gl"

// Estilos de controles/popups de MapLibre (mismo import que AddressMapPreview).
import "maplibre-gl/dist/maplibre-gl.css"

const OFM_STYLE_LIGHT = "https://tiles.openfreemap.org/styles/positron"
const OFM_STYLE_DARK = "https://tiles.openfreemap.org/styles/fiord"

/** Centro de respaldo cuando la sucursal no tiene coordenadas cargadas (Asunción). */
const FALLBACK_CENTER: [number, number] = [-57.6359, -25.2637]

/** Si el estilo no terminó de cargar en este lapso, asumimos que no hay red. */
const STYLE_LOAD_TIMEOUT_MS = 12_000

/**
 * Desmonta los roots de React de los popups. Va en un microtask porque
 * `root.unmount()` sincrónico desde el cleanup de un efecto dispara el warning
 * "Attempted to synchronously unmount a root while React was already
 * rendering".
 */
function unmountPopupRoots(roots: Root[]): void {
  const snapshot = [...roots]
  queueMicrotask(() => {
    for (const r of snapshot) r.unmount()
  })
}

function hasCoords(o: Order): boolean {
  return (
    typeof o.deliveryLat === "number" &&
    typeof o.deliveryLng === "number" &&
    Number.isFinite(o.deliveryLat) &&
    Number.isFinite(o.deliveryLng)
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
  // Roots de React montados dentro de los popups de MapLibre (ver
  // OrderMapPopup). Se guardan para desmontarlos cuando los markers se
  // reconcilian o el componente muere: MapLibre destruye el nodo del popup,
  // pero el root de React seguiría vivo y filtrando memoria.
  const popupRootsRef = React.useRef<Root[]>([])
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
      unmountPopupRoots(popupRootsRef.current)
      popupRootsRef.current = []
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
      unmountPopupRoots(popupRootsRef.current)
      popupRootsRef.current = []

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
        const lng = order.deliveryLng as number
        const lat = order.deliveryLat as number

        const el = document.createElement("div")
        // min-w/h-9: touch target de 36px+ — se toca con el dedo en tablet.
        el.className =
          "flex h-9 min-w-9 cursor-pointer items-center justify-center rounded-full border-2 border-background bg-foreground px-2 text-xs font-bold tabular-nums text-background shadow-md"
        // Urgencia en el pin: mismos colores del canal de demora del KDS
        // (amber = warn, rose = late). fresh queda en bg-foreground neutral.
        const tier = orderElapsedTier(order)
        const accent = KDS_TIER_ACCENT[tier]
        if (accent) {
          el.style.backgroundColor = accent
          el.style.color = "#000"
        }
        el.textContent = order.orderNumber !== null ? `#${order.orderNumber}` : "#—"

        // Popup con React adentro (ver OrderMapPopup): nodo suelto + createRoot,
        // y `setDOMContent` en vez de `setHTML`. `closeButton: false` porque el
        // botón nativo de MapLibre es una × sin estilo que no matchea nada del
        // design system; el popup se cierra tocando el mapa u otro pin, que es
        // el gesto natural en una tablet.
        const popupNode = document.createElement("div")
        const root = createRoot(popupNode)
        popupRootsRef.current.push(root)
        const popup = new maplibregl.Popup({
          offset: 18,
          closeButton: false,
          className: "punto-map-popup",
          maxWidth: "none",
        }).setDOMContent(popupNode)

        root.render(
          <OrderMapPopup
            order={order}
            total={formatMoney(orderTotal(order), config)}
            onOpen={() => {
              popup.remove()
              onOpenOrderRef.current(order)
            }}
          />,
        )

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

        {/* Envíos con dirección pero SIN pin (§D.5 — dirección pegada como
            texto, sin link de mapa) no pueden quedar invisibles: mismo
            tratamiento visual que el aviso de sucursal sin ubicación, pero
            como chip contador flotante que abre el detalle en un Popover. */}
        {withoutCoords.length > 0 ? (
          <Popover>
            <PopoverTrigger asChild>
              <button
                type="button"
                // Abajo a la izquierda: arriba vive el aviso de "sucursal sin
                // ubicación" (inset-x-2 top-2, ocupa todo el ancho) y a la
                // derecha el control de atribución de MapLibre.
                className="absolute bottom-2 left-2 rounded-md bg-background/90 px-3 py-2 text-sm font-medium text-foreground shadow-sm hover:bg-background"
              >
                {withoutCoords.length} envío{withoutCoords.length !== 1 ? "s" : ""} sin ubicación
              </button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-80">
              <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                Sin ubicación ({withoutCoords.length})
              </p>
              <div className="flex max-h-64 flex-col gap-1 overflow-y-auto">
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
                    <Badge variant={STATUS_VARIANT[order.status]}>{statusLabelFor(order)}</Badge>
                  </Button>
                ))}
              </div>
            </PopoverContent>
          </Popover>
        ) : null}
      </div>
    </div>
  )
}

/** Ícono de local para el marker del outlet (lucide `Store`, inline por ser DOM crudo). */
const STORE_ICON_SVG = `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7"/><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="M15 22v-4a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2v4"/><path d="M2 7h20"/><path d="M22 7v3a2 2 0 0 1-2 2 2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 16 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 12 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 8 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 4 12a2 2 0 0 1-2-2V7"/></svg>`

/**
 * Contenido del popup de una orden.
 *
 * Es un componente React montado con `createRoot` sobre un nodo suelto que se
 * le pasa a MapLibre con `setDOMContent` — NO un string de HTML inyectado con
 * `setHTML`, como estaba antes. El string obligaba a reimplementar a mano cada
 * control (el botón era un `<button>` con clases copiadas de `Button`), así que
 * el popup quedaba fuera del design system y se desincronizaba solo:
 * cualquier cambio de los primitives no llegaba acá (regla del proyecto: shadcn
 * sobre HTML nativo, `context/14-ui-conventions.md`). Con React adentro usa los
 * MISMOS componentes que el resto del POS, y de paso desaparece el
 * `escapeHtml` — React escapa por construcción.
 *
 * El chrome propio de MapLibre (caja blanca, sombra, tip y botón de cerrar) se
 * neutraliza en `app/globals.css`, así que lo que se ve es esta Card y nada más.
 */
function OrderMapPopup({
  order,
  total,
  onOpen,
}: {
  order: Order
  total: string
  onOpen: () => void
}) {
  const number = order.orderNumber !== null ? `#${order.orderNumber}` : "#—"
  const since = order.sentAt ?? order.createdAt
  const age = since ? formatRelativeShort(since) : null
  // Edad con el MISMO canal de color que el pill del KDS (amber/rose) — el
  // cajero lee la urgencia igual en el mapa que en cocina.
  const accent = KDS_TIER_ACCENT[orderElapsedTier(order)]

  return (
    <div className="flex w-56 flex-col gap-2 rounded-lg border bg-popover p-3 text-popover-foreground shadow-md">
      <div className="flex items-baseline justify-between gap-2">
        <p className="text-sm font-semibold tabular-nums">Orden {number}</p>
        {age ? (
          <span
            className="text-xs font-medium tabular-nums text-muted-foreground"
            style={accent ? { color: accent } : undefined}
          >
            {age}
          </span>
        ) : null}
      </div>

      <p className="truncate text-sm text-muted-foreground" title={order.customerName ?? undefined}>
        {order.customerName ?? "Sin cliente"}
      </p>

      <div className="flex items-center justify-between gap-2">
        <Badge variant={STATUS_VARIANT[order.status] ?? "secondary"}>{statusLabelFor(order)}</Badge>
        <span className="text-sm font-semibold tabular-nums">{total}</span>
      </div>

      <p className="truncate text-xs text-muted-foreground">
        {order.courierName ?? "Sin repartidor"}
      </p>

      <Button size="sm" className="w-full" onClick={onOpen}>
        Ver detalle
      </Button>
    </div>
  )
}

/**
 * Tier de demora de una orden para el mapa. Mismos umbrales que la pantalla de
 * despacho (display-card.tsx: warn 5' / late 15') y mismo criterio de origen
 * (sentAt, con createdAt de fallback). `parseNaive` y no `new Date`: los
 * timestamps del negocio son naive tenant-local (ver lib/format-date.ts).
 * Snapshot al render — los markers se reconcilian con cada refetch (15s), así
 * que el tier se refresca solo.
 */
function orderElapsedTier(order: Order): ElapsedTier {
  const since = order.sentAt ?? order.createdAt
  if (!since) return "fresh"
  const d = parseNaive(since)
  if (!d) return "fresh"
  const minutes = Math.floor((Date.now() - d.getTime()) / 60000)
  return minutes >= 15 ? "late" : minutes >= 5 ? "warn" : "fresh"
}
