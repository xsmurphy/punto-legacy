import { NextRequest, NextResponse } from "next/server"
import {
  asGranularity,
  averagePerBucket,
  type Granularity,
  type TimeBucket,
} from "@/lib/charts/granularity"
import type { SalesSeriesResponse, SalesSeriesRow } from "@/hooks/use-reports"

/**
 * BFF — Income Chart Dashboard.
 *
 *   GET /api/dashboard/income-chart?from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * Arquitectura (mismo patrón que /panel/bff/reports/summary.php?view=chart):
 *   - API (/v1/reports/sales?dataset=series): agrega en el SERVIDOR por hora
 *     (un solo día) o por día / semana / mes según el largo del rango
 *     (`api/lib/Support/TimeBuckets.php`) y devuelve el calendario completo
 *     (`buckets`, con los bordes `partial`) + ventas y egresos por bucket.
 *   - Este BFF (Next route handler, server-side): reshape para el chart →
 *     un punto por bucket del calendario (ceros donde no hubo movimiento),
 *     margen por bucket y totales. No enumera fechas ni decide el grano: eso
 *     es del servidor.
 *   - Frontend (frontend page): solo renderea
 *
 * La credencial viaja en `Authorization: Bearer` (context/54 F2) y este
 * handler la reenvía tal cual al backend — un fetch desde un route handler no
 * propaga nada automáticamente.
 */

// El contrato del dataset es UNO, compartido con los que lo leen directo.
type SeriesResponse = Partial<Pick<SalesSeriesResponse, "granularity" | "buckets">> &
  Pick<SalesSeriesResponse, "isDay" | "sales" | "expenses">
type SeriesBucket = SalesSeriesRow

export interface IncomeChartPoint extends TimeBucket {
  ingresos: number       // = total - discount
  egresos: number
  margen: number         // = ingresos - egresos (clamped a >= 0)
}

export interface IncomeChartResponse {
  isDay: boolean
  granularity: Granularity
  data: IncomeChartPoint[]
  totals: {
    ingresos: number
    egresos: number
    margen: number
    /** Ingresos promedio por período COMPLETO (línea de referencia). */
    average: number
  }
}

export async function GET(req: NextRequest) {
  // Reenviar el `Authorization` entrante TAL CUAL (context/54 F2). El panel es
  // Bearer: el token lo manda `lib/api-client.ts` desde `lib/auth/panel-token.ts`.
  //
  // Este handler fue el origen del leak cross-tenant del 2026-08-26: extraía
  // `_jwt_panel` por nombre con `req.cookies.get()` y la re-acuñaba como Bearer.
  // Con dos cookies homónimas en scopes distintos, Next devolvía una y PHP
  // parseaba la otra, así que el chart resolvía un tenant y el resto del
  // dashboard otro. Sin cookies en juego, esa clase de bug no existe: hay una
  // sola credencial y es la que el cliente eligió mandar. No volver a leer
  // cookies acá.
  const auth = req.headers.get("authorization") ?? ""
  if (!/^Bearer\s+\S+/i.test(auth)) {
    return NextResponse.json({ ok: false, error: "no autenticado" }, { status: 401 })
  }

  const searchParams = req.nextUrl.searchParams
  const from = searchParams.get("from")
  const to = searchParams.get("to")
  if (!from || !to) {
    return NextResponse.json(
      { ok: false, error: "from y to requeridos (YYYY-MM-DD HH:mm:ss)" },
      { status: 422 },
    )
  }

  // Server-side, no CORS — reenviamos el Bearer del panel tal cual.
  const apiBase = process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL
  if (!apiBase) {
    return NextResponse.json({ ok: false, error: "API_URL no configurada" }, { status: 500 })
  }
  const url = `${apiBase.replace(/\/$/, "")}/v1/reports/sales?dataset=series&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`

  // Reenviar el view-scope de sucursal seleccionado en el panel para que el chart
  // se filtre por la MISMA sucursal que el resto del dashboard. Sin esto el chart
  // quedaba fijo en el outlet del JWT y no actualizaba al cambiar de sucursal.
  const viewOutlet = req.headers.get("x-outlet-id")

  const res = await fetch(url, {
    headers: {
      Authorization: auth,
      Accept: "application/json",
      ...(viewOutlet ? { "X-Outlet-Id": viewOutlet } : {}),
    },
    cache: "no-store",
  })

  if (!res.ok) {
    const body = await res.text().catch(() => "")
    return NextResponse.json(
      { ok: false, error: `API ${res.status}`, body: body.slice(0, 500) },
      { status: res.status },
    )
  }

  const envelope = (await res.json()) as { ok?: boolean; data?: SeriesResponse }
  const raw = envelope.data ?? { isDay: false, buckets: [], sales: [], expenses: [] }
  const shaped = shapeForChart(raw)
  return NextResponse.json({ ok: true, data: shaped })
}

/**
 * Un punto por bucket del calendario que mandó el servidor, con ceros donde no
 * hubo movimiento, margen por bucket y totales.
 */
function shapeForChart(raw: SeriesResponse): IncomeChartResponse {
  const salesByBucket = new Map<string, SeriesBucket>()
  for (const r of raw.sales ?? []) salesByBucket.set(String(r.bucket), r)
  const expsByBucket = new Map<string, SeriesBucket>()
  for (const r of raw.expenses ?? []) expsByBucket.set(String(r.bucket), r)

  let totalIng = 0
  let totalEgr = 0
  let totalMargen = 0
  const data: IncomeChartPoint[] = (raw.buckets ?? []).map((b) => {
    const s = salesByBucket.get(String(b.bucket))
    const e = expsByBucket.get(String(b.bucket))
    const ingresos = (s?.total ?? 0) - (s?.discount ?? 0)
    const egresos = e?.total ?? 0
    const margen = Math.max(0, ingresos - egresos)
    totalIng += ingresos
    totalEgr += egresos
    totalMargen += margen
    return { bucket: String(b.bucket), end: String(b.end), partial: !!b.partial, ingresos, egresos, margen }
  })

  return {
    isDay: !!raw.isDay,
    granularity: asGranularity(raw.granularity),
    data,
    totals: {
      ingresos: totalIng,
      egresos: totalEgr,
      margen: totalMargen,
      average: averagePerBucket(data, (p) => p.ingresos),
    },
  }
}
