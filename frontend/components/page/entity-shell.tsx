"use client"

import * as React from "react"
import { usePathname, useRouter, useSearchParams } from "next/navigation"
import { Loader2 } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Skeleton } from "@/components/ui/skeleton"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { BackLink } from "@/components/page/back-link"
import { cn } from "@/lib/utils"

/**
 * EntityShell — el armazón de TODA ficha de entidad del panel (context/84 §3,
 * estructura decidida por el owner 2026-09-18).
 *
 *   BackLink
 *   Encabezado:  [avatar]  h1 · estado          acciones  [Guardar]
 *                subtítulo (solo DATO: RUC, puesto, fechas)
 *   Pestañas:    Resumen → Datos → pestañas propias…
 *
 * El problema que resuelve: la misma función tenía distinto nombre y lugar
 * según la sección (artículos editaba en "Perfil", primera pestaña; clientes
 * en "Datos", la última; sucursales en "Sucursal"), cada ficha armaba su
 * encabezado y su "volver" a mano, y el botón de guardar aparecía en pestañas
 * que no editaban nada.
 *
 * LA API ES LA REGLA. No existe forma de romperla desde un call-site:
 *  - `summary` y `data` son props OBLIGATORIAS y separadas. Sin una de las dos
 *    no compila.
 *  - Sus labels ("Resumen", "Datos") y su orden (1ª y 2ª) viven acá. Las
 *    pestañas propias solo entran por `extraTabs` y siempre van después.
 *  - "Guardar" lo pinta el armazón, y solo con la pestaña Datos activa.
 *  - El h1, el BackLink y la lista de pestañas no aceptan clases de estilo.
 *  - Pestañas SOLO TEXTO (`EntityTab` no tiene `icon`): C8, owner 2026-09-18.
 *
 * Que las páginas de ficha usen este componente lo exige el guard
 * `lib/ui/__tests__/entity-detail-structure.test.ts`.
 *
 * Estado de la pestaña en `?tab=` (T13). `tabAliases` mapea valores viejos al
 * lugar nuevo para que los links guardados no se rompan (ej. `?tab=perfil` de
 * artículos → `datos`).
 *
 * Alta (`isNew`): mismas pestañas, en el mismo lugar, con Datos activa y el
 * resto deshabilitadas hasta que la entidad existe (context/84 §3, patrón que
 * ya usaba `items/[id]`).
 */

export const ENTITY_SUMMARY_KEY = "resumen"
export const ENTITY_DATA_KEY = "datos"
const SUMMARY_LABEL = "Resumen"
const DATA_LABEL = "Datos"

export interface EntityTab {
  /** Clave en `?tab=`. No puede ser "resumen" ni "datos". */
  key: string
  label: string
  content: React.ReactNode
  disabled?: boolean
}

export interface EntitySaveAction {
  pending?: boolean
  disabled?: boolean
  /**
   * Sin `onClick`, el botón es `type="submit"`: el call-site envuelve el
   * armazón en su `<form>` (react-hook-form) y el submit lo maneja ahí.
   */
  onClick?: () => void
}

export interface EntityShellProps {
  back: { href: string; label: string }
  /** Nombre de la entidad. Se pinta como h1 `text-2xl font-semibold`. */
  title: React.ReactNode
  isLoading?: boolean
  avatar?: React.ReactNode
  /** Estado y atributos cortos (segmento, activo/inactivo): badges junto al h1. */
  status?: React.ReactNode
  /** Solo DATO (C9): RUC, puesto, "Cliente desde…". Nunca una leyenda. */
  subtitle?: React.ReactNode
  /** Acciones del encabezado: neutras primero, destructivas al final. */
  actions?: React.ReactNode
  /** Pestaña 1 — "Resumen". KPIs en StatTile + contenido en cards blancas. */
  summary: React.ReactNode
  /** Pestaña 2 — "Datos". El ÚNICO lugar donde se editan los atributos. */
  data: React.ReactNode
  /** Pestañas propias, siempre después de Datos. `false`/`null` se omite. */
  extraTabs?: ReadonlyArray<EntityTab | false | null | undefined>
  /** Guardar de Datos. Sin `save` la ficha es de solo lectura (sin permiso). */
  save?: EntitySaveAction
  isNew?: boolean
  /** Valores viejos de `?tab=` → clave vigente. */
  tabAliases?: Readonly<Record<string, string>>
  /** Avisa el cambio de pestaña (ej. para no pedir datos que no se ven). */
  onTabChange?: (key: string) => void
}

export interface ResolvedEntityTab {
  key: string
  label: string
  content: React.ReactNode
  disabled: boolean
}

/**
 * Orden y nombres canónicos. Exportado para la única superficie que no usa
 * las pestañas del panel: la ficha de cliente dentro de la CAJA
 * (`contact-detail-view.tsx`, `nav="sidebar"`), que las muestra como menú
 * lateral pero con el MISMO orden y los MISMOS nombres.
 */
export function resolveEntityTabs({
  summary,
  data,
  extraTabs,
  isNew,
}: Pick<EntityShellProps, "summary" | "data" | "extraTabs" | "isNew">): ResolvedEntityTab[] {
  const extras = (extraTabs ?? []).filter(Boolean) as EntityTab[]
  for (const t of extras) {
    if (t.key === ENTITY_SUMMARY_KEY || t.key === ENTITY_DATA_KEY) {
      throw new Error(`EntityShell: la pestaña propia "${t.key}" pisa una canónica`)
    }
  }
  return [
    { key: ENTITY_SUMMARY_KEY, label: SUMMARY_LABEL, content: summary, disabled: !!isNew },
    { key: ENTITY_DATA_KEY, label: DATA_LABEL, content: data, disabled: false },
    ...extras.map((t) => ({ ...t, disabled: !!isNew || !!t.disabled })),
  ]
}

function useEntityTabParam(
  tabs: ResolvedEntityTab[],
  isNew: boolean | undefined,
  aliases: Readonly<Record<string, string>> | undefined,
): [string, (key: string) => void] {
  const router = useRouter()
  const pathname = usePathname()
  const searchParams = useSearchParams()

  const fallback = isNew ? ENTITY_DATA_KEY : ENTITY_SUMMARY_KEY
  const raw = searchParams.get("tab")
  const requested = raw ? (aliases?.[raw] ?? raw) : null
  const enabled = tabs.filter((t) => !t.disabled).map((t) => t.key)
  const active = requested && enabled.includes(requested) ? requested : fallback

  const setTab = React.useCallback(
    (key: string) => {
      const params = new URLSearchParams(searchParams.toString())
      params.set("tab", key)
      router.replace(`${pathname}?${params.toString()}`, { scroll: false })
    },
    [pathname, router, searchParams],
  )
  return [active, setTab]
}

export function EntityShell({
  back,
  title,
  isLoading,
  avatar,
  status,
  subtitle,
  actions,
  summary,
  data,
  extraTabs,
  save,
  isNew,
  tabAliases,
  onTabChange,
}: EntityShellProps) {
  const tabs = resolveEntityTabs({ summary, data, extraTabs, isNew })
  const [tab, setTab] = useEntityTabParam(tabs, isNew, tabAliases)

  React.useEffect(() => {
    onTabChange?.(tab)
  }, [tab, onTabChange])

  const showSave = save !== undefined && tab === ENTITY_DATA_KEY

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-2">
        <BackLink href={back.href} label={back.label} />
        <header className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div className="flex min-w-0 items-center gap-3">
            {avatar}
            <div className="flex min-w-0 flex-col gap-1">
              <div className="flex min-w-0 flex-wrap items-center gap-2">
                <h1 className="truncate text-2xl font-semibold">
                  {isLoading ? <Skeleton className="h-8 w-56" /> : title}
                </h1>
                {!isLoading && status}
              </div>
              {isLoading ? (
                <Skeleton className="h-4 w-64" />
              ) : (
                subtitle && (
                  <div className="text-sm text-muted-foreground">{subtitle}</div>
                )
              )}
            </div>
          </div>
          {(actions || showSave) && (
            <div className="flex shrink-0 flex-wrap items-center gap-2">
              {actions}
              {showSave && (
                <Button
                  type={save.onClick ? "button" : "submit"}
                  onClick={save.onClick}
                  disabled={save.disabled || save.pending || isLoading}
                >
                  {save.pending && <Loader2 className="size-4 animate-spin" />}
                  {isNew ? "Crear" : "Guardar"}
                </Button>
              )}
            </div>
          )}
        </header>
      </div>

      <Tabs value={tab} onValueChange={setTab} className="gap-6">
        <div className="-mx-2 overflow-x-auto px-2">
          <TabsList className="min-w-max">
            {tabs.map((t) => (
              <TabsTrigger key={t.key} value={t.key} disabled={t.disabled}>
                {t.label}
              </TabsTrigger>
            ))}
          </TabsList>
        </div>
        {tabs.map((t) => (
          <TabsContent key={t.key} value={t.key} className={cn("flex flex-col gap-6")}>
            {t.content}
          </TabsContent>
        ))}
      </Tabs>
    </div>
  )
}

/**
 * Avatar con iniciales para el encabezado de la ficha — el mismo en cliente y
 * persona del equipo, que lo armaban cada uno por su lado.
 */
export function entityInitials(name: string | null | undefined): string {
  if (!name) return "?"
  return name
    .split(" ")
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0].toUpperCase())
    .join("")
}
