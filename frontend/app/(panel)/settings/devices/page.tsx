"use client"
import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import { Plus, Trash2, Bell, MonitorSmartphone, RefreshCw, ExternalLink, Copy } from "lucide-react"
import { toast } from "sonner"
import { DataTable } from "@/components/data-table/data-table"
import { RowActions } from "@/components/data-table/row-actions"
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
import {
  DEVICE_KIND_LABELS,
  DEVICE_KIND_ROUTES,
  deviceHistoryReason,
  type ConnectedDevice,
} from "@/lib/devices/connected-device"
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
  const revokeDeviceTarget = React.useMemo(
    () => devices.find((d) => d.id === revokeId) ?? null,
    [devices, revokeId],
  )

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
      // Solo la caja ASIGNADA, que es un campo del propio dispositivo (a qué
      // caja está pareado este POS). Los tipos sin caja —screen/kds/display/
      // print— muestran "—".
      //
      // Sin badges de TENENCIA acá: ese dato vive completo en Ajustes →
      // Sucursales → Cajas, con la marca de tenencia huérfana (el aparato
      // tenedor ya fue reasignado a otra caja) y la acción "Liberar caja".
      // Esta columna lo repetía sin el chequeo de huérfana, así que afirmaba
      // "en uso por <dispositivo>" sobre tenencias que ya no correspondían a
      // esa caja. Un dato en dos pantallas, correcto en una sola, es peor que
      // en una.
      cell: ({ row }) => row.original.registerName ?? "—",
    },
    // Sin columna "Módulo": era el MISMO dato que "Tipo" — `kind` se deriva
    // de `module` (`moduleToKind` en hooks/use-connected-devices.ts), así que
    // mostraba el valor crudo al lado de su propia etiqueta legible.
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
      // El contador de sesiones se queda, pero cambió de significado: dejó
      // de ser información de color ("este aparato tiene 4 sesiones") para
      // ser una ANOMALÍA. Desde el fix de `DeviceAuth::buildToken()`
      // (2026-09-01) cada emisión revoca la anterior, así que un dispositivo
      // tiene exactamente 1 sesión activa. Mostrarlo como dato neutro
      // normalizaba justo el síntoma que tapaba el bug de las cajas
      // bloqueadas por pestañas fantasma.
      //
      // Por qué no se borra: el invariante lo sostiene el código, no un
      // constraint de BD — no hay índice único sobre (deviceid) parcial por
      // status=1 porque las sesiones revocadas se conservan para auditoría.
      // Si algún camino futuro vuelve a emitir sin revocar, esta es la única
      // pantalla donde se ve.
      cell: ({ row }) => {
        const { status, activeSessions } = row.original
        if (status !== 1) return <Badge variant="secondary">Revocado</Badge>
        return (
          <div className="flex items-center gap-1.5">
            <Badge variant="default">Activo</Badge>
            {activeSessions > 1 && (
              <Badge
                variant="destructive"
                title="Un dispositivo debería tener una sola sesión activa. Revocalo y volvé a conectarlo para dejar una sola."
              >
                {activeSessions} sesiones
              </Badge>
            )}
          </div>
        )
      },
    },
    {
      id: "actions",
      header: "",
      cell: ({ row }) => {
        const { kind, id, status, historyKinds } = row.original
        const isActive = status === 1
        // Un dispositivo con rastro operativo NO se borra: `register_lease` es
        // la cadena de qué aparato tenía qué caja al emitir cada comprobante
        // (FK dura, sin ON DELETE a propósito), y `auth_session` /
        // `pos_order_event` / `station_printer` quedarían apuntando a un
        // aparato inexistente. El backend lo rechaza con 409; acá se
        // deshabilita la acción con el motivo a la vista, porque ofrecer un
        // botón que siempre falla es peor que no ofrecerlo. Ver
        // `api/lib/services/DeviceHistoryService.php`.
        const historyReason = deviceHistoryReason(historyKinds)
        // Hard-delete: cualquier dispositivo YA revocado, sin importar el tipo.
        // El backend (DELETE /v1/devices?hard=1) solo exige status=0 — la
        // barrera de seguridad es "revocar primero", no el módulo. El gate
        // `kind === "pos"` que había acá era herencia de cuando las pantallas
        // se listaban desde otra fuente: dejaba screen/kds/display/print
        // revocados atascados en el historial, sin ninguna acción posible.
        const isDeletable = status === 0
        return (
          <RowActions
            actions={[
              {
                label: "Abrir",
                icon: ExternalLink,
                // Abre la pantalla del dispositivo. El pareo vive en el
                // localStorage de SU browser, así que este link recupera la
                // sesión al abrirlo ahí — sirve para volver a entrar cuando
                // alguien cierra la pestaña del KDS o del despacho, que antes
                // no dejaba ninguna pista de cuál era la URL.
                href: DEVICE_KIND_ROUTES[kind],
                target: "_blank",
                hidden: !isActive,
              },
              {
                label: "Copiar link",
                icon: Copy,
                onSelect: () => {
                  navigator.clipboard.writeText(
                    `${window.location.origin}${DEVICE_KIND_ROUTES[kind]}`,
                  )
                  toast.success("Link copiado")
                },
                hidden: !isActive,
              },
              {
                label: "Reconectar",
                icon: RefreshCw,
                onSelect: () => handleReconnect(row.original),
                disabled: reconnectingId === id,
                hidden: !isActive,
              },
              {
                label: "Revocar",
                icon: Trash2,
                variant: "destructive",
                onSelect: () => setRevokeId(id),
                hidden: !isActive,
              },
              {
                label: "Eliminar",
                icon: Trash2,
                variant: "destructive",
                onSelect: () => setDeletePosId(id),
                hidden: !isDeletable,
                // Deshabilitada, no oculta: el admin tiene que entender que
                // ese aparato se conserva A PROPÓSITO. Escondiendo la acción,
                // la ausencia parecería un bug de la pantalla.
                disabled: historyReason !== null,
                reason: historyReason ?? undefined,
              },
            ]}
          />
        )
      },
    },
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
            <AlertDialogTitle>
              Revocar {revokeDeviceTarget ? `"${revokeDeviceTarget.name}"` : "dispositivo"}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {revokeDeviceTarget
                ? `${DEVICE_KIND_LABELS[revokeDeviceTarget.kind]} dejará de funcionar inmediatamente.`
                : "El dispositivo dejará de funcionar inmediatamente."}
              {" "}Esta acción no se puede deshacer.
              {/* La caja que se nombra es la que el aparato tiene TOMADA
                  (`heldRegisterName`), no la asignada: el revoke llama a
                  `releaseByDevice()`, que libera la tenencia del dispositivo
                  sea sobre la caja que sea. Nombrar la asignada mentía en el
                  caso del aparato reasignado que quedó reteniendo la vieja. */}
              {revokeDeviceTarget?.holdsRegister && revokeDeviceTarget.heldRegisterName && (
                <>
                  {" "}Tiene tomada la caja{" "}
                  <strong>{revokeDeviceTarget.heldRegisterName}</strong>: al revocarlo queda libre
                  para otro dispositivo.
                </>
              )}
              {revokeDeviceTarget && revokeDeviceTarget.activeSessions > 1 && (
                <>
                  {" "}Figura con <strong>{revokeDeviceTarget.activeSessions} sesiones activas</strong>,
                  cuando debería tener una sola — se cortan todas a la vez.
                </>
              )}
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

      {/* AlertDialog: eliminar del historial un dispositivo ya revocado (cualquier tipo) */}
      <AlertDialog open={deletePosId !== null} onOpenChange={(o) => { if (!o) setDeletePosId(null) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Eliminar dispositivo del historial</AlertDialogTitle>
            <AlertDialogDescription>
              Se borrará permanentemente el registro de este dispositivo. Esta acción no se
              puede deshacer. Solo llegan acá los aparatos sin historial operativo: los que
              tomaron una caja, abrieron sesión, movieron órdenes o registraron impresoras
              se conservan para poder auditarlos.
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
