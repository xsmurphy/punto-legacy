"use client"

/**
 * Declaración de COBERTURA de un bloque del reporte de operación.
 *
 * Va ARRIBA del bloque, con número y porcentaje, y el gráfico se dibuja igual
 * debajo — nunca se esconde detrás de un clic. Es la misma corrección que el
 * owner ya pidió en el mapa de clientes (`customers-geo-tab.tsx`): el riesgo
 * de que alguien decida sobre una muestra sesgada es real, pero alcanza con
 * decirlo; esconder el dato agrega fricción en cada visita y asume que nadie
 * lee.
 *
 * Por debajo del umbral se suma una advertencia visible. El texto de la
 * advertencia lo pone cada bloque porque cada uno tiene otra razón para que
 * falte el dato (etapas sin marcar, personas sin cargar).
 */

import * as React from "react"
import { AlertTriangle } from "lucide-react"

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert"

export function coveragePct(part: number, total: number): number {
  return total > 0 ? (part / total) * 100 : 0
}

export function CoverageNote({
  children,
  low,
  lowTitle,
  lowDescription,
}: {
  /** La cobertura dicha en una línea, con número y porcentaje. */
  children: React.ReactNode
  /** Por debajo del umbral del bloque. */
  low: boolean
  lowTitle: string
  lowDescription: string
}) {
  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm text-muted-foreground">{children}</p>
      {low && (
        <Alert>
          <AlertTriangle />
          <AlertTitle>{lowTitle}</AlertTitle>
          <AlertDescription>{lowDescription}</AlertDescription>
        </Alert>
      )}
    </div>
  )
}
