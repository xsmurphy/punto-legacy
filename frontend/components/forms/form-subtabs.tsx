"use client"

import * as React from "react"
import { usePathname, useRouter, useSearchParams } from "next/navigation"
import { useFormContext, useFormState } from "react-hook-form"

import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { firstSubtabError, subtabsWithErrors } from "@/lib/forms/subtab-errors"

/**
 * FormSubtabs — sub-pestañas de la pestaña Datos de una ficha (context/84 §3).
 *
 * Cuando Datos junta muchas `FormSection`, las agrupa en sub-pestañas. Solo
 * ORGANIZAN la vista: sigue siendo UN formulario con UN "Guardar" (el del
 * armazón `EntityShell`). Por eso todas las sub-pestañas quedan montadas
 * (`forceMount` + oculto): cambiar de sub-pestaña no pierde valores, no
 * desregistra campos y la validación ve el formulario entero.
 *
 * - `fields` declara qué campos viven en cada sub-pestaña: la que tenga uno
 *   inválido lleva una marca, y al guardar con errores se salta a la primera
 *   (en el orden de la lista) y se enfoca el campo.
 * - `hidden` saca una sub-pestaña que no aplica (ej. sin ninguna sección
 *   visible para el tipo de entidad).
 * - La activa vive en `?sub=` (configurable con `param`), al lado del
 *   `?tab=` del armazón, para que sea linkeable.
 *
 * Debe renderizarse dentro del `<Form>` (FormProvider) de la ficha.
 */

export interface FormSubtab {
  /** Clave en `?sub=`. */
  id: string
  label: string
  content: React.ReactNode
  /** Campos del form (rutas de react-hook-form) que viven acá. */
  fields?: readonly string[]
  hidden?: boolean
}

export function FormSubtabs({
  tabs,
  param = "sub",
  defaultTab,
}: {
  tabs: ReadonlyArray<FormSubtab>
  param?: string
  /** Sub-pestaña inicial cuando `?sub=` no dice nada (ej. un link viejo). */
  defaultTab?: string
}) {
  const visible = tabs.filter((t) => !t.hidden)
  const router = useRouter()
  const pathname = usePathname()
  const searchParams = useSearchParams()
  const form = useFormContext()
  const { errors, submitCount } = useFormState({ control: form.control })

  const requested = searchParams.get(param)
  const active =
    requested && visible.some((t) => t.id === requested)
      ? requested
      : defaultTab && visible.some((t) => t.id === defaultTab)
        ? defaultTab
        : (visible[0]?.id ?? "")

  const setActive = React.useCallback(
    (id: string) => {
      const params = new URLSearchParams(searchParams.toString())
      params.set(param, id)
      router.replace(`${pathname}?${params.toString()}`, { scroll: false })
    },
    [param, pathname, router, searchParams],
  )

  const withErrors = subtabsWithErrors(visible, errors)

  // Guardar con errores: react-hook-form intenta enfocar el primer campo
  // inválido, pero si está en una sub-pestaña oculta el foco no llega. Se
  // salta a la primera sub-pestaña con error y se enfoca ahí.
  const panels = React.useRef<Record<string, HTMLDivElement | null>>({})
  const pendingFocus = React.useRef<{ tabId: string; field: string } | null>(null)
  const lastSubmit = React.useRef(submitCount)
  React.useEffect(() => {
    if (submitCount === lastSubmit.current) return
    lastSubmit.current = submitCount
    const target = firstSubtabError(visible, errors)
    if (!target) return
    pendingFocus.current = target
    if (target.tabId !== active) setActive(target.tabId)
    // `visible`/`errors` se leen en el momento del submit; solo el submit
    // dispara el salto.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [submitCount])

  React.useEffect(() => {
    const target = pendingFocus.current
    if (!target || target.tabId !== active) return
    pendingFocus.current = null
    const frame = requestAnimationFrame(() => {
      const panel = panels.current[target.tabId]
      try {
        form.setFocus(target.field)
      } catch {
        // Campo sin ref enfocable (ej. un controlado que no reenvía ref).
      }
      if (panel && !panel.contains(document.activeElement)) {
        panel.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
      }
    })
    return () => cancelAnimationFrame(frame)
  }, [active, submitCount, form])

  if (visible.length === 0) return null

  return (
    <Tabs value={active} onValueChange={setActive} className="gap-6">
      {/* Scroll horizontal propio en pantallas angostas; el pb deja lugar al
          subrayado de la variante line, que cuelga por debajo de la lista. */}
      <div className="-mx-2 overflow-x-auto px-2 pb-1">
        <TabsList variant="line" className="min-w-max">
          {visible.map((t) => (
            <TabsTrigger key={t.id} value={t.id} className="flex-none px-3">
              {t.label}
              {withErrors.has(t.id) && (
                <>
                  <span aria-hidden className="size-1.5 rounded-full bg-destructive" />
                  <span className="sr-only">(con errores)</span>
                </>
              )}
            </TabsTrigger>
          ))}
        </TabsList>
      </div>
      {visible.map((t) => (
        <TabsContent
          key={t.id}
          value={t.id}
          forceMount
          ref={(el) => {
            panels.current[t.id] = el
          }}
          className="data-[state=inactive]:hidden"
        >
          {t.content}
        </TabsContent>
      ))}
    </Tabs>
  )
}
