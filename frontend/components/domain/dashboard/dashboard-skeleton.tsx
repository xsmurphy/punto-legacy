import * as React from "react"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"

/**
 * Encabezado del dashboard (`app/(panel)/page.tsx`). Lo comparten la página y
 * su skeleton para que el título y la descripción sean EXACTAMENTE los mismos
 * y el reemplazo no mueva nada.
 */
export function DashboardHeader({ actions }: { actions: React.ReactNode }) {
  return (
    <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold">Resumen general</h1>
        <p className="text-sm text-muted-foreground">
          Cómo va tu negocio en el período y lo que está pasando ahora.
        </p>
      </div>
      {actions}
    </header>
  )
}

/**
 * Skeleton de la PÁGINA COMPLETA del dashboard: lo primero que se ve al entrar,
 * hasta que llegaron los permisos y las queries que definen la estructura
 * (`lib/dashboard/first-load.ts`). Replica el grid, los anchos y las card
 * shells del layout real (columna principal + sidebar de 22rem) con el caso
 * típico de bloques, para que el contenido lo reemplace sin saltos.
 *
 * Los títulos fijos (KPIs, gráfico) van como texto; los de bloques que pueden
 * no montarse (Ahora, Requiere atención, rankings) van como skeleton, para no
 * anunciar algo que después desaparece.
 */
export function DashboardSkeleton() {
  return (
    <div className="flex flex-col gap-6" aria-busy="true" aria-label="Cargando resumen">
      <DashboardHeader actions={<Skeleton className="h-9 w-56" />} />

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_22rem]">
        {/* ── MAIN COLUMN ─────────────────────────────────────────────── */}
        <div className="flex min-w-0 flex-col gap-4">
          <section className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <BigMetricSkeleton label="Ingresos" />
            <BigMetricSkeleton label="Egresos" />
          </section>

          <section className="grid grid-cols-1 gap-3 lg:grid-cols-[1fr_15rem]">
            <div className="flex flex-col gap-2">
              <h2 className="text-xl font-semibold">Margen, ingresos y egresos</h2>
              <Skeleton className="h-[240px] w-full" />
            </div>
            <div className="flex flex-col self-start">
              <div className="flex flex-col items-center gap-1 py-6">
                <span className="text-xs font-medium text-muted-foreground">Ganancia</span>
                <Skeleton className="h-8 w-32" />
              </div>
              <div className="grid grid-cols-2 divide-x divide-border border-t py-4">
                <KpiSkeleton label="Margen" width="w-12" />
                <KpiSkeleton label="Cant. Ventas" width="w-12" />
              </div>
              <div className="border-t py-4">
                <KpiSkeleton label="Ticket promedio" width="w-24" />
              </div>
            </div>
          </section>

          <section className="grid grid-cols-1 gap-3 md:grid-cols-2">
            {Array.from({ length: 4 }, (_, i) => (
              <BlockSkeleton key={i} />
            ))}
          </section>
        </div>

        {/* ── SIDEBAR ─────────────────────────────────────────────────── */}
        <aside className="flex min-w-0 flex-col gap-4">
          <section className="flex flex-col gap-3">
            <Skeleton className="h-7 w-20" />
            {Array.from({ length: 2 }, (_, i) => (
              <Card key={i} size="sm">
                <CardHeader>
                  <Skeleton className="h-4 w-24" />
                </CardHeader>
                <CardContent className="flex flex-col gap-2">
                  <Skeleton className="h-7 w-16" />
                  <Skeleton className="h-4 w-full" />
                </CardContent>
              </Card>
            ))}
          </section>

          <SidebarCardSkeleton rows={3} />

          <Card variant="soft">
            <CardHeader>
              <CardTitle>
                <Skeleton className="h-4 w-20" />
              </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
              <div className="flex flex-col gap-1">
                <Skeleton className="h-3 w-24" />
                <Skeleton className="h-7 w-28" />
              </div>
              <div className="flex flex-col gap-1.5 border-t pt-3">
                <Skeleton className="h-4 w-full" />
                <Skeleton className="h-4 w-full" />
                <Skeleton className="h-4 w-3/4" />
              </div>
            </CardContent>
          </Card>

          <SidebarCardSkeleton rows={3} bars={4} />
        </aside>
      </div>
    </div>
  )
}

function BigMetricSkeleton({ label }: { label: string }) {
  return (
    <Card className="relative overflow-hidden">
      <CardContent className="flex flex-col gap-3">
        <span className="text-xs font-medium text-muted-foreground">{label}</span>
        <Skeleton className="h-10 w-40" />
        <Skeleton className="mt-1 h-12 w-full" />
      </CardContent>
    </Card>
  )
}

function KpiSkeleton({ label, width }: { label: string; width: string }) {
  return (
    <div className="flex flex-col items-center gap-1">
      <span className="text-xs text-muted-foreground">{label}</span>
      <Skeleton className={`h-6 ${width}`} />
    </div>
  )
}

/** Card de un bloque del período (ranking / donut): título + lista. */
function BlockSkeleton() {
  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <Skeleton className="h-5 w-32" />
        </CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        {Array.from({ length: 5 }, (_, i) => (
          <Skeleton key={i} className="h-6 w-full" />
        ))}
      </CardContent>
    </Card>
  )
}

/** Card `soft` del sidebar: filas label/valor y, opcional, barras de tasa. */
function SidebarCardSkeleton({ rows, bars = 0 }: { rows: number; bars?: number }) {
  return (
    <Card variant="soft">
      <CardHeader className="pb-2">
        <CardTitle>
          <Skeleton className="h-4 w-28" />
        </CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <div className="flex flex-col gap-3">
          {Array.from({ length: rows }, (_, i) => (
            <div key={i} className="flex items-center justify-between gap-2">
              <Skeleton className="h-4 w-24" />
              <Skeleton className="h-4 w-12" />
            </div>
          ))}
        </div>
        {bars > 0 && (
          <div className="flex flex-col gap-3">
            {Array.from({ length: bars }, (_, i) => (
              <div key={i} className="flex flex-col gap-1.5">
                <Skeleton className="h-3 w-28" />
                <Skeleton className="h-2 w-full rounded-full" />
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  )
}
