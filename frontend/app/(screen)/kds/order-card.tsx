"use client"

import * as React from "react"
import { Check, Clock, Pin, PinOff } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { useElapsed } from "@/hooks/use-elapsed"
import type { Order, OrderItem } from "@/hooks/use-orders"
import type { KdsConfig } from "@/lib/kds/config"
import {
  KDS_ITEM_VISUALS,
  KDS_STATUS_VISUALS,
  KDS_TIER_ACCENT,
  kdsTint,
  type KdsOrderStatus,
} from "@/lib/kds/kds-visuals"
import { SOURCE_LABEL } from "@/lib/orders/order-display"

/**
 * Tarjeta de comanda del KDS — flujo horizontal (rediseño 2026-07-27).
 *
 * Invariante central del rediseño: **la tarjeta NO se mueve nunca**. Ni al
 * cambiar de estado (antes saltaba de columna, con el cocinero leyéndola) ni al
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
 * `lib/kds/kds-visuals.ts` — nunca inline acá.
 *
 * Tap en la tarjeta = bump de TODOS los ítems bumpeables; tap en una línea =
 * bump de ESE ítem. `delivered` NO se toca acá — es de la pantalla de mozos, y
 * el backend lo bloquea para module=kds (`assertModuleCanSetStatus`).
 */

interface OrderCardProps {
  order: Order
  config: KdsConfig
  busy: boolean
  pinned: boolean
  onTogglePin: (orderId: string) => void
  onBumpOrder: (order: Order, items: OrderItem[]) => void
  onBumpItem: (item: OrderItem) => void
}

export function OrderCard({
  order,
  config,
  busy,
  pinned,
  onTogglePin,
  onBumpOrder,
  onBumpItem,
}: OrderCardProps) {
  const elapsed = useElapsed(order.sentAt ?? order.createdAt, {
    warnMin: config.warnMin,
    lateMin: config.lateMin,
  })

  const items = React.useMemo(() => {
    const all = order.items ?? []
    if (config.stationIds.length === 0) return all
    return all.filter((i) => i.stationId && config.stationIds.includes(i.stationId))
  }, [order.items, config.stationIds])

  const bumpable = items.filter((i) => i.status === "pending" || i.status === "preparing")
  const status = KDS_STATUS_VISUALS[order.status as KdsOrderStatus] ?? KDS_STATUS_VISUALS.sent
  const accent = status.accent ?? "transparent"
  const tierAccent = KDS_TIER_ACCENT[elapsed.tier]

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
      onClick={bumpAll}
      onKeyDown={(e) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault()
          bumpAll()
        }
      }}
      className="flex h-full min-h-0 select-none flex-col overflow-hidden rounded-lg border-2 bg-card text-left shadow-sm transition-opacity aria-disabled:opacity-60"
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
              color: tierAccent ?? undefined,
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
            <span
              className="shrink-0 font-semibold uppercase tracking-wide"
              style={{ color: accent }}
            >
              {status.label}
            </span>
            <span className="truncate text-muted-foreground">
              {SOURCE_LABEL[order.source] ?? order.source}
              {order.customerName ? ` · ${order.customerName}` : ""}
            </span>
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
            return (
              <li key={item.id}>
                <button
                  type="button"
                  disabled={busy || cancelled || done}
                  onClick={(e) => {
                    e.stopPropagation()
                    if (item.status === "pending" || item.status === "preparing") onBumpItem(item)
                  }}
                  className={`flex min-h-11 w-full items-start gap-2 rounded-md px-2 py-1.5 text-left disabled:cursor-default ${
                    cancelled ? "line-through opacity-40" : ""
                  }`}
                  style={{
                    backgroundColor: visual.accent ? kdsTint(visual.accent, "soft") : undefined,
                  }}
                >
                  <span className="shrink-0 font-bold tabular-nums">{item.qty}×</span>
                  <span className="min-w-0 flex-1">
                    <span className={done ? "opacity-70" : "font-semibold"}>{item.name}</span>
                    {item.note && (
                      <span className="block text-muted-foreground" style={{ fontSize: "0.8em" }}>
                        {item.note}
                      </span>
                    )}
                  </span>
                  {done ? (
                    <Check
                      className="mt-0.5 size-5 shrink-0"
                      style={{ color: visual.accent ?? undefined }}
                    />
                  ) : item.status === "preparing" ? (
                    <Badge variant="secondary" className="shrink-0">
                      {visual.label}
                    </Badge>
                  ) : null}
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
