"use client"

import * as React from "react"
import { Settings } from "lucide-react"
import { Button } from "@/components/ui/button"
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet"
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
import type { KdsConfig } from "@/lib/kds/config"

interface Station {
  id: string
  name: string
}

interface ConfigSheetProps {
  config: KdsConfig
  stations: Station[]
  onChange: (config: KdsConfig) => void
}

export function KdsConfigSheet({ config, stations, onChange }: ConfigSheetProps) {
  const [open, setOpen] = React.useState(false)
  const [draft, setDraft] = React.useState<KdsConfig>(config)

  React.useEffect(() => { if (open) setDraft(config) }, [open, config])

  function toggleStation(id: string) {
    setDraft((d) => ({
      ...d,
      stationIds: d.stationIds.includes(id) ? d.stationIds.filter((s) => s !== id) : [...d.stationIds, id],
    }))
  }

  function save() {
    onChange(draft)
    setOpen(false)
  }

  return (
    <Sheet open={open} onOpenChange={setOpen}>
      <SheetTrigger asChild>
        <Button variant="ghost" size="icon" aria-label="Ajustes del KDS">
          <Settings className="size-5" />
        </Button>
      </SheetTrigger>
      <SheetContent side="right" className="sm:max-w-2xl overflow-y-auto">
        <SheetHeader>
          <SheetTitle>Ajustes del KDS</SheetTitle>
          <SheetDescription>Config de esta pantalla — se guarda en el dispositivo, no afecta otras.</SheetDescription>
        </SheetHeader>

        <div className="flex flex-col gap-6 px-4">
          <div className="space-y-1.5">
            <Label>Vista</Label>
            <Select
              value={draft.columnMode}
              onValueChange={(v) => setDraft((d) => ({ ...d, columnMode: v as KdsConfig["columnMode"] }))}
            >
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="status">Columnas por estado (Nuevas / En preparación / Listas)</SelectItem>
                <SelectItem value="stream">Single-stream (ordenado por antigüedad)</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1.5">
            <Label>Densidad</Label>
            <Select
              value={draft.density}
              onValueChange={(v) => setDraft((d) => ({ ...d, density: v as KdsConfig["density"] }))}
            >
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="comfortable">Confortable</SelectItem>
                <SelectItem value="compact">Compacta (más tarjetas por fila)</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-1.5">
              <Label htmlFor="warn-min">Alerta ámbar (min)</Label>
              <Input
                id="warn-min"
                type="number"
                min={1}
                value={draft.warnMin}
                onChange={(e) => setDraft((d) => ({ ...d, warnMin: Number(e.target.value) || d.warnMin }))}
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="late-min">Alerta roja (min)</Label>
              <Input
                id="late-min"
                type="number"
                min={1}
                value={draft.lateMin}
                onChange={(e) => setDraft((d) => ({ ...d, lateMin: Number(e.target.value) || d.lateMin }))}
              />
            </div>
          </div>

          <div className="space-y-1.5">
            <Label>Estaciones visibles</Label>
            <p className="text-sm text-muted-foreground">Sin selección = todas las estaciones.</p>
            <div className="flex flex-col gap-2">
              {stations.map((s) => (
                <label key={s.id} className="flex items-center gap-2 text-sm">
                  <Checkbox
                    checked={draft.stationIds.includes(s.id)}
                    onCheckedChange={() => toggleStation(s.id)}
                  />
                  {s.name}
                </label>
              ))}
              {stations.length === 0 && (
                <p className="text-sm text-muted-foreground">Sin estaciones configuradas para esta sucursal.</p>
              )}
            </div>
          </div>
        </div>

        <SheetFooter>
          <Button onClick={save}>Guardar</Button>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  )
}
