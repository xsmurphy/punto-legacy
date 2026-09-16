"use client"

/**
 * Elegir tu código POS cuando todavía es el que puso el alta de la cuenta
 * (context/72 §9.3).
 *
 * Con un solo usuario la caja no pide código, así que el dueño nunca lo
 * necesitó. Apenas el comercio tiene un segundo usuario el bloqueo vuelve, y
 * el dueño no puede quedar frente a un código que no eligió. Se abre solo en
 * la sección Equipo —que es donde se da de alta a ese segundo usuario— cuando
 * el servidor dice que hace falta, y se vuelve a abrir mientras siga haciendo
 * falta.
 *
 * NO muestra el código por defecto: dejarlo en pantalla es dejar un código
 * conocido en todos los comercios (§9.4).
 */

import * as React from "react"
import { Loader2 } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { PinInput } from "@/components/ui/pin-input"
import { ApiError } from "@/lib/api-client"
import { useOwnPinPrompt, useSetOwnPin } from "@/hooks/use-team"

export function OwnPinDialog() {
  const { data } = useOwnPinPrompt()
  const save = useSetOwnPin()
  const [pin, setPin] = React.useState("")
  const [error, setError] = React.useState<string | null>(null)
  // "Ahora no" cierra hasta la próxima vez que se entre a Equipo. No se guarda
  // en ningún lado: mientras el código siga siendo el por defecto, se vuelve a
  // pedir. Se reabre también cuando el servidor pasa de "no hace falta" a "hace
  // falta" (el alta del segundo usuario en esta misma visita).
  const [dismissed, setDismissed] = React.useState(false)

  const required = data?.required === true
  React.useEffect(() => {
    if (required) setDismissed(false)
  }, [required])

  const open = required && !dismissed

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    if (!/^\d{4}$/.test(pin)) {
      setError("El código tiene que tener 4 dígitos")
      return
    }
    setError(null)
    try {
      await save.mutateAsync(pin)
      setPin("")
      toast.success("Código POS guardado")
    } catch (err) {
      setError(err instanceof ApiError && err.message ? err.message : "No se pudo guardar el código")
    }
  }

  return (
    <Dialog open={open} onOpenChange={(next) => { if (!next) setDismissed(true) }}>
      <DialogContent className="sm:max-w-md">
        <form onSubmit={submit} className="flex flex-col gap-6">
          <DialogHeader>
            <DialogTitle>Elegí tu código POS</DialogTitle>
            <DialogDescription>
              Lo vas a usar para desbloquear la caja.
            </DialogDescription>
          </DialogHeader>
          <div className="flex flex-col items-center gap-2">
            <PinInput value={pin} onChange={(v) => { setPin(v); setError(null) }} />
            {error ? <p className="text-sm text-destructive">{error}</p> : null}
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setDismissed(true)}>
              Ahora no
            </Button>
            <Button type="submit" disabled={save.isPending || pin.length !== 4}>
              {save.isPending ? <Loader2 className="size-4 animate-spin" /> : null}
              Guardar
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
