"use client"
import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import { Inbox } from "lucide-react"
import { toast } from "sonner"
import { DataTable } from "@/components/data-table/data-table"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
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
import {
  useDeviceInvitations,
  useDenyDeviceInvitation,
  type DeviceInvitation,
} from "@/hooks/use-device-invitations"
import { DeviceInviteApproveDialog } from "./device-invite-approve-dialog"

function timeUntil(iso: string): string {
  const diff = new Date(iso).getTime() - Date.now()
  if (diff <= 0) return "vencida"
  const h = Math.floor(diff / 1000 / 60 / 60)
  const m = Math.floor((diff / 1000 / 60) % 60)
  if (h > 0) return `en ${h}h ${m}m`
  return `en ${m}m`
}

function StatusBadge({ status }: { status: DeviceInvitation["status"] }) {
  if (status === "opened") return <Badge variant="default">Abierto en dispositivo</Badge>
  if (status === "pending") return <Badge variant="outline">Esperando</Badge>
  return <Badge variant="secondary">{status}</Badge>
}

export function DeviceInvitesTab() {
  const { data, isLoading } = useDeviceInvitations()
  const deny = useDenyDeviceInvitation()
  const [approveTarget, setApproveTarget] = React.useState<DeviceInvitation | null>(null)
  const [denyId, setDenyId] = React.useState<string | null>(null)

  const columns = React.useMemo<ColumnDef<DeviceInvitation>[]>(() => [
    {
      accessorKey: "userCode",
      header: "Código",
      cell: ({ row }) => (
        <span className="font-mono tabular-nums">{row.original.userCode ?? "—"}</span>
      ),
    },
    {
      accessorKey: "module",
      header: "Módulo",
      cell: ({ row }) =>
        row.original.module === "pos" ? (
          <Badge variant="secondary">POS</Badge>
        ) : (
          <span>{row.original.module}</span>
        ),
    },
    {
      accessorKey: "status",
      header: "Estado",
      cell: ({ row }) => <StatusBadge status={row.original.status} />,
    },
    {
      accessorKey: "deviceUa",
      header: "Dispositivo",
      cell: ({ row }) => {
        const ua = row.original.deviceUa
        if (!ua) return "—"
        return ua.length > 60 ? ua.slice(0, 60) + "…" : ua
      },
    },
    {
      accessorKey: "expiresAt",
      header: "Vence en",
      cell: ({ row }) => timeUntil(row.original.expiresAt),
    },
    {
      id: "actions",
      header: "",
      cell: ({ row }) => (
        <div className="flex gap-1">
          {row.original.status === "opened" && (
            <Button size="sm" onClick={() => setApproveTarget(row.original)}>
              Aprobar
            </Button>
          )}
          <Button
            variant="ghost"
            size="sm"
            className="text-destructive hover:text-destructive"
            onClick={() => setDenyId(row.original.id)}
          >
            Rechazar
          </Button>
        </div>
      ),
    },
  ], [])

  const invitations = (data ?? []).filter((i) => i.status === "pending" || i.status === "opened")

  return (
    <>
      {invitations.length === 0 && !isLoading ? (
        <EmptyState
          icon={Inbox}
          title="Sin dispositivos esperando"
          description="Cuando alguien abra un link de conexión aparecerá acá."
          showMarquee={false}
        />
      ) : (
        <DataTable
          tableId="device-invitations"
          columns={columns}
          data={invitations}
          isLoading={isLoading}
          searchPlaceholder="Buscar solicitud..."
          exportFileName={null}
        />
      )}

      <DeviceInviteApproveDialog
        invitation={approveTarget}
        onOpenChange={(v) => { if (!v) setApproveTarget(null) }}
      />

      <AlertDialog open={denyId !== null} onOpenChange={(o) => { if (!o) setDenyId(null) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Rechazar solicitud</AlertDialogTitle>
            <AlertDialogDescription>
              El dispositivo no podrá conectarse con esta invitación. No se puede deshacer.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              onClick={() => {
                if (!denyId) return
                deny.mutate(denyId, {
                  onSuccess: () => { toast.success("Solicitud rechazada"); setDenyId(null) },
                  onError: (err) => { toast.error(err.message); setDenyId(null) },
                })
              }}
            >
              Rechazar
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}
