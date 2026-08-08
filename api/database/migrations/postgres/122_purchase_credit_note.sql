-- 122_purchase_credit_note.sql
-- Notas de crédito de compra (total o parcial): nuevo transactionType=14
-- (SaleType::PurchaseCreditNote). Documento hermano de la compra (types 1/4)
-- que revierte total o parcialmente sus itemSold (unidades negativas) y,
-- según refundMode, mueve caja ('cash' → FinanceLedger::recordPurchaseCreditNote)
-- o reduce el saldo pendiente de una compra a crédito ('credit' — mismo camino
-- que ya usan los pagos a proveedor: transaction_link + payedByParent()).
--
-- Este mig SOLO extiende el CHECK de transaction_link.kind (mig 115) para
-- aceptar 'purchase_credit_note'. No hay tablas nuevas: la NC vive en
-- `transaction`/`itemSold` como cualquier otro documento del dominio — mismo
-- criterio que `return` (mig 115) para las devoluciones de venta.
--
-- Convenciones de deploy (precedente migs 74/77 que tiraron todos los
-- deploys en prod): PROHIBIDO el operador `?`/`?|`/`?&` de jsonb (el wrapper
-- PDO lo reescribe a placeholder y rompe el boot) — no aplica acá, no hay
-- jsonb en este mig. Todo idempotente y re-corrible: el DO block localiza el
-- CHECK constraint de `kind` por su DEFINICIÓN (no por el nombre asumido
-- `transaction_link_kind_check` que Postgres le puso en la mig 115), así que
-- corre limpio tanto en la primera corrida como en cualquier re-run,
-- independientemente de si el nombre real coincide con el asumido.

BEGIN;

DO $$
DECLARE
  existing_check text;
BEGIN
  SELECT con.conname INTO existing_check
    FROM pg_constraint con
    JOIN pg_class rel ON rel.oid = con.conrelid
   WHERE rel.relname = 'transaction_link'
     AND con.contype = 'c'
     AND pg_get_constraintdef(con.oid) ILIKE '%kind%IN%';

  IF existing_check IS NOT NULL THEN
    EXECUTE format('ALTER TABLE transaction_link DROP CONSTRAINT %I', existing_check);
  END IF;
END $$;

ALTER TABLE transaction_link
  ADD CONSTRAINT transaction_link_kind_check
  CHECK (kind IN (
    'quote_to_sale', 'credit_payment', 'purchase_payment',
    'return', 'package_session', 'table_merge', 'purchase_credit_note'
  ));

COMMIT;
