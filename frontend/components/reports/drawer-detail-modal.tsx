"use client"

import * as React from "react"
import { toast } from "sonner"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { MoneyInput } from "@/components/ui/money-input"
import { Separator } from "@/components/ui/separator"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { type DrawerRow, useCloseDrawerPanel, useDrawerDetail } from "@/hooks/use-reports"
import { formatMoney } from "@/lib/format"
import { formatDateTime } from "@/lib/format-date"
import { cn } from "@/lib/utils"
import { CashCountBadge } from "@/components/reports/cash-count-badge"

interface DrawerDetailModalProps {
  drawer: DrawerRow | null
  /** Margen de cuadre del comercio, para explicar un "Cuadra" con diferencia. */
  tolerance?: number
  onClose: () => void
  /** Llamado después de un cierre exitoso (además de onClose). */
  onClosed: () => void
}

export function DrawerDetailModal({ drawer, tolerance, onClose, onClosed }: DrawerDetailModalProps) {
  const { data: bootstrap } = useBootstrap()
  const closeDrawer = useCloseDrawerPanel()

  // Estado local del monto contado — se resetea al abrir un nuevo drawer.
  const [countedAmount, setCountedAmount] = React.useState<number | null>(null)
  const prevDrawerIdRef = React.useRef<string | null>(null)

  React.useEffect(() => {
    if (drawer?.drawerId !== prevDrawerIdRef.current) {
      setCountedAmount(null)
      prevDrawerIdRef.current = drawer?.drawerId ?? null
    }
  }, [drawer?.drawerId])

  function handleClose() {
    if (countedAmount === null) return
    const date = new Date().toISOString().slice(0, 19).replace("T", " ")
    closeDrawer.mutate(
      { drawerId: drawer!.drawerId, amount: countedAmount, date },
      {
        onSuccess: () => {
          toast.success("Caja cerrada")
          onClosed()
          onClose()
        },
        onError: (err) => {
          toast.error(err instanceof Error ? err.message : "Error al cerrar la caja")
        },
      },
    )
  }

  // Esperado y diferencia vienen RESUELTOS del backend (mig 164): congelados
  // al cerrar cuando el cierre es posterior a la migración, estimados y
  // marcados como tales cuando es anterior. El modal no recalcula — si lo
  // hiciera volvería el bug que el reporte tenía: sumar todos los medios de
  // pago contra un monto contado que es solo efectivo.
  const expected = drawer?.expectedAmount != null ? parseNum(drawer.expectedAmount) : null
  const diff = drawer?.difference != null ? parseNum(drawer.difference) : null

  // El cuadre en vivo del formulario de cierre usa el MISMO esperado que
  // después queda congelado, para que lo que el dueño ve antes de confirmar
  // sea lo que va a leer en el reporte.
  const liveDiff =
    countedAmount !== null && expected !== null ? countedAmount - expected : null

  // Arqueo por medio de pago (mig 167). Solo tiene sentido para una caja YA
  // cerrada: mientras está abierta no hay nada contado. Se pide aparte porque
  // el listado no lo trae — una fila del listado por caja con N medios adentro
  // multiplicaría el payload del reporte entero por un detalle que se mira de
  // a una caja por vez.
  const { data: detailData, isLoading: detailLoading } = useDrawerDetail(
    drawer?.isClosed ? drawer.drawerId : null,
  )
  const countRows = detailData?.detail?.countByMethod ?? []

  return (
    <Dialog
      open={drawer !== null}
      onOpenChange={(open) => {
        if (!open) onClose()
      }}
    >
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>
            {drawer?.outletName ?? ""} — {drawer?.registerName ?? ""}
          </DialogTitle>
          <DialogDescription asChild>
            <div>
              {drawer?.isClosed ? (
                <Badge variant="secondary">Cerrada</Badge>
              ) : (
                <Badge variant="default">En curso</Badge>
              )}
            </div>
          </DialogDescription>
        </DialogHeader>

        {drawer && (
          <div className="flex flex-col gap-4 text-sm">
            {/* Apertura */}
            <section className="grid grid-cols-2 gap-x-4 gap-y-1.5">
              <span className="text-muted-foreground">Apertura</span>
              <span className="tabular-nums text-right">{niceDateTime(drawer.openDate)}</span>
              <span className="text-muted-foreground">Abrió</span>
              <span className="text-right">{drawer.openUserName || "—"}</span>
              <span className="text-muted-foreground">Monto inicial</span>
              <span className="tabular-nums text-right">
                {formatMoney(parseNum(drawer.openAmount), bootstrap)}
              </span>
            </section>

            <Separator />

            {/* Movimientos */}
            <section className="flex flex-col gap-1.5">
              <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide mb-1">
                Movimientos
              </p>
              <MovRow label="Ventas" value={parseNum(drawer.sold)} bootstrap={bootstrap} />
              {/* Cuánto de esas ventas entró en billetes. Es el componente que
                  explica el esperado: sin esta fila, un turno con tarjeta
                  parece que "debería" tener mucho más en el cajón. */}
              <MovRow label="…de eso, en efectivo" value={parseNum(drawer.cashSold)} bootstrap={bootstrap} />
              <MovRow label="Ingresos" value={parseNum(drawer.income)} bootstrap={bootstrap} />
              <MovRow
                label="Egresos"
                value={-Math.abs(parseNum(drawer.expense))}
                bootstrap={bootstrap}
              />
              <MovRow
                label="Devoluciones"
                value={-Math.abs(parseNum(drawer.return))}
                bootstrap={bootstrap}
              />
            </section>

            <Separator />

            {/* Efectivo esperado — solo la parte cobrada en billetes: es contra
                eso que se cuenta el cajón, no contra el total del turno. */}
            <div className="flex justify-between font-medium">
              <span>Efectivo esperado</span>
              <span className="tabular-nums">
                {expected !== null ? formatMoney(expected, bootstrap) : "—"}
              </span>
            </div>

            {/* Sección cierre (solo si está cerrada) */}
            {drawer.isClosed && (
              <>
                <Separator />
                <section className="flex flex-col gap-1.5">
                  <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide mb-1">
                    Cierre
                  </p>
                  <div className="grid grid-cols-2 gap-x-4 gap-y-1.5">
                    <span className="text-muted-foreground">Cerró</span>
                    <span className="text-right">{drawer.closeUserName || "—"}</span>
                    <span className="text-muted-foreground">Fecha cierre</span>
                    <span className="tabular-nums text-right">
                      {niceDateTime(drawer.closeDate ?? "")}
                    </span>
                    <span className="text-muted-foreground">Efectivo declarado</span>
                    <span className="tabular-nums text-right">
                      {formatMoney(parseNum(drawer.closeAmount), bootstrap)}
                    </span>
                    <span className="text-muted-foreground">Cuadre</span>
                    <span className="flex justify-end">
                      <CashCountBadge
                        status={drawer.cashStatus}
                        difference={diff}
                        expectedSource={drawer.expectedSource}
                        tolerance={tolerance}
                        bootstrap={bootstrap}
                        size="md"
                      />
                    </span>
                  </div>
                </section>

                {/* Arqueo medio por medio (mig 167). El detalle se pide aparte
                    —el listado no lo trae— y solo cuando hay una caja abierta
                    en el modal. Un cierre anterior a la migración devuelve
                    únicamente la fila del cajón, marcada `estimated`: los
                    demás medios no se muestran en cero porque nadie los contó. */}
                {countRows.length > 0 && (
                  <>
                    <Separator />
                    <section className="flex flex-col gap-1.5">
                      <p className="mb-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                        Arqueo por medio de pago
                      </p>
                      {countRows.map((r) => (
                        <div key={r.key} className="flex items-baseline justify-between gap-3">
                          <span className="min-w-0 truncate text-muted-foreground">{r.name}</span>
                          <span className="flex shrink-0 items-center gap-2">
                            <span className="tabular-nums">
                              {formatMoney(parseNum(r.counted), bootstrap)}
                            </span>
                            <CashCountBadge
                              status={r.status}
                              difference={r.difference != null ? parseNum(r.difference) : null}
                              expectedSource={r.source}
                              tolerance={tolerance}
                              bootstrap={bootstrap}
                            />
                          </span>
                        </div>
                      ))}
                      {detailLoading && (
                        <p className="text-xs text-muted-foreground">Cargando el arqueo…</p>
                      )}
                    </section>
                  </>
                )}
              </>
            )}

            {/* Sección cerrar caja (solo si está abierta) */}
            {!drawer.isClosed && (
              <>
                <Separator />
                <section className="flex flex-col gap-3">
                  <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                    Cerrar caja
                  </p>
                  <div className="flex flex-col gap-1.5">
                    <label
                      htmlFor="counted-amount"
                      className="text-sm font-medium leading-none"
                    >
                      Monto contado
                    </label>
                    <MoneyInput
                      id="counted-amount"
                      value={countedAmount}
                      onChange={setCountedAmount}
                      placeholder="0"
                    />
                    {liveDiff !== null && (
                      <p
                        className={cn(
                          "text-xs tabular-nums",
                          // Mismo umbral que el backend (`CashCountStatus`):
                          // el piso de redondeo es una unidad mínima de la
                          // moneda. Acá no se conoce la tolerancia del
                          // comercio con certeza, así que se usa la que llega
                          // por prop y, si no llegó, el piso.
                          Math.abs(liveDiff) <= (tolerance ?? 1)
                            ? "text-emerald-600"
                            : liveDiff < 0
                              ? "text-destructive"
                              : "text-amber-600",
                        )}
                      >
                        {Math.abs(liveDiff) <= (tolerance ?? 1)
                          ? "Sin diferencia"
                          : liveDiff < 0
                            ? `Faltante: ${formatMoney(Math.abs(liveDiff), bootstrap)}`
                            : `Sobrante: ${formatMoney(liveDiff, bootstrap)}`}
                      </p>
                    )}
                  </div>
                  <Button
                    variant="destructive"
                    disabled={countedAmount === null || closeDrawer.isPending}
                    onClick={handleClose}
                    className="w-full"
                  >
                    {closeDrawer.isPending ? "Cerrando..." : "Cerrar caja"}
                  </Button>
                </section>
              </>
            )}
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}

// ── helpers ────────────────────────────────────────────────────────────────

function parseNum(v: unknown): number {
  if (typeof v === "number") return v
  if (typeof v === "string" && v !== "") {
    const n = Number(v)
    return Number.isFinite(n) ? n : 0
  }
  return 0
}


function niceDateTime(iso: string): string {
  if (!iso) return "—"
  return formatDateTime(iso)
}

interface MovRowProps {
  label: string
  value: number
  bootstrap: Parameters<typeof formatMoney>[1]
}

function MovRow({ label, value, bootstrap }: MovRowProps) {
  return (
    <div className="flex justify-between">
      <span className="text-muted-foreground">{label}</span>
      <span
        className={cn(
          "tabular-nums",
          value < 0 ? "text-destructive" : "",
        )}
      >
        {value < 0 ? "-" : ""}
        {formatMoney(Math.abs(value), bootstrap)}
      </span>
    </div>
  )
}
