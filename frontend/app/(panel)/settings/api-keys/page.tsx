"use client"
import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import { Copy, KeyRound, Plus, Trash2 } from "lucide-react"
import { toast } from "sonner"
import { DataTable } from "@/components/data-table/data-table"
import { RowActions } from "@/components/data-table/row-actions"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Switch } from "@/components/ui/switch"
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
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { EmptyState } from "@/components/empty-state"
import {
  useMcpKeys,
  useIssueMcpKey,
  useRevokeMcpKey,
  type McpKey,
} from "@/hooks/use-mcp-keys"

function niceDate(iso: string | null): string {
  if (!iso) return "—"
  return new Intl.DateTimeFormat("es", {
    day: "numeric",
    month: "short",
    year: "numeric",
  }).format(new Date(iso))
}

export default function McpKeysPage() {
  const [showRevoked, setShowRevoked] = React.useState(false)
  const [revokeId, setRevokeId] = React.useState<string | null>(null)
  const [createOpen, setCreateOpen] = React.useState(false)
  const [name, setName] = React.useState("")
  // El token vive SOLO en este estado, mientras el diálogo está abierto: no hay
  // endpoint que lo relea, y guardarlo en cualquier otro lado sería inventar
  // una segunda copia de una credencial que el backend ya decidió no persistir.
  const [issued, setIssued] = React.useState<{ token: string; name: string } | null>(null)

  const { data: keys = [], isLoading } = useMcpKeys({ showRevoked })
  const issueKey = useIssueMcpKey()
  const revokeKey = useRevokeMcpKey()

  const columns = React.useMemo<ColumnDef<McpKey>[]>(
    () => [
      {
        accessorKey: "name",
        header: "Nombre",
        cell: ({ row }) => <span className="font-medium">{row.original.name || "—"}</span>,
        meta: { label: "Nombre" },
      },
      {
        accessorKey: "createdAt",
        header: "Creada",
        cell: ({ row }) => niceDate(row.original.createdAt),
        meta: { label: "Creada" },
      },
      {
        accessorKey: "lastSeenAt",
        header: "Último uso",
        cell: ({ row }) =>
          row.original.lastSeenAt ? (
            niceDate(row.original.lastSeenAt)
          ) : (
            <span className="text-muted-foreground">Nunca</span>
          ),
        meta: { label: "Último uso" },
      },
      {
        accessorKey: "expiresAt",
        header: "Vence",
        cell: ({ row }) => niceDate(row.original.expiresAt),
        meta: { label: "Vence" },
      },
      {
        accessorKey: "revoked",
        header: "Estado",
        cell: ({ row }) => {
          // "Vencida" antes que "Activa": una key vencida sigue con status=1 en
          // la tabla —nada la revoca al expirar, `authResolve` simplemente la
          // rechaza— y mostrarla como activa haría que el comercio buscara el
          // problema en otro lado.
          if (row.original.revoked) return <Badge variant="secondary">Revocada</Badge>
          if (row.original.expired) return <Badge variant="destructive">Vencida</Badge>
          return <Badge variant="default">Activa</Badge>
        },
        meta: { label: "Estado" },
      },
      {
        id: "actions",
        header: "",
        cell: ({ row }) => (
          <RowActions
            actions={[
              {
                label: "Revocar",
                icon: Trash2,
                variant: "destructive",
                onSelect: () => setRevokeId(row.original.id),
                hidden: row.original.revoked,
              },
            ]}
          />
        ),
      },
    ],
    [],
  )

  function handleIssue() {
    issueKey.mutate(
      { name },
      {
        onSuccess: (res) => {
          setCreateOpen(false)
          setName("")
          setIssued({ token: res.token, name: res.name })
        },
        onError: (err) => toast.error(err.message),
      },
    )
  }

  async function copyToken() {
    if (!issued) return
    try {
      await navigator.clipboard.writeText(issued.token)
      toast.success("Key copiada")
    } catch {
      toast.error("No se pudo copiar — seleccionala y copiala a mano")
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold">Keys de integración</h1>
        <p className="text-sm text-muted-foreground">
          Conectá Claude u otra herramienta de IA a los datos de tu comercio. Cada key
          lee exactamente lo mismo que puede ver el usuario que la creó, y nunca puede
          escribir.
        </p>
      </header>

      <DataTable
        tableId="mcp-keys"
        columns={columns}
        data={keys}
        isLoading={isLoading}
        searchPlaceholder="Buscar key..."
        exportFileName={null}
        rightToolbarSlot={
          <div className="flex items-center gap-4">
            <div className="flex items-center gap-2">
              <Label
                htmlFor="show-revoked-keys"
                className="cursor-pointer text-sm text-muted-foreground"
              >
                Mostrar revocadas
              </Label>
              <Switch
                id="show-revoked-keys"
                checked={showRevoked}
                onCheckedChange={setShowRevoked}
              />
            </div>
            <Button size="sm" onClick={() => setCreateOpen(true)}>
              <Plus className="size-4" />
              Nueva key
            </Button>
          </div>
        }
        emptyMessage={
          <EmptyState
            icon={KeyRound}
            title="Sin keys de integración"
            description="Creá una key para conectar Claude u otra herramienta a los datos de tu comercio."
          />
        }
      />

      {/* Crear */}
      <Dialog open={createOpen} onOpenChange={setCreateOpen}>
        <DialogContent className="sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>Nueva key de integración</DialogTitle>
            <DialogDescription>
              El nombre es lo único que la distingue después, cuando tengas varias y
              necesites revocar una. Poné dónde la vas a usar.
            </DialogDescription>
          </DialogHeader>
          <div className="flex flex-col gap-3">
            <Label htmlFor="mcp-key-name">Nombre</Label>
            <Input
              id="mcp-key-name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Claude Desktop de Ana"
              maxLength={60}
              autoFocus
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setCreateOpen(false)}>
              Cancelar
            </Button>
            <Button onClick={handleIssue} disabled={name.trim() === "" || issueKey.isPending}>
              {issueKey.isPending ? "Creando..." : "Crear key"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Token recién emitido — se muestra UNA sola vez */}
      <Dialog open={issued !== null} onOpenChange={(o) => !o && setIssued(null)}>
        <DialogContent className="sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>Key creada</DialogTitle>
            <DialogDescription>
              Copiala ahora: no la vas a poder volver a ver. Guardamos solo una huella
              para validarla, no la key en sí. Si la perdés, revocá esta y creá otra.
            </DialogDescription>
          </DialogHeader>
          <div className="flex items-center gap-2">
            <Input readOnly value={issued?.token ?? ""} className="font-mono" />
            <Button variant="outline" size="icon" onClick={copyToken} aria-label="Copiar key">
              <Copy className="size-4" />
            </Button>
          </div>
          <DialogFooter>
            <Button onClick={() => setIssued(null)}>Ya la guardé</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Revocar */}
      <AlertDialog open={revokeId !== null} onOpenChange={(o) => !o && setRevokeId(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Revocar key</AlertDialogTitle>
            <AlertDialogDescription>
              La herramienta que la esté usando pierde el acceso de inmediato. No se
              puede deshacer: si la necesitás de vuelta, hay que crear una nueva.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              onClick={() => {
                if (!revokeId) return
                revokeKey.mutate(revokeId, {
                  onSuccess: () => {
                    toast.success("Key revocada")
                    setRevokeId(null)
                  },
                  onError: (err) => toast.error(err.message),
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
