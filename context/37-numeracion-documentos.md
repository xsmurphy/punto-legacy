# 37 — Numeración correlativa de documentos

> Estado: **plan abierto** (2026-08-04). D1/D2/D4 cerradas. D3/D5/D6 pendientes
> del owner. F1 puede arrancar: no depende de ninguna de las tres.

## Requerimiento

Todo documento que el sistema **emita o reciba** lleva numeración correlativa.
Es obligatorio, no opcional. El número de orden puede ser por sucursal (no es
documento legal); el resto de los documentos legales van por punto de
expedición, que en Punto es la caja (`context/29`, `context/25`).

## Estado actual — el gap

Ojo: la numeración por documento **existe desde el legacy, dormida**. Mig 26
mantuvo a propósito 9 columnas contador en `register` ("counters atómicos del
POS"): `registerInvoiceNumber`, `registerRemitoNumber`, `registerQuoteNumber`,
`registerReturnNumber`, `registerTicketNumber`, `registerOrderNumber`,
`registerPedidoNumber`, `registerBoletaNumber`, `registerScheduleNumber`.
`RegisterService::docNumbers()` expone 6 y `nextDocNumber()` calcula el
siguiente. El problema no es que no exista: es que casi ningún emisor las
consume, y el mecanismo tiene fallas de fondo (ver abajo).

Hay tres mecanismos distintos, ninguno compartido, y cubren 3 documentos de ~13.

| Documento | Tabla | Numeración hoy |
|---|---|---|
| Factura (contado/crédito) | `transaction` (type 0/3) | `MAX(invoiceNo)+1` vía `numbering_lease` |
| Cotización | `transaction` (type 9) | `registerQuoteNumber` + `MAX` |
| Orden | `pos_order` | `MAX(ordernumber)+1` por outlet, advisory lock |
| Nota de crédito | — | **no existe el documento** |
| Nota de débito | — | **no existe el documento** |
| Nota de remisión | — | `SaleType 10` declarado, nada lo emite |
| Recibo (pago de crédito) | `transaction` (type 5) | **sin numeración** |
| Comprobante interno | — | el flag `interno` ni se persiste (`SaleInput` no lo tiene) |
| Producción | `production_order` | **sin numeración** |
| Transferencia de stock | `stock_transfer` | **sin numeración** |
| Conteo de inventario | `inventory_count` | **sin numeración** |
| Movimiento de caja | `fin_movement` | **sin numeración** |
| Merma | `waste_event` | **sin numeración** |
| Compra / gasto (recibido) | `transaction` (type 1/4) | nro. del proveedor; **sin correlativo propio de recepción** |

### Por qué los 9 contadores de `register` no alcanzan

1. **`nextDocNumber()` no reserva.** Es `max(contador guardado, último usado)`
   — pura lectura. El caller tiene que hacer el UPDATE aparte, así que dos
   cajeros concurrentes leen el mismo número. No es atómico pese al comentario
   de mig 26 ("counters atómicos").
2. **No es extensible.** Cada documento nuevo es una columna nueva.
3. **Solo alcanza scope `register`.** Orden va por sucursal y los documentos
   de stock también (decisión del owner) — no se puede expresar en columnas de
   la caja.
4. **Sin rango ni prefijo por documento.** El timbrado tiene inicio y fin.
5. **Mitad están muertas** (boleta, remito, pedido) y las vivas conviven con
   los otros dos mecanismos.

### Por qué `MAX(...)+1` no alcanza

1. **No es un correlativo, es una derivación.** Si se borra la última fila el
   número se reemite. Un correlativo real no puede depender del contenido.
2. **No expresa "arranca en 1234".** Un timbrado autorizado desde 1234 emitía
   desde 1 — fuera del rango. Parcheado con un piso en `register.data`
   (commit `b334095b`), pero es un piso, no una secuencia.
3. **No tiene techo.** El timbrado tiene rango autorizado con FIN; hoy nada
   impide pasarse y emitir fuera de timbrado.
4. **Escanea la tabla.** `MAX()` sobre `transaction` crece con el histórico.

## Arquitectura propuesta

Una tabla de secuencias y **un solo** asignador.

```sql
CREATE TABLE document_sequence (
  sequenceId  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  companyId   UUID        NOT NULL,
  docType     VARCHAR(40) NOT NULL,   -- 'factura' | 'nota_credito' | 'orden' | ...
  scopeType   VARCHAR(20) NOT NULL,   -- 'register' | 'outlet' | 'company'
  scopeId     UUID        NOT NULL,
  nextNumber  BIGINT      NOT NULL DEFAULT 1,
  rangeFrom   BIGINT,                 -- timbrado: inicio autorizado
  rangeTo     BIGINT,                 -- timbrado: fin autorizado (bloquea)
  prefix      VARCHAR(16),            -- EEE-PPP de los fiscales
  UNIQUE (companyId, docType, scopeType, scopeId)
);
```

Asignador único:

```php
DocumentNumber::allocate(string $docType, string $scopeId): int
// UPDATE document_sequence SET nextNumber = nextNumber + 1
//  WHERE ... RETURNING nextNumber - 1
```

`UPDATE ... RETURNING` toma el row lock de PG: es atómico y race-free sin
advisory lock ni `MAX()`. Reemplaza los tres mecanismos actuales.

### Huecos y contención

Llamado **dentro** de la transacción del documento: si la operación falla, el
rollback revierte también el incremento — sin huecos, a costa de serializar
las emisiones concurrentes de esa caja. Una caja es de un cajero por
definición, así que la contención es teórica.

**Excepción: offline.** El POS arrienda números por adelantado
(`numbering_lease`) para poder facturar sin red. Un número arrendado y nunca
consumido ES un hueco, y eso es inherente al modo offline — no lo cambia esta
arquitectura. `numbering_lease` se mantiene como registro auditable de esos
huecos y pasa a alimentarse del asignador en vez de `MAX()`.

## Decisiones

- **D1 — ¿Huecos permitidos?** CERRADA: asignar dentro de la transacción (sin
  huecos), salvo el camino offline, donde son inevitables y quedan auditados
  en `numbering_lease`.
- **D2 — Scope por documento.** CERRADA salvo el financiero:
  - Fiscales (factura, NC, ND, remisión, recibo) → `register` (punto de expedición)
  - Orden → `outlet`
  - Stock (producción, transferencia, conteo, merma) → `outlet`
  - Movimiento financiero → `register` (pedido del owner) — **bloqueado, ver D6**
  - Compra / gasto recibido → `outlet`
- **D6 — Movimientos financieros.** PENDIENTE, dos problemas encadenados:
  1. `fin_movement` NO tiene `registerId` (solo `companyId` + `outletId` +
     `accountId`). Numerar por caja exige agregar la columna; las filas
     históricas quedan en NULL y hay que decidir qué hacer con ellas.
  2. `fin_movement` es el libro financiero completo, no solo el movimiento de
     caja: `source` ∈ manual|sale|purchase|expense|credit_payment|check|
     transfer|opening. Las filas con source sale/purchase son espejos
     automáticos de documentos que YA tienen número propio — numerarlas daría
     dos correlativos al mismo hecho económico. Propuesta: numerar solo los
     que son documento por derecho propio (`manual`, `transfer`, `opening`).
- **D3 — Documentos recibidos.** PENDIENTE. La compra guarda el número del
  proveedor (su correlativo). ¿Se agrega además un correlativo interno de
  recepción? Recomendado sí: es lo que permite auditar cuántos documentos se
  recibieron sin depender de la numeración ajena.
- **D4 — Migración.** CERRADA: `nextNumber` se siembra con el GREATEST de
  todas las fuentes que hoy conviven, para no reemitir un número ya usado:
  el `MAX` real por scope, el contador legacy de `register` correspondiente,
  el `MAX` de `numbering_lease` (facturas) y el piso de
  `register.data.registerNumbering`. Los 9 contadores legacy y el piso quedan
  obsoletos y se retiran en F2 — la tabla los subsume.
- **D5 — Fin de rango del timbrado.** PENDIENTE. ¿Bloquear la emisión al
  llegar a `rangeTo`, o alertar al acercarse y bloquear recién en el límite?

## Fases

- **F1** — mig `document_sequence` + `DocumentNumber` + backfill desde los MAX
  actuales. Sin cambio de comportamiento observable.
- **F2** — migrar los 3 emisores existentes (factura, cotización, orden) al
  asignador. Retirar `registerQuoteNumber` y el piso de `register.data`.
- **F3** — numerar los documentos internos de stock, todos scope `outlet`
  (producción, transferencia, conteo, merma). No depende de D6.
- **F3b** — movimientos financieros, una vez resuelta D6 (requiere agregar
  `registerId` a `fin_movement` si se confirma el scope por caja).
- **F4** — rango del timbrado (`rangeFrom`/`rangeTo`) + bloqueo/alerta, y UI
  de administración por caja.
- **F5** — documentos que todavía no existen: NC, ND, remisión, comprobante
  interno, recibo. Acopladas al pedido de anulaciones/devoluciones del tester.

## Notas

- `SaleType` (`api/lib/Sales/SaleType.php`) es la fuente de verdad de los tipos
  de transacción — `SaleInput::fromPayload` valida contra él. No duplicar el
  mapeo en literales. `TX_TYPE_LABELS` (front) es una segunda copia, solo de
  labels; si se toca, unificar.
- El `docType` de esta tabla NO es `SaleType`: varios documentos no son
  transacciones (producción, transferencia, conteo). Son dimensiones distintas.
