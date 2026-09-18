-- 234_wallet_caja.sql — Wallet F2: la caja (context/74 §12, D12-D15).
--
-- Dos cosas de schema, las dos chicas:
--
-- 1. `wallet_pocket.taxid` — el IVA de la CARGA.
--    La carga es una venta con su factura (modo A, §4) y esa factura necesita
--    una tasa. No sale del producto que se va a consumir (todavía no se sabe
--    cuál es): sale del BOLSILLO. §4 ya lo recomendaba — "conviene que cada
--    bolsillo agrupe productos de una misma tasa" —, así que la tasa es un
--    atributo del bolsillo y no una elección del cajero en cada carga.
--
--    Default = el impuesto por defecto del comercio, que en Punto es el
--    PRIMERO del catálogo en su orden (`sortorder NULLS LAST, name`, el mismo
--    orden de `TaxService::list()` y el que usa el alta de compras para
--    preseleccionar). No hay columna "es default": inventarla acá sería una
--    segunda fuente de verdad del orden del catálogo.
--
--    NULL = el comercio no tiene impuestos cargados → la carga sale exenta,
--    mismo criterio que un ítem sin impuesto (`enrichWithTaxes`).
--
--    FK con RESTRICT, no SET NULL: borrar el impuesto de un bolsillo en uso
--    convertiría en silencio sus cargas futuras en exentas — un cambio FISCAL
--    que nadie decidió. `TaxService::delete()` lo ataja antes con un mensaje.
--
-- 2. `item.systemkey` — el ítem de sistema "Carga de saldo".
--    La línea de una carga necesita un ítem (la factura, los reportes por
--    producto y el detalle de la venta hablan de ítems). Es UNO por comercio,
--    lo crea el servidor la primera vez que hace falta
--    (`WalletLoadItem::ensure()`), no se edita ni se borra desde el panel, no
--    lleva stock y no se ofrece en ninguna caja (sin filas en `item_outlet`,
--    que es exactamente "no visible para ninguna sucursal" — mig 170).
--
--    Columna real y no una clave en `data` (el precedente de
--    `data->>'saasPlanCode'`): hay que poder ENCONTRARLO sin escanear el
--    JSONB y hay que GARANTIZAR que es uno solo por comercio aun con dos
--    ventas creándolo a la vez — eso es un índice único, y un índice único
--    sobre una clave de JSONB schemaless es un invariante escondido.

BEGIN;

-- ── 1. Tasa del bolsillo ────────────────────────────────────────────────────

ALTER TABLE wallet_pocket
  ADD COLUMN IF NOT EXISTS taxid UUID;

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'wallet_pocket_taxid_fkey'
  ) THEN
    ALTER TABLE wallet_pocket
      ADD CONSTRAINT wallet_pocket_taxid_fkey
      FOREIGN KEY (taxid) REFERENCES tax(taxid) ON DELETE RESTRICT;
  END IF;
END $$;

-- Backfill: los bolsillos que ya existen (F1) toman el impuesto por defecto de
-- su comercio. Solo los que no tienen: re-correr no pisa una elección.
UPDATE wallet_pocket p
   SET taxid = (
         SELECT t.taxid FROM tax t
          WHERE t.companyid = p.companyid
          ORDER BY t.sortorder NULLS LAST, t.name
          LIMIT 1
       )
 WHERE p.taxid IS NULL;

COMMENT ON COLUMN wallet_pocket.taxid IS
  'Wallet F2: impuesto de la factura de CARGA de este bolsillo (context/74 §4). '
  'NULL = sin impuesto (exenta).';

-- El impuesto tiene que ser del MISMO comercio que el bolsillo. No entra en
-- una FK (tax no tiene UNIQUE (taxid, companyid)), así que va en un trigger.
CREATE OR REPLACE FUNCTION wallet_pocket_tax_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.taxid IS NOT NULL AND NOT EXISTS (
       SELECT 1 FROM tax WHERE taxid = NEW.taxid AND companyid = NEW.companyid
     ) THEN
    RAISE EXCEPTION 'wallet_pocket: el impuesto % no es de este comercio', NEW.taxid
      USING ERRCODE = 'check_violation';
  END IF;
  RETURN NEW;
END $$;

DROP TRIGGER IF EXISTS trg_wallet_pocket_tax_guard ON wallet_pocket;
CREATE TRIGGER trg_wallet_pocket_tax_guard
  BEFORE INSERT OR UPDATE OF taxid ON wallet_pocket
  FOR EACH ROW EXECUTE FUNCTION wallet_pocket_tax_guard();

-- ── 2. Ítem de sistema ──────────────────────────────────────────────────────

ALTER TABLE item
  ADD COLUMN IF NOT EXISTS systemkey VARCHAR(40);

-- Uno por comercio y clave. Parcial: los ítems del comercio (NULL) no cuentan.
CREATE UNIQUE INDEX IF NOT EXISTS uidx_item_systemkey
    ON item (companyid, systemkey)
 WHERE systemkey IS NOT NULL;

COMMENT ON COLUMN item.systemkey IS
  'Ítem creado y administrado por Punto, no por el comercio (hoy solo '
  '''wallet_load'', context/74 F2). No se edita ni se borra desde el panel y '
  'no se lista en el catálogo. NULL = ítem del comercio.';

COMMIT;
