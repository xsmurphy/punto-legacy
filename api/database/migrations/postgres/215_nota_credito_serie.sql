-- 215_nota_credito_serie.sql
-- La NOTA DE CRÉDITO entra al modelo de series fiscales (context/40 F3).
--
-- ── Qué cambia ──────────────────────────────────────────────────────────
-- Hasta hoy el número de la nota de crédito lo ponía el proveedor: el mapper
-- omitía el campo `numero` para el tipo 5, el guard del CDC se saltaba la
-- comprobación del número y `assertNumberingCoherence()` salía por un return
-- temprano. Eso contradice la regla del owner: **Punto es dueño de la
-- numeración fiscal**. Desde este commit la NC tiene la MISMA identidad que
-- la factura — `(timbrado, punto de expedición, correlativo)` — asignada por
-- `DocumentNumber::allocate('nota_credito', SCOPE_REGISTER, …)` dentro de la
-- transacción de la devolución y congelada en su fila de `transaction`.
--
-- ── De dónde sale el punto de expedición ────────────────────────────────
-- **La NC hereda la CAJA de la factura que corrige** (decisión del owner
-- 2026-09-09). `C005 dEst` y `C006 dPunExp` son obligatorios 1-1 para TODO
-- documento electrónico (Manual Técnico DNIT v150, grupo C) y entran al CDC
-- de la NC igual que al de la factura; SIFEN la vincula a su factura por el
-- CDC del documento asociado, no exigiendo que el punto coincida. Heredar la
-- caja es entonces una decisión NUESTRA de coherencia, y de paso elimina el
-- fallback que adivinaba el punto ("primera caja activa por nombre") cuando
-- la devolución salía del panel.
--
-- ── Terreno limpio: NO hay historia fiscal que preservar ────────────────
-- Verificado en la base de PRODUCCIÓN antes de escribir esta migración:
--
--     notas de crédito en el outbox `einvoice_document`  : 0
--     notas de crédito emitidas                          : 0
--     devoluciones (transactiontype = 6) con `invoiceno` : 0
--     secuencias `doctype = 'nota_credito'`              : NINGUNA
--
-- O sea que las series de NC nacen desde cero. Esta migración NO reconstruye
-- nada, no backfillea correlativos y no tiene que arrancar por encima de
-- ningún número ya usado: la primera NC de cada caja será la número 1 de su
-- serie, que es exactamente lo correcto para una serie recién abierta.
--
-- Por la misma razón NO se tocan las filas viejas de `document_sequence` con
-- `doctype = 'nota_credito'` y `scopetype = 'outlet'`: en producción no
-- existen, y en una base de desarrollo son datos de prueba. Borrarlas sería
-- un DELETE sin necesidad; quedan inertes (nadie las lee más) en vez de
-- arriesgar un borrado en una migración que corre al arrancar el contenedor.
--
-- ── Por qué el SCHEMA casi no cambia ────────────────────────────────────
-- La mig 209 ya dejó el mecanismo genérico: `document_sequence` lleva
-- `invoiceauth` + `prefix` EN LA CLAVE ÚNICA, y sumar un doctype a la serie
-- es pasarle una `DocumentSeries` en su call-site. Lo único que falta en la
-- base es la unicidad fiscal del número de la NC.

BEGIN;

-- ═══════════════════════════════════════════════════════════════════════
-- Unicidad fiscal del número de la nota de crédito
-- ═══════════════════════════════════════════════════════════════════════
-- Dos notas de crédito con el mismo número DENTRO de la misma serie son un
-- documento duplicado ante la SET. Entre series distintas conviven, igual que
-- `001-001-0000838` y `001-002-0000838` en la factura (context/29 §2).
--
-- ── Por qué un índice APARTE y no ampliar el de la factura ─────────────
-- `uq_transaction_expedition_invoiceno` (mig 209) es
-- `(companyid, registerid, invoiceauth, invoiceprefix, invoiceno)` con
-- `WHERE transactiontype IN (0, 3)`. Sumarle el tipo 6 haría chocar la
-- factura 001-001-0000001 con la NC 001-001-0000001, que son dos documentos
-- legales y distintos (talonarios distintos bajo el mismo timbrado). Y
-- meter `transactiontype` en la clave sería peor: dejaría que una venta al
-- contado (0) y una a crédito (3) duplicaran el mismo número de factura, que
-- es justo lo que ese índice impide. Dos índices parciales DISJUNTOS sobre la
-- misma tabla resuelven las dos unicidades sin que ninguna debilite a la otra.
--
-- Tampoco se conserva el nombre del otro: `SaleService::abortSale()` clasifica
-- el choque fiscal de la VENTA (409 / `NUMBER_TAKEN`, para que la cola offline
-- renumere) buscando el nombre EXACTO de aquel índice en el texto del error de
-- PG. Un nombre propio acá deja esa clasificación intacta.
--
-- ── Por qué NO lleva `registerid` ──────────────────────────────────────
-- Porque en una NC `registerid` es la caja donde se OPERÓ la devolución
-- (NULL cuando sale del panel), no la caja de la que hereda la serie. La
-- serie viaja congelada en `invoiceauth`/`invoiceprefix`, que es la identidad
-- fiscal real y además no puede ser NULL de forma útil: en un índice único de
-- Postgres `NULL <> NULL`, así que una clave con `registerid` NULL no
-- enforcearía nada justamente en el caso —la NC del panel— que este trabajo
-- viene a ordenar.
--
-- ── Por qué solo cuando HAY punto de expedición ────────────────────────
-- Un comercio sin facturación electrónica no tiene timbrado ni punto en sus
-- cajas: sus devoluciones se numeran igual (una secuencia por caja, como
-- cualquier documento no fiscal) y quedan con la serie vacía. Sin el filtro,
-- todas esas NC colapsarían a la clave (companyid, '', '', invoiceno) y la
-- segunda caja del comercio no podría devolver — un índice fiscal rompiendo
-- una operación no fiscal. La unicidad de un número de comprobante solo
-- significa algo DENTRO de una serie declarada, así que se enforcea ahí.
--
-- No puede fallar por duplicados: en producción no hay ni una fila que
-- califique (cero devoluciones con `invoiceno`).
CREATE UNIQUE INDEX IF NOT EXISTS uq_transaction_creditnote_invoiceno
  ON transaction_registry (companyid, COALESCE(invoiceauth, ''), COALESCE(invoiceprefix, ''), invoiceno)
  WHERE invoiceno IS NOT NULL
    AND transactiontype = 6
    AND COALESCE(invoiceprefix, '') <> '';

COMMENT ON INDEX uq_transaction_creditnote_invoiceno IS
  'Unicidad fiscal del numero de NOTA DE CREDITO, POR SERIE (mig 215). '
  'Gemelo disjunto de uq_transaction_expedition_invoiceno: aquel cubre la '
  'FACTURA (transactiontype 0/3), este la NC (6). Separados porque factura y '
  'NC son dos talonarios bajo el MISMO timbrado y punto, asi que comparten '
  'espacio de numeros sin colisionar entre si. Parcial sobre invoiceprefix '
  'no vacio: un comercio sin facturacion electronica numera sus devoluciones '
  'sin serie fiscal y no puede quedar bloqueado por una regla fiscal.';

COMMIT;
