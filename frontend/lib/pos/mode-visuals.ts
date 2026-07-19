/**
 * Mapeo único modo del carrito → color/label/icono (context/20-design-system.md
 * §"Colores de modo del POS").
 *
 * Fuente única de los "2 lugares" del color de modo: (1) CTA principal del
 * carrito, (2) banda superior del panel. Un color fijo por modo — agenda y
 * futuros modos se suman ACÁ, nunca con mapping inline duplicado en
 * cart-panel.tsx u otro componente.
 *
 * Hex SIEMPRE vía `PALETTE_COLORS` (lib/ui/color-palette.ts) — nunca hex
 * nuevo. Contraste: blanco sobre amber/emerald/violet no llega a AA (medido:
 * ~2.1:1, ~2.5:1, ~4.2:1 respectivamente contra el mínimo 3:1 de texto
 * grande) — se usa texto oscuro (`text-black`) sobre los 3 acentos, que sí
 * cumple AA holgado (>4.9:1) en cualquier tamaño.
 */
import type { LucideIcon } from "lucide-react"
import { LayoutGrid, CalendarClock, ClipboardList, FileText } from "lucide-react"
import { resolveColorBg } from "@/lib/ui/color-palette"

export type CartModeKey =
  | "venta"
  | "orden-mostrador"
  | "orden-espacio"
  | "cotizacion"
  | "agenda"

function paletteHex(key: string): string {
  const hex = resolveColorBg(key)
  if (!hex) throw new Error(`[mode-visuals] color "${key}" no está en PALETTE_COLORS`)
  return hex
}

export interface ModeVisual {
  /** null = venta, baseline neutral sin tinte — "cualquier color = no estás cobrando". */
  color: string | null
  label: string
  icon: LucideIcon | null
}

export const MODE_VISUALS: Record<CartModeKey, ModeVisual> = {
  venta: { color: null, label: "Venta", icon: null },
  "orden-mostrador": { color: paletteHex("emerald"), label: "Orden", icon: ClipboardList },
  // LayoutGrid: ícono único del concepto Espacios en todo el sistema (mismo que
  // el sidebar del POS post-rename Mesas→Espacios). No usar Armchair.
  "orden-espacio": { color: paletteHex("emerald"), label: "Orden", icon: LayoutGrid },
  cotizacion: { color: paletteHex("amber"), label: "Cotización", icon: FileText },
  // Reservado — "Modo reserva" (context/24-orders-module-plan.md, O4, fuera
  // de alcance). No hay estado real hoy que dispare este modo; queda
  // definido acá para que agenda lo consuma sin reabrir el mapping.
  agenda: { color: paletteHex("violet"), label: "Agenda", icon: CalendarClock },
}

/**
 * Resuelve el modo visual desde el estado del carrito.
 *
 * - `savingQuote` (usePosUIStore) tiene prioridad: cotización es una acción
 *   de guardado inmediato, NO un posMode sticky (lib/cart/store.ts, campo
 *   `posMode` — ver comentario ahí "no se toca su mecanismo acá") — su única
 *   ventana de vida es la llamada async a `createQuote()`, así que el color
 *   solo se refleja mientras esa promesa está en vuelo.
 * - `posMode === "orden"` se divide en 2 variantes de color según haya o no
 *   espacio seleccionado (mismo color, ícono/label distinto).
 */
export function resolveCartMode(
  posMode: "venta" | "orden",
  spaceName: string | null,
  savingQuote: boolean,
): CartModeKey {
  if (savingQuote) return "cotizacion"
  if (posMode === "orden") return spaceName ? "orden-espacio" : "orden-mostrador"
  return "venta"
}
