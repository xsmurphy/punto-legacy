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

-- Empresa master (SaaS admin).
-- isInternal = 1: no es un cliente, es la empresa del propio SaaS. El flag lo
-- leen las analíticas de /admin (AdminReportsService) para no contarla como
-- tenant — sin él, el conteo de comercios activos y el MRR salen inflados.
INSERT INTO company (
    companyId, status, plan, balance, isParent, isInternal, config
) VALUES (
    '00000000-0000-0000-0000-000000000001',
    'active',
    0,
    0.00,
    TRUE,
    1,
    '{"settingName":"Master Admin","settingCurrency":"USD","settingLanguage":"es"}'
) ON CONFLICT (companyId) DO UPDATE
    SET status     = EXCLUDED.status,
        isParent   = EXCLUDED.isParent,
        isInternal = EXCLUDED.isInternal;

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

-- Depósito y caja de la sucursal master.
--
-- La cadena Company > Sucursal > (Depósito | Caja) es OBLIGATORIA (regla del
-- owner 2026-08-24, context/08). Este seed la rompía: creaba la empresa y
-- "Master Outlet" y ahí terminaba, así que la sucursal master nacía sin
-- depósito (backfilleado después por la mig 165) y sin caja (mig 166) — era la
-- ÚNICA sucursal sin caja en producción.
--
-- `WHERE NOT EXISTS` en vez de `ON CONFLICT (id)` a propósito: en una base que
-- ya corrió las migs 165/166 el depósito y la caja existen con UUID aleatorio,
-- y un INSERT con id fijo agregaría un SEGUNDO depósito por defecto — que
-- `uq_taxonomy_location_default` (mig 165) rechaza y tumbaría el seed entero.
INSERT INTO taxonomy (taxonomyId, companyId, taxonomyType, outletId, taxonomyName, taxonomyExtra)
SELECT '00000000-0000-0000-0000-000000000004',
       '00000000-0000-0000-0000-000000000001',
       'location',
       '00000000-0000-0000-0000-000000000002',
       'Depósito Master Outlet',
       '{"isDefault": true}'
 WHERE NOT EXISTS (
     SELECT 1 FROM taxonomy
      WHERE outletId = '00000000-0000-0000-0000-000000000002'
        AND taxonomyType = 'location'
 );

INSERT INTO register (registerId, registerName, registerStatus, outletId, companyId)
SELECT '00000000-0000-0000-0000-000000000005',
       'Caja Principal',
       TRUE,
       '00000000-0000-0000-0000-000000000002',
       '00000000-0000-0000-0000-000000000001'
 WHERE NOT EXISTS (
     SELECT 1 FROM register WHERE outletId = '00000000-0000-0000-0000-000000000002'
 );

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
    -- main = 'true': es el flag del contacto principal del comercio y el único
    -- valor que entiende el sistema (ver mig 172). El 'admin' que había acá no
    -- significaba nada — los admins de la plataforma viven en `admin_user` —
    -- y dejaba a este contacto fuera de las lecturas de /admin.
    1, 0, 'true', 1,
    '00000000-0000-0000-0000-000000000002',
    '00000000-0000-0000-0000-000000000001',
    '[{"permissions":{"encom":{"companyList":true,"companyListAccess":true,"companyEdit":true,"eposDeleteRecord":true,"eposPayout":true,"eposPayoutMonth":true}}}]'
) ON CONFLICT (contactId) DO UPDATE
    SET contactEmail    = EXCLUDED.contactEmail,
        contactPassword = EXCLUDED.contactPassword,
        salt            = EXCLUDED.salt,
        contactStatus   = EXCLUDED.contactStatus,
        main            = EXCLUDED.main,
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
