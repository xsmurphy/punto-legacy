"use client"

/**
 * Menú de acciones de un espacio — los tres puntos de la esquina superior
 * derecha del tile (pedido del owner con mockup, 2026-08-25).
 *
 * Hasta ahora TODAS estas acciones vivían un tap más adentro: había que abrir
 * el `SpaceSessionDialog` y recién ahí aparecían Editar / Mover / Unir /
 * Cancelar. Este menú las sube al tile; no reimplementa ninguna — cada ítem
 * dispara exactamente el mismo diálogo que ya estaba montado en la página
 * (`app/(pos)/pos/espacios/page.tsx`), que sigue siendo el único dueño de esos
 * flujos.
 *
 * "Renombrar" del mockup NO está: renombrar el ESPACIO (no la ocupación) es una
 * mutación de catálogo (`PUT /v1/spaces`, realm panel con `_jwt_panel`) y el BFF
 * del POS solo expone GET y POST(bulk|layout). Habilitarlo desde la caja es
 * ampliar la superficie de auth del token de device, decisión que el owner toma
 * aparte. Se omite antes que dejarlo muerto o apagado para siempre.
 *
 * ── Iconos: excepción EXPLÍCITA, no un descuido ─────────────────────────────
 *
 * `context/20-design-system.md` (decisión 2026-08-08) dice que los ítems de un
 * menú de acciones van TEXTO SOLO. Esa decisión se tomó sobre el `RowActions`
 * del `<DataTable>` del panel y alcanza a sus consumidores. Este menú lleva
 * icono en cada ítem porque el owner lo pidió así en el mockup, y el contexto
 * es otro: es el POS, se opera con el dedo y de reojo, y el icono es el canal
 * que hace escaneable la lista sin leerla (mismo criterio que ya siguen los
 * botones del `SpaceSessionDialog`, que también los tienen). No tomar este
 * archivo como precedente para menús del panel.
 *
 * ── Ítems bloqueados: apagados, nunca ocultos ───────────────────────────────
 *
 * Regla del owner: el impedimento vive en el CONTROL de la acción. Un menú que
 * cambia de largo según el estado del espacio rompe la memoria muscular
 * (Regla #10) y no explica nada. Acá el ítem sigue en su lugar, apagado, y dice
 * por qué — tooltip para mouse, toast para el dedo.
 */

import * as React from "react"
import { toast } from "sonner"
import {
  MoreVertical,
  Eye,
  Tag,
  Merge,
  ArrowRightLeft,
  UserPlus,
  Ban,
  type LucideIcon,
} from "lucide-react"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@/components/ui/tooltip"
import { cn } from "@/lib/utils"
import { useOnlineStatus } from "@/hooks/use-online-status"
import { useCatalogStore } from "@/lib/catalog/store"
import { useLockStore } from "@/lib/pos/lock-store"
import { evaluateSpaceAccess } from "@/lib/pos/space-access"
import { cancelSessionDescription, countActiveOrders } from "@/lib/spaces/cancel-session-copy"
import { useOrdersBySession, ACTIVE_ORDER_STATUSES } from "@/hooks/use-orders"
import type { SpaceWithState } from "@/hooks/use-pos-spaces"

/**
 * Los seis handlers del menú, en un solo objeto.
 *
 * Un objeto y no seis props sueltas porque la página los arma UNA vez con
 * `useMemo` y el mismo objeto viaja a los dos call-sites del tile (mapa y
 * grilla). Cada handler recibe el espacio: el menú no sabe —ni tiene por qué—
 * en qué estado tiene la página al espacio "seleccionado".
 */
export interface SpaceTileActions {
  onViewDetail: (table: SpaceWithState) => void
  onLabel: (table: SpaceWithState) => void
  onMerge: (table: SpaceWithState) => void
  onMove: (table: SpaceWithState) => void
  onAssignWaiter: (table: SpaceWithState) => void
  onCloseSpace: (table: SpaceWithState) => void
}

interface Props {
  table: SpaceWithState
  actions: SpaceTileActions
  /**
   * Área táctil reducida. En el mapa los tiles bajan a ~70px y el target de
   * 44px se come el tile entero — ver el comentario del tamaño abajo.
   */
  compact?: boolean
}

export function SpaceActionsMenu({ table, actions, compact = false }: Props) {
  const [confirmClose, setConfirmClose] = React.useState(false)

  const isOnline = useOnlineStatus()
  const activeUser = useLockStore((s) => s.activeUser)
  const operatorToken = useLockStore((s) => s.operatorToken)
  const operatorPermissions = useLockStore((s) => s.operatorPermissions)

  // Mismo criterio que el tile (`pos-space-tile.tsx`): el mozo se resuelve
  // contra los usuarios ya precacheados en el bootstrap, nunca con una request
  // por espacio.
  const users = useCatalogStore((s) => s.users)
  const session = table.session
  const waiterName = React.useMemo(() => {
    if (!session?.waiterId) return null
    return users.find((u) => u.id === session.waiterId)?.name ?? null
  }, [session, users])

  const access = React.useMemo(
    () =>
      evaluateSpaceAccess({
        session,
        activeUser,
        operatorToken,
        permissions: operatorPermissions,
        waiterName,
      }),
    [session, activeUser, operatorToken, operatorPermissions, waiterName],
  )

  /**
   * "No hay nada sobre lo que operar", ya redactado.
   *
   * Un espacio sin sesión puede estarlo por dos motivos distintos y el cajero
   * necesita saber cuál: `free` es "andá y abrila", `disabled` es "está fuera
   * de servicio, no la vas a poder abrir". Decir "libre" sobre una mesa
   * deshabilitada manda al cajero a intentar algo que no existe. (`reserved`
   * todavía no lo emite el backend —F4 de `SpaceService`— así que cae en el
   * caso general.)
   */
  const noSessionReason: string | null = session
    ? null
    : table.state === "disabled"
      ? "El espacio está fuera de servicio."
      : "El espacio está libre."

  /**
   * Motivo por el que una acción de GESTIÓN no se puede ejecutar, en orden de
   * precedencia. El orden importa: sin sesión no hay nada que gestionar, y ese
   * hecho manda sobre la red y sobre de quién es la mesa.
   *
   * Las cinco acciones de gestión pasan por el mismo guard de ownership en el
   * backend (editar, mover, unir, cancelar) y todas exigen red — el módulo
   * tiene un gate offline duro, pero SOLO cuando no hay datos cacheados: si la
   * red se cae con la grilla ya pintada, los tiles siguen ahí y este es el
   * único aviso que le queda al cajero.
   */
  const manageBlocked: string | null =
    noSessionReason ?? (!isOnline ? "Necesita conexión." : access.reason)

  /**
   * "Ver detalle" solo pide que haya sesión. NO pide red ni exclusividad: es
   * read-only sobre datos que el dispositivo ya tiene, y mirar la mesa de otro
   * mozo nunca estuvo prohibido — lo que el guard protege es mutarla.
   */
  const detailBlocked: string | null = noSessionReason

  return (
    <>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <button
            type="button"
            aria-label={`Acciones de ${table.name}`}
            // El tile entero es un <button> hermano de este: sin frenar la
            // propagación, tocar los tres puntos abriría además el diálogo del
            // tile. `onPointerDown` además de `onClick` porque en touch el tap
            // del tile puede resolverse antes del click sintético.
            onClick={(e) => e.stopPropagation()}
            onPointerDown={(e) => e.stopPropagation()}
            className={cn(
              "absolute right-0 top-0 z-10 flex items-center justify-center rounded-full",
              "text-foreground/50 transition-colors hover:text-foreground",
              // Target táctil de 44px en la grilla (mínimo de dedo). En el mapa
              // los tiles miden ~70px y 44 se comerían más de la mitad, así que
              // ahí baja a 36 — override de tamaño permitido y documentado para
              // POS touch-first (context/14 Regla #2). Lo que cambia es el
              // ÁREA: el glifo mide size-4 en los dos casos.
              compact ? "size-9" : "size-11",
            )}
          >
            <MoreVertical className="size-4" />
          </button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" className="w-56">
          {/* Header con el nombre del espacio (mockup del owner): el menú se
              abre desde un tile chico y, ya desplegado, tapa a sus vecinos —
              sin título no queda claro sobre cuál se está operando. */}
          <DropdownMenuLabel className="truncate">
            {/* El alias de la ocupación, cuando existe, ES cómo el mozo llama a
                esta mesa — mismo criterio que el título del SpaceSessionDialog. */}
            {session?.alias ? `${session.alias} · ${table.name}` : table.name}
          </DropdownMenuLabel>
          <DropdownMenuSeparator />

          <MenuAction
            icon={Eye}
            label="Ver detalle"
            blockedReason={detailBlocked}
            onSelect={() => actions.onViewDetail(table)}
          />
          <MenuAction
            icon={Tag}
            label="Etiquetar"
            blockedReason={manageBlocked}
            onSelect={() => actions.onLabel(table)}
          />
          <MenuAction
            icon={Merge}
            label="Unir espacios"
            blockedReason={manageBlocked}
            onSelect={() => actions.onMerge(table)}
          />
          <MenuAction
            icon={ArrowRightLeft}
            label="Cambiar de espacio"
            blockedReason={manageBlocked}
            onSelect={() => actions.onMove(table)}
          />
          <MenuAction
            icon={UserPlus}
            label="Asignar Usuario"
            blockedReason={manageBlocked}
            onSelect={() => actions.onAssignWaiter(table)}
          />

          <DropdownMenuSeparator />
          <MenuAction
            icon={Ban}
            label="Cerrar espacio"
            destructive
            blockedReason={manageBlocked}
            onSelect={() => setConfirmClose(true)}
          />
        </DropdownMenuContent>
      </DropdownMenu>

      {/* Cerrar el espacio libera la mesa SIN cobro y cancela en cascada las
          órdenes activas — destructivo e irreversible, va con confirmación. El
          texto sale del helper compartido con el `SpaceSessionDialog`: es el
          mismo `action=cancel` y el cajero tiene que leer la misma advertencia
          desde donde sea que lo dispare. */}
      <AlertDialog open={confirmClose} onOpenChange={setConfirmClose}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>¿Cerrar {table.name}?</AlertDialogTitle>
            <AlertDialogDescription>
              {session ? <CancelWarning sessionId={session.id} /> : cancelSessionDescription(0)}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Volver</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              onClick={() => {
                setConfirmClose(false)
                actions.onCloseSpace(table)
              }}
            >
              Cerrar espacio
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}

/**
 * Cuántas órdenes activas se van a cancelar.
 *
 * Componente aparte —y no un hook en `SpaceActionsMenu`— porque solo se monta
 * cuando el `AlertDialogContent` está abierto: el menú existe en CADA tile de
 * la grilla, y consultar las órdenes de todos al pintar la pantalla sería un
 * N+1 sobre una vista que se repinta con cada evento de realtime. Así la
 * request sale una sola vez, para el espacio que se está por cerrar.
 *
 * Mientras carga muestra la advertencia mínima (sin número) en vez de un
 * spinner: el mensaje corto ya es verdadero y el largo solo agrega precisión.
 */
function CancelWarning({ sessionId }: { sessionId: string }) {
  const { data } = useOrdersBySession(sessionId)
  const orders = data?.orders ?? []
  return <>{cancelSessionDescription(countActiveOrders(orders, ACTIVE_ORDER_STATUSES))}</>
}

/**
 * Ítem del menú, con o sin motivo de bloqueo.
 *
 * `aria-disabled` y NO `disabled`: un ítem realmente deshabilitado no recibe
 * eventos de puntero, así que el tooltip nunca dispararía — y en tablet no hay
 * hover, así que el toque tiene que decir lo mismo que el tooltip o el ítem
 * queda mudo. Queda inerte para lo que importa (no ejecuta la acción) porque el
 * `onSelect` corta antes con el motivo.
 *
 * El ítem bloqueado NO cierra el menú: el cajero acaba de leer por qué no puede
 * y lo más probable es que quiera otra de las acciones de la lista.
 */
function MenuAction({
  icon: Icon,
  label,
  blockedReason,
  destructive = false,
  onSelect,
}: {
  icon: LucideIcon
  label: string
  blockedReason: string | null
  destructive?: boolean
  onSelect: () => void
}) {
  const item = (
    <DropdownMenuItem
      aria-disabled={blockedReason ? true : undefined}
      aria-label={blockedReason ? `${label} — ${blockedReason}` : undefined}
      onSelect={(e) => {
        if (blockedReason) {
          e.preventDefault()
          toast.info(blockedReason)
          return
        }
        onSelect()
      }}
      className={cn(
        destructive && "text-destructive focus:text-destructive",
        blockedReason && "text-muted-foreground opacity-60 focus:text-muted-foreground",
      )}
    >
      <Icon className="size-4" />
      {label}
    </DropdownMenuItem>
  )

  if (!blockedReason) return item

  return (
    <TooltipProvider>
      <Tooltip>
        <TooltipTrigger asChild>{item}</TooltipTrigger>
        <TooltipContent side="left">{blockedReason}</TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}
