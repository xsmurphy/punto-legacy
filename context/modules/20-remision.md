# 20 — Remisión

> Estado del doc: borrador (verificado contra código leyendo fuente, sin correr nada)
> Responsable de la última verificación: sesión 2026-08-17

## 1. Qué resuelve

Documentar (numerar, consultar, imprimir) el traslado de mercadería cuando
el destino NO es una sucursal/depósito propio del comercio: venta,
devolución a proveedor, consignación, exposición/demostración, o compra
(recepción). Sin conexión a SIFEN todavía — es la base sobre la que se monta
la remisión electrónica cuando llegue esa fase.

**Relación con `07-transferencias.md`, léase primero si no está claro**: el
traslado entre sucursales/depósitos PROPIOS ya tenía su propio documento
completo (`stock_transfer`) antes de que existiera este módulo. `document_remision`
(mig 137) NO lo duplica — cubre exactamente los motivos que `stock_transfer`
no modela porque no hay un outlet propio del otro lado.

## 2. Entidades y datos

| Tabla | Qué guarda | Invariantes / trampas |
|---|---|---|
| `document_remision` (mig 137) | Cabecera: `motivo` (enum tipado, no texto libre), `outletId`/`locationId` de origen, `destinationContactId`/`destinationNote` de destino, `transactionId` opcional, `docNumber`, `status`. | `CHECK motivo IN ('venta','devolucion_proveedor','consignacion','exposicion','compra')` (`137_document_remision.sql:76-77`) — **`'traslado_interno' NO es un valor válido acá a propósito**, ese motivo vive en `stock_transfer`. `transactionId` nullable: la remisión puede emitirse ANTES de que exista la factura/NC/compra que la respalda (caso típico: remisión primero, factura días después). |
| `document_remision_item` | Línea: `itemId`, `qty > 0`, `note`. | `itemId` es `ON DELETE RESTRICT` — no se puede borrar un ítem que aparece en una remisión histórica. |
| `RemisionMotivo` (enum PHP, `api/lib/Documents/RemisionMotivo.php`) | Espejo tipado del `CHECK` de BD, con `label()` y `ownsStockMovementElsewhere(): bool`. | `ownsStockMovementElsewhere()` es documentación ejecutable de la regla 2 de abajo — existe para que un futuro caller no reintroduzca un movimiento de stock pensando que "falta". `Venta`/`DevolucionProveedor`/`Compra` → `true` (ya tienen dueño); `Consignacion`/`Exposicion` → `false` (sin dueño hoy, decisión abierta). |

## 3. Reglas de negocio

1. **La relación con transferencias, sin ambigüedad**: `stock_transfer` ES la remisión completa para "traslado entre sucursales/depósitos propios" — numerada, con movimiento de stock de doble entrada, con cancelación (ver `07-transferencias.md`). `document_remision` NUNCA modela ese motivo; los cinco valores válidos de `motivo` son, todos, casos donde el destino NO es un outlet propio del comercio (`137_document_remision.sql:9-13`, `RemisionMotivo.php:12-16`). Dos tablas, un solo concepto de negocio ("remisión"), **unificadas solo en la capa de impresión** — ambas resuelven al mismo `docType: "delivery"` (`context/42-remision.md:57-60, 43-47`).
2. **NINGÚN motivo de `document_remision` mueve stock — invariante de diseño, no un hueco.** Cada motivo con movimiento real ya tiene su propio dueño: `venta` → la factura, cuando se emite (`SaleService`); `devolucion_proveedor` → `PurchaseCreditNoteService` (transactionType 14, `affectsStock`), que YA descuenta y YA tiene reversa; `compra` → `PurchasesService` (types 1/4), que YA suma el stock al recibir. Mover stock también desde `document_remision` para esos tres duplicaría el movimiento si el comercio también carga el documento que sí corresponde — es exactamente el error de doble-descuento que este diseño evita (docblock completo en `137_document_remision.sql:15-25`, espejado en código por `RemisionMotivo::ownsStockMovementElsewhere()`). Verificado en `RemisionService::create()` y `cancel()`: ningún método de la clase llama `Inventory::manageStock()` — `cancel()` lo dice explícito en el comentario (`RemisionService.php:310-311`: "Sin reversa de stock: document_remision nunca mueve stock — cancelar es solo marcar el status").
3. **Consignación y exposición no mueven stock — y es DECISIÓN ABIERTA del owner, no un descarte.** A diferencia de los otros tres motivos, estos dos NO tienen hoy una operación que consuma ese stock — moverlo de la sucursal de origen sin un dueño real "sería inventar un movimiento sin dueño" (`137_document_remision.sql:23-25`). Documentado explícitamente como pendiente en `context/42-remision.md §Decisiones abiertas`: si el comercio necesita dejar de contar ese stock como propio mientras está en consignación, hace falta un concepto de "ubicación de consignación" que hoy no existe. Tampoco hay remisión de retorno modelada para exposición (documento de una sola vía).
4. **Numeración: scope OUTLET, mismo criterio que producción/merma/transferencia/conteo (mig 129 F3), NO el scope register que `context/37` había propuesto originalmente para remisión "por ser fiscal".** La mayoría de estos motivos se emiten desde el panel/backoffice sin caja de por medio (traslado por compra, devolución a proveedor, consignación, exposición) — forzar scope register dejaría esos flujos sin secuencia utilizable (`137_document_remision.sql:30-39`). Cuando llegue la fase SIFEN y el timbrado exija punto de expedición, la migración de scope se hace entonces (mismo patrón que la mig de facturación electrónica hizo para factura/cotización).
5. **Permiso: mismo gate que transferencia, reusado a propósito.** El POST de `api/v1/remisiones.php` exige `inventory.transfer` (no una clave nueva) desde el mismo commit `f6d13c83` que corrigió el gate de transferencia — "emitir una remisión es amparar mercadería que sale del comercio, no algo que habilite el solo hecho de tener acceso al panel" (`remisiones.php:77-83`). Antes, cualquier usuario de panel podía emitir una remisión sin rol específico.
6. **Cancelación: solo cambia `status`, no hay reversa de stock que revertir (regla 2).** `RemisionService::cancel()` (`:287-322`) exige `status=1` (ya cancelada → `409`, `FOR UPDATE` contra concurrencia), marca `status=0`. A diferencia de `stock_transfer::cancel()`, no hay ningún `manageStock()` que ejecutar — es la consecuencia directa de que el documento nunca movió nada.

## 4. Flujos principales

**Crear remisión** (panel, `POST /v1/remisiones?action=create`) — `RemisionService::create()` (`api/lib/services/RemisionService.php:21-165`): valida `motivo` contra el enum, `outletId`/`locationId` del tenant, `destinationContactId` (si viene) contra `contact`, `transactionId` (si viene) contra `transaction`, e ítems (existencia + `itemStatus=1` + `qty>0`) — todo antes de la TX. Dentro de la TX: asigna `docNumber` (scope outlet), inserta cabecera + líneas. Nunca toca `stock`.

**Listar / ver detalle** (`GET action=list|get`) — filtros por outlet, motivo, status, rango de fecha. Sin gate de permiso adicional para lectura, igual que transferencia.

**Cancelar** (`POST action=cancel`) — solo `status=0`, sin reversa (regla 6).

**Consulta e impresión** — Panel: `/remisiones` (listado), `/remisiones/new` (alta), `/remisiones/[id]` (detalle + Imprimir + Cancelar). El detalle de `/stock-transfer/[id]` ganó el mismo botón "Imprimir" en esta misma fase — antes no tenía ninguno. Dos adapters (`buildTicketDataFromStockTransfer`, `buildTicketDataFromRemision`, `frontend/lib/hardware/printers/build-ticket-data.ts:144-145, 761-801`) alimentan tres bloques nuevos de plantilla (`transfer_reason`, `transfer_origin`, `transfer_destination`, disponibles para CUALQUIER `docType`, sin gating — decisión del owner: "es problema del cliente" qué bloque va en qué documento). Ninguno de los dos adapters manda precios: una remisión ampara traslado, no venta — `unitPrice`/`total` van en 0 (si el comercio agrega esos bloques a su plantilla de remisión, imprime "Gs. 0" a propósito, decisión suya).

**POS**: el tile "Remisión" del `PosModeDialog` sigue en "Próximamente" — NO se activó en esta fase. El único motivo con sentido de emitirse desde la caja es `venta` (traslado por venta, antes de facturar), pero esa fase entregó el CRUD desde el panel, no un flujo dentro del carrito (selección de motivo/cliente/dirección/ítems ya cargados). Activar el tile sin ese flujo real dejaría un botón que abre el panel en otra pestaña.

## 5. Interacciones con otros módulos

| Módulo | Qué le pide / le da | Contrato (qué asume) |
|---|---|---|
| Transferencias (`stock_transfer`) | Motivo excluyente: `document_remision` nunca modela "traslado interno" — ese ya vive ahí, con su propio movimiento de stock. | Que ningún desarrollador agregue `'traslado_interno'` al `CHECK` de `motivo` — el `CHECK` de BD lo bloquea aunque el código de un caller lo intentara. |
| Compras (`PurchasesService`, `PurchaseCreditNoteService`) | `document_remision` con `motivo IN ('compra', 'devolucion_proveedor')` es puramente documental — el movimiento de stock real ya lo hacen estos servicios. | Que el comercio no espera que emitir la remisión, por sí sola, mueva stock — si lo hiciera, doble-descontaría cuando también se carga la compra/NC. Este es el invariante central del módulo (regla 2). |
| Ventas | `motivo='venta'` documenta el traslado; la factura (cuando se emite) es la que mueve stock y plata. | Que puede pasar tiempo entre la remisión y la factura (`transactionId` nullable a propósito) — no hay job que las concilie automáticamente ni alerte si una remisión de venta nunca llegó a facturarse. |
| Numeración (`context/37`) | `DocumentNumber::allocate('remision', SCOPE_OUTLET, ...)`, mismo asignador que producción/merma/transferencia/conteo. | Que el scope outlet es correcto HOY (no fiscal) — cuando llegue SIFEN, puede exigir migrar a scope register (regla 4), como ya pasó con factura/cotización. |
| Permisos | Reusa `inventory.transfer` — mismo gate que transferencia, sin clave propia. | Que separar remisión de transferencia (si algún día hace falta) requiere agregar una clave Y sembrarla en los roles que ya tienen `inventory.transfer` — no es automático. |
| Impresión / plantillas | `docType: "delivery"` compartido con transferencia; bloques `transfer_*` sin gating de `docType`. | Que el constructor de plantillas no debe restringir qué bloque va en qué documento (decisión del owner) — un comercio puede armar una plantilla de remisión con bloques de precio y el sistema va a imprimir "Gs. 0" sin advertencia, porque el adapter nunca puebla esos campos para remisión. |
| Facturación electrónica (SIFEN, `context/28`) | `motivo` como columna TIPADA (no texto libre) es justamente lo que permite sumar campos exigidos por SIFEN por motivo (transportista, vehículo, chofer) sin romper lo ya emitido. | **NO conectado hoy** — `api/lib/EInvoice/*` no fue tocado por esta fase. Cuando se conecte, `document_remision` y `stock_transfer` son las DOS fuentes que hay que mapear a la remisión electrónica (mismo patrón que `SaleToInvoiceMapper` para factura). |

## 6. Offline

No aplica — panel-only. `api/v1/remisiones.php` autentica con
`apiAuthTenant(['panel'])`, no con el realm de dispositivo POS. El tile del
POS existe en la UI pero deshabilitado ("Próximamente") — no hay superficie
offline que documentar todavía.

## 7. Huecos conocidos y NO verificado

- **Sin conexión SIFEN**: `api/lib/EInvoice/*` no fue tocado. Falta, como mínimo: mapeo motivo→campos exigidos por la tabla 3 de la SET.py (transportista, vehículo, chofer para traslados propios), y posible migración de numeración de scope outlet a scope register si el timbrado paraguayo lo exige por punto de expedición — a confirmar contra la especificación real de SIFEN para remisión electrónica (`context/42-remision.md §Qué queda para SIFEN`).
- **Consignación/exposición sin dueño de stock** (regla 3) — decisión abierta del owner, no implementado ningún concepto de "ubicación de consignación" ni remisión de retorno para exposición.
- **`transactionId` sin UI de vínculo posterior**: el backend acepta el campo opcional al crear, pero no hay acción "vincular esta remisión a la factura ya emitida" desde el detalle — vale la pena solo si el flujo real de "remisión primero, factura días después" necesita auditar el link explícitamente; hoy queda manual.
- **POS sin flujo de remisión-por-venta**: tile deshabilitado a propósito (ver §4) — el caso de uso con más sentido de mostrador (`motivo='venta'`) no está cubierto todavía.
- **NO VERIFICADO**: si existe algún reporte o alerta que cruce remisiones de `motivo='venta'` sin `transactionId` contra el tiempo transcurrido, para detectar remisiones "huérfanas" que nunca se facturaron.

## 8. Planes y decisiones relacionados

- `context/42-remision.md` — plan de implementación completo (estado: implementada, mig 137, 2026-08-15), diagnóstico de qué se reusó, arquitectura elegida, decisiones abiertas y lo que queda para SIFEN.
- `context/37-numeracion-documentos.md` — numeración correlativa compartida (F3, mig 129), origen del criterio scope outlet.
- `context/28-facturacion-electronica-plan.md` — fase SIFEN/Factomate, donde eventualmente se conecta este módulo.
- `07-transferencias.md` — el otro documento de "remisión", para el motivo traslado interno.
