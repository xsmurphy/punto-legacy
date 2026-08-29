# 35 — Vínculos entre transacciones y órdenes (`transaction_link`)

> Estado: **implementado** (mig 115, 2026-08-03). Este doc es la referencia del
> modelo; el código lo cita desde `115_transaction_link.sql` y
> `api/lib/services/TransactionLinkService.php`.

## Qué reemplazó y por qué

Antes existía **un solo campo**, `transaction.transactionparentid` (self-FK),
sobrecargado con cinco semánticas distintas que solo se podían distinguir
cruzando el `transactiontype` de las dos puntas. Y las órdenes se vinculaban a
su factura con un puntero suelto, `pos_order.saletransactionid`, sin tabla
puente: no había forma directa de preguntar "qué órdenes se cobraron con esta
factura", que es el caso real de una mesa con varias comandas.

Ambas columnas se **dropearon** en la mig 115, tras backfillear a dos tablas
puente con el tipo de vínculo explícito.

## Las dos tablas

**`transaction_link`** — transacción ↔ transacción.

| columna | qué es |
|---|---|
| `originid` | el documento del que DERIVA (cotización, factura a crédito, venta original, compra a crédito) |
| `derivedid` | el documento que NACE (factura, pago, nota de crédito) |
| `kind` | el tipo de vínculo (ver tabla de abajo) |
| `companyid` | aislamiento multi-tenant, obligatorio en toda query |

`UNIQUE (companyid, originid, derivedid, kind)`, CHECK de `originid <> derivedid`,
FK reales a `transaction`, e índices en las dos direcciones.

**`order_transaction_link`** — `pos_order` ↔ `transaction`, con `kind =
'order_billed'`. Tabla separada y NO polimórfica a propósito: una orden no es
una transacción financiera, y así las dos puntas conservan FK reales. El índice
`(companyid, transactionid)` es el que responde "todas las órdenes de esta
factura".

## Tabla de `kind`

| kind | derivado ← origen | caso |
|---|---|---|
| `return` | type 6 ← 0/3 | nota de crédito → venta original |
| `credit_payment` | type 5 ← 3 | pago → factura a crédito |
| `purchase_payment` | type 5 ← 4 | pago a proveedor → compra a crédito |
| `quote_to_sale` | type 0/3 ← 9/2 | cotización → factura. **Writer reconstruido el 2026-08-29** (`ebef4373`): la mig 115 backfilleó los históricos desde `transactionParentId` y dropeó la columna, pero el writer que la reemplazara quedó declarado y sin hacer ("sub-slices futuros lo agregarán", `SaleService`). El front venía mandando `parentTransactionId` en el payload de la venta desde entonces y el backend lo descartaba — `assertSimplePathEligible` chequea `parentId`, que es otra clave. Ahora `SaleInput::$quoteParentId` lo lee y `SaleService::save()` escribe el vínculo tras el commit, best-effort. Lo facturado entre la mig 115 y esa fecha NO se puede recuperar. |
| `package_session` | type 13 ← 0/3 | cita/sesión → venta del paquete |
| `table_merge` | type 11 ← 11 | mesa unida → mesa destino (legacy) |

⚠ `transactiontype = 5` es "pago" TANTO de una venta a crédito como de una
compra a crédito. Cualquier lectura que resuelva el origen de un pago **debe
filtrar por `kind`**; sin eso, un pago a proveedor colado en una consulta de
caja resuelve contra una compra. Ya pasó — ver `aaaa33dd`.

## Decisiones cerradas (no relitigar)

**El vínculo es BINARIO: no hay columna `amount`.** Decisión del owner. La fila
dice que A deriva de B y nada más; los montos se siguen infiriendo de los
totales de cada documento.

**Corte limpio, no convivencia.** El backfill y el DROP de las dos columnas
viejas fueron en la misma migración. Se pudo hacer porque el sistema todavía no
estaba en producción.

## Trampas que costaron dos deploys

1. **`transactionparentid` era `uuid`, no `text`.** La migración traía un guard
   de "dato sucio legacy de mesas" con un regex (`!~*`) que en una columna uuid
   ni siquiera tiene operador → SQLSTATE 42883 y el container no arrancaba. En
   una columna uuid un valor mal formado no puede existir: el único descarte
   real es el parent **huérfano** (uuid válido que no resuelve a ninguna fila
   del mismo company), que es lo que hoy se cuenta aparte.
2. **La verificación de conteos aborta el deploy**, a propósito: si el backfill
   no cuadra, `RAISE EXCEPTION` corta antes del DROP y el container viejo sigue
   sirviendo. Si falla, el mensaje NOMBRA los pares `(transactiontype derivado,
   origen)` que ningún INSERT cubrió — no hay que adivinar entre deploys.

## Qué NO se migró

`api/lib/services/OrderService.php` es el módulo de órdenes **legacy** completo,
construido sobre `transactiontype = 12` (pedido online viejo). Sigue vivo, con
consumidores propios, y migrarlo es un trabajo aparte. El reporte de Órdenes del
panel y los KPIs del dashboard sí se migraron a `pos_order` (`c2871e56`).
