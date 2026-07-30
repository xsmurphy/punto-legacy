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
import { SplitBillDialog, type SplitSelection } from "@/components/spaces/split-bill-dialog"
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
import {
  fetchSessionBalance,
  useSessionBalance,
  type SessionBalance,
} from "@/hooks/use-space-settlement"
import { useCartStore, type SettlementIntent } from "@/lib/cart/store"
import {
  buildItemsLines,
  buildProportionalLines,
  currencyDecimals,
  sourcesFromOrders,
  splitShares,
  MONEY_EPSILON,
  type SettlementSource,
} from "@/lib/spaces/settlement-lines"
import { useCatalogStore } from "@/lib/catalog/store"
import { formatMoney } from "@/lib/format-money"
import { usePosUIStore } from "@/lib/ui/store"
import { usePersistedView } from "@/lib/ui/use-persisted-view"

/**
 * El monto a cobrar no puede exceder el saldo RECIÉN LEÍDO. El backend
 * también lo valida, pero recién cuando se registra el pago — es decir,
 * después de haber creado la venta. Un rechazo ahí deja plata cobrada sin
 * renglón en el ledger.
 */
function assertFitsBalance(target: number, balance: SessionBalance): void {
  if (target - balance.balance > MONEY_EPSILON) {
    throw new Error(
      "El saldo de la mesa cambió (otro cobro entró recién). Revisá el monto e intentá de nuevo.",
    )
  }
}

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
  /** Mesa con el diálogo de split abierto (elección de modo de cobro). */
  const [splitTable, setSplitTable] = React.useState<SpaceWithState | null>(null)
  const [preparingCharge, setPreparingCharge] = React.useState(false)
  /**
   * Mesa con un cobro PARCIAL en curso en el PayDialog. Al cerrarse el
   * PayDialog se relee el saldo: si quedó en 0 el backend ya liberó el
   * espacio y se vuelve al mapa; si queda saldo, se reabre el split para
   * cobrar la parte siguiente.
   */
  const [settlingTable, setSettlingTable] = React.useState<SpaceWithState | null>(null)
  const chargeInFlight = React.useRef(false)

  const openSession = useOpenSpaceSession()
  const requestBill = useRequestBill()
  const cancelSession = useCancelSpaceSession()

  const config = useCatalogStore((s) => s.config)
  const setSelectedSpace = useCartStore((s) => s.setSelectedSpace)
  const loadFromSession = useCartStore((s) => s.loadFromSession)
  const loadForSettlement = useCartStore((s) => s.loadForSettlement)
  const payOpen = usePosUIStore((s) => s.payOpen)
  const setPayOpen = usePosUIStore((s) => s.setPayOpen)
  const { refetch: refetchSettlingBalance } = useSessionBalance(
    settlingTable?.session?.id ?? null,
  )

  // Mesa del split siempre "fresca": el estado del espacio puede haber
  // cambiado (otra caja cobró una parte) mientras el diálogo estaba abierto.
  // Sin useMemo a propósito: `tables` se recrea en cada render y memoizar acá
  // no ahorraría nada (un find sobre la lista de mesas de un sector).
  const liveSplitTable = splitTable
    ? (tables.find((t) => t.id === splitTable.id) ?? splitTable)
    : null

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

  /** "Cobrar" en el diálogo de sesión → elegir modo de cobro (total o split). */
  function handleCharge() {
    if (!sessionTable?.session) return
    setSplitTable(sessionTable)
    setSessionTable(null)
  }

  /**
   * Arma el carrito para el modo elegido y abre el PayDialog.
   *
   * - `total` sin pagos previos → camino de SIEMPRE (`loadFromSession`):
   *   markPaid de cada orden + close de la sesión los sigue haciendo
   *   `pay-dialog.tsx`. Intacto.
   * - Cualquier otro caso → cobro parcial (`loadForSettlement`): la venta se
   *   registra en el ledger y el cierre lo decide el backend.
   *
   * Todo lo que puede fallar (ítem sin artículo de catálogo, monto que no
   * entra) falla ACÁ, antes de crear la venta — después de cobrar ya no hay
   * vuelta atrás.
   */
  async function handleSplitCharge(selection: SplitSelection) {
    const table = liveSplitTable
    if (!table?.session) return
    // Guarda de doble tap: `preparingCharge` deshabilita el botón, pero entre
    // dos taps consecutivos puede no haber re-render. Dos cobros en vuelo
    // serían dos ventas (el backend deduplica el LEDGER por transactionId,
    // no las transacciones — serían dos comprobantes por la misma parte).
    if (chargeInFlight.current) return
    chargeInFlight.current = true
    const sessionId = table.session.id
    const spaceName = table.name
    setPreparingCharge(true)
    try {
      // El saldo se RELEE acá, no se usa el que mostró el diálogo: entre que
      // se abrió y el cajero tocó "Cobrar" pudo entrar un parcial de otra
      // caja. Con un `paid` viejo se tomaría el camino de mesa completa
      // (markPaid + close, sin pasar por el ledger) sobre una mesa que ya
      // tenía plata cobrada, y `SpaceSessionService::close()` no valida
      // saldo: nadie lo atraparía. El saldo cacheado es para mirar; para
      // cobrar, este.
      const [balance, { orders: summaries }] = await Promise.all([
        fetchSessionBalance(sessionId),
        fetchOrdersBySession(sessionId),
      ])
      const billable = summaries.filter((o) => o.status !== "closed" && o.status !== "cancelled")
      if (billable.length === 0) {
        toast.error("El espacio no tiene órdenes por cobrar")
        return
      }
      const orders = await Promise.all(billable.map((o) => fetchOrderDetail(o.id)))

      if (selection.mode === "total" && balance.paid <= 0) {
        loadFromSession(sessionId, spaceName, orders)
        setSplitTable(null)
        setPayOpen(true)
        return
      }

      const sources = sourcesFromOrders(orders)
      const decimals = currencyDecimals(config)

      let lines
      let intent: SettlementIntent

      if (selection.mode === "items") {
        // Contra el saldo recién leído: si otra caja cobró alguno de estos
        // ítems mientras el diálogo estaba abierto, el CAS del backend
        // abortaría — pero recién DESPUÉS de crear la venta, con la plata ya
        // cobrada. Se corta acá.
        const alreadySettled = balance.items.filter(
          (i) => i.settled && selection.orderItemIds.includes(i.id),
        )
        if (alreadySettled.length > 0) {
          toast.error("Otro cobro ya se llevó alguno de esos ítems", {
            description: "El saldo de la mesa cambió. Revisá la selección.",
          })
          setSplitTable(table)
          return
        }
        lines = buildItemsLines(sources, selection.orderItemIds)
        intent = { sessionId, kind: "items", orderItemIds: selection.orderItemIds }
      } else {
        // Base del prorrateo: SOLO los ítems todavía no saldados — los ya
        // cobrados por `kind='items'` no se vuelven a facturar ni a
        // descontar de stock.
        const unsettled = balance.items
          .filter((i) => !i.settled)
          .map((i) => sources.get(i.id))
          .filter((s): s is SettlementSource => s !== undefined)

        if (selection.mode === "share") {
          const target = splitShares(balance.total, selection.shareCount, decimals)[
            selection.shareIndex - 1
          ]
          assertFitsBalance(target, balance)
          lines = buildProportionalLines(
            unsettled,
            target,
            decimals,
            `Parte ${selection.shareIndex} de ${selection.shareCount}`,
          )
          intent = {
            sessionId,
            kind: "share",
            shareCount: selection.shareCount,
            shareIndex: selection.shareIndex,
          }
        } else {
          // `amount` explícito, o `total` con pagos previos (se cobra el saldo).
          const target = selection.mode === "amount" ? selection.amount : balance.balance
          assertFitsBalance(target, balance)
          lines = buildProportionalLines(unsettled, target, decimals)
          intent = { sessionId, kind: "amount", amount: target }
        }
      }

      loadForSettlement(spaceName, lines, intent)
      setSettlingTable(table)
      setSplitTable(null)
      setPayOpen(true)
    } catch (err) {
      toast.error("No se pudo preparar el cobro del espacio", {
        description: err instanceof Error ? err.message : String(err),
      })
    } finally {
      chargeInFlight.current = false
      setPreparingCharge(false)
    }
  }

  // ── Post-cobro parcial ────────────────────────────────────────────────────
  //
  // El PayDialog se cerró y había un cobro parcial en curso: se relee el
  // saldo (el registro en el ledger lo invalida, esto además cubre el caso de
  // que todavía estuviera en vuelo). Saldo 0 → el backend ya cerró órdenes y
  // sesión, el espacio quedó libre y se ve el mapa. Saldo > 0 → se reabre el
  // split para cobrar la parte siguiente, ya con el saldo nuevo.
  const prevPayOpen = React.useRef(payOpen)
  React.useEffect(() => {
    const wasOpen = prevPayOpen.current
    prevPayOpen.current = payOpen
    if (!wasOpen || payOpen || !settlingTable) return

    const table = settlingTable
    void (async () => {
      try {
        // El refetch va ANTES de limpiar `settlingTable`: al limpiarlo, el
        // sessionId del hook pasa a null y la query queda deshabilitada.
        const { data } = await refetchSettlingBalance()
        const remaining = data?.balance ?? 0
        if (remaining > MONEY_EPSILON) {
          toast.info(`${table.name} — saldo pendiente ${formatMoney(remaining, config)}`)
          setSplitTable(table)
        } else {
          toast.success(`${table.name} — cuenta saldada`)
        }
      } catch {
        // Sin saldo confiable no se decide nada: el mapa se refresca solo por
        // la invalidación de ["spaces"] y el cajero reabre la mesa si hace falta.
      } finally {
        setSettlingTable(null)
      }
    })()
  }, [payOpen, settlingTable, refetchSettlingBalance, config])

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
      />
      <SplitBillDialog
        table={liveSplitTable}
        onOpenChange={(v) => !v && setSplitTable(null)}
        onCharge={handleSplitCharge}
        preparing={preparingCharge}
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
