"use client"

import * as React from "react"
import Link from "next/link"
import { toast } from "sonner"
import { Plus, Pencil, Trash2, FileText, Loader2 } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog"
import {
  useDeleteDocumentTemplate,
  useDocumentTemplates,
} from "@/hooks/use-document-templates"
import type { DocumentTemplateRow } from "@/lib/types/print-template"

const DOC_TYPE_LABELS: Record<DocumentTemplateRow["docType"], string> = {
  receipt:   "Ticket",
  invoice:   "Factura",
  quote:     "Presupuesto",
  workorder: "Orden de trabajo",
  giftcard:  "Gift card",
  delivery:  "Remito",
}

const PAGE_SIZE_LABELS: Record<DocumentTemplateRow["pageSize"], string> = {
  "57mm":         "Rollo 57",
  "76mm":         "Rollo 76",
  "80mm":         "Rollo 80",
  "A4":           "A4",
  "A4-landscape": "A4 horiz.",
  "letter":       "Carta",
  "legal":        "Oficio",
}

/**
 * Tab "Documentos" de Settings — listado de plantillas + acceso al editor.
 * El editor visual full-page vive en /settings/print-templates.
 *
 * `onNavigate` (opcional): pasado por el modal de Settings para cerrar el
 * Dialog antes de navegar al editor full-page; sin él (uso standalone) caemos
 * a un Link normal.
 */
export function DocumentsTab({ onNavigate }: { onNavigate?: (href: string) => void } = {}) {
  const { data, isLoading } = useDocumentTemplates()
  const del = useDeleteDocumentTemplate()

  const templates = data?.templates ?? []

  return (
    <div className="space-y-4">
      <header className="flex items-start justify-between gap-4">
        <div>
          <h2 className="text-base font-semibold">Plantillas de impresión</h2>
          <p className="text-sm text-muted-foreground">
            Diseñá facturas, tickets, presupuestos y comprobantes en el editor visual.
            Cada plantilla se asigna a un tipo de documento.
          </p>
        </div>
        {onNavigate ? (
          <Button onClick={() => onNavigate("/settings/print-templates")}>
            <Plus className="size-4" />
            Nueva plantilla
          </Button>
        ) : (
          <Button asChild>
            <Link href="/settings/print-templates">
              <Plus className="size-4" />
              Nueva plantilla
            </Link>
          </Button>
        )}
      </header>

      <Card>
        <CardContent className="p-0">
          {isLoading ? (
            <div className="flex items-center justify-center py-12 text-muted-foreground">
              <Loader2 className="size-4 animate-spin" />
            </div>
          ) : templates.length === 0 ? (
            <div className="flex flex-col items-center gap-2 py-12 text-muted-foreground">
              <FileText className="size-8 opacity-30" />
              <p>Aún no tenés plantillas creadas.</p>
              <p className="text-xs">
                Empezá con una en blanco desde el botón <strong>Nueva plantilla</strong>.
              </p>
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Nombre</TableHead>
                  <TableHead>Tipo de documento</TableHead>
                  <TableHead>Tamaño</TableHead>
                  <TableHead>Por defecto</TableHead>
                  <TableHead className="w-24" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {templates.map((t) => (
                  <TableRow key={t.templateId}>
                    <TableCell className="font-medium">{t.name}</TableCell>
                    <TableCell>{DOC_TYPE_LABELS[t.docType]}</TableCell>
                    <TableCell>{PAGE_SIZE_LABELS[t.pageSize]}</TableCell>
                    <TableCell>
                      {t.isDefault && <Badge variant="secondary">Por defecto</Badge>}
                    </TableCell>
                    <TableCell>
                      <div className="flex items-center justify-end gap-1">
                        {onNavigate ? (
                          <Button
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            onClick={() => onNavigate(`/settings/print-templates?id=${t.templateId}`)}
                            aria-label="Editar plantilla"
                          >
                            <Pencil className="size-3.5" />
                          </Button>
                        ) : (
                          <Button asChild variant="ghost" size="icon" className="size-8">
                            <Link href={`/settings/print-templates?id=${t.templateId}`}>
                              <Pencil className="size-3.5" />
                            </Link>
                          </Button>
                        )}
                        <DeleteTemplateButton
                          name={t.name}
                          onConfirm={async () => {
                            try {
                              await del.mutateAsync(t.templateId)
                              toast.success("Plantilla eliminada")
                            } catch (e) {
                              toast.error("No se pudo eliminar", {
                                description: e instanceof Error ? e.message : undefined,
                              })
                            }
                          }}
                        />
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>
    </div>
  )
}

function DeleteTemplateButton({
  name,
  onConfirm,
}: {
  name: string
  onConfirm: () => Promise<void>
}) {
  return (
    <AlertDialog>
      <AlertDialogTrigger asChild>
        <Button variant="ghost" size="icon" className="size-8 text-destructive">
          <Trash2 className="size-3.5" />
        </Button>
      </AlertDialogTrigger>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Eliminar plantilla</AlertDialogTitle>
          <AlertDialogDescription>
            ¿Eliminar la plantilla <strong>{name}</strong>? Esta acción no se puede deshacer.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Cancelar</AlertDialogCancel>
          <AlertDialogAction onClick={onConfirm}>Eliminar</AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}
