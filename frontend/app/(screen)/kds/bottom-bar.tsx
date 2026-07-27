"use client"

import * as React from "react"
import { ChevronLeft, ChevronRight, RefreshCw, Volume2 } from "lucide-react"
import { Button } from "@/components/ui/button"
import { KDS_STATUS_VISUALS, type KdsOrderStatus } from "@/lib/kds/kds-visuals"

/**
 * Barra inferior fija del KDS. Reemplaza lo único que aportaban las columnas
 * por estado: el conteo. "3 en espera · 2 en proceso · 1 listo" da la misma
 * información de un vistazo sin gastar dos tercios del ancho de la pantalla en
 * títulos de columna.
 *
 * Posiciones estables (14-ui-conventions.md Regla #10): los tres contadores se
 * renderizan SIEMPRE, también en cero, y el bloque de paginación reserva su
 * alto aunque haya una sola página. Nada de acá se mueve cuando cambia el
 * estado de una comanda.
 *
 * De teléfono a TV: el nombre trunca, la palabra del contador se esconde por
 * debajo de `sm` (queda el punto de color + el número, que es lo operativo), y
 * con muchas páginas los puntos se reemplazan por "3 / 12" — 12 puntitos en un
 * teléfono no se distinguen ni se aciertan con el dedo. El tamaño de letra
 * escala con `vw` por el mismo motivo que las tarjetas: se mira de lejos.
 */

const COUNTER_ORDER: KdsOrderStatus[] = ["sent", "in_progress", "ready"]

/** Más páginas que esto y los puntos dejan de ser legibles: se pasa a "n / N". */
const MAX_DOTS = 8

interface BottomBarProps {
  name: string
  counts: Record<KdsOrderStatus, number>
  page: number
  totalPages: number
  onPage: (page: number) => void
  loading: boolean
  /** true = el sonido está pedido en la config pero el browser todavía no lo habilitó. */
  needsSoundUnlock: boolean
  onUnlockSound: () => void
  /** Trigger del diálogo de configuración. */
  children: React.ReactNode
}

export function KdsBottomBar({
  name,
  counts,
  page,
  totalPages,
  onPage,
  loading,
  needsSoundUnlock,
  onUnlockSound,
  children,
}: BottomBarProps) {
  return (
    <footer
      className="flex h-16 shrink-0 items-center gap-3 overflow-hidden border-t bg-card px-2 sm:gap-4 sm:px-4"
      style={{ fontSize: "clamp(0.875rem, calc(0.6rem + 0.6vw), 1.5rem)" }}
    >
      <div className="flex min-w-0 shrink items-center gap-2">
        <span className="truncate font-semibold">{name}</span>
        {loading && <RefreshCw className="size-4 shrink-0 animate-spin text-muted-foreground" />}
      </div>

      <div className="flex shrink-0 items-center gap-3 sm:gap-4">
        {COUNTER_ORDER.map((status) => {
          const visual = KDS_STATUS_VISUALS[status]
          return (
            <span key={status} className="flex items-center gap-1.5 whitespace-nowrap sm:gap-2">
              <span
                className="size-3 shrink-0 rounded-full"
                style={{ backgroundColor: visual.accent ?? undefined }}
                aria-hidden
              />
              <span className="font-bold tabular-nums">{counts[status]}</span>
              <span className="hidden text-muted-foreground sm:inline">
                {visual.label.toLowerCase()}
              </span>
              {/* El label completo siempre disponible para lectores de pantalla. */}
              <span className="sr-only">{visual.label}</span>
            </span>
          )
        })}
      </div>

      <div className="ml-auto flex shrink-0 items-center gap-1 sm:gap-2">
        {needsSoundUnlock && (
          <Button type="button" variant="outline" className="h-11" onClick={onUnlockSound}>
            <Volume2 className="size-5" />
            <span className="hidden sm:inline">Activar sonido</span>
          </Button>
        )}

        {/* Paginación — alto reservado siempre para que la barra no cambie. */}
        <div className="flex h-11 items-center gap-1 sm:gap-2">
          {totalPages > 1 && (
            <>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-11"
                aria-label="Comandas anteriores"
                onClick={() => onPage((page - 1 + totalPages) % totalPages)}
              >
                <ChevronLeft className="size-6" />
              </Button>

              {totalPages <= MAX_DOTS ? (
                <div className="flex items-center gap-1.5" role="tablist" aria-label="Páginas de comandas">
                  {Array.from({ length: totalPages }, (_, i) => (
                    <button
                      key={i}
                      type="button"
                      role="tab"
                      aria-selected={i === page}
                      aria-label={`Página ${i + 1}`}
                      onClick={() => onPage(i)}
                      className={`size-3 rounded-full transition-colors ${
                        i === page ? "bg-foreground" : "bg-muted-foreground/40"
                      }`}
                    />
                  ))}
                </div>
              ) : (
                <span className="whitespace-nowrap font-semibold tabular-nums">
                  {page + 1} / {totalPages}
                </span>
              )}

              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-11"
                aria-label="Comandas siguientes"
                onClick={() => onPage((page + 1) % totalPages)}
              >
                <ChevronRight className="size-6" />
              </Button>
            </>
          )}
        </div>

        {children}
      </div>
    </footer>
  )
}
