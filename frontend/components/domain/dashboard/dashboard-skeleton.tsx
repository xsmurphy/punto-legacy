import * as React from "react"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { TileRow, TileRows } from "@/components/domain/dashboard/tile"

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
 * Los títulos fijos (KPIs, gráfico, Ganancia) van como texto; los de bloques
 * que pueden no montarse (Objetivo semanal, lo del momento, Requiere
 * atención, rankings) van como skeleton, para no anunciar algo que después
 * desaparece.
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

          <section>
            <div className="flex flex-col gap-2">
              <Skeleton className="h-4 w-48 self-end" />
              <Skeleton className="h-[240px] w-full" />
            </div>
          </section>

          {/* Grilla principal: primero las compactas del momento (órdenes,
              espacios) apareadas, después bloques de media fila. */}
          <section className="grid grid-cols-1 gap-3 md:grid-cols-2">
            {Array.from({ length: 2 }, (_, i) => (
              <CompactBlockSkeleton key={`c${i}`} />
            ))}
            {Array.from({ length: 4 }, (_, i) => (
              <BlockSkeleton key={i} />
            ))}
          </section>
        </div>

        {/* ── SIDEBAR ─────────────────────────────────────────────────── */}
        <aside className="flex min-w-0 flex-col gap-4">
          {/* Objetivo semanal: la card invertida, misma forma (cifra, barra
              con marca, estado / monto). Título en skeleton: puede no
              montarse sin historia suficiente. */}
          <Card variant="inverse">
            <CardHeader>
              <TitleSkeleton />
              <Skeleton className="h-4 w-40" />
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
              <FigureSkeleton />
              <div className="flex flex-col gap-1.5">
                <Skeleton className="h-1 w-full rounded-full" />
                <div className="flex items-center justify-between gap-3">
                  <Skeleton className="h-4 w-40" />
                  <Skeleton className="h-4 w-16" />
                </div>
              </div>
            </CardContent>
          </Card>

          {/* Misma forma que la card de Ganancia real: cifra + pill y las
              filas directo sobre el gris, sin caja interna. */}
          <Card variant="soft">
            <CardHeader>
              <CardTitle>Ganancia</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
              <FigureSkeleton pill />
              <TileRows>
                <TileRow label="Margen" value={null} />
                <TileRow label="Ventas" value={null} />
                <TileRow label="Ticket promedio" value={null} emphasis />
              </TileRows>
            </CardContent>
          </Card>

          {/* Requiere atención y Finanzas: pueden no montarse. */}
          <SidebarCardSkeleton rows={3} />

          <Card variant="soft">
            <CardHeader>
              <TitleSkeleton />
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
              <FigureSkeleton label />
              <RowsSkeleton rows={3} />
            </CardContent>
          </Card>
        </aside>
      </div>
    </div>
  )
}

function BigMetricSkeleton({ label }: { label: string }) {
  return (
    <Card className="relative overflow-hidden">
      <CardContent className="flex flex-col gap-3">
        <span className="text-sm text-muted-foreground">{label}</span>
        <Skeleton className="h-10 w-40" />
        <Skeleton className="mt-1 h-12 w-full" />
      </CardContent>
    </Card>
  )
}

/** Título de card que puede no montarse: skeleton del alto de `CardTitle`. */
function TitleSkeleton() {
  return (
    <CardTitle>
      <Skeleton className="h-5 w-28" />
    </CardTitle>
  )
}

/** Forma de `TileFigure`: label opcional, cifra destacada y pill opcional. */
function FigureSkeleton({ label, pill }: { label?: boolean; pill?: boolean }) {
  return (
    <div className="flex flex-col items-start gap-1.5">
      {label && <Skeleton className="h-4 w-24" />}
      <Skeleton className="h-8 w-36" />
      {pill && <Skeleton className="h-4 w-14 rounded-full" />}
    </div>
  )
}

/** Forma de `TileRows`: filas label / valor con el mismo ritmo y divisores. */
function RowsSkeleton({ rows }: { rows: number }) {
  return (
    <TileRows>
      {Array.from({ length: rows }, (_, i) => (
        <div key={i} className="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0">
          <Skeleton className="h-5 w-24" />
          <Skeleton className="h-5 w-16" />
        </div>
      ))}
    </TileRows>
  )
}

/** Card compacta de la grilla (órdenes, espacios): título + cifra. */
function CompactBlockSkeleton() {
  return (
    <Card>
      <CardHeader>
        <TitleSkeleton />
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <FigureSkeleton />
      </CardContent>
    </Card>
  )
}

/** Card de un bloque de la grilla (ranking / barra partida): título + lista. */
function BlockSkeleton() {
  return (
    <Card>
      <CardHeader>
        <TitleSkeleton />
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
      <CardHeader>
        <TitleSkeleton />
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <RowsSkeleton rows={rows} />
        {bars > 0 && (
          <div className="flex flex-col gap-3">
            {Array.from({ length: bars }, (_, i) => (
              <div key={i} className="flex flex-col gap-1.5">
                <Skeleton className="h-5 w-28" />
                <Skeleton className="h-1 w-full rounded-full" />
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  )
}
