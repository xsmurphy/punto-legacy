-- =============================================================
-- SEED PostgreSQL: Punto de venta (register) + ítems de prueba
-- para la empresa demo (2cffe736-f5dc-4876-9752-ea5f0db24757)
-- Permite probar el flujo de venta E2E en dev.
-- =============================================================

-- Register principal en el outlet demo
-- registerHotkeys: precargado en data JSONB con los 5 ítems del seed para
-- saltar el wizard de primer arranque del POS (sino el cajero queda en
-- modo "edit hotkeys"). La columna registerHotkeys fue demoted a data JSONB.
INSERT INTO register (
    registerid, registername, registerstatus,
    registerinvoicenumber, registerticketnumber, registerreturnnumber,
    registerschedulenumber, registerpedidonumber, registerquotenumber,
    data,
    outletid, companyid
) VALUES (
    '51169383-9306-4f56-a293-037dbadea2d9',
    'Caja Principal', TRUE,
    1, 1, 1, 1, 1, 1,
    '{"registerHotkeys":"[{\"color\":\"\",\"itemId\":\"dd6a7423-de71-4ede-9059-dae08d5b2692\",\"position\":0},{\"color\":\"\",\"itemId\":\"955d2785-095c-4658-918c-61811611ac08\",\"position\":1},{\"color\":\"\",\"itemId\":\"3bdfdcd6-2961-48a3-a6b0-e01aae76f1e1\",\"position\":2},{\"color\":\"\",\"itemId\":\"87398feb-0684-482b-a04c-f95281cfedeb\",\"position\":3},{\"color\":\"\",\"itemId\":\"591867cb-ce6a-43b6-be2c-7ea364b7d637\",\"position\":4}]"}'::jsonb,
    'ff8470f8-5952-4297-9ce0-fda08c701c21',
    '2cffe736-f5dc-4876-9752-ea5f0db24757'
) ON CONFLICT (registerid) DO UPDATE
    SET registername   = EXCLUDED.registername,
        registerstatus = EXCLUDED.registerstatus,
        data           = EXCLUDED.data;

-- 5 ítems de prueba (sin tracking de inventario para venta directa)
INSERT INTO item (
    itemid, itemname, itemsku, itemprice, itemcost,
    itemtype, itemstatus, itemcansale, itemtrackinventory,
    itemsort, companyid
) VALUES
    ('dd6a7423-de71-4ede-9059-dae08d5b2692', 'Café',         'CAF-001',  8000,  3000, 'product', 1, TRUE, FALSE, 1, '2cffe736-f5dc-4876-9752-ea5f0db24757'),
    ('955d2785-095c-4658-918c-61811611ac08', 'Empanada',     'EMP-001', 12000,  5000, 'product', 1, TRUE, FALSE, 2, '2cffe736-f5dc-4876-9752-ea5f0db24757'),
    ('3bdfdcd6-2961-48a3-a6b0-e01aae76f1e1', 'Sandwich',     'SAN-001', 25000, 10000, 'product', 1, TRUE, FALSE, 3, '2cffe736-f5dc-4876-9752-ea5f0db24757'),
    ('87398feb-0684-482b-a04c-f95281cfedeb', 'Jugo Natural', 'JUG-001', 15000,  6000, 'product', 1, TRUE, FALSE, 4, '2cffe736-f5dc-4876-9752-ea5f0db24757'),
    ('591867cb-ce6a-43b6-be2c-7ea364b7d637', 'Agua Mineral', 'AGU-001',  5000,  2000, 'product', 1, TRUE, FALSE, 5, '2cffe736-f5dc-4876-9752-ea5f0db24757')
ON CONFLICT (itemid) DO UPDATE
    SET itemname  = EXCLUDED.itemname,
        itemsku   = EXCLUDED.itemsku,
        itemprice = EXCLUDED.itemprice,
        itemcost  = EXCLUDED.itemcost;
