/**
 * Mapeo único estado → color del KDS. Espejo de `lib/pos/space-state-visuals.ts`:
 * el color de cada estado se decide ACÁ, nunca con un mapping inline duplicado
 * en `order-card.tsx` u otro componente.
 *
 * Por qué importa más acá que en cualquier otra pantalla: en el flujo
 * horizontal la tarjeta NO cambia de posición al cambiar de estado. El color
 * es el ÚNICO canal que comunica el estado, y se lee de reojo, a varios metros,
 * por alguien con las manos ocupadas.
 *
 * ⚠ `amber` y `rose` están RESERVADOS para el canal de demora
 * (context/27-delivery-sla-plan.md §A.4 — "el ESTADO pinta el fondo/borde; el
 * SLA pinta SOLO el pill de tiempo. Dos canales, nunca mezclados"). Si el
 * estado también los usara, "demorada" y "en proceso" serían indistinguibles.
 * De `PALETTE_COLORS` quedan slate / sky / emerald / violet para el estado.
 *
 * Hex SIEMPRE vía `PALETTE_COLORS` (lib/ui/color-palette.ts) — fijos, iguales
 * en light/dark (no son tokens de tema; el KDS además corre siempre en dark).
 */

import type { OrderItemStatus, OrderStatus } from "@/hooks/use-orders"
import type { ElapsedTier } from "@/hooks/use-elapsed"
import { STATUS_LABEL, STATUS_ACCENT } from "@/lib/orders/order-display"
import { resolveColorBg } from "@/lib/ui/color-palette"

/** Gris neutro de emergencia — nunca debería usarse (ver comentario abajo). */
const FALLBACK_HEX = "#64748b"

/**
 * Falla SUAVE a propósito. Este módulo se evalúa al importarse, así que un
 * `throw` acá deja la pantalla de cocina en blanco sin recuperación posible
 * salvo redeploy. `PALETTE_COLORS` es compartida por toda la app: un rename
 * o una limpieza en otro módulo no puede tumbar el KDS de un local que está
 * en pleno servicio. Se degrada a gris y se avisa por consola.
 */
function paletteHex(key: string): string {
  const hex = resolveColorBg(key)
  if (!hex) {
    console.error(`[kds-visuals] color "${key}" no está en PALETTE_COLORS — usando gris`)
    return FALLBACK_HEX
  }
  return hex
}

/** Los tres estados que el KDS muestra (`ACTIVE_STATUSES` de la pantalla). */
export type KdsOrderStatus = Extract<OrderStatus, "sent" | "in_progress" | "ready">

export interface KdsVisual {
  /** null = neutro, sin tinte (usa tokens de tema). */
  accent: string | null
  label: string
}

/**
 * Progresión de frío a "hecho": slate (esperando, nadie la tomó) → sky (alguien
 * la está trabajando) → emerald (terminada, sale). `emerald` es el mismo verde
 * que el modo Orden del carrito y `occupied` en el plano de espacios — mismo
 * color para el mismo concepto en todo el producto.
 *
 * Los labels salen de `STATUS_LABEL` (lib/orders/order-display.ts) — fuente
 * única con /pos/ordenes: quien opera y el cajero nombran igual los estados.
 *
 * El color sale de `STATUS_ACCENT` (mismo archivo) — fuente única de estado→
 * color para toda la app, incluida la vista lista de /pos/ordenes. Acá solo
 * se resuelve el fallback gris si algún día esas keys de paleta faltaran.
 */
function statusAccent(status: KdsOrderStatus): string {
  const accent = STATUS_ACCENT[status]
  if (!accent) {
    console.error(`[kds-visuals] STATUS_ACCENT["${status}"] vino null — usando gris`)
    return FALLBACK_HEX
  }
  return accent
}

export const KDS_STATUS_VISUALS: Record<KdsOrderStatus, KdsVisual> = {
  sent: { accent: statusAccent("sent"), label: STATUS_LABEL.sent },
  in_progress: { accent: statusAccent("in_progress"), label: STATUS_LABEL.in_progress },
  ready: { accent: statusAccent("ready"), label: STATUS_LABEL.ready },
}

/**
 * Estado del ítem DENTRO de la comanda. El ítem se marca en su misma posición
 * (pedido explícito del owner): nunca se saca, se mueve ni se reordena — solo
 * cambia de color. Misma escala que la orden para que se lean como una sola
 * cosa.
 *
 * `delivered` no lo produce el KDS (lo setea la pantalla de despacho y el backend
 * bloquea la transición para module=kds, ver `assertModuleCanSetStatus`), pero
 * puede llegar por WS mientras la comanda sigue en pantalla.
 */
export const KDS_ITEM_VISUALS: Record<OrderItemStatus, KdsVisual> = {
  pending: { accent: null, label: "Pendiente" },
  preparing: { accent: paletteHex("sky"), label: "Preparando" },
  ready: { accent: paletteHex("emerald"), label: "Listo" },
  delivered: { accent: paletteHex("violet"), label: "Entregado" },
  cancelled: { accent: null, label: "Cancelado" },
}

/**
 * Canal de demora — SOLO el pill de tiempo, nunca el fondo de la tarjeta
 * (context/27 §A.4). Hoy los tiers salen de los umbrales locales del
 * dispositivo (`useElapsed` + `warnMin`/`lateMin` de la config); cuando llegue
 * F-SLA-0 el tier pasa a calcularse contra `targetminutes` de la orden y este
 * mapping se muda a `lib/pos/sla-visuals.ts` sin tocar los componentes.
 */
export const KDS_TIER_ACCENT: Record<ElapsedTier, string | null> = {
  fresh: null,
  warn: paletteHex("amber"),
  late: paletteHex("rose"),
}

/**
 * Fondo tintado a partir de un acento. Mismo criterio que `spaceTintBg`:
 * suficiente para leerse a distancia sin tapar el texto. Hex de 8 dígitos
 * (RGBA), soportado por todos los navegadores objetivo.
 *
 * Sirve igual en claro y en oscuro: es el MISMO acento a baja opacidad sobre el
 * fondo del modo, así que se resuelve claro sobre claro y oscuro sobre oscuro.
 */
export function kdsTint(accentHex: string, strength: "soft" | "strong" = "soft"): string {
  return `${accentHex}${strength === "strong" ? "33" : "1f"}`
}

/** Modo resuelto de la pantalla (la config puede decir "auto" — ver lib/kds/config.ts). */
export type KdsMode = "dark" | "light"

/**
 * Los acentos de la paleta están elegidos para BRILLAR sobre fondo oscuro. Como
 * FONDO (franja, borde, tinte) funcionan en los dos modos, pero como TEXTO
 * sobre fondo claro no llegan ni cerca del contraste mínimo: sky #38bdf8 sobre
 * blanco da ~1.9:1 y emerald ~2.5:1 — ilegibles, y esta es una pantalla cuyo
 * único propósito es leerse de lejos.
 *
 * Por eso el texto tiene su propio hex en modo claro: el tono 700 del mismo
 * color, que mantiene la identidad (sky sigue siendo azul, emerald verde) y
 * pasa 4.5:1 sobre blanco y sobre el tinte pálido del mismo acento. Mismo
 * criterio que el precedente de context/20 §7 (elegir el color del texto por
 * contraste, no por estética).
 *
 * El mapeo es hex→hex y no key→hex a propósito: los componentes ya tienen el
 * acento resuelto y no deberían tener que arrastrar además la key de paleta.
 */
const LIGHT_TEXT_BY_ACCENT: Record<string, string> = {
  [paletteHex("slate")]: "#475569",
  [paletteHex("sky")]: "#0369a1",
  [paletteHex("emerald")]: "#047857",
  [paletteHex("violet")]: "#6d28d9",
  [paletteHex("amber")]: "#b45309",
  [paletteHex("rose")]: "#be123c",
}

/** El mismo acento, en el tono que se lee como TEXTO en este modo. */
export function kdsTextHex(accentHex: string, mode: KdsMode): string {
  if (mode === "dark") return accentHex
  return LIGHT_TEXT_BY_ACCENT[accentHex] ?? accentHex
}
