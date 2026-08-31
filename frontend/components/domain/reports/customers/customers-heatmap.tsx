"use client"

/**
 * Mapa de calor de densidad de clientes — MapLibre GL + estilos vectoriales de
 * OpenFreeMap, mismo patrón que `components/orders/orders-map-view.tsx`
 * (dynamic import, sin API key, positron/fiord según el tema, degradación a
 * mensaje si no carga el estilo).
 *
 * Se usa la capa `heatmap` NATIVA de MapLibre en vez de pintar un marker por
 * cliente: con dos mil puntos superpuestos un mapa de pines no dice nada, y la
 * pregunta del reporte ("¿en qué zonas se concentran?") es exactamente lo que
 * un heatmap responde.
 *
 * El peso de cada punto es cuántos clientes comparten esa coordenada (el
 * backend agrupa por coordenada redondeada), así que un edificio con diez
 * clientes pesa diez, no uno.
 *
 * Ciclo de vida: el mapa se crea una vez y se destruye en el unmount. La
 * fuente y la capa se re-crean en `styledata` porque `setStyle()` (cambio de
 * tema) borra todo lo que no venga del estilo base.
 */

import * as React from "react"
import { useTheme } from "next-themes"
import { WifiOff } from "lucide-react"

import { resolveColorBg } from "@/lib/ui/color-palette"

import type { LayerSpecification, Map as MapLibreMap } from "maplibre-gl"

import "maplibre-gl/dist/maplibre-gl.css"

const OFM_STYLE_LIGHT = "https://tiles.openfreemap.org/styles/positron"
const OFM_STYLE_DARK = "https://tiles.openfreemap.org/styles/fiord"

/** Si el estilo no terminó de cargar en este lapso, asumimos que no hay red. */
const STYLE_LOAD_TIMEOUT_MS = 12_000

const SOURCE_ID = "clientes-densidad"
const LAYER_ID = "clientes-densidad-heat"

/**
 * Rampa de color de la densidad. Un heatmap es un degradado por definición, así
 * que no puede salir de los tokens semánticos (que son colores sueltos): se
 * arma con `PALETTE_COLORS`, la paleta fija del proyecto (context/20 §3), en
 * vez de inventar hex nuevos. Frío = pocos clientes, cálido = concentración.
 */
const RAMP = ["sky", "emerald", "amber", "rose"].map(
  (key) => resolveColorBg(key) || "#64748b",
)

export interface HeatPoint {
  lat: number
  lng: number
  peso: number
}

export function CustomersHeatmap({ points }: { points: HeatPoint[] }) {
  const { resolvedTheme } = useTheme()
  const isDark = resolvedTheme === "dark"

  const containerRef = React.useRef<HTMLDivElement | null>(null)
  const mapRef = React.useRef<MapLibreMap | null>(null)
  const didFitRef = React.useRef(false)
  // Los puntos se leen por ref desde el handler de `styledata`, que se
  // registra una sola vez: sin esto el handler capturaría el primer array y
  // seguiría repintando datos viejos tras un refetch.
  const pointsRef = React.useRef(points)

  const [loadFailed, setLoadFailed] = React.useState(false)
  const [mapReady, setMapReady] = React.useState(false)

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
        const map = new maplibregl.Map({
          container: containerRef.current,
          style: isDark ? OFM_STYLE_DARK : OFM_STYLE_LIGHT,
          // El encuadre real lo pone `fitBounds` sobre los puntos apenas
          // carga el estilo; este centro solo evita el flash inicial.
          center: [0, 0],
          zoom: 1,
          attributionControl: { compact: true },
        })
        mapRef.current = map

        // `styledata` y no solo `load`: al cambiar de tema MapLibre reemplaza
        // el estilo entero y se lleva puestas la fuente y la capa.
        map.on("styledata", () => {
          if (cancelled) return
          applyHeat(map, pointsRef.current)
        })

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
      mapRef.current?.remove()
      mapRef.current = null
      setMapReady(false)
    }
    // Deliberadamente sin deps: el mapa se crea una vez y vive hasta el
    // unmount. Tema y datos se aplican en efectos aparte.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // ── Tema → setStyle (la capa se re-crea en el handler de `styledata`) ─────
  React.useEffect(() => {
    if (!mapRef.current || !mapReady) return
    mapRef.current.setStyle(isDark ? OFM_STYLE_DARK : OFM_STYLE_LIGHT)
  }, [isDark, mapReady])

  // ── Datos → refresca la fuente y encuadra la primera vez ──────────────────
  React.useEffect(() => {
    const map = mapRef.current
    if (!map || !mapReady) return
    applyHeat(map, points)

    if (didFitRef.current || points.length === 0) return
    didFitRef.current = true
    void import("maplibre-gl").then((mod) => {
      if (!mapRef.current) return
      const maplibregl = mod.default ?? mod
      if (points.length === 1) {
        map.setCenter([points[0].lng, points[0].lat])
        map.setZoom(14)
        return
      }
      const bounds = points.reduce(
        (b, p) => b.extend([p.lng, p.lat] as [number, number]),
        new maplibregl.LngLatBounds(
          [points[0].lng, points[0].lat],
          [points[0].lng, points[0].lat],
        ),
      )
      map.fitBounds(bounds, { padding: 48, maxZoom: 15 })
    })
  }, [points, mapReady])

  return (
    <div className="relative h-[420px] overflow-hidden rounded-xl border border-border bg-muted">
      <div ref={containerRef} className="size-full" />
      {loadFailed ? (
        <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-muted p-6 text-center">
          <WifiOff className="size-6 text-muted-foreground" aria-hidden />
          <p className="text-sm font-medium text-foreground">
            No se pudo cargar el mapa
          </p>
          <p className="text-sm text-muted-foreground">
            El mapa necesita conexión a internet. El ranking de localidades y
            ciudades de abajo funciona igual.
          </p>
        </div>
      ) : null}
    </div>
  )
}

/**
 * Crea (o actualiza) la fuente GeoJSON y la capa de calor. Es idempotente
 * porque corre tanto al llegar datos nuevos como cada vez que MapLibre
 * reemplaza el estilo.
 */
function applyHeat(map: MapLibreMap, points: HeatPoint[]): void {
  // Durante el `setStyle` hay una ventana en la que el estilo todavía no está
  // disponible y `addSource`/`addLayer` tiran. `styledata` dispara varias
  // veces por cambio de estilo, así que el intento fallido se recupera solo en
  // el siguiente evento: se traga el error en vez de romper el handler (y con
  // él el mapa entero).
  try {
    if (!map.style) return

    const data = {
      type: "FeatureCollection" as const,
      features: points.map((p) => ({
        type: "Feature" as const,
        properties: { peso: p.peso },
        geometry: { type: "Point" as const, coordinates: [p.lng, p.lat] },
      })),
    }

    const existing = map.getSource(SOURCE_ID)
    if (existing) {
      // @ts-expect-error — `setData` existe en GeoJSONSource; el union de
      // `getSource` no lo estrecha sin un type guard que no aporta nada acá.
      existing.setData(data)
    } else {
      map.addSource(SOURCE_ID, { type: "geojson", data })
    }

    // Peso máximo real de la tanda: si se fijara en 1, una zona con 40
    // clientes en el mismo punto saturaría todo el mapa en el extremo cálido
    // y no se distinguiría ningún gradiente. Se recalcula SIEMPRE — con datos
    // nuevos y el mismo layer vivo, un máximo viejo deforma la escala.
    const maxPeso = Math.max(1, ...points.map((p) => p.peso))

    // Anotado como `LayerSpecification` para que TS tipe contextualmente las
    // expresiones del `paint` (los literales sueltos se infieren como
    // `string[]` y no matchean la spec de MapLibre).
    const layer: LayerSpecification = {
      id: LAYER_ID,
      type: "heatmap",
      source: SOURCE_ID,
      paint: {
        "heatmap-weight": [
          "interpolate",
          ["linear"],
          ["get", "peso"],
          0,
          0,
          maxPeso,
          1,
        ],
        "heatmap-intensity": ["interpolate", ["linear"], ["zoom"], 0, 1, 16, 3],
        "heatmap-color": [
          "interpolate",
          ["linear"],
          ["heatmap-density"],
          0,
          "rgba(0,0,0,0)",
          0.2,
          RAMP[0],
          0.45,
          RAMP[1],
          0.7,
          RAMP[2],
          1,
          RAMP[3],
        ],
        "heatmap-radius": ["interpolate", ["linear"], ["zoom"], 0, 6, 16, 36],
        "heatmap-opacity": 0.75,
      },
    }

    if (map.getLayer(LAYER_ID)) {
      map.setPaintProperty(
        LAYER_ID,
        "heatmap-weight",
        layer.paint?.["heatmap-weight"],
      )
      return
    }
    map.addLayer(layer)
  } catch {
    // Estilo a medio cargar — el próximo `styledata` reintenta.
  }
}
