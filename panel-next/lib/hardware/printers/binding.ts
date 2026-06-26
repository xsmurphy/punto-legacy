/**
 * Store de vínculos impresora → rol (Zustand + persistencia en localStorage).
 *
 * Cada binding asocia un dispositivo USB (identificado por vendorId + productId)
 * con un rol lógico (ticketera, comandera, factura A4). La UI de Ajustes →
 * Impresoras lee y escribe este store; el helper printTest() y los builders de
 * tickets lo consumen para resolver qué dispositivo usar.
 */

import { create } from "zustand"
import { persist, createJSONStorage } from "zustand/middleware"

// ── Tipos ────────────────────────────────────────────────────────────────────

export type PrinterRole = "receipt" | "kitchen" | "invoice"
export type PrinterTransport = "usb"

export interface PrinterBinding {
  /** UUID v4 generado al vincular. */
  id: string
  role: PrinterRole
  transport: PrinterTransport
  vendorId: number
  productId: number
  /** Nombre legible dado por el usuario (ej. "Epson TM-T20III"). */
  label: string
  /** Ancho del papel en mm. Default 80. */
  paperWidthMm: 58 | 80
  /** ISO 8601 — momento del alta del vínculo. */
  createdAt: string
}

// ── Store ────────────────────────────────────────────────────────────────────

interface PrinterBindingsState {
  bindings: PrinterBinding[]

  addBinding: (binding: PrinterBinding) => void
  removeBinding: (id: string) => void
  updateBinding: (id: string, patch: Partial<Omit<PrinterBinding, "id" | "createdAt">>) => void
  getBindingByRole: (role: PrinterRole) => PrinterBinding | undefined
  getAllBindings: () => PrinterBinding[]
}

export const usePrinterBindingsStore = create<PrinterBindingsState>()(
  persist(
    (set, get) => ({
      bindings: [],

      addBinding: (binding) =>
        set((s) => ({ bindings: [...s.bindings, binding] })),

      removeBinding: (id) =>
        set((s) => ({ bindings: s.bindings.filter((b) => b.id !== id) })),

      updateBinding: (id, patch) =>
        set((s) => ({
          bindings: s.bindings.map((b) =>
            b.id === id ? { ...b, ...patch } : b,
          ),
        })),

      getBindingByRole: (role) => get().bindings.find((b) => b.role === role),

      getAllBindings: () => get().bindings,
    }),
    {
      name: "punto.printers.bindings",
      storage: createJSONStorage(() => localStorage),
    },
  ),
)
