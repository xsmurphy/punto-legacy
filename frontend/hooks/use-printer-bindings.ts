"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api, type HttpClient } from "@/lib/api-client"
import { enqueueOp } from "@/lib/pos/pending-ops"
import {
  resolvePrinterBindings,
  saveLocalPrinterBindings,
} from "@/lib/pos/local-register-state"
import type { PrinterBinding } from "@/lib/hardware/printers/binding"

/**
 * `/v1/printer_binding` es multi-realm en backend (panel + pos-app) —
 * consumida por settings (panel) y por varias pantallas del POS (venta,
 * órdenes, transacciones). El POS inyecta `client: posApi` (Bearer); el
 * panel usa el default `api` (cookie). Ver invariante de realm en
 * `lib/api-client.ts`.
 *
 * Las MUTACIONES aceptan el mismo `client` desde 2026-08-23. Antes iban
 * siempre con `api` (cookie del panel) aunque la lectura fuera del device:
 * en el browser del operador eso "andaba" porque tenía las dos sesiones
 * abiertas, pero escribía con el scope del PANEL, no con el de la caja — el
 * mismo cruce de realms que causó el bug de espacios (memoria
 * `project_client_per_realm_no_cross_credentials`). Un cliente por realm, en
 * lectura y en escritura.
 */
export function usePrinterBindings(registerId: string | undefined, opts: { client?: HttpClient } = {}) {
  const client = opts.client ?? api
  const isPos = client !== api
  return useQuery<{ bindings: PrinterBinding[] }>({
    queryKey: ["printer-bindings", registerId, isPos ? "pos" : "panel"],
    queryFn: async () => {
      // Solo el POS lee sin red: el panel corre en una máquina con conexión y
      // cachear su listado agregaría un estado más para razonar, sin ganancia.
      if (!isPos) {
        return client.get<{ bindings: PrinterBinding[] }>(
          `/v1/printer_binding?registerId=${registerId}`,
        )
      }
      try {
        const fresh = await client.get<{ bindings: PrinterBinding[] }>(
          `/v1/printer_binding?registerId=${registerId}`,
        )
        await saveLocalPrinterBindings(registerId ?? "", fresh.bindings)
        return { bindings: await resolvePrinterBindings(registerId ?? "", fresh.bindings) }
      } catch (err) {
        const resolved = await resolvePrinterBindings(registerId ?? "", null)
        if (resolved.length === 0) throw err
        return { bindings: resolved }
      }
    },
    staleTime: 30 * 1000,
    enabled: !!registerId,
  })
}

type CreatePayload = Omit<PrinterBinding, "id" | "createdAt" | "updatedAt"> & { registerId: string }
type UpdatePayload = Partial<Omit<PrinterBinding, "id" | "createdAt" | "updatedAt">> & { id: string }

interface MutationOpts {
  client?: HttpClient
}

/** ¿El fallo fue "no llegué al servidor"? Solo eso se encola. */
function isUnreachable(err: unknown): boolean {
  if (typeof navigator !== "undefined" && !navigator.onLine) return true
  return err instanceof TypeError
}

function newBindingId(): string {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
    return crypto.randomUUID()
  }
  return `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}

/**
 * Alta de impresora.
 *
 * El `id` lo genera el CLIENTE, no `gen_random_uuid()` en el INSERT. Eso
 * resuelve dos cosas de una: la impresora que el cajero acaba de crear sin red
 * tiene desde el primer momento su id definitivo (así se la puede editar o
 * borrar antes de que sincronice, sin un intercambio de ids después), y el
 * alta se vuelve idempotente — el backend inserta con `ON CONFLICT DO NOTHING`,
 * así que un reenvío tras una respuesta perdida devuelve la misma fila en vez
 * de crear una segunda impresora idéntica.
 */
export function useCreatePrinterBinding(registerId: string | undefined, opts: MutationOpts = {}) {
  const qc = useQueryClient()
  const client = opts.client ?? api
  const isPos = client !== api

  return useMutation<{ binding: PrinterBinding }, Error, CreatePayload>({
    mutationFn: async (data) => {
      const id = newBindingId()
      const { registerId: targetRegisterId, ...fields } = data
      const binding = { id, ...fields } as Omit<PrinterBinding, "createdAt" | "updatedAt">

      const enqueueOffline = async (): Promise<{ binding: PrinterBinding }> => {
        await enqueueOp({
          kind: "printerBindingCreate",
          stream: "printer-bindings",
          registerId: targetRegisterId,
          payload: { registerId: targetRegisterId, binding },
          label: `Impresora "${data.name}" — alta`,
        })
        return { binding: { ...binding, createdAt: new Date().toISOString() } as PrinterBinding }
      }

      if (isPos && typeof navigator !== "undefined" && !navigator.onLine) return enqueueOffline()
      try {
        return await client.post("/v1/printer_binding", {
          action: "create",
          id,
          registerId: targetRegisterId,
          ...fields,
        } as unknown as Record<string, unknown>)
      } catch (err) {
        if (isPos && isUnreachable(err)) return enqueueOffline()
        throw err
      }
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ["printer-bindings", registerId] }),
  })
}

export function useUpdatePrinterBinding(registerId: string | undefined, opts: MutationOpts = {}) {
  const qc = useQueryClient()
  const client = opts.client ?? api
  const isPos = client !== api

  return useMutation<{ binding: PrinterBinding }, Error, UpdatePayload>({
    mutationFn: async (data) => {
      const { id, ...patch } = data

      const enqueueOffline = async (): Promise<{ binding: PrinterBinding }> => {
        await enqueueOp({
          kind: "printerBindingUpdate",
          stream: "printer-bindings",
          registerId: registerId ?? "",
          payload: { id, patch },
          label: `Impresora "${patch.name ?? "sin nombre"}" — cambios`,
          // Editar la misma impresora dos veces sin red es una sola edición
          // acumulada, no dos requests al volver la conexión.
          mergePayload: (prev, next) => {
            const a = prev as UpdatePayload & { patch: Record<string, unknown> }
            const b = next as UpdatePayload & { patch: Record<string, unknown> }
            return a.id === b.id ? { id: a.id, patch: { ...a.patch, ...b.patch } } : b
          },
        })
        return { binding: { id, ...patch } as PrinterBinding }
      }

      if (isPos && typeof navigator !== "undefined" && !navigator.onLine) return enqueueOffline()
      try {
        return await client.post("/v1/printer_binding", {
          action: "update",
          ...data,
        } as unknown as Record<string, unknown>)
      } catch (err) {
        if (isPos && isUnreachable(err)) return enqueueOffline()
        throw err
      }
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ["printer-bindings", registerId] }),
  })
}

export function useDeletePrinterBinding(registerId: string | undefined, opts: MutationOpts = {}) {
  const qc = useQueryClient()
  const client = opts.client ?? api
  const isPos = client !== api

  return useMutation<{ deleted: boolean }, Error, string>({
    mutationFn: async (id) => {
      const enqueueOffline = async (): Promise<{ deleted: boolean }> => {
        await enqueueOp({
          kind: "printerBindingDelete",
          stream: "printer-bindings",
          registerId: registerId ?? "",
          payload: { id },
          label: "Impresora — baja",
        })
        return { deleted: true }
      }

      if (isPos && typeof navigator !== "undefined" && !navigator.onLine) return enqueueOffline()
      try {
        return await client.post("/v1/printer_binding", { action: "delete", id })
      } catch (err) {
        if (isPos && isUnreachable(err)) return enqueueOffline()
        throw err
      }
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ["printer-bindings", registerId] }),
  })
}
