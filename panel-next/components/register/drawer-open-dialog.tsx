"use client"

/**
 * Modal bloqueante que solicita el monto inicial de caja antes de cobrar.
 *
 * Se muestra cuando el cajero intenta cobrar y la caja no está abierta.
 * Confirmar abre la caja y continúa al flujo de pago.
 */

import * as React from "react"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { MoneyInput } from "@/components/ui/money-input"
import { useOpenDrawer } from "@/hooks/use-drawer"
import { toast } from "sonner"

interface DrawerOpenDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSuccess: () => void
}

export function DrawerOpenDialog({
  open,
  onOpenChange,
  onSuccess,
}: DrawerOpenDialogProps) {
  const [amount, setAmount] = React.useState<number | null>(null)
  const openDrawer = useOpenDrawer()

  const handleConfirm = async () => {
    try {
      await openDrawer.mutateAsync({ amount: amount ?? 0 })
      onOpenChange(false)
      onSuccess()
    } catch {
      toast.error("No se pudo abrir la caja. Intentá de nuevo.")
    }
  }

  // Resetear el monto al abrir el dialog.
  React.useEffect(() => {
    if (open) setAmount(null)
  }, [open])

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-sm">
        <DialogHeader>
          <DialogTitle>Abrir caja</DialogTitle>
        </DialogHeader>

        <div className="py-2">
          <p className="mb-4 text-sm text-muted-foreground">
            Ingresá el monto inicial en efectivo para abrir la caja y continuar
            con el cobro.
          </p>
          <MoneyInput
            value={amount}
            onChange={setAmount}
            placeholder="0"
            autoFocus
            onKeyDown={(e) => {
              if (e.key === "Enter" && !openDrawer.isPending) {
                handleConfirm()
              }
            }}
          />
        </div>

        <DialogFooter>
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={openDrawer.isPending}
          >
            Cancelar
          </Button>
          <Button onClick={handleConfirm} disabled={openDrawer.isPending}>
            {openDrawer.isPending ? "Abriendo..." : "Abrir caja"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
