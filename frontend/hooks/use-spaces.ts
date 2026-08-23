"use client"

/**
 * Espacios (space, mig 80, context/15-espacios-module-plan.md F0+F1) +
 * editor de layout. Config del panel — `api-client` (cookie `_jwt_panel`).
 *
 * Un "espacio" es genérico según el rubro del comercio: mesa (gastronomía),
 * silla de atención (peluquería), habitación (hostal/hotel).
 *
 * `listWithState` (resource=state) es el mismo shape que consume el plano
 * operativo del POS en F2 — acá se usa solo para la preview del editor
 * ("Vista grilla / Vista layout" del toggle).
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export type SpaceShape = "square" | "round" | "rect" | "bar" | "decor_wall" | "decor_plant"
// `reserved` aún NO lo emite el backend (reservas = F4 pendiente,
// context/15-espacios-module-plan.md). Se define acá para que el mapping de
// color (lib/pos/space-state-visuals.ts) y el tile queden listos para F4 sin
// reabrir el union. Fuente única del tipo — `use-pos-spaces.ts` lo re-exporta.
export type SpaceState = "free" | "occupied" | "bill_requested" | "reserved" | "disabled"

export interface Space {
  id: string
  companyId: string
  outletId: string
  sectorId: string | null
  name: string
  seats: number
  shape: SpaceShape
  posX: number | null
  posY: number | null
  width: number | null
  height: number | null
  rotation: number
  status: number
  sort: number
  createdAt: string | null
}

export interface SpaceWithState extends Space {
  state: SpaceState
  session: {
    id: string
    status: string
    guests: number | null
    waiterId: string | null
    /**
     * Nombre libre que el mozo le puso a ESTA ocupación ("los del
     * cumpleaños") — mig 163. No confundir con `name` del espacio, que es el
     * nombre fijo de la mesa y vive en el nivel de arriba de este objeto.
     */
    alias: string | null
    openedAt: string | null
    orderCount: number
  } | null
}

export interface SpacePayload {
  outletId?: string
  sectorId?: string | null
  name?: string
  seats?: number
  shape?: SpaceShape
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
  shape?: SpaceShape
}

export function useSpaces(outletId: string | undefined, sectorId?: string) {
  return useQuery<{ spaces: Space[] }>({
    queryKey: ["spaces", outletId, sectorId ?? null],
    queryFn: () =>
      api.get(`/v1/spaces?outletId=${outletId}${sectorId ? `&sectorId=${sectorId}` : ""}`),
    enabled: !!outletId,
    staleTime: 15 * 1000,
  })
}

/** Plano con estado derivado — usado por la preview del editor. */
export function useSpacesState(outletId: string | undefined) {
  return useQuery<{ spaces: SpaceWithState[] }>({
    queryKey: ["spaces", "state", outletId],
    queryFn: () => api.get(`/v1/spaces?outletId=${outletId}&resource=state`),
    enabled: !!outletId,
    staleTime: 10 * 1000,
  })
}

export function useCreateSpace() {
  const qc = useQueryClient()
  return useMutation<Space, Error, SpacePayload>({
    mutationFn: (body) => api.post<Space>("/v1/spaces", body as Record<string, unknown>),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["spaces"] }),
  })
}

export function useBulkCreateSpaces() {
  const qc = useQueryClient()
  return useMutation<{ spaceIds: string[] }, Error, { outletId: string; count: number; sectorId?: string }>({
    mutationFn: (body) =>
      api.post<{ spaceIds: string[] }>("/v1/spaces?action=bulk", body as Record<string, unknown>),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["spaces"] }),
  })
}

export function useUpdateSpace() {
  const qc = useQueryClient()
  return useMutation<Space, Error, { id: string; values: SpacePayload }>({
    mutationFn: ({ id, values }) =>
      api.put<Space>(`/v1/spaces?id=${id}`, values as Record<string, unknown>),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["spaces"] }),
  })
}

export function useDeleteSpace() {
  const qc = useQueryClient()
  return useMutation<{ deleted: boolean }, Error, string>({
    mutationFn: (id) => api.del(`/v1/spaces?id=${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["spaces"] }),
  })
}

/** Guarda posición/tamaño/rotación de un batch — botón "Guardar" del editor de layout. */
export function useSaveSpaceLayout() {
  const qc = useQueryClient()
  return useMutation<
    { spaces: SpaceWithState[] },
    Error,
    { outletId: string; positions: LayoutPosition[] }
  >({
    mutationFn: (body) =>
      api.post<{ spaces: SpaceWithState[] }>(
        "/v1/spaces?action=layout",
        body as unknown as Record<string, unknown>,
      ),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["spaces"] }),
  })
}
