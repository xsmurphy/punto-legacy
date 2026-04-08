-- =============================================================
-- SEED PostgreSQL: Empresa cliente de prueba
-- Usuario: demo@local.test / admin123
-- =============================================================

-- Empresa demo
INSERT INTO company (
    companyId, status, plan, balance, isParent,
    config
) VALUES (
    '00000000-0000-0000-0000-000000000010',
    'Active',
    1,
    0.00,
    FALSE,
    '{"settingName":"Demo Company","settingCurrency":"PYG","settingLanguage":"es","settingCountry":"PY","settingTimeZone":"America/Asuncion","settingDecimal":",","settingThousandSeparator":"."}'
) ON CONFLICT (companyId) DO UPDATE
    SET status = EXCLUDED.status,
        config = EXCLUDED.config;

-- Sucursal demo
INSERT INTO outlet (
    outletId, outletName, outletStatus, companyId
) VALUES (
    '00000000-0000-0000-0000-000000000011',
    'Sucursal Principal',
    1,
    '00000000-0000-0000-0000-000000000010'
) ON CONFLICT (outletId) DO UPDATE
    SET outletName = EXCLUDED.outletName;

-- Usuario admin de la empresa demo (admin123)
INSERT INTO contact (
    contactId, contactName, contactEmail,
    contactPassword, salt,
    contactStatus, type, main, role,
    outletId, companyId
) VALUES (
    '00000000-0000-0000-0000-000000000012',
    'Demo Admin',
    'demo@local.test',
    'd1e425ce2c0b4f5f4bbead2ab72bba98e5764600864c3cfb54f69491c1625bfa',
    '18d31afc38712036',
    1, 0, 'admin', 1,
    '00000000-0000-0000-0000-000000000011',
    '00000000-0000-0000-0000-000000000010'
) ON CONFLICT (contactId) DO UPDATE
    SET contactEmail    = EXCLUDED.contactEmail,
        contactPassword = EXCLUDED.contactPassword,
        salt            = EXCLUDED.salt,
        contactStatus   = EXCLUDED.contactStatus,
        role            = EXCLUDED.role;
