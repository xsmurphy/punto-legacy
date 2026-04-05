-- =============================================================
-- SEED: Datos base para desarrollo local - Punto POS
-- Ejecutar: bash database/seeds/run_seeds.sh
-- =============================================================
-- Credenciales de acceso local:
--   APP   http://localhost:8000  →  admin@local.test / admin123
--   Panel http://localhost:8001  →  admin@local.test / admin123
-- =============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------------
-- Plan (habilita todas las funcionalidades)
-- -------------------------------------------------------------
INSERT INTO `plans`
  (`id`,`name`,`type`,`price`,
   `max_items`,`max_users`,`max_customers`,`max_outlets`,`max_registers`,
   `max_suppliers`,`max_categories`,`max_brands`,
   `max_kds`,`expenses`,`purchase`,`tags`,`basicSettings`,`clockinout`,
   `satisfaction`,`orders`,`geosales`,`custom_payments`,`ecommerce`,
   `sms_receipt`,`duration_days`,`inventory`,`batch_inventory`,
   `inventory_count`,`delivery`,`production`,`drawerControl`,`item_options`,
   `activityLog`,`loyalty`,`storeCredit`,`storeTables`,`schedule`,
   `customerRecords`,`notify`,`customRoles`)
VALUES
  (1,'Local Dev Plan','premium',0.00,
   99999,99999,99999,99,99,
   99,99,99,
   1,1,1,1,1,1,
   1,1,1,1,1,
   1,99999,1,1,
   1,1,1,1,1,
   1,1,1,1,1,
   1,1,1)
ON DUPLICATE KEY UPDATE
  `name`='Local Dev Plan', `max_items`=99999, `max_users`=99999,
  `max_customers`=99999, `max_outlets`=99, `max_registers`=99;

-- -------------------------------------------------------------
-- Empresa
-- -------------------------------------------------------------
INSERT INTO `company`
  (`companyId`,`companyStatus`,`companyPlan`,`companyUserActivated`,
   `companyBalance`,`companyDB`)
VALUES
  (1,'Active',1,1,0.00,'905')
ON DUPLICATE KEY UPDATE
  `companyStatus`='Active', `companyPlan`=1, `companyUserActivated`=1;

-- -------------------------------------------------------------
-- Sucursal (outlet)
-- -------------------------------------------------------------
INSERT INTO `outlet`
  (`outletId`,`outletName`,`outletStatus`,`outletCreationDate`,
   `outletNextExpirationDate`,`companyId`)
VALUES
  (1,'Sucursal Principal',1,NOW(),NOW(),1)
ON DUPLICATE KEY UPDATE
  `outletName`='Sucursal Principal', `outletStatus`=1;

-- -------------------------------------------------------------
-- Caja (register)
-- -------------------------------------------------------------
INSERT INTO `register`
  (`registerId`,`registerName`,`registerStatus`,`registerDocsLeadingZeros`,
   `registerCreationDate`,`lastupdated`,`outletId`,`companyId`)
VALUES
  (1,'Caja 1',1,0,NOW(),'2018-01-01 00:00:00',1,1)
ON DUPLICATE KEY UPDATE
  `registerName`='Caja 1', `registerStatus`=1;

-- -------------------------------------------------------------
-- Usuario administrador
-- Contraseña: admin123
-- Hash generado con SHA-256 x65646 rondas (ver comentario al pie)
-- -------------------------------------------------------------
INSERT INTO `contact`
  (`contactId`,`contactName`,`contactEmail`,`contactPassword`,`salt`,
   `contactStatus`,`type`,`main`,`role`,`outletId`,`companyId`)
VALUES
  (1,'Administrador','admin@local.test',
   'd1e425ce2c0b4f5f4bbead2ab72bba98e5764600864c3cfb54f69491c1625bfa',
   '18d31afc38712036',
   1,0,'admin',1,1,1)
ON DUPLICATE KEY UPDATE
  `contactEmail`='admin@local.test',
  `contactPassword`='d1e425ce2c0b4f5f4bbead2ab72bba98e5764600864c3cfb54f69491c1625bfa',
  `salt`='18d31afc38712036',
  `contactStatus`=1;

-- -------------------------------------------------------------
-- Configuración de la empresa
-- -------------------------------------------------------------
INSERT INTO `setting`
  (`settingId`,`settingName`,`settingCountry`,`settingCurrency`,`settingLanguage`,
   `settingTimeZone`,`settingDecimal`,`settingThousandSeparator`,
   `settingRemoveTaxes`,`settingSellSoldOut`,`settingLockScreen`,
   `settingDrawerEmail`,`settingDrawerBlind`,`settingPaymentMethodId`,
   `settingItemSerialized`,`settingAcceptedTerms`,`settingSellCredit`,
   `settingLoyaltyValue`,`settingItemsSaleLimit`,`settingInvoiceTemplate`,
   `settingOpenFrom`,`settingOpenTo`,`companyId`)
VALUES
  (1,'Mi Empresa Local','PY','PYG','es',
   'America/Asuncion',',','.',
   1,'yes',0,
   0,0,0,
   1,1,1,
   0.00,20,0,
   '8:00','21:00',1)
ON DUPLICATE KEY UPDATE
  `settingName`='Mi Empresa Local', `settingCountry`='PY',
  `settingCurrency`='PYG', `settingLanguage`='es',
  `settingTimeZone`='America/Asuncion';

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================
-- Para regenerar el hash de contraseña:
-- /opt/homebrew/bin/php -r "
--   define('SALT', 2147483647);
--   \$salt = bin2hex(random_bytes(8));
--   \$hash = 'admin123' . \$salt . SALT;
--   for (\$i=0; \$i<65646; \$i++) \$hash = hash('sha256', \$hash);
--   echo 'salt=' . \$salt . PHP_EOL;
--   echo 'hash=' . \$hash . PHP_EOL;
-- "
-- =============================================================
