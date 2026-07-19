"use client"

/**
 * Mesas (dining_table, mig 80, context/15-mesas-module-plan.md F0+F1) +
 * editor de layout. Config del panel — `api-client` (cookie `_jwt_panel`).
 *
 * `listWithState` (resource=state) es el mismo shape que va a consumir el
 * plano operativo del POS en F2 — acá se usa solo para la preview del
 * editor ("Vista grilla / Vista layout" del toggle).
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export type TableShape = "square" | "round" | "rect" | "bar" | "decor_wall" | "decor_plant"
export type TableState = "free" | "occupied" | "bill_requested" | "disabled"

export interface DiningTable {
  id: string
  companyId: string
  outletId: string
  sectorId: string | null
  name: string
  seats: number
  shape: TableShape
  posX: number | null
  posY: number | null
  width: number | null
  height: number | null
  rotation: number
  status: number
  sort: number
  createdAt: string | null
}

export interface DiningTableWithState extends DiningTable {
  state: TableState
  session: {
    id: string
    status: string
    guests: number | null
    waiterId: string | null
    openedAt: string | null
    orderCount: number
  } | null
}

export interface DiningTablePayload {
  outletId?: string
  sectorId?: string | null
  name?: string
  seats?: number
  shape?: TableShape
  sort?: number
  status?: number
}

export interface LayoutPosition {
  tableId: string
  posX: number | null
  posY: number | null
  width: number | null
  height: number | null
  rotation?: number
  shape?: TableShape
}

export function useDiningTables(outletId: string | undefined, sectorId?: string) {
  return useQuery<{ tables: DiningTable[] }>({
    queryKey: ["dining-tables", outletId, sectorId ?? null],
    queryFn: () =>
      api.get(`/v1/dining-tables?outletId=${outletId}${sectorId ? `&sectorId=${sectorId}` : ""}`),
    enabled: !!outletId,
    staleTime: 15 * 1000,
  })
}

/** Plano con estado derivado — usado por la preview del editor. */
export function useDiningTablesState(outletId: string | undefined) {
  return useQuery<{ tables: DiningTableWithState[] }>({
    queryKey: ["dining-tables", "state", outletId],
    queryFn: () => api.get(`/v1/dining-tables?outletId=${outletId}&resource=state`),
    enabled: !!outletId,
    staleTime: 10 * 1000,
  })
}

export function useCreateDiningTable() {
  const qc = useQueryClient()
  return useMutation<DiningTable, Error, DiningTablePayload>({
    mutationFn: (body) => api.post<DiningTable>("/v1/dining-tables", body as Record<string, unknown>),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["dining-tables"] }),
  })
}

export function useBulkCreateDiningTables() {
  const qc = useQueryClient()
  return useMutation<{ tableIds: string[] }, Error, { outletId: string; count: number; sectorId?: string }>({
    mutationFn: (body) =>
      api.post<{ tableIds: string[] }>("/v1/dining-tables?action=bulk", body as Record<string, unknown>),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["dining-tables"] }),
  })
}

export function useUpdateDiningTable() {
  const qc = useQueryClient()
  return useMutation<DiningTable, Error, { id: string; values: DiningTablePayload }>({
    mutationFn: ({ id, values }) =>
      api.put<DiningTable>(`/v1/dining-tables?id=${id}`, values as Record<string, unknown>),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["dining-tables"] }),
  })
}

export function useDeleteDiningTable() {
  const qc = useQueryClient()
  return useMutation<{ deleted: boolean }, Error, string>({
    mutationFn: (id) => api.del(`/v1/dining-tables?id=${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["dining-tables"] }),
  })
}

/** Guarda posición/tamaño/rotación de un batch — botón "Guardar" del editor de layout. */
export function useSaveTableLayout() {
  const qc = useQueryClient()
  return useMutation<
    { tables: DiningTableWithState[] },
    Error,
    { outletId: string; positions: LayoutPosition[] }
  >({
    mutationFn: (body) =>
      api.post<{ tables: DiningTableWithState[] }>(
        "/v1/dining-tables?action=layout",
        body as unknown as Record<string, unknown>,
      ),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["dining-tables"] }),
  })
}
