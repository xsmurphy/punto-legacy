"use client"

/**
 * Conteo del cierre de caja, MEDIO DE PAGO POR MEDIO DE PAGO.
 *
 * Por qué no es el `NumericPadDialog` de un solo monto que había antes: el
 * turno se cobra por muchas vías y el cajero tiene delante los vouchers de las
 * tarjetas y los comprobantes de QR igual que tiene los billetes. Pedirle solo
 * el efectivo dejaba el resto del turno sin arqueo (pedido del owner,
 * 2026-08-24).
 *
 * Forma de la interacción — una lista y UN pad
 * ───────────────────────────────────────────
 * Las filas son los medios; el pad de abajo edita la fila seleccionada. Es el
 * mismo pad que ya usa el resto de la caja (mismo tamaño, mismo
 * comportamiento as-you-type), y hay uno solo: un pad por fila obligaría a
 * scrollear la lista con el dedo tapando el teclado, y un `<input>` por fila
 * levantaría el teclado del sistema, que en una tablet de mostrador es peor
 * que no tener nada.
 *
 * El foco arranca en el efectivo porque es lo primero que se cuenta, y avanza
 * a la fila siguiente con Enter/Aceptar. En la última fila, Enter confirma el
 * cierre. Todo operable sin mouse (memoria `project_pos_touch_keyboard_first`).
 *
 * Control a ciegas
 * ────────────────
 * `blind` NO cambia qué se cuenta: cambia si se ve contra qué. Con el control
 * a ciegas prendido las filas no muestran esperado ni diferencia — el cajero
 * declara lo que contó y el veredicto lo emite el servidor, que es toda la
 * idea del arqueo a ciegas. La lista de medios sí se muestra: no ver los
 * acumulados no es lo mismo que no saber qué hay que contar.
 *
 * Posiciones estables (context/14 §10): la fila del efectivo está siempre en
 * el mismo lugar y el pad no se mueve al cambiar de fila. Lo único que cambia
 * es el resaltado de la fila activa.
 */

import * as React from "react"
import {
  ResponsiveDialog,
  ResponsiveDialogContent,
} from "@/components/ui/responsive-dialog"
import { Button } from "@/components/ui/button"
import { NumericPad } from "@/components/pos/numeric-pad"
import { formatMoney } from "@/lib/format-money"
import { cn } from "@/lib/utils"
import type { CountedMethod, ShiftMethod } from "@/lib/pos/local-shift-total"
import type { ServerCloseMethodRow } from "@/lib/pos/shift-close-reconciliation"

export interface DrawerCountDialogProps {
  open: boolean
  onClose: () => void
  /** Medios a contar. El efectivo viene primero y siempre está. */
  methods: ShiftMethod[]
  /**
   * Esperado por medio, indexado por `key`. Solo se usa con `blind=false`;
   * pasar `undefined` cuando no se conoce (sin conexión, por ejemplo) hace que
   * la fila no muestre referencia, sin cambiar nada más.
   */
  expected?: Record<string, number>
  blind: boolean
  isPending?: boolean
  config: Parameters<typeof formatMoney>[1]
  onConfirm: (counted: CountedMethod[]) => void
}

export function DrawerCountDialog({
  open,
  onClose,
  methods,
  expected,
  blind,
  isPending = false,
  config,
  onConfirm,
}: DrawerCountDialogProps) {
  // Un draft por medio, en string porque es lo que consume el pad (mantiene
  // los ceros y el punto a medio tipear sin que un Number() los coma).
  const [drafts, setDrafts] = React.useState<Record<string, string>>({})
  const [activeKey, setActiveKey] = React.useState<string>("")

  // Al abrir se arranca de cero y con el efectivo seleccionado. Se reinicia
  // por apertura y no por cambio de `methods`: la lista puede refrescarse sola
  // (una venta que sincroniza) y perder lo ya tipeado sería inaceptable.
  React.useEffect(() => {
    if (!open) return
    setDrafts(Object.fromEntries(methods.map((m) => [m.key, "0"])))
    setActiveKey(methods[0]?.key ?? "")
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  // Un medio que aparece con el diálogo ya abierto entra con su draft en cero
  // en vez de quedar `undefined` (que el pad leería como vacío).
  React.useEffect(() => {
    if (!open) return
    setDrafts((prev) => {
      const missing = methods.filter((m) => prev[m.key] === undefined)
      if (missing.length === 0) return prev
      return { ...prev, ...Object.fromEntries(missing.map((m) => [m.key, "0"])) }
    })
  }, [open, methods])

  const activeIndex = methods.findIndex((m) => m.key === activeKey)
  const isLast = activeIndex === methods.length - 1

  function confirm() {
    onConfirm(
      methods.map((m) => ({
        key: m.key,
        name: m.name,
        // El slug viaja con el conteo: es por donde el servidor empareja
        // cuando el nombre resuelto difiere del que anotó esta caja.
        code: m.code,
        isCash: m.isCash,
        counted: Number(drafts[m.key] ?? "0") || 0,
      })),
    )
  }

  /** Enter/Aceptar: baja a la fila siguiente; en la última, cierra la caja. */
  function advance() {
    if (isLast) {
      confirm()
      return
    }
    setActiveKey(methods[activeIndex + 1]?.key ?? activeKey)
  }

  return (
    <ResponsiveDialog open={open} onOpenChange={(v) => !v && onClose()}>
      <ResponsiveDialogContent sectioned className="sm:max-w-2xl">
        <div className="border-b px-6 py-4">
          <h2 className="text-lg font-semibold">Cerrar caja — conteo por medio de pago</h2>
          <p className="mt-1 text-sm text-muted-foreground">
            {blind
              ? "Contá cada medio y declará lo que encontraste. El arqueo lo hace el servidor."
              : "Contá cada medio. La diferencia con lo esperado se muestra al lado."}
          </p>
        </div>

        {/* Lista de medios. Alto acotado con scroll propio: con muchos medios
            el pad de abajo tiene que seguir en pantalla. */}
        <div className="max-h-[38vh] overflow-y-auto px-6 py-2">
          <div className="divide-y divide-border">
            {methods.map((m) => {
              const active = m.key === activeKey
              const draft = Number(drafts[m.key] ?? "0") || 0
              const exp = expected?.[m.key]
              const diff = !blind && exp !== undefined ? draft - exp : null
              return (
                <button
                  key={m.key}
                  type="button"
                  onClick={() => setActiveKey(m.key)}
                  // h-16 y ancho completo: se toca con el dedo en tablet.
                  className={cn(
                    "flex h-16 w-full items-center justify-between gap-3 rounded-md px-3 text-left transition-colors",
                    active ? "bg-accent" : "hover:bg-muted/50",
                  )}
                >
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium">{m.name}</p>
                    {!blind && exp !== undefined && (
                      <p className="text-xs text-muted-foreground">
                        Esperado {formatMoney(exp, config)}
                      </p>
                    )}
                  </div>
                  <div className="shrink-0 text-right">
                    <p className="text-lg font-semibold tabular-nums">
                      {formatMoney(draft, config)}
                    </p>
                    {diff !== null && diff !== 0 && (
                      <p
                        className={cn(
                          "text-xs tabular-nums",
                          diff < 0 ? "text-destructive" : "text-amber-600",
                        )}
                      >
                        {diff < 0 ? "Faltan " : "Sobran "}
                        {formatMoney(Math.abs(diff), config)}
                      </p>
                    )}
                  </div>
                </button>
              )
            })}
          </div>
        </div>

        {/* Pad único: edita la fila activa. */}
        <div className="border-t px-6 py-4">
          <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Contado en {methods[activeIndex]?.name ?? "—"}
          </p>
          <NumericPad
            mode="money"
            value={drafts[activeKey] ?? "0"}
            onChange={(v) => setDrafts((d) => ({ ...d, [activeKey]: v }))}
            onConfirm={advance}
            onCancel={onClose}
          />
        </div>

        <div className="border-t px-6 py-4">
          <Button
            onClick={confirm}
            className="w-full"
            size="lg"
            variant="destructive"
            disabled={isPending}
          >
            {isPending ? "Cerrando…" : "Cerrar caja"}
          </Button>
        </div>
      </ResponsiveDialogContent>
    </ResponsiveDialog>
  )
}

/**
 * El arqueo del cierre, ya calculado por el servidor: qué se esperaba de cada
 * medio, qué se contó y cuánto difiere.
 *
 * Se muestra UNA vez, justo después de cerrar, porque es la única oportunidad:
 * la caja ya está cerrada y el resumen del turno no se puede volver a pedir
 * desde el POS. El detalle permanente vive en Control de Cajas del panel.
 *
 * NO se abre con el control a ciegas prendido — el call-site ni lo intenta.
 * Ahí el dueño decidió que este cajero no ve acumulados, y el arqueo es
 * exactamente eso: mostrarlo acá sería romper la decisión justo en la pantalla
 * donde más pesa.
 */
export function DrawerCloseReportDialog({
  open,
  onClose,
  rows,
  config,
}: {
  open: boolean
  onClose: () => void
  rows: ServerCloseMethodRow[]
  config: Parameters<typeof formatMoney>[1]
}) {
  return (
    <ResponsiveDialog open={open} onOpenChange={(v) => !v && onClose()}>
      <ResponsiveDialogContent sectioned className="sm:max-w-2xl">
        <div className="border-b px-6 py-4">
          <h2 className="text-lg font-semibold">Caja cerrada — arqueo del turno</h2>
          <p className="mt-1 text-sm text-muted-foreground">
            Lo que el servidor esperaba de cada medio contra lo que declaraste.
          </p>
        </div>

        <div className="max-h-[55vh] overflow-y-auto px-6 py-2">
          <div className="divide-y divide-border">
            {rows.map((r) => (
              <div key={r.key} className="flex items-center justify-between gap-3 px-1 py-3">
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium">{r.name}</p>
                  <p className="text-xs tabular-nums text-muted-foreground">
                    Esperado {r.expected === null ? "—" : formatMoney(r.expected, config)}
                    {" · Contado "}
                    {r.counted === null ? "—" : formatMoney(r.counted, config)}
                  </p>
                </div>
                <span
                  className={cn(
                    "shrink-0 text-sm font-semibold tabular-nums",
                    r.difference === null || r.difference === 0
                      ? "text-muted-foreground"
                      : r.difference < 0
                        ? "text-destructive"
                        : "text-amber-600",
                  )}
                >
                  {r.difference === null
                    ? "Sin contar"
                    : r.difference === 0
                      ? "Cuadra"
                      : formatMoney(r.difference, config)}
                </span>
              </div>
            ))}
          </div>
        </div>

        <div className="border-t px-6 py-4">
          <Button onClick={onClose} className="w-full" size="lg">
            Entendido
          </Button>
        </div>
      </ResponsiveDialogContent>
    </ResponsiveDialog>
  )
}
