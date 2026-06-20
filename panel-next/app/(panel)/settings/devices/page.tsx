"use client"
import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import { Trash2 } from "lucide-react"
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
import { useScreens, useRevokeScreen, type Screen } from "@/hooks/use-screens"

export default function DevicesPage() {
  const { data, isLoading } = useScreens()
  const revoke = useRevokeScreen()
  const [revokeId, setRevokeId] = React.useState<string | null>(null)

  function niceDate(iso: string | null) {
    if (!iso) return "—"
    return new Intl.DateTimeFormat("es", {
      day: "numeric", month: "short",
      hour: "2-digit", minute: "2-digit",
    }).format(new Date(iso))
  }

  const columns = React.useMemo<ColumnDef<Screen>[]>(() => [
    {
      accessorKey: "name",
      header: "Nombre",
      cell: ({ row }) => (
        <span className="font-medium">{row.original.name}</span>
      ),
    },
    {
      accessorKey: "registerName",
      header: "Caja",
      cell: ({ row }) => row.original.registerName ?? "—",
    },
    {
      accessorKey: "ipLast",
      header: "IP",
      cell: ({ row }) => (
        <span className="font-mono text-sm">{row.original.ipLast ?? "—"}</span>
      ),
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
          <Badge variant="default">Activa</Badge>
        ) : (
          <Badge variant="secondary">Revocada</Badge>
        ),
    },
    {
      id: "actions",
      header: "",
      cell: ({ row }) =>
        row.original.status === 1 ? (
          <Button
            variant="ghost"
            size="sm"
            className="text-destructive hover:text-destructive"
            onClick={() => setRevokeId(row.original.id)}
          >
            <Trash2 className="size-4 mr-1.5" />
            Revocar
          </Button>
        ) : null,
    },
  ], [])

  const screens = data?.screens ?? []

  return (
    <div className="space-y-6 p-6">
      <div>
        <h1 className="text-2xl font-bold">Dispositivos</h1>
        <p className="text-muted-foreground text-sm mt-1">
          Pantallas cliente y dispositivos conectados al POS.
        </p>
      </div>

      <DataTable
        tableId="screens"
        columns={columns}
        data={screens}
        isLoading={isLoading}
        searchPlaceholder="Buscar pantalla..."
        exportFileName={null}
      />

      <AlertDialog open={revokeId !== null} onOpenChange={(o) => { if (!o) setRevokeId(null) }}>
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
                if (!revokeId) return
                revoke.mutate(revokeId, {
                  onSuccess: () => { toast.success("Pantalla revocada"); setRevokeId(null) },
                  onError: (err) => { toast.error(err.message) },
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
