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

-- Localiza los CHECK de `kind` por la COLUMNA que referencian (con.conkey), no
-- por el texto de su definición: Postgres normaliza `kind IN (...)` a
-- `kind = ANY (ARRAY[...])` al guardarlo, así que buscar '%kind%IN%' no
-- matcheaba nunca — ni siquiera en la primera corrida — y el ADD de abajo
-- chocaba contra el nombre que la mig 115 ya había autogenerado
-- (`transaction_link_kind_check`), tirando todos los deploys.
-- `chk_transaction_link_no_self` no se toca: referencia originid/derivedid.
DO $$
DECLARE
  existing_check text;
BEGIN
  FOR existing_check IN
    SELECT con.conname
      FROM pg_constraint con
      JOIN pg_class rel ON rel.oid = con.conrelid
      JOIN pg_attribute att ON att.attrelid = rel.oid AND att.attnum = ANY (con.conkey)
     WHERE rel.relname = 'transaction_link'
       AND con.contype = 'c'
       AND att.attname = 'kind'
  LOOP
    EXECUTE format('ALTER TABLE transaction_link DROP CONSTRAINT %I', existing_check);
  END LOOP;
END $$;

ALTER TABLE transaction_link
  ADD CONSTRAINT transaction_link_kind_check
  CHECK (kind IN (
    'quote_to_sale', 'credit_payment', 'purchase_payment',
    'return', 'package_session', 'table_merge', 'purchase_credit_note'
  ));

COMMIT;
