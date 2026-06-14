"use client"

import * as React from "react"
import { toast } from "sonner"
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
import { useModules, useToggleModule } from "@/hooks/use-modules"
import {
  MODULES_CATALOG,
  MODULE_CATEGORIES,
  type ModuleCatalogEntry,
} from "@/lib/modules-catalog"
import type { ModulesMap } from "@/lib/types/module"
import { ModuleConfigDialog } from "@/components/modules/module-config-dialog"

// ── ModuleCard ────────────────────────────────────────────────────────────────

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

  return (
    <Card
      className={
        isSoon
          ? "opacity-60 select-none"
          : "transition-shadow hover:shadow-md"
      }
    >
      <CardHeader className="pb-3">
        <div className="flex items-start justify-between gap-3">
          <div className="flex size-10 shrink-0 items-center justify-center rounded-lg border bg-muted/40">
            <Icon className="size-5 text-muted-foreground" />
          </div>
          {isSoon ? (
            <Badge variant="secondary" className="shrink-0">
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
        <CardTitle className="text-base leading-tight">{entry.title}</CardTitle>
        <CardDescription className="text-sm leading-snug">
          {entry.description}
        </CardDescription>
      </CardHeader>

      {hasConfig && enabled && (
        <CardContent className="pt-0">
          <Button
            variant="outline"
            size="sm"
            onClick={() => onConfigure(entry)}
            className="w-full"
          >
            Configurar
          </Button>
        </CardContent>
      )}
    </Card>
  )
}

// ── SkeletonGrid ──────────────────────────────────────────────────────────────

function SkeletonGrid({ count = 6 }: { count?: number }) {
  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {Array.from({ length: count }).map((_, i) => (
        <Card key={i}>
          <CardHeader className="pb-3">
            <div className="flex items-start justify-between gap-3">
              <Skeleton className="size-10 rounded-lg" />
              <Skeleton className="h-5 w-9 rounded-full" />
            </div>
            <Skeleton className="h-4 w-3/4" />
            <Skeleton className="h-3 w-full" />
            <Skeleton className="h-3 w-4/5" />
          </CardHeader>
        </Card>
      ))}
    </div>
  )
}

// ── Page ──────────────────────────────────────────────────────────────────────

export default function ModulesPage() {
  const { data: modulesMap, isLoading } = useModules()
  const toggleModule = useToggleModule()

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

  return (
    <div className="space-y-8 p-6">
      {/* Header */}
      <div className="space-y-1">
        <h1 className="text-2xl font-bold tracking-tight">Módulos</h1>
        <p className="text-muted-foreground">
          Activá las funciones que tu negocio necesita.
        </p>
      </div>

      {/* Categories */}
      {MODULE_CATEGORIES.map((category, catIdx) => {
        const entries = MODULES_CATALOG.filter((m) => m.category === category)

        return (
          <section key={category} className="space-y-4">
            {catIdx > 0 && <Separator />}
            <h2 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground">
              {category}
            </h2>

            {isLoading ? (
              <SkeletonGrid count={entries.length} />
            ) : (
              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
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
      })}

      {/* Config dialog — montado una sola vez, el configKind cambia por entry */}
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
