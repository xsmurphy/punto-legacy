"use client"

/**
 * Elegir el código POS propio antes de un bloqueo manual (context/72 §9.3).
 *
 * Lo abre "Bloquear" cuando la caja opera sin PIN (un solo usuario) y el PIN
 * del operador sigue siendo el del alta de la cuenta: bloquear sin esto lo
 * dejaría frente a un código que no conoce. Pide el código dos veces, lo
 * guarda por `/api/pos/operator-pin` (Bearer del device + afirmación de
 * operador, que adjunta `posFetch`) y recién entonces bloquea.
 *
 * Nunca muestra el código por defecto (§9.4).
 */

import * as React from "react"
import { useQueryClient } from "@tanstack/react-query"
import { Loader2 } from "lucide-react"
import { Button } from "@/components/ui/button"
import {
  ResponsiveDialog,
  ResponsiveDialogContent,
  ResponsiveDialogDescription,
  ResponsiveDialogFooter,
  ResponsiveDialogHeader,
  ResponsiveDialogTitle,
} from "@/components/ui/responsive-dialog"
import { PinInput } from "@/components/ui/pin-input"
import { posFetch } from "@/lib/api/pos-fetch"
import { useCatalogStore } from "@/lib/catalog/store"

async function sha256Hex(text: string): Promise<string> {
  const buf = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(text))
  return Array.from(new Uint8Array(buf))
    .map((b) => b.toString(16).padStart(2, "0"))
    .join("")
}

export function ChooseOwnPinDialog({
  open,
  onOpenChange,
  operatorId,
  onSaved,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  operatorId: string
  /** Se llama con el código ya guardado; quien abre el diálogo bloquea. */
  onSaved: () => void
}) {
  const qc = useQueryClient()
  const setUserPinhash = useCatalogStore((s) => s.setUserPinhash)
  const [pin, setPin] = React.useState("")
  const [confirm, setConfirm] = React.useState("")
  const [error, setError] = React.useState<string | null>(null)
  const [saving, setSaving] = React.useState(false)

  React.useEffect(() => {
    if (!open) {
      setPin("")
      setConfirm("")
      setError(null)
      setSaving(false)
    }
  }, [open])

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    if (!/^\d{4}$/.test(pin)) {
      setError("El código tiene que tener 4 dígitos")
      return
    }
    if (pin !== confirm) {
      setError("Los dos códigos no coinciden")
      return
    }
    setError(null)
    setSaving(true)
    try {
      const res = await posFetch("/api/pos/operator-pin", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ lockPass: pin }),
      })
      const json = (await res.json().catch(() => null)) as { ok?: boolean; error?: { message?: string } } | null
      if (!res.ok || !json?.ok) {
        setError(json?.error?.message ?? "No se pudo guardar el código")
        setSaving(false)
        return
      }
      // El lock screen valida contra el roster local, también sin red: el
      // hash nuevo tiene que estar ahí ANTES de bloquear. El bootstrap que se
      // refresca después lo deja también en el snapshot offline.
      setUserPinhash(operatorId, await sha256Hex(pin))
      void qc.invalidateQueries({ queryKey: ["pos-bootstrap"] })
      onSaved()
    } catch {
      setError("No se pudo guardar el código. Revisá la conexión.")
      setSaving(false)
    }
  }

  return (
    <ResponsiveDialog open={open} onOpenChange={onOpenChange}>
      <ResponsiveDialogContent className="sm:max-w-md">
        <form onSubmit={submit} className="flex flex-col gap-6">
          <ResponsiveDialogHeader>
            <ResponsiveDialogTitle>Elegí tu código</ResponsiveDialogTitle>
            <ResponsiveDialogDescription>Lo vas a usar para desbloquear la caja.</ResponsiveDialogDescription>
          </ResponsiveDialogHeader>
          <div className="flex flex-col items-center gap-4">
            <div className="flex flex-col items-center gap-2">
              <p className="text-sm text-muted-foreground">Código</p>
              <PinInput value={pin} onChange={(v) => { setPin(v); setError(null) }} disabled={saving} />
            </div>
            <div className="flex flex-col items-center gap-2">
              <p className="text-sm text-muted-foreground">Repetilo</p>
              <PinInput value={confirm} onChange={(v) => { setConfirm(v); setError(null) }} disabled={saving} />
            </div>
            {error ? <p className="text-sm text-destructive">{error}</p> : null}
          </div>
          <ResponsiveDialogFooter>
            <Button
              type="submit"
              size="lg"
              className="w-full"
              disabled={saving || pin.length !== 4 || confirm.length !== 4}
            >
              {saving ? <Loader2 className="size-4 animate-spin" /> : null}
              Guardar y bloquear
            </Button>
          </ResponsiveDialogFooter>
        </form>
      </ResponsiveDialogContent>
    </ResponsiveDialog>
  )
}
