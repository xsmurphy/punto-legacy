"use client"

import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import { Printer, Trash2, PrinterCheck } from "lucide-react"
import { toast } from "sonner"

import { Alert, AlertDescription } from "@/components/ui/alert"
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
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"

import {
  isWebUsbSupported,
  requestUsbPrinter,
  printTest,
  usePrinterBindingsStore,
  type PrinterBinding,
  type PrinterRole,
} from "@/lib/hardware/printers"

// ── Helpers ───────────────────────────────────────────────────────────────────

const ROLE_LABELS: Record<PrinterRole, string> = {
  receipt: "Ticketera",
  kitchen: "Comandera",
  invoice: "Factura A4",
}

function toHex(n: number): string {
  return `0x${n.toString(16).toUpperCase().padStart(4, "0")}`
}

// ── Modal de configuración al vincular ────────────────────────────────────────

interface ConfigDialogProps {
  device: USBDevice | null
  onClose: () => void
  onSave: (opts: { label: string; role: PrinterRole; paperWidthMm: 58 | 80 }) => void
}

function ConfigDialog({ device, onClose, onSave }: ConfigDialogProps) {
  const defaultLabel = device
    ? device.productName?.trim() ||
      `Impresora ${toHex(device.vendorId)}:${toHex(device.productId)}`
    : ""

  const [label, setLabel] = React.useState(defaultLabel)
  const [role, setRole] = React.useState<PrinterRole>("receipt")
  const [paperWidthMm, setPaperWidthMm] = React.useState<58 | 80>(80)

  React.useEffect(() => {
    if (device) {
      setLabel(
        device.productName?.trim() ||
          `Impresora ${toHex(device.vendorId)}:${toHex(device.productId)}`,
      )
      setRole("receipt")
      setPaperWidthMm(80)
    }
  }, [device])

  return (
    <Dialog open={device !== null} onOpenChange={(o) => { if (!o) onClose() }}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Configurar impresora</DialogTitle>
        </DialogHeader>

        <div className="space-y-4 py-2">
          <div className="space-y-1.5">
            <Label htmlFor="printer-label">Etiqueta</Label>
            <Input
              id="printer-label"
              value={label}
              onChange={(e) => setLabel(e.target.value)}
              placeholder="Ej. Epson TM-T20III"
            />
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="printer-role">Rol</Label>
            <Select value={role} onValueChange={(v) => setRole(v as PrinterRole)}>
              <SelectTrigger id="printer-role">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="receipt">Ticketera</SelectItem>
                <SelectItem value="kitchen">Comandera</SelectItem>
                <SelectItem value="invoice">Factura A4</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="printer-width">Ancho del papel</Label>
            <Select
              value={String(paperWidthMm)}
              onValueChange={(v) => setPaperWidthMm(Number(v) as 58 | 80)}
            >
              <SelectTrigger id="printer-width">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="80">80 mm</SelectItem>
                <SelectItem value="58">58 mm</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancelar
          </Button>
          <Button
            disabled={!label.trim()}
            onClick={() => onSave({ label: label.trim(), role, paperWidthMm })}
          >
            Guardar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

// ── Componente principal ──────────────────────────────────────────────────────

export function PrintersManager() {
  const bindings = usePrinterBindingsStore((s) => s.bindings)
  const addBinding = usePrinterBindingsStore((s) => s.addBinding)
  const removeBinding = usePrinterBindingsStore((s) => s.removeBinding)

  const [pendingDevice, setPendingDevice] = React.useState<USBDevice | null>(null)
  const [deleteTarget, setDeleteTarget] = React.useState<PrinterBinding | null>(null)
  const [testingId, setTestingId] = React.useState<string | null>(null)

  async function handleRequestDevice() {
    try {
      const device = await requestUsbPrinter()
      setPendingDevice(device)
    } catch (err) {
      if (err instanceof DOMException && err.name === "NotAllowedError") {
        // El usuario canceló el selector — no es un error real.
        return
      }
      toast.error("No se pudo acceder a la impresora.", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  function handleSave(opts: { label: string; role: PrinterRole; paperWidthMm: 58 | 80 }) {
    if (!pendingDevice) return

    const binding: PrinterBinding = {
      id: crypto.randomUUID(),
      role: opts.role,
      transport: "usb",
      vendorId: pendingDevice.vendorId,
      productId: pendingDevice.productId,
      label: opts.label,
      paperWidthMm: opts.paperWidthMm,
      createdAt: new Date().toISOString(),
    }
    addBinding(binding)
    setPendingDevice(null)
    toast.success("Impresora vinculada correctamente.")
  }

  async function handlePrintTest(binding: PrinterBinding) {
    setTestingId(binding.id)
    try {
      await printTest(binding)
      toast.success("Ticket de prueba enviado.")
    } catch (err) {
      toast.error("No se pudo imprimir.", {
        description: err instanceof Error ? err.message : undefined,
      })
    } finally {
      setTestingId(null)
    }
  }

  const columns = React.useMemo<ColumnDef<PrinterBinding>[]>(
    () => [
      {
        accessorKey: "label",
        header: "Etiqueta",
        cell: ({ row }) => (
          <span className="font-medium">{row.original.label}</span>
        ),
      },
      {
        accessorKey: "role",
        header: "Rol",
        cell: ({ row }) => ROLE_LABELS[row.original.role],
      },
      {
        accessorKey: "paperWidthMm",
        header: "Ancho",
        cell: ({ row }) => `${row.original.paperWidthMm} mm`,
      },
      {
        id: "vidpid",
        header: "VID:PID",
        cell: ({ row }) => (
          <span className="font-mono text-sm">
            {toHex(row.original.vendorId)}:{toHex(row.original.productId)}
          </span>
        ),
      },
      {
        id: "actions",
        header: "",
        cell: ({ row }) => {
          const b = row.original
          return (
            <div className="flex gap-1">
              <Button
                variant="ghost"
                size="sm"
                disabled={testingId === b.id}
                onClick={() => handlePrintTest(b)}
              >
                <PrinterCheck className="size-4 mr-1.5" />
                {testingId === b.id ? "Imprimiendo…" : "Imprimir prueba"}
              </Button>
              <Button
                variant="ghost"
                size="sm"
                className="text-destructive hover:text-destructive"
                onClick={() => setDeleteTarget(b)}
              >
                <Trash2 className="size-4 mr-1.5" />
                Eliminar
              </Button>
            </div>
          )
        },
      },
    ],
    [testingId],
  )

  const webUsbOk = isWebUsbSupported()

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Impresoras</h1>
          <p className="text-sm text-muted-foreground">
            Vincular impresoras térmicas por USB para tickets, comandas y facturas.
          </p>
        </div>
        {webUsbOk && (
          <Button onClick={handleRequestDevice}>
            <Printer className="size-4 mr-1.5" />
            Vincular impresora USB
          </Button>
        )}
      </header>

      {!webUsbOk && (
        <Alert>
          <Printer className="size-4" />
          <AlertDescription>
            Tu navegador no soporta impresión directa por USB. Usá Chrome o Edge
            en escritorio.
          </AlertDescription>
        </Alert>
      )}

      {webUsbOk && (
        <>
          <DataTable
            tableId="printers"
            columns={columns}
            data={bindings}
            searchPlaceholder="Buscar impresora…"
            exportFileName={null}
            emptyMessage={
              <EmptyState
                icon={Printer}
                title="Sin impresoras vinculadas"
                description='Hacé clic en "Vincular impresora USB" para agregar la primera.'
                showMarquee={false}
              />
            }
          />

          <ConfigDialog
            device={pendingDevice}
            onClose={() => setPendingDevice(null)}
            onSave={handleSave}
          />

          <AlertDialog
            open={deleteTarget !== null}
            onOpenChange={(o) => { if (!o) setDeleteTarget(null) }}
          >
            <AlertDialogContent>
              <AlertDialogHeader>
                <AlertDialogTitle>Eliminar impresora</AlertDialogTitle>
                <AlertDialogDescription>
                  {`¿Eliminar "${deleteTarget?.label}"? El vínculo se borrará de este dispositivo.`}
                </AlertDialogDescription>
              </AlertDialogHeader>
              <AlertDialogFooter>
                <AlertDialogCancel>Cancelar</AlertDialogCancel>
                <AlertDialogAction
                  className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                  onClick={() => {
                    if (!deleteTarget) return
                    removeBinding(deleteTarget.id)
                    toast.success("Impresora eliminada.")
                    setDeleteTarget(null)
                  }}
                >
                  Eliminar
                </AlertDialogAction>
              </AlertDialogFooter>
            </AlertDialogContent>
          </AlertDialog>
        </>
      )}
    </div>
  )
}
