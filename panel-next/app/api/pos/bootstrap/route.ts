/**
 * BFF — POS Bootstrap (Slice A6).
 *
 * Punto de entrada del catálogo del POS. Compone en UNA sola respuesta todo
 * lo que el store de catálogo necesita para hidratar en memoria. Hace 3
 * fetches en paralelo al backend `/api/v1/*` con loopback in-container:
 *
 *   GET /v1/bootstrap          → config tenant + user + outlet activo
 *   GET /v1/items?limit=500    → items vendibles (filtra itemCanSale+status)
 *   GET /v1/contacts?type=1    → clientes
 *
 * Auth: requiere cookie `_jwt` (realm `pos-app`). Si falta o expira → 401.
 *
 * Diferencias vs el catch-all `/api/v1/[...path]`:
 *   - Reshapea cada upstream a `PosBootstrap` (los shapes del backend NO
 *     coinciden 1:1 con los tipos del front; ver `lib/types/pos-bootstrap.ts`).
 *   - Si CUALQUIER upstream devuelve 401, propaga 401 (no parcial).
 *   - Si cualquier upstream devuelve 5xx, loguea snippet y responde 502.
 *
 * Loopback in-container y env vars: mismo patrón que el catch-all.
 *
 * Ver context/16-app-next-rewrite.md §4 (arquitectura BFF) y §7 Slice A6.
 */

import { NextRequest, NextResponse } from "next/server"
import type {
  PosBootstrap,
  PosConfig,
  PosCustomer,
  PosItem,
  PosRegister,
  PosUser,
  PaymentMethodConfig,
} from "@/lib/types/pos-bootstrap"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

// ── Fallback de métodos de pago ───────────────────────────────────────────────
// Hardcoded hasta que el owner decida exponer taxonomy paymentMethod via /v1.

const FALLBACK_PAYMENT_METHODS: PaymentMethodConfig[] = [
  { id: "efectivo", name: "Efectivo", code: "A", hasChange: true, requiresIdentifier: false, isDefault: true },
  {
    id: "tcredito",
    name: "T. Crédito",
    code: "S",
    hasChange: false,
    requiresIdentifier: true,
    identifierLabel: "Nro de operación",
    identifierPlaceholder: "Ej. 123456",
    isDefault: true,
  },
  {
    id: "tdebito",
    name: "T. Débito",
    code: "D",
    hasChange: false,
    requiresIdentifier: true,
    identifierLabel: "Nro de operación",
    identifierPlaceholder: "Ej. 123456",
    isDefault: true,
  },
]

// ── Resolución de upstream (idéntica al catch-all) ────────────────────────────

function getTargetBase(): string {
  const loopbackBase = process.env.PUNTO_SHARED_API_BASE
  const url =
    loopbackBase ?? process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL
  if (!url) {
    throw new Error(
      "API base URL missing. Set PUNTO_SHARED_API_BASE, API_URL, or NEXT_PUBLIC_API_URL.",
    )
  }
  return url.replace(/\/$/, "")
}

const HOST_OVERRIDE = process.env.PUNTO_SHARED_API_HOST

// ── Shapes upstream (lo que /v1/* devuelve realmente) ────────────────────────

interface UpstreamEnvelope<T> {
  ok?: boolean
  data?: T
  error?: { message?: string; code?: number }
}

interface UpstreamBootstrap {
  currency: string
  decimal: string
  thousand: "comma" | "dot"
  taxName: string
  tinName: string
  country: string
  companyName: string
  companyId: string | number
  publicUrl: string
  user: { id: string | number; role: number }
  activeOutletId: string
  activeOutletName: string
  activeRegisterId?: string
  outlets: Array<{ id: string; name: string }>
}

interface UpstreamRegisterRow {
  id: string
  name: string
}

interface UpstreamRegisterList {
  registers: UpstreamRegisterRow[]
}

// Shape real de /v1/items rows — viene de presentItem() + _flattenJsonb().
// Los campos JSONB demoted (itemTaxIncluded, itemUOM, etc.) aparecen
// flattened a top-level. Campos opcionales nullables = la BD puede no
// tenerlos.
interface UpstreamItemRow {
  itemId: string
  itemName: string
  itemSKU?: string | null
  itemPrice?: number | string | null
  itemStatus?: number | boolean | string
  itemCanSale?: boolean
  itemTrackInventory?: boolean
  itemIsParent?: boolean
  itemParentId?: string | null
  itemTaxIncluded?: boolean
  itemUOM?: string | null
  taxId?: string | null
  categoryId?: string | null
  categoryName?: string | null
  coverImageUrl?: string | null
  kind?: string
}

interface UpstreamItemsList {
  items: UpstreamItemRow[]
  total: number
  limit: number
  offset: number
}

interface UpstreamContactRow {
  id: string
  name: string
  phone: string | null
  tin: string | null
  storeCredit: number | string | null
  status: string | number | null
}

interface UpstreamContactsList {
  contacts: UpstreamContactRow[]
  total: number
}

interface UpstreamUserRow {
  id: string
  name: string
  lockPass?: string | null
  lockPassHash?: string | null
  pinhash?: string | null
  status: number
}

interface UpstreamUsersList {
  users: UpstreamUserRow[]
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function buildUpstreamHeaders(req: NextRequest): Headers {
  const h = new Headers()
  // Reenviar solo lo que el backend PHP necesita: cookie (_jwt) y accept.
  const cookie = req.headers.get("cookie")
  if (cookie) h.set("cookie", cookie)
  h.set("accept", "application/json")
  if (HOST_OVERRIDE) h.set("host", HOST_OVERRIDE)
  return h
}

async function fetchUpstream<T>(
  base: string,
  path: string,
  headers: Headers,
): Promise<{ status: number; data: T | null; rawText: string }> {
  const res = await fetch(`${base}${path}`, {
    method: "GET",
    headers,
    cache: "no-store",
    redirect: "manual",
  })
  const rawText = await res.text()
  let envelope: UpstreamEnvelope<T> | null = null
  try {
    envelope = rawText ? (JSON.parse(rawText) as UpstreamEnvelope<T>) : null
  } catch {
    envelope = null
  }
  // El backend usa envelope { ok, data } — devolvemos `data` si está presente,
  // si no devolvemos null para que el caller decida.
  const data =
    envelope && envelope.ok === true && envelope.data !== undefined
      ? envelope.data
      : null
  return { status: res.status, data, rawText }
}

// ── Reshapers ─────────────────────────────────────────────────────────────────

function reshapeConfig(bs: UpstreamBootstrap): PosConfig {
  return {
    currency: bs.currency ?? "",
    decimal: bs.decimal === "yes" ? "yes" : "no",
    thousand: bs.thousand === "comma" ? "comma" : "dot",
    taxName: bs.taxName ?? "IVA",
    tinName: bs.tinName ?? "TIN",
    country: bs.country ?? "",
    companyName: bs.companyName ?? "",
    companyId: bs.companyId ?? "",
    publicUrl: bs.publicUrl ?? "",
  }
}

function reshapeItem(row: UpstreamItemRow): PosItem {
  return {
    id: row.itemId,
    name: row.itemName,
    sku: row.itemSKU ?? null,
    price: Number(row.itemPrice ?? 0),
    taxIncluded: row.itemTaxIncluded ?? true,
    taxId: row.taxId ?? null,
    categoryId: row.categoryId ?? null,
    categoryName: row.categoryName ?? null,
    imageUrl: row.coverImageUrl ?? null,
    uom: row.itemUOM ?? null,
    kind: row.kind ?? "producto",
    trackInventory: row.itemTrackInventory ?? false,
    // TODO (A6+): pedir stock real al depósito del outlet activo. El LIST de
    // /v1/items no incluye stock — habría que componer con /v1/items?resource=inventory
    // por item o agregar un endpoint /v1/stock?outletId=X. Por ahora null = sin info.
    stock: null,
    isGroup: row.itemIsParent === true,
    parentId: row.itemParentId ?? null,
  }
}

function reshapeCustomer(row: UpstreamContactRow): PosCustomer {
  // TODO (A6+): el legacy decide isCreditable mirando `settingCreditEnabled`
  // del tenant + `contactStatus=1`. Acá lo dejo true por default — el cobro
  // a crédito ya valida cliente existente al confirmar la venta, así que el
  // riesgo es que se OFREZCA la opción cuando el tenant no tiene crédito
  // habilitado. Refinar cuando se porte la regla del legacy a /api.
  return {
    id: row.id,
    name: row.name,
    phone: row.phone ?? null,
    tin: row.tin ?? null,
    storeCredit: Number(row.storeCredit ?? 0),
    isCreditable: true,
  }
}

function reshapeUsers(
  data: UpstreamUsersList | null,
  httpStatus: number,
): PosUser[] {
  // 5xx o null → lista vacía (degradación controlada, ya logueado).
  if (httpStatus >= 500 || data === null) return []
  return data.users
    .filter((u) => u.status === 1)
    .map((u) => ({
      id: u.id,
      name: u.name,
      pinhash: u.pinhash ?? null,
      lockpasshash: u.lockPassHash ?? null,
    }))
}

// ── Handler ───────────────────────────────────────────────────────────────────

export async function GET(req: NextRequest): Promise<NextResponse> {
  // El POS puede operar con cualquiera de las dos cookies:
  //   _jwt_panel — realm `panel` (operador logueado en el panel)
  //   _jwt        — realm `pos-app` (device pairing; sobrevive logout del panel)
  // Si ninguna está presente → 401 sin pegarle al backend.
  // Cuando ambas están, también funciona — los endpoints upstream aceptan ambos realms.
  const cookie = req.headers.get("cookie") ?? ""
  const hasPanel = /(?:^|;)\s*_jwt_panel=/.test(cookie)
  const hasPosApp = /(?:^|;)\s*_jwt=/.test(cookie)
  if (!hasPanel && !hasPosApp) {
    return NextResponse.json(
      {
        ok: false,
        error: { message: "No autenticado", code: 401 },
      },
      { status: 401 },
    )
  }

  const base = getTargetBase()
  const headers = buildUpstreamHeaders(req)

  // Fan-out paralelo. Los 5 endpoints son independientes en el backend
  // (cada uno hace su propia auth y SELECTs distintos).
  let bsRes: Awaited<ReturnType<typeof fetchUpstream<UpstreamBootstrap>>>
  let itemsRes: Awaited<ReturnType<typeof fetchUpstream<UpstreamItemsList>>>
  let customersRes: Awaited<ReturnType<typeof fetchUpstream<UpstreamContactsList>>>
  let registersRes: Awaited<ReturnType<typeof fetchUpstream<UpstreamRegisterList>>>
  let usersRes: Awaited<ReturnType<typeof fetchUpstream<UpstreamUsersList>>>
  try {
    ;[bsRes, itemsRes, customersRes, registersRes, usersRes] = await Promise.all([
      fetchUpstream<UpstreamBootstrap>(base, "/v1/bootstrap", headers),
      fetchUpstream<UpstreamItemsList>(
        base,
        "/v1/items?limit=500&offset=0&includeGroupChildren=true",
        headers,
      ),
      fetchUpstream<UpstreamContactsList>(
        base,
        "/v1/contacts?type=1&limit=500&offset=0",
        headers,
      ),
      fetchUpstream<UpstreamRegisterList>(
        base,
        "/v1/register?resource=list",
        headers,
      ),
      fetchUpstream<UpstreamUsersList>(
        base,
        "/v1/users?status=1&limit=200",
        headers,
      ),
    ])
  } catch (err) {
    console.error("[bff /api/pos/bootstrap] network error", {
      err: err instanceof Error ? err.message : String(err),
    })
    return NextResponse.json(
      {
        ok: false,
        error: {
          message: "BFF no pudo contactar a la API",
          code: 502,
        },
      },
      { status: 502 },
    )
  }

  // 401 en cualquiera → propagar 401 entero.
  if (
    bsRes.status === 401 ||
    itemsRes.status === 401 ||
    customersRes.status === 401 ||
    registersRes.status === 401 ||
    usersRes.status === 401
  ) {
    return NextResponse.json(
      {
        ok: false,
        error: { message: "No autenticado", code: 401 },
      },
      { status: 401 },
    )
  }

  // 5xx en cualquiera → loguear snippet + 502 al front.
  // registers: si falla con 5xx también bloquea (sin lista no se puede elegir caja).
  // Si devuelve lista vacía (data.registers = []), está OK — el guard lo mostrará.
  for (const [label, r] of [
    ["bootstrap", bsRes],
    ["items", itemsRes],
    ["contacts", customersRes],
    ["registers", registersRes],
  ] as const) {
    if (r.status >= 500) {
      const snippet =
        r.rawText.length > 500 ? r.rawText.slice(0, 500) + "…" : r.rawText
      console.warn("[bff /api/pos/bootstrap] upstream 5xx", {
        label,
        status: r.status,
        body: snippet,
      })
    }
  }

  // users: si falla con 5xx, degradar a lista vacía (no bloquea el bootstrap).
  if (usersRes.status >= 500) {
    const snippet =
      usersRes.rawText.length > 500
        ? usersRes.rawText.slice(0, 500) + "…"
        : usersRes.rawText
    console.warn("[bff /api/pos/bootstrap] upstream 5xx users (degradando a [])", {
      status: usersRes.status,
      body: snippet,
    })
  }

  if (
    bsRes.status >= 500 ||
    itemsRes.status >= 500 ||
    customersRes.status >= 500 ||
    registersRes.status >= 500
  ) {
    return NextResponse.json(
      {
        ok: false,
        error: { message: "Upstream error componiendo bootstrap", code: 502 },
      },
      { status: 502 },
    )
  }

  // Si alguno de los 3 core devolvió 4xx no-401 (ej. 403), propagamos 502.
  // registers: null → tratar como lista vacía (no bloqueante).
  if (bsRes.data === null || itemsRes.data === null || customersRes.data === null) {
    return NextResponse.json(
      {
        ok: false,
        error: {
          message: "Upstream no devolvió data completa",
          code: 502,
        },
      },
      { status: 502 },
    )
  }

  const bs = bsRes.data
  const itemsList = itemsRes.data
  const contactsList = customersRes.data

  // Items vendibles: itemStatus=1 (activo) + itemCanSale=true.
  // itemIsParent=true tiene dos significados: agrupadores de catálogo (canSale=false,
  // no pasan) y combos/packs (canSale=true, sí deben aparecer en el POS). La condición
  // canSale ya descarta agrupadores no-vendibles.
  //
  // Hijos de grupos (itemParentId != null, canSale=true) también pasan este filtro —
  // el grid del POS los oculta del top-level y los muestra en GroupItemsDialog.
  const items: PosItem[] = itemsList.items
    .filter((i) => {
      const status = i.itemStatus
      const active = status === 1 || status === true || status === "1"
      const canSale = i.itemCanSale === true
      return active && canSale
    })
    .map(reshapeItem)

  const customers: PosCustomer[] = contactsList.contacts.map(reshapeCustomer)

  // Cajas del outlet activo. Si el fetch falló (null), degradar a lista vacía.
  const registers: PosRegister[] = (registersRes.data?.registers ?? []).map(
    (r): PosRegister => ({
      id: r.id,
      name: r.name,
      outletId: bs.activeOutletId ?? "",
      expeditionPoint: null, // TODO (A7+): exponer desde el endpoint cuando esté disponible
    }),
  )

  const bootstrap: PosBootstrap = {
    config: reshapeConfig(bs),
    user: {
      id: bs.user?.id ?? "",
      role: bs.user?.role ?? 0,
    },
    outlet: {
      id: bs.activeOutletId ?? "",
      name: bs.activeOutletName ?? "",
    },
    // Lista completa de sucursales del tenant (para el selector de 2 pasos).
    outlets: Array.isArray(bs.outlets) ? bs.outlets : [],
    registers,
    items,
    customers,
    paymentMethods: FALLBACK_PAYMENT_METHODS,
    users: reshapeUsers(usersRes.data, usersRes.status),
    activeRegisterId: bs.activeRegisterId ?? "",
  }

  return NextResponse.json(bootstrap)
}
