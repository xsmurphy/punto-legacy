import { saleTypeLabelOrNull } from "@/lib/domain/sale-type"

/**
 * VOCABULARIO del normalizador: qué significa cada campo que devuelven los
 * endpoints del panel, y cómo se dice en un idioma que un modelo entienda sin
 * haber leído el PHP.
 *
 * El motor (el recorrido, la poda, el sobre con la moneda) vive en
 * `normalize-tool-result.ts`. Acá solo está el significado. Se separan porque
 * cambian por motivos distintos: una columna nueva en un reporte toca este
 * archivo y nunca el otro.
 *
 * ── La regla que ordena todo este archivo ──────────────────────────────────
 * Una entrada solo existe si su semántica está CONFIRMADA en el backend, y el
 * comentario dice dónde. Un campo que no se pudo confirmar viaja crudo: el
 * modelo lee `transactionStatus: 4` y duda, que es correcto. Si le ponemos un
 * nombre lindo y equivocado, deja de dudar — y ahí el error llega al dueño del
 * comercio como si fuera un dato.
 *
 * ── Por qué las reglas son LISTAS y no una por nombre ──────────────────────
 * Los nombres colisionan entre reportes, y no de forma menor:
 *
 *   `count`  → cantidad de VENTAS en el resumen anual,
 *              unidades EN UN DEPÓSITO en el reporte de stock.
 *   `cogs`   → costo total de lo vendido en el reporte de productos,
 *              costo unitario PROMEDIO en el reporte de stock.
 *   `type`   → tipo de TRANSACCIÓN en cobros/cotizaciones,
 *              rol del CONTACTO (cliente/proveedor) en el listado de contactos.
 *
 * Una tabla plana nombre → significado tendría que elegir uno y mentir en el
 * otro. Por eso cada nombre lleva una LISTA de reglas y cada regla puede
 * exigir una condición sobre la fila (`when`): gana la primera que matchea, y
 * si ninguna matchea el campo viaja crudo.
 */

// ── Tipos ────────────────────────────────────────────────────────────────────

export interface FieldRule {
  /**
   * Condición sobre el VALOR y la fila CRUDA (claves originales). Sin `when`,
   * la regla aplica siempre. Es lo que resuelve las colisiones de nombre.
   */
  when?: (value: unknown, row: Record<string, unknown>) => boolean
  /** Nombre nuevo. El crudo desaparece: dejar los dos duplica tokens. */
  rename?: string
  /** Traduce el VALOR. Devolver `undefined` poda el campo. */
  translate?: (value: unknown) => unknown
  /** El valor es un monto en la moneda del tenant (lo declara `meta`). */
  money?: boolean
  /** Poda el campo: siempre (`true`) o cuando el predicado da `true`. */
  drop?: true | ((value: unknown) => boolean)
  /**
   * Advertencia que va UNA vez a `meta.notes` si la regla se aplicó. Para lo
   * que no cabe en un nombre de campo: que un neto incluya devoluciones, que
   * un total no tenga el IVA descontado. Cortas — es contexto, no prosa.
   */
  note?: string
}

// ── Helpers de valor ─────────────────────────────────────────────────────────

export function asNumber(v: unknown): number | null {
  if (typeof v === "number") return Number.isFinite(v) ? v : null
  if (typeof v === "string" && v.trim() !== "") {
    const n = Number(v)
    return Number.isFinite(n) ? n : null
  }
  return null
}

/**
 * Booleano tolerante. Postgres serializa los `BOOLEAN` como `t`/`f` según el
 * driver, y los reportes los emiten como `0`/`1` — el mismo campo llega de las
 * tres formas según por dónde salga (`TransactionsService::isComplete()` hace
 * exactamente esta normalización del lado PHP).
 */
export function asBool(v: unknown): boolean | null {
  if (typeof v === "boolean") return v
  const n = asNumber(v)
  if (n !== null) return n !== 0
  if (typeof v === "string") {
    const s = v.trim().toLowerCase()
    if (s === "t" || s === "true") return true
    if (s === "f" || s === "false") return false
  }
  return null
}

/** `0`, `"0"`, `null`, ausente — el "no hay dato" de una columna numérica. */
function isZeroish(v: unknown): boolean {
  if (v === null || v === undefined || v === "") return true
  const n = asNumber(v)
  return n === null || n === 0
}

/** Poda un booleano en `false`: la ausencia dice lo mismo y no ocupa lugar. */
const dropWhenFalse = (v: unknown) => asBool(v) !== true

/** El campo `<x>Id` no aporta si la fila ya trae `<x>Name`. */
const hasName = (name: string) => (_v: unknown, row: Record<string, unknown>) =>
  typeof row[name] === "string" && row[name] !== ""

// ── El diccionario ───────────────────────────────────────────────────────────

export const FIELD_RULES: Record<string, FieldRule[]> = {
  // ── Transacciones ────────────────────────────────────────────────────────

  /**
   * `api/lib/Sales/SaleType.php` es la fuente de verdad y
   * `lib/domain/sale-type.ts` su espejo TS, con un test de paridad que lee el
   * enum PHP del filesystem. NO se define un mapa acá: sería la sexta copia del
   * mismo vocabulario, y las copias anteriores ya habían divergido entre sí.
   *
   * Un tipo desconocido queda CRUDO (el helper devuelve null y devolvemos el
   * valor original): que la API traiga un tipo que el espejo no tiene es un bug
   * del espejo, y mostrar el número es mejor que ocultarlo.
   */
  transactionType: [{ translate: (v) => saleTypeLabelOrNull(v as number) ?? v }],

  /**
   * ESTE es el campo del incidente que motivó todo el archivo: el modelo vio
   * `transactionComplete: 0` y dijo que "sugiere" un documento a crédito o
   * pendiente de cierre. Era un dato exacto, no una sugerencia.
   *
   * Significa SALDADO, y nada más. `SaleService.php:790-793` lo pone en 0 al
   * crear los tipos que se pagan después (crédito 3, compra a crédito 4,
   * agendado 13) y en 1 para el resto; `CreditPaymentService.php:723-731` lo
   * pasa a 1 recién cuando el pago cancela la deuda entera (un pago PARCIAL no
   * lo mueve). No dice nada sobre entrega, cierre de caja ni estado de la
   * orden — eso es `transactionStatus`, que es otro campo y no se traduce acá.
   */
  transactionComplete: [
    {
      rename: "settled",
      translate: (v) => asBool(v) ?? v,
      note: "settled: el documento está saldado. false = queda saldo por cobrar (lo normal en una venta a crédito hasta que se cancele la deuda completa; un pago parcial no lo cambia).",
    },
  ],

  /** `netTotal - payed`. Solo se calcula para el tipo 3 (crédito): en los demás
   *  queda 0 por construcción (`TransactionsService.php:103-107`). */
  topay: [{ rename: "outstandingBalance", money: true }],

  /**
   * `total` y `netTotal` salen del MISMO valor en la vista de transacciones
   * (`TransactionsService.php:151-189`). Mandar los dos es pagar tokens por
   * decir lo mismo dos veces e invitar a que el modelo los reste entre sí.
   * Se poda solo cuando se comprueba la igualdad: si algún día divergen, los
   * dos viajan.
   */
  netTotal: [
    { when: (v, row) => "total" in row && row.total === v, drop: true },
    { money: true },
  ],

  /** `netTotal - tax` (`TransactionsService.php:181-186`). */
  totalGravado: [{ rename: "taxableAmount", money: true }],

  /** Venta emitida sin IVA. Cuando es false no dice nada: se poda. */
  ivaRemoved: [{ rename: "taxRemoved", translate: (v) => asBool(v) ?? v, drop: dropWhenFalse }],

  // ── Resumen anual (`/v1/reports/summary_year`) ───────────────────────────

  /**
   * `SUM(transactionTotal)` de los tipos 0 y 3 sin anular
   * (`SummaryYearService.php:100`). El descuento viaja aparte y NO está
   * restado: el nombre lo dice para que el modelo no lo presente como neto.
   */
  salesTotal: [{ rename: "salesTotalBeforeDiscount", money: true }],

  /**
   * Son COMPRAS (`transactionType IN (1,4)`, `SummaryYearService.php:142-150`),
   * no los gastos del módulo Finanzas — que son otra tabla (`fin_movement` con
   * `kind='expense'`) y otro número. Un modelo que lee "expenses" contesta
   * "gastaste X" y el dueño compara contra un total que no es ese.
   */
  expensesTotal: [
    {
      rename: "purchasesTotal",
      money: true,
      note: "purchasesTotal son COMPRAS a proveedores (contado y crédito), no los gastos del módulo Finanzas.",
    },
  ],

  returnsTotal: [{ money: true }],

  /**
   * Gift cards + crédito interno + puntos + ventas internas
   * (`NonAddingSales.php:36, :59-69`): ventas que existen pero no suman a la
   * facturación. `nonAddingTotal` no se entiende sin el PHP.
   */
  nonAddingTotal: [
    {
      rename: "nonRevenueSalesTotal",
      money: true,
      note: "nonRevenueSalesTotal: ventas que no suman a la facturación (gift cards, crédito interno, puntos y ventas internas).",
    },
  ],

  /**
   * Clientes NUEVOS registrados en el mes (`COUNT(contactId) ... WHERE type=1
   * AND contactDate BETWEEN`, `SummaryYearService.php:176-184`), no clientes
   * que compraron. El `when` lo ata a la fila del resumen: `customers` a secas
   * en otro payload podría ser cualquier cosa.
   */
  customers: [
    {
      when: (_v, row) => "salesTotal" in row,
      rename: "newCustomers",
      note: "newCustomers son clientes NUEVOS dados de alta ese mes en la sucursal consultada — no la cantidad de clientes que compraron.",
    },
  ],

  // ── KPIs de ventas (`/v1/reports/dashboard?widget=incomeOutcomeStats`) ───
  //
  // El widget es el ÚNICO lugar del backend que calcula el ticket promedio
  // (`DashboardService.php:121`) y hasta ahora ninguna tool lo exponía. Las
  // cinco reglas de abajo se atan a la fila por `customerAverage`, que es la
  // marca de este widget: `total`, `count`, `expenses` y `margin` a secas
  // significan otra cosa en cualquier otro payload.

  /**
   * `total / count` (`DashboardService.php:121`) — pero OJO con el numerador, y
   * esto es una trampa real: el promedio usa el `SUM(transactionTotal)` CRUDO,
   * mientras el `total` que viaja en la misma fila es
   * `(total - descuento) - ventas internas` (`:119`). O sea
   * `averageTicket * salesCount` NO da `total`, y por un margen que puede ser
   * grande en un comercio que descuenta. Sin la nota, el modelo hace esa
   * multiplicación para "verificar", no le cierra, y reporta una inconsistencia
   * que no existe.
   */
  customerAverage: [
    {
      rename: "averageTicket",
      money: true,
      note: "averageTicket es el promedio por venta calculado sobre el total BRUTO (antes de descuentos y de descontar ventas internas), mientras que total en la misma respuesta va neto. Por eso averageTicket x salesCount no da total: no es un error.",
    },
  ],

  /**
   * Mismo enum de tipos que `expensesTotal` del resumen anual
   * (`transactionType IN (1,4)`, `DashboardService.php:125-130`): son COMPRAS a
   * proveedores, no los gastos del módulo Finanzas.
   */
  expenses: [
    {
      when: (_v, row) => "customerAverage" in row,
      rename: "purchasesTotal",
      money: true,
      note: "purchasesTotal son COMPRAS a proveedores (contado y crédito), no los gastos del módulo Finanzas.",
    },
  ],

  /** `total - compras` (`DashboardService.php:132`). Puede ser NEGATIVO. */
  revenue: [
    {
      when: (_v, row) => "customerAverage" in row,
      rename: "netResult",
      money: true,
      note: "netResult = ventas netas - compras del período. Es negativo cuando se compró más de lo que se vendió, y no incluye los gastos del módulo Finanzas.",
    },
  ],

  /**
   * Porcentaje, no un monto — y con un default que engaña: cuando no hay
   * compras registradas la fórmula NO se calcula y devuelve 100
   * (`DashboardService.php:133`). Un comercio que no cargó sus compras ve
   * "margen 100%" y no es un margen, es la ausencia del dato.
   */
  margin: [
    {
      when: (_v, row) => "customerAverage" in row,
      rename: "marginPercent",
      note: "marginPercent está en porcentaje. Vale 100 cuando el período no tiene compras registradas: ahí no es un margen sino la ausencia del costo. Si se compara entre períodos, su absoluteChange está en PUNTOS porcentuales (de 75 a 60 son 15 puntos, no 15%).",
    },
  ],

  // ── Reporte de productos: el bloque de período anterior ──────────────────
  //
  // `ProductsService::general()` (`api/lib/Reports/ProductsService.php:57-67`)
  // ya calcula el período anterior cuando la consulta no filtra por cliente,
  // usuario ni ítem — y la tool lo TIRABA junto con el resto del payload al
  // hacer `rows.slice()`. Viaja gratis en la misma respuesta: recuperarlo es la
  // única comparación que no cuesta un segundo fetch.

  /**
   * Totales del período anterior, calculados por el backend. La ventana la
   * define `NonAddingSales::previousPeriod()` desplazando por DURACIÓN, no por
   * calendario, así que puede no coincidir con el mes anterior completo — la
   * nota lo dice porque el bloque no trae sus propias fechas.
   */
  prev: [
    {
      when: (_v, row) => "prevByItem" in row || "rows" in row,
      rename: "previousPeriodTotals",
      note: "previousPeriodTotals son los totales del período ANTERIOR de la misma duración y cubren TODOS los productos, no solo los que se listan acá: no los compares contra la suma de las filas visibles. La ventana se desplaza por duración y no está alineada al mes calendario, así que sirve para la tendencia y no como 'el mes pasado' exacto.",
    },
  ],

  /**
   * `{itemId: unidades_del_período_anterior}` — el eje que ninguna comparación
   * agregada puede dar: cuánto vendió CADA producto antes.
   */
  prevByItem: [
    {
      rename: "previousPeriodUnitsByItemId",
      note: "previousPeriodUnitsByItemId mapea id de ítem a las unidades que vendió en el período anterior, recortado a los ítems que se listan en esta respuesta. Un ítem listado que no aparece en el mapa vendió 0 unidades antes: es un dato, no una ausencia.",
    },
  ],

  /** Ventas internas descontadas del total (`ProductsService.php:201-210`). */
  internals: [
    {
      when: (_v, row) => "rows" in row,
      rename: "internalSalesExcluded",
      note: "internalSalesExcluded son ventas internas del período que NO suman a la facturación. Solo tiene valores si el comercio activó ignoreInternal en sus ajustes.",
    },
  ],

  // ── Finanzas (`/v1/finance/summary`) ─────────────────────────────────────

  /** Suma de los saldos de todas las cuentas. Es una FOTO al momento de la
   *  consulta, no un flujo del período: no se compara entre períodos. */
  totalBalance: [
    {
      money: true,
      note: "totalBalance es el saldo de las cuentas AHORA, no un movimiento del período consultado: no cambia si se acota el rango de fechas.",
    },
  ],

  totalIncome: [{ money: true }],
  totalExpense: [{ money: true }],
  /** `income - expense` del período. Negativo cuando salió más de lo que entró. */
  netFlow: [{ money: true }],

  // ── Unidades y conteos ───────────────────────────────────────────────────

  /**
   * `SUM(itemSoldUnits)` / `SUM(transactionUnitsSold)`. Es NETO: en una
   * devolución las unidades vienen negadas (`ProductsService.php:103-106`), así
   * que restan del acumulado.
   */
  usold: [
    {
      rename: "unitsSold",
      note: "unitsSold es neto: las devoluciones restan unidades del acumulado.",
    },
  ],

  /**
   * Colisión resuelta por forma de la fila. En stock, `count` son unidades del
   * depósito (`StockService.php:134-151`, la fila trae `locationId`; el bloque
   * `principal` trae `min`). En el resumen anual es la cantidad de
   * transacciones de venta (`COUNT(*)`, `SummaryYearService.php:97`). Fuera de
   * esos dos casos viaja crudo — no hay un tercer significado confirmado.
   *
   * El tercer caso confirmado es el widget de KPIs: ahí `count` es
   * `COUNT(transactionId)` de las ventas no anuladas de tipo 0, 3 y 6
   * (`DashboardService.php:107-113`) y la fila se reconoce por `customerAverage`.
   * Es EXACTAMENTE el denominador del ticket promedio, y la razón por la que ese
   * promedio se expone desde el backend en vez de derivarse acá: fuera de estos
   * tres casos nadie sabe qué cuenta `count`, y dividir por el equivocado da un
   * ticket promedio plausible y falso.
   */
  count: [
    { when: (_v, row) => "locationId" in row || "min" in row, rename: "onHand" },
    { when: (_v, row) => "salesTotal" in row, rename: "salesCount" },
    { when: (_v, row) => "customerAverage" in row, rename: "salesCount" },
  ],

  /** Mes 1-12 del desglose de un ítem (`ProductsService.php:127-137`). Viene
   *  `null` salvo en esa vista, y el null lo poda el motor. */
  smonth: [{ rename: "monthNumber" }],

  /**
   * En el resumen anual `month` es el número de mes; en la respuesta del
   * reporte de productos `month` es un BOOLEANO que indica si la vista está
   * desglosada por mes (`ProductsService.php:55`). Solo se renombra el número.
   */
  month: [{ when: (v) => typeof v === "number", rename: "monthNumber" }],

  // ── Costos y margen ──────────────────────────────────────────────────────

  /**
   * Dos significados distintos con el mismo nombre:
   *  - en stock (la fila trae `onHand`) es el costo unitario PROMEDIO del ítem
   *    (`StockService.php:94`), con IVA incluido por decisión explícita
   *    (`context/52`);
   *  - en el reporte de productos es el costo TOTAL de lo vendido,
   *    `SUM(ABS(itemSoldCOGS) * itemSoldUnits)` (`ProductsService.php:107`).
   *
   * En 0 se poda en los dos casos: un costo 0 no significa "cuesta cero", significa
   * "no hay costeo cargado", y es justo el valor que hace que el modelo calcule
   * un margen del 100%.
   */
  cogs: [
    {
      when: (_v, row) => "onHand" in row,
      rename: "averageUnitCost",
      money: true,
      drop: isZeroish,
    },
    { rename: "costOfGoodsSold", money: true, drop: isZeroish },
  ],

  /** `SUM(itemSoldComission)` (`ProductsService.php:108`). En 0 —el caso normal
   *  en un comercio que no paga comisión— es ruido puro. */
  comission: [{ rename: "salesCommission", money: true, drop: isZeroish }],

  /**
   * Margen calculado en PHP, y con dos trampas confirmadas:
   *
   *  1. Sin costo cargado (`cogs` en 0) la fórmula devuelve el total entero, y
   *     el modelo lo lee como margen del 100%. No es un margen: es la ausencia
   *     del costo. Se poda, y la nota dice por qué falta.
   *  2. La fórmula DIFIERE por vista: `general` y `detail` hacen
   *     `(total - cogs) - comission` sin descontar el IVA del total, mientras
   *     `combos` y el bloque `prev` sí lo descuentan (`ProductsService.php:52,
   *     :82, :303, :190`). La fila no dice de qué vista viene, así que la nota
   *     lo advierte en vez de afirmar un neto que puede no serlo.
   */
  utility: [
    {
      when: (_v, row) => isZeroish(row.cogs),
      drop: true,
      note: "El margen no viaja en las filas sin costo cargado: sin costo no hay margen que calcular (el crudo daría 100%).",
    },
    {
      rename: "grossProfit",
      money: true,
      note: "grossProfit = total - costo - comisión. Según la vista del reporte puede NO tener el IVA descontado del total.",
    },
  ],

  // ── Ítems y catálogo ─────────────────────────────────────────────────────

  /**
   * Traducción deliberadamente imprecisa en un caso, y es lo correcto.
   *
   * `itemType = 'product'` NO significa "producto": el mapa canónico
   * `ItemKind::MAP` (`api/lib/Items/ItemKind.php:27-40`) guarda los servicios
   * con ese mismo valor. Traducirlo como "producto" haría que el modelo llame
   * producto a un servicio con total confianza. "producto o servicio" es lo que
   * el campo realmente afirma.
   *
   * `compound`, `precombo` y `comboAddons` se leen en los reportes pero no
   * están en el mapa canónico y no se encontró quién los escribe: quedan
   * crudos.
   */
  itemType: [
    {
      translate: (v) => {
        const map: Record<string, string> = {
          product: "producto o servicio",
          production: "producción previa",
          combo: "combo",
          discount: "descuento",
          giftcard: "gift card",
        }
        return typeof v === "string" ? (map[v] ?? v) : v
      },
    },
  ],

  /**
   * En el reporte de productos `deleted` NO es el archivado del catálogo: es
   * "el itemId de la línea vendida ya no resuelve a ninguna fila de `item`"
   * (`ProductsService.php:361`, el lookup no filtra `itemStatus`). Un ítem
   * archivado sale con `deleted: false`. En false es el caso normal y se poda.
   */
  deleted: [{ rename: "itemNoLongerInCatalog", drop: dropWhenFalse }],

  /**
   * CREDENCIALES DEL EQUIPO — se podan SIEMPRE, sin excepción.
   *
   * `/v1/users` le manda al panel el PIN de caja en claro (`lockPass`), porque
   * el form de equipo lo prellena. El agente corre con esa misma credencial de
   * panel, así que sin esta regla cada `get_users` reenviaba el PIN de todo el
   * personal a un modelo EXTERNO. Los hashes ya no salen del endpoint desde
   * 2026-09-02, pero se podan igual acá: si mañana alguien vuelve a
   * proyectarlos, esta capa los frena antes de que crucen a la red.
   *
   * `drop: true` a secas y no un `when`: no hay ningún contexto en el que un
   * modelo necesite una credencial para responder.
   */
  lockPass: [{ drop: true }],
  lockpasshash: [{ drop: true }],
  pinhash: [{ drop: true }],

  /**
   * Mínimo del ÍTEM, repetido idéntico en cada depósito y en el principal. No
   * existe un mínimo por depósito, y el código lo dice con todas las letras
   * (`StockService.php:114-120`).
   */
  min: [
    {
      rename: "itemMinStock",
      note: "itemMinStock es el mínimo del ÍTEM y se repite igual en cada depósito: no existe un mínimo por depósito.",
    },
  ],

  // ── Estados ──────────────────────────────────────────────────────────────

  /**
   * `1 = activo, 0 = archivado/inactivo` en las cinco tablas donde es numérico:
   * `outlet` (`v1/outlets.php:106`), `item` (`ItemRepository.php:64,:78`),
   * `contact` (`v1/contacts.php:11`, `v1/users.php:10`), `fin_account` y
   * `fin_movement` (`72_finance.sql:31,:76`).
   *
   * Solo se traduce cuando es numérico 0/1. Los `status` de texto —los cheques
   * son `pending|deposited|cleared|bounced|cancelled`— ya se explican solos y
   * pasan intactos.
   */
  status: [
    {
      when: (v) => {
        const n = asNumber(v)
        return n === 0 || n === 1
      },
      rename: "active",
      translate: (v) => asBool(v) ?? v,
    },
  ],

  /**
   * Rol del contacto contra tipo de transacción: el mismo nombre para dos
   * enums sin relación. La fila de contacto trae `UID`
   * (`ContactService.php:472-505`); las de cobros y cotizaciones traen
   * `transactionId` (`TransactionsService.php:262-286, :348-370`). Sin ninguna
   * de las dos marcas, viaja crudo.
   */
  type: [
    {
      when: (_v, row) => "transactionId" in row,
      translate: (v) => saleTypeLabelOrNull(v as number) ?? v,
    },
    {
      when: (_v, row) => "UID" in row,
      translate: (v) => {
        const n = asNumber(v)
        // COMMENT de columna en `db-schema.sql:380` + constantes en
        // `ContactService.php:39-40`. El 0 es el usuario/empleado y no se puede
        // crear desde el endpoint público (`ContactService.php:244-247`).
        if (n === 0) return "usuario del comercio"
        if (n === 1) return "cliente"
        if (n === 2) return "proveedor"
        return v
      },
    },
  ],

  /** `outletEcom`, serializado ya como booleano (`OutletsService.php:366`). En
   *  false es el caso normal: se poda. */
  ecom: [{ rename: "ecommerce", drop: dropWhenFalse }],

  // ── Montos sueltos, confirmados ──────────────────────────────────────────

  total: [{ money: true }],
  subtotal: [{ money: true }],
  tax: [{ money: true }],
  discount: [{ money: true }],
  price: [{ money: true }],
  cost: [{ money: true }],
  /** `fin_movement.amount` es SIEMPRE positivo: el signo lo da `kind`
   *  (`72_finance.sql:63`, `MovementService.php:827`). */
  amount: [{ money: true }],
  balance: [{ money: true }],
  storeCredit: [{ money: true }],
  creditLine: [{ money: true }],
  loyaltyAmount: [{ money: true }],

  // ── Poda de identificadores ──────────────────────────────────────────────
  //
  // Los ids NO se podan a ciegas, y esto es una restricción real y verificada:
  // el agente del panel escribe con `update_contact` y `update_item_price`, que
  // reciben el `id` del registro en su payload (`lib/agent/confirm-tool.ts:41`).
  // Ese id lo saca de `get_items` / `get_contacts`. Podarlo dejaría al agente
  // sin forma de editar nada.
  //
  // Lo que sí se poda es el id que la propia fila ya resolvió a nombre, y el que
  // es constante para todo el tenant.

  /** Duplicado exacto de `id` en el serializador de contactos
   *  (`ContactService.php:472-473`: `'id' => $id, 'UID' => $id`). */
  UID: [{ when: (v, row) => row.id === v, drop: true }],

  /** Constante en todas las filas de un tenant: no distingue nada y ninguna
   *  tool lo acepta como entrada. */
  companyId: [{ drop: true }],

  outletId: [{ when: hasName("outletName"), drop: true }],
  registerId: [{ when: hasName("registerName"), drop: true }],
  userId: [{ when: hasName("userName"), drop: true }],
  customerId: [{ when: hasName("customerName"), drop: true }],
}

/**
 * Campos que se dejaron CRUDOS a propósito, con el motivo. No es una lista
 * muerta: es la respuesta a "¿por qué esto no está traducido?" para el próximo
 * que lea el archivo, y el lugar donde sumar la traducción cuando alguien
 * confirme el significado que falta.
 *
 *  - `transactionStatus` (1..6): el COMMENT del schema (`db-schema.sql:1977`)
 *    dice que 6 es "Otro", pero el código vivo lo usa como ANULADO
 *    (`CreditPaymentService.php:370-372`). Si el comentario está mal en un
 *    valor, no se puede confiar en los otros cinco.
 *  - `taxName`: es texto libre del tenant y llega con valores como `"10"`.
 *  - `quoteStatus`: ya viene en castellano y confirmado
 *    (`TransactionsService.php:376-398`).
 *  - `itemType` en `'compound' | 'precombo' | 'comboAddons'`: se leen en los
 *    reportes pero no están en `ItemKind::MAP` y no se encontró quién los
 *    escribe.
 *  - `einvoiceStatus`, `sifen_status`: vocabulario de SIFEN, fuera del alcance
 *    de este slice.
 */
export const LEFT_RAW_ON_PURPOSE = [
  "transactionStatus",
  "taxName",
  "quoteStatus",
  "einvoiceStatus",
] as const

/**
 * Campos ADITIVOS: los únicos que el motor de comparación
 * (`period-comparison.ts`) puede sumar entre filas y comparar entre períodos.
 *
 * Es una ALLOWLIST y no una denylist a propósito. Comparar cualquier número que
 * aparezca produciría totales que no significan nada —la suma de los precios de
 * lista del catálogo, un costo unitario promedio acumulado— y el problema de un
 * número así no es que se vea raro: se ve perfectamente razonable. El modelo lo
 * presenta con confianza y el dueño del comercio no tiene cómo detectarlo. Un
 * campo que nadie declaró aditivo simplemente no se compara, y esa ausencia es
 * el resultado correcto.
 *
 * Los nombres son los de DESPUÉS de normalizar (`unitsSold`, no `usold`): la
 * comparación se arma sobre el payload ya traducido, así el bloque `comparison`
 * usa el mismo vocabulario que `data`.
 *
 * ── Lo que quedó AFUERA, y por qué ─────────────────────────────────────────
 *  - `price`, `cost`, `averageUnitCost`: son por unidad. Sumarlos entre filas no
 *    da un total de nada.
 *  - `onHand`, `balance`, `totalBalance`, `storeCredit`, `creditLine`: son FOTOS
 *    del momento, no flujos del período. No cambian con el rango de fechas, así
 *    que un delta entre períodos sería siempre cero o siempre engañoso.
 *  - `averageTicket`, `marginPercent`: promedios y porcentajes. Ver
 *    `PERIOD_SCALARS` abajo — sí se comparan, pero nunca se suman.
 *  - `itemMinStock`: es una constante del ítem repetida en cada depósito.
 */
export const ADDITIVE_FIELDS: ReadonlySet<string> = new Set([
  // Montos de venta y documento
  "total",
  "subtotal",
  "tax",
  "discount",
  "taxableAmount",
  "outstandingBalance",
  // Resumen anual
  "salesTotalBeforeDiscount",
  "purchasesTotal",
  "returnsTotal",
  "nonRevenueSalesTotal",
  "newCustomers",
  // Costos y margen (montos, no porcentajes)
  "costOfGoodsSold",
  "salesCommission",
  "grossProfit",
  "netResult",
  // Unidades y conteos
  "unitsSold",
  "salesCount",
  "qty",
  // Finanzas: flujos del período (NO `totalBalance`, que es una foto)
  "amount",
  "totalIncome",
  "totalExpense",
  "netFlow",
])

/**
 * Campos que SE COMPARAN entre períodos pero NUNCA se suman entre filas.
 *
 * "Sumable" y "comparable" no son lo mismo, y confundirlos deja afuera
 * justamente las preguntas que motivan una comparación. El ticket promedio es
 * el caso: sumarlo entre filas no significa nada, pero "¿mi ticket promedio
 * subió contra el año pasado?" es la pregunta central de un análisis de ventas.
 * Lo mismo el margen.
 *
 * La diferencia con `ADDITIVE_FIELDS` es de DÓNDE sale el número. Estos solo se
 * toman cuando el backend ya los calculó para el período entero y llegan como
 * escalar propio del payload (el widget de KPIs). Nunca se acumulan sobre filas:
 * el promedio de dos meses no es la suma ni el promedio de los dos promedios.
 *
 * `totalBalance` NO entra, aunque también sea un escalar propio: es el saldo de
 * AHORA y no se mueve con el rango consultado, así que compararlo entre dos
 * períodos devolvería dos veces el mismo número con un delta de cero — y el
 * modelo lo leería como "el saldo no cambió", que es una afirmación sobre el
 * negocio y no sobre el dato.
 */
export const PERIOD_SCALARS: ReadonlySet<string> = new Set(["averageTicket", "marginPercent"])
