"use client"

import * as React from "react"
import {
  Bluetooth, CheckCircle2, Loader2, Network, Plus, Printer, RefreshCw,
  RotateCcw, Usb, XCircle,
} from "lucide-react"
import { toast } from "sonner"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { EmptyState } from "@/components/empty-state"
import { DeviceNotConnected } from "@/components/layout/device-not-connected"
import { usePairedScreen } from "@/hooks/use-paired-screen"
import { getDeviceClaims } from "@/lib/auth/device-claims"
import { buildDrawerPulse, buildTestTicket } from "@/lib/hardware/printers/encoder"
import { triggerWindowPrint } from "@/lib/hardware/printers/transports/window-print"
import {
  claimJob, fetchPendingJobs, fetchStationPrinters, markJobDone, markJobFailed,
} from "@/lib/print-station/api"
import { dispatchJob, resolveLinks, sendToPrinter, type UsbHandleMap } from "@/lib/print-station/dispatch"
import type { PrintJob, StationLogEntry, StationPrinter } from "@/lib/print-station/types"
import { AddPrinterDialog } from "./add-printer-dialog"

/**
 * Estación de Impresión — pantalla device-paired (P1,
 * context/26-print-station-plan.md). Corre en la PC que tiene las impresoras
 * físicas conectadas.
 *
 * Es un ROUTER TONTO: acá NO se configura nada de negocio (ni plantillas, ni
 * ruteo, ni bindings). El payload llega ya renderizado desde el POS/panel y la
 * estación solo lo escribe en la impresora que indica el job. Lo único que se
 * configura es la conexión física.
 *
 * Pairing/WS/heartbeat: `usePairedScreen` (mismo hook que KDS y pantalla de
 * mozos, module `print`, canal `{companyId}:print:{outletId}`). La cola durable
 * vive en BD: el WS solo notifica, y al abrir/reconectar re-sincronizamos con
 * GET ?resource=pending — un comando de cocina no se pierde porque la estación
 * estaba offline.
 *
 * Dark forzado con `.dark` en el wrapper, igual que el KDS: `(screen)/layout.tsx`
 * fija `forcedTheme="light"` para el visor al cliente, y Tailwind v4 escopea el
 * theme con `@custom-variant dark (&:is(.dark *))`.
 */

/** Red de seguridad del drenado: reintentos de jobs re-encolados y jobs que
 *  llegaron mientras el WS estaba caído. NO es el camino principal (ese es el
 *  evento WS) — por eso 10s y no 1s. */
const DRAIN_INTERVAL_MS = 10_000
const LOG_LIMIT = 50

const TRANSPORT_ICON = {
  usb: Usb,
  bluetooth: Bluetooth,
  network: Network,
  native: Printer,
} as const

export default function PrintStationPage() {
  const [printers, setPrinters] = React.useState<StationPrinter[]>([])
  const [linked, setLinked] = React.useState<Set<string>>(new Set())
  const [pending, setPending] = React.useState<PrintJob[]>([])
  const [log, setLog] = React.useState<StationLogEntry[]>([])
  const [addOpen, setAddOpen] = React.useState(false)
  const [busy, setBusy] = React.useState(false)
  const [testingId, setTestingId] = React.useState<string | null>(null)

  // Refs: el loop de la cola corre fuera del ciclo de render y necesita el
  // valor actual, no el capturado en el closure de un efecto.
  const printersRef = React.useRef<StationPrinter[]>([])
  const linkedRef = React.useRef<Set<string>>(new Set())
  const usbHandlesRef = React.useRef<UsbHandleMap>(new Map())
  const drainingRef = React.useRef(false)
  const mountedRef = React.useRef(true)
  const drawerPulseRef = React.useRef<Uint8Array | null>(null)

  function drawerPulse(): Uint8Array {
    drawerPulseRef.current ??= buildDrawerPulse()
    return drawerPulseRef.current
  }

  const pushLog = React.useCallback((entry: StationLogEntry) => {
    if (!mountedRef.current) return
    setLog((prev) => {
      const rest = prev.filter((e) => e.jobId !== entry.jobId)
      return [entry, ...rest].slice(0, LOG_LIMIT)
    })
  }, [])

  // ── Impresoras físicas ───────────────────────────────────────────────────

  const refreshPrinters = React.useCallback(async () => {
    const deviceId = getDeviceClaims("print")?.deviceId ?? ""
    try {
      const all = await fetchStationPrinters()
      // El realm pos-app scopea por outlet; las impresoras de OTRAS estaciones
      // del mismo outlet no las manejamos nosotros.
      const mine = all.filter((p) => p.deviceId === deviceId && p.status === 1)
      const { linked: linkedIds, usbHandles } = await resolveLinks(mine)

      printersRef.current = mine
      linkedRef.current = linkedIds
      usbHandlesRef.current = usbHandles
      if (!mountedRef.current) return
      setPrinters(mine)
      setLinked(linkedIds)
    } catch (err) {
      // Red caída o permisos WebUSB/BT no disponibles: seguimos con el último
      // estado conocido en vez de dejar la estación sin impresoras. Se loguea
      // porque esta pantalla corre desatendida días — sin rastro, una estación
      // trabada enumerando hardware es indiagnosticable.
      console.warn("[print-station] no se pudo refrescar las impresoras:", err)
    }
  }, [])

  // ── Loop de la cola ──────────────────────────────────────────────────────

  /**
   * Despacha un job YA reclamado (status `printing`) y cierra la transición.
   * Nunca tira: un error de una impresora no puede cortar el drenado de las
   * demás ni romper el "Reintentar".
   */
  const runClaimedJob = React.useCallback(async (job: PrintJob, printer: StationPrinter) => {
    pushLog({ jobId: job.id, at: Date.now(), job, printerName: printer.name, state: "printing" })
    try {
      await dispatchJob(job, printer, usbHandlesRef.current, drawerPulse())
      await markJobDone(job.id)
      pushLog({ jobId: job.id, at: Date.now(), job, printerName: printer.name, state: "done" })
    } catch (err) {
      const message = err instanceof Error ? err.message : String(err)
      try {
        await markJobFailed(job.id, message)
      } catch { /* quedó en `printing`; el backend es la fuente de verdad */ }
      pushLog({
        jobId: job.id, at: Date.now(), job,
        printerName: printer.name, state: "failed", error: message,
      })
    }
  }, [pushLog])

  /**
   * Una pasada, serializada y acotada: lee los pendientes, y para cada job de
   * una impresora vinculada acá hace claim → dispatch → done|failed.
   *
   * Invariantes:
   *  - `drainingRef` garantiza UNA sola pasada activa: sin él, un burst de
   *    eventos WS dispararía claims en paralelo sobre el mismo job.
   *  - Una sola pasada por invocación (nunca re-entra sobre sí misma): un job
   *    que falla vuelve a `queued` en el backend y reaparecería para siempre.
   *    El reintento lo dispara el intervalo, no un bucle.
   *  - Cada job va en su propio try/catch (dentro de runClaimedJob): una
   *    impresora rota no corta el drenado de las demás.
   */
  const drain = React.useCallback(async () => {
    if (drainingRef.current) return
    drainingRef.current = true
    if (mountedRef.current) setBusy(true)
    try {
      let jobs: PrintJob[]
      try {
        jobs = await fetchPendingJobs()
      } catch {
        return
      }
      if (mountedRef.current) setPending(jobs)

      for (const job of jobs) {
        if (!mountedRef.current) return
        if (!linkedRef.current.has(job.stationPrinterId)) continue // sin vincular: queda en cola
        const printer = printersRef.current.find((p) => p.id === job.stationPrinterId)
        if (!printer) continue

        const claimed = await claimJob(job.id)
        if (!claimed.ok) continue // otra estación lo tomó — sin ruido

        await runClaimedJob(job, printer)
      }

      // Refresco final del contador (los que quedaron sin impresora vinculada).
      try {
        const left = await fetchPendingJobs()
        if (mountedRef.current) setPending(left)
      } catch { /* best-effort */ }
    } finally {
      drainingRef.current = false
      if (mountedRef.current) setBusy(false)
    }
  }, [runClaimedJob])

  const resync = React.useCallback(async () => {
    await refreshPrinters()
    await drain()
  }, [refreshPrinters, drain])

  const { pairState, ctx } = usePairedScreen({
    module: "print",
    channels: (c) => [`${c.companyId}:print:${c.outletId}`],
    onEvent: (event, data) => {
      if (event === "job:new") { void drain(); return }
      // `job:update` lo publica también NUESTRO propio claim/done/failed. Solo
      // drenamos si el job volvió a `queued` (re-encolado por reintento o
      // liberado por otra estación) — sin este filtro, cada done disparaba un
      // drain vacío y se realimentaba solo.
      if (event === "job:update" && (data as PrintJob | null)?.status === "queued") void drain()
    },
    onOpen: () => { void resync() },
  })

  React.useEffect(() => {
    mountedRef.current = true
    const id = setInterval(() => { void drain() }, DRAIN_INTERVAL_MS)
    return () => {
      mountedRef.current = false
      clearInterval(id)
    }
  }, [drain])

  // ── Acciones de UI ───────────────────────────────────────────────────────

  async function testPrinter(printer: StationPrinter) {
    setTestingId(printer.id)
    try {
      if (printer.transport === "native") {
        triggerWindowPrint("<html><body><p>Punto — Test de impresión</p></body></html>")
      } else {
        await sendToPrinter(printer, buildTestTicket({ paperWidthMm: 80 }), usbHandlesRef.current)
      }
      toast.success(`Prueba enviada a ${printer.name}`)
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "No se pudo imprimir la prueba")
    } finally {
      setTestingId(null)
    }
  }

  /**
   * Reintento manual: re-claim del job y re-despacho. El backend solo permite
   * el CAS desde `queued` — un job con los 3 intentos agotados quedó en
   * `failed` y NO hay endpoint para re-encolarlo, así que mostramos el error
   * del backend tal cual en vez de inventar una transición nueva.
   */
  async function retryJob(entry: StationLogEntry) {
    if (drainingRef.current) return // no pisar el drenado en curso
    const printer = printersRef.current.find((p) => p.id === entry.job.stationPrinterId)
    if (!printer) {
      toast.error("La impresora de este trabajo ya no está vinculada en esta estación")
      return
    }
    drainingRef.current = true
    try {
      const res = await claimJob(entry.job.id)
      if (!res.ok) {
        toast.error(res.error ?? "No se pudo reintentar el trabajo")
        return
      }
      await runClaimedJob(entry.job, printer)
    } finally {
      drainingRef.current = false
    }
  }

  if (pairState === "unpaired") {
    return <DeviceNotConnected kind="print" />
  }

  const waiting = pending.filter((j) => !linked.has(j.stationPrinterId))

  return (
    <div className="dark flex h-screen flex-col bg-background text-foreground">
      <header className="flex items-center justify-between gap-3 border-b px-6 py-4">
        <div className="flex items-baseline gap-3">
          <h1 className="text-2xl font-semibold">
            Estación de impresión — {ctx?.outletName ?? "Sucursal"}
          </h1>
          <Badge variant={pairState === "ready" ? "secondary" : "outline"}>
            {pairState === "ready" ? "Conectada" : "Reconectando..."}
          </Badge>
        </div>
        <div className="flex items-center gap-3">
          <span className="text-sm text-muted-foreground">
            {pending.length} pendiente{pending.length === 1 ? "" : "s"}
          </span>
          {busy ? <Loader2 className="size-4 animate-spin text-muted-foreground" /> : null}
          <Button variant="outline" size="sm" onClick={() => void resync()} disabled={busy}>
            <RefreshCw className="size-4" />
            Actualizar
          </Button>
          <Button size="sm" onClick={() => setAddOpen(true)}>
            <Plus className="size-4" />
            Agregar impresora
          </Button>
        </div>
      </header>

      <main className="flex flex-1 flex-col gap-6 overflow-y-auto p-6">
        <section className="flex flex-col gap-4">
          <h2 className="text-base font-semibold tracking-tight">Impresoras</h2>
          {printers.length === 0 ? (
            <EmptyState
              icon={Printer}
              title="Sin impresoras vinculadas"
              description="Agregá las impresoras conectadas a esta computadora para que la estación pueda imprimir."
            />
          ) : (
            <div className="grid grid-cols-[repeat(auto-fill,minmax(280px,1fr))] gap-4">
              {printers.map((printer) => {
                const Icon = TRANSPORT_ICON[printer.transport]
                const isLinked = linked.has(printer.id)
                return (
                  <Card key={printer.id}>
                    <CardHeader className="flex flex-row items-start justify-between gap-2">
                      <CardTitle className="flex items-center gap-2 text-base font-semibold tracking-tight">
                        <Icon className="size-4 text-muted-foreground" />
                        {printer.name}
                      </CardTitle>
                      <Badge variant={isLinked ? "secondary" : "destructive"}>
                        {isLinked ? "Vinculada" : "Sin vincular"}
                      </Badge>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                      <p className="text-xs text-muted-foreground">
                        {printer.transport === "network"
                          ? `${printer.transportConfig.networkHost}:${printer.transportConfig.networkPort ?? 9100}`
                          : printer.transportConfig.deviceLabel || printer.transport}
                      </p>
                      {isLinked ? (
                        <Button
                          variant="outline"
                          size="sm"
                          className="self-start"
                          disabled={testingId === printer.id}
                          onClick={() => void testPrinter(printer)}
                        >
                          {testingId === printer.id ? <Loader2 className="size-4 animate-spin" /> : null}
                          Probar
                        </Button>
                      ) : (
                        <p className="text-xs text-muted-foreground">
                          El navegador perdió el permiso del dispositivo. Agregala de nuevo
                          para re-autorizarla.
                        </p>
                      )}
                    </CardContent>
                  </Card>
                )
              })}
            </div>
          )}
        </section>

        {waiting.length > 0 ? (
          <section className="flex flex-col gap-2">
            <h2 className="text-base font-semibold tracking-tight">En espera</h2>
            <ul className="flex flex-col gap-1">
              {waiting.map((job) => (
                <li key={job.id} className="text-sm text-muted-foreground">
                  {job.docType ?? "Documento"} — esperando impresora{" "}
                  {printers.find((p) => p.id === job.stationPrinterId)?.name ?? job.stationPrinterId}
                </li>
              ))}
            </ul>
          </section>
        ) : null}

        <section className="flex flex-col gap-2">
          <h2 className="text-base font-semibold tracking-tight">Actividad</h2>
          {log.length === 0 ? (
            <p className="text-sm text-muted-foreground">Todavía no se imprimió nada en esta sesión.</p>
          ) : (
            <ul className="flex flex-col divide-y rounded-md border">
              {log.map((entry) => (
                <li key={entry.jobId} className="flex items-center gap-3 px-4 py-2 text-sm">
                  <span className="tabular-nums text-xs text-muted-foreground">
                    {new Date(entry.at).toLocaleTimeString("es", {
                      hour: "2-digit", minute: "2-digit", second: "2-digit",
                    })}
                  </span>
                  <span className="w-40 shrink-0 truncate">{entry.job.docType ?? "Documento"}</span>
                  <span className="w-40 shrink-0 truncate text-muted-foreground">{entry.printerName}</span>
                  <span className="flex flex-1 items-center gap-1.5">
                    {entry.state === "printing" ? (
                      <><Loader2 className="size-3.5 animate-spin" />Imprimiendo</>
                    ) : entry.state === "done" ? (
                      <><CheckCircle2 className="size-3.5" />Impreso</>
                    ) : (
                      <>
                        <XCircle className="size-3.5 text-destructive" />
                        <span className="truncate text-destructive">{entry.error ?? "Error"}</span>
                      </>
                    )}
                  </span>
                  {entry.state === "failed" ? (
                    <Button variant="outline" size="sm" onClick={() => void retryJob(entry)}>
                      <RotateCcw className="size-4" />
                      Reintentar
                    </Button>
                  ) : null}
                </li>
              ))}
            </ul>
          )}
        </section>
      </main>

      <AddPrinterDialog
        open={addOpen}
        onOpenChange={setAddOpen}
        onRegistered={() => { void resync() }}
      />
    </div>
  )
}
