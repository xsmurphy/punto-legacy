"use client"

import * as React from "react"
import { RotateCcw } from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip"
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

/**
 * Botón "Nueva conversación" — confirma antes de borrar, con tooltip.
 * Antes llamaba `clear()` directo desde el ícono: sin confirmación y sin
 * affordance, un toque perdía todo el historial del chat sin aviso. Wrapper
 * compartido entre la página `/chat` y el FAB flotante para que ambos
 * tengan el mismo comportamiento (no dos parches locales).
 */
interface Props {
  onClear: () => void
}

export function ClearChatButton({ onClear }: Props) {
  const [open, setOpen] = React.useState(false)

  return (
    <AlertDialog open={open} onOpenChange={setOpen}>
      <Tooltip>
        <TooltipTrigger asChild>
          <AlertDialogTrigger asChild>
            <Button variant="ghost" size="icon" aria-label="Nueva conversación">
              <RotateCcw className="size-4" />
            </Button>
          </AlertDialogTrigger>
        </TooltipTrigger>
        <TooltipContent>Nueva conversación — borra el historial</TooltipContent>
      </Tooltip>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>¿Borrar la conversación?</AlertDialogTitle>
          <AlertDialogDescription>
            Se pierde todo el historial de este chat. No se puede deshacer.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Cancelar</AlertDialogCancel>
          <AlertDialogAction
            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            onClick={onClear}
          >
            Borrar
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}
