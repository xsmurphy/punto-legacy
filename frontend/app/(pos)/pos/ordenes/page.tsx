"use client"

/**
 * Módulo Órdenes del POS (O1, context/24-orders-module-plan.md).
 *
 * Listado táctil de órdenes ACTIVAS del outlet del device (excluye
 * closed/cancelled — ver ACTIVE_ORDER_STATUSES en hooks/use-orders.ts) con
 * tres vistas intercambiables desde la barra flotante inferior:
 *
 *   - Cuadros (default): grilla de cards.
 *   - Lista: tabla compacta con buscador en tiempo real.
 *   - Mapa: MapLibre con el local y las órdenes geolocalizadas.
 *
 * "Cobrar" copia el contenido de la orden al carrito en modo venta
 * (loadFromOrder) y navega a /pos — el cobro en sí usa el flujo normal de
 * pay-dialog, que cierra el rastro con markPaid al confirmar (ver pay-dialog.tsx).
 *
 * Regla #10 (context/14-ui-conventions.md): la barra flotante existe SIEMPRE,
 * incluso mientras carga o cuando no hay órdenes — nunca se mueve ni
 * desaparece según el estado.
 */

import * as React from "react"
import { ClipboardList, LayoutGrid, List, Map } from "lucide-react"

import { EmptyState } from "@/components/empty-state"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { cn } from "@/lib/utils"
import { OrderCard } from "@/components/orders/order-card"
import { OrdersListView } from "@/components/orders/orders-list-view"
import { OrdersMapView } from "@/components/orders/orders-map-view"
import { FILTERABLE_STATUSES, STATUS_LABEL } from "@/lib/orders/order-display"
import { usePersistedView } from "@/lib/ui/use-persisted-view"
import { useActiveOrders, type Order, type OrderStatus } from "@/hooks/use-orders"

type OrdersView = "cards" | "list" | "map"
const VIEW_STORAGE_KEY = "punto.pos.ordenes.view"
const ORDERS_VIEWS = ["cards", "list", "map"] as const
const ALL_STATUSES = "__all__"

const VIEWS: Array<{ id: OrdersView; icon: typeof LayoutGrid; label: string }> = [
  { id: "cards", icon: LayoutGrid, label: "Cuadros" },
  { id: "list", icon: List, label: "Lista" },
  { id: "map", icon: Map, label: "Mapa" },
]

export default function PosOrdenesPage() {
  const { data, isLoading } = useActiveOrders()
  const orders = React.useMemo(() => data?.orders ?? [], [data])

  // Vista persistida por dispositivo (localStorage). Default: cuadros.
  const [view, setStoredView] = usePersistedView<OrdersView>(
    VIEW_STORAGE_KEY,
    ORDERS_VIEWS,
    "cards",
  )

  const [statusFilter, setStatusFilter] = React.useState<OrderStatus | typeof ALL_STATUSES>(
    ALL_STATUSES,
  )
  const [detailOrderId, setDetailOrderId] = React.useState<string | null>(null)

  // El mapa se monta la primera vez que se lo elige y desde ahí queda montado
  // (oculto en las otras vistas): recrear MapLibre en cada cambio de vista
  // dispara una descarga de tiles innecesaria en cada toque. `mapWasShown` lo
  // prende el handler; el `view === "map"` cubre entrar con la vista mapa ya
  // persistida en localStorage.
  const [mapWasShown, setMapWasShown] = React.useState(false)
  const mapMounted = view === "map" || mapWasShown

  const selectView = React.useCallback(
    (v: OrdersView) => {
      if (v === "map") setMapWasShown(true)
      setStoredView(v)
    },
    [setStoredView],
  )

  // El filtro de estado aplica a las TRES vistas.
  const filtered = React.useMemo(
    () =>
      statusFilter === ALL_STATUSES
        ? orders
        : orders.filter((o) => o.status === statusFilter),
    [orders, statusFilter],
  )

  const handleOpenOrder = React.useCallback((order: Order) => {
    setDetailOrderId(order.id)
  }, [])

  // Se resuelve contra la lista viva (no contra `filtered`): si la orden se
  // cobra o cancela desde otro lado el diálogo se cierra solo, pero cambiar el
  // filtro de estado mientras está abierto no debe cerrarlo.
  const detailOrder = orders.find((o) => o.id === detailOrderId) ?? null

  return (
    <div className="relative flex h-full w-full flex-col">
      <div className="relative flex-1 overflow-hidden">
        {/* Cuadros / Lista — contenedor scrolleable. pb-16 deja aire para que
            la barra flotante no tape la última fila. */}
        <div className={cn("h-full overflow-y-auto p-4 pb-16", view === "map" && "hidden")}>
          {isLoading ? (
            <p className="pt-6 text-center text-sm text-muted-foreground">Cargando órdenes...</p>
          ) : orders.length === 0 ? (
            <EmptyState
              ghost
              icon={ClipboardList}
              title="Sin órdenes activas"
              description="Las órdenes enviadas a cocina desde el carrito (modo Orden) van a aparecer acá."
              className="h-full"
            />
          ) : filtered.length === 0 ? (
            <EmptyState
              ghost
              icon={ClipboardList}
              title="Sin órdenes en este estado"
              description="Cambiá el filtro de la barra inferior para ver el resto."
              className="h-full"
            />
          ) : view === "list" ? (
            <OrdersListView orders={filtered} onOpenOrder={handleOpenOrder} />
          ) : (
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
              {filtered.map((order) => (
                <OrderCard key={order.id} order={order} />
              ))}
            </div>
          )}
        </div>

        {/* Mapa — siempre en la misma caja (absolute inset-0), así conserva sus
            dimensiones cuando está oculto y no hace falta un resize al volver. */}
        {mapMounted ? (
          <div
            className={cn(
              "absolute inset-0 p-4 pb-16",
              view !== "map" && "pointer-events-none opacity-0",
            )}
            aria-hidden={view !== "map"}
          >
            <OrdersMapView orders={filtered} onOpenOrder={handleOpenOrder} />
          </div>
        ) : null}
      </div>

      {/* Barra flotante — misma visual/posición que la de /pos/espacios y la de
          categorías del modo venta (product-area.tsx): pill oscura #22252A
          (excepción documentada del design system). Existe SIEMPRE (Regla #10):
          switch de vista + filtros de estado. */}
      <div className="pointer-events-none absolute inset-x-0 bottom-3 z-10 flex px-3">
        <div className="pointer-events-auto flex w-full items-center gap-2 rounded-full bg-[#22252A] py-1.5 pl-1.5 pr-3 shadow-lg">
          {/* Switch de vista (segmented, solo íconos para ahorrar ancho). */}
          <div className="flex shrink-0 items-center gap-1">
            {VIEWS.map((v) => (
              <ViewButton
                key={v.id}
                icon={v.icon}
                label={v.label}
                active={view === v.id}
                onClick={() => selectView(v.id)}
              />
            ))}
          </div>
          <div className="h-6 w-px shrink-0 bg-white/15" />
          {/* Pills de estado — single-select con "Todas", scroll horizontal. */}
          <div
            className="flex flex-1 items-center gap-1 overflow-x-auto whitespace-nowrap"
            style={{ scrollbarWidth: "none" }}
          >
            <StatusPill
              label="Todas"
              active={statusFilter === ALL_STATUSES}
              onClick={() => setStatusFilter(ALL_STATUSES)}
            />
            {FILTERABLE_STATUSES.map((s) => (
              <StatusPill
                key={s}
                label={STATUS_LABEL[s]}
                active={statusFilter === s}
                onClick={() => setStatusFilter(s)}
              />
            ))}
          </div>
        </div>
      </div>

      {/* Detalle desde Lista/Mapa — mismas acciones que la vista Cuadros. */}
      <Dialog open={detailOrder !== null} onOpenChange={(open) => !open && setDetailOrderId(null)}>
        <DialogContent className="sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>Orden #{detailOrder?.orderNumber ?? "—"}</DialogTitle>
            <DialogDescription>
              Cobrá, reimprimí la comanda o cancelá la orden.
            </DialogDescription>
          </DialogHeader>
          {detailOrder ? (
            <OrderCard
              order={detailOrder}
              className="border-0 bg-transparent p-0"
              onAfterAction={() => setDetailOrderId(null)}
            />
          ) : null}
        </DialogContent>
      </Dialog>
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
      title={`Vista ${label.toLowerCase()}`}
      aria-pressed={active}
      className={cn(
        "flex size-9 shrink-0 items-center justify-center rounded-full transition-colors",
        active ? "bg-white text-neutral-900" : "text-white/80 hover:text-white",
      )}
    >
      <Icon className="size-4" />
    </button>
  )
}

function StatusPill({
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
      aria-pressed={active}
      className={cn(
        "shrink-0 rounded-full px-3 py-1.5 text-sm font-bold transition-colors",
        active ? "bg-white text-neutral-900" : "text-white/80 hover:text-white",
      )}
    >
      {label}
    </button>
  )
}
