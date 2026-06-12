"use client"

import * as React from "react"
import { toast } from "sonner"
import { Loader2, Star, Warehouse } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import {
  useItemLocations,
  useSyncItemLocations,
  useTaxonomiesByType,
} from "@/hooks/use-items"
import { useOutlets } from "@/hooks/use-outlets"
import { cn } from "@/lib/utils"

interface Props {
  itemId: string
}

/**
 * Editor de depósitos por item. Cada sucursal lista sus depósitos (taxonomies
 * de type='location' con outletId = ese outlet). El usuario tildea en cuáles
 * vive el item; entre los tildeados de cada sucursal, uno se marca como
 * default (estrella) — es el que se usa al vender/producir si no se
 * especifica otro.
 *
 * Save es bulk: cuando el usuario aprieta Guardar, hacemos un sync con la
 * lista completa de locationIds + el defaultLocationId (uno solo, el primer
 * default que el usuario haya marcado — el backend setea isDefault por outlet).
 */
export function LocationsEditor({ itemId }: Props) {
  const { data, isLoading } = useItemLocations(itemId)
  const { data: outletsResp, isLoading: outletsLoading } = useOutlets()
  const { data: allLocations, isLoading: locsLoading } = useTaxonomiesByType("location")
  const sync = useSyncItemLocations()

  // Estado local en draft (se sincroniza al cargar el server-state).
  // checked: Set de locationIds marcados
  // defaults: Map outletId → locationId que es default en ese outlet
  const [checked, setChecked] = React.useState<Set<string>>(new Set())
  const [defaults, setDefaults] = React.useState<Record<string, string>>({})
  const [hydrated, setHydrated] = React.useState(false)

  React.useEffect(() => {
    if (!data) return
    const c = new Set<string>()
    const d: Record<string, string> = {}
    for (const loc of data.locations) {
      c.add(loc.locationId)
      if (loc.isDefault) d[loc.outletId] = loc.locationId
    }
    setChecked(c)
    setDefaults(d)
    setHydrated(true)
  }, [data])

  // Locations agrupadas por outlet
  const byOutlet = React.useMemo(() => {
    const map: Record<string, typeof allLocations> = {}
    for (const loc of allLocations ?? []) {
      if (!loc.outletId) continue
      ;(map[loc.outletId] ??= []).push(loc)
    }
    return map
  }, [allLocations])

  const outlets = outletsResp?.rows ?? []

  if (isLoading || outletsLoading || locsLoading) {
    return (
      <div className="flex h-20 items-center justify-center text-sm text-muted-foreground">
        <Loader2 className="mr-2 size-4 animate-spin" />
        Cargando depósitos…
      </div>
    )
  }

  if (outlets.length === 0) {
    return (
      <div className="rounded-md border border-dashed bg-muted/20 px-4 py-6 text-center text-xs text-muted-foreground">
        No hay sucursales configuradas. Creá al menos una sucursal en Configuración
        para asignar depósitos.
      </div>
    )
  }

  const toggleLocation = (outletId: string, locationId: string, isChecked: boolean) => {
    const next = new Set(checked)
    if (isChecked) {
      next.add(locationId)
      // Si era el primero tildeado en este outlet, marcarlo como default
      setDefaults((d) => (d[outletId] ? d : { ...d, [outletId]: locationId }))
    } else {
      next.delete(locationId)
      // Si era el default, quitar el default de ese outlet
      setDefaults((d) => {
        if (d[outletId] !== locationId) return d
        const { [outletId]: _, ...rest } = d
        return rest
      })
    }
    setChecked(next)
  }

  const setDefault = (outletId: string, locationId: string) => {
    setDefaults((d) => ({ ...d, [outletId]: locationId }))
  }

  const onSave = async () => {
    try {
      const locationIds = Array.from(checked)
      // Mandamos el primer defaultLocationId — backend lo aplica por outlet (cada
      // outlet con un default propio se setea con sus calls subsiguientes).
      const defaultLocationId = Object.values(defaults)[0] ?? null
      await sync.mutateAsync({ itemId, locationIds, defaultLocationId })
      // Para cada outlet con un default distinto, podríamos hacer un setDefault
      // explícito — el syncForItem actual solo acepta un defaultLocationId
      // único. Por ahora setea el primero; los otros quedan igual al estado
      // previo del backend. Mejora futura: extender el endpoint a un array.
      toast.success("Depósitos actualizados")
    } catch (e) {
      toast.error("No se pudieron guardar los depósitos", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  const isDirty = React.useMemo(() => {
    if (!hydrated || !data) return false
    const serverChecked = new Set(data.locations.map((l) => l.locationId))
    if (serverChecked.size !== checked.size) return true
    for (const id of checked) if (!serverChecked.has(id)) return true
    const serverDefaults: Record<string, string> = {}
    for (const l of data.locations) if (l.isDefault) serverDefaults[l.outletId] = l.locationId
    for (const o of Object.keys({ ...defaults, ...serverDefaults })) {
      if (defaults[o] !== serverDefaults[o]) return true
    }
    return false
  }, [hydrated, data, checked, defaults])

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-3">
        {outlets.map((o) => {
          const locs = byOutlet[o.id] ?? []
          const outletDefault = defaults[o.id] ?? null
          return (
            <div key={o.id} className="rounded-md border bg-card p-3">
              <div className="mb-2 flex items-center gap-2">
                <Warehouse className="size-3.5 text-muted-foreground" />
                <p className="text-sm font-medium">{o.name}</p>
              </div>
              {locs.length === 0 ? (
                <p className="rounded-md border border-dashed px-3 py-3 text-center text-xs text-muted-foreground">
                  Esta sucursal no tiene depósitos creados.
                </p>
              ) : (
                <div className="flex flex-col gap-1">
                  {locs.map((loc) => {
                    const isChecked = checked.has(loc.id)
                    const isDefault = outletDefault === loc.id
                    return (
                      <label
                        key={loc.id}
                        className={cn(
                          "flex items-center justify-between gap-2 rounded-md border bg-card px-3 py-2 text-sm transition",
                          isChecked
                            ? "border-primary/30 bg-primary/5"
                            : "border-transparent hover:bg-muted/40",
                        )}
                      >
                        <div className="flex items-center gap-2.5">
                          <Checkbox
                            checked={isChecked}
                            onCheckedChange={(v) => toggleLocation(o.id, loc.id, !!v)}
                            aria-label={`Vive en ${loc.name}`}
                          />
                          <span>{loc.name}</span>
                        </div>
                        {isChecked && (
                          <Button
                            type="button"
                            variant={isDefault ? "default" : "ghost"}
                            size="sm"
                            className={cn(
                              "h-7 gap-1.5 text-[10px]",
                              !isDefault && "text-muted-foreground hover:text-foreground",
                            )}
                            onClick={(e) => {
                              e.preventDefault()
                              setDefault(o.id, loc.id)
                            }}
                          >
                            <Star className={cn("size-3", isDefault && "fill-current")} />
                            {isDefault ? "Default" : "Marcar default"}
                          </Button>
                        )}
                      </label>
                    )
                  })}
                </div>
              )}
            </div>
          )
        })}
      </div>

      <div className="flex items-center justify-end gap-2">
        {sync.isPending && (
          <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
            <Loader2 className="size-3 animate-spin" />
            Guardando…
          </span>
        )}
        <Button
          type="button"
          onClick={onSave}
          disabled={!isDirty || sync.isPending}
        >
          Guardar depósitos
        </Button>
      </div>
    </div>
  )
}
