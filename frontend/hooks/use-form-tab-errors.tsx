"use client"

import * as React from "react"
import type { FieldErrors, FieldValues, Path, UseFormReturn } from "react-hook-form"
import { toast } from "sonner"
import { cn } from "@/lib/utils"

/**
 * Patrón "react-hook-form + Tabs no controladas": si el usuario está parado
 * en una tab y el campo inválido vive en OTRA, `handleSubmit` aborta en
 * silencio — el único feedback (el input en rojo) está en un tab oculto.
 * Este hook centraliza el arreglo: salta a la primera tab con error, enfoca
 * el campo y avisa con un toast. Ver context/14-ui-conventions.md.
 */

interface UseFormTabErrorsOptions<TFieldValues extends FieldValues> {
  form: UseFormReturn<TFieldValues>
  /**
   * Qué campos viven en qué tab, EN EL MISMO ORDEN en que las tabs aparecen
   * en pantalla — de ahí sale "la primera tab con error". Los paths pueden
   * ser hojas (`"phone"`) o padres (`"availability"`, cubre todo lo que
   * cuelga de ahí, ej. `availability.days.lunes.enabled`).
   */
  fields: Record<string, Path<TFieldValues>[]>
  onTabChange: (tab: string) => void
  /** Nombre lindo para el toast — default: la key cruda de `fields`. */
  tabLabels?: Record<string, string>
}

interface UseFormTabErrorsResult<TFieldValues extends FieldValues> {
  tabsWithErrors: Set<string>
  onInvalid: (errors: FieldErrors<TFieldValues>) => void
}

export function useFormTabErrors<TFieldValues extends FieldValues>({
  form,
  fields,
  onTabChange,
  tabLabels,
}: UseFormTabErrorsOptions<TFieldValues>): UseFormTabErrorsResult<TFieldValues> {
  // Leer `errors` acá (en el cuerpo del render, no dentro de un callback) es
  // lo que hace que el Proxy de formState suscriba el re-render de este
  // hook — si esto se mueve a un callback, `tabsWithErrors` deja de
  // apagarse solo cuando el usuario corrige el campo.
  const { errors } = form.formState

  const errorPaths = React.useMemo(() => flattenErrorPaths(errors), [errors])

  const tabsWithErrors = React.useMemo(() => {
    const set = new Set<string>()
    for (const [tab, paths] of Object.entries(fields)) {
      if (paths.some((p) => errorPaths.some((ep) => matchesPath(ep, p)))) {
        set.add(tab)
      }
    }
    return set
  }, [fields, errorPaths])

  const onInvalid = React.useCallback(
    (formErrors: FieldErrors<TFieldValues>) => {
      const leaves = flattenErrorPaths(formErrors)
      if (leaves.length === 0) return

      // Primer tab, en el orden de `fields`, que tiene algún campo inválido.
      let targetTab: string | null = null
      let targetPath: string | null = null
      for (const [tab, paths] of Object.entries(fields)) {
        const hit = leaves.find((ep) => paths.some((p) => matchesPath(ep, p)))
        if (hit) {
          targetTab = tab
          targetPath = hit
          break
        }
      }

      // Red de seguridad: si `fields` se desincroniza (alguien agrega un
      // campo y se olvida de anotarlo acá), no nos quedamos callados —
      // avisamos igual y dejamos rastro en consola para que se corrija el mapa.
      const mappedPaths = Object.values(fields).flat()
      const unmapped = leaves.filter(
        (ep) => !mappedPaths.some((p) => matchesPath(ep, p)),
      )
      if (unmapped.length > 0) {
        console.error(
          "[useFormTabErrors] Campos con error sin tab asignado en `fields` — revisar el mapa:",
          unmapped,
        )
      }

      if (targetTab) {
        onTabChange(targetTab)
        const label = tabLabels?.[targetTab] ?? targetTab
        toast.error(`Revisá la pestaña "${label}"`, {
          description:
            unmapped.length > 0
              ? "Hay más campos inválidos fuera de esta pestaña."
              : "Hay campos inválidos que corregir ahí.",
        })
        if (targetPath) {
          // Radix desmonta el contenido de las tabs inactivas — el input
          // todavía no existe en el DOM en este mismo tick porque recién
          // acabamos de pedir el cambio de tab. Diferir el foco a después
          // del render (rAF) es necesario; no "limpiar" esto después.
          requestAnimationFrame(() => {
            form.setFocus(targetPath as Path<TFieldValues>)
          })
        }
      } else if (unmapped.length > 0) {
        toast.error("Hay campos inválidos que corregir", {
          description: unmapped.join(", "),
        })
      }
    },
    [fields, form, onTabChange, tabLabels],
  )

  return { tabsWithErrors, onInvalid }
}

function matchesPath(errorPath: string, mappedPath: string): boolean {
  return errorPath === mappedPath || errorPath.startsWith(`${mappedPath}.`)
}

/**
 * Claves internas del objeto de error de RHF: no son campos del formulario y
 * no se recursa en ellas. `ref` en particular apunta al nodo del DOM (o al
 * objeto de un Controller) — recursarlo genera paths fantasma tipo
 * "phone.ref" que después se reportan como "campos sin tab asignado".
 */
const RHF_ERROR_META_KEYS = new Set(["ref", "types", "type", "message", "root"])

/** RHF nest los errores con la misma forma que los valores del form. Esta
 * función los aplana a una lista de paths tipo "availability.days.lunes.enabled"
 * — solo hojas reales (objetos con `type`/`message`), recursando también por
 * si un nodo intermedio tiene error propio Y errores en sus hijos. */
function flattenErrorPaths(errors: unknown, prefix = ""): string[] {
  if (!errors || typeof errors !== "object") return []
  const paths: string[] = []
  for (const key of Object.keys(errors as Record<string, unknown>)) {
    if (RHF_ERROR_META_KEYS.has(key)) continue
    const value = (errors as Record<string, unknown>)[key]
    if (!value || typeof value !== "object") continue
    const path = prefix ? `${prefix}.${key}` : key
    if ("type" in value || "message" in value) {
      paths.push(path)
    }
    paths.push(...flattenErrorPaths(value, path))
  }
  return paths
}

/**
 * Punto que se pinta dentro de un `<TabsTrigger>` cuando esa tab tiene algún
 * campo con error — mismo indicador en las tres páginas que usan el hook,
 * nadie inventa su propia versión.
 */
export function TabErrorDot({ className }: { className?: string }) {
  return (
    <span
      aria-label="Tiene campos con error"
      title="Tiene campos con error"
      className={cn("inline-block size-1.5 shrink-0 rounded-full bg-destructive", className)}
    />
  )
}
