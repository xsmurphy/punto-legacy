# 36 — Módulo de Vouchers (vales por productos)

> Estado: **plan cerrado**, sin implementar. Decisiones tomadas con el owner
> el 2026-08-05. No relitigar lo que está en "Decisiones cerradas".

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
4. **Se vende en la caja.** Genera ingreso al emitirlo; el canje posterior no
   suma venta nueva, solo cambia el medio de pago.
5. **Entra como MEDIO DE PAGO** por el valor de sus ítems — los productos se
   cargan al carrito a precio normal y el vale paga esa porción. No entran a
   precio 0: así el reporte sigue mostrando qué se entregó y a cuánto.
6. **Precio CONGELADO al emitir.** El vale cubre `Σ(qty × unitPriceAtIssue)`.
   Si los precios subieron entre la emisión y el canje, el cliente paga la
   diferencia con otro medio. Protege el margen del comercio.

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
| `unitpriceatissue` | **precio congelado**. Es el valor autoritativo del vale (decisión 6), no un dato histórico |

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
3. Si valida, **el vale agrega sus propias líneas** al carrito, con `qty` y
   precio bloqueados, marcadas como pertenecientes al vale.
4. Al llegar al cobro, el vale ya está aplicado como medio de pago por
   `Σ(qty × unitpriceatissue)`. Si los precios subieron, la diferencia queda
   pendiente y se cobra con otro medio.
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
