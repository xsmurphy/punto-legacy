"use client"

/**
 * Mapa de espacios — módulo Espacios del POS (context/15-espacios-module-plan.md
 * F2). Genérico según el rubro: mesas (gastronomía), sillas de atención
 * (peluquerías), habitaciones (hostales/hoteles).
 *
 * Ocupa el slot de hotkeys (bloque izquierdo del workspace, ver
 * `app/(pos)/pos/layout.tsx`) — el carrito de la derecha es el mismo
 * CartPanel persistente de siempre, ahora "en modo espacio" cuando hay una
 * `spaceSessionId` seleccionada en el cart store.
 *
 * Render: si los espacios del sector tienen posición custom (F1, editor de
 * layout) → canvas absoluto escalado responsive al contenedor (mismo canvas
 * 900×600 que `layout-editor.tsx`, solo lectura). Si no → grilla numerada
 * fallback (mismo criterio que el toggle "Vista grilla" del editor).
 */

import * as React from "react"
import { useRouter } from "next/navigation"
import { toast } from "sonner"
import { cn } from "@/lib/utils"
import { LayoutGrid, Map } from "lucide-react"
import { EmptyState } from "@/components/empty-state"
import { PosSpaceTile } from "@/components/spaces/pos-space-tile"
import { OpenSpaceDialog } from "@/components/spaces/open-space-dialog"
import { SpaceSessionDialog } from "@/components/spaces/space-session-dialog"
import { defaultSize } from "@/components/spaces/canvas-space-block"
import {
  usePosSpacesState,
  usePosSpaceSectors,
  useOpenSpaceSession,
  useRequestBill,
  useCancelSpaceSession,
  type SpaceWithState,
} from "@/hooks/use-pos-spaces"
import { fetchOrderDetail, fetchOrdersBySession } from "@/hooks/use-orders"
import { useCartStore } from "@/lib/cart/store"
import { usePosUIStore } from "@/lib/ui/store"

const CANVAS_WIDTH = 900
const CANVAS_HEIGHT = 600
const ALL_SECTORS = "__all__"
const DECOR_SHAPES = ["decor_wall", "decor_plant", "bar"]

type SpaceView = "grid" | "map"
const VIEW_STORAGE_KEY = "punto.pos.espacios.view"

// Config local por dispositivo (patrón KdsConfig, pero inline): la vista
// elegida persiste en localStorage y se restaura al volver. Default: grilla.
function readStoredView(): SpaceView {
  if (typeof window === "undefined") return "grid"
  return window.localStorage.getItem(VIEW_STORAGE_KEY) === "map" ? "map" : "grid"
}

export default function EspaciosPage() {
  const router = useRouter()
  const { data: tablesData } = usePosSpacesState()
  const { data: sectorsData } = usePosSpaceSectors()
  const tables = tablesData?.spaces ?? []
  const sectors = sectorsData?.sectors ?? []

  const [activeSector, setActiveSector] = React.useState<string>(ALL_SECTORS)
  // SSR guard: arranca en 'grid' y se hidrata desde localStorage tras montar.
  const [view, setView] = React.useState<SpaceView>("grid")
  React.useEffect(() => {
    setView(readStoredView())
  }, [])
  const selectView = React.useCallback((v: SpaceView) => {
    setView(v)
    if (typeof window !== "undefined") window.localStorage.setItem(VIEW_STORAGE_KEY, v)
  }, [])

  const sectorTables = React.useMemo(
    () => (activeSector === ALL_SECTORS ? tables : tables.filter((t) => t.sectorId === activeSector)),
    [tables, activeSector],
  )
  // Grilla: SOLO espacios reales (los decorativos —barra/pared/planta— nunca
  // aportan a la grilla numerada aunque tengan posición custom).
  const gridTables = React.useMemo(
    () => sectorTables.filter((t) => !DECOR_SHAPES.includes(t.shape)),
    [sectorTables],
  )
  // Mapa: espacios reales + decorativos que tengan posición custom del editor.
  const mapTables = React.useMemo(
    () => sectorTables.filter((t) => !DECOR_SHAPES.includes(t.shape) || (t.posX !== null && t.posY !== null)),
    [sectorTables],
  )
  const hasCustomLayout = mapTables.some((t) => t.posX !== null && t.posY !== null)

  const [openingTable, setOpeningTable] = React.useState<SpaceWithState | null>(null)
  const [sessionTable, setSessionTable] = React.useState<SpaceWithState | null>(null)
  const [chargingSessionId, setChargingSessionId] = React.useState<string | null>(null)

  const openSession = useOpenSpaceSession()
  const requestBill = useRequestBill()
  const cancelSession = useCancelSpaceSession()

  const setSelectedSpace = useCartStore((s) => s.setSelectedSpace)
  const loadFromSession = useCartStore((s) => s.loadFromSession)
  const setPayOpen = usePosUIStore((s) => s.setPayOpen)

  function handleTileClick(table: SpaceWithState) {
    if (table.state === "free") {
      setOpeningTable(table)
    } else {
      setSessionTable(table)
    }
  }

  async function confirmOpenTable(guests: number | undefined) {
    if (!openingTable) return
    try {
      const session = await openSession.mutateAsync({ tableId: openingTable.id, guests })
      setSelectedSpace(session.id, openingTable.name)
      setOpeningTable(null)
      router.push("/pos")
    } catch (err) {
      toast.error("No se pudo abrir el espacio", {
        description: err instanceof Error ? err.message : String(err),
      })
    }
  }

  function handleAddOrder() {
    if (!sessionTable?.session) return
    setSelectedSpace(sessionTable.session.id, sessionTable.name)
    setSessionTable(null)
    router.push("/pos")
  }

  async function handleRequestBill() {
    if (!sessionTable?.session) return
    try {
      await requestBill.mutateAsync(sessionTable.session.id)
      toast.success(`${sessionTable.name} — cuenta pedida`)
      setSessionTable(null)
    } catch (err) {
      toast.error("No se pudo pedir la cuenta", {
        description: err instanceof Error ? err.message : String(err),
      })
    }
  }

  async function handleCancelSession() {
    if (!sessionTable?.session) return
    try {
      await cancelSession.mutateAsync(sessionTable.session.id)
      toast.success(`${sessionTable.name} liberado`)
      setSessionTable(null)
    } catch (err) {
      toast.error("No se pudo cancelar la sesión", {
        description: err instanceof Error ? err.message : String(err),
      })
    }
  }

  async function handleCharge() {
    if (!sessionTable?.session) return
    const sessionId = sessionTable.session.id
    const spaceName = sessionTable.name
    setChargingSessionId(sessionId)
    try {
      const { orders: summaries } = await fetchOrdersBySession(sessionId)
      const billable = summaries.filter((o) => o.status !== "closed" && o.status !== "cancelled")
      if (billable.length === 0) {
        toast.error("El espacio no tiene órdenes por cobrar")
        return
      }
      const orders = await Promise.all(billable.map((o) => fetchOrderDetail(o.id)))
      loadFromSession(sessionId, spaceName, orders)
      setSessionTable(null)
      setPayOpen(true)
    } catch (err) {
      toast.error("No se pudo preparar el cobro del espacio", {
        description: err instanceof Error ? err.message : String(err),
      })
    } finally {
      setChargingSessionId(null)
    }
  }

  return (
    <div className="relative flex h-full flex-col overflow-hidden">
      <OpenSpaceDialog
        table={openingTable}
        onOpenChange={(v) => !v && setOpeningTable(null)}
        onConfirm={confirmOpenTable}
        submitting={openSession.isPending}
      />
      <SpaceSessionDialog
        table={sessionTable}
        onOpenChange={(v) => !v && setSessionTable(null)}
        onAddOrder={handleAddOrder}
        onRequestBill={handleRequestBill}
        onCharge={handleCharge}
        onCancelSession={handleCancelSession}
        requestBillPending={requestBill.isPending}
        cancelPending={cancelSession.isPending}
        chargePending={chargingSessionId === sessionTable?.session?.id}
      />

      {/* pb-16: deja espacio para que la barra flotante no tape los tiles. */}
      <div className="flex-1 overflow-auto p-3 pb-16">
        {view === "map" ? (
          hasCustomLayout ? (
            <ScaledCanvas>
              {mapTables.map((table) => {
                const size = defaultSize(table.shape)
                return (
                  <PosSpaceTile
                    key={table.id}
                    table={table}
                    onClick={() => handleTileClick(table)}
                    position={{
                      x: table.posX ?? 0,
                      y: table.posY ?? 0,
                      width: table.width ?? size.width,
                      height: table.height ?? size.height,
                      rotation: table.rotation,
                    }}
                  />
                )
              })}
            </ScaledCanvas>
          ) : (
            <EmptyState
              icon={Map}
              title="Sin plano configurado"
              description="Armá el plano en Ajustes → Espacios, o usá la vista grilla."
              className="h-full"
            />
          )
        ) : gridTables.length === 0 ? (
          <EmptyState
            icon={LayoutGrid}
            title="Sin espacios configurados en este sector"
            description="Configuralos desde Ajustes → Espacios."
            className="h-full"
          />
        ) : (
          <div className="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6">
            {gridTables.map((table) => (
              <div key={table.id} className="aspect-square">
                <PosSpaceTile table={table} onClick={() => handleTileClick(table)} />
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Barra flotante — misma visual/posición que la barra de categorías del
          modo venta (product-area.tsx): pill oscura #22252A (excepción documentada
          del design system). Existe SIEMPRE (Regla #10): switch de vista +
          sectores. Con 0 sectores quedan el switch y el pill "Todos". */}
      <div className="pointer-events-none absolute inset-x-0 bottom-3 z-10 flex px-3">
        <div className="pointer-events-auto flex w-full items-center gap-2 rounded-full bg-[#22252A] py-1.5 pl-1.5 pr-3 shadow-lg">
          {/* Switch de vista Grilla / Mapa (segmented). */}
          <div className="flex shrink-0 items-center gap-1">
            <ViewButton
              icon={LayoutGrid}
              label="Grilla"
              active={view === "grid"}
              onClick={() => selectView("grid")}
            />
            <ViewButton
              icon={Map}
              label="Mapa"
              active={view === "map"}
              onClick={() => selectView("map")}
            />
          </div>
          <div className="h-6 w-px shrink-0 bg-white/15" />
          {/* Pills de sectores — scroll horizontal (igual que categorías). */}
          <div
            className="flex flex-1 items-center gap-1 overflow-x-auto whitespace-nowrap"
            style={{ scrollbarWidth: "none" }}
          >
            <SectorPill
              label="Todos"
              active={activeSector === ALL_SECTORS}
              onClick={() => setActiveSector(ALL_SECTORS)}
            />
            {sectors.map((s) => (
              <SectorPill
                key={s.id}
                label={s.name}
                active={activeSector === s.id}
                onClick={() => setActiveSector(s.id)}
              />
            ))}
          </div>
        </div>
      </div>
    </div>
  )
}

function ViewButton({
  icon: Icon,
  label,
  active,
  onClick,
}: {
  icon: typeof LayoutGrid
  label: string
  active: boolean
  onClick: () => void
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={`Vista ${label.toLowerCase()}`}
      aria-pressed={active}
      className={cn(
        "flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-bold transition-colors",
        active ? "bg-white text-neutral-900" : "text-white/80 hover:text-white",
      )}
    >
      <Icon className="size-4" />
      {label}
    </button>
  )
}

function SectorPill({
  label,
  active,
  onClick,
}: {
  label: string
  active: boolean
  onClick: () => void
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        "shrink-0 rounded-full px-3 py-1.5 text-sm font-bold transition-colors",
        active ? "bg-white text-neutral-900" : "text-white/80 hover:text-white",
      )}
    >
      {label}
    </button>
  )
}

/**
 * Envoltorio del canvas 900×600 (mismo tamaño que `layout-editor.tsx`)
 * escalado por `transform: scale()` al ancho real del contenedor — el POS
 * corre en pantallas de tamaños muy distintos (tablet a desktop), a
 * diferencia del editor del panel que asume una ventana grande.
 */
function ScaledCanvas({ children }: { children: React.ReactNode }) {
  const containerRef = React.useRef<HTMLDivElement>(null)
  const [scale, setScale] = React.useState(1)

  React.useEffect(() => {
    const el = containerRef.current
    if (!el) return
    const observer = new ResizeObserver((entries) => {
      const width = entries[0]?.contentRect.width ?? CANVAS_WIDTH
      setScale(Math.min(1, width / CANVAS_WIDTH))
    })
    observer.observe(el)
    return () => observer.disconnect()
  }, [])

  return (
    <div ref={containerRef} style={{ height: CANVAS_HEIGHT * scale }}>
      <div
        className="relative rounded-md border border-border bg-muted/30"
        style={{
          width: CANVAS_WIDTH,
          height: CANVAS_HEIGHT,
          transform: `scale(${scale})`,
          transformOrigin: "top left",
        }}
      >
        {children}
      </div>
    </div>
  )
}
