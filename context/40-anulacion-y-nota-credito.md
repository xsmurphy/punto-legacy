# 40 — Anulación y Nota de Crédito

> Estado: **F1+F2 implementadas (2026-08-21)** para la anulación de VENTAS
> (contado/crédito, type 0/3) — ver sección "Implementación (F1+F2)" al
> final. **D2+D3 implementadas en la DEVOLUCIÓN (2026-08-21)** — ver sección
> "Implementación de D2/D3 en la devolución" al final: `ReturnService`
> reparte stock según la misma política que la anulación (`StockReversalPolicy`,
> wrapper compartido) y respeta `settingReturnRefund`. **F3/F4/F6 (numeración
> de NC como doctype propio con timbrado independiente del correlativo
> INTERNO, nota de crédito como documento propio distinto de `transactionType=6`,
> UI en `/pos`) siguen sin implementar** — la devolución YA es, en los
> hechos, la "nota de crédito interna" que F4 describía (documento con su
> propio correlativo `nota_credito`/scope outlet desde antes de esta sesión,
> D1 parcial-por-ítem ya soportado), así que lo que falta de F3/F4 es más
> acotado de lo que el plan original sugería — ver el detalle en esa sección.
> **F5 (NC electrónica) — VERIFICADA end-to-end esta sesión, funciona**: ver
> esa sección. Pedido del owner desde `/pos` → detalle de transacción: los
> botones "Anular" y "Devolución" siguen deshabilitados en la UI hasta que el
> agente de frontend cablee el contrato de esta sesión.
>
> **Anulación de RECIBOS DE PAGO/COBRO (type=5) — implementada 2026-08-16.**
> Ver sección al final de este doc. Es un feature DISTINTO del de arriba (no
> anula una venta/factura, anula un recibo que pagó una) — comparte el
> criterio de "correlativo conservado" pero no las fases F1-F6.

## Qué pidió el owner, textual

- **Anulación**: cancela la factura. El número de factura **NO se libera** —
  queda usado. La venta anulada **no suma al total vendido**.
- **Devolución**: es una **nota de crédito**.
- Si el tenant tiene **facturación electrónica**, las dos operaciones tienen que
  estar integradas con la cancelación y la NC de la integración.

## Lo que YA existe (no rehacer)

Relevado antes de planificar — la base está mucho más armada de lo que parece
desde la UI:

| Pieza | Estado |
|---|---|
| `EInvoiceService::cancel($companyId, $docId, $reason)` | **Implementado y completo**: valida que el doc esté `issued`, exige motivo, manda el evento a SIFEN vía Factomate y marca `einvoice_document.status='cancelled'`. **Sin ningún caller.** |
| `SaleToInvoiceMapper` con `documentType=5` + `associatedCdc` | **Implementado**: sabe armar el payload de una nota de crédito electrónica asociada al CDC de la factura corregida. |
| `SaleType::Return = 6`, `SaleType::Canceled = 7` | Declarados en el enum. Nada los emite. |
| `transaction_link` con `kind='return'` | Tabla y tipo de vínculo listos (mig 115). |
| `document_sequence` + `DocumentNumber::allocate` | Listos (context/37). Falta el doctype `nota_credito` y su rango de timbrado. |

O sea: falta **la capa de negocio**, no la de integración.

## Lo que falta

1. **Anulación** — marcar la venta como anulada sin perder que existió, revertir
   stock, revertir el movimiento financiero, y disparar la cancelación
   electrónica si el tenant la tiene.
2. **Nota de crédito** — documento NUEVO, con su propia numeración y su propio
   timbrado, vinculado a la factura original, que devuelve stock y plata.
3. **Numeración** — `doctype = 'nota_credito'` scope `register` (F5 de
   context/37), con rango de timbrado propio: en PY la NC lleva timbrado
   separado de la factura.
4. **Reportes / rollups** — excluir lo anulado del total vendido, y restar las
   NC. Toca las tablas de rollup (context/18).
5. **UI** — habilitar los dos botones del detalle de transacción en `/pos`.

## Decisión de diseño ya tomada (no es opinable)

**La anulación NO cambia `transactionType` a 7.** Se agrega estado
(`voidedAt`, `voidReason`, `voidedBy`) sobre la venta original.

Por qué: el `transactionType` es lo que determina el tipo de documento y la
numeración (`SaleType` es la fuente de verdad, context/37). Pisarlo con 7
convertiría una factura emitida en "otra cosa", y el número de factura quedaría
colgado de una fila que ya no dice ser una factura — justo lo contrario de lo
que pidió el owner ("el número queda usado"). Con un flag, la factura sigue
siendo esa factura, con su número y su timbrado, marcada como anulada.

`SaleType::Canceled = 7` queda como está: declarado y sin uso.

## Decisiones (todas cerradas)

- **D1 — ¿La NC puede ser parcial?** CERRADA (owner, 2026-08-14): **parcial por
  ítem**. Se eligen qué ítems y cuántas unidades se devuelven, la NC lleva su
  propio detalle y se pueden emitir varias contra la misma factura hasta
  cubrirla. Consecuencias de modelo, a respetar desde el arranque:
  - la NC necesita detalle propio (`itemSold` de la NC), no derivado;
  - hay que acumular lo ya devuelto por factura para no permitir devolver más
    de lo vendido — el guard va contra la SUMA de las NC previas, no contra la
    última;
  - la relación factura→NC es 1:N (`transaction_link` ya lo soporta: su unique
    es por `(companyid, originid, derivedid, kind)` y cada NC es un `derivedid`
    distinto).
- **D2 — ¿La mercadería devuelta vuelve al stock?** CERRADA y REVISADA (owner,
  2026-08-14). La primera respuesta fue "lo elige el cajero por ítem", pero el
  owner corrigió con un caso que rompe esa simplificación: una hamburguesa
  preparada que se devuelve NO puede volver al stock — los insumos ya se
  consumieron y no se des-preparan. Y sumó el criterio de fondo: **estas no son
  decisiones nuestras, son reglas del comercio.**

  Quedan separadas TRES cosas que se estaban mezclando:

  **a) Qué es POSIBLE — lo determina el sistema, no es opinable.** Depende de
  cómo el ítem descuenta stock al venderse:

  | Tipo de ítem | Al vender descontó | Se puede reponer |
  |---|---|---|
  | Con stock propio | ese mismo ítem | **Sí** — vuelve a su saldo |
  | Producción previa | el terminado, que tiene stock propio | **Sí** — vuelve el terminado |
  | Producción directa (receta al vender) | los INSUMOS de la receta | **No como ítem**: no tiene saldo propio. Solo cabe reponer los insumos, y únicamente si nunca se preparó |
  | Combo | lo que explotó su receta, en todos sus niveles | Igual que producción directa |
  | Servicio / sin stock | nada | **No** — no hay nada que reponer |

  La UI solo puede OFRECER lo que esta tabla habilita. Ofrecer "devolver al
  stock" en una hamburguesa preparada es ofrecer algo que el sistema no puede
  hacer bien.

  **b) Qué decide el CAJERO — SIEMPRE, línea por línea** (revisión final del
  owner, 2026-08-14: "solo el operador conoce si realmente aplica la devolución
  al stock"). No hay política de tenant para esto: un ítem CON stock propio
  también puede volver roto, vencido o consumido a medias, y eso solo lo ve
  quien tiene el producto en la mano. La decisión es del cajero en cada línea,
  dentro de lo que (a) habilita — la tabla de arriba define qué opciones se
  OFRECEN, el cajero elige entre ellas. Default visual del toggle: "repone" para
  ítems con stock propio, "a pérdida" para producción directa/combos.
  - Se elimina `settingReturnRestock` del diseño (estaba propuesto, no
    implementado). Queda solo `settingReturnAllowIngredientReversal` (bool,
    default `false`): habilita OFRECER la reposición de INSUMOS de una
    producción directa que no llegó a prepararse. Es un capability switch —
    amplía el menú de lo posible — no una política que decida por el cajero.
    Apagado por defecto: reponer insumos ya consumidos infla el inventario.

  Lo que NO vuelve al stock no desaparece: genera su `waste_event` con el costo,
  para que la pérdida quede registrada en vez de evaporarse del inventario.
  Reusa el módulo de merma existente (correlativo desde la mig 129) con un
  `wasteReason` sembrado tipo "Devolución de cliente".

- **D3 — ¿La NC devuelve dinero o deja saldo a favor?** CERRADA y REVISADA
  (owner, 2026-08-14). Se implementan las dos salidas, pero por el mismo
  criterio de D2, el cajero elige en cada devolución entre salida de caja y
  saldo a favor. `settingReturnRefund` (`cash` | `credit` | `ask`, default
  `ask`) existe para el comercio que SÍ quiera fijar una política única; con
  `ask` —el default— la pantalla pregunta siempre.
  - **Salida de caja** → `fin_movement` (`kind='expense'`) contra la caja y el
    turno ABIERTOS al momento de la devolución, NO contra los de la venta
    original. Resuelve solo el caso "la venta fue en otro turno o en otra
    sucursal": la plata sale de donde efectivamente se entrega. El arqueo de ese
    turno tiene que mostrarla, si no el cajero cierra con diferencia.
  - **Saldo a favor** → acredita `contact.contactStoreCredit`. La columna YA
    existe y está VIVA: `SaleService` la acredita con los ítems `inCredit` y
    `Customer` la debita al usarla, así que la NC solo suma un origen más al
    mismo mecanismo — no hay cuenta corriente que inventar.
  - Saldo a favor exige cliente identificado. Si la venta fue sin cliente, esa
    opción no se ofrece aunque la política diga `credit`: no hay a quién
    acreditarle. Ahí se cae a salida de caja.

- **D4 — ¿Hasta cuándo se puede anular?** CERRADA (owner, 2026-08-14): **48
  horas desde la emisión**, y el corte se aplica en LOS DOS lados.
  - El botón "Anular" se deshabilita en la UI pasado el plazo, con el motivo a
    la vista y ofreciendo "Devolución" en su lugar.
  - El endpoint RECHAZA la anulación pasado el plazo, aunque el request llegue
    igual. El guard de UI es comodidad; el que manda es el del servidor —
    deshabilitar un botón no es un control de acceso, y este es un límite
    fiscal, no una preferencia de interfaz.
  - El plazo se cuenta desde la **fecha de emisión de la factura**
    (`transactionDate`), no desde el último cambio ni desde el momento del
    pedido. Es la fecha que mira SIFEN.
  - **Aplica a todos los tenants, tengan o no facturación electrónica**
    (asunción declarada, no preguntada de nuevo). El owner respondió el plazo
    sin distinguir, y un documento fiscal no deja de serlo porque no se
    transmita: permitir anular una factura en papel un mes después es peor para
    la auditoría que el caso con FE, donde al menos SIFEN lo rechazaría. Si más
    adelante se quiere relajar para tenants sin FE, es un flag, no un rediseño.
  - Pasado el plazo el camino correcto es la **nota de crédito**, que no tiene
    límite de tiempo.

## Fases propuestas

- **F1** — Anulación interna: estado sobre la venta, reverso de stock, reverso
  del movimiento financiero, exclusión de reportes. Sin FE.
- **F2** — Anulación integrada con FE: dispara `EInvoiceService::cancel()`
  cuando el tenant la tiene. El corte de 48 h (D4) va en F1, no acá: es un
  límite del documento, no de la integración, y tiene que valer también para
  quien no emite electrónicamente.
- **F3** — Numeración de NC: doctype `nota_credito`, rango de timbrado propio
  por caja, UI en el tab Cajas.
- **F4** — Nota de crédito interna: documento, detalle, vínculo con la original,
  reverso de stock y plata. D1, D2 y D3 cerradas — no depende de nada.
- **F5** — NC electrónica: emisión con `documentType=5` + `associatedCdc`,
  reusando el mapper que ya existe.
- **F6** — UI en `/pos`: habilitar los botones con sus confirmaciones.

## Notas

- La anulación y la NC son cosas DISTINTAS y no intercambiables: anular borra el
  hecho económico (la venta no ocurrió), la NC lo corrige (ocurrió y se
  devuelve). Mezclarlas es el error clásico — por eso el owner las pidió
  separadas.
- Todo lo que revierta stock tiene que pasar por `Inventory::manageStock` y
  respetar la explosión recursiva de recetas: anular la venta de un combo tiene
  que devolver los insumos de TODOS sus niveles, no solo el primero.
- **Criterio general, del owner (2026-08-14, afinado en dos pasadas):** el
  sistema decide qué es técnicamente POSIBLE; el CAJERO decide, dentro de eso,
  lo que depende del estado físico de la mercadería (¿vuelve al stock?), porque
  solo él lo ve; y el COMERCIO puede fijar por configuración lo que es política
  comercial (¿plata o saldo a favor?), con `ask` como default. Tres niveles,
  tres dueños distintos.
- La ANULACIÓN usa las mismas reglas de reposición que la NC. El caso típico
  —anular a los dos minutos, antes de preparar— es justamente donde reponer
  insumos de una producción directa SÍ corresponde, y es la razón de que
  `settingReturnAllowIngredientReversal` exista en vez de prohibirlo siempre.

---

## Implementación (F1+F2) — 2026-08-21

`SaleVoidService` (`api/lib/services/SaleVoidService.php`) — servicio nuevo,
namespace `Punto\Api\Services`. Implementa D1-D4 tal como quedaron cerradas
arriba: NO pisa `transactionType`, agrega `voidedAt`/`voidReason`/`voidedBy`
(mig `154_sale_void.sql`, columnas lowercase — `transaction` es tabla legacy,
§44 de `context/08-convenciones-criticas.md`). También marca
`transactionComplete = TRUE` al anular una venta a crédito: la deuda
desaparece, y con eso `OpenInvoicesService` deja de listarla sola (filtra
`transactionComplete = false`), sin tocar ese servicio.

- **`void()`**: lockea la venta (`FOR UPDATE`), rechaza si ya está anulada
  (409 `ALREADY_VOIDED`), si pasaron las 48h desde `transactionDate` (422
  `VOID_WINDOW_EXPIRED`, D4), si tiene devoluciones vigentes vinculadas
  (`transaction_link kind='return'`, 409 `HAS_RETURNS`) o recibos de cobro
  vigentes (`kind='credit_payment'`, 409 `HAS_PAYMENTS` — hay que anularlos
  primero, `CreditPaymentService::void()`).
- **Reposición de stock (D2)**: `voidOptions()` clasifica cada línea contra
  la tabla D2 (`ownStock`/`ingredientReversal`/`service`) usando
  `Inventory::explodeRecipe()` (explosión MULTI-NIVEL real — no el nivel
  único que usa el legacy `TransactionService::voidTransaction()`, mejora
  intencional para combos de varios niveles). El cajero decide por línea
  (`$lines`), clampeado a lo que `canRestock` habilita. Lo que no se repone
  y tuvo impacto real de stock genera `waste_event` (motivo "Devolución de
  cliente", get-or-create lazy en `taxonomy`, mismo criterio que
  `WasteReasonService::ensureSeed()`).
- **`settingReturnAllowIngredientReversal`**: vive en `company.config` JSONB,
  sin DDL propia (mismo patrón que `settingSellSoldOut`). Se lee directo con
  `config->>'settingReturnAllowIngredientReversal' = 'yes'`.
- **Pagos**: `TransactionService::restorePaymentBalances()` — lógica de
  restore de points/storeCredit/giftcard EXTRAÍDA de `voidTransaction()` a un
  método estático compartido (antes vivía inline ahí, ahora la reusan los dos
  callers).
- **Caja**: `FinanceLedger::voidBySource($companyId,'sale',$transactionId)`
  DENTRO de la misma transacción de BD — si falla, la anulación entera
  revierte (no es best-effort como el endpoint legacy, que probaba 4 sources
  a ciegas porque no sabía el tipo real; acá se sabe con certeza).
- **F2 (FE)**: si hay `einvoice_document` `issued` (doctype `FC`/`FCR` según
  contado/crédito) para la venta, `EInvoiceService::cancel()` corre DENTRO de
  la misma transacción — si SIFEN rechaza, todo (voidedAt, stock, pagos,
  caja) hace rollback. Es la única pieza no best-effort del flujo.

**Endpoints**:
- POS: `api/v1/sales-void.php` — `GET ?id=` → `canVoid()` + `voidOptions()`;
  `POST {id, reason, lines}` → `void()`. Auth `apiAuthTenant(['panel',
  'pos-app'])` (mismo realm que `returns.php`, la acción hermana del mismo
  detalle de transacción — NO `apiAuthPosContext()`/Bearer pese a lo que
  decía el brief original de la tarea, corregido contra el código real de
  `returns.php`). Gatea `pos.sale.void` (permiso ya existente, ya sembrado en
  owner/manager).
- Panel: `PUT /v1/transactions?resource=void` — venta contado/crédito (type
  0/3) delega a `SaleVoidService::void()` con `lines=[]` (defaults);
  cualquier otro tipo sigue el camino legacy (`voidTransaction()`, type→7).
  De paso se cerró un gap encontrado auditando este endpoint: nunca
  chequeaba `pos.sale.void` (cualquier device autenticado podía anular) —
  ahora sí, para las dos ramas.

**Reportes (exclusión de anuladas)**: `Reports\SaleFilters::notVoidedSql()`
— helper nuevo, agrega `AND voidedAt IS NULL` a cualquier WHERE que ya
filtre `transactionType IN (0,3,...)`. Aplicado inicialmente a los
call-sites de mayor materialidad (dashboard "total vendido", RG90/Libro
Ventas, reporte de Ventas): `DashboardService` (7 sitios),
`FiscalService::loadSales()` (RG90), `SalesService::salesTotals/series/
hours/byDay` (Reports, 4 sitios).

**F4 (2026-08-21) — cobertura completa.** Pasada de seguimiento que
terminó de aplicar el helper al resto de los call-sites que suman/cuentan
`transaction` filtrando por tipo de venta:

- `Reports\CustomersService::ranking()`
- `Reports\CategoriesService::salesByCategoryLive()`
- `Reports\BrandsService::salesByBrandLive()`
- `Reports\ProductionService::general()/detail()` (línea de producción
  directa vendida)
- `Reports\ProductsService::aggregate()/detail()` (las 10 variantes de
  filtro: cliente/usuario/ítem/mes/rango)
- `Reports\UsersService::salesByUser()`
- `Reports\PaymentMethodsService::report()`
- `Reports\CashflowService::periodTotals()` (rama `transactionType IN
  (0,6)`; `receivedPayments()`/`purchaseLines()` no tocadas — no son venta
  contado/crédito directa)
- `Reports\SummaryYearService::yearlyLive()`
- `Reports\NonAddingSales::salesByPayment()/lessInternalTotals()` — helper
  compartido: al excluir la venta anulada acá, el "no suma a ventas"
  (gift card/store credit/puntos/internas) tampoco la cuenta, evitando
  doble descuento sobre un total que ya la excluye.
- `Reports\SalesService::salesByType()` — gap real: tenía `transactionType
  = ?` sin el filtro pese a que sus hermanas (`salesTotals`, `series`,
  etc.) ya lo tenían desde F1.
- `Contacts\ContactAnalyticsService::compute()` — las 8 queries agregadas
  del tab "Comportamiento" (`totals`, `topItems`, `topCategories`,
  `paymentMix`, `byHour`, `byDayOfWeek`, `byMonth`, `byOutlet`); el filtro
  se aplica siempre (también para `type=2` proveedor/`COMPRA_TYPES`) porque
  `voidedAt` solo lo setea `SaleVoidService` sobre filas venta contado/
  crédito — en filas de compra siempre es `NULL`, así que no afecta ese
  camino.

**Rollups (F4, mig `155_rollup_exclude_voided_sales.sql`)**:
`rollup_recompute_period()` (PL/pgSQL, `report_rollup`) recreada completa
— `AND voidedat IS NULL` en las 9 ramas derivadas de venta contado/crédito
(day/month/año × dominios `sales`, `item_sales`, `payments`; en `payments`
alcanza también a las filas `transactionType=5`, sin efecto porque
`voidedAt` nunca se setea ahí). `SaleVoidService::void()` llama
`rollupMarkDirty($companyId, ['sales','item_sales','payments'], $txDate)`
post-commit (mismos dominios que `SaleService::save()` usa para una venta
contado/crédito) para que `rollup_reconcile()` recalcule los buckets
afectados con la función ya corregida.

**NO tocados, y por qué**:
- `Reports\TransactionsService` / `TransactionDetailService` — listado/
  detalle general, muestran la venta anulada A PROPÓSITO (auditoría, mismo
  principio que la anulación de recibos: "desaparecer es indistinguible de
  se borró").
- `services/DrawerService` + `Reports\DrawersService` — reconciliación de
  turno/caja, no reporte de ventas; ¿una venta anulada a mitad de turno
  sigue en el cierre de ESE turno? pregunta de producto sin cerrar
  (anotado inline en ambos archivos).
- `services/ReturnService` — devoluciones; interacción anulación↔devolución
  fuera de alcance de F4 (además `SaleVoidService::void()` ya rechaza
  anular una venta con devoluciones vigentes, `HAS_RETURNS`).
- `Reports\ScheduleService` — agenda/turnos programados (type=9), no venta.
- `Admin/TenantHealthService::fetchActivity()` — señal de ACTIVIDAD del
  tenant para `/admin` (¿sigue operando la caja?), no de revenue; una venta
  anulada igual demuestra uso del POS (anotado inline).
- `PurchasesService` y el lado-compra de `ProductsService`/`ProductionService`
  (`transactionType IN (1,4)`/`= 1`) — no son venta, `voidedAt` no aplica.
- `Reports\CashflowService::receivedPayments()` — resuelve por
  `transaction_link`/origen, no filtra `transaction` directo por tipo de
  venta; el caso "venta voided con pago vigente" ya está bloqueado por
  `HAS_PAYMENTS` en `SaleVoidService::void()`.

`Transactions\TransactionDetailService::find()` (resolver canónico,
context/39) expone `voidedAt`/`voidReason`/`voidedBy`/`voidedByName`; `void`
ahora es `type===7 || voidedAt !== null` (antes solo miraba `type===7`).

**Test**: `api/tests/sale_void_test.php` + `run_sale_void_test.sh` (+ helper
`_sale_void_once_cli.php` para los casos que esperan `apiError`/`exit`),
mismo patrón que `credit_payment_void_test.php`. Cubre: reposición mixta
(stock propio repuesto, insumos de producción directa NO repuestos por
default + `waste_event`, ítem de servicio sin efecto), idempotencia (409),
ventana de 48h (422), `HAS_RETURNS` (409), exclusión en
`SalesService::salesTotals()`, (F4) que `rollup_recompute_period(company,
'sales', day)` recalculado tras anular no incluye la venta anulada — su
cnt/total consolidado del día coincide con la misma query en vivo filtrada
por `voidedAt IS NULL` — y (g) el guard de línea ambigua descrito abajo.
**No cubre F2** (cancelación FE): requeriría provisionar Factomate contra el
tenant fixture, fuera de alcance de un arnés "sin red" como `verify_chain`.

### Fixes de code review sobre F1+F2 (2026-08-21, mismo día)

Un code-reviewer externo auditó el commit `5a14447f` (F1+F2) y encontró 4
hallazgos, corregidos en la misma sesión que F4:

- **P0 — auditoría faltante en ventas anuladas type 0/3.** Ni
  `SaleVoidService::void()` ni sus dos callers (`sales-void.php` del POS,
  `transactions.php?resource=void` del panel) llamaban `sendAuditoria()` —
  la rama LEGACY de `transactions.php` (tipos que NO son 0/3,
  `voidTransaction()`) sí la llama, pero esa rama es inalcanzable para 0/3
  (el branch de `SaleVoidService` hace `apiOk()` — `: never` — antes de
  llegar ahí, así que no había duplicación que remover, solo el gap).
  Solución: `SaleVoidService::sendVoidAudit()` (privado), llamado una sola
  vez post-commit best-effort (mismo `try/catch` que `realtimePublish`/
  `rollupMarkDirty`), mismo shape de payload (`module='FACTURACION'`,
  `origin='CAJA'`) que `SaleService::sendAudit()` (creación) y que la rama
  legacy de `transactions.php` — cubre los dos endpoints de una vez.
- **P1 — `SalesService::salesByType()` y `NonAddingSales::salesByPayment()`
  sin el filtro.** Ya estaban corregidos por el barrido de F4 de este mismo
  commit (ver lista de call-sites arriba) — confirmado, no hizo falta un
  cambio adicional.
- **P2 — docblock de `FiscalService.php:33-41` desactualizado** (describía
  la exclusión de `voidedAt` como pendiente). Ya actualizado por F4 — el
  docblock ahora dice que `loadSales()` ya filtra con `SaleFilters::
  notVoidedSql()`.
- **P2 — `SaleVoidService::resolveLineDecisions()` contagiaba la decisión
  entre líneas del mismo `itemId`.** Si el request mandaba una línea
  identificada solo por `itemId` (sin `itemSoldId`) y la venta tenía 2+
  líneas de ese ítem, la misma decisión de reposición se aplicaba a TODAS
  — un cajero que quería reponer una sola línea terminaba reponiendo (o no
  reponiendo) las demás también. Fix: resuelve SIEMPRE por `itemSoldId`
  primero; el fallback por `itemId` solo se acepta cuando ese `itemId` es
  único entre las líneas de la venta — si no, tira
  `AmbiguousVoidLineException` (clase nueva, `api/lib/services/
  AmbiguousVoidLineException.php`), catcheada en `void()` como 422 con
  rollback limpio (`FailTrans()`/`CompleteTrans()`) — NO un `apiError()`
  directo, que hubiera saltado el rollback porque `resolveLineDecisions()`
  corre DENTRO de la transacción de BD, después del `UPDATE` que marca
  `voidedAt`.

---

## Anulación de recibos de pago/cobro (type=5) — IMPLEMENTADA (2026-08-16)

Feature distinto del de arriba: no anula una VENTA, anula un RECIBO (cobro a
cliente `kind='credit_payment'` o pago a proveedor `kind='purchase_payment'`,
`CreditPaymentService`, F5.1 de context/41) registrado desde
`/reports/open-invoices` o el detalle de contacto. Pedido textual del owner:
*"Se tiene que poder eliminar el pago. ¿Eso queda registrado como un recibo de
dinero en compras a crédito? Sí o sí se tiene que poder eliminar un pago mal
cargado."*

**Decisión del owner, textual (2026-08-16):** *"Está bien anular y que el
número no se pueda reusar, queda anulado para auditoría."* — confirma:
- El recibo anulado **conserva su correlativo** (`invoiceNo`). No se borra la
  fila, no se libera el número, no se reasigna a otro documento.
- Queda **visible como anulado** en el detalle del contacto y en el listado
  de transacciones — no desaparece. Desaparecer es indistinguible de "se
  borró" y hace la auditoría imposible.
- **No se puede anular dos veces**, ni operar sobre un recibo ya anulado.

### Criterio de diseño: `transactionStatus=6`, NO `transactionType=7`

A diferencia de la anulación de VENTA (F1-F6 arriba, que decidió NO tocar
`transactionType` sino agregar un flag), la anulación de un RECIBO usa el
patrón **soft-void que YA existía** para compras (`PurchasesService::void()`)
y notas de crédito de compra (`PurchaseCreditNoteService::void()`):
`UPDATE transaction SET transactionStatus = 6`. Por qué:

- `TransactionLinkService::sumDerivedAmounts()` / `mapSumDerivedAmounts()` YA
  excluyen derivados con `COALESCE(transactionStatus, 1) <> 6` — es el
  criterio de exclusión que compras/NC ya usan. Marcar el recibo con ESE
  mismo status hace que la deuda de las facturas que pagó se recalcule sola,
  sin tocar `TransactionLinkService` (superficie compartida con devoluciones
  y NC de compra).
- `transactionType=7` es el vocabulario reservado para "esto ya no es una
  factura, es una anulación" (ventas). Un recibo nunca fue una factura —
  pisarle el tipo no aporta nada y hubiera exigido enseñarle ESE nuevo
  criterio a `sumDerivedAmounts()`.

Implementado en `CreditPaymentService::void()`
(`api/lib/services/CreditPaymentService.php`): lockea el recibo + TODAS las
facturas que pagó (mismo orden `transactionId ASC FOR UPDATE` que
`create()`/`createDistributed()` — evita deadlock con un cobro concurrente),
marca `transactionStatus=6`, y recalcula `transactionComplete` de cada
factura desde CERO (no asume "vuelve a impaga" — puede tener OTROS recibos
vigentes). El movimiento de caja se revierte con
`FinanceLedger::voidBySource($companyId, $kind, $paymentId)` (mismo método
que ya usa `purchases.php` para `PurchasesService::void()`).

Endpoint: `DELETE /v1/credit-payments?id=<uuid>`
(`api/v1/credit-payments.php`). Permiso: `pos.sale.void` (cobro a cliente) |
`finance.manage` (pago a proveedor) — ninguna clave nueva, las dos ya existen
en `PermissionCatalog` y ya están sembradas en manager/owner (no en cashier).

UI: botón "Anular cobro"/"Anular pago" con `AlertDialog` de confirmación en
`AccountStatementSection` (tabla "Cobros/Pagos aplicados", visible en
`/reports/open-invoices` y el tab Financiero del contacto) y en
`/transactions/{id}` (detalle del recibo — necesario porque
`OpenInvoicesService::contactStatement()` solo lista facturas ABIERTAS: un
recibo que saldó una factura del todo deja de aparecer ahí, así que la única
forma de anularlo es desde su propio detalle).

Bug latente encontrado auditando la exclusión de anulados (relevado ANTES de
tocar `TransactionLinkService`, per protocolo): `DashboardService::
payedByParent()` arma su propia query cruda (no pasa por
`sumDerivedAmounts()`) y NO filtraba `transactionStatus<>6` — un recibo o
devolución anulado seguía sumando "Cobrado" en el dashboard para siempre.
Fixeado con el mismo criterio `COALESCE(transactionStatus, 1) <> 6`.

Bug NO relacionado con anulación, encontrado en el camino (mismo método que
se tocó para agregar visibilidad de recibos anulados):
`OpenInvoicesService::payedByParent()`/`contactStatement()` hardcodeaban
`kind='credit_payment'` sin importar `$isCustomer` — para PROVEEDORES
(`purchase_payment`, generalizado 2026-08) el saldo mostrado en "Cuentas por
pagar" no bajaba con pagos parciales (solo al saldar la factura del todo, vía
`transactionComplete`). Fixeado pasando `$isCustomer`/kind correcto en los
3 call-sites (`general()`, `forContact()`, `contactStatement()`).

Test de integración (DB real, Postgres vía Docker): `api/tests/
credit_payment_void_test.php` + wrapper `api/tests/
run_credit_payment_void_test.sh` (mismo patrón que
`api/lib/Sales/verify_chain/run.sh` — reusa su `seed.sql`). Cubre: recibo que
pagó 3 facturas → al anular, las 3 vuelven al saldo original; factura con DOS
pagos, se anula uno → queda con el saldo del otro; el documento anulado deja
de sumar en `sumDerivedAmounts`; anular un recibo ya anulado se rechaza.

---

## Implementación de D2/D3 en la devolución (2026-08-21)

`ReturnService::create()` (`api/lib/services/ReturnService.php`) reponía
stock SIEMPRE que el ítem tuviera `itemTrackInventory`, sin preguntar al
cajero y sin generar merma — violaba D2 (una hamburguesa preparada volvía al
stock igual) y no exponía D3 (`refundMode` era libre, sin política de
tenant). Las dos se cerraron en esta sesión, reusando el trabajo de F1+F2.

**`StockReversalPolicy`** (`api/lib/services/StockReversalPolicy.php`,
namespace `Punto\Api\Services`) — wrapper COMPARTIDO extraído de
`SaleVoidService`, regla del proyecto de atacar el wrapper en vez de
duplicar el call-site. API pública:

- `settingAllowIngredientReversal(companyId): bool`
- `classifyLine(fila, companyId, allowIngredientReversal): array{itemSoldId,itemId,name,qty,unitPrice,unitCogs,kind,canRestock,defaultRestock,hadStockImpact}`
  — tabla D2 completa (`ownStock`/`ingredientReversal`/`service`).
- `resolveLineDecisions(options, requestedLines): array` — decisión del
  cajero por línea, clamp a `canRestock`, guard de ambigüedad
  (`itemId` repetido sin `itemSoldId` → `AmbiguousStockLineException`, 422).
- `restockLine(d, companyId, outletId, transactionId, userId, source): void`
  — `source` distingue el ledger de stock (`'void'` | `'return'`).
- `recordWaste(d, companyId, outletId, wasteReasonId, userId, note): void`
  — `note` completo lo arma cada caller ("Anulación de venta: …" |
  "Devolución de cliente: …").
- `getOrCreateReturnWasteReasonId(companyId, db): string`.

`SaleVoidService` quedó SIN copias de estos métodos (delegó a
`$this->stockPolicy()`) — `sale_void_test.php` corrió sin cambios de
expectativas y pasó los 18 casos, incluida la excepción de ambigüedad
(renombrada `AmbiguousStockLineException`, movida a su propio archivo — el
test solo verifica texto/status, no el nombre de la clase).

**`ReturnService::create()` — request nuevo por línea**: `items: [{itemId,
qty, restock?: bool, itemSoldId?: string}]`. `restock` ausente = default de
`classifyLine()` para ese itemId; presente y `canRestock=false` → se
CLAMPEA en silencio (se ignora, no 422 — mismo criterio que
`SaleVoidService`, decisión de esta sesión: pedir algo que el sistema marcó
imposible no es un error de input, es una preferencia que no aplica).
`itemSoldId` no participa del cupo (ver abajo) — viaja solo por simetría de
contrato con `resolveLineDecisions()`.

Cambio de modelo importante: a diferencia de `SaleVoidService::voidOptions()`
(una fila por `itemSold` FÍSICO), `ReturnService` agrega las líneas de la
venta original POR itemId (`aggregatedParentLines()`) — porque el cupo
disponible (`alreadyReturned`) siempre fue itemId-level (una venta puede
tener el mismo ítem en 2+ filas de `itemSold` sin `mergeRepeated`, y el
`alreadyReturned` histórico se calculaba agregado). Clasificar por itemId
elimina de raíz la ambigüedad que sí existe en `SaleVoidService` — nunca hay
2 opciones con el mismo itemId en `ReturnService`, así que
`AmbiguousStockLineException` nunca dispara desde acá (invocado igual, por
consistencia de contrato).

**Bug latente pre-existente, corregido de paso**: si el mismo `itemId`
aparecía 2+ veces en el mismo request de devolución, cada línea validaba su
cupo contra `alreadyReturned` de devoluciones PREVIAS únicamente — dos
líneas del mismo itemId en un solo request podían pasar el guard cada una
por separado y devolver más de lo vendido en conjunto. Ahora
`create()` descuenta también lo ya comprometido por OTRA línea de ese mismo
request antes de validar cada una.

**`returnOptions(companyId, parentTransactionId): array`** — método público
nuevo, reemplaza el listado de ítems que el front armaba por su cuenta.
Shape EXACTO por línea (una por itemId de la venta original):

```
{itemSoldId, itemId, name, soldQty, alreadyReturned, availableQty,
 unitPrice, canRestock, defaultRestock, kind}
```

Expuesto en `GET /v1/returns.php?action=returnOptions&parentId=<uuid>` →
`{ ok, data: { lines: [...] } }`. El endpoint también acepta `restock`/
`itemSoldId` opcionales por ítem en el POST de `create` (validados: `restock`
booleano, `itemSoldId` UUID si viene).

**D3 — `settingReturnRefund`** (`cash` | `credit` | `ask`, default `ask`,
`company.config` JSONB, sin DDL — mismo patrón que
`settingReturnAllowIngredientReversal`). `ReturnService::create()` rechaza
(422, `InvalidArgumentException` con el nombre del setting en el mensaje) un
`refundMode` que no matchea una política fijada en `'cash'`/`'credit'`.
Regla ya vigente preservada: `credit` exige cliente en la venta original —
si la política FUERZA `'credit'` y la venta no tiene cliente, cae a `'cash'`
en vez de fallar (no hay a quién acreditarle, pero el comercio igual quiere
poder devolver la plata); si el cajero elige `'credit'` LIBREMENTE (política
`'ask'`) sin que la venta tenga cliente, sigue siendo un error de input
(sin cambios — la decisión de owner de D3 habla del caso "la política
fuerza", no del caso "el cajero elige mal").

**Expuesto al POS y al panel** (mismo camino que `settingSellSoldOut`
resultó NO llegar al bootstrap del POS — se comprobó que ese setting hoy
solo se lee/escribe en el módulo Settings del panel, sin ningún consumidor
en `/pos`; para que el POS pueda ofrecer/ocultar el toggle de `refundMode`
según la política, se agregó DIRECTO a `api/v1/bootstrap.php`, que sí es el
camino real que consume el POS — mismo patrón que `bancardQr`/`bancardPos`):

- `api/v1/bootstrap.php` — `settingReturnRefund` (`'cash'|'credit'|'ask'`) y
  `settingReturnAllowIngredientReversal` (bool) en el payload de `apiOk()`.
- `api/lib/Settings/SettingsService.php` (`general()`/`updateGeneral()`) y
  `api/v1/settings.php` (whitelist de campos) — editable desde el panel.
  Labels que le corresponde poner al front (no tocado en esta sesión, "no
  tocar frontend/"): "Devoluciones: forma de reintegro" con opciones
  "Preguntar siempre" / "Solo efectivo" / "Solo saldo a favor", y "Permitir
  reponer insumos de producción directa".

**Test**: `api/tests/return_d2_d3_test.php` + `run_return_d2_d3_test.sh`
(mismo patrón que `run_sale_void_test.sh`, reusa el fixture "Verify PY").
`ReturnService::create()` nunca llama `apiError()` (tira excepciones
catcheables) — no necesitó el patrón de subproceso que usa
`sale_void_test.php`. Cubre: (a) devolución de 2 líneas, una repuesta (stock
propio) y otra a merma (producción directa, default); (b) `restock=true`
pedido sobre una línea `canRestock=false` se ignora en silencio (decisión de
esta sesión: clamp, no 422 — documentada arriba); (c)
`settingReturnRefund='cash'` + request `'credit'` → rechazo; (d) política
`'credit'` forzada + venta sin cliente → cae a `'cash'`; (e)
`returnOptions()` refleja `alreadyReturned`/`availableQty` antes y después
de una devolución parcial; (f) invariante financiero — una devolución TOTAL
de una línea devuelve EXACTAMENTE lo que esa línea vendió, sin arrastre de
redondeo (se preservó a propósito el cálculo de `unitPrice`/`unitCogs` con
precisión completa para la plata, separado de la versión redondeada a 2/4
decimales que usa `classifyLine()` solo para mostrar en `returnOptions()`).

**Verificación en el servidor de Punto (167.71.165.221)** — Docker no
levanta en esta máquina, se corrió remoto con el patrón ya usado hoy para
F1+F2 (Postgres descartable + imagen `punto-php-test` ya existente en el
server). 153 migraciones aplicadas limpio, seed cargado. Resultado:

- `return_d2_d3_test.php` — 10/10 casos OK.
- `sale_void_test.php` — 18/18 casos OK, SIN cambios de expectativas (confirma
  que la extracción de `StockReversalPolicy` no rompió nada).
- `api/lib/Sales/verify_chain/run_sale_chain.php` (tenant PY) — TODOS los
  casos OK (venta multi-tasa, RG90, EInvoice con IVA congelado, aislamiento
  cross-tenant, venta a crédito de cliente sin crédito).
- `verify_return_numbering.php` — TODO OK, incluida la rama `alreadyReturned`
  (`$returnIds !== []`, devolución parcial sobre devolución parcial) y el
  rechazo de una tercera devolución que superaría lo vendido.
- `verify_pg_identifiers.php` — TODO OK, incluido el caso 1
  (`EInvoiceService::buildCreditNoteArrayForMapper()` arma la NC con
  `associatedCdc` correcto) — confirma F5 (ver abajo) sin tocar código de FE.

Limpieza post-test: `docker rm -f prt_pg` + `rm -rf /tmp/punto-ret-test` —
confirmado, sin containers de Punto huérfanos.

**Fix de code review sobre esta implementación (mismo día, antes de mergear
a `main`)**: un `code-reviewer` externo encontró un P1 en
`StockReversalPolicy::resolveLineDecisions()` — cuando el request de
`ReturnService::create()` manda un `itemSoldId` que NO coincide con el
`itemSoldId` representativo que arma `aggregatedParentLines()` (un
`MIN(itemsoldid)` arbitrario cuando la venta original tiene 2+ filas del
mismo itemId), la decisión de `restock` del cajero se perdía en silencio y
caía a `defaultRestock` — sin error, sin log, comportamiento sorpresivo.
Fix: `resolveLineDecisions()` ahora también registra el fallback por
`itemId` cuando ese itemId es único entre las opciones (siempre el caso en
`ReturnService`, que arma una sola opción por itemId) — la ambigüedad real
de `SaleVoidService` (2+ opciones del mismo itemId) sigue exigiendo
`itemSoldId` exacto, sin cambios ahí. Re-verificado en el mismo server:
`return_d2_d3_test.php` 10/10 y `sale_void_test.php` 18/18 (incluido el caso
(g) de ambigüedad) tras el fix, misma limpieza post-test.

## F5 (NC electrónica) — verificada end-to-end, sin gaps de alcance

Tarea C pedía verificar el camino completo, no solo su existencia. Se leyó
`EInvoiceService.php` (`enqueueForSale`, `parentInvoiceIsIssued`,
`buildCreditNoteArrayForMapper`, `stampForDocument`) y se corrió
`verify_pg_identifiers.php` caso 1 contra datos reales:

- **`parentInvoiceIsIssued($companyId, $transactionId)`** (`transactionId`
  = la DEVOLUCIÓN) resuelve el origen vía
  `TransactionLinkService::listOriginIds(..., 'return')` y exige un
  `einvoice_document` `status='issued'` con `cdc IS NOT NULL` de la venta
  padre — correcto, confirmado.
- **Factura padre NO emitida electrónicamente → la NC no se encola, y no
  rompe la devolución**: `enqueueForSale()` hace `return;` temprano si
  `parentInvoiceIsIssued()` es false; el caller (`ReturnService::create()`)
  ya envuelve la llamada en `try/catch` — confirmado por lectura, no hay
  código que pueda tirar la devolución por esto.
- **El CDC llega al payload**: `buildCreditNoteArrayForMapper()` resuelve
  `$parentCdc` con la MISMA query de `parentInvoiceIsIssued` (issued + cdc no
  nulo) y lo pone en `associatedCdc` del payload (`documentType=5`) —
  confirmado por `verify_pg_identifiers.php` caso 1 corriendo contra BD real.
- **Timbrado propio de la NC — NO es un gap, ya está resuelto**: el brief de
  esta tarea asumía (citando "D3 de context/37") que la NC necesitaba
  timbrado separado sin resolver — pero `context/37` no menciona timbrado de
  NC (su D3 es sobre "documentos recibidos" de compras, otro tema). Lo que
  SÍ existe y resuelve el punto real: `EInvoiceService::stampForDocument()`
  lee `provisioning.stampMap[$registerId]['nc']` (separado de `['fc']` para
  factura) y solo cae al `stamp` cacheado global si ese mapa no tiene entrada
  para la caja — el timbrado de la NC YA puede ser distinto del de la
  factura, por caja, cuando el comercio lo configura así en Factomate. No se
  tocó código acá — se verificó que el mecanismo existe y es correcto.

**Gap real, no de esta tarea**: `EInvoiceService::cancel()` (cancelación
manual de un DE) no dispara ninguna cancelación automática cuando
`SaleVoidService::void()` anula una venta que ya tenía una NC electrónica en
su contra — no hay código que combine los dos flujos (documentado ya en
`context/modules/19-facturacion-electronica.md` §6, sin cambios).
