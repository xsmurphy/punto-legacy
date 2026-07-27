"use client"

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"

/**
 * Ayuda de atajos. Nadie adivina un atajo: la barra inferior tiene el botón de
 * teclado siempre visible y `?` abre esto.
 *
 * Modal (Dialog centrado), nunca Sheet/Drawer — convención del proyecto.
 */

const SHORTCUTS: { keys: string; label: string }[] = [
  { keys: "← →", label: "Comanda anterior / siguiente" },
  { keys: "↑ ↓", label: "Ítem anterior / siguiente" },
  { keys: "Enter", label: "Marcar el ítem; sin ítem seleccionado, la comanda entera" },
  { keys: "Backspace", label: "Retroceder un paso el ítem seleccionado" },
  { keys: "Z", label: "Deshacer la última acción" },
  { keys: "P", label: "Fijar / soltar la comanda a la izquierda" },
  { keys: "R", label: "Comandas que ya salieron (recall)" },
  { keys: "[ ]", label: "Página anterior / siguiente (o PgUp / PgDn)" },
  { keys: "Esc", label: "Soltar la selección" },
  { keys: "?", label: "Esta ayuda" },
]

interface HelpDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function KdsHelpDialog({ open, onOpenChange }: HelpDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Atajos de teclado</DialogTitle>
          <DialogDescription>
            Se opera más rápido sin soltar lo que se tiene en la mano. Con un ítem seleccionado
            Enter lo marca; sin ítem, marca la comanda entera. Mantener presionada una línea con el
            dedo también la retrocede un paso.
          </DialogDescription>
        </DialogHeader>

        <dl className="flex flex-col gap-2">
          {SHORTCUTS.map((s) => (
            <div key={s.keys} className="flex items-baseline gap-3">
              <dt className="w-24 shrink-0">
                <kbd className="rounded border bg-muted px-2 py-1 font-mono text-sm font-semibold">
                  {s.keys}
                </kbd>
              </dt>
              <dd className="min-w-0 flex-1 text-muted-foreground">{s.label}</dd>
            </div>
          ))}
        </dl>
      </DialogContent>
    </Dialog>
  )
}
