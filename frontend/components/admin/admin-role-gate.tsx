"use client"

import { ShieldAlert } from "lucide-react"
import { EmptyState } from "@/components/empty-state"
import { useAdminContext } from "@/components/admin/admin-auth-guard"
import { adminRoleAtLeast, type AdminRole } from "@/hooks/use-admin"

/**
 * Gate de UI por rol (F6 §1, context/34-admin-saas-plan.md). El backend YA
 * rechaza con 403 (adminRequireRole) — esto es solo para no mostrar una
 * pantalla en blanco/errores de query cuando un admin sin el rol necesario
 * entra por URL directa a una página owner-only (el nav ya la oculta).
 */
export function AdminRoleGate({
  minRole,
  children,
}: {
  minRole: AdminRole
  children: React.ReactNode
}) {
  const admin = useAdminContext()

  if (!adminRoleAtLeast(admin.role, minRole)) {
    return (
      <EmptyState
        icon={ShieldAlert}
        title="Acceso restringido"
        description={`Esta sección requiere rol "${minRole}" o superior. Tu rol actual es "${admin.role}".`}
      />
    )
  }

  return <>{children}</>
}
