"use client"

import * as React from "react"
import { toast } from "sonner"
import { Upload, FileText, Download, CheckCircle2, AlertTriangle, Loader2 } from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog"
import { Label } from "@/components/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { api } from "@/lib/api-client"
import { useImportItems, importTemplateUrl, type ImportReport } from "@/hooks/use-items"
import { cn } from "@/lib/utils"

export function ImportItemsDialog() {
  const [open, setOpen] = React.useState(false)
  const [file, setFile] = React.useState<File | null>(null)
  const [mode, setMode] = React.useState<"insert" | "update">("insert")
  const [report, setReport] = React.useState<ImportReport | null>(null)
  const [dragOver, setDragOver] = React.useState(false)
  const inputRef = React.useRef<HTMLInputElement>(null)
  const importMut = useImportItems()

  const reset = () => {
    setFile(null)
    setMode("insert")
    setReport(null)
    if (inputRef.current) inputRef.current.value = ""
  }

  const onPickFile = (f: File | null) => {
    if (!f) return setFile(null)
    if (!/\.(csv|txt)$/i.test(f.name)) {
      toast.error("Solo archivos CSV", {
        description: "Si tenés un XLSX, exportalo como CSV desde Excel/Google Sheets primero.",
      })
      return
    }
    setFile(f)
    setReport(null)
  }

  const onSubmit = async () => {
    if (!file) return
    try {
      const r = await importMut.mutateAsync({ file, mode })
      setReport(r)
      if (r.errors.length === 0) {
        toast.success(
          `${r.created} ${r.created === 1 ? "artículo creado" : "artículos creados"}` +
            (r.updated > 0 ? `, ${r.updated} actualizados` : ""),
        )
      } else {
        toast.warning(`Importado con ${r.errors.length} errores`, {
          description: "Revisá el detalle abajo.",
        })
      }
    } catch (e) {
      toast.error("Falló la importación", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  const onDownloadTemplate = async () => {
    try {
      // Fetch con credentials para que el JWT viaje en el cookie cross-origin.
      const res = await fetch(importTemplateUrl(), { credentials: "include" })
      if (!res.ok) throw new Error("No se pudo descargar la plantilla")
      const blob = await res.blob()
      const url = URL.createObjectURL(blob)
      const a = document.createElement("a")
      a.href = url
      a.download = "items_plantilla.csv"
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      URL.revokeObjectURL(url)
    } catch (e) {
      toast.error("No se pudo descargar la plantilla", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(v) => {
        setOpen(v)
        if (!v) reset()
      }}
    >
      <DialogTrigger asChild>
        <Button variant="outline">
          <Upload className="size-4" />
          Importar
        </Button>
      </DialogTrigger>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>Importar artículos desde CSV</DialogTitle>
          <DialogDescription>
            Cargá un CSV con los artículos. Marca, categoría e impuesto se crean
            automáticamente si no existen.
          </DialogDescription>
        </DialogHeader>

        {!report && (
          <div className="flex flex-col gap-4">
            <div className="flex items-center justify-between gap-2">
              <Label className="text-xs text-muted-foreground">
                ¿No tenés el formato? Descargá la plantilla con ejemplos.
              </Label>
              <Button
                variant="ghost"
                size="sm"
                className="h-8 gap-1.5 text-xs"
                onClick={onDownloadTemplate}
              >
                <Download className="size-3.5" />
                Plantilla CSV
              </Button>
            </div>

            <div
              className={cn(
                "flex flex-col items-center justify-center gap-2 rounded-md border-2 border-dashed p-8 transition",
                dragOver
                  ? "border-primary bg-primary/5"
                  : "border-muted-foreground/20 hover:border-muted-foreground/40",
              )}
              onDragOver={(e) => {
                e.preventDefault()
                setDragOver(true)
              }}
              onDragLeave={() => setDragOver(false)}
              onDrop={(e) => {
                e.preventDefault()
                setDragOver(false)
                const f = e.dataTransfer.files?.[0] ?? null
                onPickFile(f)
              }}
              onClick={() => inputRef.current?.click()}
              role="button"
              tabIndex={0}
            >
              <input
                ref={inputRef}
                type="file"
                accept=".csv,.txt,text/csv"
                className="hidden"
                onChange={(e) => onPickFile(e.target.files?.[0] ?? null)}
              />
              {file ? (
                <>
                  <FileText className="size-8 text-primary" />
                  <p className="text-sm font-medium">{file.name}</p>
                  <p className="text-xs text-muted-foreground">
                    {(file.size / 1024).toFixed(1)} KB · Click para cambiar
                  </p>
                </>
              ) : (
                <>
                  <Upload className="size-8 text-muted-foreground/40" />
                  <p className="text-sm">
                    Arrastrá un CSV o <span className="font-medium underline">click para elegir</span>
                  </p>
                  <p className="text-xs text-muted-foreground">
                    Máximo 2000 filas. Delimitador `,` o `;`.
                  </p>
                </>
              )}
            </div>

            <div className="flex items-center justify-between gap-3">
              <Label htmlFor="import-mode" className="text-xs">
                Modo
              </Label>
              <Select value={mode} onValueChange={(v) => setMode(v as typeof mode)}>
                <SelectTrigger id="import-mode" className="h-9 w-[200px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="insert">Crear nuevos artículos</SelectItem>
                  <SelectItem value="update">Actualizar por SKU</SelectItem>
                </SelectContent>
              </Select>
            </div>
            {mode === "update" && (
              <p className="text-xs text-muted-foreground">
                En modo actualizar, el SKU es obligatorio en cada fila —
                buscamos el artículo existente por ese campo.
              </p>
            )}
          </div>
        )}

        {report && (
          <div className="flex flex-col gap-3">
            <div className="flex items-center gap-3 rounded-md border bg-card p-4">
              {report.errors.length === 0 ? (
                <CheckCircle2 className="size-6 text-emerald-500" />
              ) : (
                <AlertTriangle className="size-6 text-amber-500" />
              )}
              <div className="flex flex-col gap-0.5">
                <p className="font-medium">
                  {report.created} creados, {report.updated} actualizados
                  {report.errors.length > 0 && `, ${report.errors.length} errores`}
                </p>
                <p className="text-xs text-muted-foreground">
                  Procesadas {report.total}{" "}
                  {report.total === 1 ? "fila" : "filas"}.
                </p>
              </div>
            </div>

            {report.errors.length > 0 && (
              <div className="flex flex-col gap-1 rounded-md border border-destructive/30 bg-destructive/5 p-3">
                <p className="text-xs font-medium text-destructive">Errores</p>
                <div className="max-h-48 overflow-auto text-xs">
                  {report.errors.map((e, i) => (
                    <div key={i} className="flex gap-2 py-0.5">
                      <span className="font-mono text-muted-foreground">
                        L{e.line}
                      </span>
                      <span>{e.message}</span>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        )}

        <DialogFooter>
          {!report ? (
            <>
              <Button variant="ghost" onClick={() => setOpen(false)}>
                Cancelar
              </Button>
              <Button
                onClick={onSubmit}
                disabled={!file || importMut.isPending}
              >
                {importMut.isPending && (
                  <Loader2 className="size-4 animate-spin" />
                )}
                Importar
              </Button>
            </>
          ) : (
            <>
              <Button variant="ghost" onClick={reset}>
                Subir otro
              </Button>
              <Button onClick={() => setOpen(false)}>Listo</Button>
            </>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
