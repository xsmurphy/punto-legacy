# 07 — Transferencias de stock

> Estado del doc: borrador (verificado contra código leyendo fuente, sin correr nada)
> Responsable de la última verificación: sesión 2026-08-17

## 1. Qué resuelve

Mover mercadería entre sucursales o depósitos PROPIOS de un mismo comercio,
con doble entrada (egreso en origen, ingreso en destino), numeración
correlativa y cancelación. Es el documento completo de remisión para ese
motivo específico — ver `20-remision.md` para por qué NO se duplica esta
lógica en la tabla de remisión nueva.

## 2. Entidades y datos

| Tabla | Qué guarda | Invariantes / trampas |
|---|---|---|
| `stock_transfer` (mig 46, numerada mig 129) | Cabecera: origen (`fromOutletId`/`fromLocationId`), destino (`toOutletId`/`toLocationId`), `status` (1 activa / 0 cancelada), `docNumber`. | `docNumber` se asigna por la sucursal que EMITE (origen) — la de destino RECIBE el documento, no lo emite (comentario `129_stock_docs_numbering.sql:73-74`). Nullable: transferencias históricas anteriores a la mig 129 quedan sin número (backfill cronológico las numeró retroactivamente). |
| `stock_transfer_item` | Línea: `itemId`, `qty`, `unitCost` — snapshot del costo al momento de crear. | `unitCost` = `stockOnHandCOGS` del outlet ORIGEN al momento de la transferencia (`StockTransferService.php:121-131`), 0 si el ítem nunca tuvo stock ahí. Es un snapshot: no se recalcula después, ni siquiera al cancelar. |

No hay columna de "motivo" en `stock_transfer` — a diferencia de
`document_remision`, el único motivo que modela esta tabla es el traslado
entre sucursales/depósitos propios; no hace falta discriminar.

## 3. Reglas de negocio

1. **Flujo completo: validación pre-TX, doble movimiento dentro de una TX, numeración dentro de la misma TX.** `StockTransferService::create()` (`api/lib/services/StockTransferService.php:19-235`): valida que ambos outlets pertenezcan al tenant, que origen≠destino, que las `locationId` (si vienen) pertenezcan a su outlet, y que todos los `qty > 0` — todo ANTES de abrir la transacción. Filtra ítems no stockeables (`skippedItems`, no aborta la transferencia completa por un ítem sin `itemTrackInventory`). Dentro de la TX: asigna `docNumber`, inserta la cabecera, y por cada ítem stockeable hace un egreso en origen seguido de un ingreso en destino, ambos `source='transfer'`.
2. **Si un movimiento falla a la mitad, la TX entera se revierte — no queda transferencia parcial.** Cualquier `manageStock()` que devuelva `false` dispara `$db->FailTrans(); $db->CompleteTrans(); throw` (`:195-199, 216-220`) — el rollback deshace la cabecera, la línea y cualquier movimiento de stock ya aplicado en esa misma pasada. `DocumentNumber::allocate()` corre DENTRO de la misma TX (`:140-145`), así que un fallo también libera el número — no queda hueco en la secuencia.
3. **Numeración: scope OUTLET, no fiscal.** Mig 129 le dio correlativo (`document_sequence`, `DocumentNumber::allocate()`) con `SCOPE_OUTLET` — "no son documentos fiscales, así que no dependen del punto de expedición" (comentario `129_stock_docs_numbering.sql:6-7`). El criterio es el mismo que producción/merma/conteo: identificable y auditable ("la transferencia 45"), sin necesitar timbrado.
4. **Permiso: el POST exige `inventory.transfer` desde `f6d13c83` (2026-08-15).** Antes, `api/v1/stock_transfer.php` solo validaba `apiAuthTenant(['panel'])` — cualquier usuario con sesión de panel podía mover mercadería entre sucursales sin importar su rol. El fix reusa la permission key existente (`inventory.transfer`, ya en `PermissionCatalog`) en vez de crear una nueva: "un permiso nuevo no lo tendría ningún rol y dejaría a todos afuera hasta editar cada rol a mano" (mensaje del commit). El GET (listado/detalle) sigue con solo auth de panel — mismo criterio que `production.php`/`waste.php`. **Ver `05-stock.md` regla 9: el mismo gate falta hoy en `stock_adjustment.php`/`inventory_count.php`, que ese fix no tocó.**
5. **Cancelación: reversa de doble entrada, permite overdraft en destino a propósito.** `cancel()` (`:402-495`) hace `FOR UPDATE` sobre la cabecera para evitar doble-cancelación concurrente, exige `status=1` (ya cancelada → `409`), y por cada línea revierte: egreso en DESTINO (`source='transfer-cancel'`, `type='-'`) seguido de ingreso en ORIGEN (`type='+'`). El egreso en destino puede dejar el saldo NEGATIVO — decisión documentada en el docblock de la clase (`:11-15`): "el stock puede haber sido consumido (vendido) entre la transferencia y la cancelación. Forzar stock >= 0 bloquearía cancelaciones legítimas — es preferible registrar el saldo negativo y que el operador lo corrija con un ajuste." No hay reversa de la numeración: el `docNumber` de la transferencia cancelada queda usado (no se libera, a diferencia de un fallo a mitad de camino en `create()`).
6. **Doble movimiento con `manageStock()`, nunca `INSERT`/`UPDATE` directo en `stock`.** Declarado explícitamente en el docblock de la clase (`StockTransferService.php:8-9`) y verificado: los cuatro puntos que tocan stock (egreso/ingreso en `create`, egreso/ingreso en `cancel`) pasan los cuatro por `Inventory::manageStock()`.

## 4. Flujos principales

**Crear transferencia** (panel, `POST /v1/stock_transfer?action=create`) — body `{from: {outletId, locationId?}, to: {outletId, locationId?}, note?, items: [{itemId, qty}]}`. Filtra ítems no stockeables antes de abrir TX, snapshotea `unitCost` del outlet origen, asigna `docNumber`, inserta cabecera + líneas, aplica egreso+ingreso por cada ítem (regla 1). Devuelve `{id, itemsProcessed, skippedItems}` — el caller ve qué ítems se salteraron sin que la operación completa fallara.

**Listar / ver detalle** (`GET action=list|get`) — filtros por outlet origen/destino, status, rango de fecha. Sin gate de permiso adicional (regla 4).

**Cancelar** (`POST action=cancel`) — reversa de doble entrada (regla 5), marca `status=0`. Si la transferencia ya estaba cancelada, `409`. No hay "cancelación parcial" — es todo o nada, dentro de una sola TX con `FOR UPDATE`.

**Error a mitad de camino en `create()`** — cualquier `manageStock()` fallido revierte TODA la transferencia (cabecera, líneas, y los movimientos de stock ya aplicados en esa pasada), libera el `docNumber` (regla 2). No hay estado intermedio "parcialmente transferido".

## 5. Interacciones con otros módulos

| Módulo | Qué le pide / le da | Contrato (qué asume) |
|---|---|---|
| Stock (`manageStock`) | Cada transferencia genera 2 movimientos por ítem al crear (egreso+ingreso) y 2 al cancelar (reversa). | Que `manageStock()` es el único camino — nunca escribe `stock` directo (regla 6). Que `manageStock()` permite saldo negativo cuando hace falta (cancelación, regla 5) — un caller que agregara una validación de "stock ≥ 0" ahí rompería cancelaciones legítimas. |
| Sucursales / depósitos | Origen y destino son `outlet`/`taxonomy(type=location)` reales del tenant — validados antes de la TX. | Que origen≠destino siempre (validado explícitamente, `:52-57`) — no existe "transferencia a sí mismo". |
| Numeración (`context/37`) | `DocumentNumber::allocate('transferencia', SCOPE_OUTLET, outletOrigen, ...)`. | Que la numeración es por la sucursal EMISORA, no la receptora (regla 3) — un reporte que agrupe por sucursal DESTINO no va a encontrar la secuencia ahí. |
| Permisos | El POST exige `inventory.transfer`, el GET solo panel. | Que ese gate cubre TODO el dominio de "mover mercadería" — pero no cubre ajuste manual ni conteo (mismo dominio, gate faltante, `05-stock.md` regla 9). |
| Remisión (`20-remision.md`) | `stock_transfer` ES la remisión completa para "traslado entre depósitos propios" — `document_remision` NUNCA modela este motivo. | Que un desarrollador que agregue un motivo nuevo a `RemisionMotivo` no reintroduzca "traslado interno" ahí — ya tiene dueño acá. |
| Impresión | `/stock-transfer/[id]` tiene botón "Imprimir", resuelve al mismo `docType: "delivery"` que usa remisión (`context/42-remision.md:27-31`). | Que el `docType` compartido es intencional (dos fuentes de datos, un solo concepto de negocio impreso) — no una casualidad de nombres. |

## 6. Offline

No aplica — panel-only. `api/v1/stock_transfer.php` autentica con
`apiAuthTenant(['panel'])` (`:16`), no con el realm de dispositivo POS. El
POS no tiene un flujo de transferencia — mover mercadería entre sucursales
es una operación de backoffice, no de mostrador.

## 7. Huecos conocidos y NO verificado

- **Gate de permiso asimétrico dentro del mismo dominio**: `inventory.transfer` cubre transferencia y remisión, pero NO ajuste manual ni conteo físico (mismo hallazgo que `05-stock.md` regla 9, aplicado desde el ángulo de "qué SÍ está bien acá").
- **NO VERIFICADO**: si hay algún límite de cantidad de ítems por transferencia (el body no parece capearlo explícitamente, a diferencia de `list()` que sí capea `limit` a 200).
- **NO VERIFICADO**: comportamiento si `from.outletId`/`to.outletId` pertenecen a compañías distintas dentro de un grupo multi-tenant — la validación solo chequea `companyId = ?` contra el tenant autenticado, así que debería ser imposible, pero no se armó un caso de prueba.
- **NO VERIFICADO**: si el frontend de `/stock-transfer` muestra `skippedItems` al usuario de forma clara, o si ese dato queda solo en la respuesta JSON sin superficie visible.

## 8. Planes y decisiones relacionados

- `context/37-numeracion-documentos.md` — origen de la numeración correlativa (F3, mig 129), scope outlet para documentos internos.
- `context/42-remision.md` — por qué `stock_transfer` no se duplicó al crear `document_remision`, y la unificación en la capa de impresión.
- `05-stock.md` — `manageStock()` como choke point compartido, y el hallazgo de permiso faltante en ajuste/conteo.
