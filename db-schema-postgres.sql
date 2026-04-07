-- ============================================================
-- Punto / ENCOM — PostgreSQL Schema  (v2)
-- Designed for PostgreSQL 16+
-- Generated: 2026-04
--
-- Key design decisions:
--   • All PKs are UUID (gen_random_uuid())
--   • company merges: company + setting + module + companyHours → config JSONB
--   • item:         non-indexable columns moved to data JSONB
--   • contact:      non-indexable columns moved to data JSONB
--   • transaction:  all original columns + meta JSONB for extensibility
--   • itemSold:     all original columns + meta JSONB for extensibility
--   • outlet, register, recurring, tasks: data JSONB for extensibility
--   • All FK constraints explicitly defined
--   • All indexes as CREATE INDEX statements
--
-- PHP migration note:
--   Queries to `setting` or `module` must be updated to query `company`.
--   contactUID references in PHP must be updated to use contactId directly.
-- ============================================================

CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- ============================================================
-- COMPANY  (merged: company + setting + module + companyHours)
-- ============================================================
-- config JSONB absorbs all setting.*, module.*, and companyHours.*
-- e.g. config->>'settingName', config->>'settingCurrency',
--      config->'hours'->'monday', config->'eposData'
CREATE TABLE company (
  companyId   UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  status      VARCHAR(10)   NOT NULL DEFAULT 'active',  -- active, suspended, cancelled
  plan        SMALLINT      NOT NULL DEFAULT 0,
  balance     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  slug        VARCHAR(100)  UNIQUE,         -- was settingSlug; used in URL routing
  blocked     SMALLINT               DEFAULT 0,
  planExpired BOOLEAN                DEFAULT FALSE,
  isTrial     BOOLEAN                DEFAULT FALSE,
  smsCredit   INT                    DEFAULT 0,
  discount    DECIMAL(15,2)          DEFAULT 0.00,
  parentId    UUID          REFERENCES company(companyId),
  isParent    BOOLEAN       NOT NULL DEFAULT FALSE,
  createdAt   TIMESTAMPTZ   NOT NULL DEFAULT now(),
  updatedAt   TIMESTAMPTZ,
  expiresAt   TIMESTAMPTZ,
  config      JSONB         NOT NULL DEFAULT '{}'
);

CREATE INDEX idx_company_plan    ON company(plan);
CREATE INDEX idx_company_blocked ON company(blocked, planExpired);
CREATE INDEX idx_company_config  ON company USING GIN(config);

COMMENT ON TABLE  company        IS 'Merged company + setting + module + companyHours';
COMMENT ON COLUMN company.config IS 'Absorbs setting.*, module.*, companyHours.* — access via config->>key or _flattenJsonb()';
COMMENT ON COLUMN company.slug   IS 'URL slug for storefront / API routing (was settingSlug)';


-- ============================================================
-- PLANS  (subscription plan definitions)
-- ============================================================
-- features JSONB absorbs all tinyint feature-flag columns
CREATE TABLE plans (
  id             UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  name           VARCHAR(255)  NOT NULL,
  type           VARCHAR(255)  NOT NULL,
  price          DECIMAL(15,2) NOT NULL,
  duration_days  INT           NOT NULL DEFAULT 0,
  max_items      INT           NOT NULL DEFAULT 0,
  max_users      INT           NOT NULL DEFAULT 0,
  max_customers  INT           NOT NULL DEFAULT 0,
  max_outlets    INT           NOT NULL DEFAULT 0,
  max_registers  INT           NOT NULL DEFAULT 0,
  max_suppliers  INT           NOT NULL DEFAULT 0,
  max_categories INT           NOT NULL DEFAULT 0,
  max_brands     INT           NOT NULL DEFAULT 0,
  features       JSONB         NOT NULL DEFAULT '{}'
  -- features absorbs: max_kds, expenses, purchase, tags, basicSettings, clockinout,
  -- satisfaction, orders, geosales, custom_payments, ecommerce, sms_receipt, inventory,
  -- batch_inventory, inventory_count, delivery, production, drawerControl, item_options,
  -- activityLog, loyalty, storeCredit, storeTables, schedule, customerRecords, notify,
  -- customRoles
);


-- ============================================================
-- BANKS
-- ============================================================
CREATE TABLE banks (
  bankId   UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  bankName VARCHAR(255) NOT NULL DEFAULT ''
);


-- ============================================================
-- USERS  (Laravel Sanctum auth — admin panel only)
-- ============================================================
CREATE TABLE users (
  id                UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  name              VARCHAR(255) NOT NULL,
  email             VARCHAR(255) NOT NULL,
  email_verified_at TIMESTAMPTZ,
  password          VARCHAR(255) NOT NULL,
  perfil            VARCHAR(255) NOT NULL,
  remember_token    VARCHAR(100),
  created_at        TIMESTAMPTZ,
  updated_at        TIMESTAMPTZ,
  CONSTRAINT users_email_unique UNIQUE (email)
);


-- ============================================================
-- OUTLET  (physical or virtual locations / branches)
-- ============================================================
CREATE TABLE outlet (
  outletId                 UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  outletName               VARCHAR(255) NOT NULL,
  outletStatus             SMALLINT     DEFAULT 1,
  outletAddress            TEXT,
  outletPhone              VARCHAR(255),
  outletWhatsApp           VARCHAR(20),
  outletEmail              VARCHAR(255),
  outletBillingName        VARCHAR(255),
  outletRUC                VARCHAR(255),
  outletLatLng             VARCHAR(100),
  outletDescription        VARCHAR(255),
  outletCreationDate       TIMESTAMPTZ  DEFAULT now(),
  outletNextExpirationDate TIMESTAMPTZ  DEFAULT now(),
  outletPurchaseOrderNo    INT,
  outletOrderTransferNo    INT,
  taxId                    UUID,        -- FK added after taxonomy is created (see below)
  companyId                UUID         NOT NULL REFERENCES company(companyId),
  data                     JSONB        NOT NULL DEFAULT '{}'
  -- data absorbs: outletBusinessHours, outletEcom, old text data field
);

CREATE INDEX idx_outlet_company ON outlet(companyId);


-- ============================================================
-- REGISTER  (POS terminals / cash registers)
-- ============================================================
CREATE TABLE register (
  registerId                    UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  registerName                  VARCHAR(255) NOT NULL,
  registerStatus                BOOLEAN      NOT NULL DEFAULT FALSE,
  registerCreationDate          TIMESTAMPTZ  NOT NULL DEFAULT now(),
  registerInvoiceAuth           INT,
  registerInvoiceAuthExpiration TIMESTAMPTZ,
  registerInvoicePrefix         VARCHAR(100),
  registerInvoiceSufix          VARCHAR(100),
  registerInvoiceNumber         INT,
  registerRemitoNumber          INT,
  registerQuoteNumber           INT,
  registerReturnNumber          INT,
  registerTicketNumber          INT,
  registerOrderNumber           INT,
  registerPedidoNumber          INT,
  registerBoletaNumber          INT,
  registerScheduleNumber        INT,
  registerDocsLeadingZeros      INT          DEFAULT 0,
  lastupdated                   TIMESTAMPTZ  NOT NULL DEFAULT '2018-01-01 00:00:00+00',
  sessionId                     BIGINT,
  outletId                      UUID         NOT NULL REFERENCES outlet(outletId),
  companyId                     UUID         NOT NULL REFERENCES company(companyId),
  data                          JSONB        NOT NULL DEFAULT '{}'
  -- data absorbs: registerInvoiceData, registerHotkeys, registerPrinters
);

CREATE INDEX idx_register_outlet  ON register(outletId);
CREATE INDEX idx_register_company ON register(companyId);


-- ============================================================
-- TAXONOMY  (categories, brands, tags, locations, taxes, etc.)
-- Differentiated by taxonomyType:
--   'category', 'brand', 'tag', 'location', 'tax',
--   'expenseCategory', 'tableTag', etc.
-- ============================================================
CREATE TABLE taxonomy (
  taxonomyId    UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  taxonomyName  TEXT         NOT NULL,
  taxonomyType  VARCHAR(255) NOT NULL,
  taxonomyExtra TEXT,
  sourceId      UUID,        -- polymorphic parent (e.g. parent category UUID)
  outletId      UUID         REFERENCES outlet(outletId),
  companyId     UUID         REFERENCES company(companyId)
);

CREATE INDEX idx_taxonomy_type_company ON taxonomy(taxonomyType, companyId);
CREATE INDEX idx_taxonomy_type_outlet  ON taxonomy(taxonomyType, outletId);
CREATE INDEX idx_taxonomy_name         ON taxonomy(taxonomyName, taxonomyType, companyId);

-- Deferred FK from outlet.taxId
ALTER TABLE outlet ADD CONSTRAINT fk_outlet_tax
  FOREIGN KEY (taxId) REFERENCES taxonomy(taxonomyId);


-- ============================================================
-- CONTACT  (customers, suppliers, POS staff — unified by type)
-- type: 0=staff/user  1=customer  2=supplier
-- Dropped: contactRealId (was legacy int copy of PK)
--          contactUID (use contactId UUID directly)
-- ============================================================
CREATE TABLE contact (
  contactId                   UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  contactName                 VARCHAR(255)  NOT NULL DEFAULT '',
  contactSecondName           VARCHAR(255),
  contactEmail                VARCHAR(255),
  contactAddress              TEXT,
  contactAddress2             VARCHAR(255),
  contactPhone                VARCHAR(255),
  contactPhone2               VARCHAR(255),
  contactNote                 TEXT,
  contactCity                 VARCHAR(255),
  contactLocation             VARCHAR(255),
  contactCountry              VARCHAR(5),
  contactTIN                  VARCHAR(255),  -- RUC / company tax ID
  contactCI                   VARCHAR(30),   -- cedula / national personal ID (was INT)
  contactDate                 TIMESTAMPTZ   NOT NULL DEFAULT now(),
  contactBirthDay             DATE,
  contactPassword             CHAR(68),
  contactLoyalty              INT           NOT NULL DEFAULT 1,
  contactLoyaltyAmount        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  contactStoreCredit          DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  contactCreditable           BOOLEAN       DEFAULT TRUE,
  contactCreditLine           DECIMAL(15,2),
  contactStatus               SMALLINT      DEFAULT 1,
  contactLastNotificationSeen TIMESTAMPTZ,
  debtLastNotify              TIMESTAMPTZ,
  type                        SMALLINT      NOT NULL DEFAULT 0,
  main                        VARCHAR(5),
  role                        SMALLINT,
  lockPass                    SMALLINT,
  salt                        CHAR(16),
  parentId                    UUID          REFERENCES contact(contactId),
  categoryId                  UUID          REFERENCES taxonomy(taxonomyId),
  userId                      UUID          REFERENCES contact(contactId),  -- created by
  outletId                    UUID          REFERENCES outlet(outletId),
  companyId                   UUID          NOT NULL REFERENCES company(companyId),
  updated_at                  TIMESTAMPTZ,
  data                        JSONB         NOT NULL DEFAULT '{}'
  -- data absorbs: contactGender, contactColor, contactInCalendar,
  -- contactCalendarPosition, contactTrackLocation, contactFixedComission,
  -- contactLatLng, old text data field
);

CREATE INDEX idx_contact_company ON contact(companyId);
CREATE INDEX idx_contact_type    ON contact(type, companyId);
CREATE INDEX idx_contact_email   ON contact(contactEmail, companyId);
CREATE INDEX idx_contact_phone   ON contact(contactPhone, companyId);
CREATE INDEX idx_contact_tin     ON contact(contactTIN, companyId);
CREATE INDEX idx_contact_name    ON contact(contactName, companyId);
CREATE INDEX idx_contact_status  ON contact(contactStatus, companyId);
CREATE INDEX idx_contact_updated ON contact(updated_at, companyId);


-- ============================================================
-- ITEM
-- ============================================================
CREATE TABLE item (
  itemId             UUID           PRIMARY KEY DEFAULT gen_random_uuid(),
  itemName           VARCHAR(255)   NOT NULL,
  itemDate           TIMESTAMPTZ    NOT NULL DEFAULT now(),
  itemSKU            VARCHAR(255),
  itemCost           DECIMAL(15,2),   -- avg COGS; updated by inventory movements
  itemPrice          DECIMAL(15,2),
  itemIsParent       BOOLEAN        DEFAULT FALSE,
  itemParentId       UUID           REFERENCES item(itemId),
  itemType           VARCHAR(25)    DEFAULT 'product',
  itemImage          VARCHAR(10)    DEFAULT 'false',
  itemStatus         SMALLINT       DEFAULT 1,
  itemTrackInventory BOOLEAN        DEFAULT FALSE,
  itemCanSale        BOOLEAN        DEFAULT TRUE,
  itemTaxExcluded    DECIMAL(15,2),
  itemDiscount       DECIMAL(15,10) DEFAULT 0,
  itemUOM            VARCHAR(50),    -- units of measurement
  itemSort           INT            DEFAULT 99999,
  itemProduction     BOOLEAN        DEFAULT FALSE,
  taxId              UUID           REFERENCES taxonomy(taxonomyId),
  brandId            UUID           REFERENCES taxonomy(taxonomyId),
  categoryId         UUID           REFERENCES taxonomy(taxonomyId),
  supplierId         UUID           REFERENCES contact(contactId),
  locationId         UUID           REFERENCES taxonomy(taxonomyId),
  outletId           UUID           REFERENCES outlet(outletId),
  companyId          UUID           NOT NULL REFERENCES company(companyId),
  updated_at         TIMESTAMPTZ,
  data               JSONB          NOT NULL DEFAULT '{}'
  -- data absorbs: itemDescription, itemProcedure, itemUpsellDescription,
  -- itemDateHour, itemCurrencies, itemComboAddons, itemEcom, itemFeatured,
  -- itemWaste, itemSessions, itemDuration, autoReOrder, autoReOrderLevel,
  -- itemComissionPercent, itemComissionType, itemPricePercent, itemPriceType,
  -- old text data field
);

CREATE INDEX idx_item_company     ON item(companyId);
CREATE INDEX idx_item_status_type ON item(companyId, itemStatus, itemType);
CREATE INDEX idx_item_track_inv   ON item(itemTrackInventory, itemStatus, itemType, companyId);
CREATE INDEX idx_item_category    ON item(categoryId);
CREATE INDEX idx_item_brand       ON item(brandId);
CREATE INDEX idx_item_sku         ON item(itemSKU, companyId);
CREATE INDEX idx_item_parent      ON item(itemParentId);
CREATE INDEX idx_item_sort        ON item(companyId, itemSort);
CREATE INDEX idx_item_updated     ON item(updated_at, companyId);


-- ============================================================
-- TRANSACTION
-- ============================================================
-- transactionType:  0=sale, 1=purchase, 2=saved, 3=credit-sale,
--   4=credit-purchase, 5=credit-payment, 6=return, 7=void,
--   8=recurring, 9=quote, 10=delivery, 11=table, 12=order, 13=scheduled
-- transactionStatus: 1=pending, 2=in-process, 3=on-the-way,
--   4=finalized, 5=voided, 6=other
CREATE TABLE transaction (
  transactionId        UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  transactionDate      TIMESTAMPTZ   NOT NULL DEFAULT now(),
  transactionDiscount  DECIMAL(15,2),
  transactionTax       DECIMAL(15,2),
  transactionTotal     DECIMAL(15,2) NOT NULL,
  transactionUnitsSold INT,
  transactionPaymentType TEXT,
  transactionType      SMALLINT,
  transactionName      VARCHAR(100),
  transactionNote      VARCHAR(255),
  transactionParentId  UUID          REFERENCES transaction(transactionId),
  transactionComplete  BOOLEAN       DEFAULT TRUE,
  transactionDueDate   TIMESTAMPTZ,
  transactionStatus    SMALLINT      DEFAULT 1,
  transactionUID       VARCHAR(50)   UNIQUE,     -- app-generated UID (was bigint)
  transactionCurrency  VARCHAR(3),
  fromDate             TIMESTAMPTZ,
  toDate               TIMESTAMPTZ,
  invoiceNo            BIGINT,
  invoicePrefix        VARCHAR(150),
  tableno              INT,
  timestamp            BIGINT,
  packageId            UUID,
  categoryTransId      UUID          REFERENCES taxonomy(taxonomyId),
  customerId           UUID          REFERENCES contact(contactId),
  registerId           UUID          REFERENCES register(registerId),
  userId               UUID          NOT NULL REFERENCES contact(contactId),
  responsibleId        UUID          REFERENCES contact(contactId),
  supplierId           UUID          REFERENCES contact(contactId),
  outletId             UUID          NOT NULL REFERENCES outlet(outletId),
  companyId            UUID          NOT NULL REFERENCES company(companyId),
  updated_at           TIMESTAMPTZ,
  meta                 JSONB         NOT NULL DEFAULT '{}'
  -- meta absorbs: transactionDetails, transactionLocation, tags,
  -- and any future per-transaction metadata
);

CREATE INDEX idx_tx_date            ON transaction(transactionDate);
CREATE INDEX idx_tx_company_type    ON transaction(companyId, transactionType, transactionDate);
CREATE INDEX idx_tx_company_reg     ON transaction(companyId, registerId, transactionType, transactionDate);
CREATE INDEX idx_tx_company_invoice ON transaction(companyId, registerId, transactionType, invoiceNo, transactionDate);
CREATE INDEX idx_tx_customer        ON transaction(customerId, userId);
CREATE INDEX idx_tx_outlet          ON transaction(outletId);
CREATE INDEX idx_tx_parent          ON transaction(transactionParentId);
CREATE INDEX idx_tx_status          ON transaction(transactionStatus, companyId);
CREATE INDEX idx_tx_updated         ON transaction(updated_at);


-- ============================================================
-- ITEM_SOLD
-- ============================================================
CREATE TABLE itemSold (
  itemSoldId          UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  itemSoldTotal       DECIMAL(15,2) NOT NULL,
  itemSoldTax         DECIMAL(15,2),
  itemSoldDate        TIMESTAMPTZ   NOT NULL DEFAULT now(),
  itemSoldUnits       DECIMAL(15,3),  -- was float; DECIMAL avoids floating-point issues
  itemSoldDiscount    DECIMAL(15,2),
  itemSoldCOGS        DECIMAL(15,2),
  itemSoldComission   DECIMAL(15,2),
  itemSoldDescription VARCHAR(255),
  itemSoldParent      UUID          REFERENCES item(itemId),    -- parent compound item
  itemSoldCategory    UUID          REFERENCES taxonomy(taxonomyId),
  itemId              UUID          NOT NULL REFERENCES item(itemId),
  userId              UUID          REFERENCES contact(contactId),
  transactionId       UUID          NOT NULL REFERENCES transaction(transactionId),
  meta                JSONB         NOT NULL DEFAULT '{}'
  -- meta: future per-line metadata (modifiers, prep notes, custom fields)
);

CREATE INDEX idx_itemsold_tx   ON itemSold(transactionId);
CREATE INDEX idx_itemsold_date ON itemSold(itemSoldDate);
CREATE INDEX idx_itemsold_item ON itemSold(itemId);
CREATE INDEX idx_itemsold_user ON itemSold(userId);


-- ============================================================
-- INVENTORY  (stock batches / purchase lots)
-- ============================================================
CREATE TABLE inventory (
  inventoryId             UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  inventoryDate           TIMESTAMPTZ   NOT NULL DEFAULT now(),
  inventoryChangedDate    TIMESTAMPTZ,
  inventoryCount          DECIMAL(15,3) NOT NULL,
  inventoryCOGS           DECIMAL(15,2),
  inventoryUID            VARCHAR(255),
  inventoryExpirationDate TIMESTAMPTZ,
  inventoryType           SMALLINT      DEFAULT 0,  -- 0=active, 1=waste/inactive, 2=sold
  inventorySource         VARCHAR(50),
  companyId               UUID          NOT NULL REFERENCES company(companyId),
  outletId                UUID          NOT NULL REFERENCES outlet(outletId),
  itemId                  UUID          NOT NULL REFERENCES item(itemId),
  supplierId              UUID          REFERENCES contact(contactId)
);

CREATE INDEX idx_inventory_item    ON inventory(itemId, outletId);
CREATE INDEX idx_inventory_company ON inventory(companyId);
CREATE INDEX idx_inventory_date    ON inventory(inventoryDate);
CREATE INDEX idx_inventory_type    ON inventory(inventoryType, itemId);


-- ============================================================
-- INVENTORY_COUNT  (physical stock count sessions)
-- ============================================================
CREATE TABLE inventoryCount (
  inventoryCountId      UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  inventoryCountDate    TIMESTAMPTZ  NOT NULL DEFAULT now(),
  inventoryCountUpdated TIMESTAMPTZ,
  inventoryCountName    VARCHAR(100) NOT NULL,
  inventoryCountStatus  SMALLINT     DEFAULT 0,  -- 0=pending, 1=saved, 2=finalized
  inventoryCountCounted DECIMAL(15,2),
  inventoryCountNote    VARCHAR(300),
  inventoryCountBlind   BOOLEAN,
  userId                UUID         NOT NULL REFERENCES contact(contactId),
  outletId              UUID         NOT NULL REFERENCES outlet(outletId),
  companyId             UUID         NOT NULL REFERENCES company(companyId),
  data                  JSONB        NOT NULL DEFAULT '{}'
  -- data absorbs: inventoryCountData, inventoryCountedData, old text data field
);

CREATE INDEX idx_invcount_company ON inventoryCount(companyId, outletId);


-- ============================================================
-- ITEM_DELETED  (soft-delete audit log)
-- ============================================================
CREATE TABLE itemDeleted (
  itemId    UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  date      TIMESTAMPTZ  NOT NULL DEFAULT now(),
  data      VARCHAR(255),
  outletId  UUID         NOT NULL REFERENCES outlet(outletId),
  companyId UUID         NOT NULL REFERENCES company(companyId)
);

CREATE INDEX idx_itemdeleted_company ON itemDeleted(companyId, outletId);


-- ============================================================
-- STOCK  (running stock movement ledger)
-- ============================================================
CREATE TABLE stock (
  stockId         UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  stockDate       TIMESTAMPTZ   DEFAULT now(),
  stockSource     VARCHAR(255),
  stockCount      DECIMAL(15,3),
  stockCOGS       DECIMAL(15,2) DEFAULT 0.00,
  stockOnHand     DECIMAL(15,3) DEFAULT 0.000,
  stockOnHandCOGS DECIMAL(15,2),
  stockNote       VARCHAR(255),
  itemId          UUID          NOT NULL REFERENCES item(itemId),
  transactionId   UUID          REFERENCES transaction(transactionId),
  userId          UUID          REFERENCES contact(contactId),
  supplierId      UUID          REFERENCES contact(contactId),
  outletId        UUID          NOT NULL REFERENCES outlet(outletId),
  locationId      UUID          REFERENCES taxonomy(taxonomyId),
  companyId       UUID          NOT NULL REFERENCES company(companyId)
);

CREATE INDEX idx_stock_item     ON stock(itemId);
CREATE INDEX idx_stock_company  ON stock(companyId);
CREATE INDEX idx_stock_outlet   ON stock(outletId);
CREATE INDEX idx_stock_location ON stock(locationId);
CREATE INDEX idx_stock_date     ON stock(stockDate);


-- ============================================================
-- STOCK_TRIGGER  (materialized per-item-outlet current count)
-- ============================================================
CREATE TABLE stockTrigger (
  stockTriggerId    UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  stockTriggerCount DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  itemId            UUID          NOT NULL REFERENCES item(itemId),
  outletId          UUID          NOT NULL REFERENCES outlet(outletId),
  CONSTRAINT uq_stocktrigger_item_outlet UNIQUE (itemId, outletId)
);

CREATE INDEX idx_stocktrigger_outlet_item ON stockTrigger(outletId, itemId);


-- ============================================================
-- TO_TRANSACTION  (transaction group / bundle links)
-- ============================================================
CREATE TABLE toTransaction (
  toTransactionId UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  parentId        UUID REFERENCES transaction(transactionId),
  transactionId   UUID REFERENCES transaction(transactionId)
);

CREATE INDEX idx_totransaction_parent ON toTransaction(parentId);
CREATE INDEX idx_totransaction_child  ON toTransaction(transactionId);


-- ============================================================
-- TO_TAG  (polymorphic tag assignments)
-- toTagType: 0=sale/items-in-sale  1=contact  2=item-search
-- parentId is polymorphic: transactionId | contactId | itemId
-- ============================================================
CREATE TABLE toTag (
  toTagId   UUID     PRIMARY KEY DEFAULT gen_random_uuid(),
  toTagType SMALLINT NOT NULL DEFAULT 0,
  parentId  UUID     NOT NULL,   -- polymorphic — no FK constraint by design
  tagId     UUID     NOT NULL REFERENCES taxonomy(taxonomyId)
);

CREATE INDEX idx_totag_parent ON toTag(parentId);
CREATE INDEX idx_totag_tag    ON toTag(tagId);
CREATE INDEX idx_totag_type   ON toTag(toTagType, parentId);


-- ============================================================
-- TO_TAX_OBJ  (itemised tax lines per transaction)
-- ============================================================
CREATE TABLE toTaxObj (
  toTaxObjId    UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  toTaxObjText  VARCHAR(255) NOT NULL,
  transactionId UUID         NOT NULL REFERENCES transaction(transactionId),
  companyId     UUID         NOT NULL REFERENCES company(companyId)
);

CREATE INDEX idx_totaxobj_tx ON toTaxObj(transactionId, companyId);


-- ============================================================
-- TO_ADDRESS  (delivery address snapshot per transaction)
-- FK to customerAddress added after that table is created (below)
-- ============================================================
CREATE TABLE toAddress (
  toAddressId       UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  customerAddressId UUID NOT NULL,
  transactionId     UUID NOT NULL REFERENCES transaction(transactionId)
);

CREATE INDEX idx_toaddress_tx ON toAddress(customerAddressId, transactionId);


-- ============================================================
-- TO_CATEGORY  (item ↔ extra-category many-to-many)
-- ============================================================
CREATE TABLE toCategory (
  toCategoryId UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  categoryId   UUID NOT NULL REFERENCES taxonomy(taxonomyId),
  parentId     UUID NOT NULL   -- polymorphic: item, outlet, etc.
);

CREATE INDEX idx_tocategory_category ON toCategory(categoryId);
CREATE INDEX idx_tocategory_parent   ON toCategory(parentId);


-- ============================================================
-- TO_COMPOUND  (composite item → ingredient items)
-- ============================================================
CREATE TABLE toCompound (
  toCompoundId          UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  toCompoundQty         DECIMAL(15,3),
  toCompoundOrder       SMALLINT      NOT NULL,
  toCompoundPreselected UUID          REFERENCES item(itemId),
  itemId                UUID          REFERENCES item(itemId),   -- ingredient
  compoundId            UUID          REFERENCES item(itemId)    -- parent compound item
);

CREATE INDEX idx_tocompound_compound ON toCompound(compoundId);
CREATE INDEX idx_tocompound_item     ON toCompound(itemId);


-- ============================================================
-- TO_CONTACT  (contact group / related-contact links)
-- ============================================================
CREATE TABLE toContact (
  toContactId UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  parentId    UUID REFERENCES contact(contactId),
  contactId   UUID REFERENCES contact(contactId)
);

CREATE INDEX idx_tocontact_parent ON toContact(parentId);


-- ============================================================
-- TO_LOCATION  (item ↔ storage-location current stock)
-- ============================================================
CREATE TABLE toLocation (
  toLocationId    UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  toLocationCount DECIMAL(15,3) DEFAULT 0.000,
  locationId      UUID          NOT NULL REFERENCES taxonomy(taxonomyId),
  outletId        UUID          REFERENCES outlet(outletId),
  itemId          UUID          REFERENCES item(itemId)
);

CREATE INDEX idx_tolocation_location ON toLocation(locationId);
CREATE INDEX idx_tolocation_item     ON toLocation(itemId);
CREATE INDEX idx_tolocation_loc_item ON toLocation(locationId, itemId);


-- ============================================================
-- TO_PAYMENT_METHOD  (allowed payment methods per outlet/register)
-- parentId: typically outletId or registerId
-- ============================================================
CREATE TABLE toPaymentMethod (
  toPaymentMethodId     UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  toPaymentMethodType   SMALLINT     NOT NULL DEFAULT 0,
  -- 0=customer-facing (sale/receipt)  1=egress (purchase, credit payment)
  toPaymentMethodExtras VARCHAR(255),
  paymentMethodId       VARCHAR(15)  NOT NULL,  -- 'cash', 'creditcard', or UUID of custom method
  parentId              UUID         NOT NULL   -- REFERENCES outlet(outletId)
);

CREATE INDEX idx_topm_parent ON toPaymentMethod(toPaymentMethodType, parentId, paymentMethodId);


-- ============================================================
-- TO_SCHEDULE_UID  (calendar schedule ↔ transaction link)
-- ============================================================
CREATE TABLE toScheduleUID (
  toScheduleUIDId UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
  scheduleId      UUID,                          -- reserved for future schedule table
  transactionUID  VARCHAR(50) REFERENCES transaction(transactionUID)
);

CREATE INDEX idx_toschedule_schedule ON toScheduleUID(scheduleId);
CREATE INDEX idx_toschedule_uid      ON toScheduleUID(transactionUID);


-- ============================================================
-- CUSTOMER_ADDRESS
-- ============================================================
CREATE TABLE customerAddress (
  customerAddressId       UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  customerAddressDate     VARCHAR(100),
  customerAddressName     VARCHAR(100),
  customerAddressText     VARCHAR(500),
  customerAddressLat      DECIMAL(10,8),
  customerAddressLng      DECIMAL(10,8),
  customerAddressDefault  BOOLEAN,
  customerAddressLocation VARCHAR(100),
  customerAddressCity     VARCHAR(100),
  customerId              UUID          NOT NULL REFERENCES contact(contactId),
  companyId               UUID          NOT NULL REFERENCES company(companyId),
  updated_at              TIMESTAMPTZ
);

CREATE INDEX idx_custaddr_company  ON customerAddress(companyId, customerId, updated_at);
CREATE INDEX idx_custaddr_customer ON customerAddress(customerId);

-- Deferred FK from toAddress
ALTER TABLE toAddress ADD CONSTRAINT fk_toaddress_custaddr
  FOREIGN KEY (customerAddressId) REFERENCES customerAddress(customerAddressId);


-- ============================================================
-- CUSTOMER_RECORD  (custom form / intake form definitions)
-- ============================================================
CREATE TABLE customerRecord (
  customerRecordId   UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  customerRecordSort SMALLINT,
  customerRecordName VARCHAR(255) NOT NULL,
  companyId          UUID         NOT NULL REFERENCES company(companyId),
  data               JSONB        NOT NULL DEFAULT '{}'
);

CREATE INDEX idx_custrecord_company ON customerRecord(companyId, customerRecordSort);


-- ============================================================
-- C_RECORD_FIELD  (field definitions for a customerRecord form)
-- ============================================================
CREATE TABLE cRecordField (
  cRecordFieldId       UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  cRecordFieldName     VARCHAR(255) NOT NULL,
  cRecordFieldType     SMALLINT     NOT NULL DEFAULT 0,
  cRecordFieldProgress BOOLEAN,
  cRecordFieldExtra    BOOLEAN,
  cRecordFieldSort     SMALLINT,
  customerRecordId     UUID         NOT NULL REFERENCES customerRecord(customerRecordId)
);

CREATE INDEX idx_crecordfield_record ON cRecordField(customerRecordId);


-- ============================================================
-- C_RECORD_VALUE  (form field values per contact)
-- ============================================================
CREATE TABLE cRecordValue (
  cRecordValueId   UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  cRecordValueDate TIMESTAMPTZ  NOT NULL DEFAULT now(),
  cRecordValueName VARCHAR(500),
  cRecordFieldId   UUID         NOT NULL REFERENCES cRecordField(cRecordFieldId),
  customerId       UUID         NOT NULL REFERENCES contact(contactId)
);

CREATE INDEX idx_crecordvalue_customer ON cRecordValue(customerId, cRecordValueDate, cRecordFieldId);


-- ============================================================
-- CONTACT_NOTE
-- ============================================================
CREATE TABLE contactNote (
  contactNoteId   UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
  contactNoteText TEXT,
  contactNoteDate TIMESTAMPTZ DEFAULT now(),
  customerId      UUID        NOT NULL REFERENCES contact(contactId),
  companyId       UUID        NOT NULL REFERENCES company(companyId)
);

CREATE INDEX idx_contactnote_customer ON contactNote(customerId, companyId);


-- ============================================================
-- CAMPAIGN  (marketing campaigns — SMS / email blasts)
-- ============================================================
CREATE TABLE campaign (
  campaignId      UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  campaignName    VARCHAR(255)  NOT NULL,
  campaignDate    TIMESTAMPTZ,
  campaignQtySent INT,
  campaignViewed  INT,
  campaignSales   INT,
  campaignAmount  DECIMAL(15,2),
  userId          UUID          REFERENCES contact(contactId),
  outletId        UUID          REFERENCES outlet(outletId),
  companyId       UUID          NOT NULL REFERENCES company(companyId)
);

CREATE INDEX idx_campaign_company ON campaign(companyId);
CREATE INDEX idx_campaign_outlet  ON campaign(outletId);


-- ============================================================
-- COMISSION  (sales commission records)
-- ============================================================
CREATE TABLE comission (
  comissionId     UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  comissionDate   TIMESTAMPTZ   NOT NULL DEFAULT now(),
  comissionTotal  DECIMAL(15,2),
  comissionSource VARCHAR(20),
  transactionId   UUID          REFERENCES transaction(transactionId),
  userId          UUID          NOT NULL REFERENCES contact(contactId),
  outletId        UUID          REFERENCES outlet(outletId),
  companyId       UUID          REFERENCES company(companyId)
);

CREATE INDEX idx_comission_company ON comission(companyId, outletId);
CREATE INDEX idx_comission_user    ON comission(userId);
CREATE INDEX idx_comission_tx      ON comission(transactionId);


-- ============================================================
-- CPAYMENTS  (SaaS subscription payment orders)
-- ============================================================
CREATE TABLE cpayments (
  cpaymentsId      UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  cpaymentsDate    TIMESTAMPTZ   NOT NULL DEFAULT now(),
  cpaymentsAmount  DECIMAL(15,2) NOT NULL,
  cpaymentsOrder   BIGINT        NOT NULL,
  cpaymentsInvoice BIGINT        NOT NULL,
  cpaymentsStatus  SMALLINT      NOT NULL DEFAULT 0,
  companyId        UUID          NOT NULL REFERENCES company(companyId)
);

CREATE INDEX idx_cpayments_company ON cpayments(companyId);


-- ============================================================
-- DRAWER  (cash drawer open/close sessions)
-- ============================================================
CREATE TABLE drawer (
  drawerId           UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  drawerOpenDate     TIMESTAMPTZ   DEFAULT now(),
  drawerCloseDate    TIMESTAMPTZ,   -- NULL while drawer is still open
  drawerOpenAmount   DECIMAL(15,2),
  drawerCloseAmount  DECIMAL(15,2),
  drawerUID          BIGINT        NOT NULL,
  drawerUserOpen     UUID          NOT NULL REFERENCES contact(contactId),
  drawerUserClose    UUID          REFERENCES contact(contactId),  -- NULL while open
  drawerCloseDetails TEXT,
  registerId         UUID          NOT NULL REFERENCES register(registerId),
  outletId           UUID          NOT NULL REFERENCES outlet(outletId),
  companyId          UUID          NOT NULL REFERENCES company(companyId)
);

CREATE INDEX idx_drawer_company  ON drawer(companyId);
CREATE INDEX idx_drawer_register ON drawer(registerId);
CREATE INDEX idx_drawer_outlet   ON drawer(outletId);
CREATE INDEX idx_drawer_open     ON drawer(drawerOpenDate);


-- ============================================================
-- EXPENSES
-- ============================================================
CREATE TABLE expenses (
  expensesId          UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  expensesNameId      UUID          NOT NULL REFERENCES taxonomy(taxonomyId),  -- expense category
  expensesAmount      DECIMAL(15,2) NOT NULL,
  expensesDescription TEXT,
  expensesDate        TIMESTAMPTZ   NOT NULL DEFAULT now(),
  expensesUID         BIGINT        UNIQUE,
  type                SMALLINT,
  userId              UUID          REFERENCES contact(contactId),
  registerId          UUID          REFERENCES register(registerId),
  outletId            UUID          NOT NULL REFERENCES outlet(outletId),
  companyId           UUID          NOT NULL REFERENCES company(companyId)
);

CREATE INDEX idx_expenses_register ON expenses(registerId, outletId, companyId);
CREATE INDEX idx_expenses_company  ON expenses(companyId);
CREATE INDEX idx_expenses_date     ON expenses(expensesDate, companyId);


-- ============================================================
-- FILES
-- ============================================================
CREATE TABLE files (
  filesId   UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  filesName VARCHAR(100) NOT NULL,
  filesType VARCHAR(20)  NOT NULL,
  sourceId  UUID,        -- polymorphic source
  companyId UUID         REFERENCES company(companyId)
);

CREATE INDEX idx_files_company ON files(companyId, sourceId);


-- ============================================================
-- GIFT_CARD_SOLD
-- ============================================================
CREATE TABLE giftCardSold (
  giftCardSoldId              UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  giftCardSoldValue           DECIMAL(15,2) NOT NULL,
  giftCardSoldExpires         TIMESTAMPTZ,
  giftCardSoldStatus          BOOLEAN       NOT NULL DEFAULT TRUE,
  giftCardSoldCode            INT,
  giftCardSoldNote            TEXT,
  giftCardSoldLastUsed        TIMESTAMPTZ,
  giftCardSoldSendDate        TIMESTAMPTZ,
  giftCardSoldBeneficiaryNote VARCHAR(255),
  giftCardSoldBeneficiaryId   UUID          REFERENCES contact(contactId),
  giftCardSoldColor           VARCHAR(60),
  timestamp                   BIGINT,
  transactionId               UUID          NOT NULL REFERENCES transaction(transactionId),
  outletId                    UUID          NOT NULL REFERENCES outlet(outletId),
  companyId                   UUID          NOT NULL REFERENCES company(companyId)
);

CREATE INDEX idx_giftcard_code    ON giftCardSold(giftCardSoldCode);
CREATE INDEX idx_giftcard_outlet  ON giftCardSold(outletId);
CREATE INDEX idx_giftcard_company ON giftCardSold(companyId);


-- ============================================================
-- NOTIFY  (in-app push notifications)
-- ============================================================
CREATE TABLE notify (
  notifyId       UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  notifyTitle    VARCHAR(100),
  notifyDate     TIMESTAMPTZ,
  notifyMessage  VARCHAR(300),
  notifyLink     VARCHAR(255),
  notifyType     SMALLINT,
  notifyMode     SMALLINT,
  notifyStatus   SMALLINT,
  notifyRegister BOOLEAN,
  outletId       UUID         REFERENCES outlet(outletId),
  companyId      UUID         REFERENCES company(companyId)
);

CREATE INDEX idx_notify_company ON notify(companyId);
CREATE INDEX idx_notify_date    ON notify(notifyDate, notifyStatus);


-- ============================================================
-- ACCOUNT_CATEGORY  (chart of accounts)
-- ============================================================
CREATE TABLE accountCategory (
  accountCategoryId         UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  accountCategoryName       VARCHAR(255) NOT NULL DEFAULT '',
  accountCategoryParentId   UUID         REFERENCES accountCategory(accountCategoryId),
  accountCategoryPosition   SMALLINT,
  accountCategoryExternalId INT,
  companyId                 UUID         REFERENCES company(companyId)
);

CREATE INDEX idx_acctcat_parent  ON accountCategory(accountCategoryParentId);
CREATE INDEX idx_acctcat_company ON accountCategory(companyId);


-- ============================================================
-- ACTIVITY_LOG
-- ============================================================
CREATE TABLE activityLog (
  activityLogId   UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  activityLogDate TIMESTAMPTZ  NOT NULL DEFAULT now(),
  activityLogType VARCHAR(100) NOT NULL,
  activityLogData TEXT,
  userId          UUID         NOT NULL REFERENCES contact(contactId),
  outletId        UUID         NOT NULL REFERENCES outlet(outletId),
  companyId       UUID         NOT NULL REFERENCES company(companyId)
);

CREATE INDEX idx_actlog_company ON activityLog(companyId);
CREATE INDEX idx_actlog_outlet  ON activityLog(outletId);
CREATE INDEX idx_actlog_user    ON activityLog(userId);
CREATE INDEX idx_actlog_date    ON activityLog(activityLogDate);


-- ============================================================
-- ATTENDANCE  (employee clock-in / clock-out)
-- ============================================================
CREATE TABLE attendance (
  attendanceId        UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
  attendanceOpenDate  TIMESTAMPTZ NOT NULL DEFAULT now(),
  attendanceCloseDate TIMESTAMPTZ,
  userId              UUID        NOT NULL REFERENCES contact(contactId),
  outletId            UUID        NOT NULL REFERENCES outlet(outletId),
  companyId           UUID        NOT NULL REFERENCES company(companyId)
);

CREATE INDEX idx_attendance_company ON attendance(companyId);
CREATE INDEX idx_attendance_user    ON attendance(userId);
CREATE INDEX idx_attendance_date    ON attendance(attendanceOpenDate);


-- ============================================================
-- PAYMENT_METHODS  (custom payment method names per company)
-- ============================================================
CREATE TABLE paymentMethods (
  paymentMethodsId   UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  paymentMethodsName VARCHAR(100) NOT NULL,
  companyId          UUID         NOT NULL REFERENCES company(companyId)
);

CREATE INDEX idx_pm_company ON paymentMethods(companyId);


-- ============================================================
-- PRICE_LIST
-- ============================================================
CREATE TABLE priceList (
  ID        UUID  PRIMARY KEY DEFAULT gen_random_uuid(),
  data      JSONB NOT NULL DEFAULT '{}',
  companyId UUID  NOT NULL REFERENCES company(companyId)
);

CREATE INDEX idx_pricelist_company ON priceList(companyId);


-- ============================================================
-- PRINT_SERVER  (kitchen / receipt print queue)
-- ============================================================
CREATE TABLE printServer (
  ID            UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
  date          TIMESTAMPTZ NOT NULL DEFAULT now(),
  data          JSONB,
  status        SMALLINT,
  transactionId UUID        NOT NULL REFERENCES transaction(transactionId),
  outletId      UUID        REFERENCES outlet(outletId),
  companyId     UUID        NOT NULL REFERENCES company(companyId)
);

CREATE INDEX idx_printserver_lookup ON printServer(date, status, transactionId, outletId, companyId);


-- ============================================================
-- PROCESSOR_ID  (payment processor configurations per company)
-- ============================================================
CREATE TABLE processorId (
  processorId        UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  processorName      VARCHAR(100) NOT NULL,
  processorComission DECIMAL(5,2),
  companyId          UUID         NOT NULL REFERENCES company(companyId)
);

CREATE INDEX idx_processor_company ON processorId(companyId);


-- ============================================================
-- PRODUCTION  (manufactured / produced items log)
-- ============================================================
CREATE TABLE production (
  productionId         UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  productionDate       TIMESTAMPTZ   NOT NULL DEFAULT now(),
  productionCount      DECIMAL(15,3) NOT NULL,
  productionRecipe     TEXT,
  productionType       BOOLEAN,
  productionCOGS       DECIMAL(15,3),
  productionWasteValue DECIMAL(15,3),
  itemId               UUID          NOT NULL REFERENCES item(itemId),
  userId               UUID          NOT NULL REFERENCES contact(contactId),
  outletId             UUID          NOT NULL REFERENCES outlet(outletId),
  companyId            UUID          NOT NULL REFERENCES company(companyId)
);

CREATE INDEX idx_production_item    ON production(itemId, userId, outletId);
CREATE INDEX idx_production_company ON production(companyId);
CREATE INDEX idx_production_date    ON production(productionDate);


-- ============================================================
-- RECURRING  (recurring billing / scheduled transactions)
-- recurringStatus: 0=ended, 1=active, 2=paused
-- recurringFrecuency: 'day', 'week', 'month', 'quarterly', 'year'
-- ============================================================
CREATE TABLE recurring (
  recurringId              UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
  recurringNextDate        TIMESTAMPTZ DEFAULT now(),
  recurringEndDate         TIMESTAMPTZ,  -- NULL = never ends (replaces '0000-00-00' sentinel)
  recurringFrecuency       VARCHAR(50)  NOT NULL,
  recurringStatus          SMALLINT     NOT NULL DEFAULT 1,
  recurringTransactionData VARCHAR(255),
  companyId                UUID         NOT NULL REFERENCES company(companyId),
  data                     JSONB        NOT NULL DEFAULT '{}'
  -- data absorbs: recurringSaleData
);

CREATE INDEX idx_recurring_company ON recurring(companyId);
CREATE INDEX idx_recurring_next    ON recurring(recurringNextDate, recurringStatus);


-- ============================================================
-- REMINDER
-- ============================================================
CREATE TABLE reminder (
  reminderId   UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  reminderNote VARCHAR(500) NOT NULL,
  reminderDate TIMESTAMPTZ  NOT NULL DEFAULT now(),
  userId       UUID         NOT NULL REFERENCES contact(contactId),
  itemId       UUID         REFERENCES item(itemId),
  contactUID   UUID         REFERENCES contact(contactId),
  companyId    UUID         NOT NULL REFERENCES company(companyId)
);

CREATE INDEX idx_reminder_company ON reminder(companyId);
CREATE INDEX idx_reminder_user    ON reminder(userId);
CREATE INDEX idx_reminder_date    ON reminder(reminderDate);


-- ============================================================
-- SATISFACTION  (post-sale customer feedback / NPS)
-- ============================================================
CREATE TABLE satisfaction (
  satisfactionId      UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  satisfactionDate    TIMESTAMPTZ   NOT NULL DEFAULT now(),
  satisfactionLevel   SMALLINT      NOT NULL,
  satisfactionComment TEXT,
  transactionId       UUID          REFERENCES transaction(transactionId),
  customerId          UUID          REFERENCES contact(contactId),
  outletId            UUID          NOT NULL REFERENCES outlet(outletId),
  companyId           UUID          NOT NULL REFERENCES company(companyId)
);

CREATE INDEX idx_satisfaction_company ON satisfaction(companyId);
CREATE INDEX idx_satisfaction_outlet  ON satisfaction(outletId);
CREATE INDEX idx_satisfaction_date    ON satisfaction(satisfactionDate);


-- ============================================================
-- TABLE_TAGS  (restaurant floor-plan table ↔ taxonomy assignments)
-- tableId is polymorphic (outlet, register, etc.)
-- ============================================================
CREATE TABLE tableTags (
  tableId    UUID         NOT NULL,
  taxonomyId UUID         NOT NULL REFERENCES taxonomy(taxonomyId),
  type       VARCHAR(50)  NOT NULL,
  PRIMARY KEY (tableId, taxonomyId, type)
);

CREATE INDEX idx_tabletags_table ON tableTags(tableId, type);


-- ============================================================
-- TASKS
-- ============================================================
CREATE TABLE tasks (
  ID        UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  date      DATE,
  dueDate   DATE,
  type      VARCHAR(45),
  sourceId  VARCHAR(255),
  status    VARCHAR(20),
  outletId  UUID         REFERENCES outlet(outletId),
  companyId UUID         REFERENCES company(companyId),
  data      JSONB        NOT NULL DEFAULT '{}'
);

CREATE INDEX idx_tasks_company ON tasks(companyId);
CREATE INDEX idx_tasks_due     ON tasks(dueDate, status);
CREATE INDEX idx_tasks_type    ON tasks(type, companyId);


-- ============================================================
-- UPSELL  (item upsell / cross-sell relationships)
-- ============================================================
CREATE TABLE upsell (
  upsellId       UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  upsellParentId UUID REFERENCES item(itemId),
  upsellChildId  UUID REFERENCES item(itemId),
  companyId      UUID REFERENCES company(companyId)
);

CREATE INDEX idx_upsell_parent  ON upsell(upsellParentId);
CREATE INDEX idx_upsell_company ON upsell(companyId);


-- ============================================================
-- V_PAYMENTS  (virtual terminal / ePOS payment records)
-- ============================================================
CREATE TABLE vPayments (
  ID            UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  date          TIMESTAMPTZ   NOT NULL DEFAULT now(),
  payoutDate    TIMESTAMPTZ,
  depositedDate TIMESTAMPTZ,
  amount        DECIMAL(15,2) NOT NULL,
  payoutAmount  DECIMAL(15,2),
  comission     DECIMAL(15,2),
  tax           DECIMAL(15,2),
  deposited     SMALLINT,
  orderNo       VARCHAR(255)  NOT NULL,
  authCode      VARCHAR(255),
  operationNo   BIGINT,
  inBank        SMALLINT,
  status        VARCHAR(100)  NOT NULL,
  UID           VARCHAR(100),              -- denormalised transactionUID
  source        VARCHAR(100),
  transactionId UUID          REFERENCES transaction(transactionId),
  customerId    UUID          REFERENCES contact(contactId),
  userId        UUID          REFERENCES contact(contactId),
  outletId      UUID          NOT NULL REFERENCES outlet(outletId),
  companyId     UUID          NOT NULL REFERENCES company(companyId),
  updated_at    TIMESTAMPTZ,
  data          JSONB         NOT NULL DEFAULT '{}'
  -- data absorbs: processor response payload (was text data column)
);

CREATE INDEX idx_vpayments_order   ON vPayments(orderNo, UID, outletId, companyId);
CREATE INDEX idx_vpayments_auth    ON vPayments(operationNo, authCode);
CREATE INDEX idx_vpayments_company ON vPayments(companyId);
CREATE INDEX idx_vpayments_date    ON vPayments(date, status);
CREATE INDEX idx_vpayments_tx      ON vPayments(transactionId);


-- ============================================================
-- FAILED_JOBS  (Laravel queue — failed job archive)
-- ============================================================
CREATE TABLE failed_jobs (
  id         UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  uuid       VARCHAR(255) NOT NULL,
  connection TEXT         NOT NULL,
  queue      TEXT         NOT NULL,
  payload    TEXT         NOT NULL,
  exception  TEXT         NOT NULL,
  failed_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
  CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid)
);


-- ============================================================
-- MIGRATIONS  (Laravel migration log)
-- ============================================================
CREATE TABLE migrations (
  id        UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  migration VARCHAR(255) NOT NULL,
  batch     INT          NOT NULL
);


-- ============================================================
-- PASSWORD_RESETS  (Laravel password reset tokens)
-- ============================================================
CREATE TABLE password_resets (
  id         UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  email      VARCHAR(255) NOT NULL,
  token      VARCHAR(255) NOT NULL,
  created_at TIMESTAMPTZ
);

CREATE INDEX idx_password_resets_email ON password_resets(email);


-- ============================================================
-- PASSWORD_RESET_TOKENS  (Laravel 10+ password reset tokens)
-- ============================================================
CREATE TABLE password_reset_tokens (
  email      VARCHAR(255) PRIMARY KEY,
  token      VARCHAR(255) NOT NULL,
  created_at TIMESTAMPTZ
);


-- ============================================================
-- PERSONAL_ACCESS_TOKENS  (Laravel Sanctum tokens)
-- ============================================================
CREATE TABLE personal_access_tokens (
  id             UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  tokenable_type VARCHAR(255) NOT NULL,
  tokenable_id   UUID         NOT NULL,
  name           VARCHAR(255) NOT NULL,
  token          VARCHAR(64)  NOT NULL,
  abilities      TEXT,
  last_used_at   TIMESTAMPTZ,
  expires_at     TIMESTAMPTZ,
  created_at     TIMESTAMPTZ,
  updated_at     TIMESTAMPTZ,
  CONSTRAINT personal_access_tokens_token_unique UNIQUE (token)
);

CREATE INDEX idx_pat_tokenable ON personal_access_tokens(tokenable_type, tokenable_id);


-- ============================================================
-- End of schema — 47 tables
-- ============================================================
