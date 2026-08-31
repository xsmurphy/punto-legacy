"use client"
import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import { KeyRound, LogOut } from "lucide-react"
import { toast } from "sonner"
import { DataTable } from "@/components/data-table/data-table"
import { RowActions } from "@/components/data-table/row-actions"
import { Badge } from "@/components/ui/badge"
import { Switch } from "@/components/ui/switch"
import { Label } from "@/components/ui/label"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"
import { EmptyState } from "@/components/empty-state"
import { useSessions, useRevokeSession, type AuthSession } from "@/hooks/use-sessions"

function niceDate(iso: string | null): string {
  if (!iso) return "—"
  return new Intl.DateTimeFormat("es", {
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(iso))
}

const REALM_LABELS: Record<string, string> = {
  panel: "Panel",
  "pos-app": "POS",
  screen: "Pantalla",
  admin: "Admin",
  // Las API keys son sesiones como cualquier otra y aparecen acá; se
  // administran desde /settings/api-keys, pero sin esta etiqueta la tabla
  // mostraría "api" crudo.
  api: "Integración",
}

const MODULE_LABELS: Record<string, string> = {
  panel: "Panel",
  pos: "POS",
  screen: "Pantalla",
  kds: "KDS",
  display: "Display",
  admin: "Admin",
  api: "Integración",
}

export default function SessionsPage() {
  const [showRevoked, setShowRevoked] = React.useState(false)
  const [revokeId, setRevokeId] = React.useState<string | null>(null)

  const { data: sessions = [], isLoading } = useSessions({ showRevoked })
  const revokeSession = useRevokeSession()

  const columns = React.useMemo<ColumnDef<AuthSession>[]>(
    () => [
      {
        accessorKey: "realm",
        header: "Realm / Módulo",
        cell: ({ row }) => {
          const realm = row.original.realm
          const module = row.original.module
          return (
            <div className="flex items-center gap-1.5">
              <Badge variant="secondary">{REALM_LABELS[realm] ?? realm}</Badge>
              {module && module !== realm && (
                <Badge variant="outline">{MODULE_LABELS[module] ?? module}</Badge>
              )}
            </div>
          )
        },
      },
      {
        accessorKey: "userId",
        header: "Usuario",
        cell: ({ row }) => {
          const name = row.original.userName
          const id = row.original.userId
          return name ? (
            <span>{name}</span>
          ) : (
            <span className="font-mono text-xs text-muted-foreground">{id ?? "—"}</span>
          )
        },
      },
      {
        accessorKey: "outletId",
        header: "Sucursal",
        cell: ({ row }) => {
          const name = row.original.outletName
          const id = row.original.outletId
          return name ? (
            <span>{name}</span>
          ) : (
            <span className="font-mono text-xs text-muted-foreground">{id ?? "—"}</span>
          )
        },
      },
      {
        accessorKey: "deviceId",
        header: "Dispositivo",
        cell: ({ row }) => {
          const name = row.original.deviceName
          const id = row.original.deviceId
          if (!id) return <span className="text-muted-foreground">—</span>
          return name ? (
            <span>{name}</span>
          ) : (
            <span className="font-mono text-xs text-muted-foreground">{id}</span>
          )
        },
      },
      {
        accessorKey: "lastSeenAt",
        header: "Última actividad",
        cell: ({ row }) => niceDate(row.original.lastSeenAt),
      },
      {
        accessorKey: "ipLast",
        header: "IP",
        cell: ({ row }) => (
          <span className="font-mono text-xs">{row.original.ipLast ?? "—"}</span>
        ),
      },
      {
        accessorKey: "status",
        header: "Estado",
        cell: ({ row }) =>
          row.original.status === 1 ? (
            <Badge variant="default">Activa</Badge>
          ) : (
            <Badge variant="secondary">Revocada</Badge>
          ),
      },
      {
        id: "actions",
        header: "",
        cell: ({ row }) => (
          <RowActions
            actions={[
              {
                label: "Revocar",
                icon: LogOut,
                variant: "destructive",
                onSelect: () => setRevokeId(row.original.sessionId),
                hidden: row.original.status !== 1,
              },
            ]}
          />
        ),
      },
    ],
    [],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold">Sesiones</h1>
        <p className="text-sm text-muted-foreground">
          Sesiones activas de panel, POS y pantallas de tu comercio. Podés revocar
          cualquier sesión para cerrarla de inmediato.
        </p>
      </header>

      <DataTable
        tableId="auth-sessions"
        columns={columns}
        data={sessions}
        isLoading={isLoading}
        searchPlaceholder="Buscar sesión..."
        exportFileName={null}
        rightToolbarSlot={
          <div className="flex items-center gap-2">
            <Label
              htmlFor="show-revoked-sessions"
              className="text-sm text-muted-foreground cursor-pointer"
            >
              Mostrar revocadas
            </Label>
            <Switch
              id="show-revoked-sessions"
              checked={showRevoked}
              onCheckedChange={setShowRevoked}
            />
          </div>
        }
        emptyMessage={
          <EmptyState
            icon={KeyRound}
            title="Sin sesiones"
            description="No hay sesiones registradas para tu comercio."
          />
        }
      />

      <AlertDialog
        open={revokeId !== null}
        onOpenChange={(o) => {
          if (!o) setRevokeId(null)
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Revocar sesión</AlertDialogTitle>
            <AlertDialogDescription>
              La sesión quedará inactiva de inmediato. Si el usuario o dispositivo tiene
              otra sesión válida, seguirá funcionando. Esta acción no se puede deshacer.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              onClick={() => {
                if (!revokeId) return
                revokeSession.mutate(revokeId, {
                  onSuccess: () => {
                    toast.success("Sesión revocada")
                    setRevokeId(null)
                  },
                  onError: (err) => {
                    toast.error(err.message)
                  },
                })
              }}
            >
              Revocar
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
