"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import { toast } from "sonner"
import { Search, SearchX } from "lucide-react"
import { Card } from "@/components/ui/card"
import { Switch } from "@/components/ui/switch"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Skeleton } from "@/components/ui/skeleton"
import { Input } from "@/components/ui/input"
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { useModules, useToggleModule } from "@/hooks/use-modules"
import {
  MODULE_CATEGORIES,
  catalogByKind,
  type ModuleCatalogEntry,
  type ModuleKind,
} from "@/lib/modules-catalog"
import type { ModulesMap } from "@/lib/types/module"
import { ModuleConfigDialog } from "@/components/modules/module-config-dialog"
import { EmptyState } from "@/components/empty-state"
import { cn } from "@/lib/utils"

/**
 * Administrador del catálogo — el MISMO componente pinta `/modules` y
 * `/integraciones`; lo único que cambia es el `kind` que filtra y el copy.
 * Nunca duplicar este panel para una vista nueva: sumar el `kind` acá.
 *
 * Layout (reemplaza la grilla de cards de 2026-08, owner 2026-08-24): una
 * lista de filas por categoría dentro de una sola Card. La grilla obligaba a
 * barrer tres columnas para saber qué estaba activo y escondía el estado de
 * configuración; la fila pone en una línea nombre, estado y switch, así el
 * ojo baja una sola columna. Además `context/20` §5 prohíbe una `<Card>` por
 * fila de listado.
 *
 * razón: NO usa `<DataTable>` pese a pasar las 10 filas (`context/20` §4).
 * Esto no es un listado de datos — no hay nada que ordenar, exportar ni
 * paginar, y las filas van agrupadas por categoría, que DataTable no sabe
 * hacer. Cae en el caso "lista vertical `divide-y`" de la misma sección: cada
 * grupo tiene entre 2 y 6 filas.
 */

type StatusFilter = "all" | "on" | "off"

interface PanelCopy {
  searchPlaceholder: string
  searchLabel: string
  noResults: string
  emptyFiltered: string
  toastOn: string
  toastOff: string
  toastError: string
  summary: (active: number, total: number) => string
}

const COPY: Record<ModuleKind, PanelCopy> = {
  module: {
    searchPlaceholder: "Buscar módulos…",
    searchLabel: "Buscar módulos",
    noResults: "Ningún módulo coincide con la búsqueda.",
    emptyFiltered: "Ningún módulo en este estado.",
    toastOn: "Módulo activado",
    toastOff: "Módulo desactivado",
    toastError: "No se pudo cambiar el módulo",
    summary: (active, total) => `${active} de ${total} módulos activos`,
  },
  integration: {
    searchPlaceholder: "Buscar integraciones…",
    searchLabel: "Buscar integraciones",
    noResults: "Ninguna integración coincide con la búsqueda.",
    emptyFiltered: "Ninguna integración en este estado.",
    toastOn: "Integración activada",
    toastOff: "Integración desactivada",
    toastError: "No se pudo cambiar la integración",
    summary: (active, total) => `${active} de ${total} integraciones activas`,
  },
}

interface ModuleRowProps {
  entry: ModuleCatalogEntry
  modulesMap: ModulesMap | undefined
  isPendingToggle: boolean
  onToggle: (key: string, enabled: boolean) => void
  onConfigure: (entry: ModuleCatalogEntry) => void
}

function ModuleRow({
  entry,
  modulesMap,
  isPendingToggle,
  onToggle,
  onConfigure,
}: ModuleRowProps) {
  const isSoon = entry.status === "soon"
  const moduleState = modulesMap?.[entry.key]
  const enabled = moduleState?.enabled ?? false
  // configHref (navegación a página propia) es una señal de "tiene config"
  // igual de válida que configKind — ver docblock de ModuleCatalogEntry.
  const hasConfig = (entry.configKind !== "none" || !!entry.configHref) && !isSoon
  const configStatus =
    enabled && entry.configStatus
      ? entry.configStatus(moduleState?.config)
      : null
  const Icon = entry.icon

  return (
    <div
      className={cn(
        "flex items-center gap-4 px-5 py-3.5",
        isSoon && "opacity-60 select-none",
      )}
    >
      {/* Ícono guía: en una lista densa es el ancla para encontrar una fila */}
      {/* sin leer. No es un header de Card ni un h1 — §5 no aplica.        */}
      <div className="flex size-9 shrink-0 items-center justify-center rounded-md border bg-muted/40 text-muted-foreground">
        <Icon className="size-4" />
      </div>

      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
          <span className="text-sm font-medium">{entry.title}</span>
          {isSoon && <Badge variant="secondary">Próximamente</Badge>}
          {configStatus && (
            <Badge variant={configStatus.complete ? "secondary" : "destructive"}>
              {configStatus.label}
            </Badge>
          )}
        </div>
        <p className="mt-0.5 line-clamp-2 text-sm text-muted-foreground">
          {entry.description}
        </p>
      </div>

      <div className="flex shrink-0 items-center gap-2">
        {hasConfig && enabled && (
          <Button variant="outline" size="sm" onClick={() => onConfigure(entry)}>
            Configurar
          </Button>
        )}
        {!isSoon && (
          <Switch
            checked={enabled}
            disabled={isPendingToggle || modulesMap === undefined}
            onCheckedChange={(checked) => onToggle(entry.key, checked)}
            aria-label={`${entry.title} ${enabled ? "activado" : "desactivado"}`}
          />
        )}
      </div>
    </div>
  )
}

function SkeletonRows({ count = 4 }: { count?: number }) {
  return (
    <Card className="py-0">
      <div className="divide-y">
        {Array.from({ length: count }).map((_, i) => (
          <div key={i} className="flex items-center gap-4 px-5 py-3.5">
            <Skeleton className="size-9 shrink-0 rounded-md" />
            <div className="min-w-0 flex-1 space-y-1.5">
              <Skeleton className="h-4 w-40" />
              <Skeleton className="h-3.5 w-3/4" />
            </div>
            <Skeleton className="h-5 w-9 shrink-0 rounded-full" />
          </div>
        ))}
      </div>
    </Card>
  )
}

// Normaliza acentos para que "fidelizacion" matchee "Fidelización", etc.
function normalize(s: string): string {
  return s
    .toLowerCase()
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
}

export function ModuleCatalogPanel({ kind }: { kind: ModuleKind }) {
  const router = useRouter()
  // `isError` se lee y se PINTA: sin esto, una lectura fallida caía en el
  // `?? false` de cada fila y el catálogo entero se mostraba "apagado" —
  // indistinguible de un tenant sin módulos, y encima con los switches
  // deshabilitados sin decir por qué (reporte del owner 2026-08-26). Un
  // listado de estado no puede inventar el estado cuando no lo pudo leer.
  const { data: modulesMap, isLoading, isError, error, refetch } = useModules()
  const toggleModule = useToggleModule()
  const [query, setQuery] = React.useState("")
  const [statusFilter, setStatusFilter] = React.useState<StatusFilter>("all")
  const copy = COPY[kind]

  const [configDialog, setConfigDialog] = React.useState<{
    open: boolean
    entry: ModuleCatalogEntry | null
  }>({ open: false, entry: null })

  const entries = React.useMemo(() => catalogByKind(kind), [kind])

  function handleToggle(key: string, enabled: boolean) {
    toggleModule.mutate(
      { key, enabled },
      {
        onSuccess: () => {
          toast.success(enabled ? copy.toastOn : copy.toastOff)
        },
        onError: (err) => {
          toast.error(copy.toastError, { description: err.message })
        },
      },
    )
  }

  function handleConfigure(entry: ModuleCatalogEntry) {
    if (entry.configHref) {
      router.push(entry.configHref)
      return
    }
    setConfigDialog({ open: true, entry })
  }

  // Los "Próximamente" no se pueden activar: quedan fuera del denominador.
  const toggleable = entries.filter((e) => e.status !== "soon")
  const activeCount = toggleable.filter(
    (e) => modulesMap?.[e.key]?.enabled ?? false,
  ).length

  // Filtro client-side: texto (título + descripción + categoría, acento-
  // insensible) y estado activo/inactivo.
  const filtered = React.useMemo(() => {
    const q = normalize(query.trim())
    return entries.filter((m) => {
      if (q) {
        const haystack = normalize(`${m.title} ${m.description} ${m.category}`)
        if (!haystack.includes(q)) return false
      }
      if (statusFilter === "all") return true
      const enabled = modulesMap?.[m.key]?.enabled ?? false
      return statusFilter === "on" ? enabled : !enabled
    })
  }, [entries, query, statusFilter, modulesMap])

  const noResults = !isLoading && filtered.length === 0

  return (
    <div className="flex flex-col gap-6">
      {/* Controles: buscar, filtrar por estado y ver el total activo */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="relative sm:w-72">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            type="search"
            placeholder={copy.searchPlaceholder}
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            className="pl-9"
            aria-label={copy.searchLabel}
          />
        </div>

        <div className="flex items-center gap-3">
          <p className="hidden text-sm text-muted-foreground sm:block">
            {copy.summary(activeCount, toggleable.length)}
          </p>
          <Tabs
            value={statusFilter}
            onValueChange={(v) => setStatusFilter(v as StatusFilter)}
          >
            <TabsList>
              <TabsTrigger value="all">Todos</TabsTrigger>
              <TabsTrigger value="on">Activos</TabsTrigger>
              <TabsTrigger value="off">Inactivos</TabsTrigger>
            </TabsList>
          </Tabs>
        </div>
      </div>

      {isLoading ? (
        <SkeletonRows count={Math.min(entries.length, 6)} />
      ) : isError ? (
        <EmptyState
          icon={SearchX}
          ghost={false}
          title="No se pudo leer el estado de los módulos"
          description={
            error instanceof Error
              ? `${error.message}. Los interruptores no se muestran para no dar por apagado lo que no se pudo consultar.`
              : "Los interruptores no se muestran para no dar por apagado lo que no se pudo consultar."
          }
          actions={<Button onClick={() => refetch()}>Reintentar</Button>}
        />
      ) : noResults ? (
        <EmptyState
          icon={SearchX}
          ghost={false}
          title="Sin resultados"
          description={query.trim() ? copy.noResults : copy.emptyFiltered}
        />
      ) : (
        MODULE_CATEGORIES.map((category) => {
          const rows = filtered.filter((m) => m.category === category)
          if (rows.length === 0) return null

          return (
            <section key={category} className="flex flex-col gap-3">
              <h2 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                {category}
              </h2>
              <Card className="py-0">
                <div className="divide-y">
                  {rows.map((entry) => (
                    <ModuleRow
                      key={entry.key}
                      entry={entry}
                      modulesMap={modulesMap}
                      isPendingToggle={toggleModule.isPending}
                      onToggle={handleToggle}
                      onConfigure={handleConfigure}
                    />
                  ))}
                </div>
              </Card>
            </section>
          )
        })
      )}

      {configDialog.entry && (
        <ModuleConfigDialog
          open={configDialog.open}
          onOpenChange={(open) =>
            setConfigDialog((prev) => ({ ...prev, open }))
          }
          moduleKey={configDialog.entry.key}
          moduleTitle={configDialog.entry.title}
          configKind={configDialog.entry.configKind}
          currentConfig={modulesMap?.[configDialog.entry.key]?.config}
        />
      )}
    </div>
  )
}
