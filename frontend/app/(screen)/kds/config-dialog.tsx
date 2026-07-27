"use client"

import * as React from "react"
import { Settings } from "lucide-react"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog"
import { Label } from "@/components/ui/label"
import { Input } from "@/components/ui/input"
import { Checkbox } from "@/components/ui/checkbox"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Switch } from "@/components/ui/switch"
import { KDS_CARDS_PER_SCREEN, type KdsCardsPerScreen, type KdsConfig } from "@/lib/kds/config"
import { playKdsChime, unlockKdsSound } from "@/lib/kds/sound"

/**
 * Configuración local de esta pantalla de cocina. Antes era un `Sheet`; se pasó
 * a `Dialog` centrado por la convención del proyecto (MODALS, nunca
 * Sheet/Drawer) — no había razón documentada para la excepción.
 *
 * El botón "Probar" cumple doble función: valida el volumen y, sobre todo, ES
 * el gesto de usuario que la política de autoplay exige para habilitar el
 * audio (ver lib/kds/sound.ts). Sin él, activar el switch no haría sonar nada.
 */

interface Station {
  id: string
  name: string
}

interface ConfigDialogProps {
  config: KdsConfig
  stations: Station[]
  onChange: (config: KdsConfig) => void
}

export function KdsConfigDialog({ config, stations, onChange }: ConfigDialogProps) {
  const [open, setOpen] = React.useState(false)
  const [draft, setDraft] = React.useState<KdsConfig>(config)

  React.useEffect(() => {
    if (open) setDraft(config)
  }, [open, config])

  function toggleStation(id: string) {
    setDraft((d) => ({
      ...d,
      stationIds: d.stationIds.includes(id)
        ? d.stationIds.filter((s) => s !== id)
        : [...d.stationIds, id],
    }))
  }

  async function testSound() {
    const ok = await unlockKdsSound()
    if (ok) playKdsChime()
  }

  function save() {
    onChange({ ...draft, lateMin: Math.max(draft.lateMin, draft.warnMin) })
    setOpen(false)
  }

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button variant="ghost" size="icon" className="size-11" aria-label="Ajustes del KDS">
          <Settings className="size-6" />
        </Button>
      </DialogTrigger>
      <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Ajustes del KDS</DialogTitle>
          <DialogDescription>
            Config de esta pantalla — se guarda en el dispositivo, no afecta otras.
          </DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-6">
          <div className="space-y-1.5">
            <Label htmlFor="kds-name">Nombre del KDS</Label>
            <Input
              id="kds-name"
              value={draft.name}
              maxLength={40}
              placeholder="Parrilla, Barra, Cocina fría…"
              onChange={(e) => setDraft((d) => ({ ...d, name: e.target.value }))}
            />
            <p className="text-sm text-muted-foreground">
              Se muestra en la barra inferior. Vacío = el nombre de la sucursal.
            </p>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-1.5">
              <Label>Comandas por pantalla</Label>
              <Select
                value={String(draft.cardsPerScreen)}
                onValueChange={(v) =>
                  setDraft((d) => ({ ...d, cardsPerScreen: Number(v) as KdsCardsPerScreen }))
                }
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {KDS_CARDS_PER_SCREEN.map((n) => (
                    <SelectItem key={n} value={String(n)}>
                      {n}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-1.5">
              <Label>Orden</Label>
              <Select
                value={draft.sortOrder}
                onValueChange={(v) =>
                  setDraft((d) => ({ ...d, sortOrder: v as KdsConfig["sortOrder"] }))
                }
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="oldest">Más antiguas primero</SelectItem>
                  <SelectItem value="newest">Más nuevas primero</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-1.5">
              <Label htmlFor="warn-min">Alerta ámbar (min)</Label>
              <Input
                id="warn-min"
                type="number"
                min={1}
                value={draft.warnMin}
                onChange={(e) =>
                  setDraft((d) => ({ ...d, warnMin: Number(e.target.value) || d.warnMin }))
                }
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="late-min">Alerta roja (min)</Label>
              <Input
                id="late-min"
                type="number"
                min={1}
                value={draft.lateMin}
                onChange={(e) =>
                  setDraft((d) => ({ ...d, lateMin: Number(e.target.value) || d.lateMin }))
                }
              />
            </div>
          </div>

          <div className="flex items-center justify-between gap-4">
            <div className="space-y-1">
              <Label htmlFor="kds-sound">Sonido al entrar una comanda</Label>
              <p className="text-sm text-muted-foreground">
                El navegador solo permite reproducir audio después de un toque en la pantalla. Usá
                &quot;Probar&quot; para habilitarlo en este dispositivo.
              </p>
            </div>
            <div className="flex shrink-0 items-center gap-2">
              <Button type="button" variant="outline" onClick={() => void testSound()}>
                Probar
              </Button>
              <Switch
                id="kds-sound"
                checked={draft.soundOnNew}
                onCheckedChange={(v) => setDraft((d) => ({ ...d, soundOnNew: v }))}
              />
            </div>
          </div>

          <div className="space-y-1.5">
            <Label>Estaciones visibles</Label>
            <p className="text-sm text-muted-foreground">Sin selección = todas las estaciones.</p>
            <div className="flex flex-col gap-2">
              {stations.map((s) => (
                <Label key={s.id} className="flex items-center gap-2 font-normal">
                  <Checkbox
                    checked={draft.stationIds.includes(s.id)}
                    onCheckedChange={() => toggleStation(s.id)}
                  />
                  {s.name}
                </Label>
              ))}
              {stations.length === 0 && (
                <p className="text-sm text-muted-foreground">
                  Sin estaciones configuradas para esta sucursal.
                </p>
              )}
            </div>
          </div>
        </div>

        <DialogFooter>
          <Button onClick={save}>Guardar</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
