"use client"

/**
 * Mapa de mesas — módulo Mesas del POS (context/15-mesas-module-plan.md F2).
 *
 * Ocupa el slot de hotkeys (bloque izquierdo del workspace, ver
 * `app/(pos)/pos/layout.tsx`) — el carrito de la derecha es el mismo
 * CartPanel persistente de siempre, ahora "en modo mesa" cuando hay una
 * `tableSessionId` seleccionada en el cart store.
 *
 * Render: si las mesas del sector tienen posición custom (F1, editor de
 * layout) → canvas absoluto escalado responsive al contenedor (mismo canvas
 * 900×600 que `layout-editor.tsx`, solo lectura). Si no → grilla numerada
 * fallback (mismo criterio que el toggle "Vista grilla" del editor).
 */

import * as React from "react"
import { useRouter } from "next/navigation"
import { toast } from "sonner"
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { Utensils } from "lucide-react"
import { PosTableTile } from "@/components/tables/pos-table-tile"
import { OpenTableDialog } from "@/components/tables/open-table-dialog"
import { TableSessionSheet } from "@/components/tables/table-session-sheet"
import { defaultSize } from "@/components/tables/canvas-table-block"
import {
  usePosTablesState,
  usePosTableSectors,
  useOpenTableSession,
  useRequestBill,
  useCancelTableSession,
  type DiningTableWithState,
} from "@/hooks/use-pos-tables"
import { fetchOrderDetail, fetchOrdersBySession } from "@/hooks/use-orders"
import { useCartStore } from "@/lib/cart/store"
import { usePosUIStore } from "@/lib/ui/store"

const CANVAS_WIDTH = 900
const CANVAS_HEIGHT = 600
const ALL_SECTORS = "__all__"

export default function MesasPage() {
  const router = useRouter()
  const { data: tablesData } = usePosTablesState()
  const { data: sectorsData } = usePosTableSectors()
  const tables = tablesData?.tables ?? []
  const sectors = sectorsData?.sectors ?? []

  const [activeSector, setActiveSector] = React.useState<string>(ALL_SECTORS)
  const sectorTables = React.useMemo(
    () => (activeSector === ALL_SECTORS ? tables : tables.filter((t) => t.sectorId === activeSector)),
    [tables, activeSector],
  )
  const hasCustomLayout = sectorTables.some((t) => t.posX !== null && t.posY !== null)

  const [openingTable, setOpeningTable] = React.useState<DiningTableWithState | null>(null)
  const [sessionTable, setSessionTable] = React.useState<DiningTableWithState | null>(null)
  const [chargingSessionId, setChargingSessionId] = React.useState<string | null>(null)

  const openSession = useOpenTableSession()
  const requestBill = useRequestBill()
  const cancelSession = useCancelTableSession()

  const setSelectedTable = useCartStore((s) => s.setSelectedTable)
  const loadFromSession = useCartStore((s) => s.loadFromSession)
  const setPayOpen = usePosUIStore((s) => s.setPayOpen)

  function handleTileClick(table: DiningTableWithState) {
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
      setSelectedTable(session.id, openingTable.name)
      setOpeningTable(null)
      router.push("/pos")
    } catch (err) {
      toast.error("No se pudo abrir la mesa", {
        description: err instanceof Error ? err.message : String(err),
      })
    }
  }

  function handleAddOrder() {
    if (!sessionTable?.session) return
    setSelectedTable(sessionTable.session.id, sessionTable.name)
    setSessionTable(null)
    router.push("/pos")
  }

  async function handleRequestBill() {
    if (!sessionTable?.session) return
    try {
      await requestBill.mutateAsync(sessionTable.session.id)
      toast.success(`Mesa ${sessionTable.name} — cuenta pedida`)
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
      toast.success(`Mesa ${sessionTable.name} liberada`)
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
    const tableName = sessionTable.name
    setChargingSessionId(sessionId)
    try {
      const { orders: summaries } = await fetchOrdersBySession(sessionId)
      const billable = summaries.filter((o) => o.status !== "closed" && o.status !== "cancelled")
      if (billable.length === 0) {
        toast.error("La mesa no tiene órdenes por cobrar")
        return
      }
      const orders = await Promise.all(billable.map((o) => fetchOrderDetail(o.id)))
      loadFromSession(sessionId, tableName, orders)
      setSessionTable(null)
      setPayOpen(true)
    } catch (err) {
      toast.error("No se pudo preparar el cobro de la mesa", {
        description: err instanceof Error ? err.message : String(err),
      })
    } finally {
      setChargingSessionId(null)
    }
  }

  return (
    <div className="relative flex h-full flex-col overflow-hidden">
      <OpenTableDialog
        table={openingTable}
        onOpenChange={(v) => !v && setOpeningTable(null)}
        onConfirm={confirmOpenTable}
        submitting={openSession.isPending}
      />
      <TableSessionSheet
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

      {sectors.length > 0 && (
        <div className="shrink-0 border-b border-border px-3 py-2">
          <Tabs value={activeSector} onValueChange={setActiveSector}>
            <TabsList>
              <TabsTrigger value={ALL_SECTORS}>Todos</TabsTrigger>
              {sectors.map((s) => (
                <TabsTrigger key={s.id} value={s.id}>
                  {s.name}
                </TabsTrigger>
              ))}
            </TabsList>
          </Tabs>
        </div>
      )}

      <div className="flex-1 overflow-auto p-3">
        {sectorTables.length === 0 ? (
          <div className="flex h-full flex-col items-center justify-center gap-2 text-center text-muted-foreground">
            <Utensils className="size-8" aria-hidden />
            <p className="text-sm">Sin mesas configuradas para este sector.</p>
            <p className="text-xs">Configurá el salón desde Ajustes → Mesas.</p>
          </div>
        ) : hasCustomLayout ? (
          <ScaledCanvas>
            {sectorTables.map((table) => {
              const size = defaultSize(table.shape)
              return (
                <PosTableTile
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
          <div className="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6">
            {sectorTables.map((table) => (
              <div key={table.id} className="aspect-square">
                <PosTableTile table={table} onClick={() => handleTileClick(table)} />
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
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
