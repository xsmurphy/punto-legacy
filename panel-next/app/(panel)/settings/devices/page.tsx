"use client"
import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import { Plus, Trash2, Bell, MonitorSmartphone } from "lucide-react"
import { toast } from "sonner"
import { DataTable } from "@/components/data-table/data-table"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Switch } from "@/components/ui/switch"
import { Label } from "@/components/ui/label"
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
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
import { useRevokeScreen } from "@/hooks/use-screens"
import { useRevokePosDevice, useDeletePosDevice } from "@/hooks/use-pos-devices"
import { useDeviceInvitations } from "@/hooks/use-device-invitations"
import { useConnectedDevices } from "@/hooks/use-connected-devices"
import { DeviceInvitesTab } from "@/components/settings/device-invites-tab"
import { DeviceInviteCreateDialog } from "@/components/settings/device-invite-create-dialog"
import { DEVICE_KIND_LABELS, type ConnectedDevice } from "@/lib/devices/connected-device"
import { EmptyState } from "@/components/empty-state"

function niceDate(iso: string | null): string {
  if (!iso) return "—"
  return new Intl.DateTimeFormat("es", {
    day: "numeric", month: "short",
    hour: "2-digit", minute: "2-digit",
  }).format(new Date(iso))
}

export default function DevicesPage() {
  const [createOpen, setCreateOpen] = React.useState(false)
  const [invitesOpen, setInvitesOpen] = React.useState(false)
  const [showRevoked, setShowRevoked] = React.useState(false)
  const [revokeScreenId, setRevokeScreenId] = React.useState<string | null>(null)
  const [revokePosId, setRevokePosId] = React.useState<string | null>(null)
  const [deletePosId, setDeletePosId] = React.useState<string | null>(null)

  const { data: invitationsData } = useDeviceInvitations()
  const pendingCount = (invitationsData ?? []).filter((i) => i.status === "opened").length

  const { devices, isLoading } = useConnectedDevices({ showRevoked })

  const revokeScreen = useRevokeScreen()
  const revokePosDevice = useRevokePosDevice()
  const deletePosDevice = useDeletePosDevice()

  const columns = React.useMemo<ColumnDef<ConnectedDevice>[]>(() => [
    {
      accessorKey: "kind",
      header: "Tipo",
      cell: ({ row }) => (
        <Badge variant="secondary">{DEVICE_KIND_LABELS[row.original.kind]}</Badge>
      ),
    },
    {
      accessorKey: "name",
      header: "Nombre",
      cell: ({ row }) => (
        <span className="font-medium">{row.original.name}</span>
      ),
    },
    {
      accessorKey: "outletName",
      header: "Sucursal",
      cell: ({ row }) => row.original.outletName ?? "—",
    },
    {
      accessorKey: "registerName",
      header: "Caja",
      cell: ({ row }) => row.original.registerName ?? "—",
    },
    {
      accessorKey: "module",
      header: "Módulo",
      cell: ({ row }) =>
        row.original.module ? (
          <Badge variant="outline">{row.original.module.toUpperCase()}</Badge>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
    {
      accessorKey: "ipLast",
      header: "IP",
      cell: ({ row }) => (
        <span className="font-mono text-xs">{row.original.ipLast ?? "—"}</span>
      ),
    },
    {
      accessorKey: "pairedByName",
      header: "Pareado por",
      cell: ({ row }) => row.original.pairedByName ?? "—",
    },
    {
      accessorKey: "pairedAt",
      header: "Pareado",
      cell: ({ row }) => niceDate(row.original.pairedAt),
    },
    {
      accessorKey: "lastSeenAt",
      header: "Última actividad",
      cell: ({ row }) => niceDate(row.original.lastSeenAt),
    },
    {
      accessorKey: "status",
      header: "Estado",
      cell: ({ row }) =>
        row.original.status === 1 ? (
          <Badge variant="default">Activo</Badge>
        ) : (
          <Badge variant="secondary">Revocado</Badge>
        ),
    },
    {
      id: "actions",
      header: "",
      cell: ({ row }) => {
        const { kind, id, status } = row.original
        if (kind === "screen" && status === 1) {
          return (
            <Button
              variant="ghost"
              size="sm"
              className="text-destructive hover:text-destructive"
              onClick={() => setRevokeScreenId(id)}
            >
              <Trash2 className="size-4 mr-1.5" />
              Revocar
            </Button>
          )
        }
        if (kind === "pos" && status === 1) {
          return (
            <Button
              variant="ghost"
              size="sm"
              className="text-destructive hover:text-destructive"
              onClick={() => setRevokePosId(id)}
            >
              <Trash2 className="size-4 mr-1.5" />
              Revocar
            </Button>
          )
        }
        if (kind === "pos" && status === 0) {
          return (
            <Button
              variant="ghost"
              size="sm"
              className="text-destructive hover:text-destructive"
              onClick={() => setDeletePosId(id)}
            >
              <Trash2 className="size-4 mr-1.5" />
              Eliminar
            </Button>
          )
        }
        // screen revocada — sin acción
        return null
      },
    },
  ], [])

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Dispositivos</h1>
          <p className="text-sm text-muted-foreground">
            Administrá los dispositivos conectados a tu comercio.
          </p>
        </div>
        <Button onClick={() => setCreateOpen(true)}>
          <Plus className="size-4 mr-1.5" />
          Conectar dispositivo
        </Button>
      </header>

      {pendingCount > 0 && (
        <Alert className="border-primary/40 bg-primary/5">
          <Bell className="size-4 animate-pulse text-primary" />
          <AlertTitle className="text-primary">
            {pendingCount === 1
              ? "1 solicitud pendiente de aprobación"
              : `${pendingCount} solicitudes pendientes de aprobación`}
          </AlertTitle>
          <AlertDescription className="flex items-center gap-3 mt-1">
            Un dispositivo abrió el link de conexión y está esperando que lo aprobés.
            <Button size="sm" onClick={() => setInvitesOpen(true)}>
              Ver solicitudes
            </Button>
          </AlertDescription>
        </Alert>
      )}

      <div className="flex items-center justify-end gap-3">
        <Label htmlFor="show-revoked" className="text-sm text-muted-foreground cursor-pointer">
          Mostrar revocados
        </Label>
        <Switch
          id="show-revoked"
          checked={showRevoked}
          onCheckedChange={setShowRevoked}
        />
      </div>

      <DataTable
        tableId="connected-devices"
        columns={columns}
        data={devices}
        isLoading={isLoading}
        searchPlaceholder="Buscar dispositivo..."
        exportFileName={null}
        emptyMessage={
          <EmptyState
            icon={MonitorSmartphone}
            title="Sin dispositivos conectados"
            description="Conectá una caja POS o pantalla cliente desde el botón Conectar dispositivo."
            actions={
              <Button onClick={() => setCreateOpen(true)}>
                <Plus className="size-4 mr-1.5" />
                Conectar dispositivo
              </Button>
            }
          />
        }
      />

      {/* Dialog para solicitudes pendientes */}
      <Dialog open={invitesOpen} onOpenChange={setInvitesOpen}>
        <DialogContent className="sm:max-w-4xl">
          <DialogHeader>
            <DialogTitle>Solicitudes de conexión</DialogTitle>
          </DialogHeader>
          <DeviceInvitesTab />
        </DialogContent>
      </Dialog>

      {/* AlertDialog: revocar pantalla */}
      <AlertDialog open={revokeScreenId !== null} onOpenChange={(o) => { if (!o) setRevokeScreenId(null) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Revocar pantalla</AlertDialogTitle>
            <AlertDialogDescription>
              La pantalla dejará de funcionar inmediatamente. Esta acción no se puede deshacer.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              onClick={() => {
                if (!revokeScreenId) return
                revokeScreen.mutate(revokeScreenId, {
                  onSuccess: () => { toast.success("Pantalla revocada"); setRevokeScreenId(null) },
                  onError: (err) => { toast.error(err.message) },
                })
              }}
            >
              Revocar
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* AlertDialog: revocar dispositivo POS */}
      <AlertDialog open={revokePosId !== null} onOpenChange={(o) => { if (!o) setRevokePosId(null) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Revocar dispositivo POS</AlertDialogTitle>
            <AlertDialogDescription>
              El dispositivo dejará de poder operar la caja inmediatamente. Esta acción no se puede deshacer.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              onClick={() => {
                if (!revokePosId) return
                revokePosDevice.mutate(revokePosId, {
                  onSuccess: () => { toast.success("Dispositivo revocado"); setRevokePosId(null) },
                  onError: (err) => { toast.error(err.message) },
                })
              }}
            >
              Revocar
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* AlertDialog: eliminar dispositivo POS revocado */}
      <AlertDialog open={deletePosId !== null} onOpenChange={(o) => { if (!o) setDeletePosId(null) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Eliminar dispositivo del historial</AlertDialogTitle>
            <AlertDialogDescription>
              Se borrará permanentemente este dispositivo y su historial de auditoría. Esta acción no se puede deshacer.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              onClick={() => {
                if (!deletePosId) return
                deletePosDevice.mutate(deletePosId, {
                  onSuccess: () => { toast.success("Dispositivo eliminado"); setDeletePosId(null) },
                  onError: (err) => { toast.error(err.message) },
                })
              }}
            >
              Eliminar
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <DeviceInviteCreateDialog open={createOpen} onOpenChange={setCreateOpen} />
    </div>
  )
}
