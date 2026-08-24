-- =============================================================
-- SEED PostgreSQL: Empresa cliente de prueba
-- Usuario: demo@local.test / admin123
-- =============================================================
-- UUIDs fijos pero NO-secuenciales para reproducibilidad del seed
-- (los UUIDs raw pueden filtrarse a APIs/URLs públicas — no usar
-- formatos enumerables tipo 0..1, 0..2).
--   Demo Company  : 2cffe736-f5dc-4876-9752-ea5f0db24757
--   Demo Outlet   : ff8470f8-5952-4297-9ce0-fda08c701c21
--   Demo Admin    : cb9afd35-a374-4080-873c-6d141070b92e
-- =============================================================

-- Empresa demo
INSERT INTO company (
    companyId, status, plan, balance, isParent,
    config
) VALUES (
    '2cffe736-f5dc-4876-9752-ea5f0db24757',
    'active',
    1,
    0.00,
    FALSE,
    '{
        "settingName":"Demo Company",
        "settingBillingName":"Demo Company S.A.",
        "settingAddress":"Av. Mariscal Lopez 1234, Asuncion",
        "settingPhone":"+595 21 000 000",
        "settingEmail":"demo@local.test",
        "settingWebSite":"https://local.test",
        "settingRUC":"0000000-0",
        "settingTIN":"RUC",
        "settingTaxName":"IVA",
        "settingRemoveTaxes":0,
        "settingCurrency":"PYG",
        "settingLanguage":"es",
        "settingCountry":"PY",
        "settingTimeZone":"America/Asuncion",
        "settingDecimal":"no",
        "settingThousandSeparator":"dot",
        "settingItemsSaleLimit":100,
        "settingDrawerBlind":0,
        "settingPaymentMethodId":0,
        "settingItemSerialized":0,
        "settingSellSoldOut":0,
        "settingStoreCredit":0,
        "settingLockScreen":0,
        "settingForceCreditLine":0,
        "settingMandatoryContactFields":"",
        "settingHideComboItems":0,
        "settingOpenFrom":"08:00",
        "settingOpenTo":"22:00",
        "settingCompanyCategoryId":0,
        "settingObj":"{}"
    }'::jsonb
) ON CONFLICT (companyId) DO UPDATE
    SET status = EXCLUDED.status,
        config = EXCLUDED.config;

-- Sucursal demo
INSERT INTO outlet (
    outletId, outletName, outletStatus, companyId
) VALUES (
    'ff8470f8-5952-4297-9ce0-fda08c701c21',
    'Sucursal Principal',
    1,
    '2cffe736-f5dc-4876-9752-ea5f0db24757'
) ON CONFLICT (outletId) DO UPDATE
    SET outletName = EXCLUDED.outletName;

-- Depósito de la sucursal demo. La cadena Company > Sucursal >
-- (Depósito | Caja) es OBLIGATORIA (regla del owner 2026-08-24, context/08):
-- este seed creaba la sucursal sin depósito, y la caja recién aparecía en
-- `04_dev_register_and_items.sql`. `WHERE NOT EXISTS` para no chocar con
-- `uq_taxonomy_location_default` (mig 165) en bases ya backfilleadas.
INSERT INTO taxonomy (taxonomyId, companyId, taxonomyType, outletId, taxonomyName, taxonomyExtra)
SELECT 'c0a8d1f2-3b4c-4d5e-9f60-718293a4b5c6',
       '2cffe736-f5dc-4876-9752-ea5f0db24757',
       'location',
       'ff8470f8-5952-4297-9ce0-fda08c701c21',
       'Depósito Sucursal Principal',
       '{"isDefault": true}'
 WHERE NOT EXISTS (
     SELECT 1 FROM taxonomy
      WHERE outletId = 'ff8470f8-5952-4297-9ce0-fda08c701c21'
        AND taxonomyType = 'location'
 );

-- Caja de la sucursal demo — el otro hermano de la cadena.
--
-- El registerId es EL MISMO que usa `04_dev_register_and_items.sql`, a
-- propósito: ese seed hace `ON CONFLICT (registerid) DO UPDATE` y le agrega los
-- hotkeys precargados que saltean el wizard de primer arranque del POS. Con el
-- id compartido, el 04 enriquece ESTA fila en vez de crear una segunda caja, y
-- sigue siendo el dueño de los hotkeys.
--
-- Va acá y no solo en el 04 porque los seeds se corren también sueltos: con
-- 01+02 nada más, la sucursal demo quedaba sin caja y violaba el invariante de
-- context/08 §58 (que el arnés `outlet_chain_invariant_test.php` marca en rojo).
INSERT INTO register (registerId, registerName, registerStatus, outletId, companyId)
SELECT '51169383-9306-4f56-a293-037dbadea2d9',
       'Caja Principal',
       TRUE,
       'ff8470f8-5952-4297-9ce0-fda08c701c21',
       '2cffe736-f5dc-4876-9752-ea5f0db24757'
 WHERE NOT EXISTS (
     SELECT 1 FROM register WHERE outletId = 'ff8470f8-5952-4297-9ce0-fda08c701c21'
 );

-- Usuario admin de la empresa demo (login: +5950991234567 / admin123)
-- Tenants login SOLO por teléfono (E.164). Email queda como dato opcional del perfil.
INSERT INTO contact (
    contactId, contactName, contactPhone, contactEmail,
    contactPassword, salt,
    contactStatus, type, main, role,
    outletId, companyId
) VALUES (
    'cb9afd35-a374-4080-873c-6d141070b92e',
    'Demo Admin',
    '+595991234567',
    'demo@local.test',
    'd1e425ce2c0b4f5f4bbead2ab72bba98e5764600864c3cfb54f69491c1625bfa',
    '18d31afc38712036',
    1, 0, 'admin', 1,
    'ff8470f8-5952-4297-9ce0-fda08c701c21',
    '2cffe736-f5dc-4876-9752-ea5f0db24757'
) ON CONFLICT (contactId) DO UPDATE
    SET contactPhone    = EXCLUDED.contactPhone,
        contactEmail    = EXCLUDED.contactEmail,
        contactPassword = EXCLUDED.contactPassword,
        salt            = EXCLUDED.salt,
        contactStatus   = EXCLUDED.contactStatus,
        role            = EXCLUDED.role;
