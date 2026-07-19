"use client"

import * as React from "react"
import { toast } from "sonner"
import { Loader2, Save, Plus, LayoutGrid, MoveDiagonal2 } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { EmptyState } from "@/components/empty-state"
import { Badge } from "@/components/ui/badge"

import { CanvasSpaceBlock, defaultSize } from "@/components/spaces/canvas-space-block"
import { useSpaceSectors } from "@/hooks/use-space-sectors"
import {
  useSpaces,
  useCreateSpace,
  useSaveSpaceLayout,
  type Space,
  type SpaceShape,
  type LayoutPosition,
} from "@/hooks/use-spaces"

/**
 * Editor visual del local — canvas por sector con los espacios como formas
 * arrastrables (posicionamiento absoluto + drag nativo vía react-rnd, mismo
 * patrón técnico que el editor de plantillas de impresión,
 * `components/print-templates/template-editor.tsx`; sin librerías nuevas).
 *
 * Unidades del canvas: **píxeles crudos 1:1**, no mm — a diferencia del
 * editor de impresión (que escala a un papel físico), acá el "papel" es un
 * local sin dimensión real conocida, así que posx/posy/width/height se
 * guardan y renderizan en la misma unidad arbitraria de canvas (grid snap de
 * 10px). Si más adelante se necesita imprimir el plano a escala real, se
 * puede agregar un factor de conversión sin tocar el modelo.
 *
 * Toggle "Vista grilla / Vista layout": layout = el canvas editable de
 * abajo; grilla = preview read-only de cómo se ve el espacio en el POS cuando
 * NO tiene posición custom (grilla numerada por sector, fallback default).
 */
export function LayoutEditor({ outletId }: { outletId: string }) {
  const { data: sectorsData } = useSpaceSectors(outletId)
  const sectors = sectorsData?.sectors ?? []

  const [sectorId, setSectorId] = React.useState<string>("")
  React.useEffect(() => {
    if (!sectorId && sectors.length > 0) setSectorId(sectors[0].id)
  }, [sectors, sectorId])

  const { data: tablesData, isLoading } = useSpaces(outletId, sectorId || undefined)
  const allTables = React.useMemo(() => tablesData?.spaces ?? [], [tablesData])

  const createTable = useCreateSpace()
  const saveLayout = useSaveSpaceLayout()

  // Copia local editable — el canvas no muta el server hasta "Guardar".
  const [draft, setDraft] = React.useState<Record<string, Space>>({})
  React.useEffect(() => {
    const next: Record<string, Space> = {}
    for (const t of allTables) next[t.id] = t
    setDraft(next)
  }, [allTables])

  const [selectedId, setSelectedId] = React.useState<string | null>(null)
  const [mode, setMode] = React.useState<"layout" | "grid">("layout")
  const [dirty, setDirty] = React.useState(false)

  const draftList = Object.values(draft)
  const placed = draftList.filter((t) => t.posX !== null && t.posY !== null)
  const unplaced = draftList.filter((t) => t.posX === null || t.posY === null)

  function patchTable(id: string, patch: Partial<Space>) {
    setDraft((prev) => (prev[id] ? { ...prev, [id]: { ...prev[id], ...patch } } : prev))
    setDirty(true)
  }

  function placeOnCanvas(table: Space) {
    const size = defaultSize(table.shape)
    // Cascada simple para que los espacios recién agregados no se apilen exactamente encima.
    const offset = (placed.length % 6) * 20
    patchTable(table.id, {
      posX: 40 + offset,
      posY: 40 + offset,
      width: table.width ?? size.width,
      height: table.height ?? size.height,
      rotation: table.rotation ?? 0,
    })
  }

  function removeFromLayout(id: string) {
    patchTable(id, { posX: null, posY: null })
    setSelectedId(null)
  }

  function rotateTable(id: string) {
    const t = draft[id]
    if (!t) return
    patchTable(id, { rotation: (t.rotation + 45) % 360 })
  }

  async function addDecor(shape: SpaceShape) {
    if (!sectorId) {
      toast.error("Elegí un sector primero")
      return
    }
    try {
      const created = await createTable.mutateAsync({
        outletId,
        sectorId,
        name: shape === "bar" ? "Barra" : shape === "decor_plant" ? "Planta" : "Pared",
        shape,
        seats: 0,
      })
      const size = defaultSize(shape)
      setDraft((prev) => ({
        ...prev,
        [created.id]: { ...created, posX: 40, posY: 40, width: size.width, height: size.height },
      }))
      setDirty(true)
    } catch (e) {
      toast.error("No se pudo agregar el bloque", { description: e instanceof Error ? e.message : undefined })
    }
  }

  async function handleSave() {
    const positions: LayoutPosition[] = placed.map((t) => ({
      tableId: t.id,
      posX: t.posX,
      posY: t.posY,
      width: t.width,
      height: t.height,
      rotation: t.rotation,
      shape: t.shape,
    }))
    // Espacios quitados del layout también viajan (posX/posY null) para persistir la remoción.
    const removed: LayoutPosition[] = unplaced
      .filter((t) => allTables.find((orig) => orig.id === t.id && orig.posX !== null))
      .map((t) => ({ tableId: t.id, posX: null, posY: null, width: null, height: null }))

    try {
      await saveLayout.mutateAsync({ outletId, positions: [...positions, ...removed] })
      toast.success("Layout guardado")
      setDirty(false)
    } catch (e) {
      toast.error("No se pudo guardar el layout", { description: e instanceof Error ? e.message : undefined })
    }
  }

  const canvasWidth = 900
  const canvasHeight = 600

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <Select value={sectorId} onValueChange={(v) => { setSectorId(v); setSelectedId(null) }}>
            <SelectTrigger className="h-9 w-[200px]">
              <SelectValue placeholder="Elegí un sector" />
            </SelectTrigger>
            <SelectContent>
              {sectors.map((s) => (
                <SelectItem key={s.id} value={s.id}>{s.name}</SelectItem>
              ))}
            </SelectContent>
          </Select>

          <Tabs value={mode} onValueChange={(v) => setMode(v as "layout" | "grid")}>
            <TabsList>
              <TabsTrigger value="layout" className="gap-1.5">
                <MoveDiagonal2 className="size-3.5" />
                Layout
              </TabsTrigger>
              <TabsTrigger value="grid" className="gap-1.5">
                <LayoutGrid className="size-3.5" />
                Grilla (preview POS)
              </TabsTrigger>
            </TabsList>
          </Tabs>
        </div>

        {mode === "layout" && (
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" onClick={() => addDecor("bar")} disabled={!sectorId}>
              <Plus className="size-3.5" /> Barra
            </Button>
            <Button variant="outline" size="sm" onClick={() => addDecor("decor_wall")} disabled={!sectorId}>
              <Plus className="size-3.5" /> Pared
            </Button>
            <Button variant="outline" size="sm" onClick={() => addDecor("decor_plant")} disabled={!sectorId}>
              <Plus className="size-3.5" /> Planta
            </Button>
            <Button size="sm" onClick={handleSave} disabled={!dirty || saveLayout.isPending}>
              {saveLayout.isPending ? <Loader2 className="size-3.5 animate-spin" /> : <Save className="size-3.5" />}
              Guardar layout
            </Button>
          </div>
        )}
      </div>

      {!sectorId ? (
        <EmptyState
          icon={LayoutGrid}
          title="Sin sectores"
          description="Creá un sector arriba para empezar a diseñar el local."
          showMarquee={false}
        />
      ) : isLoading ? (
        <p className="text-sm text-muted-foreground">Cargando espacios…</p>
      ) : mode === "grid" ? (
        <GridPreview tables={draftList} />
      ) : (
        <div className="flex gap-4">
          {/* Canvas */}
          <div
            className="relative shrink-0 overflow-auto rounded-md border bg-muted/30"
            style={{ width: canvasWidth, height: canvasHeight, maxWidth: "100%" }}
            onMouseDown={() => setSelectedId(null)}
          >
            <div className="relative" style={{ width: canvasWidth, height: canvasHeight }}>
              {placed.map((t) => (
                <CanvasSpaceBlock
                  key={t.id}
                  table={t}
                  selected={selectedId === t.id}
                  onSelect={() => setSelectedId(t.id)}
                  onChange={(patch) => patchTable(t.id, patch)}
                  onRotate={() => rotateTable(t.id)}
                  onRemoveFromLayout={() => removeFromLayout(t.id)}
                />
              ))}
              {placed.length === 0 && (
                <div className="flex h-full w-full items-center justify-center text-sm text-muted-foreground">
                  Arrastrá espacios desde la lista para empezar a armar el local.
                </div>
              )}
            </div>
          </div>

          {/* Sidebar de espacios sin posicionar */}
          <div className="w-56 shrink-0 space-y-2">
            <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
              Sin ubicar ({unplaced.filter((t) => t.seats > 0).length})
            </p>
            {unplaced.filter((t) => t.seats > 0).length === 0 ? (
              <p className="text-xs text-muted-foreground">Todos los espacios del sector están en el layout.</p>
            ) : (
              <div className="flex flex-col gap-1.5">
                {unplaced.filter((t) => t.seats > 0).map((t) => (
                  <button
                    key={t.id}
                    type="button"
                    onClick={() => placeOnCanvas(t)}
                    className="flex items-center justify-between rounded-md border px-2.5 py-1.5 text-sm hover:bg-accent"
                  >
                    <span>{t.name}</span>
                    <Badge variant="secondary">{t.seats}p</Badge>
                  </button>
                ))}
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  )
}

/**
 * Preview read-only de la grilla numerada default — lo que el POS muestra
 * cuando el espacio NO tiene posición custom (posX/posY null). Orden por
 * `sort`/nombre, wrap automático en columnas fijas.
 */
function GridPreview({ tables }: { tables: Space[] }) {
  const real = tables.filter((t) => t.seats > 0 || !["bar", "decor_wall", "decor_plant"].includes(t.shape))
  if (real.length === 0) {
    return (
      <EmptyState
        icon={LayoutGrid}
        title="Sin espacios en este sector"
        description="Creá espacios arriba con el alta rápida para verlos acá."
        showMarquee={false}
      />
    )
  }
  return (
    <div className="grid grid-cols-6 gap-3 sm:grid-cols-8">
      {real.map((t) => (
        <div
          key={t.id}
          className="flex aspect-square flex-col items-center justify-center gap-0.5 rounded-md border bg-card text-xs"
        >
          <span className="font-semibold">{t.name}</span>
          <span className="text-[10px] text-muted-foreground">{t.seats}p</span>
        </div>
      ))}
    </div>
  )
}
