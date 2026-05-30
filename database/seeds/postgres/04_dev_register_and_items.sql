-- =============================================================
-- SEED PostgreSQL: Punto de venta (register) + ítems de prueba
-- para la empresa demo (00000000-0000-0000-0000-000000000010)
-- Permite probar el flujo de venta E2E en dev.
-- =============================================================

-- Register principal en el outlet demo
INSERT INTO register (
    registerid, registername, registerstatus,
    registerinvoicenumber, registerticketnumber, registerreturnnumber,
    registerschedulenumber, registerpedidonumber, registerquotenumber,
    outletid, companyid
) VALUES (
    '00000000-0000-0000-0000-000000000020',
    'Caja Principal', TRUE,
    1, 1, 1, 1, 1, 1,
    '00000000-0000-0000-0000-000000000011',
    '00000000-0000-0000-0000-000000000010'
) ON CONFLICT (registerid) DO UPDATE
    SET registername   = EXCLUDED.registername,
        registerstatus = EXCLUDED.registerstatus;

-- 5 ítems de prueba (sin tracking de inventario para venta directa)
INSERT INTO item (
    itemid, itemname, itemprice, itemcost,
    itemtype, itemstatus, itemcansale, itemtrackinventory,
    itemsort, companyid
) VALUES
    ('00000000-0000-0000-0000-000000000031', 'Café',         8000,  3000, 'product', 1, TRUE, FALSE, 1, '00000000-0000-0000-0000-000000000010'),
    ('00000000-0000-0000-0000-000000000032', 'Empanada',    12000,  5000, 'product', 1, TRUE, FALSE, 2, '00000000-0000-0000-0000-000000000010'),
    ('00000000-0000-0000-0000-000000000033', 'Sandwich',    25000, 10000, 'product', 1, TRUE, FALSE, 3, '00000000-0000-0000-0000-000000000010'),
    ('00000000-0000-0000-0000-000000000034', 'Jugo Natural',15000,  6000, 'product', 1, TRUE, FALSE, 4, '00000000-0000-0000-0000-000000000010'),
    ('00000000-0000-0000-0000-000000000035', 'Agua Mineral', 5000,  2000, 'product', 1, TRUE, FALSE, 5, '00000000-0000-0000-0000-000000000010')
ON CONFLICT (itemid) DO UPDATE
    SET itemname  = EXCLUDED.itemname,
        itemprice = EXCLUDED.itemprice,
        itemcost  = EXCLUDED.itemcost;
