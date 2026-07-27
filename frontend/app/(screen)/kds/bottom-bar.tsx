"use client"

import * as React from "react"
import { ChevronLeft, ChevronRight, Keyboard, RefreshCw, Undo2, Volume2, Wifi, WifiOff } from "lucide-react"
import { Button } from "@/components/ui/button"
import type { WsState } from "@/hooks/use-paired-screen"
import { KDS_STATUS_VISUALS, kdsTextHex, type KdsMode, type KdsOrderStatus } from "@/lib/kds/kds-visuals"

/**
 * Barra inferior fija del KDS. Reemplaza lo único que aportaban las columnas
 * por estado: el conteo. "3 en espera · 2 en proceso · 1 lista" da la misma
 * información de un vistazo sin gastar dos tercios del ancho de la pantalla en
 * títulos de columna.
 *
 * Los dos primeros contadores son lo que está EN el board (lo que falta hacer);
 * el tercero es lo que ya salió y sigue esperando ser retirado — se llega a esas
 * comandas por el botón de recall, que vive al lado.
 *
 * Posiciones estables (14-ui-conventions.md Regla #10): los tres contadores se
 * renderizan SIEMPRE, también en cero, y el bloque de paginación y el de
 * conexión reservan su lugar aunque no tengan nada que decir. Nada de acá se
 * mueve cuando cambia el estado de una comanda.
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
  mode: KdsMode
  page: number
  totalPages: number
  /** Comandas que existen pero no entran en la página visible. */
  hiddenCount: number
  onPage: (page: number) => void
  loading: boolean
  /** Estado del WebSocket — ver `wsState` en use-paired-screen. */
  wsState: WsState
  /** true = el sonido está pedido en la config pero el browser todavía no lo habilitó. */
  needsSoundUnlock: boolean
  onUnlockSound: () => void
  /** false = no hay nada que deshacer (el botón queda visible pero inerte). */
  canUndo: boolean
  onUndo: () => void
  onShowHelp: () => void
  /** Triggers de los diálogos (recall, configuración). */
  children: React.ReactNode
}

export function KdsBottomBar({
  name,
  counts,
  mode,
  page,
  totalPages,
  hiddenCount,
  onPage,
  loading,
  wsState,
  needsSoundUnlock,
  onUnlockSound,
  canUndo,
  onUndo,
  onShowHelp,
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
                // Tono del modo: el mismo punto sobre fondo blanco se lava.
                style={{ backgroundColor: visual.accent ? kdsTextHex(visual.accent, mode) : undefined }}
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
        {/* Conexión. Sin WS la pantalla NO se entera de nada nuevo, y al volver
            re-sincroniza de golpe: sin este indicador eso se lee como "las
            comandas cambian solas". Discreto y de ancho fijo — el ícono está
            siempre, solo cambia. */}
        <span
          className="flex items-center gap-1.5"
          title={wsState === "online" ? "Conectado" : "Reconectando…"}
        >
          {wsState === "online" ? (
            <Wifi className="size-5 shrink-0 text-muted-foreground" aria-hidden />
          ) : (
            <WifiOff
              className="size-5 shrink-0"
              style={{ color: kdsTextHex(KDS_STATUS_VISUALS.sent.accent ?? "", mode) }}
              aria-hidden
            />
          )}
          {wsState !== "online" && (
            <span className="hidden text-muted-foreground md:inline">Reconectando…</span>
          )}
          <span className="sr-only">{wsState === "online" ? "Conectado" : "Reconectando"}</span>
        </span>

        {/* Deshacer lo último marcado. Existe también como atajo (Z) y como
            long-press sobre la línea, pero tiene que estar VISIBLE: nadie
            adivina un atajo cuando acaba de marcar la comanda equivocada. */}
        <Button
          type="button"
          variant="outline"
          className="h-11 gap-2"
          disabled={!canUndo}
          aria-label="Deshacer la última acción"
          onClick={onUndo}
        >
          <Undo2 className="size-5" />
          <span className="hidden sm:inline">Deshacer</span>
        </Button>

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
              {/* Lo que NO entra en pantalla se dice, no se insinúa. La
                  rotación automática está apagada por default, así que este
                  contador es lo único que impide que la página 2 quede
                  escondida en silencio: va con peso visual real (sólido, en
                  negrita) y además pagina al tocarlo. */}
              {hiddenCount > 0 && (
                <Button
                  type="button"
                  className="h-11 shrink-0 font-bold tabular-nums"
                  aria-label={`${hiddenCount} comandas más, ver siguiente página`}
                  onClick={() => onPage((page + 1) % totalPages)}
                >
                  +{hiddenCount}
                  <span className="hidden sm:inline">&nbsp;comandas</span>
                </Button>
              )}

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

        {/* Los atajos no se adivinan: la leyenda es discreta pero está siempre. */}
        <Button
          type="button"
          variant="ghost"
          className="h-11 gap-2"
          aria-label="Atajos de teclado"
          onClick={onShowHelp}
        >
          <Keyboard className="size-6" />
          <span className="hidden font-semibold tabular-nums lg:inline">?</span>
        </Button>

        {children}
      </div>
    </footer>
  )
}
