/**
 * Shape del endpoint BFF `/api/pos/bootstrap`.
 *
 * Este endpoint es la fuente de verdad del catálogo en memoria del POS.
 * Compone en una sola respuesta todo lo que `lib/catalog/store.ts` necesita
 * para hidratar sin round-trips adicionales.
 *
 * Ver context/16-app-next-rewrite.md §4 (arquitectura BFF) y §7 Sprint 0/Slice A.
 */

import type { DocumentTemplateRow } from "@/lib/types/print-template"

// ── Método de pago configurable ───────────────────────────────────────────────

export interface PaymentMethodConfig {
  id: string
  name: string
  /** Letra hotkey (A, S, D…). Opcional. */
  code?: string
  /** true = efectivo/similar: acepta vuelto cuando el monto supera el total. */
  hasChange: boolean
  /** true = pide un identificador antes de aplicar (voucher, nro de op, etc). */
  requiresIdentifier: boolean
  /** Label del campo (ej. "Nro de operación"). Null si !requiresIdentifier. */
  identifierLabel?: string
  /** Placeholder del input de identificador. */
  identifierPlaceholder?: string
  /** true = método del sistema (Efectivo, T. Crédito, T. Débito). Render destacado. */
  isDefault?: boolean
  /**
   * Discriminante estable para comportamiento especial en el POS (cash,
   * giftcard, internal). Viene de `taxonomyExtra.systemKey` en el backend —
   * usar esto en vez de comparar contra el `id` (taxonomyId), que varía por
   * tenant y no es estable entre entornos.
   */
  systemKey?: "cash" | "giftcard" | "internal" | "check" | "qr" | null
  /** Key de color de la paleta unificada (lib/ui/color-palette.ts). Acento en el pill. */
  color?: string
  /** Orden de aparición en el pay-dialog (drag&drop del panel). */
  sortOrder?: number | null
}

// ── Config del tenant ─────────────────────────────────────────────────────────

export interface PosConfig {
  currency: string
  /** 'yes' | 'no' — si se muestran decimales en la moneda local. */
  decimal: string
  /** 'comma' | 'dot' — separador de miles. */
  thousand: "comma" | "dot"
  /** Etiqueta del impuesto fiscal (ej. "IVA"). */
  taxName: string
  /** Etiqueta del documento fiscal del cliente (ej. "RUC"). */
  tinName: string
  /** Código ISO de país (ej. "PY"). */
  country: string
  /**
   * TZ IANA del tenant (ej. "America/Asuncion"). Convención: los writes del
   * negocio se guardan en hora LOCAL del tenant, naive. Usar con tenantNow()
   * (lib/format-date.ts) para que un device en otra TZ no desfase la fecha.
   */
  timezone: string
  companyName: string
  companyId: string | number
  /** URL del logo del tenant (S3, público). null/undefined si no hay logo cargado. */
  companyLogo?: string | null
  /** Base URL de screens standalone (impresión, KDS, etc). */
  publicUrl: string
  /**
   * Razón social/RUC/email/sitio del tenant — ticket impreso (flujo NO-FE).
   * `null` si el tenant no los cargó en Ajustes. A futuro la fuente puede
   * terminar siendo facturación electrónica (api/lib/EInvoice/*); hoy son
   * los únicos datos disponibles.
   */
  companyBillingName?: string | null
  companyTin?: string | null
  companyEmail?: string | null
  companyWebsite?: string | null
  /**
   * Dirección y teléfono del tenant (`settingAddress`/`settingPhone` de
   * `company.config`, las mismas claves de Ajustes → General). `null` si no
   * los cargó. Los consumen los bloques `company_address`/`company_phone`.
   */
  companyAddress?: string | null
  companyPhone?: string | null
  /**
   * Canales del módulo Bancard (panel → Módulos → Bancard), ya resueltos
   * server-side: módulo activo Y canal habilitado.
   *   - `bancardQrEnabled`  → botón "QR Bancard" en el cobro del POS.
   *   - `bancardPosEnabled` → config de IP del terminal en Ajustes del POS.
   */
  bancardQrEnabled?: boolean
  bancardPosEnabled?: boolean
  /**
   * Canal QR por pasarela de pago — `{ [provider]: boolean }`, resuelto
   * server-side igual que los flags de Bancard (ver PspCatalog en
   * `api/lib/PaymentMethods/`). Es lo que consulta el POS para decidir si
   * muestra el medio de pago de CADA pasarela; `bancardQrEnabled` queda como
   * el flag legacy equivalente a `pspQrEnabled.bancard` (config cacheada de
   * un POS que arrancó antes del refactor).
   */
  pspQrEnabled?: Record<string, boolean>
  /**
   * D3 (context/40-anulacion-y-nota-credito.md): política de reintegro de
   * devoluciones fijada por el comercio. `'ask'` (default) — el POS
   * pregunta en cada devolución; `'cash'`/`'credit'` fijo — el back
   * rechaza (422) un request con el otro modo, así que el POS no debería
   * ofrecerlo.
   */
  settingReturnRefund?: "cash" | "credit" | "ask"
  /**
   * D2 (context/40): habilita OFRECER la reposición de insumos de una
   * producción directa/combo que no llegó a prepararse. Default false.
   */
  settingReturnAllowIngredientReversal?: boolean
  /**
   * Listas fijas de conteo de stock (D3, context/63): qué se cuenta en el
   * mostrador, decidido de antemano por el dueño. El cajero elige una y la
   * completa — en la caja no se buscan productos sueltos.
   *
   * Viajan en el bootstrap y no en un endpoint propio porque el conteo ciego
   * es offline-nativo: sin red el cajero tiene que poder contar igual, y un
   * dato que se pide por HTTP en ese momento no está.
   *
   * Vacío o ausente = el comercio no configuró ninguna lista. La pantalla lo
   * dice; NO cae a "contá todo el catálogo".
   */
  stockCountLists?: StockCountList[]
  /**
   * D9 (context/63): al finalizar, el conteo NO ajusta el stock — queda como
   * registro. Sirve solo para que la caja anticipe qué va a pasar al
   * confirmar; quien lo aplica es el servidor, que lee el flag por su cuenta.
   */
  stockCountRecordOnly?: boolean
}

/**
 * Una lista fija de conteo. `id` y `name` los define el dueño en Ajustes;
 * `itemIds` son ítems del catálogo que la caja ya tiene en su snapshot, así
 * que la pantalla resuelve nombre y SKU sin pedir nada.
 */
export interface StockCountList {
  id: string
  name: string
  itemIds: string[]
}

// ── Caja (register) ───────────────────────────────────────────────────────────

export interface PosRegister {
  id: string
  name: string
  /** UUID del outlet al que pertenece. */
  outletId: string
  /** Punto de expedición fiscal (timbrado PY, etc). */
  expeditionPoint: string | null
  /**
   * Timbrado de la caja (`register.data.registerInvoiceAuth*`, mig 26 —
   * ver RegisterAdminService::listAll). Null si la caja no tiene timbrado
   * configurado. `authStartDate`/`authExpiration` vienen como string ISO,
   * sin parsear acá.
   */
  authNumber?: string | null
  authStartDate?: string | null
  authExpiration?: string | null
}

// ── Item vendible en el POS ───────────────────────────────────────────────────

export interface PosItem {
  id: string
  name: string
  sku: string | null
  price: number
  /**
   * Override de "precio incluye impuesto" a nivel ítem (`itemTaxIncluded`).
   * `null` = el ítem no define override propio → el carrito cae al default
   * de la sucursal (`PosBootstrap.outletTaxIncluded`), mismo criterio que
   * `SaleService::enrichWithTaxes` en el backend (F2a, context/38). NO
   * defaultear acá a `true` — perdería la distinción entre "sin configurar"
   * y "explícitamente incluido", justo lo que el backend sí preserva.
   */
  taxIncluded: boolean | null
  taxId: string | null
  /**
   * Categoría principal (para la grilla de categorías del POS). Solo el id
   * — el nombre se resuelve contra `PosBootstrap.categories` (ver
   * `PosCategory`). NO agregar `categoryName` acá: un dato que pertenece a
   * otra entidad no se copia dentro del ítem (context/45
   * -satelites-item-contact-sync.md §Decisión: el VÍNCULO es satélite, la
   * ENTIDAD no) — con el nombre copiado, renombrar una categoría obligaría a
   * re-bajar todos los ítems que la usan.
   */
  categoryId: string | null
  /**
   * Marca principal del item. Null si no tiene. Solo el id — mismo criterio
   * que `categoryId`, resolver contra `PosBootstrap.brands`.
   */
  brandId: string | null
  /**
   * Sucursal a la que está asignado el item (`item.outletId`, legado 1:1).
   * `null` = sin restricción, vendible en cualquier sucursal. Solo el id —
   * mismo criterio que `categoryId`/`brandId`, resolver contra
   * `useCatalogStore.outlets` (ya viaja con el bootstrap para el selector de
   * caja, ver `lib/catalog/store.ts`). Este campo YA venía en el SELECT
   * compartido de `/v1/items` (`buildItemsSelectSql()`) — solo faltaba
   * mapearlo en `reshapeItem()`, sin costo extra de query.
   */
  outletId: string | null
  /** URL de imagen de portada. Null si no tiene. */
  imageUrl: string | null
  /** Unidad de medida (ej. "kg", "lt"). Null si no aplica. */
  uom: string | null
  /** kind canónico del item (ver ItemKind en frontend). */
  kind: string
  /**
   * % de descuento del catálogo (`itemDiscount`, JSONB flattened, 0-100).
   * Solo tiene semántica especial para `kind === "descuento"`: al agregarse
   * al carrito, se aplica como descuento de venta (no como línea). Para el
   * resto de los kinds es informativo (default % de descuento del producto,
   * no se usa hoy en el flujo de venta). Null = sin descuento configurado.
   */
  discountPercent: number | null
  /** Si trackea stock — para mostrar alerta de stock bajo. */
  trackInventory: boolean
  /**
   * Stock actual del ítem en la caja activa (null si no trackea inventario
   * o si no está disponible). Negativo = stock en rojo.
   * Rellenado por el BFF bootstrap desde el depósito del outlet.
   */
  stock: number | null
  /** true si es un grupo de catálogo (itemIsParent=true). Click en POS abre dialog con hijos. No se vende. */
  isGroup: boolean
  /** UUID del padre si este item es hijo de un grupo (itemParentId). null si es top-level. */
  parentId: string | null
  /**
   * F4 (context/41): el ítem tiene al menos un grupo de add-ons vigente (activo
   * y con opciones). Viaja en el bootstrap —un `EXISTS` en el LIST de
   * `/v1/items`— para que el tap en el tile decida SIN un fetch por producto:
   * `true` abre `<AddonPickerDialog>`, `false` agrega directo como siempre.
   */
  hasAddons: boolean
  /**
   * Grupos de add-ons del ítem, completos (nombre + opciones), embebidos
   * DIRECTO en el ítem — hueco P0 cerrado 2026-08-16 (context/08 §53:
   * `useItemAddonsPos` pedía esto al server al abrir el modal; sin conexión,
   * cualquier ítem con un grupo obligatorio (`minSelect > 0`) era invendible,
   * y en gastronomía ese es el flujo NORMAL, no un borde).
   *
   * Alineado con `context/45-satelites-item-contact-sync.md` (plan sin
   * implementar): add-ons es satélite de `item`, así que la forma correcta
   * es que viaje DENTRO del payload del ítem, no un mecanismo de cache
   * paralelo. El trigger de DB que bumpea `item.updatedAt` al editar un
   * `addon_group` (para que el DELTA de reconexión, context/43, lo levante
   * solo) sigue sin implementar — hoy el catálogo completo (bootstrap) sí
   * trae la copia fresca, y el WS en caliente invalida `pos-bootstrap`
   * cuando se edita (ver `item_addons.php` alias-eado a entity `item` en
   * `use-realtime-sync.ts`); lo que falta es el camino de delta puntual sin
   * bootstrap completo — ver TODO en `context/41-addons-y-combos.md`.
   *
   * Array vacío = sin grupos (nunca `null`, `presentItem()` en el backend lo
   * normaliza). `PosAddonGroup`/`PosAddonOption`: mismo shape que
   * `useItemAddonsPos` consumía del fetch — se mueve la fuente de verdad
   * acá, ese hook pasa a leer del store en vez de hacer red.
   */
  addonGroups: PosAddonGroup[]
  /**
   * Receta del combo FIJO (`item_compound`), embebida DIRECTO en el ítem —
   * mismo patrón que `addonGroups` arriba, mismo motivo: offline-first. Hasta
   * 2026-08-19 esto NO viajaba al POS — el combo fijo se agregaba al carrito
   * como un ítem plano, sin ninguna vista de qué lo compone (tester,
   * "Despliegue de Combos"). Array vacío = sin receta (nunca `null`,
   * `presentItem()` en el backend lo normaliza) — el 99% del catálogo cae acá.
   */
  compoundItems: PosCompoundItem[]
}

/** Componente de la receta de un combo fijo. Ver `PosItem.compoundItems`. */
export interface PosCompoundItem {
  itemId: string
  itemName: string
  quantity: number
  uom: string | null
  sort: number
}

/** Opción de un grupo de add-ons. Espejo de `AddonService::listForItem` (sin `itemPrice` — D2, context/41). */
export interface PosAddonOption {
  id: string
  itemId: string
  itemName: string
  /** Recargo de la opción. 0 = no suma al precio (D2). */
  priceDelta: number
  /** Preseleccionada al abrir el modal. */
  isDefault: boolean
  /** Fija: marcada y no se puede desmarcar (implica isDefault). */
  isLocked: boolean
  /** Cuántas veces se puede repetir la misma opción (≥ 1). */
  maxQty: number
  sort: number
}

/** Grupo de add-ons de un ítem. Ver `PosItem.addonGroups`. */
export interface PosAddonGroup {
  id: string
  name: string
  /** 0 = grupo opcional. > 0 = el POS no deja confirmar sin elegir. */
  minSelect: number
  /** null = sin tope. */
  maxSelect: number | null
  sort: number
  options: PosAddonOption[]
}

// ── Categorías y marcas del tenant (context/45) ──────────────────────────────

/**
 * Categoría de producto del tenant (tabla `category`, migration 21). Lista
 * propia del bundle `settings` (context/43-sync-incremental.md) — chica
 * (decenas de filas), se recarga entera cuando cambia. `PosItem.categoryId`
 * la referencia; el nombre se resuelve acá, nunca copiado dentro del ítem
 * (ver comentario en `PosItem.categoryId`).
 *
 * Efecto colateral deseado: una categoría sin productos ahora existe para
 * la caja (antes, al derivarse de los items, una categoría vacía era
 * invisible).
 */
export interface PosCategory {
  id: string
  name: string
}

/** Marca de producto del tenant (tabla `brand`, migration 22). Mismo criterio que `PosCategory`. */
export interface PosBrand {
  id: string
  name: string
}

// ── Impuestos del tenant (F2b, context/38) ───────────────────────────────────

/**
 * Tasa de impuesto del comercio, tal como vive en la tabla `tax` (F0). El
 * carrito la busca por `PosItem.taxId` (vía `lib/cart/line-tax.ts`) para el
 * IVA que muestra y para el neteo de "quitar IVA" que cobra. Reemplazó al
 * `TAX_RATE = 0.10` hardcodeado, eliminado el 2026-08-22.
 */
export interface PosTaxRate {
  id: string
  /** Porcentaje (ej. 10, 5, 21). Irrelevante si `kind === "exempt"`. */
  rate: number
  /** `exempt` ≠ tasa 0% — distinción fiscal (MX/CO). Ver context/38 §Reglas LATAM. */
  kind: "rate" | "exempt"
}

// ── Plantillas de impresión (context/08 §53, hueco P0 cerrado 2026-08-16) ────

/**
 * Plantilla de impresión (ticket/factura/cotización), bajada al bootstrap
 * del POS. Reusa el shape EXACTO de `DocumentTemplateRow`
 * (`lib/types/print-template.ts`, la fuente canónica que ya consume el editor
 * del panel) — un solo shape para las tres superficies (editor panel, GET
 * puntual `/v1/document-templates?id=`, este bundle), en vez de un tipo
 * paralelo que puede divergir.
 *
 * Antes de esta fecha, `printSale`/`printTicketInBrowser` pedían la
 * plantilla al server EN EL MOMENTO de imprimir — sin cache ni fallback, así
 * que offline el ticket físico no salía aunque la venta ya se hubiera
 * emitido bien (contradice context/08 §53: la emisión depende SOLO del
 * dispositivo y la impresora). Son pocas filas por tenant (una por
 * combinación docType/variante) — mismo criterio de tamaño que
 * categories/brands/taxes, no items/customers.
 */
export type PosPrintTemplate = DocumentTemplateRow

// ── Cliente (para búsqueda en el POS) ────────────────────────────────────────

export interface PosCustomer {
  id: string
  /** Nombre display (razón social o nombre persona). */
  name: string
  /** Teléfono en E.164 (convención §31). Null si no tiene. */
  phone: string | null
  /** Documento fiscal (RUC PY, etc). */
  tin: string | null
  /** Crédito en cuenta corriente disponible. */
  storeCredit: number
  /** Es acreedor (permite venta a crédito type=3). */
  isCreditable: boolean
  /**
   * Datos extendidos del contacto — `/v1/contacts` (`presentRow()`) ya los
   * devolvía y el reshape del POS los descartaba, así que los bloques de
   * cliente del ticket salían vacíos. Todos OPCIONALES a propósito: un
   * snapshot de IndexedDB anterior a este cambio sigue siendo válido (quedan
   * `undefined` hasta el próximo sync, sin tocar la versión de la DB).
   */
  email?: string | null
  note?: string | null
  /** Cumpleaños (`contactBirthDay`), tal cual lo persiste el backend. */
  bday?: string | null
  loyalty?: string | null
  address?: string | null
  address2?: string | null
  city?: string | null
  location?: string | null
  country?: string | null
}

// ── Empleado del outlet (roster del lock screen) ─────────────────────────────

/**
 * Operador habilitado en la sucursal del device. Proyección MÍNIMA a propósito:
 * el bootstrap del POS termina persistido en el device, así que cada campo que
 * se sume acá es superficie expuesta en la tablet del mostrador. El backend
 * (`UsersService::rosterForOutlet()`) devuelve exactamente estos tres campos.
 */
export interface PosUser {
  id: string
  name: string
  /**
   * Hash SHA-256 (hex 64 chars) del PIN del operador. Almacenado en localStorage via catalog store.
   * Decision del owner (2026-06-25): SHA-256 es más simple, rápido en browser, matchea legacy.
   * Hash visible en localStorage es suficiente para identificacion — el PIN no es una
   * contrasena critica, protege contra peeking casual, no contra atacantes con acceso al device.
   *
   * `null` = ese usuario no tiene PIN cargado y por lo tanto no puede
   * desbloquear la caja (el lock screen lo saltea).
   */
  pinhash?: string | null
}

// ── Bootstrap completo ────────────────────────────────────────────────────────

/**
 * Sucursal activa del device. `lat`/`lng` son las columnas numéricas de
 * `outlet` (mig 14) y pueden ser null si nunca se cargó la ubicación — las
 * consume el PIN del local en la vista mapa de /pos/ordenes.
 */
export interface PosOutlet {
  id: string
  name: string
  lat: number | null
  lng: number | null
  /** Datos fiscales de la sucursal — ticket impreso (flujo NO-FE). Null si no cargados. */
  address?: string | null
  billingName?: string | null
  tin?: string | null
  phone?: string | null
}

export interface PosBootstrap {
  config: PosConfig
  user: {
    id: string | number
    /** Nombre del usuario del contexto. Vacío si el backend no lo trae todavía. */
    name?: string
    role: number
    /** Nombre del rol tal como lo ve el comercio ("Cajero", "Encargado"). Vacío si el rol es legacy y no existe como taxonomía. */
    roleName?: string
  }
  outlet: PosOutlet
  /** Todas las sucursales disponibles para el tenant. */
  outlets: Array<{ id: string; name: string }>
  registers: PosRegister[]
  items: PosItem[]
  customers: PosCustomer[]
  paymentMethods: PaymentMethodConfig[]
  /**
   * Roster del lock screen (id/name/pinhash de los habilitados en la sucursal
   * del device).
   *
   * `null` NO es "vacío": significa que la respuesta no traía roster (`/api`
   * desactualizado, o la sesión que la contestó no era la del device). El lock
   * screen muestra un mensaje y una salida DISTINTOS para cada caso — ver
   * `reshapeRoster` en `app/api/pos/bootstrap/route.ts`.
   *
   * Opcional además de nullable: un snapshot de IndexedDB guardado por un
   * build anterior a este campo se rehidrata sin la clave.
   */
  users?: PosUser[] | null
  /** UUID de la caja activa en el claim del JWT. '' = sin caja seleccionada. */
  activeRegisterId: string
  /** Tasas de impuesto del tenant (F0, tabla `tax`). Ver `PosTaxRate`. */
  taxes: PosTaxRate[]
  /** Categorías del tenant. Ver `PosCategory`. */
  categories: PosCategory[]
  /** Marcas del tenant. Ver `PosBrand`. */
  brands: PosBrand[]
  /** Plantillas de impresión del tenant. Ver `PosPrintTemplate`. */
  printTemplates: PosPrintTemplate[]
  /**
   * Default incluido/añadido del IVA de la sucursal activa
   * (`outlet.itemsTaxIncluded`). Fallback cuando `PosItem.taxIncluded` es
   * `null` — mismo criterio que el backend (`SaleService::enrichWithTaxes`).
   */
  outletTaxIncluded: boolean
  /**
   * Próximo correlativo de factura de `activeRegisterId`, según
   * `document_sequence` (`GET /v1/register` → `RegisterService::
   * docNumbers()`, misma fuente que "próxima factura" en Ajustes). `null` si
   * el upstream falló — el bootstrap nunca bloquea por esto.
   *
   * Es el seed inicial de `lib/pos/invoice-numbering.ts` (`primeInvoiceNumbering`,
   * llamado desde `use-catalog-seed.ts`): el device offline decide su propio
   * "último correlativo + 1" localmente, pero necesita conocer el punto de
   * partida la primera vez (o corregirse hacia adelante si otro proceso
   * movió la secuencia). Reemplaza al arriendo de bloques de numeración
   * (`numbering-lease.ts`, RECHAZADO 2026-08-17 — ver
   * context/29-numeracion-y-exclusividad-de-caja.md §6).
   */
  nextInvoiceNo: number | null
  /**
   * Cuántos dígitos ocupa el correlativo de factura al imprimirse
   * (`document_sequence.padwidth`, mig 159) — 7 = formato fiscal PY
   * `001-001-0002129`.
   *
   * Es FORMATO, no dato: `nextInvoiceNo` sigue siendo el entero con el que el
   * device numera. Baja en el bootstrap porque el POS emite offline y no
   * puede consultar el ancho al imprimir. Se consume SOLO vía
   * `lib/documents/format-document-number.ts`; `null` → default legal.
   */
  invoicePadWidth: number | null
  /**
   * Techo del rango autorizado del timbrado de la caja activa
   * (`document_sequence.rangeto` — D5, context/37). El POS lo persiste junto
   * al contador local (`primeInvoiceRange`) y avisa "quedan N números"
   * ANTES de que el corte duro server-side deje a la caja sin poder
   * facturar. `null` = sin rango cargado, sin preaviso.
   */
  invoiceRangeTo: number | null
}
