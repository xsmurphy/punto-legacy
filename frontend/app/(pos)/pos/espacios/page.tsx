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
import { FullscreenToggle } from "@/components/pos/fullscreen-toggle"
import { PosSpaceTile } from "@/components/spaces/pos-space-tile"
import { OpenSpaceDialog, type OpenSpaceValues } from "@/components/spaces/open-space-dialog"
import { SpaceSessionDialog } from "@/components/spaces/space-session-dialog"
import {
  EditSpaceSessionDialog,
  type EditSessionValues,
} from "@/components/spaces/edit-space-session-dialog"
import { MoveSpaceDialog } from "@/components/spaces/move-space-dialog"
import { MergeSpaceDialog } from "@/components/spaces/merge-space-dialog"
import { defaultSize } from "@/components/spaces/canvas-space-block"
import {
  usePosSpacesState,
  usePosSpaceSectors,
  useOpenSpaceSession,
  useRequestBill,
  useCancelSpaceSession,
  useUpdateSpaceSession,
  useMoveSpaceSession,
  useMergeSpaceSessions,
  type SpaceWithState,
} from "@/hooks/use-pos-spaces"
import { useCartStore } from "@/lib/cart/store"
import { useSpaceSettlementStore } from "@/lib/spaces/settlement-store"
import { usePersistedView } from "@/lib/ui/use-persisted-view"

const CANVAS_WIDTH = 900
const CANVAS_HEIGHT = 600
const ALL_SECTORS = "__all__"
const DECOR_SHAPES = ["decor_wall", "decor_plant", "bar"]

type SpaceView = "grid" | "map"
const VIEW_STORAGE_KEY = "punto.pos.espacios.view"
const SPACE_VIEWS = ["grid", "map"] as const

export default function EspaciosPage() {
  const router = useRouter()
  const { data: tablesData } = usePosSpacesState()
  const { data: sectorsData } = usePosSpaceSectors()
  const tables = tablesData?.spaces ?? []
  const sectors = sectorsData?.sectors ?? []

  const [activeSector, setActiveSector] = React.useState<string>(ALL_SECTORS)
  // Vista persistida por dispositivo (localStorage). Default: grilla.
  const [view, selectView] = usePersistedView<SpaceView>(VIEW_STORAGE_KEY, SPACE_VIEWS, "grid")

  // El mapa NO admite "Todos": cada sector tiene su propio plano y las
  // coordenadas del editor son POR SECTOR, así que superponerlos dibuja mesas
  // una arriba de otra (reporte del owner 2026-07-30). Al entrar al mapa con
  // "Todos" activo se cae al primer sector; el pill "Todos" se oculta en mapa.
  // Con 0 sectores no hay nada que superponer y "Todos" sigue siendo válido.
  React.useEffect(() => {
    if (view === "map" && activeSector === ALL_SECTORS && sectors.length > 0) {
      setActiveSector(sectors[0].id)
    }
  }, [view, activeSector, sectors])

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
  // Gestión de la mesa (editar / mover / unir). Cada uno guarda SU propia mesa
  // en vez de un `mode` compartido: los tres se abren desde el diálogo de
  // sesión, que se cierra al hacerlo, y necesitan recordar sobre cuál operan.
  const [editingTable, setEditingTable] = React.useState<SpaceWithState | null>(null)
  const [movingTable, setMovingTable] = React.useState<SpaceWithState | null>(null)
  const [mergingTable, setMergingTable] = React.useState<SpaceWithState | null>(null)

  const openSession = useOpenSpaceSession()
  const requestBill = useRequestBill()
  const cancelSession = useCancelSpaceSession()
  const updateSession = useUpdateSpaceSession()
  const moveSession = useMoveSpaceSession()
  const mergeSessions = useMergeSpaceSessions()

  const setSelectedSpace = useCartStore((s) => s.setSelectedSpace)
  // El diálogo de split (elección de modo de cobro), su carga del carrito y
  // la reconciliación post-cobro parcial viven en un provider persistente
  // del layout del POS (`components/spaces/space-settlement-provider.tsx`,
  // montado en `app/(pos)/pos/layout.tsx`) — no acá. Bug T8: ese flujo
  // necesita sobrevivir a la navegación a `/pos` (el carrito recién cargado
  // queda accesible detrás), y este módulo puede desmontarse al navegar. Acá
  // solo se DISPARA el flujo — ver `handleCharge` abajo.
  const setSplitTarget = useSpaceSettlementStore((s) => s.setSplitTarget)

  function handleTileClick(table: SpaceWithState) {
    if (table.state === "free") {
      setOpeningTable(table)
    } else {
      setSessionTable(table)
    }
  }

  async function confirmOpenTable(values: OpenSpaceValues) {
    if (!openingTable) return
    try {
      const session = await openSession.mutateAsync({
        tableId: openingTable.id,
        guests: values.guests,
        waiterId: values.waiterId,
        alias: values.alias,
      })
      setSelectedSpace(session.id, openingTable.name)
      setOpeningTable(null)
      router.push("/pos")
    } catch (err) {
      toast.error("No se pudo abrir el espacio", {
        description: err instanceof Error ? err.message : String(err),
      })
    }
  }

  async function confirmEditSession(values: EditSessionValues) {
    if (!editingTable?.session) return
    try {
      await updateSession.mutateAsync({ sessionId: editingTable.session.id, ...values })
      toast.success(`${editingTable.name} actualizada`)
      setEditingTable(null)
    } catch (err) {
      toast.error("No se pudo actualizar la mesa", {
        description: err instanceof Error ? err.message : String(err),
      })
    }
  }

  async function confirmMove(targetSpaceId: string) {
    if (!movingTable?.session) return
    const target = tables.find((t) => t.id === targetSpaceId)
    try {
      await moveSession.mutateAsync({ sessionId: movingTable.session.id, targetSpaceId })
      toast.success(`${movingTable.name} se movió a ${target?.name ?? "la mesa destino"}`)
      setMovingTable(null)
    } catch (err) {
      toast.error("No se pudo mover la mesa", {
        description: err instanceof Error ? err.message : String(err),
      })
    }
  }

  async function confirmMerge(targetSessionId: string) {
    if (!mergingTable?.session) return
    const target = tables.find((t) => t.session?.id === targetSessionId)
    try {
      await mergeSessions.mutateAsync({ sessionId: mergingTable.session.id, targetSessionId })
      toast.success(`${mergingTable.name} se unió a ${target?.name ?? "la otra mesa"}`)
      setMergingTable(null)
    } catch (err) {
      toast.error("No se pudieron unir las mesas", {
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

  /**
   * "Cobrar" en el diálogo de sesión → elegir modo de cobro (total o split).
   * El diálogo de split en sí y todo lo que sigue (armar el carrito,
   * navegar a `/pos`, reconciliar el saldo) lo maneja `SpaceSettlementProvider`
   * — acá solo se le pasa la posta.
   */
  function handleCharge() {
    if (!sessionTable?.session) return
    setSplitTarget({ sessionId: sessionTable.session.id, spaceName: sessionTable.name })
    setSessionTable(null)
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
        // Los tres cierran el diálogo de sesión y abren el suyo: dos modales
        // apilados en una tablet tapan la pantalla entera y dejan al cajero
        // sin saber cuál está operando.
        onEdit={() => {
          setEditingTable(sessionTable)
          setSessionTable(null)
        }}
        onMove={() => {
          setMovingTable(sessionTable)
          setSessionTable(null)
        }}
        onMerge={() => {
          setMergingTable(sessionTable)
          setSessionTable(null)
        }}
        requestBillPending={requestBill.isPending}
        cancelPending={cancelSession.isPending}
      />
      <EditSpaceSessionDialog
        table={editingTable}
        onOpenChange={(v) => !v && setEditingTable(null)}
        onConfirm={confirmEditSession}
        submitting={updateSession.isPending}
      />
      <MoveSpaceDialog
        table={movingTable}
        spaces={tables}
        onOpenChange={(v) => !v && setMovingTable(null)}
        onConfirm={confirmMove}
        submitting={moveSession.isPending}
      />
      <MergeSpaceDialog
        table={mergingTable}
        spaces={tables}
        onOpenChange={(v) => !v && setMergingTable(null)}
        onConfirm={confirmMerge}
        submitting={mergeSessions.isPending}
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
          <div className="grid grid-cols-3 gap-3 sm:grid-cols-[repeat(auto-fill,minmax(8.5rem,1fr))]">
            {/* Columnas por ANCHO DE CELDA, no por breakpoint: el tile mide
                siempre ~8.5rem y la cantidad de columnas sale del ancho real
                disponible. Es lo que hace que "pantalla completa" (que oculta el
                carrito) muestre MÁS mesas en vez de agrandar las mismas — con
                `lg:grid-cols-6` fijas, ensanchar el contenedor estiraba las 6
                columnas (reporte del owner 2026-08-21). Mobile queda en 3
                columnas fijas: ahí el módulo ocupa la pantalla entera y
                `auto-fill` con este mínimo daría solo 2. */}
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
            {/* "Todos" solo en grilla (o sin sectores) — ver el efecto de arriba. */}
            {(view !== "map" || sectors.length === 0) && (
              <SectorPill
                label="Todos"
                active={activeSector === ALL_SECTORS}
                onClick={() => setActiveSector(ALL_SECTORS)}
              />
            )}
            {sectors.map((s) => (
              <SectorPill
                key={s.id}
                label={s.name}
                active={activeSector === s.id}
                onClick={() => setActiveSector(s.id)}
              />
            ))}
          </div>
          {/* Toggle de pantalla completa — posición fija al final de la pill
              (regla del POS: posiciones estables, sin desplazamientos). */}
          <div className="hidden h-6 w-px shrink-0 bg-white/15 md:block" />
          <FullscreenToggle />
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
