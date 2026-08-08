# 39 — Detalle de transacción (venta a la altura de compra)

> Estado: **F1 implementada** (resolver backend, 2026-08-08). F2-F4 abajo,
> abiertas. Este doc es la referencia del modelo; el código lo cita desde
> `api/lib/Transactions/TransactionDetailService.php`.

## Diagnóstico

El detalle de una COMPRA (`/purchase/{id}` → `Purchases\PurchasesService::find()`)
es la referencia buena del proyecto: un Service dedicado, con JOINs, que
resuelve TODO server-side — nada de IDs crudos que el front tenga que
resolver.

El detalle de una VENTA, en cambio, estaba partido en **cuatro
implementaciones que no compartían nada**:

1. **Panel** — query inline en `api/v1/reports/transactions.php` (GET ?id=),
   sin ningún Service detrás.
2. **POS** — `Services\TransactionService::getSingle()`, con su propio SELECT
   y shape (pensado para la UX de carrito/reimpresión del POS, no para un
   detalle completo).
3. **Reports\TransactionsService::detail()** — el motor del LISTADO (filas
   resumidas para tablas), no un detalle.
4. La referencia — `Purchases\PurchasesService::find()` — que ninguna de las
   tres de arriba alcanzaba.

Casi todos los datos que faltaban ya existían en BD; el endpoint
simplemente no los devolvía: timbrado (`register.invoiceAuth`), caja
(`registerName`), hora completa (se veía solo la fecha en la UI, aunque el
backend nunca la truncó), descuento en % por línea (`itemSoldDiscount` es
monto; el % vive en `meta.transactionDetails`), comisión por línea
(`itemSold.itemSoldComission`, ni siquiera se seleccionaba), usuario
asignado a la línea resuelto a nombre, desglose de impuestos por tasa
(`toTaxObj`, congelado por F2a pero nunca leído), y documentos vinculados
más allá de devoluciones/citas — cotización origen/destino y órdenes/comandas
cobradas por la factura (ambos ya resolubles vía `transaction_link` /
`order_transaction_link`, context/35, pero sin cablear).

## Arquitectura elegida

**Un resolver canónico único**: `Transactions\TransactionDetailService::find()`
(`api/lib/Transactions/TransactionDetailService.php`), espejo de
`Purchases\PurchasesService::find()`. Carpeta nueva (`Transactions/`),
análoga a `Purchases/`: un Service de solo lectura, sin operaciones de
escritura.

Por qué NO en los lugares existentes:

- **`Reports\TransactionsService`** es el motor de LISTADOS (3 vistas:
  detail/cobros/quotes, filas resumidas para tablas) — cargarle un `find()`
  de detalle completo (líneas + desglose fiscal + documentos vinculados) le
  mezcla dos responsabilidades en una clase que ya es grande. Sí se **reusa**
  su `registerInfo()` (bump de visibilidad `private` → `public`) para no
  duplicar la resolución de timbrado/prefix/leading-zeros — un JSONB
  (`register.data`) con criterio no trivial (return prefix, leading zeros).
- **`Services\TransactionService::getSingle()`** es el servicio de
  OPERACIONES del POS (delete/void/changeStatus/reject + su propio
  `getSingle()` para la UX de carrito) — side-effecting, con convenciones
  propias (`enc`/`dec`, `CaseInsensitiveArray` manual, shape pensado para
  Alpine/Mustache legacy). No es el lugar de un resolver de solo lectura
  para el panel React.

`TransactionDetailService::find(id, companyId): ?array` resuelve:

- **Cabecera**: id, tipo, condición (contado/crédito), prefijo+número
  (con leading zeros), timbrado (`authNo`, vía `registerInfo()`), fecha+hora
  completa, estado + `void` (type=7), moneda, cliente (id+nombre+TIN).
- **Ámbito**: sucursal, caja (`registerId`+`registerName`), usuario/cajero
  resuelto a nombre, responsable.
- **Líneas**: `itemSold` (+ `item` para el nombre) enriquecido con
  `meta.transactionDetails` — descuento en monto (BD) y % (meta), comisión,
  usuario de línea a nombre, `taxRate`/`taxKind`/`taxIncluded`/`taxAmount`/
  `taxNet` congelados por F2a. El matching itemSold↔meta es por **cola FIFO
  de itemId** (`itemSold` no tiene columna de secuencia ni FK a su línea de
  meta — `itemSoldId` es UUID v4 random, no ordenable; se fuerza
  `ORDER BY itemSoldId` solo para que el resultado sea determinístico entre
  requests, no para resolver el orden real de inserción). Limitación
  documentada, no bloqueante: dos líneas del MISMO itemId con
  descuento/impuesto distintos en el mismo carrito podrían intercambiar su
  desglose. Caso raro.
- **Desglose fiscal por tasa**: `toTaxObj.toTaxObjText` (congelado por
  `SaleService::persistRelations`, F2a) como fuente primaria; si no hay fila
  o no parsea (deuda conocida: la columna es `VARCHAR(255)` y trunca con
  ~6+ tasas), degrada reconstruyendo desde las líneas de meta ya congeladas,
  agrupadas por `(taxRate, taxKind)` — mismo criterio que
  `SaleService::groupTaxByRate()`, sin re-invocar el motor.
- **Totales**: subtotal (bruto), descuento, impuesto, total neto.
- **Pagos** y **crédito** (total/pagado/deuda + recibos), igual que antes.
- **Documentos vinculados** (`transaction_link` / `order_transaction_link`,
  context/35): notas de crédito y citas (ya existían), + **`quote_to_sale`
  en ambas direcciones** (cotización origen de esta venta / venta facturada
  desde esta cotización) y **órdenes/comandas cobradas** por la factura
  (`listOrderIdsForTransaction` — caso "mesa con varias comandas"). Cada uno
  viene con lo mínimo para linkear y mostrar: id, tipo, fecha, número, total.

### `toTransaction` (tabla legacy) — DEPRECADO

Grep de todo `api/` (2026-08-08): **ningún INSERT vivo** a `toTransaction`.
El único otro lector es el `DELETE` en cascada de
`Admin\CompanyAdminService::delete()`. `transaction_link` (mig 115) la
reemplazó funcionalmente. El resolver la sigue leyendo (por si queda alguna
fila pre-migración) pero en la práctica siempre da `[]`. No se borra la
tabla en F1 — candidato a `DROP TABLE` en una migración futura, fuera de
alcance acá.

## Fases

- **F1 — resolver backend** (implementada, 2026-08-08). Panel cablea contra
  el resolver; la query inline murió. POS **no migra** en esta fase.
- **F2 — página dedicada `/transactions/{id}`**. Decisión cerrada del
  owner: el detalle deja de vivir en un Dialog/Sheet embebido y pasa a una
  página propia, **espejo de `/purchase/{id}`** (mismo patrón de layout:
  cabecera + líneas + totales + pagos + documentos vinculados). El Dialog
  actual (`PanelDetailView`, `transactions-list.tsx`) queda para navegación
  rápida desde listados; la página nueva es la vista completa.
- **F3 — cotizaciones + "Pagos recibidos"**. Decisión cerrada del owner:
  **"Pagos recibidos" se queda con un modal básico** (no amerita página
  propia — no hay mucho que mostrar más allá de fecha/monto/método). El
  flujo de cotización→venta (quote_to_sale) se expone en la página F2 usando
  `quotesOrigin`/`quotesDerived`, ya resueltos por F1.
- **F4 — migrar el POS al resolver canónico**. `Services\TransactionService::getSingle()`
  y sus 2 call-sites quedan tal cual en F1 (riesgo/UX propios del POS,
  fuera de alcance). Migrarlo es trabajo aparte: hay que decidir si el POS
  consume el mismo shape o un adapter, sin romper carrito/reimpresión.

## Decisiones cerradas (no relitigar)

- El detalle vive en una página dedicada `/transactions/{id}`, espejo de
  `/purchase/{id}` (F2).
- "Pagos recibidos" se queda con un modal básico, no página propia (F3).
- El POS migra al resolver canónico en F4, no en F1 — su UX y su riesgo son
  propios.
- `toTransaction` es legacy deprecado; no se borra la tabla todavía.
