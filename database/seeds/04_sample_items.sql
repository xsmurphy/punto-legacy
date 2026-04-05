-- =============================================================
-- SEED: Productos de ejemplo
-- =============================================================
-- Productos mínimos para probar el flujo de venta en el POS.
-- Requiere haber ejecutado 03_catalog.sql primero.
-- =============================================================

SET FOREIGN_KEY_CHECKS = 0;

INSERT INTO `item`
  (`itemId`,`itemName`,`itemDescription`,`itemPrice`,`itemCost`,
   `itemSKU`,`itemStatus`,`itemImage`,`itemTrackInventory`,
   `categoryId`,`companyId`)
VALUES
  (1,'Producto Demo 1','Producto de ejemplo para desarrollo', 10000.00, 5000.00,
   'DEMO001',1,'false',0,
   1,1),
  (2,'Producto Demo 2','Segundo producto de ejemplo',          25000.00,12000.00,
   'DEMO002',1,'false',0,
   1,1),
  (3,'Bebida Ejemplo', 'Bebida de ejemplo',                    5000.00, 2000.00,
   'BEB001', 1,'false',0,
   2,1),
  (4,'Servicio Demo',  'Servicio de ejemplo',                 50000.00, 0.00,
   'SRV001', 1,'false',0,
   4,1)
ON DUPLICATE KEY UPDATE
  `itemName`=VALUES(`itemName`),
  `itemPrice`=VALUES(`itemPrice`);

SET FOREIGN_KEY_CHECKS = 1;
