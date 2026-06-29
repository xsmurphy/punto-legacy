"use client"

import { useBootstrap } from "@/hooks/use-bootstrap"

/**
 * Retorna si el usuario tiene un permiso específico.
 * Lee permissions[] del bootstrap (cacheado por TanStack Query).
 * Retorna `false` mientras el bootstrap carga (safe default).
 */
export function usePermission(perm: string): boolean {
  const { data } = useBootstrap()
  return data?.user?.permissions?.includes(perm) ?? false
}

/**
 * Retorna el array de permisos del usuario. Vacío mientras carga.
 */
export function usePermissions(): string[] {
  const { data } = useBootstrap()
  return data?.user?.permissions ?? []
}
