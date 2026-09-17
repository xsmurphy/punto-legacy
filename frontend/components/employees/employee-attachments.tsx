"use client"

import * as React from "react"
import { Download, Loader2, Paperclip, Trash2, Upload } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
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
  downloadEmployeeAttachment,
  useDeleteEmployeeAttachment,
  useEmployeeAttachments,
  useUploadEmployeeAttachment,
  type EmployeeAttachment,
} from "@/hooks/use-employees"
import { formatDate } from "@/lib/format-date"

/**
 * Archivos del legajo — contrato, cédula, constancias.
 *
 * Los objetos son PRIVADOS en S3, así que la descarga no es un `<a href>` al
 * bucket: pasa por el endpoint con el Bearer del panel
 * (`downloadEmployeeAttachment`). Ver `EmployeeAttachmentService`.
 */
export function EmployeeAttachments({ employeeId }: { employeeId: string }) {
  const { data: attachments = [], isLoading } = useEmployeeAttachments(employeeId)
  const upload = useUploadEmployeeAttachment()
  const remove = useDeleteEmployeeAttachment()

  const inputRef = React.useRef<HTMLInputElement>(null)
  const [label, setLabel] = React.useState("")
  const [pendingDelete, setPendingDelete] = React.useState<EmployeeAttachment | null>(null)
  const [downloading, setDownloading] = React.useState<string | null>(null)

  const handlePick = async (file: File | undefined) => {
    if (!file) return
    try {
      await upload.mutateAsync({ employeeId, file, label: label.trim() || undefined })
      setLabel("")
      toast.success("Archivo agregado")
    } catch (e) {
      toast.error("No se pudo agregar el archivo", {
        description: e instanceof Error ? e.message : undefined,
      })
    } finally {
      if (inputRef.current) inputRef.current.value = ""
    }
  }

  const handleDownload = async (attachment: EmployeeAttachment) => {
    setDownloading(attachment.id)
    try {
      await downloadEmployeeAttachment(attachment)
    } catch (e) {
      toast.error("No se pudo descargar el archivo", {
        description: e instanceof Error ? e.message : undefined,
      })
    } finally {
      setDownloading(null)
    }
  }

  const handleDelete = async () => {
    if (!pendingDelete) return
    try {
      await remove.mutateAsync({ employeeId, attachmentId: pendingDelete.id })
      toast.success("Archivo eliminado")
    } catch (e) {
      toast.error("No se pudo eliminar el archivo", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
    setPendingDelete(null)
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
        <Input
          value={label}
          onChange={(e) => setLabel(e.target.value)}
          placeholder="Contrato, cédula…"
          className="sm:max-w-xs"
        />
        <input
          ref={inputRef}
          type="file"
          className="hidden"
          accept="application/pdf,image/jpeg,image/png,image/webp,image/heic"
          onChange={(e) => void handlePick(e.target.files?.[0])}
        />
        <Button
          type="button"
          variant="outline"
          onClick={() => inputRef.current?.click()}
          disabled={upload.isPending}
        >
          {upload.isPending ? (
            <Loader2 className="size-4 animate-spin" />
          ) : (
            <Upload className="size-4" />
          )}
          Subir archivo
        </Button>
      </div>

      {!isLoading && attachments.length === 0 ? (
        <EmptyState
          icon={Paperclip}
          title="Sin archivos"
          description="Subí el contrato o el documento de identidad."
          ghost={false}
        />
      ) : (
        <ul className="flex flex-col gap-2">
          {attachments.map((a) => (
            <li
              key={a.id}
              className="flex items-center justify-between gap-4 rounded-lg border p-3"
            >
              <div className="flex min-w-0 flex-col">
                <span className="truncate font-medium">{a.label ?? a.filename}</span>
                <span className="text-sm text-muted-foreground">
                  {a.label ? `${a.filename} · ` : ""}
                  {a.createdAt ? formatDate(a.createdAt) : ""}
                </span>
              </div>
              <div className="flex shrink-0 items-center gap-1">
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  aria-label="Descargar"
                  onClick={() => void handleDownload(a)}
                  disabled={downloading === a.id}
                >
                  {downloading === a.id ? (
                    <Loader2 className="size-4 animate-spin" />
                  ) : (
                    <Download className="size-4" />
                  )}
                </Button>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  aria-label="Eliminar"
                  onClick={() => setPendingDelete(a)}
                >
                  <Trash2 className="size-4" />
                </Button>
              </div>
            </li>
          ))}
        </ul>
      )}

      <AlertDialog
        open={pendingDelete !== null}
        onOpenChange={(open) => !open && setPendingDelete(null)}
      >
        <AlertDialogContent className="sm:max-w-md">
          <AlertDialogHeader>
            <AlertDialogTitle>Eliminar el archivo</AlertDialogTitle>
            <AlertDialogDescription>
              {pendingDelete?.label ?? pendingDelete?.filename} se elimina y no se puede
              recuperar.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction onClick={() => void handleDelete()}>Eliminar</AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
