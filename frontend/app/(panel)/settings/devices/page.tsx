"use client"
import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import { Plus, Trash2, Bell, MonitorSmartphone, RefreshCw } from "lucide-react"
import { toast } from "sonner"
import { DataTable } from "@/components/data-table/data-table"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Switch } from "@/components/ui/switch"
import { Label } from "@/components/ui/label"
import { Input } from "@/components/ui/input"
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
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
import { useRevokePosDevice, useDeletePosDevice } from "@/hooks/use-pos-devices"
import { useDeviceInvitations } from "@/hooks/use-device-invitations"
import { useConnectedDevices } from "@/hooks/use-connected-devices"
import { DeviceInvitesTab } from "@/components/settings/device-invites-tab"
import { DeviceInviteCreateDialog } from "@/components/settings/device-invite-create-dialog"
import { DEVICE_KIND_LABELS, type ConnectedDevice } from "@/lib/devices/connected-device"
import { EmptyState } from "@/components/empty-state"
import { api } from "@/lib/api-client"

interface ReconnectResult {
  id: string
  url: string
  expiresAt: string
  autoApprove: boolean
}

interface ReconnectDialogState {
  open: boolean
  deviceName: string
  url: string
}

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
  // Un solo state de revoke para POS y screen — ambos viven en la misma
  // tabla `device` y se revocan via DeviceAuth::revoke, así que invalidar
  // un solo queryKey ["pos-devices"] funciona para los dos. Antes había
  // useRevokeScreen separado que invalidaba ["screens"] — esa key ya no se
  // usa para listar (useConnectedDevices lee solo de /v1/devices) → el row
  // revocado de tipo screen no desaparecía de la tabla. Incidente 2026-06-28.
  const [revokeId, setRevokeId] = React.useState<string | null>(null)
  const [deletePosId, setDeletePosId] = React.useState<string | null>(null)
  const [reconnectingId, setReconnectingId] = React.useState<string | null>(null)
  const [reconnectDialog, setReconnectDialog] = React.useState<ReconnectDialogState>({
    open: false,
    deviceName: "",
    url: "",
  })

  const { data: invitationsData } = useDeviceInvitations()
  const pendingCount = (invitationsData ?? []).filter((i) => i.status === "opened").length

  const { devices, isLoading } = useConnectedDevices({ showRevoked })

  const revokeDevice = useRevokePosDevice()
  const deletePosDevice = useDeletePosDevice()

  async function handleReconnect(device: ConnectedDevice) {
    setReconnectingId(device.id)
    try {
      const form = new FormData()
      form.append("action", "reconnect")
      form.append("deviceId", device.id)
      const result = await api.postForm<ReconnectResult>("/v1/device_invitations", form)
      setReconnectDialog({ open: true, deviceName: device.name, url: result.url })
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "No se pudo generar el link de reconexión")
    } finally {
      setReconnectingId(null)
    }
  }

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
        if (status === 1) {
          return (
            <div className="flex items-center gap-1">
              <Button
                variant="ghost"
                size="sm"
                disabled={reconnectingId === id}
                onClick={() => handleReconnect(row.original)}
              >
                <RefreshCw className={`size-4 mr-1.5${reconnectingId === id ? " animate-spin" : ""}`} />
                Reconectar
              </Button>
              <Button
                variant="ghost"
                size="sm"
                className="text-destructive hover:text-destructive"
                onClick={() => setRevokeId(id)}
              >
                <Trash2 className="size-4 mr-1.5" />
                Revocar
              </Button>
            </div>
          )
        }
        // Solo POS revocado tiene hard-delete (historial); screen revocado
        // queda en la lista cuando "Mostrar revocados" está activado.
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
  // eslint-disable-next-line react-hooks/exhaustive-deps
  ], [reconnectingId])

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

      <DataTable
        tableId="connected-devices"
        columns={columns}
        data={devices}
        isLoading={isLoading}
        searchPlaceholder="Buscar dispositivo..."
        exportFileName={null}
        rightToolbarSlot={
          <div className="flex items-center gap-2">
            <Label htmlFor="show-revoked" className="text-sm text-muted-foreground cursor-pointer">
              Mostrar revocados
            </Label>
            <Switch
              id="show-revoked"
              checked={showRevoked}
              onCheckedChange={setShowRevoked}
            />
          </div>
        }
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

      {/* AlertDialog: revocar dispositivo (genérico para POS y screen). */}
      <AlertDialog open={revokeId !== null} onOpenChange={(o) => { if (!o) setRevokeId(null) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Revocar dispositivo</AlertDialogTitle>
            <AlertDialogDescription>
              El dispositivo dejará de funcionar inmediatamente. Esta acción no se puede deshacer.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              onClick={() => {
                if (!revokeId) return
                revokeDevice.mutate(revokeId, {
                  onSuccess: () => { toast.success("Dispositivo revocado"); setRevokeId(null) },
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

      {/* Dialog: reconectar dispositivo */}
      <Dialog
        open={reconnectDialog.open}
        onOpenChange={(o) => setReconnectDialog((prev) => ({ ...prev, open: o }))}
      >
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Reconectar {reconnectDialog.deviceName}</DialogTitle>
            <DialogDescription>
              El link expira en 10 minutos. Abrilo en el dispositivo para reconectarlo.
            </DialogDescription>
          </DialogHeader>
          <div className="flex items-center gap-2">
            <Input
              readOnly
              value={reconnectDialog.url}
              className="font-mono text-xs"
            />
            <Button
              variant="outline"
              size="sm"
              onClick={() => {
                navigator.clipboard.writeText(reconnectDialog.url)
                toast.success("Link copiado")
              }}
            >
              Copiar
            </Button>
          </div>
          {reconnectDialog.url && (
            <img
              src={`https://api.qrserver.com/v1/create-qr-code/?data=${encodeURIComponent(reconnectDialog.url)}&size=200x200`}
              alt="QR code"
              className="mx-auto mt-4"
            />
          )}
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => setReconnectDialog((prev) => ({ ...prev, open: false }))}
            >
              Cerrar
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
