-- 216_fix_comment_nc_serie.sql
-- Corrige el número de migración citado en el COMMENT del índice de unicidad
-- fiscal de la nota de crédito.
--
-- El índice nació en un archivo llamado `213_nota_credito_serie.sql` y su
-- COMMENT quedó diciendo "(mig 213)". Ese archivo se renumeró a 215 antes de
-- aplicarse, porque el 213 ya estaba ocupado por la migración del conteo de
-- stock. La referencia sobrevivió al renombre.
--
-- No es cosmética: los COMMENT de este módulo son el lugar donde está escrito
-- POR QUÉ existe cada invariante fiscal, y se leen con `\d+` cuando alguien
-- audita el schema. Un puntero a una migración que habla de otra cosa manda a
-- la próxima sesión al archivo equivocado.
BEGIN;

COMMENT ON INDEX uq_transaction_creditnote_invoiceno IS
  'Unicidad fiscal del numero de NOTA DE CREDITO, POR SERIE (mig 215). '
  'Gemelo disjunto de uq_transaction_expedition_invoiceno: aquel cubre la '
  'FACTURA (transactiontype 0/3), este la NC (6). Separados porque factura y '
  'NC son dos talonarios bajo el MISMO timbrado y punto, asi que comparten '
  'espacio de numeros sin colisionar entre si. Parcial sobre invoiceprefix '
  'no vacio: un comercio sin facturacion electronica numera sus devoluciones '
  'sin serie fiscal y no puede quedar bloqueado por una regla fiscal.';

COMMIT;
