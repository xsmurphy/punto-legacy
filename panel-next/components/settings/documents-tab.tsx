"use client"

import * as React from "react"
import { toast } from "sonner"
import {
  Plus,
  Star,
  Trash2,
  FileText,
  Receipt,
  FileBadge,
  ScrollText,
  Gift,
  Package,
  Loader2,
} from "lucide-react"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { Switch } from "@/components/ui/switch"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
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
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog"
import { Label } from "@/components/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Separator } from "@/components/ui/separator"
import {
  useCreateDocumentTemplate,
  useDeleteDocumentTemplate,
  useDocumentTemplates,
  useUpdateDocumentTemplate,
} from "@/hooks/use-document-templates"
import {
  DOC_TYPE_LABELS,
  PAGE_SIZE_LABELS,
  FONT_OPTIONS,
  defaultConfig,
  type DocType,
  type DocumentTemplate,
  type DocumentTemplateConfig,
  type PageSize,
} from "@/lib/types/document-template"
import { cn } from "@/lib/utils"

const DOC_TYPE_ICONS: Record<DocType, React.ComponentType<{ className?: string }>> = {
  receipt:   Receipt,
  invoice:   FileBadge,
  quote:     ScrollText,
  workorder: FileText,
  giftcard:  Gift,
  delivery:  Package,
}

const DOC_TYPES: DocType[] = ["receipt", "invoice", "quote", "workorder", "giftcard", "delivery"]
const PAGE_SIZES: PageSize[] = ["57mm", "76mm", "80mm", "A4", "A4-landscape", "letter", "legal"]

export function DocumentsTab() {
  const { data, isLoading, error } = useDocumentTemplates()
  const [editing, setEditing] = React.useState<DocumentTemplate | null>(null)
  const [creatingType, setCreatingType] = React.useState<DocType | null>(null)

  const templates = data?.templates ?? []
  const byType = React.useMemo(() => {
    const map: Partial<Record<DocType, DocumentTemplate[]>> = {}
    for (const t of templates) {
      ;(map[t.docType] ??= []).push(t)
    }
    return map
  }, [templates])

  if (isLoading) {
    return (
      <div className="flex h-32 items-center justify-center text-sm text-muted-foreground">
        <Loader2 className="mr-2 size-4 animate-spin" />
        Cargando templates…
      </div>
    )
  }
  if (error) {
    return (
      <Card>
        <CardContent className="p-6 text-sm text-muted-foreground">
          No se pudieron cargar los templates. {error.message}
        </CardContent>
      </Card>
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="rounded-md border bg-muted/30 px-4 py-3 text-xs text-muted-foreground">
        Definí cómo se imprimen los tickets, facturas, presupuestos y otros
        documentos. Podés tener varios templates por tipo y marcar uno como
        predeterminado. El template default se usa cuando se imprime sin elegir
        explícitamente.
      </div>

      {DOC_TYPES.map((docType) => {
        const Icon = DOC_TYPE_ICONS[docType]
        const list = byType[docType] ?? []
        return (
          <section key={docType} className="flex flex-col gap-3">
            <div className="flex items-center justify-between gap-2">
              <div className="flex items-center gap-2">
                <Icon className="size-4 text-muted-foreground" />
                <h3 className="text-sm font-medium">
                  {DOC_TYPE_LABELS[docType]}
                </h3>
                <Badge variant="outline" className="text-[10px]">
                  {list.length}
                </Badge>
              </div>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-8 gap-1.5 text-xs"
                onClick={() => setCreatingType(docType)}
              >
                <Plus className="size-3.5" />
                Nuevo
              </Button>
            </div>

            {list.length === 0 ? (
              <div className="rounded-md border border-dashed bg-muted/20 px-4 py-6 text-center text-xs text-muted-foreground">
                Sin templates de {DOC_TYPE_LABELS[docType].toLowerCase()} todavía.
              </div>
            ) : (
              <div className="grid grid-cols-1 gap-2 md:grid-cols-2 xl:grid-cols-3">
                {list.map((t) => (
                  <TemplateCard
                    key={t.templateId}
                    template={t}
                    onEdit={() => setEditing(t)}
                  />
                ))}
              </div>
            )}
          </section>
        )
      })}

      {/* Modal crear */}
      {creatingType !== null && (
        <TemplateDialog
          mode="create"
          docType={creatingType}
          onClose={() => setCreatingType(null)}
        />
      )}

      {/* Modal editar */}
      {editing && (
        <TemplateDialog
          mode="edit"
          template={editing}
          onClose={() => setEditing(null)}
        />
      )}
    </div>
  )
}

// ── Card ──────────────────────────────────────────────────────────────────────

function TemplateCard({
  template,
  onEdit,
}: {
  template: DocumentTemplate
  onEdit: () => void
}) {
  const remove = useDeleteDocumentTemplate()
  return (
    <Card
      className="cursor-pointer transition hover:border-primary/60"
      onClick={onEdit}
    >
      <CardContent className="flex flex-col gap-2 p-4">
        <div className="flex items-start justify-between gap-2">
          <div className="flex flex-col gap-0.5">
            <p className="text-sm font-medium leading-tight">{template.name}</p>
            <p className="text-xs text-muted-foreground">
              {PAGE_SIZE_LABELS[template.pageSize]}
            </p>
          </div>
          {template.isDefault && (
            <Badge className="gap-1 text-[10px]">
              <Star className="size-2.5 fill-current" />
              Default
            </Badge>
          )}
        </div>
        <Separator />
        <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
          <span>
            Fuente: {template.config.font ?? "—"} ·{" "}
            {template.config.fontSize ?? "—"}pt
          </span>
          <AlertDialog>
            <AlertDialogTrigger asChild>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-6 text-muted-foreground hover:text-destructive"
                onClick={(e) => e.stopPropagation()}
              >
                <Trash2 className="size-3.5" />
              </Button>
            </AlertDialogTrigger>
            <AlertDialogContent onClick={(e) => e.stopPropagation()}>
              <AlertDialogHeader>
                <AlertDialogTitle>¿Eliminar &quot;{template.name}&quot;?</AlertDialogTitle>
                <AlertDialogDescription>
                  Esta acción no se puede deshacer. Si era el template default,
                  vas a tener que marcar otro como predeterminado.
                </AlertDialogDescription>
              </AlertDialogHeader>
              <AlertDialogFooter>
                <AlertDialogCancel>Cancelar</AlertDialogCancel>
                <AlertDialogAction
                  onClick={async () => {
                    try {
                      await remove.mutateAsync(template.templateId)
                      toast.success("Template eliminado")
                    } catch (e) {
                      toast.error("No se pudo eliminar", {
                        description: e instanceof Error ? e.message : undefined,
                      })
                    }
                  }}
                >
                  Eliminar
                </AlertDialogAction>
              </AlertDialogFooter>
            </AlertDialogContent>
          </AlertDialog>
        </div>
      </CardContent>
    </Card>
  )
}

// ── Dialog (create/edit) ──────────────────────────────────────────────────────

function TemplateDialog({
  mode,
  template,
  docType,
  onClose,
}: {
  mode: "create" | "edit"
  template?: DocumentTemplate
  docType?: DocType
  onClose: () => void
}) {
  const initial = React.useMemo<DocumentTemplate>(() => {
    if (mode === "edit" && template) return template
    const dt = docType ?? "receipt"
    return {
      templateId: "",
      name: "",
      docType: dt,
      pageSize: dt === "receipt" ? "80mm" : "A4",
      isDefault: false,
      config: defaultConfig(dt),
      created_at: null,
      updated_at: null,
    }
  }, [mode, template, docType])

  const [draft, setDraft] = React.useState<DocumentTemplate>(initial)
  const create = useCreateDocumentTemplate()
  const update = useUpdateDocumentTemplate()
  const busy = create.isPending || update.isPending

  const setCfg = (patch: Partial<DocumentTemplateConfig>) =>
    setDraft((d) => ({ ...d, config: { ...d.config, ...patch } }))

  const onSubmit = async () => {
    if (!draft.name.trim()) {
      toast.error("Falta el nombre del template")
      return
    }
    try {
      if (mode === "create") {
        await create.mutateAsync({
          name: draft.name,
          docType: draft.docType,
          pageSize: draft.pageSize,
          isDefault: draft.isDefault,
          config: draft.config,
        })
        toast.success("Template creado")
      } else {
        await update.mutateAsync({
          id: draft.templateId,
          values: {
            name: draft.name,
            docType: draft.docType,
            pageSize: draft.pageSize,
            isDefault: draft.isDefault,
            config: draft.config,
          },
        })
        toast.success("Template actualizado")
      }
      onClose()
    } catch (e) {
      toast.error("No se pudo guardar", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  const isRoll = draft.pageSize.endsWith("mm")

  return (
    <Dialog open onOpenChange={(v) => !v && onClose()}>
      <DialogContent className="flex max-h-[90vh] w-[min(95vw,820px)] max-w-none flex-col gap-0 p-0 sm:max-w-none">
        <DialogHeader className="border-b px-6 py-4">
          <DialogTitle>
            {mode === "create" ? "Nuevo template" : `Editar: ${draft.name || "Template"}`}
          </DialogTitle>
          <DialogDescription>
            {DOC_TYPE_LABELS[draft.docType]} · {PAGE_SIZE_LABELS[draft.pageSize]}
          </DialogDescription>
        </DialogHeader>

        <div className="flex-1 overflow-y-auto px-6 py-5">
          <div className="flex flex-col gap-6">
            {/* Identidad */}
            <Section title="Identidad">
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <Field label="Nombre">
                  <Input
                    value={draft.name}
                    onChange={(e) => setDraft({ ...draft, name: e.target.value })}
                    placeholder="Ej: Ticket caja 80mm"
                  />
                </Field>
                <Field label="Tipo de documento">
                  <Select
                    value={draft.docType}
                    onValueChange={(v) =>
                      setDraft({ ...draft, docType: v as DocType })
                    }
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {DOC_TYPES.map((dt) => (
                        <SelectItem key={dt} value={dt}>
                          {DOC_TYPE_LABELS[dt]}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </Field>
                <Field label="Tamaño de página">
                  <Select
                    value={draft.pageSize}
                    onValueChange={(v) =>
                      setDraft({ ...draft, pageSize: v as PageSize })
                    }
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {PAGE_SIZES.map((ps) => (
                        <SelectItem key={ps} value={ps}>
                          {PAGE_SIZE_LABELS[ps]}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </Field>
                <Field label="Default para este tipo">
                  <div className="flex h-9 items-center gap-2 rounded-md border bg-card px-3">
                    <Switch
                      checked={draft.isDefault}
                      onCheckedChange={(v) => setDraft({ ...draft, isDefault: v })}
                    />
                    <span className="text-xs text-muted-foreground">
                      {draft.isDefault ? "Es el predeterminado" : "Marcar como default"}
                    </span>
                  </div>
                </Field>
              </div>
            </Section>

            {/* Contenido */}
            <Section title="Contenido del documento">
              <div className="grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-2">
                <ToggleRow label="Logo de la empresa" value={!!draft.config.showLogo} onChange={(v) => setCfg({ showLogo: v })} />
                <ToggleRow label="Nombre de la empresa" value={!!draft.config.showCompanyName} onChange={(v) => setCfg({ showCompanyName: v })} />
                <ToggleRow label="RUC / TIN" value={!!draft.config.showCompanyTIN} onChange={(v) => setCfg({ showCompanyTIN: v })} />
                <ToggleRow label="Dirección de la empresa" value={!!draft.config.showCompanyAddress} onChange={(v) => setCfg({ showCompanyAddress: v })} />
                <ToggleRow label="Teléfono" value={!!draft.config.showCompanyPhone} onChange={(v) => setCfg({ showCompanyPhone: v })} />
                <ToggleRow label="Nombre de la sucursal" value={!!draft.config.showOutletName} onChange={(v) => setCfg({ showOutletName: v })} />
                <ToggleRow label="Dirección de la sucursal" value={!!draft.config.showOutletAddress} onChange={(v) => setCfg({ showOutletAddress: v })} />
                <ToggleRow label="Caja / registradora" value={!!draft.config.showRegisterInfo} onChange={(v) => setCfg({ showRegisterInfo: v })} />
                <ToggleRow label="Cajero / atendido por" value={!!draft.config.showCashier} onChange={(v) => setCfg({ showCashier: v })} />
                <ToggleRow label="Número de documento" value={!!draft.config.showDocNumber} onChange={(v) => setCfg({ showDocNumber: v })} />
                <ToggleRow label="Fecha y hora" value={!!draft.config.showDocDate} onChange={(v) => setCfg({ showDocDate: v })} />
                <ToggleRow label="Datos del cliente" value={!!draft.config.showCustomer} onChange={(v) => setCfg({ showCustomer: v })} />
              </div>
            </Section>

            {/* Items */}
            <Section title="Tabla de items">
              <div className="grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-2">
                <ToggleRow label="Mostrar tabla de items" value={!!draft.config.showItemsTable} onChange={(v) => setCfg({ showItemsTable: v })} />
                <ToggleRow label="Columna SKU" value={!!draft.config.showItemSKU} onChange={(v) => setCfg({ showItemSKU: v })} />
                <ToggleRow label="Columna cantidad" value={!!draft.config.showItemQty} onChange={(v) => setCfg({ showItemQty: v })} />
                <ToggleRow label="Columna precio unitario" value={!!draft.config.showItemUnitPrice} onChange={(v) => setCfg({ showItemUnitPrice: v })} />
                <ToggleRow label="Columna descuento" value={!!draft.config.showItemDiscount} onChange={(v) => setCfg({ showItemDiscount: v })} />
                <ToggleRow label="Desglose de impuestos por item" value={!!draft.config.showItemTaxBreakdown} onChange={(v) => setCfg({ showItemTaxBreakdown: v })} />
              </div>
            </Section>

            {/* Totales */}
            <Section title="Totales">
              <div className="grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-2">
                <ToggleRow label="Subtotal" value={!!draft.config.showSubtotal} onChange={(v) => setCfg({ showSubtotal: v })} />
                <ToggleRow label="Descuento total" value={!!draft.config.showDiscount} onChange={(v) => setCfg({ showDiscount: v })} />
                <ToggleRow label="Desglose de impuestos" value={!!draft.config.showTaxBreakdown} onChange={(v) => setCfg({ showTaxBreakdown: v })} />
                <ToggleRow label="Total" value={!!draft.config.showTotal} onChange={(v) => setCfg({ showTotal: v })} />
                <ToggleRow label="Monto recibido" value={!!draft.config.showAmountPaid} onChange={(v) => setCfg({ showAmountPaid: v })} />
                <ToggleRow label="Vuelto" value={!!draft.config.showChange} onChange={(v) => setCfg({ showChange: v })} />
              </div>
            </Section>

            {/* Textos custom */}
            <Section title="Textos personalizados">
              <Field label="Texto de encabezado (arriba del todo)">
                <Textarea
                  rows={2}
                  className="resize-none"
                  placeholder="Opcional. Aparece arriba del logo y datos de empresa."
                  value={draft.config.headerText ?? ""}
                  onChange={(e) => setCfg({ headerText: e.target.value })}
                />
              </Field>
              <Field label="Texto entre items y totales">
                <Textarea
                  rows={2}
                  className="resize-none"
                  placeholder="Opcional. Aparece después de la tabla de items y antes del subtotal."
                  value={draft.config.notesAfterItems ?? ""}
                  onChange={(e) => setCfg({ notesAfterItems: e.target.value })}
                />
              </Field>
              <Field label="Texto de pie (abajo del todo)">
                <Textarea
                  rows={2}
                  className="resize-none"
                  placeholder="Ej: ¡Gracias por su compra! · Visite www.miempresa.com"
                  value={draft.config.footerText ?? ""}
                  onChange={(e) => setCfg({ footerText: e.target.value })}
                />
              </Field>
            </Section>

            {/* Tipografía */}
            <Section title="Tipografía y layout">
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <Field label="Fuente">
                  <Select
                    value={draft.config.font ?? "Helvetica"}
                    onValueChange={(v) => setCfg({ font: v })}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {FONT_OPTIONS.map((f) => (
                        <SelectItem key={f} value={f}>
                          {f}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </Field>
                <Field label="Tamaño (pt)">
                  <Input
                    type="number"
                    min={6}
                    max={20}
                    value={draft.config.fontSize ?? 10}
                    onChange={(e) =>
                      setCfg({ fontSize: Number(e.target.value) || 10 })
                    }
                  />
                </Field>
                {isRoll && (
                  <Field label="Margen izquierdo (mm)">
                    <Input
                      type="number"
                      min={0}
                      max={20}
                      value={draft.config.marginLeft ?? 2}
                      onChange={(e) =>
                        setCfg({ marginLeft: Number(e.target.value) || 0 })
                      }
                    />
                  </Field>
                )}
              </div>
              <ToggleRow
                label="Forzar MAYÚSCULAS en todo el documento"
                value={!!draft.config.uppercase}
                onChange={(v) => setCfg({ uppercase: v })}
              />
            </Section>
          </div>
        </div>

        <DialogFooter className="border-t bg-muted/30 px-6 py-3">
          <Button variant="ghost" onClick={onClose}>
            Cancelar
          </Button>
          <Button onClick={onSubmit} disabled={busy}>
            {busy && <Loader2 className="size-4 animate-spin" />}
            {mode === "create" ? "Crear template" : "Guardar cambios"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

// ── Bits ──────────────────────────────────────────────────────────────────────

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-3">
      <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
        {title}
      </p>
      {children}
    </div>
  )
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-1.5">
      <Label className="text-xs text-muted-foreground">{label}</Label>
      {children}
    </div>
  )
}

function ToggleRow({
  label,
  value,
  onChange,
}: {
  label: string
  value: boolean
  onChange: (v: boolean) => void
}) {
  return (
    <label
      className={cn(
        "flex items-center justify-between gap-2 rounded-md border bg-card px-3 py-2 text-xs",
        "cursor-pointer hover:bg-muted/30",
      )}
    >
      <span>{label}</span>
      <Switch checked={value} onCheckedChange={onChange} />
    </label>
  )
}
