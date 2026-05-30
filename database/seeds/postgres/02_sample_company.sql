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
    'Active',
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

-- Usuario admin de la empresa demo (admin123)
INSERT INTO contact (
    contactId, contactName, contactEmail,
    contactPassword, salt,
    contactStatus, type, main, role,
    outletId, companyId
) VALUES (
    'cb9afd35-a374-4080-873c-6d141070b92e',
    'Demo Admin',
    'demo@local.test',
    'd1e425ce2c0b4f5f4bbead2ab72bba98e5764600864c3cfb54f69491c1625bfa',
    '18d31afc38712036',
    1, 0, 'admin', 1,
    'ff8470f8-5952-4297-9ce0-fda08c701c21',
    '2cffe736-f5dc-4876-9752-ea5f0db24757'
) ON CONFLICT (contactId) DO UPDATE
    SET contactEmail    = EXCLUDED.contactEmail,
        contactPassword = EXCLUDED.contactPassword,
        salt            = EXCLUDED.salt,
        contactStatus   = EXCLUDED.contactStatus,
        role            = EXCLUDED.role;
