"use client"

/**
 * /admin/conocimiento — base de conocimiento de Punto AI (context/82, R1).
 *
 * Lo que el owner carga acá es lo ÚNICO que el bot sabe sobre cómo se usa
 * Punto: nada se indexa solo (D3). No se publica, pero se lo puede leer
 * cualquier comercio a través del asistente — de ahí el aviso de la cabecera.
 *
 * La búsqueda y la tool del agente son R2 y no viven acá.
 *
 * Copy: sin tecnicismos (context/14 §8). En pantalla hay documentos,
 * fragmentos y última actualización; nunca vectores, modelos ni códigos de
 * error del proveedor — eso va al log del backend.
 */

import * as React from "react"
import { BookOpen, Plus, RefreshCw, AlertTriangle, Loader2, Pencil, Eye, EyeOff, Trash2 } from "lucide-react"
import { toast } from "sonner"
import type { ColumnDef } from "@tanstack/react-table"

import { DataTable } from "@/components/data-table/data-table"
import { RowActions } from "@/components/data-table/row-actions"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { Switch } from "@/components/ui/switch"
import { Card, CardContent } from "@/components/ui/card"
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs"
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
import { EmptyState } from "@/components/empty-state"
import { AdminRoleGate } from "@/components/admin/admin-role-gate"

import {
  useAdminHelpKb,
  useAdminHelpDocument,
  useAdminUpsertHelpDocument,
  useAdminToggleHelpDocument,
  useAdminDeleteHelpDocument,
  useAdminReindexHelpDocument,
  useAdminReindexAllHelpDocuments,
  type AdminHelpDocument,
} from "@/hooks/use-admin"
import { formatPuntoSaasDateTime, formatPuntoSaasNumber } from "@/lib/punto-saas-locale"

function niceDateTime(v: string | null): string {
  if (!v) return "—"
  return formatPuntoSaasDateTime(v, { dateStyle: "medium", timeStyle: "short" })
}

// ── Lectura de un .md ────────────────────────────────────────────────────────

/**
 * Saca título y slug del frontmatter, si lo trae. Es SOLO para prellenar los
 * campos: el contenido del archivo viaja entero al backend, frontmatter
 * incluido — quien decide qué se indexa es el fragmentador del servidor, no
 * esta función.
 */
function readFrontmatter(raw: string): { title?: string; slug?: string } {
  const match = raw.match(/^---\r?\n([\s\S]*?)\r?\n---/)
  if (!match) return {}
  const out: { title?: string; slug?: string } = {}
  for (const line of match[1].split(/\r?\n/)) {
    const kv = line.match(/^\s*(title|slug)\s*:\s*(.+?)\s*$/i)
    if (!kv) continue
    const value = kv[2].replace(/^["']|["']$/g, "").trim()
    if (!value) continue
    if (kv[1].toLowerCase() === "title") out.title = value
    else out.slug = value
  }
  return out
}

/** Título de reserva cuando el archivo no trae frontmatter: su propio nombre. */
function titleFromFileName(name: string): string {
  return name
    .replace(/\.mdx?$/i, "")
    .replace(/^\d+[-_]/, "")
    .replace(/[-_]+/g, " ")
    .trim()
}

function parseRubros(raw: string): string[] {
  return raw
    .split(",")
    .map((r) => r.trim())
    .filter(Boolean)
}

interface PendingFile {
  fileName: string
  title: string
  slug?: string
  body: string
}

// ── Diálogo de carga / edición ───────────────────────────────────────────────

function DocumentDialog({
  open,
  onOpenChange,
  editing,
}: {
  open: boolean
  onOpenChange: (v: boolean) => void
  editing: AdminHelpDocument | null
}) {
  const upsert = useAdminUpsertHelpDocument()
  const detail = useAdminHelpDocument(editing ? editing.slug : null)

  // El diálogo se MONTA al abrirse y se desmonta al cerrarse (el padre lo
  // renderiza condicionalmente con `key`), así que el estado inicial sale
  // directo de las props. Sin efectos que copien props a estado: un efecto de
  // sincronización pisaría lo que el owner ya escribió cada vez que la query
  // de fondo se revalide.
  const [title, setTitle] = React.useState(editing?.title ?? "")
  const [slug, setSlug] = React.useState(editing?.slug ?? "")
  const [rubros, setRubros] = React.useState((editing?.rubros ?? []).join(", "))
  const [isActive, setIsActive] = React.useState(editing?.isActive ?? true)
  const [files, setFiles] = React.useState<PendingFile[]>([])
  const [reading, setReading] = React.useState(false)

  // El cuerpo llega en una segunda consulta (la lista no lo trae: son
  // documentos largos). En vez de copiarlo a estado cuando aparece, el campo
  // es derivado: `null` = el owner todavía no lo tocó, así que se muestra lo
  // que trajo el servidor; desde la primera tecla manda su borrador.
  const [bodyDraft, setBodyDraft] = React.useState<string | null>(null)
  const body = bodyDraft ?? detail.data?.document?.body ?? ""
  const setBody = setBodyDraft

  const handleFiles = async (list: FileList | null) => {
    if (!list || list.length === 0) return
    setReading(true)
    try {
      const parsed: PendingFile[] = []
      for (const file of Array.from(list)) {
        const raw = await file.text()
        const front = readFrontmatter(raw)
        parsed.push({
          fileName: file.name,
          title: front.title ?? titleFromFileName(file.name),
          slug: front.slug,
          body: raw,
        })
      }
      setFiles(parsed)
      // Un solo archivo se comporta como una carga normal: sus campos quedan
      // editables antes de guardar.
      if (parsed.length === 1) {
        setTitle(parsed[0].title)
        setSlug(parsed[0].slug ?? "")
        setBody(parsed[0].body)
      }
    } finally {
      setReading(false)
    }
  }

  const saveOne = (input: { title: string; slug?: string; body: string }) =>
    upsert.mutateAsync({
      title: input.title,
      slug: input.slug,
      body: input.body,
      rubros: parseRubros(rubros),
      isactive: isActive,
    })

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()

    // Varios archivos: cada uno es un documento con su propio título; los
    // rubros y el estado se aplican a todos.
    if (files.length > 1) {
      let ok = 0
      const failed: string[] = []
      for (const file of files) {
        try {
          await saveOne({ title: file.title, slug: file.slug, body: file.body })
          ok++
        } catch {
          failed.push(file.fileName)
        }
      }
      if (ok > 0) toast.success(`${ok} documento${ok === 1 ? "" : "s"} cargado${ok === 1 ? "" : "s"}`)
      if (failed.length) toast.error(`No pude cargar: ${failed.join(", ")}`)
      onOpenChange(false)
      return
    }

    if (!title.trim()) {
      toast.error("Poné un título")
      return
    }
    if (!body.trim()) {
      toast.error("El documento está vacío")
      return
    }

    try {
      const res = await saveOne({ title, slug: slug.trim() || undefined, body })
      if (res.index?.ok) {
        toast.success(
          `Documento guardado — ${formatPuntoSaasNumber(res.index.chunks ?? 0)} fragmento${res.index.chunks === 1 ? "" : "s"}`,
        )
      } else {
        toast.warning("Documento guardado, pero no se pudo procesar. Probá reprocesarlo.")
      }
      onOpenChange(false)
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "No pude guardar el documento")
    }
  }

  const multiple = files.length > 1

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-4xl">
        <DialogHeader>
          <DialogTitle>{editing ? "Editar documento" : "Cargar documento"}</DialogTitle>
          <DialogDescription>Lo que cargues acá es lo que Punto AI va a saber responder.</DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="flex flex-col gap-4">
          {!editing && (
            <Tabs defaultValue="archivo">
              <TabsList>
                <TabsTrigger value="archivo">Desde archivo</TabsTrigger>
                <TabsTrigger value="texto">Pegar texto</TabsTrigger>
              </TabsList>

              <TabsContent value="archivo" className="pt-4">
                <div className="flex flex-col gap-3">
                  <Label htmlFor="help-files">Archivos</Label>
                  <Input
                    id="help-files"
                    type="file"
                    accept=".md,.mdx,.txt"
                    multiple
                    onChange={(e) => void handleFiles(e.target.files)}
                  />
                  {reading && (
                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                      <Loader2 className="size-4 animate-spin" /> Leyendo archivos…
                    </p>
                  )}
                  {multiple && (
                    <div className="flex flex-col gap-2 rounded-md border p-4">
                      {files.map((f) => (
                        <div key={f.fileName} className="flex items-center justify-between gap-3">
                          <span className="truncate text-sm">{f.title}</span>
                          <span className="shrink-0 text-sm text-muted-foreground">{f.fileName}</span>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              </TabsContent>

              <TabsContent value="texto" className="pt-4">
                <div className="flex flex-col gap-3">
                  <Label htmlFor="help-body-paste">Contenido</Label>
                  <Textarea
                    id="help-body-paste"
                    value={body}
                    onChange={(e) => setBody(e.target.value)}
                    rows={12}
                    className="font-mono"
                  />
                </div>
              </TabsContent>
            </Tabs>
          )}

          {editing && (
            <div className="flex flex-col gap-3">
              <Label htmlFor="help-body">Contenido</Label>
              <Textarea
                id="help-body"
                value={body}
                onChange={(e) => setBody(e.target.value)}
                rows={14}
                className="font-mono"
                disabled={detail.isLoading}
              />
            </div>
          )}

          {!multiple && (
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="flex flex-col gap-3">
                <Label htmlFor="help-title">Título</Label>
                <Input id="help-title" value={title} onChange={(e) => setTitle(e.target.value)} />
              </div>
              <div className="flex flex-col gap-3">
                <Label htmlFor="help-slug">Identificador</Label>
                <Input
                  id="help-slug"
                  value={slug}
                  onChange={(e) => setSlug(e.target.value)}
                  disabled={!!editing}
                  placeholder={editing ? undefined : "se arma con el título"}
                />
              </div>
            </div>
          )}

          <div className="flex flex-col gap-3">
            <Label htmlFor="help-rubros">Rubros</Label>
            <Input
              id="help-rubros"
              value={rubros}
              onChange={(e) => setRubros(e.target.value)}
              placeholder="panadería, veterinaria"
            />
          </div>

          <div className="flex items-center gap-3">
            <Switch id="help-active" checked={isActive} onCheckedChange={setIsActive} />
            <Label htmlFor="help-active">Activo</Label>
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancelar
            </Button>
            <Button type="submit" disabled={upsert.isPending || reading}>
              {upsert.isPending && <Loader2 className="size-4 animate-spin" />}
              {multiple ? `Cargar ${files.length} documentos` : "Guardar"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

// ── Pantalla ─────────────────────────────────────────────────────────────────

function HelpKbPageContent() {
  const { data, isLoading, error } = useAdminHelpKb()
  const toggle = useAdminToggleHelpDocument()
  const remove = useAdminDeleteHelpDocument()
  const reindex = useAdminReindexHelpDocument()
  const reindexAll = useAdminReindexAllHelpDocuments()

  const [dialogOpen, setDialogOpen] = React.useState(false)
  const [editing, setEditing] = React.useState<AdminHelpDocument | null>(null)
  const [pendingDelete, setPendingDelete] = React.useState<AdminHelpDocument | null>(null)

  const documents = data?.documents ?? []
  const status = data?.status
  // La base del índice todavía no existe (se crea aparte, en Coolify). No es
  // un error: la pantalla lo dice y deshabilita la carga, en vez de dejar al
  // owner escribir un documento que después no se va a poder guardar.
  const notConfigured = !!status && !status.configured

  const openNew = () => {
    setEditing(null)
    setDialogOpen(true)
  }

  const openEdit = (doc: AdminHelpDocument) => {
    setEditing(doc)
    setDialogOpen(true)
  }

  const handleReindex = (doc: AdminHelpDocument) => {
    reindex.mutate(doc.slug, {
      onSuccess: (res) =>
        res.index?.ok
          ? toast.success(`Listo — ${formatPuntoSaasNumber(res.index.chunks ?? 0)} fragmentos`)
          : toast.error("No se pudo procesar. Probá de nuevo en unos minutos."),
      onError: (err) => toast.error(err.message ?? "No se pudo procesar"),
    })
  }

  const columns: ColumnDef<AdminHelpDocument, unknown>[] = [
    {
      accessorKey: "title",
      header: "Documento",
      cell: ({ row }) => (
        <div className="flex flex-col gap-0.5">
          <span className="font-medium">{row.original.title}</span>
          <span className="text-sm text-muted-foreground">{row.original.slug}</span>
        </div>
      ),
    },
    {
      accessorKey: "rubros",
      header: "Rubros",
      cell: ({ row }) =>
        row.original.rubros.length === 0 ? (
          <span className="text-sm text-muted-foreground">Todos</span>
        ) : (
          <div className="flex flex-wrap gap-2">
            {row.original.rubros.map((r) => (
              <Badge key={r} variant="secondary">
                {r}
              </Badge>
            ))}
          </div>
        ),
    },
    {
      accessorKey: "chunkCount",
      header: "Fragmentos",
      cell: ({ row }) => <span className="tabular-nums">{formatPuntoSaasNumber(row.original.chunkCount)}</span>,
    },
    {
      accessorKey: "indexedAt",
      header: "Última actualización",
      cell: ({ row }) => <span className="tabular-nums">{niceDateTime(row.original.indexedAt)}</span>,
    },
    {
      id: "estado",
      header: "Estado",
      cell: ({ row }) => {
        const doc = row.original
        if (doc.indexError) return <Badge variant="destructive">Sin procesar</Badge>
        if (!doc.isActive) return <Badge variant="outline">Inactivo</Badge>
        return <Badge variant="secondary">Activo</Badge>
      },
    },
    {
      id: "actions",
      header: "",
      cell: ({ row }) => {
        const doc = row.original
        return (
          <div className="flex justify-end" onClick={(e) => e.stopPropagation()}>
            <RowActions
              actions={[
                { label: "Editar", icon: Pencil, onSelect: () => openEdit(doc) },
                { label: "Reprocesar", icon: RefreshCw, onSelect: () => handleReindex(doc) },
                {
                  label: doc.isActive ? "Desactivar" : "Activar",
                  icon: doc.isActive ? EyeOff : Eye,
                  onSelect: () =>
                    toggle.mutate(
                      { slug: doc.slug, isactive: !doc.isActive },
                      {
                        onSuccess: () => toast.success(doc.isActive ? "Documento desactivado" : "Documento activado"),
                        onError: (err) => toast.error(err.message ?? "No se pudo cambiar"),
                      },
                    ),
                },
                {
                  label: "Eliminar",
                  icon: Trash2,
                  variant: "destructive",
                  onSelect: () => setPendingDelete(doc),
                },
              ]}
            />
          </div>
        )
      },
    },
  ]

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-3">
          <BookOpen className="size-5 text-muted-foreground" />
          <h1 className="text-2xl font-semibold">Base de conocimiento</h1>
        </div>
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            onClick={() =>
              reindexAll.mutate(undefined, {
                onSuccess: (res) =>
                  res.ok
                    ? toast.success(`${res.indexed} de ${res.documents} documentos actualizados`)
                    : toast.warning(`${res.indexed} de ${res.documents} actualizados. Revisá los que quedaron sin procesar.`),
                onError: (err) => toast.error(err.message ?? "No se pudo reprocesar"),
              })
            }
            disabled={reindexAll.isPending || documents.length === 0 || notConfigured}
          >
            {reindexAll.isPending ? <RefreshCw className="size-4 animate-spin" /> : <RefreshCw className="size-4" />}
            Reprocesar todo
          </Button>
          <Button onClick={openNew} disabled={notConfigured}>
            <Plus className="size-4" />
            Cargar documento
          </Button>
        </div>
      </header>

      <p className="text-sm text-muted-foreground">
        Punto AI responde con lo que esté cargado acá. Cualquier comercio puede leerlo a través del asistente.
      </p>

      {/* Copy propio, no `error.message`: el backend ya saneaba el texto, pero
          la pantalla no tiene por qué confiar en que siga haciéndolo — es la
          única superficie por la que podría escaparse un detalle técnico
          (context/14 §8). El detalle vive en el log del backend. */}
      {error && (
        <Card>
          <CardContent className="flex items-center gap-3 py-4">
            <AlertTriangle className="size-5 text-destructive" />
            <p className="text-sm">
              No pude traer los documentos. Probá de nuevo en unos minutos.
            </p>
          </CardContent>
        </Card>
      )}

      {status?.configured && (
        <Card>
          <CardContent className="flex flex-wrap items-center gap-6 py-4">
            <div className="flex flex-col gap-0.5">
              <span className="text-sm text-muted-foreground">Documentos</span>
              <span className="text-base font-semibold tabular-nums">{formatPuntoSaasNumber(documents.length)}</span>
            </div>
            <div className="flex flex-col gap-0.5">
              <span className="text-sm text-muted-foreground">Fragmentos</span>
              <span className="text-base font-semibold tabular-nums">{formatPuntoSaasNumber(status.chunks)}</span>
            </div>
            {status.failedDocuments > 0 && (
              <div className="flex items-center gap-2">
                <AlertTriangle className="size-4 text-destructive" />
                <span className="text-sm">
                  {formatPuntoSaasNumber(status.failedDocuments)} sin procesar. Reprocesalos para que el asistente los use.
                </span>
              </div>
            )}
            {status.needsReindexAll && (
              <div className="flex items-center gap-2">
                <AlertTriangle className="size-4 text-destructive" />
                <span className="text-sm">
                  Hay documentos procesados de forma distinta. Reprocesá todo para que las respuestas sean parejas.
                </span>
              </div>
            )}
            {!status.providerReady && (
              <div className="flex items-center gap-2">
                <AlertTriangle className="size-4 text-destructive" />
                <span className="text-sm">Falta la clave del servicio en el servidor.</span>
              </div>
            )}
          </CardContent>
        </Card>
      )}

      {!isLoading && !error && notConfigured ? (
        <EmptyState
          icon={BookOpen}
          title="La base de conocimiento todavía no está lista"
          description="En cuanto esté disponible vas a poder cargar acá lo que Punto AI tiene que saber."
        />
      ) : !isLoading && !error && documents.length === 0 ? (
        <EmptyState
          icon={BookOpen}
          title="Todavía no cargaste nada"
          description="Subí un archivo o pegá el texto que querés que el asistente sepa responder."
          actions={
            <Button onClick={openNew}>
              <Plus className="size-4" />
              Cargar documento
            </Button>
          }
        />
      ) : (
        <DataTable
          tableId="admin-help-kb"
          data={documents}
          columns={columns}
          isLoading={isLoading}
          getRowId={(r) => r.documentId}
          onRowClick={openEdit}
          searchPlaceholder="Buscar documento…"
          emptyMessage="Sin documentos"
        />
      )}

      {/* Montado solo mientras está abierto, y con `key` por documento: así el
          estado del formulario arranca de las props en vez de sincronizarse
          con un efecto. Mismo patrón que el diálogo de paquetes de /admin/ai. */}
      {dialogOpen && (
        <DocumentDialog
          key={editing?.slug ?? "nuevo"}
          open
          onOpenChange={setDialogOpen}
          editing={editing}
        />
      )}

      <AlertDialog open={!!pendingDelete} onOpenChange={(v) => { if (!v) setPendingDelete(null) }}>
        <AlertDialogContent className="sm:max-w-md">
          <AlertDialogHeader>
            <AlertDialogTitle>¿Eliminar &quot;{pendingDelete?.title}&quot;?</AlertDialogTitle>
            <AlertDialogDescription>
              El asistente deja de responder con este documento.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                const doc = pendingDelete
                if (!doc) return
                remove.mutate(doc.slug, {
                  onSuccess: () => toast.success("Documento eliminado"),
                  onError: (err) => toast.error(err.message ?? "No se pudo eliminar"),
                })
                setPendingDelete(null)
              }}
            >
              Eliminar
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

export default function AdminHelpKbPage() {
  return (
    <AdminRoleGate minRole="owner">
      <HelpKbPageContent />
    </AdminRoleGate>
  )
}
