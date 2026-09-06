/**
 * BFF — POS Bootstrap (Slice A6).
 *
 * Punto de entrada del catálogo del POS. Compone en UNA sola respuesta todo
 * lo que el store de catálogo necesita para hidratar en memoria. Hace un
 * fan-out en paralelo al backend `/api/v1/*` con loopback in-container:
 *
 *   GET /v1/bootstrap          → config tenant + user + outlet activo + roster
 *                                del lock screen (`users`)
 *   GET /v1/items?limit=500    → items vendibles (filtra itemCanSale+status)
 *   GET /v1/contacts?type=1    → clientes
 *   … + register, payment-methods, taxes, categories, brands,
 *     document-templates
 *
 * El roster del lock screen NO tiene fetch propio: hasta 2026-08-24 se pedía a
 * `/v1/users?status=1`, que exige `contacts.user.view` — permiso que el rol
 * `device` no tiene desde la mig 162 y que NO debe recuperar (abre el equipo
 * entero, con emails y teléfonos, a un token eterno guardado en la tablet del
 * mostrador). Ahora baja como proyección de tres campos dentro del bootstrap.
 *
 * Auth: SOLO el Bearer del device (realm `pos-app`). Sin él → 401, sin pegarle
 * al backend — mismo contrato que `requireBearer` de `lib/bff/proxy.ts`, que
 * ya usan el resto de los `/api/pos/*`. Este endpoint era el único que además
 * aceptaba la cookie `_jwt_panel` como credencial, y esa excepción es la causa
 * raíz del lockout del 2026-08-25 (ver el docblock de `buildUpstreamHeaders`).
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
  PosItem,
  PosCustomer,
  PosRegister,
  PosTaxRate,
  PosCategory,
  PosBrand,
  PosUser,
  PaymentMethodConfig,
  PosPrintTemplate,
} from "@/lib/types/pos-bootstrap"
import {
  reshapeItem,
  reshapeCustomer,
  isSellableItemRow,
  type UpstreamItemRow,
  type UpstreamContactRow,
} from "@/lib/pos-bff/reshape"

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
  // Dirección y teléfono del tenant (company.config settingAddress/
  // settingPhone) — los pide el ticket impreso, igual que los de arriba.
  companyAddress?: string
  companyPhone?: string
  /** Canales del módulo Bancard (bools ya resueltos por /v1/bootstrap). */
  bancardQr?: boolean
  bancardPos?: boolean
  /**
   * Canal QR por pasarela — { provider: bool }, ya resuelto server-side
   * (PspCatalog). Ausente si el /api desplegado es anterior al refactor de
   * pasarelas: en ese caso el POS cae al flag legacy `bancardQr`.
   */
  pspQr?: Record<string, boolean>
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
  /**
   * D2/D3 (context/40-anulacion-y-nota-credito.md) — política de
   * devoluciones del comercio. Ausente = bootstrap upstream viejo, el
   * reshaper cae a los mismos defaults que `api/v1/bootstrap.php`.
   */
  settingReturnRefund?: "cash" | "credit" | "ask"
  settingReturnAllowIngredientReversal?: boolean
  /**
   * Listas fijas de conteo (D3, context/63). `/v1/bootstrap` las sirve SOLO a
   * un device que es una caja, igual que el roster del lock screen. Ausente =
   * `/api` anterior a esta feature, o el device no es una caja: el POS lo trata
   * como "sin listas configuradas", nunca como "contá todo".
   */
  stockCountLists?: Array<{ id: string; name: string; itemIds: string[] }>
  stockCountRecordOnly?: boolean
  stockCountBlind?: boolean
  /**
   * Roster de la pantalla de bloqueo — proyección MÍNIMA (id/name/pinhash) de
   * los usuarios activos habilitados en la sucursal del contexto, servida por
   * `/v1/bootstrap` (ver `UsersService::rosterForOutlet()`).
   *
   * Hasta 2026-08-24 el POS lo pedía por `/v1/users?status=1`, que exige
   * `contacts.user.view` — permiso que el rol `device` no tiene desde la mig
   * 162. El 403 resultante degradaba a `[]` sin ruido y el lock screen quedaba
   * sin PINs contra los que validar: lockout de la caja.
   *
   * Se sirve SOLO al realm `pos-app`: el `pinhash` es un SHA-256 sin sal de 4
   * dígitos y no puede viajar a una sesión de panel (ver el gate en
   * `api/v1/bootstrap.php`). Ausente = `/api` viejo, o la request no llevaba el
   * Bearer del device. El handler lo loguea explícitamente en vez de tragárselo.
   */
  users?: UpstreamRosterUser[]
}

/** Fila del roster del lock screen. Tres campos, a propósito — ver arriba. */
interface UpstreamRosterUser {
  id: string
  name: string
  pinhash?: string | null
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

// Fila de /v1/categories y /v1/brands — ver CategoryService::present() /
// BrandService::present() (Slices 1/2 del refactor taxonomy, tablas
// `category`/`brand`, migrations 21/22).
interface UpstreamCategoryRow {
  id: string
  name: string
}

interface UpstreamCategoriesList {
  categories: UpstreamCategoryRow[]
}

interface UpstreamBrandRow {
  id: string
  name: string
}

interface UpstreamBrandsList {
  brands: UpstreamBrandRow[]
}

// Fila de /v1/document-templates — ver DocumentTemplateService::present().
// Reusa DocumentTemplateRow (mismo shape que el editor del panel) en vez de
// declarar upstream fields propios: el backend YA devuelve el shape final,
// sin transformación (a diferencia de items/contacts, que sí necesitan
// reshape por naming legacy).
interface UpstreamPrintTemplatesList {
  templates: PosPrintTemplate[]
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

// Shape real de GET /v1/register (sin `resource`) — RegisterService::docNumbers().
// El POS solo necesita `invoiceNo`: el próximo correlativo de FACTURA de la
// caja activa (JWT), fuente de `PosBootstrap.nextInvoiceNo` (ver
// lib/pos/invoice-numbering.ts). Reemplaza al arriendo de bloques
// (`/v1/numbering/lease`, RECHAZADO 2026-08-17).
interface UpstreamDocNumbers {
  invoiceNo: number
  /** Ancho de impresión del correlativo de factura (`document_sequence.padwidth`, mig 159). */
  invoicePadWidth?: number
  /** Techo del rango autorizado del timbrado (`document_sequence.rangeto`, D5 context/37). */
  invoiceRangeTo?: number | null
}

// Shape real de /v1/items rows y /v1/contacts rows — ver
// `@/lib/pos-bff/reshape.ts` (única fuente de verdad, compartida con los
// BFF de sync quirúrgico).
interface UpstreamItemsList {
  items: UpstreamItemRow[]
  total: number
  limit: number
  offset: number
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

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Headers hacia upstream: SOLO el Bearer del device. La cookie NO se reenvía.
 *
 * Esto no es limpieza — es la frontera de realm de este endpoint, y su
 * ausencia causó el lockout del lock screen del 2026-08-25.
 *
 * `/v1/bootstrap` es multi-realm (`apiAuthTenant(['panel','pos-app'])`) y
 * `authResolve()` se queda con la PRIMERA credencial válida cuyo realm esté
 * permitido. Mientras esta función reenviaba las dos, el browser del operador
 * —que por el modelo de doble sesión tiene la cookie del panel Y el Bearer del
 * device— podía resolver como realm `panel` en dos escenarios reales:
 *
 *   1. Sin Bearer todavía (device recién despareado / a punto de parearse): la
 *      cookie quedaba como única candidata. El bootstrap devolvía 200 con
 *      forma de PANEL y SIN la clave `users` (el roster se sirve solo a
 *      `pos-app`, ver el gate en `api/v1/bootstrap.php`). Ese 200 se cacheaba
 *      en `["pos-bootstrap"]` y se persistía en el snapshot de IndexedDB, así
 *      que sobrevivía al pareo posterior y el lock screen abría con `users:
 *      []` — acusando al comercio de no tener PINs cargados.
 *   2. Con un Bearer REVOCADO: `authResolve()` descarta la sesión revocada y
 *      sigue probando candidatos, así que la cookie lo "rescataba" como panel
 *      en vez de devolver el 401 `session_revoked` que dispara el cleanup del
 *      device. Un dispositivo expulsado seguía trayendo catálogo con la sesión
 *      del operador.
 *
 * Con una sola credencial en la request, `/v1/bootstrap` solo puede responder
 * como `pos-app` o rechazar. No hay tercera opción que el POS deba interpretar.
 */
function buildUpstreamHeaders(req: NextRequest): Headers {
  const h = new Headers()
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
    // Pasar-through, sin sentinel: "TIN" era un valor NO vacío que el
    // resolver del POS (resolveTaxIdLabel) leía como elección explícita del
    // comercio, y por eso no caía al default del país. "" = no configurado.
    tinName: bs.tinName ?? "",
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
    companyAddress: bs.companyAddress || null,
    companyPhone: bs.companyPhone || null,
    bancardQrEnabled: bs.bancardQr === true,
    bancardPosEnabled: bs.bancardPos === true,
    // Mapa genérico de pasarelas. Se completa con el flag legacy de Bancard
    // para que un /api viejo (sin `pspQr`) no apague el botón del QR.
    pspQrEnabled: {
      bancard: bs.bancardQr === true,
      ...Object.fromEntries(
        Object.entries(bs.pspQr ?? {}).map(([provider, on]) => [provider, on === true]),
      ),
    },
    settingReturnRefund:
      bs.settingReturnRefund === "cash" || bs.settingReturnRefund === "credit"
        ? bs.settingReturnRefund
        : "ask",
    settingReturnAllowIngredientReversal: bs.settingReturnAllowIngredientReversal === true,
    // Se normaliza acá, no en la pantalla: una lista sin nombre o sin ítems no
    // es una lista que el cajero pueda completar, y dejarla pasar la convierte
    // en una opción del selector que no hace nada. El backend ya aplica el
    // mismo criterio (`StockCountSettings::decodeLists`) — esto es la red por
    // si el `/api` desplegado es anterior.
    stockCountLists: (bs.stockCountLists ?? [])
      .filter((l) => l && typeof l.id === "string" && l.id !== "" && Array.isArray(l.itemIds))
      .map((l) => ({
        id: l.id,
        name: typeof l.name === "string" ? l.name : "",
        itemIds: l.itemIds.filter((i): i is string => typeof i === "string" && i !== ""),
      }))
      .filter((l) => l.name !== "" && l.itemIds.length > 0),
    stockCountRecordOnly: bs.stockCountRecordOnly === true,
    // Ausente = `/api` anterior a la F2 → se asume el piso PRENDIDO. Es el
    // default recomendado (D2) y el comportamiento que ese `/api` ya tenía, así
    // que un front nuevo contra un back viejo no promete un modo que el
    // servidor no sabe resolver. Por eso `!== false` y no `=== true`.
    stockCountBlind: bs.stockCountBlind !== false,
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

function reshapeCategory(row: UpstreamCategoryRow): PosCategory {
  return { id: row.id, name: row.name }
}

function reshapeBrand(row: UpstreamBrandRow): PosBrand {
  return { id: row.id, name: row.name }
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

/**
 * Roster del lock screen. Ya viene filtrado y proyectado por el backend
 * (activos + habilitados en la sucursal, tres campos) — acá solo se normaliza
 * el `pinhash` ausente a `null`. Sin filtro por `status`: la lista que llega YA
 * es la de los habilitados, y volver a filtrar acá sobre un campo que el
 * backend deliberadamente no manda vaciaría el roster entero.
 *
 * Devuelve `null` cuando el upstream NO mandó la clave `users` — que NO es lo
 * mismo que mandarla vacía y no se puede colapsar en `[]`.
 *
 *   `[]`   → `/v1/bootstrap` respondió como device y el comercio no tiene
 *            ningún usuario habilitado en la sucursal. Dato del comercio.
 *   `null` → la respuesta no traía roster: `/api` más viejo que este front, o
 *            la sesión no es la del device (realm `panel`, o un device cuyo
 *            `module` no es `pos`). No dice NADA sobre los PINs del comercio.
 *
 * Colapsar los dos en `[]` es exactamente lo que hacía que el lock screen
 * acusara al comercio de no tener códigos cargados cuando el problema era de
 * sesión. El front necesita el distingo para elegir el mensaje y la salida.
 */
function reshapeRoster(rows: UpstreamRosterUser[] | undefined): PosUser[] | null {
  if (!Array.isArray(rows)) return null
  return rows.map((u) => ({
    id: u.id,
    name: u.name,
    pinhash: u.pinhash ?? null,
  }))
}

// ── Handler ───────────────────────────────────────────────────────────────────

export async function GET(req: NextRequest): Promise<NextResponse> {
  // El bootstrap del POS es un recurso del DEVICE: solo se sirve contra el
  // Bearer de `pos-app`. La cookie `_jwt_panel` NO es una credencial válida
  // acá — aceptarla devolvía un bootstrap de realm `panel`, sin el roster del
  // lock screen, que el POS cacheaba como si fuera suyo (ver el docblock de
  // `buildUpstreamHeaders`). Mismo guard y misma copy que `requireBearer` en
  // `lib/bff/proxy.ts`, que ya usan el resto de los `/api/pos/*`.
  const authHeader = req.headers.get("authorization") ?? ""
  if (!/^Bearer\s+\S+/i.test(authHeader)) {
    return NextResponse.json(
      {
        ok: false,
        error: {
          message: "Falta Bearer del device. Re-conectá el dispositivo desde el panel.",
          code: 401,
        },
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
  let paymentMethodsRes: Awaited<ReturnType<typeof fetchUpstream<UpstreamPaymentMethodsList>>>
  let taxesRes: Awaited<ReturnType<typeof fetchUpstream<UpstreamTaxesList>>>
  let categoriesRes: Awaited<ReturnType<typeof fetchUpstream<UpstreamCategoriesList>>>
  let brandsRes: Awaited<ReturnType<typeof fetchUpstream<UpstreamBrandsList>>>
  let printTemplatesRes: Awaited<ReturnType<typeof fetchUpstream<UpstreamPrintTemplatesList>>>
  let docNumbersRes: Awaited<ReturnType<typeof fetchUpstream<UpstreamDocNumbers>>>
  try {
    ;[bsRes, itemsRes, customersRes, registersRes, paymentMethodsRes, taxesRes, categoriesRes, brandsRes, printTemplatesRes, docNumbersRes] = await Promise.all([
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
      // Mismo criterio que taxes: bundle `settings` chico, no debe poder
      // tumbar el bootstrap entero (context/45 F1 — la lista propia de
      // categorías/marcas reemplaza el `categoryName`/`brandName` copiado
      // dentro de `PosItem`). Un fallo acá degrada a [] — los ítems sin
      // match caen al fallback explícito del componente que resuelve el
      // nombre, nunca a "undefined".
      fetchUpstream<UpstreamCategoriesList>(base, "/v1/categories", headers).catch((err) => {
        console.warn("[bff /api/pos/bootstrap] categories fetch falló (degradando a [])", {
          err: err instanceof Error ? err.message : String(err),
        })
        return { status: 0, data: null, rawText: "" }
      }),
      fetchUpstream<UpstreamBrandsList>(base, "/v1/brands", headers).catch((err) => {
        console.warn("[bff /api/pos/bootstrap] brands fetch falló (degradando a [])", {
          err: err instanceof Error ? err.message : String(err),
        })
        return { status: 0, data: null, rawText: "" }
      }),
      // Plantillas de impresión (context/08 §53, hueco P0 cerrado
      // 2026-08-16) — mismo criterio que categories/brands/taxes: bundle
      // `settings` chico, no debe poder tumbar el bootstrap entero. Un fallo
      // acá degrada a [] — `printSale`/`printTicketInBrowser` ya saben tratar
      // "sin plantilla resuelta" con el fallback genérico embebido
      // (`renderFallbackTicketHtml`), así que degradar no deja el POS sin
      // poder imprimir, solo sin el diseño custom del tenant hasta el
      // próximo bootstrap.
      fetchUpstream<UpstreamPrintTemplatesList>(base, "/v1/document-templates", headers).catch((err) => {
        console.warn("[bff /api/pos/bootstrap] document-templates fetch falló (degradando a [])", {
          err: err instanceof Error ? err.message : String(err),
        })
        return { status: 0, data: null, rawText: "" }
      }),
      // Próximo correlativo de la caja activa (context/29 — reemplaza al
      // arriendo de bloques). Mismo criterio que taxes/categories/brands: un
      // fallo acá NUNCA debe tumbar el bootstrap entero — degrada a `null`,
      // y `primeInvoiceNumbering()` en el front simplemente no corrige el
      // contador local esta vez (sigue con lo que ya tenía persistido).
      fetchUpstream<UpstreamDocNumbers>(base, "/v1/register", headers).catch((err) => {
        console.warn("[bff /api/pos/bootstrap] register (docNumbers) fetch falló (degradando a null)", {
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

  // Un fallo de AUTORIZACIÓN no puede disfrazarse de dataset vacío.
  //
  // `fetchUpstream` colapsa "no autorizado" y "respuesta sin data" en el mismo
  // `data: null`, y todos los reshapers de abajo lo degradan a `[]`. Para un
  // 5xx o un timeout eso es correcto y deliberado (el POS tiene que arrancar
  // igual). Para un 401/403 NO: significa que este realm no puede leer ese
  // endpoint, y la lista vacía resultante se lee río abajo como "el tenant no
  // tiene nada" — que fue exactamente cómo un 403 de `/v1/users` terminó
  // pintándose como "no hay PINs configurados" y dejó cajas bloqueadas
  // (2026-08-24).
  //
  // El 401 de los core ya se propaga arriba (sesión muerta). Acá quedan los
  // degradables: se loguea explícito para que la causa aparezca en el log en
  // vez de desaparecer. NO se cambia la política de degradación —ni la de 5xx
  // ni la de payment-methods, ambas razonadas— solo se hace DISTINGUIBLE.
  for (const [label, r] of [
    ["taxes", taxesRes],
    ["categories", categoriesRes],
    ["brands", brandsRes],
    ["document-templates", printTemplatesRes],
    ["register (docNumbers)", docNumbersRes],
  ] as const) {
    if (r.status === 401 || r.status === 403) {
      console.warn(
        "[bff /api/pos/bootstrap] upstream NO AUTORIZADO (degradando a vacío — NO es un dataset vacío)",
        { label, status: r.status },
      )
    }
  }

  // El roster del lock screen viaja DENTRO del bootstrap (ya no se pide a
  // `/v1/users`). Si llega ausente, la caja arrancaría sin PINs contra los que
  // validar, así que se loguea en vez de degradar en silencio. Dos causas
  // posibles, ambas accionables y ninguna silenciosa:
  //   - el `/api` desplegado es anterior a este cambio (deploy no coordinado);
  //   - la request se autenticó como realm `panel` y no como device, y
  //     `/v1/bootstrap` sirve el roster SOLO a `pos-app` (el `pinhash` es
  //     forzable, ver el gate en `api/v1/bootstrap.php`). Eso significa que
  //     este device no mandó su Bearer: el arranque correcto es parear, no
  //     abrir el lock screen.
  if (bsRes.data !== null && !Array.isArray(bsRes.data.users)) {
    console.warn(
      "[bff /api/pos/bootstrap] /v1/bootstrap no devolvió `users` (roster del lock screen) — /api desactualizado, o la sesión no es la del device (realm pos-app)",
    )
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

  // Items vendibles: itemStatus=1 (activo) + itemCanSale=true (`isSellableItemRow`,
  // compartido con el sync quirúrgico — ver @/lib/pos-bff/reshape.ts).
  // itemIsParent=true tiene dos significados: agrupadores de catálogo (canSale=false,
  // no pasan) y combos/packs (canSale=true, sí deben aparecer en el POS). La condición
  // canSale ya descarta agrupadores no-vendibles.
  //
  // Hijos de grupos (itemParentId != null, canSale=true) también pasan este filtro —
  // el grid del POS los oculta del top-level y los muestra en GroupItemsDialog.
  const items: PosItem[] = itemsList.items
    .filter(isSellableItemRow)
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
    // Roster del lock screen (id/name/pinhash), servido por `/v1/bootstrap`.
    // Al viajar dentro del bootstrap queda además en el snapshot de IndexedDB,
    // así que el PIN se valida sin red en el arranque en frío.
    users: reshapeRoster(bs.users),
    activeRegisterId: bs.activeRegisterId ?? "",
    // F2b (context/38): tasas del tenant + default de la sucursal. Sin esto
    // el carrito no puede resolver la tasa de una línea, y el neteo de
    // "quitar IVA" no tiene con qué dividir (ver lib/cart/line-tax.ts).
    // `taxesRes.data` null (fetch degradado arriba) → [] — el carrito trata
    // toda línea sin match de tasa como exenta, nunca inventa una.
    taxes: (taxesRes.data?.taxes ?? []).map(reshapeTax),
    outletTaxIncluded: bs.activeOutletTaxIncluded ?? true,
    // context/45 F1: lista propia, ya no copiada dentro de cada PosItem.
    categories: (categoriesRes.data?.categories ?? []).map(reshapeCategory),
    brands: (brandsRes.data?.brands ?? []).map(reshapeBrand),
    // context/08 §53 (hueco P0 cerrado 2026-08-16): shape idéntico al
    // upstream (DocumentTemplateRow), sin reshape — ver comentario de
    // UpstreamPrintTemplatesList.
    printTemplates: printTemplatesRes.data?.templates ?? [],
    // context/29: próximo correlativo de FACTURA de la caja activa — seed de
    // `lib/pos/invoice-numbering.ts`. `null` si el fetch degradó arriba.
    nextInvoiceNo:
      typeof docNumbersRes.data?.invoiceNo === "number" ? docNumbersRes.data.invoiceNo : null,
    // mig 159: cuántos dígitos ocupa el correlativo impreso. El device
    // factura sin red, así que el FORMATO tiene que bajar con el bootstrap —
    // si no, el ticket offline sale con el número pelado y no coincide con lo
    // que muestra el panel. `null` → el helper pone el default legal (7).
    invoicePadWidth:
      typeof docNumbersRes.data?.invoicePadWidth === "number"
        ? docNumbersRes.data.invoicePadWidth
        : null,
    // D5 (context/37): techo del timbrado — el POS avisa "quedan N números"
    // comparando su contador local contra esto, también sin red.
    invoiceRangeTo:
      typeof docNumbersRes.data?.invoiceRangeTo === "number"
        ? docNumbersRes.data.invoiceRangeTo
        : null,
  }

  return NextResponse.json(bootstrap)
}
