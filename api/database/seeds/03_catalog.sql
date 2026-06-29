-- =============================================================
-- SEED: Catálogo mínimo (categorías, métodos de pago)
-- =============================================================
-- Datos de referencia necesarios para que el POS funcione:
--  - Categorías de productos (taxonomy type=category)
--  - Métodos de pago (taxonomy type=paymentMethod)
--  - Marcas (taxonomy type=brand)
-- =============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------------
-- Categorías de productos
-- -------------------------------------------------------------
INSERT INTO `taxonomy` (`taxonomyId`,`taxonomyType`,`taxonomyName`,`taxonomyExtra`,`companyId`)
VALUES
  (1,'category','General',       1, 1),
  (2,'category','Bebidas',       2, 1),
  (3,'category','Comidas',       3, 1),
  (4,'category','Servicios',     4, 1)
ON DUPLICATE KEY UPDATE `taxonomyName`=VALUES(`taxonomyName`);

-- -------------------------------------------------------------
-- Métodos de pago
-- -------------------------------------------------------------
INSERT INTO `taxonomy` (`taxonomyId`,`taxonomyType`,`taxonomyName`,`taxonomyExtra`,`companyId`)
VALUES
  (10,'paymentMethod','Efectivo',       NULL, 1),
  (11,'paymentMethod','Tarjeta Débito', NULL, 1),
  (12,'paymentMethod','Tarjeta Crédito',NULL, 1),
  (13,'paymentMethod','Transferencia',  NULL, 1)
ON DUPLICATE KEY UPDATE `taxonomyName`=VALUES(`taxonomyName`);

-- -------------------------------------------------------------
-- Marcas
-- -------------------------------------------------------------
INSERT INTO `taxonomy` (`taxonomyId`,`taxonomyType`,`taxonomyName`,`taxonomyExtra`,`companyId`)
VALUES
  (20,'brand','Sin Marca', NULL, 1)
ON DUPLICATE KEY UPDATE `taxonomyName`=VALUES(`taxonomyName`);

-- -------------------------------------------------------------
-- Método de pago predeterminado en setting
-- -------------------------------------------------------------
UPDATE `setting` SET `settingPaymentMethodId` = 10 WHERE `companyId` = 1;

SET FOREIGN_KEY_CHECKS = 1;
