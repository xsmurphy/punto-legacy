/**
 * Mapeo único estado de espacio → color/label (context/20-design-system.md §7
 * "Colores de modo del POS"). Espejo de `lib/pos/mode-visuals.ts`: el color de
 * cada estado se decide ACÁ, nunca con mapping inline duplicado en
 * `pos-space-tile.tsx` u otro componente.
 *
 * Coherencia con los colores de modo del carrito: `occupied` reusa el
 * `emerald` del modo Orden y `reserved` el `violet` del modo Agenda — el
 * cajero ve el mismo color para el mismo concepto en el plano y en la banda
 * del carrito. `bill_requested` ("pidió la cuenta") usa `rose` — la única
 * señal que exige atención inmediata.
 *
 * Hex SIEMPRE vía `PALETTE_COLORS` (lib/ui/color-palette.ts) — fijos, mismos
 * en light/dark (no son tokens de tema). Contraste: blanco sobre
 * emerald/rose/violet no llega a AA (§7); sobre los acentos sólidos (pills) se
 * usa texto oscuro (`text-black`, >4.9:1). El texto principal del tile queda
 * en `text-foreground` sobre el fondo tintado translúcido, no sobre el acento
 * sólido.
 */
import type { SpaceState } from "@/hooks/use-spaces"
import { resolveColorBg } from "@/lib/ui/color-palette"

function paletteHex(key: string): string {
  const hex = resolveColorBg(key)
  if (!hex) throw new Error(`[space-state-visuals] color "${key}" no está en PALETTE_COLORS`)
  return hex
}

export interface SpaceStateVisual {
  /** null = neutro, sin tinte (free/disabled usan tokens de tema). */
  accent: string | null
  label: string
}

export const SPACE_STATE_VISUALS: Record<SpaceState, SpaceStateVisual> = {
  free: { accent: null, label: "Libre" },
  occupied: { accent: paletteHex("emerald"), label: "Ocupado" },
  bill_requested: { accent: paletteHex("rose"), label: "Pidió la cuenta" },
  // Reservado — el backend aún NO emite este estado (reservas = F4 pendiente,
  // context/15-espacios-module-plan.md). Definido acá para que F4 lo consuma
  // directo sin reabrir el mapping ni el tile.
  reserved: { accent: paletteHex("violet"), label: "Reservado" },
  disabled: { accent: null, label: "Deshabilitado" },
}

/**
 * Fondo tintado del tile a partir del acento. Más presente que el `/10` previo
 * (que en dark era casi invisible sobre el card): ~17% de opacidad, suficiente
 * para leer el estado a distancia de cajero sin tapar el texto principal.
 * Hex de 8 dígitos (RGBA) soportado por todos los navegadores objetivo.
 */
export function spaceTintBg(accentHex: string): string {
  return `${accentHex}2b`
}
