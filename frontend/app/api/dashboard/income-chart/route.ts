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
 * El JWT viaja por cookie `_jwt_panel` (domain .punto.la → server-to-server
 * tiene que pasarla manualmente porque fetch desde route handler no la
 * propaga automáticamente).
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
  // Reenviar el header `cookie` CRUDO al backend — igual que el catch-all
  // /api/v1, agent/chat, ocr y geo. NO extraer `_jwt_panel` por nombre para
  // re-acuñarla como `Authorization: Bearer`: `req.cookies.get()` de Next
  // devuelve UNA de dos cookies homónimas (la primera) mientras PHP parsea la
  // última, y mandar ese valor como Bearer le da PRECEDENCIA en `authResolve`
  // (api/includes/auth_session.php). Así este widget resolvía un tenant
  // distinto al del resto del dashboard — el leak cross-tenant que vio el owner
  // (2026-08-26): KPIs de la empresa correcta, barras de OTRA. Reenviando la
  // cookie cruda, income-chart resuelve por el MISMO `authResolve` que las
  // demás rutas de panel, así que no puede divergir por más emisores de
  // `_jwt_panel` que lleguen a existir. Además respeta §60 (cookie = panel,
  // Bearer = device): un valor de cookie nunca debe viajar como Bearer.
  const cookie = req.headers.get("cookie") ?? ""
  if (!/(?:^|;\s*)_jwt_panel=/.test(cookie)) {
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

  // Server-side, no CORS — reenviamos la cookie de sesión del panel tal cual.
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
      cookie,
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
