"use client"

/**
 * Modal bloqueante que solicita el monto inicial de caja antes de cobrar.
 *
 * Se muestra cuando el cajero intenta cobrar y la caja no está abierta.
 * Confirmar abre la caja y continúa al flujo de pago.
 *
 * Usa NumericPadDialog (patrón canónico touch/tablet).
 */

import * as React from "react"
import { NumericPadDialog } from "@/components/pos/numeric-pad-dialog"
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
  const [draft, setDraft] = React.useState<string>("0")
  const openDrawer = useOpenDrawer()

  const handleConfirm = async () => {
    try {
      await openDrawer.mutateAsync({ amount: Number(draft) })
      onOpenChange(false)
      onSuccess()
    } catch {
      toast.error("No se pudo abrir la caja. Intentá de nuevo.")
    }
  }

  // Resetear el draft al abrir el dialog.
  React.useEffect(() => {
    if (open) setDraft("0")
  }, [open])

  return (
    <NumericPadDialog
      open={open}
      onClose={() => onOpenChange(false)}
      title="Monto inicial de caja"
      mode="money"
      value={draft}
      onValueChange={setDraft}
      onConfirm={handleConfirm}
      confirmLabel="Abrir caja"
    />
  )
}
