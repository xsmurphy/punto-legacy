"use client"

import * as React from "react"
import { useTheme } from "next-themes"
import { Sun, Moon, Monitor, Check } from "lucide-react"

import { cn } from "@/lib/utils"

type ThemeValue = "light" | "dark" | "system"

interface Option {
  value: ThemeValue
  label: string
  Icon: typeof Sun
}

const OPTIONS: Option[] = [
  { value: "light",  label: "Claro",   Icon: Sun },
  { value: "dark",   label: "Oscuro",  Icon: Moon },
  { value: "system", label: "Sistema", Icon: Monitor },
]

/**
 * Selector de tema con preview visual del mock — cada card muestra un
 * mini-UI representando cómo luce el tema (bg + líneas skeleton + dot
 * amarillo que simboliza el elemento activo). Sistema = split vertical
 * light/dark para indicar que sigue la preferencia del OS.
 *
 * Persistido por next-themes en localStorage (preferencia per-device,
 * no per-tenant). Se cambia solo desde este picker (sin atajo global).
 */
/** Detección de mounted sin setState-in-effect — useSyncExternalStore retorna
 *  el server snapshot (false) durante SSR y el client snapshot (true) en cliente,
 *  evitando hydration mismatch con next-themes sin disparar warnings de lint. */
function useMounted(): boolean {
  return React.useSyncExternalStore(
    () => () => {},
    () => true,
    () => false,
  )
}

export function ThemePicker() {
  const { theme, setTheme } = useTheme()
  const mounted = useMounted()

  const current = (mounted ? theme ?? "system" : "system") as ThemeValue

  return (
    <div className="space-y-3">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        {OPTIONS.map((opt) => (
          <ThemeCard
            key={opt.value}
            option={opt}
            active={current === opt.value}
            onSelect={() => setTheme(opt.value)}
          />
        ))}
      </div>
      <p className="text-xs text-muted-foreground">
        <span className="font-medium text-foreground">Sistema</span>{" "}
        sigue la preferencia de tu sistema operativo. Se aplica al instante
        y se recuerda entre sesiones.
      </p>
    </div>
  )
}

function ThemeCard({
  option,
  active,
  onSelect,
}: {
  option: Option
  active: boolean
  onSelect: () => void
}) {
  return (
    <button
      type="button"
      onClick={onSelect}
      aria-pressed={active}
      aria-label={`Tema ${option.label}`}
      className={cn(
        "group relative flex flex-col gap-3 rounded-xl border bg-card p-3 text-left transition",
        "hover:border-foreground/30",
        active
          ? "border-foreground/80 ring-2 ring-foreground/10"
          : "border-border",
      )}
    >
      <ThemePreview value={option.value} />

      <div className="flex items-center gap-2">
        <option.Icon className="size-4 text-foreground" />
        <span className="text-sm font-medium">{option.label}</span>
      </div>

      {active && (
        <span
          aria-hidden
          className="absolute bottom-3 right-3 flex size-6 items-center justify-center rounded-full bg-foreground text-background"
        >
          <Check className="size-3.5" strokeWidth={3} />
        </span>
      )}
    </button>
  )
}

/**
 * Mock visual del tema. Renderizado con CSS puro (sin imágenes):
 *  - 2 líneas tipo skeleton arriba (variable widths)
 *  - 1 línea ancha con un dot amarillo simbolizando el "ítem activo"
 *
 * Sistema = split vertical: mitad izquierda con paleta light, mitad derecha
 * con paleta dark. El dot se ubica en la división para sugerir la transición.
 */
function ThemePreview({ value }: { value: ThemeValue }) {
  if (value === "system") {
    return (
      <div className="relative aspect-[16/9] w-full overflow-hidden rounded-md border border-border">
        {/* Mitad light */}
        <div className="absolute inset-y-0 left-0 w-1/2 bg-white">
          <PreviewSkeleton scheme="light" side="left" />
        </div>
        {/* Mitad dark */}
        <div className="absolute inset-y-0 right-0 w-1/2 bg-neutral-900">
          <PreviewSkeleton scheme="dark" side="right" />
        </div>
        {/* Dot dorado en la división */}
        <span
          aria-hidden
          className="absolute bottom-[26%] left-1/2 size-2 -translate-x-1/2 rounded-full bg-amber-400 shadow-[0_0_0_3px_rgba(0,0,0,0.05)]"
        />
      </div>
    )
  }

  const isDark = value === "dark"
  return (
    <div
      className={cn(
        "relative aspect-[16/9] w-full overflow-hidden rounded-md border",
        isDark
          ? "border-neutral-700 bg-neutral-900"
          : "border-neutral-200 bg-white",
      )}
    >
      <PreviewSkeleton scheme={isDark ? "dark" : "light"} />
    </div>
  )
}

function PreviewSkeleton({
  scheme,
  side,
}: {
  scheme: "light" | "dark"
  side?: "left" | "right"
}) {
  const lineColor =
    scheme === "dark" ? "bg-neutral-700" : "bg-neutral-200"
  const activeBarColor =
    scheme === "dark" ? "bg-neutral-600" : "bg-neutral-300"

  return (
    <div className="flex h-full flex-col justify-between px-3 py-3">
      <div className="space-y-1.5">
        <div className={cn("h-1.5 w-1/3 rounded-full", lineColor)} />
        <div className={cn("h-1.5 w-2/3 rounded-full", lineColor)} />
      </div>
      <div className="flex items-center gap-1.5">
        {/* dot amarillo (sólo si no es split — el split lo dibuja afuera) */}
        {side === undefined && (
          <span
            aria-hidden
            className="size-1.5 shrink-0 rounded-full bg-amber-400"
          />
        )}
        <div className={cn("h-1.5 flex-1 rounded-full", activeBarColor)} />
      </div>
    </div>
  )
}
