"use client"

import * as React from "react"
import { toast } from "sonner"
import { Search, SearchX } from "lucide-react"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import { Switch } from "@/components/ui/switch"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Skeleton } from "@/components/ui/skeleton"
import { Separator } from "@/components/ui/separator"
import { Input } from "@/components/ui/input"
import { useModules, useToggleModule } from "@/hooks/use-modules"
import {
  MODULES_CATALOG,
  MODULE_CATEGORIES,
  type ModuleCatalogEntry,
} from "@/lib/modules-catalog"
import type { ModulesMap } from "@/lib/types/module"
import { ModuleConfigDialog } from "@/components/modules/module-config-dialog"
import { EmptyState } from "@/components/empty-state"

// Marketplace de módulos. Pensado para vivir tanto en una página standalone
// (/modules) como en una sección del modal /settings — por eso no incluye el
// `<h1>` / descripción de página; el caller los aporta si los necesita.

interface ModuleCardProps {
  entry: ModuleCatalogEntry
  modulesMap: ModulesMap | undefined
  isPendingToggle: boolean
  onToggle: (key: string, enabled: boolean) => void
  onConfigure: (entry: ModuleCatalogEntry) => void
}

function ModuleCard({
  entry,
  modulesMap,
  isPendingToggle,
  onToggle,
  onConfigure,
}: ModuleCardProps) {
  const Icon = entry.icon
  const isSoon = entry.status === "soon"
  const moduleState = modulesMap?.[entry.key]
  const enabled = moduleState?.enabled ?? false
  const hasConfig = entry.configKind !== "none" && !isSoon

  // Card compacta: con 3 cols dentro del modal (~780px content area) cada
  // card queda ~245px; tipografías base no entraban. Mantener gap interno
  // breve para que el switch quede a la altura del icono.
  return (
    <Card
      className={
        isSoon
          ? "opacity-60 select-none"
          : "transition-shadow hover:shadow-md"
      }
    >
      <CardHeader className="gap-2 pb-2">
        <div className="flex items-start justify-between gap-2">
          <div className="flex size-9 shrink-0 items-center justify-center rounded-lg border bg-muted/40">
            <Icon className="size-4 text-muted-foreground" />
          </div>
          {isSoon ? (
            <Badge variant="secondary" className="shrink-0 text-[10px]">
              Próximamente
            </Badge>
          ) : (
            <Switch
              checked={enabled}
              disabled={isPendingToggle || modulesMap === undefined}
              onCheckedChange={(checked) => onToggle(entry.key, checked)}
              aria-label={`${entry.title} ${enabled ? "activado" : "desactivado"}`}
            />
          )}
        </div>
        <CardTitle className="text-sm font-semibold leading-tight">
          {entry.title}
        </CardTitle>
        <CardDescription className="text-xs leading-snug">
          {entry.description}
        </CardDescription>
      </CardHeader>

      {hasConfig && enabled && (
        <CardContent className="pt-0">
          <Button
            variant="outline"
            size="sm"
            onClick={() => onConfigure(entry)}
            className="h-7 w-full text-xs"
          >
            Configurar
          </Button>
        </CardContent>
      )}
    </Card>
  )
}

function SkeletonGrid({ count = 6 }: { count?: number }) {
  return (
    <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3">
      {Array.from({ length: count }).map((_, i) => (
        <Card key={i}>
          <CardHeader className="gap-2 pb-2">
            <div className="flex items-start justify-between gap-2">
              <Skeleton className="size-9 rounded-lg" />
              <Skeleton className="h-5 w-9 rounded-full" />
            </div>
            <Skeleton className="h-3.5 w-3/4" />
            <Skeleton className="h-3 w-full" />
            <Skeleton className="h-3 w-4/5" />
          </CardHeader>
        </Card>
      ))}
    </div>
  )
}

// Normaliza acentos para que "fidelizacion" matchee "Fidelización", etc.
function normalize(s: string): string {
  return s
    .toLowerCase()
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
}

export function ModulesPanel() {
  const { data: modulesMap, isLoading } = useModules()
  const toggleModule = useToggleModule()
  const [query, setQuery] = React.useState("")

  const [configDialog, setConfigDialog] = React.useState<{
    open: boolean
    entry: ModuleCatalogEntry | null
  }>({ open: false, entry: null })

  function handleToggle(key: string, enabled: boolean) {
    toggleModule.mutate(
      { key, enabled },
      {
        onSuccess: () => {
          toast.success(enabled ? "Módulo activado" : "Módulo desactivado")
        },
        onError: (err) => {
          toast.error("No se pudo cambiar el módulo", {
            description: err.message,
          })
        },
      },
    )
  }

  function handleConfigure(entry: ModuleCatalogEntry) {
    setConfigDialog({ open: true, entry })
  }

  // Filtro client-side por título + descripción + categoría. Acento-insensible.
  const filtered = React.useMemo(() => {
    const q = normalize(query.trim())
    if (!q) return MODULES_CATALOG
    return MODULES_CATALOG.filter((m) => {
      const haystack = normalize(
        `${m.title} ${m.description} ${m.category}`,
      )
      return haystack.includes(q)
    })
  }, [query])

  const noResults = !isLoading && filtered.length === 0

  return (
    <div className="space-y-6">
      {/* Buscador sticky en el tope. icon-inside Input para no agregar otro */}
      {/* primitive nuevo — el Search queda como elemento absoluto sobre el     */}
      {/* padding-left del Input.                                              */}
      <div className="relative">
        <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          type="search"
          placeholder="Buscar módulos…"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          className="pl-9"
          aria-label="Buscar módulos"
        />
      </div>

      {noResults ? (
        <EmptyState
          icon={SearchX}
          showMarquee={false}
          title="Sin resultados"
          description={`Ningún módulo coincide con "${query.trim()}".`}
        />
      ) : (
        MODULE_CATEGORIES.map((category) => {
          const entries = filtered.filter((m) => m.category === category)
          if (entries.length === 0) return null

          return (
            <section key={category} className="space-y-3">
              <h2 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                {category}
              </h2>

              {isLoading ? (
                <SkeletonGrid count={entries.length} />
              ) : (
                <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3">
                  {entries.map((entry) => (
                    <ModuleCard
                      key={entry.key}
                      entry={entry}
                      modulesMap={modulesMap}
                      isPendingToggle={toggleModule.isPending}
                      onToggle={handleToggle}
                      onConfigure={handleConfigure}
                    />
                  ))}
                </div>
              )}
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
          currentConfig={
            modulesMap?.[configDialog.entry.key]?.config
          }
        />
      )}
    </div>
  )
}
