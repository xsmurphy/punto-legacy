-- =============================================================
-- SEED PostgreSQL: Empresa master y usuario super admin
-- Credenciales: master@local.test / admin123
-- URL: http://localhost:8002/main
-- =============================================================
-- IDs fijos para reproducibilidad:
--   Empresa master : 00000000-0000-0000-0000-000000000001
--   Sucursal master: 00000000-0000-0000-0000-000000000002
--   Usuario master : 00000000-0000-0000-0000-000000000003
-- =============================================================

-- Empresa master (SaaS admin)
INSERT INTO company (
    companyId, status, plan, balance, isParent, config
) VALUES (
    '00000000-0000-0000-0000-000000000001',
    'Active',
    0,
    0.00,
    TRUE,
    '{"settingName":"Master Admin","settingCurrency":"USD","settingLanguage":"es"}'
) ON CONFLICT (companyId) DO UPDATE
    SET status   = EXCLUDED.status,
        isParent = EXCLUDED.isParent;

-- Sucursal para la empresa master
INSERT INTO outlet (
    outletId, outletName, outletStatus, companyId
) VALUES (
    '00000000-0000-0000-0000-000000000002',
    'Master Outlet',
    1,
    '00000000-0000-0000-0000-000000000001'
) ON CONFLICT (outletId) DO UPDATE
    SET outletName = EXCLUDED.outletName;

-- Usuario super admin
-- Contraseña: admin123 (mismo hash que admin@local.test)
INSERT INTO contact (
    contactId, contactName, contactEmail,
    contactPassword, salt,
    contactStatus, type, main, role,
    outletId, companyId, data
) VALUES (
    '00000000-0000-0000-0000-000000000003',
    'Master Admin',
    'master@local.test',
    'd1e425ce2c0b4f5f4bbead2ab72bba98e5764600864c3cfb54f69491c1625bfa',
    '18d31afc38712036',
    1, 0, 'admin', 1,
    '00000000-0000-0000-0000-000000000002',
    '00000000-0000-0000-0000-000000000001',
    '[{"permissions":{"encom":{"companyList":true,"companyListAccess":true,"companyEdit":true,"eposDeleteRecord":true,"eposPayout":true,"eposPayoutMonth":true}}}]'
) ON CONFLICT (contactId) DO UPDATE
    SET contactEmail    = EXCLUDED.contactEmail,
        contactPassword = EXCLUDED.contactPassword,
        salt            = EXCLUDED.salt,
        contactStatus   = EXCLUDED.contactStatus,
        role            = EXCLUDED.role,
        data            = EXCLUDED.data;

-- =============================================================
-- Para regenerar el hash de contraseña:
-- php -r "
--   define('SALT', 2147483647);
--   \$salt = bin2hex(random_bytes(8));
--   \$hash = 'admin123' . \$salt . SALT;
--   for (\$i=0; \$i<65646; \$i++) \$hash = hash('sha256', \$hash);
--   echo 'salt=' . \$salt . PHP_EOL;
--   echo 'hash=' . \$hash . PHP_EOL;
-- "
-- =============================================================
