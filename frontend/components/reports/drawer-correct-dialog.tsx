"use client"

/**
 * Corrección de un arqueo de caja.
 *
 * Existe porque el cierre se carga a mano en el POS y se equivoca: el cajero
 * cuenta mal, cierra con la fecha del día siguiente, o cierra una caja que no
 * era. Sin esta pantalla la única salida era tocar la base.
 *
 * Es una operación de AUDITORÍA: pisa los cuatro campos del arqueo y con eso
 * cambia la diferencia del reporte y los números de efectivo. Por eso el
 * diálogo muestra los valores actuales al lado de los nuevos — corregir a
 * ciegas es cómo se rompe un arqueo que estaba bien.
 */

import * as React from "react"
import { toast } from "sonner"

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { MoneyInput } from "@/components/ui/money-input"
import { useCorrectDrawer, type DrawerRow } from "@/hooks/use-reports"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { formatMoney } from "@/lib/format"

/** "YYYY-MM-DD HH:MM:SS" (backend) → "YYYY-MM-DDTHH:MM" (input datetime-local). */
function toInputValue(raw: string | null): string {
  if (!raw) return ""
  const s = raw.trim().replace(" ", "T")
  return s.length >= 16 ? s.slice(0, 16) : s
}

/** "YYYY-MM-DDTHH:MM" → "YYYY-MM-DD HH:MM:SS" (lo que valida el endpoint). */
function toBackendValue(raw: string): string {
  if (!raw.trim()) return ""
  const s = raw.replace("T", " ")
  return s.length === 16 ? `${s}:00` : s
}

function toNum(v: number | string | null): number {
  const n = typeof v === "number" ? v : parseFloat(String(v ?? "0"))
  return Number.isFinite(n) ? n : 0
}

interface Props {
  drawer: DrawerRow | null
  onClose: () => void
}

export function DrawerCorrectDialog({ drawer, onClose }: Props) {
  const { data: bootstrap } = useBootstrap()
  const correct = useCorrectDrawer()

  const [openDate, setOpenDate] = React.useState("")
  const [openAmount, setOpenAmount] = React.useState<number | null>(0)
  const [closeDate, setCloseDate] = React.useState("")
  const [closeAmount, setCloseAmount] = React.useState<number | null>(0)

  // Re-sembrar cada vez que se abre con otra caja: el diálogo se monta una
  // sola vez y sin esto quedarían los valores de la caja anterior.
  React.useEffect(() => {
    if (!drawer) return
    setOpenDate(toInputValue(drawer.openDate))
    setOpenAmount(toNum(drawer.openAmount))
    setCloseDate(toInputValue(drawer.closeDate))
    setCloseAmount(toNum(drawer.closeAmount))
  }, [drawer])

  if (!drawer) return null

  const openDateInvalid = openDate.trim() === ""
  // Cerrar antes de abrir no es un arqueo posible; el backend no lo valida,
  // así que el corte va acá.
  const rangeInvalid =
    closeDate.trim() !== "" && openDate.trim() !== "" && closeDate < openDate

  function handleSave() {
    if (!drawer) return
    correct.mutate(
      {
        drawerId: drawer.drawerId,
        openDate: toBackendValue(openDate),
        openAmount: openAmount ?? 0,
        closeDate: toBackendValue(closeDate),
        closeAmount: closeAmount ?? 0,
      },
      {
        onSuccess: () => {
          toast.success("Arqueo corregido")
          onClose()
        },
        onError: (err) => toast.error(err.message),
      },
    )
  }

  return (
    <Dialog open onOpenChange={(o) => { if (!o) onClose() }}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Corregir arqueo</DialogTitle>
          <DialogDescription>
            {drawer.outletName} · {drawer.registerName}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4 py-2">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="drawer-open-date">Apertura</Label>
              <Input
                id="drawer-open-date"
                type="datetime-local"
                value={openDate}
                onChange={(e) => setOpenDate(e.target.value)}
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="drawer-open-amount">Monto inicial</Label>
              <MoneyInput
                id="drawer-open-amount"
                value={openAmount}
                onChange={setOpenAmount}
              />
              <p className="text-xs text-muted-foreground">
                Actual: {formatMoney(toNum(drawer.openAmount), bootstrap)}
              </p>
            </div>
          </div>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="drawer-close-date">Cierre</Label>
              <Input
                id="drawer-close-date"
                type="datetime-local"
                value={closeDate}
                onChange={(e) => setCloseDate(e.target.value)}
              />
              <p className="text-xs text-muted-foreground">
                Vacío deja la caja abierta.
              </p>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="drawer-close-amount">Monto contado</Label>
              <MoneyInput
                id="drawer-close-amount"
                value={closeAmount}
                onChange={setCloseAmount}
              />
              <p className="text-xs text-muted-foreground">
                {drawer.isClosed
                  ? `Actual: ${formatMoney(toNum(drawer.closeAmount), bootstrap)}`
                  : "La caja figura abierta."}
              </p>
            </div>
          </div>

          {rangeInvalid && (
            <p className="text-xs text-destructive">
              El cierre no puede ser anterior a la apertura.
            </p>
          )}
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={correct.isPending}>
            Cancelar
          </Button>
          <Button
            onClick={handleSave}
            disabled={correct.isPending || openDateInvalid || rangeInvalid}
          >
            {correct.isPending ? "Guardando…" : "Guardar"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
