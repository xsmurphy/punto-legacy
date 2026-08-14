# 40 — Anulación y Nota de Crédito

> Estado: **plan abierto** (2026-08-14). Pedido del owner desde `/pos` → detalle
> de transacción: los botones "Anular" y "Devolución" están deshabilitados.
> Bloqueado por D1–D4 (abajo). Nada implementado todavía.

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

## Decisiones pendientes del owner

- **D1 — ¿La nota de crédito puede ser parcial?** ¿Se devuelven ítems sueltos y
  cantidades parciales, o siempre es por el total de la factura? Cambia el
  modelo: parcial exige detalle propio de la NC (qué ítems, cuántas unidades) y
  permite varias NC contra la misma factura; total puede derivarse de la
  original.
- **D2 — ¿La mercadería devuelta vuelve al stock?** Siempre, nunca, o se elige
  por ítem al hacer la devolución. Un producto devuelto por fallado no vuelve a
  estar disponible para vender.
- **D3 — ¿La NC devuelve dinero o deja saldo a favor?** Salida de caja en el
  momento, o crédito del cliente para descontar en la próxima compra. Si es
  salida de caja, ¿de qué caja sale si la venta fue en otro turno?
- **D4 — ¿Hasta cuándo se puede anular?** La cancelación de SIFEN tiene plazo
  (48 h desde la emisión). Pasado ese plazo, ¿se bloquea la anulación y se
  obliga a nota de crédito? Es la práctica correcta, pero hay que confirmarlo.

## Fases propuestas

- **F1** — Anulación interna: estado sobre la venta, reverso de stock, reverso
  del movimiento financiero, exclusión de reportes. Sin FE.
- **F2** — Anulación integrada con FE: dispara `EInvoiceService::cancel()`
  cuando el tenant la tiene. El corte por plazo depende de D4.
- **F3** — Numeración de NC: doctype `nota_credito`, rango de timbrado propio
  por caja, UI en el tab Cajas.
- **F4** — Nota de crédito interna: documento, detalle, vínculo con la original,
  reverso de stock y plata. Depende de D1, D2 y D3.
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
