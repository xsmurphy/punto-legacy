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
  PosTaxRate,
  PosUser,
  PaymentMethodConfig,
} from "@/lib/types/pos-bootstrap"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

// ── Fallback de métodos de pago ───────────────────────────────────────────────
// Se usa SOLO cuando GET /v1/payment-methods falla o devuelve vacío (ver
// handler abajo) — el bootstrap nunca debe dejar el POS sin poder cobrar.

const FALLBACK_PAYMENT_METHODS: PaymentMethodConfig[] = [
  { id: "efectivo", name: "Efectivo", code: "A", hasChange: true, requiresIdentifier: false, isDefault: true, systemKey: "cash" },
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

/** number|string|null → number finito o null. Nunca devuelve NaN. */
function toFiniteNumber(raw: unknown): number | null {
  if (raw === null || raw === undefined || raw === "") return null
  const n = typeof raw === "number" ? raw : Number(raw)
  return Number.isFinite(n) ? n : null
}

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
  timezone: string
  companyName: string
  companyId: string | number
  logoUrl?: string
  publicUrl: string
  user: { id: string | number; role: number }
  activeOutletId: string
  activeOutletName: string
  /** Coords de la sucursal activa (mig 14). null si no tiene ubicación cargada. */
  activeOutletLat?: number | string | null
  activeOutletLng?: number | string | null
  activeRegisterId?: string
  outlets: Array<{ id: string; name: string }>
  // Razón social/RUC/email/sitio del tenant y datos fiscales de la sucursal
  // activa — ticket impreso (flujo NO-FE, ver context/10-roadmap.md
  // §2026-07-30). '' si no cargados.
  companyBillingName?: string
  companyTin?: string
  companyEmail?: string
  companyWebsite?: string
  /** Canales del módulo Bancard (bools ya resueltos por /v1/bootstrap). */
  bancardQr?: boolean
  bancardPos?: boolean
  activeOutletAddress?: string
  activeOutletBillingName?: string
  activeOutletTin?: string
  activeOutletPhone?: string
  /**
   * Default incluido/añadido del IVA de la sucursal activa (F2b,
   * context/38). Ausente = bootstrap upstream viejo (deploy no coordinado);
   * el reshaper cae a `true`, mismo default fiscal que el backend.
   */
  activeOutletTaxIncluded?: boolean
}

// Fila de /v1/taxes — ver TaxService::present() (F0, tabla `tax`).
interface UpstreamTaxRow {
  id: string
  rate: number | string | null
  kind: "rate" | "exempt" | string
}

interface UpstreamTaxesList {
  taxes: UpstreamTaxRow[]
}

interface UpstreamRegisterRow {
  id: string
  name: string
  // Timbrado de la caja (register.data.registerInvoiceAuth*, mig 26). '' si no configurado.
  invoiceAuth?: string
  invoicePrefix?: string
  invoiceAuthStart?: string
  invoiceAuthExpiration?: string
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
  brandId?: string | null
  brandName?: string | null
  coverImageUrl?: string | null
  kind?: string
  /** % de descuento de catálogo (JSONB flattened). Ver PosItem.discountPercent. */
  itemDiscount?: number | string | null
  /** F4 (context/41): el ítem tiene grupos de add-ons vigentes. Ver PosItem.hasAddons. */
  hasAddons?: boolean | string | number | null
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

// Shape real de /v1/payment-methods — ver PaymentMethodService::present().
interface UpstreamPaymentMethodRow {
  id: string
  name: string
  code: string
  hasChange: boolean
  requiresIdentifier: boolean
  identifierLabel: string
  identifierPlaceholder: string
  color: string
  sortOrder: number | null
  systemKey: "cash" | "giftcard" | "internal" | null
  accountId: string | null
}

interface UpstreamPaymentMethodsList {
  paymentMethods: UpstreamPaymentMethodRow[]
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
  // Reenviar cookie (_jwt_panel del panel) y Authorization (Bearer del device POS).
  const cookie = req.headers.get("cookie")
  if (cookie) h.set("cookie", cookie)
  const auth = req.headers.get("authorization")
  if (auth) h.set("authorization", auth)
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
    timezone: bs.timezone ?? "",
    companyName: bs.companyName ?? "",
    companyId: bs.companyId ?? "",
    companyLogo: bs.logoUrl || null,
    publicUrl: bs.publicUrl ?? "",
    companyBillingName: bs.companyBillingName || null,
    companyTin: bs.companyTin || null,
    companyEmail: bs.companyEmail || null,
    companyWebsite: bs.companyWebsite || null,
    bancardQrEnabled: bs.bancardQr === true,
    bancardPosEnabled: bs.bancardPos === true,
  }
}

function reshapeItem(row: UpstreamItemRow): PosItem {
  return {
    id: row.itemId,
    name: row.itemName,
    sku: row.itemSKU ?? null,
    price: Number(row.itemPrice ?? 0),
    // `?? true` perdía la distinción "sin override" vs "explícitamente
    // incluido" — el carrito necesita el `null` para caer al default de la
    // sucursal (ver PosItem.taxIncluded, F2b context/38). NO defaultear acá.
    taxIncluded: row.itemTaxIncluded ?? null,
    taxId: row.taxId ?? null,
    categoryId: row.categoryId ?? null,
    categoryName: row.categoryName ?? null,
    brandId: row.brandId ?? null,
    brandName: row.brandName ?? null,
    imageUrl: row.coverImageUrl ?? null,
    uom: row.itemUOM ?? null,
    kind: row.kind ?? "producto",
    discountPercent: (() => {
      if (row.itemDiscount === null || row.itemDiscount === undefined || row.itemDiscount === "") {
        return null
      }
      const n = Number(row.itemDiscount)
      // Backend corrupto/valor no numérico → null, nunca NaN (contaminaría
      // saleDiscount y el total del carrito, ver lib/cart/store.ts::addItem).
      return Number.isFinite(n) ? n : null
    })(),
    trackInventory: row.itemTrackInventory ?? false,
    // TODO (A6+): pedir stock real al depósito del outlet activo. El LIST de
    // /v1/items no incluye stock — habría que componer con /v1/items?resource=inventory
    // por item o agregar un endpoint /v1/stock?outletId=X. Por ahora null = sin info.
    stock: null,
    isGroup: row.itemIsParent === true,
    parentId: row.itemParentId ?? null,
    // F4 (context/41): PG con PDO puede devolver el boolean del EXISTS como
    // 't'/'f' string según driver — presentItem() ya lo normaliza a bool, pero
    // el reshape no puede asumirlo (un 'f' string es truthy en JS). Solo el
    // `true` real y sus representaciones explícitas cuentan.
    hasAddons: row.hasAddons === true || row.hasAddons === "t" || row.hasAddons === "true" || row.hasAddons === 1,
  }
}

// rate llega NUMERIC de PG → puede venir string vía PDO (mismo motivo que
// toFiniteNumber arriba). kind fuera de la unión conocida (dato corrupto,
// nunca debería pasar post-mig 120) → "exempt", el default fiscal seguro.
function reshapeTax(row: UpstreamTaxRow): PosTaxRate {
  const rate = typeof row.rate === "number" ? row.rate : Number(row.rate ?? 0)
  return {
    id: row.id,
    rate: Number.isFinite(rate) ? rate : 0,
    kind: row.kind === "rate" ? "rate" : "exempt",
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

function reshapePaymentMethod(row: UpstreamPaymentMethodRow): PaymentMethodConfig {
  return {
    id: row.id,
    name: row.name,
    code: row.code || undefined,
    hasChange: row.hasChange,
    requiresIdentifier: row.requiresIdentifier,
    identifierLabel: row.identifierLabel || undefined,
    identifierPlaceholder: row.identifierPlaceholder || undefined,
    isDefault: row.systemKey != null,
    systemKey: row.systemKey,
    color: row.color || undefined,
    sortOrder: row.sortOrder,
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
  // El POS puede acceder con:
  //   Authorization: Bearer <token> — device POS (Bearer en localStorage)
  //   _jwt_panel cookie             — panel admin (HttpOnly, 24h)
  // Sin ninguno → 401 sin pegarle al backend.
  const cookie = req.headers.get("cookie") ?? ""
  const authHeader = req.headers.get("authorization") ?? ""
  const hasPanel = /(?:^|;)\s*_jwt_panel=/.test(cookie)
  const hasBearerToken = /^Bearer\s+\S+/i.test(authHeader)
  if (!hasPanel && !hasBearerToken) {
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
  let paymentMethodsRes: Awaited<ReturnType<typeof fetchUpstream<UpstreamPaymentMethodsList>>>
  let taxesRes: Awaited<ReturnType<typeof fetchUpstream<UpstreamTaxesList>>>
  try {
    ;[bsRes, itemsRes, customersRes, registersRes, usersRes, paymentMethodsRes, taxesRes] = await Promise.all([
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
      // .catch() propio: un fallo acá (red/timeout/DNS) NO debe reventar el
      // Promise.all entero (eso tiraría 502 a TODO el bootstrap, no solo a
      // payment-methods). Se resuelve a un resultado "vacío" que el código de
      // abajo ya sabe degradar a FALLBACK_PAYMENT_METHODS.
      fetchUpstream<UpstreamPaymentMethodsList>(
        base,
        "/v1/payment-methods",
        headers,
      ).catch((err) => {
        console.warn("[bff /api/pos/bootstrap] payment-methods fetch falló (degradando a fallback)", {
          err: err instanceof Error ? err.message : String(err),
        })
        return { status: 0, data: null, rawText: "" }
      }),
      // Mismo criterio que payment-methods: `/v1/taxes` no debe poder tumbar
      // el bootstrap entero. Si falla (red/timeout/401 de un token viejo sin
      // el realm ampliado), degradar a lista vacía — el carrito ya sabe tratar
      // un `taxId` sin match como exento (fallback fiscal seguro, F2b).
      fetchUpstream<UpstreamTaxesList>(base, "/v1/taxes", headers).catch((err) => {
        console.warn("[bff /api/pos/bootstrap] taxes fetch falló (degradando a [])", {
          err: err instanceof Error ? err.message : String(err),
        })
        return { status: 0, data: null, rawText: "" }
      }),
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

  // 401 en cualquiera → propagar 401 entero. `paymentMethodsRes` incluido: desde
  // que `/v1/payment-methods` acepta el realm `pos-app` (antes era el único
  // upstream panel-only), un 401 acá ya no puede significar "endpoint más
  // estricto que el resto" — significa sesión muerta, igual que en los core.
  // Degradarlo a los métodos de fallback dejaba la caja operando con ids
  // falsos y rompía el cobro después, lejos de la causa (incidente 2026-07-29).
  const unauthorizedRes = [
    bsRes,
    itemsRes,
    customersRes,
    registersRes,
    usersRes,
    paymentMethodsRes,
  ].find((r) => r.status === 401)
  if (unauthorizedRes) {
    // El upstream (`api/includes/auth_session.php`) manda `code: "session_revoked"`
    // en el body cuando la sesión fue revocada explícitamente por un admin — sin
    // preservarlo acá, `posFetch`/`PosAuthGuard` no pueden distinguir esa causa
    // de un token vencido/ausente genérico y muestran el copy equivocado.
    let upstreamCode: string | number = 401
    try {
      const parsed = unauthorizedRes.rawText ? JSON.parse(unauthorizedRes.rawText) : null
      if (parsed && typeof parsed.code !== "undefined") upstreamCode = parsed.code
    } catch {
      // rawText no era JSON — nos quedamos con el código genérico.
    }
    return NextResponse.json(
      {
        ok: false,
        error: { message: "No autenticado", code: 401 },
        code: upstreamCode,
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

  // payment-methods: una falla de INFRAESTRUCTURA (5xx, red, respuesta vacía)
  // degrada al fallback hardcodeado — el POS siempre necesita al menos Efectivo
  // para poder cobrar. El 401, en cambio, se propaga junto con los core (ver
  // bloque de abajo): es sesión muerta, no un endpoint indisponible, y los ids
  // del fallback no son UUID del tenant — cobrar con ellos falla más tarde con
  // un error que no se parece en nada a un problema de auth.
  if (paymentMethodsRes.status >= 400 && paymentMethodsRes.status !== 401) {
    const snippet =
      paymentMethodsRes.rawText.length > 500
        ? paymentMethodsRes.rawText.slice(0, 500) + "…"
        : paymentMethodsRes.rawText
    console.warn("[bff /api/pos/bootstrap] upstream error payment-methods (degradando a fallback)", {
      status: paymentMethodsRes.status,
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
      expeditionPoint: r.invoicePrefix || null,
      authNumber: r.invoiceAuth || null,
      authStartDate: r.invoiceAuthStart || null,
      authExpiration: r.invoiceAuthExpiration || null,
    }),
  )

  // Medios de pago reales del tenant. Si el fetch falló o el tenant todavía
  // no tiene ninguno (antes del auto-seed, o error transitorio), degradar al
  // fallback hardcodeado — el bootstrap NUNCA debe dejar el POS sin métodos
  // de pago para cobrar.
  const fetchedPaymentMethods = paymentMethodsRes.data?.paymentMethods ?? []
  const paymentMethods: PaymentMethodConfig[] =
    fetchedPaymentMethods.length > 0
      ? fetchedPaymentMethods.map(reshapePaymentMethod)
      : FALLBACK_PAYMENT_METHODS

  const bootstrap: PosBootstrap = {
    config: reshapeConfig(bs),
    user: {
      id: bs.user?.id ?? "",
      role: bs.user?.role ?? 0,
    },
    outlet: {
      id: bs.activeOutletId ?? "",
      name: bs.activeOutletName ?? "",
      // PG NUMERIC llega como string por PDO; normalizamos a number|null y
      // descartamos cualquier valor no finito (nunca NaN hacia el cliente).
      lat: toFiniteNumber(bs.activeOutletLat),
      lng: toFiniteNumber(bs.activeOutletLng),
      address: bs.activeOutletAddress || null,
      billingName: bs.activeOutletBillingName || null,
      tin: bs.activeOutletTin || null,
      phone: bs.activeOutletPhone || null,
    },
    // Lista completa de sucursales del tenant (para el selector de 2 pasos).
    outlets: Array.isArray(bs.outlets) ? bs.outlets : [],
    registers,
    items,
    customers,
    paymentMethods,
    users: reshapeUsers(usersRes.data, usersRes.status),
    activeRegisterId: bs.activeRegisterId ?? "",
    // F2b (context/38): tasas del tenant + default de la sucursal, para que
    // el carrito calcule el IVA con el motor en vez del TAX_RATE hardcodeado.
    // `taxesRes.data` null (fetch degradado arriba) → [] — el carrito trata
    // toda línea sin match de tasa como exenta, nunca inventa una.
    taxes: (taxesRes.data?.taxes ?? []).map(reshapeTax),
    outletTaxIncluded: bs.activeOutletTaxIncluded ?? true,
  }

  return NextResponse.json(bootstrap)
}
