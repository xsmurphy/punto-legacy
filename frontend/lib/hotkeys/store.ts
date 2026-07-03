/**
 * Store de Hotkeys del POS (Zustand + persistencia local).
 *
 * Hotkeys = grilla de accesos rápidos configurables por caja (estilo Square
 * POS). Cada slot apunta a un artículo vendible / grupo / servicio / categoría.
 *
 * Shape de cada entrada — IDÉNTICO al JSON que el legacy guarda en
 * `register.data.hotkeys` (ncmHotKeys.obj en app.js:23583), para que el
 * wiring al backend (cuando A7 traiga la caja activa) sea directo:
 *   { itemId, position, color, isCategory }
 *
 * Persistencia: por ahora localStorage (stand-in). Cuando exista caja activa
 * (A7/F2) se reemplaza por GET/PUT a `register.data.hotkeys` sin tocar la UI.
 */

import { create } from "zustand"
import { persist, createJSONStorage } from "zustand/middleware"

// ── Tipo (espejo del JSON backend) ──────────────────────────────────────────

export interface Hotkey {
  /** Id del artículo o de la categoría asignada al slot. */
  itemId: string
  /** Índice del slot en la grilla (0-based). */
  position: number
  /** Color para tiles sin imagen (modo tabla periódica). "" = default. */
  color: string
  /** true = categoría (click → entra); false = artículo (click → al carrito). */
  isCategory: boolean
}

// ── Paleta de colores (selector del modo edición) ────────────────────────────
// La paleta canónica del panel vive en lib/ui/color-palette.ts (compartida con
// Usuarios, Impresoras y Medios de pago). Se re-exporta acá con los nombres
// históricos para no romper los importadores existentes del POS.

import { PALETTE_COLORS, resolveColorBg } from "@/lib/ui/color-palette"

export const HOTKEY_COLORS = PALETTE_COLORS
export const hotkeyColorBg = resolveColorBg

// ── Store ────────────────────────────────────────────────────────────────────

interface HotkeysState {
  hotkeys: Hotkey[]
  /** Modo edición activo (se entra desde Menú POS → Ajustes). */
  editing: boolean

  setEditing: (v: boolean) => void
  /** Reemplaza toda la config (al cargar desde backend/seed). */
  hydrate: (hotkeys: Hotkey[]) => void
  /** Asigna un artículo/categoría a un slot. No-op si el slot ya está ocupado. */
  addHotkey: (position: number, itemId: string, isCategory: boolean) => void
  removeHotkey: (position: number) => void
  setColor: (position: number, color: string) => void
  /** Mueve un hotkey de un slot a otro (drag&drop). Swap si el destino está ocupado. */
  moveHotkey: (from: number, to: number) => void
  clearAll: () => void
  /** Reset completo (logout / re-pair). Limpia hotkeys y sale del modo edición. */
  reset: () => void
}

export const useHotkeysStore = create<HotkeysState>()(
  persist(
    (set) => ({
      hotkeys: [],
      editing: false,

      setEditing: (v) => set({ editing: v }),

      hydrate: (hotkeys) => set({ hotkeys }),

      addHotkey: (position, itemId, isCategory) =>
        set((s) => {
          // No sobrescribir un slot ocupado.
          if (s.hotkeys.some((h) => h.position === position)) return s
          // Un ítem o categoría sólo puede estar en UN slot a la vez:
          // si ya está asignado en otro lado, no lo duplicamos.
          if (s.hotkeys.some((h) => h.itemId === itemId && h.isCategory === isCategory)) return s
          return {
            hotkeys: [...s.hotkeys, { itemId, position, color: "", isCategory }],
          }
        }),

      removeHotkey: (position) =>
        set((s) => ({
          hotkeys: s.hotkeys.filter((h) => h.position !== position),
        })),

      setColor: (position, color) =>
        set((s) => ({
          hotkeys: s.hotkeys.map((h) =>
            h.position === position ? { ...h, color } : h,
          ),
        })),

      moveHotkey: (from, to) =>
        set((s) => {
          if (from === to) return s
          const moving = s.hotkeys.find((h) => h.position === from)
          if (!moving) return s
          const atTarget = s.hotkeys.find((h) => h.position === to)
          return {
            hotkeys: s.hotkeys.map((h) => {
              if (h.position === from) return { ...h, position: to }
              if (atTarget && h.position === to) return { ...h, position: from }
              return h
            }),
          }
        }),

      clearAll: () => set({ hotkeys: [] }),

      reset: () => set({ hotkeys: [], editing: false }),
    }),
    {
      name: "punto.pos.hotkeys",
      storage: createJSONStorage(() => localStorage),
      // Solo persistir la config, no el flag de edición.
      partialize: (s) => ({ hotkeys: s.hotkeys }),
    },
  ),
)
