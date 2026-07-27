"use client"

import * as React from "react"
import { Check, Clock, Pin, PinOff } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { useElapsed } from "@/hooks/use-elapsed"
import type { Order, OrderItem } from "@/hooks/use-orders"
import { screenItems } from "@/lib/kds/board"
import type { KdsConfig } from "@/lib/kds/config"
import {
  KDS_ITEM_VISUALS,
  KDS_STATUS_VISUALS,
  KDS_TIER_ACCENT,
  kdsTextHex,
  kdsTint,
  type KdsMode,
  type KdsOrderStatus,
} from "@/lib/kds/kds-visuals"
import { orderOrigin } from "@/lib/orders/order-display"

/**
 * Tarjeta de comanda del KDS — flujo horizontal (rediseño 2026-07-27).
 *
 * Invariante central del rediseño: **la tarjeta NO se mueve nunca**. Ni al
 * cambiar de estado (antes saltaba de columna, con quien opera leyéndola) ni al
 * marcar un ítem. El estado es COLOR (franja + encabezado tintado + etiqueta),
 * la posición es TIEMPO. Los ítems se marcan en su misma línea: no se sacan de
 * la lista, no se reordenan, no se colapsan.
 *
 * Alto: la tarjeta ocupa TODO el alto disponible y el scroll vertical vive
 * DENTRO de la lista de ítems (`overflow-y-auto` + `min-h-0`). La pantalla
 * nunca scrollea — es una TV, nadie la va a tocar.
 *
 * Posiciones estables (14-ui-conventions.md Regla #10): el encabezado tiene
 * siempre las mismas dos filas. La nota de la comanda vive DENTRO del área
 * scrolleable, así una comanda con nota no empuja la lista de ítems hacia
 * abajo respecto de una sin nota.
 *
 * Dos canales de color, nunca mezclados (context/27 §A.4): el ESTADO pinta la
 * franja/encabezado, la DEMORA pinta solo el pill de tiempo. Mapping en
 * `lib/kds/kds-visuals.ts` — nunca inline acá. El ORIGEN es un tercer dato pero
 * NO un tercer color: va como badge neutro, para no meterse en esos dos
 * canales.
 *
 * GESTOS
 * ------
 * Tap en la tarjeta = bump de TODOS los ítems bumpeables; tap en una línea =
 * bump de ESE ítem; **long-press en una línea = retroceder un paso**. El
 * retroceso es un gesto DISTINTO y no un segundo tap que cicle: si el tap
 * ciclara, marcar dos ítems seguidos con el dedo apurado sería una ruleta.
 * `delivered` NO se toca acá — es de la pantalla de despacho, y el backend lo
 * bloquea para module=kds (`assertModuleCanSetStatus`).
 */

/** Un tap normal en una TV táctil dura ~100ms; 500ms no se dispara sin querer. */
const LONG_PRESS_MS = 500

interface OrderCardProps {
  order: Order
  config: KdsConfig
  /** Modo resuelto de la pantalla — decide el tono legible de los acentos. */
  mode: KdsMode
  busy: boolean
  pinned: boolean
  /** Selección por teclado: esta es la comanda activa. */
  selected: boolean
  /** Selección por teclado: ítem activo DENTRO de esta comanda. */
  selectedItemId: string | null
  onTogglePin: (orderId: string) => void
  onBumpOrder: (order: Order, items: OrderItem[]) => void
  onBumpItem: (item: OrderItem) => void
  onStepBackItem: (item: OrderItem) => void
}

/** ¿Se puede retroceder este ítem un paso? (ready → preparing → pending) */
export function canStepBack(item: OrderItem): boolean {
  return item.status === "preparing" || item.status === "ready"
}

export function OrderCard({
  order,
  config,
  mode,
  busy,
  pinned,
  selected,
  selectedItemId,
  onTogglePin,
  onBumpOrder,
  onBumpItem,
  onStepBackItem,
}: OrderCardProps) {
  const elapsed = useElapsed(order.sentAt ?? order.createdAt, {
    warnMin: config.warnMin,
    lateMin: config.lateMin,
  })

  // Misma función que usa la pantalla para decidir si la comanda ya salió del
  // board y para navegar por teclado — si divergieran, una comanda podría salir
  // con líneas sin marcar a la vista.
  const items = React.useMemo(
    () => screenItems(order, config.stationIds),
    [order, config.stationIds]
  )

  const bumpable = items.filter((i) => i.status === "pending" || i.status === "preparing")
  const status = KDS_STATUS_VISUALS[order.status as KdsOrderStatus] ?? KDS_STATUS_VISUALS.sent
  /**
   * El acento SÓLIDO (franja, borde, etiqueta) va en el tono del modo: los hex
   * de la paleta están pensados para brillar sobre fondo oscuro y sobre blanco
   * se lavan — y la franja de estado es el canal que se lee a varios metros, no
   * un adorno. En modo oscuro `kdsTextHex` devuelve el mismo hex, así que el
   * comportamiento anterior queda intacto. Los FONDOS tintados siguen saliendo
   * del acento crudo: son el mismo color a baja opacidad sobre el fondo del
   * modo, y ahí sí se resuelven solos.
   */
  const accent = status.accent ? kdsTextHex(status.accent, mode) : "transparent"
  const tierAccent = KDS_TIER_ACCENT[elapsed.tier]

  const longPressRef = React.useRef<ReturnType<typeof setTimeout> | null>(null)
  /** El long-press ya disparó: el `click` que viene atrás del dedo se descarta. */
  const firedRef = React.useRef(false)
  const selectedItemRef = React.useRef<HTMLButtonElement>(null)

  React.useEffect(() => () => { if (longPressRef.current) clearTimeout(longPressRef.current) }, [])

  // La lista de ítems scrollea dentro de la tarjeta: navegar con ↑/↓ hasta un
  // ítem que quedó fuera de vista tiene que traerlo, o la selección se pierde.
  React.useEffect(() => {
    if (selected && selectedItemId) {
      selectedItemRef.current?.scrollIntoView({ block: "nearest" })
    }
  }, [selected, selectedItemId])

  function cancelLongPress() {
    if (longPressRef.current) {
      clearTimeout(longPressRef.current)
      longPressRef.current = null
    }
  }

  function startLongPress(item: OrderItem) {
    cancelLongPress()
    firedRef.current = false
    if (busy || !canStepBack(item)) return
    longPressRef.current = setTimeout(() => {
      firedRef.current = true
      onStepBackItem(item)
    }, LONG_PRESS_MS)
  }

  function bumpAll() {
    if (busy || bumpable.length === 0) return
    onBumpOrder(order, bumpable)
  }

  return (
    <div
      role="button"
      tabIndex={0}
      aria-label={`Comanda ${order.orderNumber ?? ""} — ${status.label}`}
      aria-disabled={busy || bumpable.length === 0}
      aria-current={selected ? "true" : undefined}
      onClick={bumpAll}
      onKeyDown={(e) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault()
          bumpAll()
        }
      }}
      // La selección por teclado se ve con un anillo GRUESO: esto se mira a
      // varios metros, un outline de 1px no existe a esa distancia. `foreground`
      // y no un color de la paleta — así no compite con el canal de estado ni
      // con el de demora, y contrasta al máximo en los dos modos.
      className={`flex h-full min-h-0 select-none flex-col overflow-hidden rounded-lg border-2 bg-card text-left shadow-sm transition-opacity aria-disabled:opacity-60 ${
        selected ? "ring-4 ring-foreground" : ""
      }`}
      style={{
        borderColor: accent,
        // Escala tipográfica con DOS términos, nunca px fijos:
        //  - ancho real de la columna (`--kds-col`, que la grilla setea desde el
        //    ancho medido) → el texto entra en la tarjeta sea cual sea el nº de
        //    comandas por pantalla;
        //  - `vw` → una TV de 55" a 4 metros necesita letra más grande que un
        //    teléfono a 30 cm, aunque la columna mida parecido.
        // El `clamp()` pone piso (legible en teléfono) y techo (no grotesco).
        fontSize: "clamp(1rem, calc(var(--kds-col, 320px) * 0.05 + 0.35vw), 2rem)",
      }}
    >
      {/* Franja de estado — el canal que se lee a varios metros. */}
      <div className="h-2 shrink-0" style={{ backgroundColor: accent }} />

      <header
        className="shrink-0 border-b px-3 py-2"
        style={{ backgroundColor: status.accent ? kdsTint(status.accent, "strong") : undefined }}
      >
        <div className="flex items-center gap-2">
          <span
            className="min-w-0 flex-1 truncate font-bold leading-none tabular-nums"
            style={{ fontSize: "clamp(1.5rem, calc(var(--kds-col, 320px) * 0.12 + 0.6vw), 4rem)" }}
          >
            #{order.orderNumber ?? "—"}
          </span>
          <span
            className="flex shrink-0 items-center gap-1 rounded-md px-2 py-1 font-semibold tabular-nums"
            style={{
              backgroundColor: tierAccent ? kdsTint(tierAccent, "strong") : undefined,
              color: tierAccent ? kdsTextHex(tierAccent, mode) : undefined,
              fontSize: "clamp(1rem, calc(var(--kds-col, 320px) * 0.055 + 0.4vw), 2.25rem)",
            }}
          >
            <Clock className="size-[1em] shrink-0" />
            <span className="min-w-[3.5ch] text-right">{elapsed.label}</span>
          </span>
        </div>

        {/* Segunda fila de alto fijo (la del botón de pin, 44px). Los
            secundarios van en `em`, no en `text-sm`: escalan junto con la
            tarjeta — un `text-sm` fijo es ilegible en una TV a 4 metros.
            El pin vive acá y no arriba para no disputarle ancho al número de
            comanda, que es el identificador y nunca debe truncarse. */}
        <div className="mt-1 flex h-11 items-center gap-2">
          <div className="flex min-w-0 flex-1 items-center gap-2" style={{ fontSize: "0.72em" }}>
            {/* ORIGEN — "Espacio 4", no "Espacio". Es de los primeros datos que
                quien opera necesita (a dónde va el plato), así que va como
                badge y no como texto muted perdido al lado del estado. Sin
                color propio: los dos canales de color ya están tomados. */}
            <Badge
              variant="secondary"
              className="shrink-0 font-semibold"
              style={{ fontSize: "inherit" }}
            >
              {orderOrigin(order)}
            </Badge>
            <span className="shrink-0 font-semibold uppercase tracking-wide" style={{ color: accent }}>
              {status.label}
            </span>
            {order.customerName && (
              <span className="truncate text-muted-foreground">{order.customerName}</span>
            )}
          </div>
          <Button
            type="button"
            variant="ghost"
            size="icon"
            aria-label={pinned ? "Quitar de la izquierda" : "Fijar a la izquierda"}
            aria-pressed={pinned}
            onClick={(e) => {
              e.stopPropagation()
              onTogglePin(order.id)
            }}
            className="size-11 shrink-0"
          >
            {pinned ? <Pin className="size-5 fill-current" /> : <PinOff className="size-5 opacity-50" />}
          </Button>
        </div>
      </header>

      {/* Único contenedor scrolleable de la pantalla. */}
      <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain p-2">
        {order.note && (
          <p
            className="mb-2 rounded-md bg-muted px-2 py-1 font-medium uppercase"
            style={{ fontSize: "0.8em" }}
          >
            {order.note}
          </p>
        )}

        <ul className="flex flex-col gap-1">
          {items.map((item) => {
            const visual = KDS_ITEM_VISUALS[item.status]
            const done = item.status === "ready" || item.status === "delivered"
            const cancelled = item.status === "cancelled"
            const isSelected = selected && item.id === selectedItemId
            return (
              <li key={item.id}>
                <button
                  ref={isSelected ? selectedItemRef : undefined}
                  type="button"
                  // Un ítem `ready` NO está deshabilitado: el tap no hace nada
                  // (ya está hecho) pero el long-press tiene que poder
                  // retroceder el marcado equivocado, que es el error más común
                  // de la cocina. `delivered` sí: ya salió al cliente.
                  disabled={busy || cancelled || item.status === "delivered"}
                  aria-current={isSelected ? "true" : undefined}
                  onPointerDown={() => startLongPress(item)}
                  onPointerUp={cancelLongPress}
                  onPointerLeave={cancelLongPress}
                  onPointerCancel={cancelLongPress}
                  onClick={(e) => {
                    e.stopPropagation()
                    cancelLongPress()
                    // El long-press ya actuó: el click que el browser dispara
                    // al levantar el dedo NO puede volver a avanzar la línea.
                    if (firedRef.current) {
                      firedRef.current = false
                      return
                    }
                    if (item.status === "pending" || item.status === "preparing") onBumpItem(item)
                  }}
                  className={`flex min-h-11 w-full items-start gap-2 rounded-md px-2 py-1.5 text-left disabled:cursor-default ${
                    cancelled ? "line-through opacity-40" : ""
                  } ${isSelected ? "ring-[3px] ring-inset ring-foreground" : ""}`}
                  style={{
                    backgroundColor: visual.accent ? kdsTint(visual.accent, "soft") : undefined,
                  }}
                >
                  {/* Señal de estado de la línea: una barrita de 4px. Antes acá
                      había un badge "Preparando" que le robaba ~70px al nombre
                      del ítem y lo partía en dos renglones — y decía lo obvio,
                      porque preparando es el estado por defecto de todo lo que
                      está en pantalla. Lo que hay que distinguir es hecho / no
                      hecho, y eso lo dice el check. La barrita conserva la
                      distinción empezado / no empezado por 4px. */}
                  <span
                    aria-hidden
                    className="mt-0.5 w-1 shrink-0 self-stretch rounded-full"
                    style={{
                      backgroundColor: visual.accent ? kdsTextHex(visual.accent, mode) : "transparent",
                    }}
                  />
                  <span className="shrink-0 font-bold tabular-nums">{item.qty}×</span>
                  {/* El nombre se queda con TODO el ancho disponible: es lo que
                      quien opera tiene que cocinar. */}
                  <span className="min-w-0 flex-1">
                    <span className={done ? "opacity-70" : "font-semibold"}>{item.name}</span>
                    {item.note && (
                      <span className="block text-muted-foreground" style={{ fontSize: "0.8em" }}>
                        {item.note}
                      </span>
                    )}
                  </span>
                  {/* El label del estado sigue existiendo para lectores de
                      pantalla — deja de PINTARSE, no de estar. */}
                  <span className="sr-only">{visual.label}</span>
                  {done && (
                    <Check
                      className="mt-0.5 size-5 shrink-0"
                      style={{ color: visual.accent ? kdsTextHex(visual.accent, mode) : undefined }}
                    />
                  )}
                </button>
              </li>
            )
          })}
          {items.length === 0 && (
            <li className="px-2 py-1 text-muted-foreground" style={{ fontSize: "0.8em" }}>
              Sin ítems para esta estación.
            </li>
          )}
        </ul>
      </div>
    </div>
  )
}
