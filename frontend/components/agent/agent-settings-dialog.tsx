"use client"

import * as React from "react"
import { Settings2, Loader2 } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group"
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog"
import { useSettings, useUpdateSettings } from "@/hooks/use-settings"
import type { SettingsGeneral } from "@/lib/types/settings"
import { cn } from "@/lib/utils"

type AgentPersonality = SettingsGeneral["agentPersonality"]

const PERSONALITIES: { value: AgentPersonality; label: string; desc: string }[] = [
  { value: "professional", label: "Profesional", desc: "Tono neutro, va al punto con cortesía." },
  { value: "friendly", label: "Cercano", desc: "Tono cálido, tuteo relajado." },
  { value: "direct", label: "Directo", desc: "Respuestas mínimas, el dato primero." },
  { value: "teacher", label: "Didáctico", desc: "Explica el porqué de los números." },
]

/**
 * Configuración del asistente IA — nombre y personalidad, por empresa.
 * Compartido entre la página `/chat` y el FAB flotante (mismo patrón que
 * ClearChatButton: componente único, mismo trigger visual en ambos lados).
 *
 * Guarda vía el mismo flujo que el resto de Settings (useUpdateSettings,
 * POST /v1/settings action=update&type=setting) — ese endpoint espera el
 * objeto COMPLETO de settings (ver serialize() en hooks/use-settings.ts),
 * así que acá partimos de los datos ya cargados (useSettings, misma query
 * key que el modal de Settings — cache compartida) y solo pisamos los 2
 * campos del asistente. Mandar un payload parcial pisaría el resto de los
 * ajustes de la empresa con strings vacíos.
 */
export function AgentSettingsDialog() {
  const [open, setOpen] = React.useState(false)
  const { data } = useSettings()
  const update = useUpdateSettings()

  const [name, setName] = React.useState("")
  const [personality, setPersonality] = React.useState<AgentPersonality>("professional")

  // Sincronizar el form local con los datos cargados cada vez que se abre —
  // evita mostrar valores stale si otra pestaña/sección cambió el nombre.
  React.useEffect(() => {
    if (!open || !data) return
    setName(data.agentName ?? "")
    setPersonality(data.agentPersonality ?? "professional")
  }, [open, data])

  async function handleSave() {
    if (!data) return
    const { logo, hasLogo, ...rest } = data
    void logo
    void hasLogo
    try {
      await update.mutateAsync({
        ...rest,
        agentName: name.trim().slice(0, 40),
        agentPersonality: personality,
      })
      // El tono nuevo entra al system prompt del PRÓXIMO mensaje, pero en una
      // conversación larga el historial con el tono viejo pesa — avisar que
      // un chat nuevo lo aplica pleno evita el reporte "no cambió nada".
      toast.success("Asistente actualizado", {
        description:
          "El cambio aplica desde el próximo mensaje. En una conversación ya iniciada el tono anterior puede persistir — para verlo pleno, iniciá un chat nuevo.",
      })
      setOpen(false)
    } catch (e) {
      toast.error("No se pudo guardar la configuración del asistente", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <Tooltip>
        <TooltipTrigger asChild>
          <DialogTrigger asChild>
            <Button variant="ghost" size="icon" aria-label="Configurar asistente">
              <Settings2 className="size-4" />
            </Button>
          </DialogTrigger>
        </TooltipTrigger>
        <TooltipContent>Configurar asistente</TooltipContent>
      </Tooltip>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Configurar asistente</DialogTitle>
          <DialogDescription>
            Personalizá el nombre y el estilo de comunicación del asistente para tu equipo.
          </DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-6">
          <div className="flex flex-col gap-2">
            <Label htmlFor="agent-name">Nombre del asistente</Label>
            <Input
              id="agent-name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Asistente"
              maxLength={40}
            />
          </div>

          <div className="flex flex-col gap-2">
            <Label>Personalidad</Label>
            <RadioGroup
              value={personality}
              onValueChange={(v) => setPersonality(v as AgentPersonality)}
            >
              {PERSONALITIES.map((p) => (
                <Label
                  key={p.value}
                  htmlFor={`agent-personality-${p.value}`}
                  className={cn(
                    "flex cursor-pointer items-start gap-3 rounded-md border p-3 font-normal",
                    personality === p.value ? "border-foreground/30 bg-accent/50" : "border-border",
                  )}
                >
                  <RadioGroupItem
                    value={p.value}
                    id={`agent-personality-${p.value}`}
                    className="mt-0.5"
                  />
                  <div className="flex flex-col gap-0.5">
                    <span className="text-sm font-medium">{p.label}</span>
                    <span className="text-xs text-muted-foreground">{p.desc}</span>
                  </div>
                </Label>
              ))}
            </RadioGroup>
          </div>
        </div>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => setOpen(false)}>
            Cancelar
          </Button>
          <Button type="button" onClick={handleSave} disabled={update.isPending || !data}>
            {update.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
            Guardar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
