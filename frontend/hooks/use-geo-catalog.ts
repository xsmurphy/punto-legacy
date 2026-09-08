"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type { GeoCatalogStatus, GeoCatalogSyncResult, GeoNode } from "@/lib/types/geo"

const GEO_KEY = ["geo"]

/**
 * El catálogo geográfico es dato de PLATAFORMA y cambia cuando la autoridad
 * tributaria crea o renombra una ciudad — o sea, casi nunca. Cachear una hora
 * evita que abrir y cerrar el formulario de facturación electrónica dispare
 * una ráfaga de fetches por algo que no se mueve.
 */
const CATALOG_STALE_TIME = 60 * 60 * 1000

/**
 * Cobertura del catálogo. `departments === 0` significa que todavía no se
 * sincronizó: el formulario usa ese dato para decidir si ofrece los selectores
 * o degrada a la carga manual de códigos.
 */
export function useGeoStatus() {
  return useQuery<GeoCatalogStatus>({
    queryKey: [...GEO_KEY, "status"],
    queryFn: () => api.get<GeoCatalogStatus>("/v1/geo?resource=status"),
    staleTime: CATALOG_STALE_TIME,
  })
}

/** Departamentos. Sin país devuelve los de todos los países cargados. */
export function useGeoDepartments(countryCode?: string) {
  return useQuery<GeoNode[]>({
    queryKey: [...GEO_KEY, "departments", countryCode ?? ""],
    queryFn: async () =>
      (
        await api.get<{ items: GeoNode[] }>(
          `/v1/geo?resource=departments${countryCode ? `&country=${encodeURIComponent(countryCode)}` : ""}`,
        )
      ).items,
    staleTime: CATALOG_STALE_TIME,
  })
}

/**
 * Distritos de un departamento. Sin departamento la query no corre: la lista
 * completa de distritos no le sirve a nadie y la cascada siempre viene de
 * haber elegido uno.
 */
export function useGeoDistricts(departmentCode: number | null, countryCode?: string) {
  return useQuery<GeoNode[]>({
    queryKey: [...GEO_KEY, "districts", departmentCode ?? 0, countryCode ?? ""],
    enabled: departmentCode !== null,
    queryFn: async () =>
      (
        await api.get<{ items: GeoNode[] }>(
          `/v1/geo?resource=districts&department=${departmentCode}` +
            (countryCode ? `&country=${encodeURIComponent(countryCode)}` : ""),
        )
      ).items,
    staleTime: CATALOG_STALE_TIME,
  })
}

/**
 * Ciudades de un distrito (o, en su defecto, de un departamento), con búsqueda
 * por nombre.
 *
 * La búsqueda va al SERVIDOR y no se filtra en el cliente: son ~6.400 ciudades
 * y bajarlas todas para filtrar en memoria sería mandarle el catálogo entero
 * al browser en cada apertura del formulario. El backend compara sin acentos,
 * así que "capiata" encuentra "Capiatá".
 */
export function useGeoCities(
  districtCode: number | null,
  departmentCode: number | null,
  search: string,
  countryCode?: string,
) {
  const parent =
    districtCode !== null
      ? `district=${districtCode}`
      : departmentCode !== null
        ? `department=${departmentCode}`
        : null

  return useQuery<GeoNode[]>({
    queryKey: [...GEO_KEY, "cities", parent ?? "", search, countryCode ?? ""],
    enabled: parent !== null,
    queryFn: async () =>
      (
        await api.get<{ items: GeoNode[] }>(
          `/v1/geo?resource=cities&${parent}&search=${encodeURIComponent(search)}` +
            (countryCode ? `&country=${encodeURIComponent(countryCode)}` : ""),
        )
      ).items,
    staleTime: CATALOG_STALE_TIME,
  })
}

/**
 * Dispara la sincronización a mano (requiere `einvoice.manage`). Es la salida
 * cuando el catálogo todavía está vacío: sin esto, el comercio tendría que
 * esperar al cron semanal para poder elegir su domicilio de una lista.
 */
export function useSyncGeoCatalog() {
  const qc = useQueryClient()
  return useMutation<GeoCatalogSyncResult, Error, void>({
    mutationFn: () => api.post<GeoCatalogSyncResult>("/v1/geo?action=sync"),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: GEO_KEY })
    },
  })
}
