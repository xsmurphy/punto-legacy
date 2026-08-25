-- =============================================================
-- SEED: fixtures del arnés de verificación end-to-end del proceso de
-- venta (ver verify_chain/README.md).
--
-- Dos tenants aislados, cada uno con impuestos e items propios — el
-- runner PHP (run_sale_chain.php) los usa para vender vía SaleService
-- real y comparar lo persistido contra valores calculados a mano en
-- fixtures.json. Los itemSKU son slugs estables que el runner resuelve
-- a UUID por nombre (no hardcodea UUIDs en las cases).
--
-- UUIDs fijos pero generados random (uuidgen), no enumerables —
-- mismo criterio que api/database/seeds/postgres/02_sample_company.sql.
--
-- Idempotente: todo INSERT tiene ON CONFLICT DO UPDATE/NOTHING, se puede
-- re-correr sobre la misma base sin duplicar.
--
-- Company A — "Verify PY" — decimals=0, PYG, tasas 10/5/0(real)/exenta,
-- pensada para ejercitar también EInvoice/SIFEN (solo admite 10|5|0).
-- Company B — "Verify MX" — decimals=2, tasa 16% + exenta.
-- =============================================================

-- ── Company A: Verify PY (decimals=0) ───────────────────────────────
INSERT INTO company (companyId, status, plan, balance, isParent, config) VALUES (
    '0ea6c5d8-57e5-4226-8140-ec914deec024', 'active', 1, 0.00, FALSE,
    '{
        "settingName":"Verify PY",
        "settingDecimal":"no",
        "settingThousandSeparator":"dot",
        "settingCountry":"PY",
        "settingCurrency":"PYG",
        "settingTimeZone":"America/Asuncion",
        "settingTaxName":"IVA",
        "settingLanguage":"es",
        "settingSocialMedia":"{}",
        "settingObj":"{}"
    }'::jsonb
) ON CONFLICT (companyId) DO UPDATE SET status = EXCLUDED.status, config = EXCLUDED.config;

INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (
    '1a282724-6073-49c3-8bc3-0114a132e349', 'Verify PY - Sucursal', 1,
    '0ea6c5d8-57e5-4226-8140-ec914deec024'
) ON CONFLICT (outletId) DO UPDATE SET outletName = EXCLUDED.outletName;

INSERT INTO register (
    registerid, registername, registerstatus,
    registerinvoicenumber, registerticketnumber, registerreturnnumber,
    registerschedulenumber, registerpedidonumber, registerquotenumber,
    outletid, companyid
) VALUES (
    '81c541da-640e-4891-a1a0-b32841e64c75', 'Verify PY - Caja', TRUE,
    1, 1, 1, 1, 1, 1,
    '1a282724-6073-49c3-8bc3-0114a132e349', '0ea6c5d8-57e5-4226-8140-ec914deec024'
) ON CONFLICT (registerid) DO UPDATE SET registername = EXCLUDED.registername;

-- Depósito por defecto — la cadena Company > Sucursal > (Depósito | Caja) es
-- OBLIGATORIA (context/08). Este seed carga DESPUÉS de las migraciones, así
-- que el backfill de la mig 165 no lo alcanza: si la sucursal no trae su
-- depósito acá, `outlet_chain_invariant_test.php` la marca como rota.
-- `WHERE NOT EXISTS` para no chocar con `uq_taxonomy_location_default`.
INSERT INTO taxonomy (taxonomyId, companyId, taxonomyType, outletId, taxonomyName, taxonomyExtra)
SELECT 'b41a2f30-7c5d-4e18-9a26-1d3f5b7c9e02',
       '0ea6c5d8-57e5-4226-8140-ec914deec024',
       'location',
       '1a282724-6073-49c3-8bc3-0114a132e349',
       'Verify PY - Depósito',
       '{"isDefault": true}'
 WHERE NOT EXISTS (
     SELECT 1 FROM taxonomy
      WHERE outletId = '1a282724-6073-49c3-8bc3-0114a132e349'
        AND taxonomyType = 'location'
 );

INSERT INTO contact (
    contactId, contactName, contactPhone, contactEmail, contactStatus, type, main, role, outletId, companyId
) VALUES (
    '3e52da17-74a2-49c3-9d07-8d4806671fd5', 'Verify PY Admin', '+595991000001', 'verify-py@local.test',
    1, 0, 'admin', 1, '1a282724-6073-49c3-8bc3-0114a132e349', '0ea6c5d8-57e5-4226-8140-ec914deec024'
) ON CONFLICT (contactId) DO UPDATE SET contactName = EXCLUDED.contactName;

-- Cliente (type=1) SIN crédito habilitado (contactCreditable ausente/NULL,
-- falsy en `(int)($row.contactCreditable ?? 0) > 0`). Usado por
-- run_sale_chain.php (`verifyCreditNonCreditableClientPersists`) para
-- probar el invariante de context/08 §53: una venta a crédito de este
-- cliente tiene que PERSISTIR, no ser rechazada por SaleService::save() —
-- esa regla se valida al emitir en el POS (pay-dialog.tsx), no al recibir.
INSERT INTO contact (
    contactId, contactName, contactPhone, contactEmail, contactStatus, type, main, role, outletId, companyId
) VALUES (
    '2b9f6a71-3e2b-4b34-9b5a-7a6a6a6a6a6a', 'Verify PY Cliente sin credito', '+595991000002', 'verify-py-nocredit@local.test',
    1, 1, '', 0, '1a282724-6073-49c3-8bc3-0114a132e349', '0ea6c5d8-57e5-4226-8140-ec914deec024'
) ON CONFLICT (contactId) DO UPDATE SET contactName = EXCLUDED.contactName;

-- Tasas: 10%, 5%, 0% real (kind=rate, distinto de exenta), exenta, y una
-- tasa que SIFEN NO admite (21%) para el caso de rechazo de facturación.
INSERT INTO tax (taxId, companyId, name, rate, kind) VALUES
    ('3cf780bb-51d6-4b41-b52d-1e77bfb60969', '0ea6c5d8-57e5-4226-8140-ec914deec024', '10', 10, 'rate'),
    ('c5f98ab9-9622-446e-9e90-ffb103309828', '0ea6c5d8-57e5-4226-8140-ec914deec024', '5',  5,  'rate'),
    ('2e14f50c-30d2-4fe1-a141-241641a8ae17', '0ea6c5d8-57e5-4226-8140-ec914deec024', '0',  0,  'rate'),
    ('e16ad2ce-db03-48e3-81d1-653df1c1ab11', '0ea6c5d8-57e5-4226-8140-ec914deec024', 'Exenta', 0, 'exempt'),
    ('90b39d98-ddfb-4909-b783-70277d5105f7', '0ea6c5d8-57e5-4226-8140-ec914deec024', '21', 21, 'rate')
ON CONFLICT (taxId) DO UPDATE SET rate = EXCLUDED.rate, kind = EXCLUDED.kind, name = EXCLUDED.name;

-- Items: itemPrice de catálogo es decorativo (la venta manda su propio
-- precio de línea) salvo en VERIFY-10-PRICEMOD, donde a propósito difiere
-- del precio que manda la venta — ejercita "precio modificado en la línea".
-- itemTrackInventory=FALSE en todos: no hace falta inventario para el
-- chequeo fiscal (manageStock no-opea sobre items no stockeables).
INSERT INTO item (itemid, itemname, itemsku, itemprice, itemtype, itemstatus, itemcansale, itemtrackinventory, taxid, data, companyid, itemkind) VALUES
    ('10223f3b-2e3d-4339-8496-9f288d8be65b', 'Verify 10% incluido',      'VERIFY-10-INC',         11000, 'product', 1, TRUE, FALSE, '3cf780bb-51d6-4b41-b52d-1e77bfb60969', '{"itemTaxIncluded": true}'::jsonb,  '0ea6c5d8-57e5-4226-8140-ec914deec024', 'producto'),
    ('61230b1e-90e8-4018-ac59-865ca957b293', 'Verify 5% añadido',        'VERIFY-5-ADD',           5000, 'product', 1, TRUE, FALSE, 'c5f98ab9-9622-446e-9e90-ffb103309828', '{"itemTaxIncluded": false}'::jsonb, '0ea6c5d8-57e5-4226-8140-ec914deec024', 'producto'),
    ('d4f2c9b6-baa0-4415-9f27-1f0e791c4df1', 'Verify 0% real añadido',   'VERIFY-0RATE-ADD',       8000, 'product', 1, TRUE, FALSE, '2e14f50c-30d2-4fe1-a141-241641a8ae17', '{"itemTaxIncluded": false}'::jsonb, '0ea6c5d8-57e5-4226-8140-ec914deec024', 'producto'),
    ('dca3b670-60a6-4b81-a7d7-7e39a1f7cc30', 'Verify exenta incluido',   'VERIFY-EXEMPT-INC',      6000, 'product', 1, TRUE, FALSE, 'e16ad2ce-db03-48e3-81d1-653df1c1ab11', '{"itemTaxIncluded": true}'::jsonb,  '0ea6c5d8-57e5-4226-8140-ec914deec024', 'producto'),
    ('0e15e0bb-2892-404a-b2a4-78e769bee4a4', 'Verify 10% cantidad decimal', 'VERIFY-10-DECIMALQTY', 4000, 'product', 1, TRUE, FALSE, '3cf780bb-51d6-4b41-b52d-1e77bfb60969', '{"itemTaxIncluded": false}'::jsonb, '0ea6c5d8-57e5-4226-8140-ec914deec024', 'producto'),
    ('3334b924-7947-427a-88f5-b6987528703a', 'Verify 10% precio modificado', 'VERIFY-10-PRICEMOD', 50000, 'product', 1, TRUE, FALSE, '3cf780bb-51d6-4b41-b52d-1e77bfb60969', '{"itemTaxIncluded": true}'::jsonb,  '0ea6c5d8-57e5-4226-8140-ec914deec024', 'producto'),
    ('5c835556-d25d-4258-861d-599ef3edae90', 'Verify 21% no admitido SIFEN', 'VERIFY-BAD21',         1000, 'product', 1, TRUE, FALSE, '90b39d98-ddfb-4909-b783-70277d5105f7', '{"itemTaxIncluded": false}'::jsonb, '0ea6c5d8-57e5-4226-8140-ec914deec024', 'producto')
ON CONFLICT (itemid) DO UPDATE SET itemname = EXCLUDED.itemname, itemprice = EXCLUDED.itemprice, taxid = EXCLUDED.taxid, data = EXCLUDED.data;

-- Items stockeables (itemtrackinventory=TRUE) — usados por verify_realtime.php
-- para ejercitar Inventory::manageStock() de verdad (los items de arriba son
-- todos itemtrackinventory=FALSE a propósito, no sirven para eso). Dos items
-- (no uno) para poder probar el batching de ids con ítems DISTINTOS en el
-- mismo request, no solo dedup del mismo id — ver context/15-realtime-sync-plan.md.
INSERT INTO item (itemid, itemname, itemsku, itemprice, itemtype, itemstatus, itemcansale, itemtrackinventory, taxid, data, companyid, itemkind) VALUES
    ('7a1c1a9e-3b1a-4e7b-8f7a-9a2b8c1d4e5f', 'Verify stock trackeable', 'VERIFY-STOCK-TRACK', 1000, 'product', 1, TRUE, TRUE, '3cf780bb-51d6-4b41-b52d-1e77bfb60969', '{}'::jsonb, '0ea6c5d8-57e5-4226-8140-ec914deec024', 'producto'),
    ('7a1c1a9e-3b1a-4e7b-8f7a-9a2b8c1d4e60', 'Verify stock trackeable 2', 'VERIFY-STOCK-TRACK-2', 1500, 'product', 1, TRUE, TRUE, '3cf780bb-51d6-4b41-b52d-1e77bfb60969', '{}'::jsonb, '0ea6c5d8-57e5-4226-8140-ec914deec024', 'producto')
ON CONFLICT (itemid) DO UPDATE SET itemtrackinventory = EXCLUDED.itemtrackinventory;

-- Items del sync incremental (context/43-sync-incremental.md, verify_sync.php):
-- uno se UPDATEa en vivo (demuestra que el delta trae SOLO el modificado, no
-- el catálogo entero) y el otro se archiva + hard-delete (demuestra que el
-- id borrado aparece en `deletedIds` vía la lápida de mig 138). itemstatus=1
-- de arranque — el script hace el archive/delete real.
INSERT INTO item (itemid, itemname, itemsku, itemprice, itemtype, itemstatus, itemcansale, itemtrackinventory, taxid, data, companyid, itemkind) VALUES
    ('9b2e6a1c-4f3d-4a5b-8c6d-1e2f3a4b5c6d', 'Verify sync modificable', 'VERIFY-SYNC-MODIFY', 2000, 'product', 1, TRUE, FALSE, '3cf780bb-51d6-4b41-b52d-1e77bfb60969', '{}'::jsonb, '0ea6c5d8-57e5-4226-8140-ec914deec024', 'producto'),
    ('9b2e6a1c-4f3d-4a5b-8c6d-1e2f3a4b5c6e', 'Verify sync borrable', 'VERIFY-SYNC-DELETE', 3000, 'product', 1, TRUE, FALSE, '3cf780bb-51d6-4b41-b52d-1e77bfb60969', '{}'::jsonb, '0ea6c5d8-57e5-4226-8140-ec914deec024', 'producto')
ON CONFLICT (itemid) DO UPDATE SET itemstatus = 1, itemprice = EXCLUDED.itemprice;

-- Item con add-ons (context/08 §53, hueco P0 offline cerrado 2026-08-16,
-- verify_offline_resolution.php): grupo OBLIGATORIO (minSelect=1) — el caso
-- que el hueco dejaba invendible sin conexión, porque el modal no podía
-- resolver el grupo. La opción reusa VERIFY-5-ADD como producto real
-- (`addon_group_option.itemId` → item, no un texto suelto).
INSERT INTO item (itemid, itemname, itemsku, itemprice, itemtype, itemstatus, itemcansale, itemtrackinventory, taxid, data, companyid, itemkind) VALUES
    ('9b2e6a1c-4f3d-4a5b-8c6d-1e2f3a4b5c7a', 'Verify item con add-ons', 'VERIFY-ADDON-PARENT', 15000, 'product', 1, TRUE, FALSE, '3cf780bb-51d6-4b41-b52d-1e77bfb60969', '{}'::jsonb, '0ea6c5d8-57e5-4226-8140-ec914deec024', 'producto')
ON CONFLICT (itemid) DO UPDATE SET itemname = EXCLUDED.itemname;

INSERT INTO "addon_group" (groupid, companyid, itemid, "name", minselect, maxselect, "sort", "status") VALUES
    ('9b2e6a1c-4f3d-4a5b-8c6d-1e2f3a4b5c7b', '0ea6c5d8-57e5-4226-8140-ec914deec024', '9b2e6a1c-4f3d-4a5b-8c6d-1e2f3a4b5c7a', 'Tamaño (obligatorio)', 1, 1, 0, TRUE)
ON CONFLICT (groupid) DO UPDATE SET "name" = EXCLUDED."name", minselect = EXCLUDED.minselect;

INSERT INTO "addon_group_option" (optionid, groupid, itemid, pricedelta, isdefault, islocked, maxqty, "sort") VALUES
    ('9b2e6a1c-4f3d-4a5b-8c6d-1e2f3a4b5c7c', '9b2e6a1c-4f3d-4a5b-8c6d-1e2f3a4b5c7b', '61230b1e-90e8-4018-ac59-865ca957b293', 2000, TRUE, FALSE, 1, 0)
ON CONFLICT (optionid) DO UPDATE SET pricedelta = EXCLUDED.pricedelta;

-- Add-on que DESCUENTA STOCK (verify_addon_stock.php). Fixtures propias, no
-- se reusa el par de arriba: aquella opción apunta a un ítem sin control de
-- inventario y su grupo es obligatorio con maxQty=1, así que no sirve para
-- probar ni el descuento ni una opción repetida. Acá:
--
--   VERIFY-ADDON-STOCK-PARENT (15.000, sin stock propio)
--     └── grupo OPCIONAL (minSelect=0, maxSelect=2)
--           └── opción → VERIFY-ADDON-STOCK-OPT (trackeable, maxQty=3, +2.000)
--
-- El ítem de la opción es `itemtrackinventory=TRUE` porque el punto del
-- arnés es justamente ese: que vender el padre con la opción mueva el ledger
-- de stock de la OPCIÓN. Un ítem propio (y no VERIFY-STOCK-TRACK) para que
-- los movimientos no se mezclen con los de verify_realtime.php.
INSERT INTO item (itemid, itemname, itemsku, itemprice, itemtype, itemstatus, itemcansale, itemtrackinventory, taxid, data, companyid, itemkind) VALUES
    ('c1a2b3c4-d5e6-4f70-8a91-b2c3d4e5f601', 'Verify addon padre con stock', 'VERIFY-ADDON-STOCK-PARENT', 15000, 'product', 1, TRUE, FALSE, '3cf780bb-51d6-4b41-b52d-1e77bfb60969', '{}'::jsonb, '0ea6c5d8-57e5-4226-8140-ec914deec024', 'producto'),
    ('c1a2b3c4-d5e6-4f70-8a91-b2c3d4e5f602', 'Verify addon opción trackeable', 'VERIFY-ADDON-STOCK-OPT', 2000, 'product', 1, TRUE, TRUE, '3cf780bb-51d6-4b41-b52d-1e77bfb60969', '{}'::jsonb, '0ea6c5d8-57e5-4226-8140-ec914deec024', 'producto')
ON CONFLICT (itemid) DO UPDATE SET itemtrackinventory = EXCLUDED.itemtrackinventory, itemprice = EXCLUDED.itemprice;

INSERT INTO "addon_group" (groupid, companyid, itemid, "name", minselect, maxselect, "sort", "status") VALUES
    ('c1a2b3c4-d5e6-4f70-8a91-b2c3d4e5f603', '0ea6c5d8-57e5-4226-8140-ec914deec024', 'c1a2b3c4-d5e6-4f70-8a91-b2c3d4e5f601', 'Extras (opcional)', 0, 2, 0, TRUE)
ON CONFLICT (groupid) DO UPDATE SET minselect = EXCLUDED.minselect, maxselect = EXCLUDED.maxselect;

INSERT INTO "addon_group_option" (optionid, groupid, itemid, pricedelta, isdefault, islocked, maxqty, "sort") VALUES
    ('c1a2b3c4-d5e6-4f70-8a91-b2c3d4e5f604', 'c1a2b3c4-d5e6-4f70-8a91-b2c3d4e5f603', 'c1a2b3c4-d5e6-4f70-8a91-b2c3d4e5f602', 2000, FALSE, FALSE, 3, 0)
ON CONFLICT (optionid) DO UPDATE SET pricedelta = EXCLUDED.pricedelta, maxqty = EXCLUDED.maxqty;

-- Producción directa (verify_production_cogs.php, fix 2026-08-19): un
-- insumo trackeable (su costo real lo pone el script vía manageStock(), acá
-- solo se seedea el item) y un ítem de producción directa (itemtrackinventory
-- =FALSE, itemproduction=FALSE, itemtype='product' — igual que ItemKind.php
-- 'produccion_directa') con una receta de 2 unidades del insumo por unidad
-- vendida. Verifica que itemSold.itemSoldCOGS se calcule (antes quedaba null:
-- SaleService comparaba itemType==='direct_production', string que nunca se
-- persiste) y que el movimiento de stock del insumo consumido lleve
-- stockSource='production' (antes siempre 'sale').
INSERT INTO item (itemid, itemname, itemsku, itemprice, itemcost, itemtype, itemstatus, itemcansale, itemtrackinventory, itemproduction, data, companyid, itemkind) VALUES
    ('b4a1e5f2-6c3d-4e21-9a8b-1f2e3d4c5b6a', 'Verify insumo producción', 'VERIFY-PROD-INSUMO', 0, 0, 'product', 1, FALSE, TRUE, FALSE, '{}'::jsonb, '0ea6c5d8-57e5-4226-8140-ec914deec024', 'insumo_stock'),
    ('b4a1e5f2-6c3d-4e21-9a8b-1f2e3d4c5b6b', 'Verify producción directa', 'VERIFY-PROD-DIRECT', 9000, 0, 'product', 1, TRUE, FALSE, FALSE, '{}'::jsonb, '0ea6c5d8-57e5-4226-8140-ec914deec024', 'produccion_directa')
ON CONFLICT (itemid) DO UPDATE SET itemtrackinventory = EXCLUDED.itemtrackinventory, itemproduction = EXCLUDED.itemproduction;

INSERT INTO item_compound (parentItemId, childItemId, quantity, sort, companyId) VALUES
    ('b4a1e5f2-6c3d-4e21-9a8b-1f2e3d4c5b6b', 'b4a1e5f2-6c3d-4e21-9a8b-1f2e3d4c5b6a', 2, 0, '0ea6c5d8-57e5-4226-8140-ec914deec024')
ON CONFLICT (parentItemId, childItemId) DO UPDATE SET quantity = EXCLUDED.quantity;

-- Receta de DOS NIVELES + insumo sin ledger (verify_production_cogs.php,
-- unificación del costeo 2026-08-22 en `RecipeCosting`). Ejercita los tres
-- puntos donde las tres fórmulas viejas divergían:
--   * VERIFY-PROD-SUBPREP: producción directa SIN stock propio, receta de 3 ×
--     VERIFY-PROD-INSUMO. Su `itemcost` de catálogo es 7777 A PROPÓSITO — un
--     costeo de un solo nivel valuaría la sub-preparación con ESE número en
--     vez de bajar a sus insumos, y el 7777 lo delata en el diff del arnés.
--   * VERIFY-PROD-NOSTOCK: insumo sin control de inventario (agua/sal). Nunca
--     tiene filas en `stock`, así que solo se puede valuar con `itemcost`
--     (250): la fórmula vieja de la venta, sin fallback, lo contaba como 0.
--     Lleva `itemWaste` 20% para que además se vea la merma aplicada
--     (1 / (1 - 0.20) = 1.25 unidades por unidad producida).
--   * VERIFY-PROD-L2: el terminado, 1 × SUBPREP + 1 × NOSTOCK.
INSERT INTO item (itemid, itemname, itemsku, itemprice, itemcost, itemtype, itemstatus, itemcansale, itemtrackinventory, itemproduction, data, companyid, itemkind) VALUES
    ('b4a1e5f2-6c3d-4e21-9a8b-1f2e3d4c5b6c', 'Verify sub-preparación',      'VERIFY-PROD-SUBPREP',     0, 7777, 'product', 1, FALSE, FALSE, FALSE, '{}'::jsonb,                 '0ea6c5d8-57e5-4226-8140-ec914deec024', 'produccion_directa'),
    ('b4a1e5f2-6c3d-4e21-9a8b-1f2e3d4c5b6d', 'Verify insumo sin stock',     'VERIFY-PROD-NOSTOCK',     0,  250, 'product', 1, FALSE, FALSE, FALSE, '{"itemWaste": 20}'::jsonb,  '0ea6c5d8-57e5-4226-8140-ec914deec024', 'insumo_sin_stock'),
    ('b4a1e5f2-6c3d-4e21-9a8b-1f2e3d4c5b6e', 'Verify producción 2 niveles', 'VERIFY-PROD-L2',      20000,    0, 'product', 1, TRUE,  FALSE, FALSE, '{}'::jsonb,                 '0ea6c5d8-57e5-4226-8140-ec914deec024', 'produccion_directa')
ON CONFLICT (itemid) DO UPDATE SET itemcost = EXCLUDED.itemcost, data = EXCLUDED.data,
    itemtrackinventory = EXCLUDED.itemtrackinventory, itemproduction = EXCLUDED.itemproduction;

INSERT INTO item_compound (parentItemId, childItemId, quantity, sort, companyId) VALUES
    ('b4a1e5f2-6c3d-4e21-9a8b-1f2e3d4c5b6c', 'b4a1e5f2-6c3d-4e21-9a8b-1f2e3d4c5b6a', 3, 0, '0ea6c5d8-57e5-4226-8140-ec914deec024'),
    ('b4a1e5f2-6c3d-4e21-9a8b-1f2e3d4c5b6e', 'b4a1e5f2-6c3d-4e21-9a8b-1f2e3d4c5b6c', 1, 0, '0ea6c5d8-57e5-4226-8140-ec914deec024'),
    ('b4a1e5f2-6c3d-4e21-9a8b-1f2e3d4c5b6e', 'b4a1e5f2-6c3d-4e21-9a8b-1f2e3d4c5b6d', 1, 1, '0ea6c5d8-57e5-4226-8140-ec914deec024')
ON CONFLICT (parentItemId, childItemId) DO UPDATE SET quantity = EXCLUDED.quantity;

-- Combo FIJO (context/52-stock-ledger-unica-fuente.md, stock_ledger_test.php).
-- `itemtype='combo'` es lo que dispara `SaleService::expandCompoundSelections()`:
-- la venta persiste una linea HIJA por componente (`meta.compound`), pura
-- trazabilidad de reportes, y el stock fisico lo descuenta el PADRE explotando
-- la receta. La reversa (anulacion/devolucion) NO debe reponer esas hijas: si
-- lo hiciera acreditaria unidades que nunca se restaron, encima del insumo que
-- ya repone el padre -- doble reposicion (G4 de context/52).
--
-- Receta: 2 x VERIFY-STOCK-TRACK. itemtrackinventory=FALSE + itemproduction=
-- FALSE => `Inventory::saleExplodesRecipe()` da true, que es el predicado real
-- que usa la venta para decidir si explota la receta.
INSERT INTO item (itemid, itemname, itemsku, itemprice, itemcost, itemtype, itemstatus, itemcansale, itemtrackinventory, itemproduction, data, companyid, itemkind) VALUES
    ('c0b1a5f2-6c3d-4e21-9a8b-1f2e3d4c5b70', 'Verify combo fijo', 'VERIFY-COMBO-FIJO', 25000, 0, 'combo', 1, TRUE, FALSE, FALSE, '{}'::jsonb, '0ea6c5d8-57e5-4226-8140-ec914deec024', 'combo_fijo')
ON CONFLICT (itemid) DO UPDATE SET itemtype = EXCLUDED.itemtype, itemkind = EXCLUDED.itemkind,
    itemtrackinventory = EXCLUDED.itemtrackinventory, itemproduction = EXCLUDED.itemproduction;

INSERT INTO item_compound (parentItemId, childItemId, quantity, sort, companyId) VALUES
    ('c0b1a5f2-6c3d-4e21-9a8b-1f2e3d4c5b70', '7a1c1a9e-3b1a-4e7b-8f7a-9a2b8c1d4e5f', 2, 0, '0ea6c5d8-57e5-4226-8140-ec914deec024')
ON CONFLICT (parentItemId, childItemId) DO UPDATE SET quantity = EXCLUDED.quantity;

-- Plantilla de impresión (context/08 §53, verify_offline_resolution.php):
-- prueba que el device (`pos-app`) puede leer `document_template` — antes
-- `apiAuthTenant(['panel'])` la bloqueaba para cualquier token que no fuera
-- de operador panel.
INSERT INTO document_template (templateId, companyId, name, docType, pageSize, isDefault, config) VALUES (
    '9b2e6a1c-4f3d-4a5b-8c6d-1e2f3a4b5c7d', '0ea6c5d8-57e5-4226-8140-ec914deec024', 'Verify ticket offline', 'receipt', '80mm', TRUE,
    '{"page_size":"receipt80","page_size_name":"Roll 80mm","page_name":"Recibo","page_font_family":"monospace","page_font_size":"9pt","page_font_case":"none","receipt_left_margin":"0","mm":3.78,"data":[{"type":"company_name"}]}'::jsonb
) ON CONFLICT (templateId) DO UPDATE SET config = EXCLUDED.config;

-- ── Company B: Verify MX (decimals=2) ───────────────────────────────
INSERT INTO company (companyId, status, plan, balance, isParent, config) VALUES (
    'fa8cf679-9003-417e-8726-5b772d3b6e88', 'active', 1, 0.00, FALSE,
    '{
        "settingName":"Verify MX",
        "settingDecimal":"yes",
        "settingThousandSeparator":"comma",
        "settingCountry":"MX",
        "settingCurrency":"MXN",
        "settingTimeZone":"America/Mexico_City",
        "settingTaxName":"IVA",
        "settingLanguage":"es",
        "settingSocialMedia":"{}",
        "settingObj":"{}"
    }'::jsonb
) ON CONFLICT (companyId) DO UPDATE SET status = EXCLUDED.status, config = EXCLUDED.config;

INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (
    '6d3cab3a-c040-4428-8090-6790469de3bd', 'Verify MX - Sucursal', 1,
    'fa8cf679-9003-417e-8726-5b772d3b6e88'
) ON CONFLICT (outletId) DO UPDATE SET outletName = EXCLUDED.outletName;

INSERT INTO register (
    registerid, registername, registerstatus,
    registerinvoicenumber, registerticketnumber, registerreturnnumber,
    registerschedulenumber, registerpedidonumber, registerquotenumber,
    outletid, companyid
) VALUES (
    'e91e3e74-b593-4833-9ee8-25b8ce9e4454', 'Verify MX - Caja', TRUE,
    1, 1, 1, 1, 1, 1,
    '6d3cab3a-c040-4428-8090-6790469de3bd', 'fa8cf679-9003-417e-8726-5b772d3b6e88'
) ON CONFLICT (registerid) DO UPDATE SET registername = EXCLUDED.registername;

-- Depósito por defecto de la sucursal MX (ver comentario del tenant PY arriba).
INSERT INTO taxonomy (taxonomyId, companyId, taxonomyType, outletId, taxonomyName, taxonomyExtra)
SELECT 'd82b4c16-9e07-4a35-8b41-6c2e0f9d7a13',
       'fa8cf679-9003-417e-8726-5b772d3b6e88',
       'location',
       '6d3cab3a-c040-4428-8090-6790469de3bd',
       'Verify MX - Depósito',
       '{"isDefault": true}'
 WHERE NOT EXISTS (
     SELECT 1 FROM taxonomy
      WHERE outletId = '6d3cab3a-c040-4428-8090-6790469de3bd'
        AND taxonomyType = 'location'
 );

INSERT INTO contact (
    contactId, contactName, contactPhone, contactEmail, contactStatus, type, main, role, outletId, companyId
) VALUES (
    '999986f1-05fe-4d91-841f-156a090e7a15', 'Verify MX Admin', '+525500000001', 'verify-mx@local.test',
    1, 0, 'admin', 1, '6d3cab3a-c040-4428-8090-6790469de3bd', 'fa8cf679-9003-417e-8726-5b772d3b6e88'
) ON CONFLICT (contactId) DO UPDATE SET contactName = EXCLUDED.contactName;

INSERT INTO tax (taxId, companyId, name, rate, kind) VALUES
    ('5af6f0c6-994b-4543-9455-4b67cb8c049e', 'fa8cf679-9003-417e-8726-5b772d3b6e88', '16', 16, 'rate'),
    ('eb7e216c-2649-48e1-8bbf-3aba6ad43c69', 'fa8cf679-9003-417e-8726-5b772d3b6e88', 'Exenta', 0, 'exempt')
ON CONFLICT (taxId) DO UPDATE SET rate = EXCLUDED.rate, kind = EXCLUDED.kind, name = EXCLUDED.name;

INSERT INTO item (itemid, itemname, itemsku, itemprice, itemtype, itemstatus, itemcansale, itemtrackinventory, taxid, data, companyid, itemkind) VALUES
    ('52b6ee53-3702-4127-a5a9-f31c8a75b938', 'Verify 16% incluido',    'VERIFY-16-INC',        58.00, 'product', 1, TRUE, FALSE, '5af6f0c6-994b-4543-9455-4b67cb8c049e', '{"itemTaxIncluded": true}'::jsonb,  'fa8cf679-9003-417e-8726-5b772d3b6e88', 'producto'),
    ('5e117782-3014-4fb5-88cd-1601974eaf52', 'Verify 16% añadido',     'VERIFY-16-ADD',         25.00, 'product', 1, TRUE, FALSE, '5af6f0c6-994b-4543-9455-4b67cb8c049e', '{"itemTaxIncluded": false}'::jsonb, 'fa8cf679-9003-417e-8726-5b772d3b6e88', 'producto'),
    ('46e7fef1-eb5f-4c29-8edb-79936e698f10', 'Verify exenta incluido', 'VERIFY-B-EXEMPT',       40.00, 'product', 1, TRUE, FALSE, 'eb7e216c-2649-48e1-8bbf-3aba6ad43c69', '{"itemTaxIncluded": true}'::jsonb,  'fa8cf679-9003-417e-8726-5b772d3b6e88', 'producto'),
    ('21dcfb96-107a-4642-a597-d3503f391f68', 'Verify 16% cantidad decimal', 'VERIFY-B-DECIMALQTY', 10.00, 'product', 1, TRUE, FALSE, '5af6f0c6-994b-4543-9455-4b67cb8c049e', '{"itemTaxIncluded": false}'::jsonb, 'fa8cf679-9003-417e-8726-5b772d3b6e88', 'producto')
ON CONFLICT (itemid) DO UPDATE SET itemname = EXCLUDED.itemname, itemprice = EXCLUDED.itemprice, taxid = EXCLUDED.taxid, data = EXCLUDED.data;

-- ── Sucursales de cada ítem (`item_outlet`, mig 170) ────────────────────────
-- Este seed inserta ítems con SQL directo, sin pasar por `ItemOutletService`.
-- Bajo el modelo N-a-N un ítem SIN filas acá es invisible para toda caja (cero
-- sucursales es estado inválido), así que hay que asignarlas explícitamente o
-- el catálogo del arnés no existiría para ningún device.
--
-- Misma regla que el backfill (b) de la migración 170: un ítem sin sucursal
-- explícita vive en TODAS las de su empresa. Va al FINAL del seed a propósito —
-- necesita que ya estén insertados todos los ítems y todas las sucursales.
-- Idempotente (ON CONFLICT), como el resto del seed.
INSERT INTO item_outlet (itemid, outletid, companyid)
  SELECT i.itemid, o.outletid, i.companyid
    FROM item i
    JOIN outlet o ON o.companyid = i.companyid
ON CONFLICT DO NOTHING;
