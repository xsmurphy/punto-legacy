-- 87_customer_address_extend.sql
-- Extiende `customerAddress` en vez de crear una tabla `contact_address`
-- paralela (context/27-delivery-sla-plan.md PARTE D, corrección 2026-07-19).
--
-- `customerAddress` es ANTERIOR a las migraciones numeradas — por eso no
-- aparece grepeando 01-86 — pero ya es una libreta de direcciones completa y
-- en producción: CustomerAddressService (CRUD + invariante de un solo
-- default en TX), api/v1/customer_address.php, hooks de frontend, y el tab
-- "Direcciones" del panel. La versión original de PARTE D asumía que el
-- contacto tenía una sola dirección en el JSONB `contact.data` y proponía
-- una tabla nueva — habría dejado DOS libretas de direcciones conviviendo
-- para el mismo cliente. D.2 documenta la decisión de extender, no duplicar.
--
-- Columnas nuevas:
--   reference — referencia verbal ("portón negro, timbre 2"). En Paraguay
--               suele valer más que la numeración para que el repartidor
--               encuentre la casa. Único campo del requisito del owner que
--               faltaba en la tabla existente.
--   status    — soft-delete (1=activa, 0=eliminada). El borrado hoy es
--               DELETE físico (CustomerAddressService::delete); pasa a soft
--               porque una dirección referenciada por una orden histórica
--               (tabla toAddress) no puede desaparecer del historial.
--
-- Naming: camelCase sin comillas, como el resto de las columnas de esta
-- tabla (customerAddressId/Name/Text/Location/City/Lat/Lng, customerId,
-- companyId) — NO el lowercase de las migs 79+ (ese es el patrón de las
-- tablas nuevas del módulo de Órdenes). Postgres pliega los identificadores
-- sin comillas a minúsculas; todo el código existente de esta tabla
-- (CustomerAddressService.php, ContactRepository.php, CustomerService.php)
-- ya la referencia sin comillas, así que el folding es consistente con lo
-- que hay.
ALTER TABLE customerAddress ADD COLUMN IF NOT EXISTS reference TEXT;
ALTER TABLE customerAddress ADD COLUMN IF NOT EXISTS status SMALLINT NOT NULL DEFAULT 1;

-- Los listados (CustomerAddressService::listForCustomer) y el lookup de
-- default (ContactRepository/CustomerService) filtran por
-- (companyId, customerId, status) — mismo patrón que las tablas nuevas del
-- módulo de Órdenes (mig 79/85).
CREATE INDEX IF NOT EXISTS idx_customeraddress_company_customer_status
  ON customerAddress (companyId, customerId, status);
