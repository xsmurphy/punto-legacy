# 36 — Módulo de Vouchers (vales por productos)

> Estado: **F1 y F2 implementadas** (2026-08-07). F1: schema +
> `VoucherService` + `/v1/vouchers.php` (2026-08-06). F2: integración con el
> carrito del POS — canje del vale, exclusión del total, consumo transaccional
> (2026-08-07). Decisiones tomadas con el owner el 2026-08-05. No relitigar lo
> que está en "Decisiones cerradas". Pendiente: flujo de EMISIÓN desde la caja
> (vender el vale como ítem) — ver "Pendiente" abajo.

## F1 — schema + backend (implementada)

- Migración `100_vouchers.sql`: tablas `voucher` y `voucher_item` (columnas
  y FKs tal cual "Modelo de datos" abajo; `code` único por company vía
  `uq_voucher_company_code`, índice compuesto que también sirve de lookup).
- `api/lib/services/VoucherService.php`: `issue()` (atómico, precio
  congelado desde `item.itemprice`, código autogenerado `VC-XXXXXXXX`),
  `validate()` (errores tipados: not_found/used/expired/voided),
  `consume()` (lock optimista `WHERE usedat IS NULL`, idempotente por
  `transactionId`).
- `api/v1/vouchers.php`: `POST ?resource=issue|validate|consume`, realm
  `panel`+`pos-app`, mismo formato que `giftcards.php`.
- Probado end-to-end contra la base real (dentro de una transacción
  revertida): emisión con 2 ítems, validación, canje, canje idempotente,
  re-validación post-canje ("used"), canje con otro transactionId sobre
  voucher ya usado ("used_by_other").

## F2 — integración con el carrito del POS (implementada)

- `frontend/lib/cart/store.ts`: `CartLine.voucher?: {voucherId, code}` (mismo
  patrón que `giftcard`). `lineSubtotal` devuelve 0 si la línea tiene
  `voucher` (explícito, NO vía `discount: 100`). `eligibleForSaleDiscount` /
  `linesCoveredBySaleDiscount` excluyen líneas de vale. `applyVoucher()`
  agrega una línea por ítem (qty/precio = `unitPriceAtIssue`, `basePrice`
  igual), no-op si el `voucherId` ya está aplicado. `removeVoucher()` saca
  TODAS las líneas de ese vale de una vez — única forma de deshacer.
  `addLines`/`addItem` no fusionan líneas de vale con otras (mismo criterio
  que `giftcard`). `applyResolvedPrices` nunca pisa el precio congelado.
  `setLinePrice`/`setLineDiscount` son no-op sobre líneas de vale (backstop
  de store, además del bloqueo en la UI).
- `frontend/components/register/voucher-apply-dialog.tsx` (nuevo): valida el
  código contra `POST /v1/vouchers?resource=validate` y aplica sus ítems al
  carrito. Lista los vales ya aplicados con botón "quitar". Entrypoint: menú
  "Opciones de venta" → "Vale" (`sale-options-drawer.tsx`) — NO el diálogo de
  cobro, se ingresa al armar la venta.
- `frontend/components/register/cart-panel.tsx`: líneas de vale con
  `qtyLocked`, borde/badge azul distintivo ("No suma al total"), precio y
  descuento bloqueados en "más opciones", botón "Quitar" rerouteado a
  `removeVoucher()` (nunca borra una sola línea del vale).
- `frontend/lib/commands/create-sale.ts`: `SaleItem.voucher` propagado desde
  `line.voucher`. `total` de la línea sigue siendo el BRUTO (registro de lo
  entregado); el `subtotal` de la transacción EXCLUYE esas líneas —
  explícito por línea en la suma, no un descuento del 100%.
- `api/lib/App/Domain/Money.php::sanitizeSaleArray`: whitelist de `voucher`
  (`voucherId`, `code`), mismo criterio que `giftcard`.
- `api/lib/Sales/SaleService.php::persistVoucherRedemptions()`: consume cada
  vale (dedupeado por `code`, una vez aunque tenga varias líneas) vía
  `VoucherService::consume()` DENTRO de la transacción de `save()` — mismo
  `$db` ambiente, sin nada especial de por medio. Un consume fallido (carrera,
  vale ya usado) aborta TODA la venta.
- Probado end-to-end contra la base real (transacción revertida): vale con 2
  ítems + 1 ítem extra pagado → `transactionTotal` = solo el extra, ambos
  ítems del vale en `itemsold` con su cantidad, voucher consumido con el
  `transactionid` de la venta.

## Pendiente — fuera de esta fase

- Flujo de emisión desde la caja (vender el voucher como ítem, llamar
  `issue` al confirmar la venta, en la misma transacción de la venta). Hoy
  los vales solo se pueden crear vía llamada directa a `VoucherService::issue`
  (probado en F1) — no hay UI de venta todavía.

## Qué es, y en qué se diferencia de una gift card

Un **voucher** es un vale por PRODUCTOS concretos ("2x Café Americano"), no por
un importe. Se vende en la caja como cualquier otro producto y se canjea una
sola vez.

| | Gift card | Voucher |
|---|---|---|
| Qué representa | un importe (`currentBalance`) | ítems exactos con cantidades |
| Al canjear | cubre hasta su saldo | cubre sus ítems, al precio congelado |
| Usos | uno, se quema | uno, se quema |
| Regla de monto | la venta debe ser ≥ el saldo | el carrito debe contener sus ítems |

La gift card ya está implementada (`api/v1/giftcards.php`, tabla `giftcard`);
este módulo es aparte y NO la reemplaza.

## Decisiones cerradas (owner, 2026-08-05)

1. **Vale por productos**, no por monto ni por descuento porcentual.
2. **Un solo uso.** Al canjearlo queda quemado, sin saldo remanente.
3. **Ítems exactos**, no "elegí uno de esta categoría".
4. **Se vende en la caja.** El ingreso se reconoce al EMITIRLO; el canje no
   suma venta nueva.
5. **Las líneas del vale NO suman al total de la venta.** El vale ya se cobró
   al emitirse: volver a sumarlo sería cobrarlo dos veces. NO hay medio de pago
   "voucher" — la línea simplemente no aporta al total, y lo pendiente a pagar
   es únicamente lo que el cajero haya agregado aparte. Es lo que el cajero y
   el cliente entienden de un vistazo, sin explicación.
6. **Precio CONGELADO al emitir** (`unitpriceatissue`). Congelar NO es para
   cobrarle una diferencia al cliente: es el valor con el que queda REGISTRADO
   lo entregado. Aunque el producto haya subido desde la emisión, el cliente no
   paga nada por esas líneas — el vale las cubre enteras.

   El monto congelado es lo que el cliente efectivamente pagó al comprar el
   vale, así que es la cifra coherente para reportar y para conciliar contra la
   venta de emisión.

⚠ La cobertura de esas líneas tiene que quedar **tipada como "vale"**, no como
descuento genérico. Mezclada con los descuentos manuales, los reportes muestran
promociones que nunca existieron y el cajero no puede distinguir una cosa de la
otra.

## Modelo de datos

**`voucher`** — la cabecera.

| columna | notas |
|---|---|
| `voucherid` | uuid PK |
| `companyid` | aislamiento multi-tenant, obligatorio en toda query |
| `outletid` | sucursal emisora |
| `code` | único por company; mismo criterio de generación que giftcard |
| `status` | activo / anulado |
| `expiresat` | vencimiento, nullable |
| `usedat`, `usedbytransactionid` | marca de consumo (igual que giftcard) |
| `issuedbytransactionid` | la venta que lo emitió — es lo que lo hace auditable |
| `beneficiarycontactid` | opcional |
| `created_at` | |

**`voucher_item`** — el contenido.

| columna | notas |
|---|---|
| `voucheritemid` | uuid PK |
| `voucherid`, `companyid` | |
| `itemid` | FK al ítem |
| `qty` | cantidad comprometida |
| `unitpriceatissue` | **precio congelado**: el valor con el que se registra la línea al canjear (decisión 6). No se usa para cobrar diferencias |

El valor total del vale es `Σ(qty × unitpriceatissue)` y se calcula, no se
duplica en la cabecera — misma razón por la que `pos_order` no guarda total.

## Flujo de emisión

Se vende en la caja como un ítem más. Al confirmar la venta se crea el
`voucher` con sus `voucher_item`, congelando el precio unitario vigente de cada
ítem, y se guarda `issuedbytransactionid` apuntando a esa venta.

⚠ La creación va **en la misma transacción** que la venta, no como
fire-and-forget posterior. Si se hace después y falla, el cliente pagó un vale
que no existe. Es el mismo defecto que tuvimos en el cobro parcial de mesa
(T1, `1f9c8f97`) y en el `consume` de gift cards.

## Flujo de canje

**El vale se ingresa al ARMAR la venta, no al cobrar** (decisión del owner,
2026-08-06). Escanear o tipear el código **carga sus ítems al carrito**; no es
el cajero el que tiene que saber qué contiene el vale y cargarlo a mano.

1. El cajero ingresa el código en el carrito.
2. `validate` verifica que exista, que no esté usado y que no esté vencido.
3. Si valida, **el vale agrega sus propias líneas** al carrito, con `qty`
   bloqueada, precio = `unitpriceatissue` y marcadas como del vale.
4. Esas líneas **no suman al total**: el vale ya está pagado. Lo pendiente a
   cobrar es únicamente lo que el cajero haya agregado aparte, y eso es lo que
   ve en pantalla sin tener que interpretar nada.
5. Al confirmar la venta, `consume` marca `usedat` + `usedbytransactionid` con
   lock optimista (`WHERE usedat IS NULL`), igual que giftcard.

**Por qué el vale trae sus ítems y no se valida contra el carrito**: la versión
anterior de este plan exigía que el cajero cargara los productos primero y
recién ahí aceptaba el vale. Además de incómodo, dejaba abierto el agujero de
quemar un vale sobre una venta que no incluye lo que promete. Trayendo los
ítems consigo, esa condición se cumple por construcción y no hay nada que
validar.

**Líneas propias, NO fusionadas con las existentes.** Si el cajero ya había
cargado los 2 cafés a mano y después ingresa el vale, se verán 4 líneas. Es
deliberado: el error queda a la vista y el cajero borra las suyas. Fusionar en
silencio haría imposible entender qué porción del carrito está cubierta.

**Quitar el vale es lo que desbloquea.** Las líneas del vale no se editan ni se
borran de a una — se van todas juntas al quitar el vale del cobro. Sin esa
salida, un código mal tipeado deja la venta trabada y el cajero termina
cancelándola entera.

Precedente técnico: las líneas de gift card del POS ya usan `qtyLocked` y no son
editables (ver `cart-panel.tsx`).

**Por qué validar antes de cobrar**: rechazar en `consume` dejaría la venta ya
pagada con un vale que nunca se marca usado, o sea reutilizable. Mismo criterio
que el preflight del cobro parcial de mesa.

## Fuera de alcance (fase 1)

- Vales por categoría ("un almuerzo, elegí cuál").
- Vales emitidos como promoción sin cobro.
- Vales generados desde un paquete o convenio.
- Uso parcial o saldo remanente.

## Trampas conocidas del dominio (leer antes de codear)

- `ncmExecute` devuelve un `CaseInsensitiveArray`, **no un array**: `is_array()`
  da false y `(array)` devuelve propiedades privadas mangleadas. Usar `ncmRow()`
  (`api/includes/lib/DB.php`). Esta clase de bug apareció tres veces en un solo
  día: variantes de ítems, resolver de listas de precios y canje de gift cards.
- Identificadores SQL en minúscula **sin comillas**. Un camelCase quoteado
  referencia una columna inexistente y falla en silencio (`ef6bab48`).
- Fechas del backend: parsear con `parseNaive` (`frontend/lib/format-date.ts`).
  `new Date("2026-07-31 23:59:59-03".replace(" ","T"))` da **Invalid Date**, y
  toda comparación contra eso es `false` — así ninguna gift card se marcaba
  vencida (`5a5be14b`).
