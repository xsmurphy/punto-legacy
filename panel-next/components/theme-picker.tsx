"use client"

import * as React from "react"
import { useTheme } from "next-themes"
import { Sun, Moon, Monitor, Check } from "lucide-react"

import { cn } from "@/lib/utils"

const OPTIONS = [
  {
    value: "light" as const,
    label: "Claro",
    description: "Fondo blanco. Bueno para entornos iluminados.",
    Icon: Sun,
  },
  {
    value: "dark" as const,
    label: "Oscuro",
    description: "Fondo negro. Menos cansancio visual de noche.",
    Icon: Moon,
  },
  {
    value: "system" as const,
    label: "Sistema",
    description: "Sigue la preferencia del sistema operativo.",
    Icon: Monitor,
  },
]

/**
 * Selector de tema visual. Persistido por next-themes en localStorage
 * (independiente del backend — es una preferencia per-device, no per-tenant).
 *
 * El atajo de teclado 'D' sigue funcionando (ThemeHotkey en theme-provider).
 */
export function ThemePicker() {
  const { theme, setTheme } = useTheme()
  const [mounted, setMounted] = React.useState(false)

  // next-themes resuelve el tema solo en cliente — evitar hydration mismatch.
  React.useEffect(() => setMounted(true), [])

  const current = mounted ? theme ?? "system" : "system"

  return (
    <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
      {OPTIONS.map((opt) => {
        const active = current === opt.value
        return (
          <button
            key={opt.value}
            type="button"
            onClick={() => setTheme(opt.value)}
            aria-pressed={active}
            className={cn(
              "relative flex flex-col items-start gap-1 rounded-md border bg-card p-3 text-left transition",
              "hover:bg-muted/50",
              active && "border-primary ring-2 ring-primary/30",
            )}
          >
            <div className="flex w-full items-center justify-between gap-2">
              <opt.Icon className="size-4 text-muted-foreground" />
              {active && <Check className="size-4 text-primary" />}
            </div>
            <span className="text-sm font-medium leading-tight">
              {opt.label}
            </span>
            <span className="text-xs leading-snug text-muted-foreground">
              {opt.description}
            </span>
          </button>
        )
      })}
    </div>
  )
}
