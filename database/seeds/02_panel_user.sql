-- =============================================================
-- SEED: Usuario administrador del Panel (localhost:8001)
-- Credenciales: admin@local.test / admin123
-- =============================================================
-- El panel usa la misma tabla `contact` que la APP.
-- role=1 = Admin (acceso completo al panel)
-- =============================================================

SET FOREIGN_KEY_CHECKS = 0;

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
  `role`=1,
  `contactStatus`=1;

SET FOREIGN_KEY_CHECKS = 1;
