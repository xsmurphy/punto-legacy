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

INSERT INTO contact (
    contactId, contactName, contactPhone, contactEmail, contactStatus, type, main, role, outletId, companyId
) VALUES (
    '3e52da17-74a2-49c3-9d07-8d4806671fd5', 'Verify PY Admin', '+595991000001', 'verify-py@local.test',
    1, 0, 'admin', 1, '1a282724-6073-49c3-8bc3-0114a132e349', '0ea6c5d8-57e5-4226-8140-ec914deec024'
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
INSERT INTO item (itemid, itemname, itemsku, itemprice, itemtype, itemstatus, itemcansale, itemtrackinventory, taxid, data, companyid) VALUES
    ('10223f3b-2e3d-4339-8496-9f288d8be65b', 'Verify 10% incluido',      'VERIFY-10-INC',         11000, 'product', 1, TRUE, FALSE, '3cf780bb-51d6-4b41-b52d-1e77bfb60969', '{"itemTaxIncluded": true}'::jsonb,  '0ea6c5d8-57e5-4226-8140-ec914deec024'),
    ('61230b1e-90e8-4018-ac59-865ca957b293', 'Verify 5% añadido',        'VERIFY-5-ADD',           5000, 'product', 1, TRUE, FALSE, 'c5f98ab9-9622-446e-9e90-ffb103309828', '{"itemTaxIncluded": false}'::jsonb, '0ea6c5d8-57e5-4226-8140-ec914deec024'),
    ('d4f2c9b6-baa0-4415-9f27-1f0e791c4df1', 'Verify 0% real añadido',   'VERIFY-0RATE-ADD',       8000, 'product', 1, TRUE, FALSE, '2e14f50c-30d2-4fe1-a141-241641a8ae17', '{"itemTaxIncluded": false}'::jsonb, '0ea6c5d8-57e5-4226-8140-ec914deec024'),
    ('dca3b670-60a6-4b81-a7d7-7e39a1f7cc30', 'Verify exenta incluido',   'VERIFY-EXEMPT-INC',      6000, 'product', 1, TRUE, FALSE, 'e16ad2ce-db03-48e3-81d1-653df1c1ab11', '{"itemTaxIncluded": true}'::jsonb,  '0ea6c5d8-57e5-4226-8140-ec914deec024'),
    ('0e15e0bb-2892-404a-b2a4-78e769bee4a4', 'Verify 10% cantidad decimal', 'VERIFY-10-DECIMALQTY', 4000, 'product', 1, TRUE, FALSE, '3cf780bb-51d6-4b41-b52d-1e77bfb60969', '{"itemTaxIncluded": false}'::jsonb, '0ea6c5d8-57e5-4226-8140-ec914deec024'),
    ('3334b924-7947-427a-88f5-b6987528703a', 'Verify 10% precio modificado', 'VERIFY-10-PRICEMOD', 50000, 'product', 1, TRUE, FALSE, '3cf780bb-51d6-4b41-b52d-1e77bfb60969', '{"itemTaxIncluded": true}'::jsonb,  '0ea6c5d8-57e5-4226-8140-ec914deec024'),
    ('5c835556-d25d-4258-861d-599ef3edae90', 'Verify 21% no admitido SIFEN', 'VERIFY-BAD21',         1000, 'product', 1, TRUE, FALSE, '90b39d98-ddfb-4909-b783-70277d5105f7', '{"itemTaxIncluded": false}'::jsonb, '0ea6c5d8-57e5-4226-8140-ec914deec024')
ON CONFLICT (itemid) DO UPDATE SET itemname = EXCLUDED.itemname, itemprice = EXCLUDED.itemprice, taxid = EXCLUDED.taxid, data = EXCLUDED.data;

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

INSERT INTO item (itemid, itemname, itemsku, itemprice, itemtype, itemstatus, itemcansale, itemtrackinventory, taxid, data, companyid) VALUES
    ('52b6ee53-3702-4127-a5a9-f31c8a75b938', 'Verify 16% incluido',    'VERIFY-16-INC',        58.00, 'product', 1, TRUE, FALSE, '5af6f0c6-994b-4543-9455-4b67cb8c049e', '{"itemTaxIncluded": true}'::jsonb,  'fa8cf679-9003-417e-8726-5b772d3b6e88'),
    ('5e117782-3014-4fb5-88cd-1601974eaf52', 'Verify 16% añadido',     'VERIFY-16-ADD',         25.00, 'product', 1, TRUE, FALSE, '5af6f0c6-994b-4543-9455-4b67cb8c049e', '{"itemTaxIncluded": false}'::jsonb, 'fa8cf679-9003-417e-8726-5b772d3b6e88'),
    ('46e7fef1-eb5f-4c29-8edb-79936e698f10', 'Verify exenta incluido', 'VERIFY-B-EXEMPT',       40.00, 'product', 1, TRUE, FALSE, 'eb7e216c-2649-48e1-8bbf-3aba6ad43c69', '{"itemTaxIncluded": true}'::jsonb,  'fa8cf679-9003-417e-8726-5b772d3b6e88'),
    ('21dcfb96-107a-4642-a597-d3503f391f68', 'Verify 16% cantidad decimal', 'VERIFY-B-DECIMALQTY', 10.00, 'product', 1, TRUE, FALSE, '5af6f0c6-994b-4543-9455-4b67cb8c049e', '{"itemTaxIncluded": false}'::jsonb, 'fa8cf679-9003-417e-8726-5b772d3b6e88')
ON CONFLICT (itemid) DO UPDATE SET itemname = EXCLUDED.itemname, itemprice = EXCLUDED.itemprice, taxid = EXCLUDED.taxid, data = EXCLUDED.data;
