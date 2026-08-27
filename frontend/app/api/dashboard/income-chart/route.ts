import { NextRequest, NextResponse } from "next/server"

/**
 * BFF — Income Chart Dashboard.
 *
 *   GET /api/dashboard/income-chart?from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * Arquitectura (mismo patrón que /panel/bff/reports/summary.php?view=chart):
 *   - API (/v1/reports/sales?dataset=series): devuelve buckets crudos por
 *     día/hora con sales[]/expenses[] y flag isDay
 *   - Este BFF (Next route handler, server-side): reshape para el chart →
 *     buckets alineados con calendario completo (rellena ceros), calcula
 *     margen por bucket, totales agregados. Devuelve JSON listo para
 *     <LineChart>
 *   - Frontend (frontend page): solo renderea
 *
 * La credencial viaja en `Authorization: Bearer` (context/54 F2) y este
 * handler la reenvía tal cual al backend — un fetch desde un route handler no
 * propaga nada automáticamente.
 */

interface SeriesBucket {
  bucket: string  // 'YYYY-MM-DD' multi-día / '0'-'23' single
  total: number
  discount?: number
}

interface SeriesResponse {
  isDay: boolean
  sales: SeriesBucket[]
  expenses: SeriesBucket[]
}

export interface IncomeChartPoint {
  bucket: string         // label crudo: fecha ISO multi / hora '00' single
  ingresos: number       // = total - discount
  egresos: number
  margen: number         // = ingresos - egresos (clamped a >= 0)
}

export interface IncomeChartResponse {
  isDay: boolean
  data: IncomeChartPoint[]
  totals: {
    ingresos: number
    egresos: number
    margen: number
    average: number      // promedio de ingresos por bucket (para línea de referencia)
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
  const raw = envelope.data ?? { isDay: false, sales: [], expenses: [] }
  const shaped = shapeForChart(raw, from, to)
  return NextResponse.json({ ok: true, data: shaped })
}

/**
 * Convierte el dataset crudo (sales/expenses por bucket) a una serie
 * alineada con el calendario completo entre from y to. Rellena ceros para
 * días sin movimientos. Calcula totales agregados.
 */
function shapeForChart(raw: SeriesResponse, from: string, to: string): IncomeChartResponse {
  const isDay = !!raw.isDay
  const salesByBucket = new Map<string, SeriesBucket>()
  for (const r of raw.sales ?? []) salesByBucket.set(String(r.bucket), r)
  const expsByBucket = new Map<string, SeriesBucket>()
  for (const r of raw.expenses ?? []) expsByBucket.set(String(r.bucket), r)

  const buckets: string[] = isDay
    ? Array.from({ length: 24 }, (_, i) => String(i))
    : enumerateDates(from, to)

  let totalIng = 0
  let totalEgr = 0
  let totalMargen = 0
  const data: IncomeChartPoint[] = buckets.map((b) => {
    const s = salesByBucket.get(b)
    const e = expsByBucket.get(b)
    const ingresos = (s?.total ?? 0) - (s?.discount ?? 0)
    const egresos = e?.total ?? 0
    const margen = Math.max(0, ingresos - egresos)
    totalIng += ingresos
    totalEgr += egresos
    totalMargen += margen
    return { bucket: b, ingresos, egresos, margen }
  })

  const average = buckets.length > 0 ? totalIng / buckets.length : 0
  return {
    isDay,
    data,
    totals: {
      ingresos: totalIng,
      egresos: totalEgr,
      margen: totalMargen,
      average,
    },
  }
}

function enumerateDates(from: string, to: string): string[] {
  // 'YYYY-MM-DD HH:mm:ss' → tomar solo la parte de fecha.
  const start = new Date(from.slice(0, 10) + "T00:00:00Z")
  const end = new Date(to.slice(0, 10) + "T00:00:00Z")
  const dates: string[] = []
  const cur = new Date(start)
  while (cur <= end) {
    const yyyy = cur.getUTCFullYear()
    const mm = String(cur.getUTCMonth() + 1).padStart(2, "0")
    const dd = String(cur.getUTCDate()).padStart(2, "0")
    dates.push(`${yyyy}-${mm}-${dd}`)
    cur.setUTCDate(cur.getUTCDate() + 1)
  }
  return dates
}
