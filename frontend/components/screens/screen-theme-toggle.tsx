"use client"

import * as React from "react"
import { Moon, Sun, Clock } from "lucide-react"
import { Button } from "@/components/ui/button"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import type { ScreenTheme } from "@/lib/screens/theme"

/**
 * Selector de tono para las pantallas device-paired (despacho, impresión,
 * checkout). Mismo vocabulario que el diálogo de config del KDS
 * (`kds/config-dialog.tsx`): Oscuro / Claro / Automático (por horario).
 *
 * Botón chico (`size="icon"`) pero con área táctil suficiente para tablet/TV —
 * mismo criterio que el resto de los controles de estas pantallas.
 */

const THEME_ICON: Record<ScreenTheme, typeof Moon> = {
  dark: Moon,
  light: Sun,
  auto: Clock,
}

const THEME_LABEL: Record<ScreenTheme, string> = {
  dark: "Oscuro",
  light: "Claro",
  auto: "Automático (por horario)",
}

interface ScreenThemeToggleProps {
  theme: ScreenTheme
  onChange: (theme: ScreenTheme) => void
  className?: string
}

export function ScreenThemeToggle({ theme, onChange, className }: ScreenThemeToggleProps) {
  const Icon = THEME_ICON[theme]

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          size="icon"
          className={className ?? "size-11"}
          aria-label={`Tono de pantalla: ${THEME_LABEL[theme]}`}
        >
          <Icon className="size-5" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        {(Object.keys(THEME_LABEL) as ScreenTheme[]).map((value) => {
          const OptionIcon = THEME_ICON[value]
          return (
            <DropdownMenuItem key={value} onSelect={() => onChange(value)}>
              <OptionIcon className="size-4" />
              {THEME_LABEL[value]}
            </DropdownMenuItem>
          )
        })}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
