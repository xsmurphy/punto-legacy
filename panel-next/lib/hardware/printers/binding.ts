import { create } from "zustand"
import { persist, createJSONStorage } from "zustand/middleware"

export type PrinterTransport = "usb"
export type PrinterMode = "escpos" | "native"
export type PrinterDocType =
  | "receipt"
  | "quote"
  | "order"
  | "withdraw"
  | "delivery"
  | "closeReg"
  | "return"

export interface PrinterBinding {
  id: string
  name: string
  color: string
  transport: PrinterTransport
  vendorId: number
  productId: number
  deviceLabel: string
  mode: PrinterMode
  templateId: string | null
  paperWidthMm: 58 | 80
  copies: number
  openDrawer: boolean
  autoPrint: boolean
  printDelay: number
  categoryIds: string[]
  docTypes: PrinterDocType[]
  createdAt: string
}

interface PrinterBindingState {
  bindings: PrinterBinding[]
  addBinding: (b: PrinterBinding) => void
  removeBinding: (id: string) => void
  updateBinding: (id: string, partial: Partial<Omit<PrinterBinding, "id" | "createdAt">>) => void
  getAllBindings: () => PrinterBinding[]
  getBindingsByDocType: (docType: PrinterDocType) => PrinterBinding[]
  getBindingsForSale: (docType: PrinterDocType, itemCategoryIds: string[]) => PrinterBinding[]
}

export const usePrinterBindingsStore = create<PrinterBindingState>()(
  persist(
    (set, get) => ({
      bindings: [],

      addBinding: (b) => set((s) => ({ bindings: [...s.bindings, b] })),

      removeBinding: (id) =>
        set((s) => ({ bindings: s.bindings.filter((b) => b.id !== id) })),

      updateBinding: (id, partial) =>
        set((s) => ({
          bindings: s.bindings.map((b) => (b.id === id ? { ...b, ...partial } : b)),
        })),

      getAllBindings: () => get().bindings,

      getBindingsByDocType: (docType) =>
        get().bindings.filter((b) => b.docTypes.includes(docType)),

      getBindingsForSale: (docType, itemCategoryIds) =>
        get().bindings.filter(
          (b) =>
            b.docTypes.includes(docType) &&
            (b.categoryIds.length === 0 ||
              b.categoryIds.some((c) => itemCategoryIds.includes(c))),
        ),
    }),
    {
      name: "punto.printers.bindings",
      storage: createJSONStorage(() => localStorage),
      version: 2,
      migrate: (persistedState: unknown, fromVersion: number) => {
        if (fromVersion < 2) {
          const old = persistedState as { bindings?: Array<Record<string, unknown>> }
          const bindings = (old.bindings ?? []).map((b) => ({
            id: b["id"] as string,
            name: (b["label"] as string | undefined) ?? "Impresora",
            color: "#7bd148",
            transport: "usb" as const,
            vendorId: b["vendorId"] as number,
            productId: b["productId"] as number,
            deviceLabel: (b["label"] as string | undefined) ?? "",
            mode: "escpos" as const,
            templateId: null,
            paperWidthMm: (b["paperWidthMm"] as 58 | 80 | undefined) ?? 80,
            copies: 1,
            openDrawer: false,
            autoPrint: false,
            printDelay: 0,
            categoryIds: [],
            docTypes: [
              (b["role"] as string | undefined) === "kitchen" ? "order" : "receipt",
            ] as PrinterDocType[],
            createdAt: (b["createdAt"] as string | undefined) ?? new Date().toISOString(),
          }))
          return { bindings }
        }
        return persistedState as { bindings: PrinterBinding[] }
      },
    },
  ),
)
