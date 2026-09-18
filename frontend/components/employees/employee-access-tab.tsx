"use client"

/**
 * El ACCESO de una persona: su rol, sus sucursales y su código de caja.
 *
 * Acá no se edita nada en el lugar: se muestra el estado y se abre el MISMO
 * diálogo de usuario que usa el listado (`UserFormDialog`). Un segundo
 * formulario de credenciales sería un segundo lugar donde se cambia un rol, con
 * sus propias reglas de escalamiento — el `originalRoleId` de `use-team.ts`
 * documenta lo que cuesta equivocarse ahí.
 *
 * Los PERMISOS tampoco se editan por persona: cuelgan del ROL. El link va a
 * Roles, que es donde viven de verdad.
 */

import * as React from "react"
import Link from "next/link"
import { ArrowUpRight, Pencil, Shield } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Skeleton } from "@/components/ui/skeleton"
import { FormSection } from "@/components/forms/form-section"
import { UserFormDialog } from "@/components/employees/user-form-dialog"
import { usePermission } from "@/hooks/use-permissions"
import type { TeamMember } from "@/hooks/use-team"
import { formatPhone } from "@/lib/phone"

export function EmployeeAccessTab({
  user,
  isLoading,
}: {
  user: TeamMember | null
  isLoading: boolean
}) {
  const canManage = usePermission("contacts.user.manage")
  const [editing, setEditing] = React.useState(false)

  if (isLoading) {
    return (
      <div className="flex flex-col gap-3">
        <Skeleton className="h-5 w-40" />
        <Skeleton className="h-5 w-64" />
        <Skeleton className="h-5 w-52" />
      </div>
    )
  }

  if (!user) return null

  const outlets = user.outletNames ?? []

  return (
    <div className="flex flex-col gap-6">
      <FormSection title="Acceso al sistema">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label="Rol">
            {user.roleName ? (
              <Badge variant="secondary" className="gap-1">
                <Shield className="size-3" />
                {user.roleName}
              </Badge>
            ) : (
              <span className="text-sm text-muted-foreground">Sin rol asignado</span>
            )}
          </Field>
          <Field label="Estado">
            {user.status === 1 ? (
              <Badge variant="secondary">Activo</Badge>
            ) : (
              <Badge variant="outline">Inactivo</Badge>
            )}
          </Field>
          <Field label="Sucursales">
            <span className="text-sm">
              {outlets.length === 0 ? "Todas" : outlets.join(", ")}
            </span>
          </Field>
          <Field label="Código de caja">
            {user.lockPass ? (
              <Badge variant="secondary">Tiene código</Badge>
            ) : (
              <Badge variant="outline">Sin código</Badge>
            )}
          </Field>
          <Field label="Teléfono">
            <span className="text-sm">{formatPhone(user.phone) || "—"}</span>
          </Field>
          <Field label="Email">
            <span className="text-sm">{user.email || "—"}</span>
          </Field>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {canManage && (
            <Button type="button" variant="outline" onClick={() => setEditing(true)}>
              <Pencil className="size-4" />
              Editar acceso
            </Button>
          )}
          <Button asChild variant="ghost">
            <Link href="/settings/roles">
              Roles y permisos
              <ArrowUpRight className="size-4" />
            </Link>
          </Button>
        </div>
      </FormSection>

      <UserFormDialog open={editing} member={user} onOpenChange={setEditing} />
    </div>
  )
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-1">
      <span className="text-xs text-muted-foreground">{label}</span>
      {children}
    </div>
  )
}
