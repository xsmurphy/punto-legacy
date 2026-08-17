# Módulos del sistema — índice y mapa de interacciones

> Creado 2026-08-16 por pedido del owner. **Propósito**: que nadie tenga que
> re-descubrir un módulo entero, y sobre todo que nadie **asuma** cómo funciona
> otro módulo al integrarse con él.
>
> Motivo concreto, de esta misma sesión: se cerró en falso un reporte de
> cuentas por pagar ("no existe ninguna compra a crédito") apoyándose en un
> conteo en vez del flujo; se "arregló" un bug (T8) que ya estaba resuelto; y
> un badge que decía "Completa" para el estado del DOCUMENTO se leyó dos veces
> como "pagada". Los tres son el mismo error: asumir en vez de verificar.

## Qué es esto y qué NO es

- **Es**: cómo funciona cada módulo hoy, qué reglas lo gobiernan, y **qué
  asume de los demás**. Con evidencia `path:line`.
- **No es**: una bitácora de commits (eso es `05-modulos-clave.md`) ni un plan
  de cambios (esos son los `context/NN-*.md` numerados). Este doc describe el
  estado y las reglas; los planes describen las modificaciones.

## Reglas para escribir y mantener estos docs

1. **Todo con evidencia.** Cada regla lleva `path:line`. Sin evidencia es
   suposición, y la suposición es exactamente lo que este doc elimina.
2. **Lo no verificado se marca como no verificado.** Es información valiosa.
   Afirmar sin haber mirado es peor que dejar el hueco declarado.
3. **La sección de interacciones es obligatoria** aunque el módulo parezca
   aislado. Ningún módulo lo está.
4. **Se actualiza al cambiar el módulo**, no "después". Un doc desactualizado
   es peor que no tenerlo: da confianza falsa.
5. Plantilla: `_template.md`. Respetarla — la uniformidad es lo que hace que
   se pueda leer rápido el módulo que no conocés.

## Índice

Estado: ⬜ sin escribir · 🟡 borrador · ✅ verificado contra código

### Catálogo y precios
- ✅ `01-catalogo-items.md` — tipos de artículo (`kind`), campos, variantes; **`kind=pack` se ofrece en el panel pero el backend lo rechaza con 422** (no está en `VALID_KINDS`)
- ✅ `02-combos-y-addons.md` — combo fijo, combo dinámico, grupos de add-ons
- ✅ `03-listas-de-precio.md` — prioridad de listas, ajustes, overrides por ítem
- ✅ `04-impuestos.md` — tasas, incluido/añadido, exento vs 0%, congelado por línea

### Inventario
- ✅ `05-stock.md` — movimientos, `manageStock` como choke point, ajustes, conteo
- 🟡 `06-produccion.md` — recetas, producción directa vs previa, merma, costos
- ✅ `07-transferencias.md` — entre sucursales/depósitos, y su relación con remisión

### Compras
- ✅ `08-compras.md` — contado vs crédito, packSize, OCR, borradores; `transactionStatus` (documento) no es `transactionComplete` (pago)
- ✅ `09-notas-credito-compra.md` — devolución a proveedor; es la ÚNICA dueña del stock de devolución (confirma que remisión hace bien en no moverlo)

### Ventas
- ✅ `10-pos-venta.md` — carrito, medios de pago, descuentos, offline; venta ONLINE nunca asigna `invoiceNo` (hallazgo)
- ✅ `11-ordenes-y-comandas.md` — orden vs venta, cocina, estados; add-ons en la orden YA resuelto (commit `46ac668f`), el gap sigue solo al cobrar
- ✅ `12-espacios.md` — mesas, sesiones, cobro parcial (ledger), estado compartido online-only
- ✅ `13-cotizaciones.md` — **el vínculo cotización→venta no existe**: `kind='quote_to_sale'` se lee pero nada lo escribe

### Dinero
- ✅ `14-caja.md` — apertura, cierre, arqueo; **una venta offline puede caer en el arqueo del turno equivocado** (el `drawerid` se resuelve al sincronizar)
- ✅ `15-credito-y-cobranzas.md` — cuentas por cobrar/pagar, FIFO; el gate de crédito habilitado es SOLO el cache local del POS
- ✅ `16-giftcards-y-vales.md` — **canje de gift card es fire-and-forget** (la venta queda cobrada aunque el saldo no se debite); los vales sí son atómicos

### Documentos
- ✅ `17-numeracion.md` — correlativos, arriendo offline, scope por caja/sucursal; venta sin número NO se emite (owner 2026-08-16)
- ✅ `18-impresion.md` — plantillas, bloques, bindings, transports; native/escpos local, station depende de internet por diseño
- ✅ `19-facturacion-electronica.md` — SIFEN/Factomate, lee IVA congelado por línea (F3a), no el catálogo
- ✅ `20-remision.md` — `document_remision` (motivos sin outlet propio) vs `stock_transfer` (traslado interno); ningún motivo mueve stock

### Transversales
- ✅ `21-contactos.md` — clientes, proveedores, direcciones; **el importador CSV duplica en cada reimportación** (compara teléfono con `+` contra columna sin `+`)
- ✅ `22-sincronizacion.md` — realtime, delta, lápidas; `parked-sales.php` y `numbering/lease.php` mutan sin emitir evento
- ✅ `23-auth-y-permisos.md` — realms, tokens opacos; **solo 25 de 45 claves de permiso tienen chequeo en el backend**
- ✅ `24-sucursales-y-scopes.md` — view-scope; `Roc::build()` cae a "todas las sucursales" con `outletId` vacío, `TenantContext` falla cerrado (garantía no uniforme)
- ✅ `25-reportes.md` — incluidos los fiscales; **el rollup existe pero `rollup_reconcile()` no tiene caller** y `pg_cron` no está instalado en prod

## Mapa de interacciones (se completa a medida que se escriben los docs)

Las flechas más peligrosas — donde una suposición equivocada rompe plata:

| Origen | Destino | Qué asume |
|---|---|---|
| Venta | Impuestos | Que la tasa se congela por línea al vender, no se recalcula del catálogo |
| Venta | Numeración | Que hay correlativo disponible; sin él NO se emite |
| Venta | Stock | Que `manageStock` es el único camino que mueve inventario |
| Compras | Cuentas por pagar | Que crédito ⇒ `transactionComplete = false` |
| Cobranzas | `transaction_link` | Que el saldo sale de sumar vínculos, no de una columna |
| POS | Sincronización | Que el cache local es fuente de verdad para operar |
| Impresión | Plantillas | Que lo que se imprime lo decide la plantilla, no el renderer |
| Órdenes/mesas | Add-ons | **Actualizado 2026-08-17**: el gap de creación YA se cerró (`46ac668f`, mismo día) — `CreateOrderItemInput` SÍ tiene `selections`, `OrderCoreService::create()` las valida y persiste como líneas hijas, y comanda/KDS las muestran indentadas. Lo que SIGUE roto es más angosto: al COBRAR esa orden, `loadFromOrder`/`loadFromSession` no re-mandan `selections` (para no duplicar el recargo, que ya está en `unitPrice`) — `expandAddonSelections` nunca corre para esa venta, así que el stock de la opción elegida no se descuenta y el `itemSold` pierde el desglose fiscal. Plata correcta, inventario/trazabilidad rotos. Ver `context/modules/11-ordenes-y-comandas.md` regla 3 |
| Venta | Numeración (camino online) | Que una venta emitida CON conexión recibe `invoiceNo` igual que la offline — en realidad `SaleService::save()` nunca llama `DocumentNumber::allocate()` para venta (solo cotización) y el front nunca manda `invoiceno` en `/v1/sales`: **toda venta online persiste `invoiceNo = NULL`**. Solo el camino offline→`offline-sync.php` inyecta el número (desde el lease). Ver `context/modules/10-pos-venta.md` regla 4 |
| Venta | Impresión (número de comprobante) | Que el ticket recién impreso muestra el `invoiceNo`/`leasedInvoiceNo` — en realidad `buildTicketData` (usado en AMBAS ramas, online y offline) nunca lee `result.invoiceNumber` hacia `documentNumber`; el bloque `document_number` renderiza vacío en las dos ramas. Ver `context/modules/10-pos-venta.md` regla 4 |
| Espacios | Stock | Que un cobro parcial no puede descontar stock dos veces — depende ÍNTEGRAMENTE de que una sesión no mezcle familias `items`/`amount`/`share` (`SpaceSettlementService::validateAndComputeAmount`); si ese guard se rompiera, un ítem prorrateado por monto libre podría volver a cobrarse por ítems y descontar su stock dos veces sin que la plata lo delate. Ver `context/modules/12-espacios.md` regla 2 |
| Add-ons | Stock | Que cada opción elegida (incluidas `isLocked`) descuenta con la misma `explodeRecipe` que cualquier ítem — sin excepción para las que el cajero no tocó |
| Venta/Anulación | Producción | Que ambas resuelven "¿esta receta se explota?" con el MISMO predicado (`Inventory::saleExplodesRecipe`, contra BD) — no contra `$saleDetail[]['type']`, que el POS nunca manda. Divergencia real ya ocurrida (fix `822f8df3`): producción previa consumía insumos dos veces y anular reponía insumos jamás gastados |
| Producción | Reportes | Contrato roto hoy: los tabs de "producción directa" filtran `item.itemType = 'direct_production'` y `stock.stockSource = 'production'`, pero ninguno de los dos valores llega a ocurrir nunca (el primero es una etiqueta sintética que no se persiste; el segundo depende del mismo campo de carrito que el POS no manda) — esos tabs quedan vacíos siempre, sin error visible |
| Listas de precio | Impuestos | Que el precio YA ajustado por lista (descuento o recargo) es la base sobre la que se calcula el IVA — verificado: `line.unitPrice` post-resolución viaja como `price` al motor de impuestos sin ningún paso que revierta el ajuste antes de gravar |
| POS | Listas de precio | Que `/v1/price_resolve` siempre resuelve el precio correcto — sin conexión, el front atrapa el error y cobra precio BASE en silencio: un cliente con lista de descuento paga precio lleno offline, sin aviso (plan `context/44` sin implementar) |
| Impuestos | Facturación electrónica / RG90 | Que `kind=exempt` y `kind=rate,rate=0` son fiscalmente distintos — pero el layout fijo de RG90 (3 columnas) los junta en "exento" por falta de columna, no por error de dato |
| Venta | Stock (`source`) | Que el `source` del movimiento de una receta explotada distingue producción directa de venta simple — en realidad `SaleService.php:1842` lee `$sD['type']` (que el POS nunca manda) para decidirlo, así que ese `source` es SIEMPRE `'sale'`, nunca `'production'`; mismo patrón de campo-que-nunca-llega que ya rompía `Producción → Reportes` |
| Panel | Ajuste de stock / Conteo físico | **Corregido 2026-08-17** (`4de46ba1`): `inventory.stock.adjust` existía en `PermissionCatalog` y gateaba la UI, pero `api/v1/stock_adjustment.php` e `inventory_count.php` nunca llamaban `hasPermission()`, solo exigían sesión de panel — mismo bug que `f6d13c83` ya había corregido en transferencia/remisión (`inventory.transfer`), sin tocar estos dos. Ambos endpoints ahora exigen el permiso; el GET sigue con la auth de panel, mismo criterio que `production.php`/`waste.php` |
| Remisión | Stock | Que `document_remision` (venta/devolución a proveedor/consignación/exposición/compra) NUNCA mueve stock — cada motivo con movimiento real tiene su propio dueño (venta→factura, devolución→NC de compra, compra→compras); moverlo también acá duplicaría el descuento. Consignación/exposición no tienen dueño de stock hoy — decisión ABIERTA del owner, no un bug |
| POS (catálogo) | Stock | Que `PosItem.stock` refleja el saldo real para alertar "stock bajo" — en realidad `frontend/lib/pos-bff/reshape.ts:83` fija `stock: null` a mano con un TODO desactualizado ("el LIST no incluye stock"); el backend (`ItemsQuery.php`) sí expone `stockOnHand` desde mig 133, pero el reshape del BFF nunca lo lee |
| Numeración | Caja | **Corregido 2026-08-17**: el invariante fiscal NO es "un punto de expedición, una caja" — es "un par (timbrado, punto de expedición), una caja activa". Dos cajas SÍ pueden compartir EEE-PPP con timbrados distintos (talonarios independientes). Guard en `RegisterAdminService::assertExpeditionPointFree` (`api/lib/services/RegisterAdminService.php:523`) + índice único parcial desde `143_register_expedition_point_unique_by_auth.sql` sobre `(companyid, data->>'registerInvoiceAuth', data->>'registerInvoicePrefix')`. La mig 128 (índice sobre el prefix solo, sin timbrado) quedó superseded por clave incorrecta — nunca llegó a crearse en prod por duplicados preexistentes con esa clave |
| Numeración (arriendo offline) | POS multi-dispositivo | Que el lease de una caja tiene un solo tenedor — en realidad `lease.php` entrega el bloque activo a cualquier device que lo pida sin noción de tenencia; dos dispositivos parados a la misma caja pueden recibir el MISMO bloque y duplicar números al sincronizar offline. Riesgo P0 documentado (`context/29-numeracion-y-exclusividad-de-caja.md`), plan F0-F6 sin implementar |
| Impresión | Add-ons (D4) | Que "lo que se imprime lo decide la plantilla" (D4, `context/41`) — en realidad `buildTicketItemsFromTransaction` filtra add-ons gratis en el BUILDER según `docType==="order"` (`build-ticket-data.ts:452-467`), no con variantes de bloque como D4 pidió; el resultado observable es correcto pero la arquitectura no es la decidida |
| Facturación electrónica | Anulación / nota de crédito | Que la nota de crédito electrónica depende del plan de anulación de `context/40` — en realidad ya emite hoy, atada a `transaction_link kind='return'` (devoluciones), un mecanismo preexistente e independiente; `context/40` sigue "sin implementar" y no es su fuente |
| Venta offline | Caja (arqueo) | Se asume que la venta cae en la sesión de caja en la que se hizo. En realidad `transaction.drawerid` se resuelve en el INSERT (`SaleService.php:679`, `CreditPaymentService.php:551`), que para una venta offline es el momento del SYNC. Si la caja rotó de sesión en el medio, la plata aterriza en el turno que estuviera abierto al sincronizar. Sin error ni aviso |
| Caja | Numeración (arriendo) | Cerrar la caja NO libera la tenencia de `register_lease` — dos ciclos de vida sobre el mismo `register` sin cablear entre sí |
| POS (UI) | Caja (control a ciegas) | Se asume que `registerBlindControl` oculta los totales al cajero. `/v1/drawer.php` nunca lo lee: devuelve siempre los datos completos y el ocultamiento pasa solo en `pos-main-menu.tsx` |
| Venta | Gift card (canje) | El canje se dispara DESPUÉS de confirmar la venta (fire-and-forget): si falla, la venta queda cobrada y el saldo nunca se debita. Offline es peor — el bloque vive después del `return` de la rama offline, así que nunca consume ni al sincronizar. Contraste: el canje de VALE corre dentro de la transacción y aborta todo si falla |
| Cotización | Venta | Se asume que convertir una cotización deja rastro. `pos-transactions-dialog.tsx:551` afirma que el back vincula, pero `SaleService` nunca lee `parentTransactionId` (`SaleService.php:652`: "columna dropeada"). `kind='quote_to_sale'` se lee en el resolver pero NADA lo escribe |
| Panel (cualquier módulo) | Permisos | Se asume que una clave en `PermissionCatalog` gatea el backend. Solo 25 de 45 lo hacen, y 5 de esas se chequean ÚNICAMENTE dentro del agente IA (`api/v1/ai/execute.php:66-75`), nunca en el endpoint REST del mismo recurso. 12 endpoints de escritura sin ningún chequeo: `items.php`, `contacts.php`, `spaces.php`, `orders-core.php`, `sales.php`, `devices.php`, `users.php`, `drawer.php`, `giftcards.php`, `purchases.php`, `register.php`, `customers.php` |
| Reportes | Rollup | Se asume que activar `REPORTS_ROLLUP_ENABLED` acelera los reportes. Hoy serviría datos CONGELADOS: `rollup_reconcile()` no tiene ningún caller, `pg_cron` no está instalado en prod (verificado 2026-08-17) y `rollup_dirty` acumula 121 períodos que nadie drena |
| Contactos | Importador CSV | El modo "actualizar" compara el teléfono en E.164 con `+`, pero `contactPhone` se persiste SIN `+` por convención del proyecto: nunca matchea, así que cada reimportación crea duplicados en vez de actualizar (`ContactImporter.php:109,128-131`) |
| Catálogo | Alta de ítem (`kind=pack`) | El frontend ofrece "Pack / Combo de servicios" con formulario propio, `packDurationDays` y endpoint `/v1/pack_component`, pero `'pack'` no está en `VALID_KINDS` (`api/v1/items.php:141`): toda alta de pack falla con 422. Feature construida de los dos lados menos en la lista que valida |
| Cuentas por pagar | Saldo de crédito | Dos fórmulas distintas calculan el mismo saldo (`Reports/PurchasesService::payedByParent` vs `Finance/OpenInvoicesService::payedByParent`): una resta `transactionDiscount`, la otra no. Coinciden hoy solo porque las compras a crédito con NC rara vez llevan descuento |
