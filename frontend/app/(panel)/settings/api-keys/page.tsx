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
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group"
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
  useApiKeys,
  useIssueApiKey,
  useRevokeApiKey,
  type ApiKey,
  type ApiKeyScope,
} from "@/hooks/use-api-keys"
import { cn } from "@/lib/utils"

/**
 * Los dos scopes, con el texto que decide el comercio.
 *
 * "Solo lectura" va PRIMERO y es el default: la mayoría de las integraciones no
 * necesitan más, y el que no lee la ayuda se lleva la opción segura. La segunda
 * dice qué habilita en concreto —crear y modificar datos del negocio— sin
 * prometer que puede TODO: la key nunca puede más que el usuario que la emitió,
 * y las ventas quedan afuera por decisión de producto.
 */
const SCOPES: Array<{ value: ApiKeyScope; label: string; desc: string }> = [
  {
    value: "read",
    label: "Solo lectura",
    desc: "Consulta ventas, stock, clientes y reportes. No puede modificar nada.",
  },
  {
    value: "write",
    label: "Lectura y configuración",
    desc: "Además de consultar, permite crear y modificar datos del negocio (productos, clientes, usuarios, sucursales, cajas) a través del catálogo de acciones del asistente, con los permisos del usuario que crea la key.",
  },
]

function niceDate(iso: string | null): string {
  if (!iso) return "—"
  return new Intl.DateTimeFormat("es", {
    day: "numeric",
    month: "short",
    year: "numeric",
  }).format(new Date(iso))
}

export default function ApiKeysPage() {
  const [showRevoked, setShowRevoked] = React.useState(false)
  const [revokeId, setRevokeId] = React.useState<string | null>(null)
  const [createOpen, setCreateOpen] = React.useState(false)
  const [name, setName] = React.useState("")
  const [scope, setScope] = React.useState<ApiKeyScope>("read")
  // El token vive SOLO en este estado, mientras el diálogo está abierto: no hay
  // endpoint que lo relea, y guardarlo en cualquier otro lado sería inventar
  // una segunda copia de una credencial que el backend ya decidió no persistir.
  const [issued, setIssued] = React.useState<{ token: string; name: string } | null>(null)

  const { data: keys = [], isLoading } = useApiKeys({ showRevoked })
  const issueKey = useIssueApiKey()
  const revokeKey = useRevokeApiKey()

  const columns = React.useMemo<ColumnDef<ApiKey>[]>(
    () => [
      {
        accessorKey: "name",
        header: "Nombre",
        cell: ({ row }) => <span className="font-medium">{row.original.name || "—"}</span>,
        meta: { label: "Nombre" },
      },
      {
        accessorKey: "scope",
        header: "Acceso",
        // La de escritura es la excepción y la que hay que poder identificar de
        // un vistazo para revocarla; la de lectura queda en texto tenue porque
        // es el default y un badge por fila no distinguiría nada.
        cell: ({ row }) =>
          row.original.scope === "write" ? (
            <Badge variant="secondary">Configuración</Badge>
          ) : (
            <span className="text-muted-foreground">Lectura</span>
          ),
        meta: { label: "Acceso" },
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
      { name, scope },
      {
        onSuccess: (res) => {
          setCreateOpen(false)
          setName("")
          // El scope vuelve al default para la próxima: elegir "configuración"
          // es una decisión por key, no una preferencia de la pantalla.
          setScope("read")
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
          alcanza exactamente lo mismo que el usuario que la creó, nunca más. Las de
          solo lectura no pueden modificar nada; las de configuración además dejan que
          el asistente cargue y edite datos del negocio.
        </p>
      </header>

      <DataTable
        tableId="api-keys"
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
          <div className="flex flex-col gap-6">
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

            <div className="flex flex-col gap-3">
              <Label>Qué puede hacer</Label>
              <RadioGroup value={scope} onValueChange={(v) => setScope(v as ApiKeyScope)}>
                {SCOPES.map((s) => (
                  <Label
                    key={s.value}
                    htmlFor={`api-key-scope-${s.value}`}
                    className={cn(
                      "flex cursor-pointer items-start gap-3 rounded-md border p-3 font-normal",
                      scope === s.value ? "border-foreground/30 bg-accent/50" : "border-border",
                    )}
                  >
                    <RadioGroupItem
                      value={s.value}
                      id={`api-key-scope-${s.value}`}
                      className="mt-0.5"
                    />
                    <div className="flex flex-col gap-0.5">
                      <span className="text-sm font-medium">{s.label}</span>
                      <span className="text-xs text-muted-foreground">{s.desc}</span>
                    </div>
                  </Label>
                ))}
              </RadioGroup>
              <p className="text-xs text-muted-foreground">
                El acceso se define ahora y no se cambia después: para pasar de una a
                otra se emite una key nueva y se revoca la anterior.
              </p>
            </div>
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
