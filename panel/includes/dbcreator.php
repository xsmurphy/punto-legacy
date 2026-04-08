<?php
include_once("../libraries/adodb/adodb.inc.php");

$db = ADONewConnection('mysqli');
$db->NConnect('localhost', 'incomepo_manager', 'AE?jrkc1@?p(');
//$db->selectDb('incomepo_905');
$db->cacheSecs 		= 3600*24;//cache 24 hs
$ADODB_CACHE_DIR 	= '../../cache/adodb';//desde root panel hasta el cache
$ADODB_COUNTRECS 	= true;
$NAMESPACE 			= 'incomepo_';
//$db->debug 			= true;

if(!isset($_GET['dbname'])){
	die('error');
}

$dbName = $db->Prepare($_GET['dbname']);
$dbName = $NAMESPACE . $dbName;

if($dbName){
	$chkDb = $db->Execute('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?',[$dbName]);
	if($chkDb){
		if($chkDb->RecordCount()>0){
			//DB EXISTE
			echo 'DB ' . $chkDb->fields[0] . ' ya existe';
		}else{
			//DB No existe

			$createDB = $db->Execute("CREATE DATABASE IF NOT EXISTS" . $dbName);
			if($createDB !== false){
				//DB created, add structure
				$db->selectDb($dbName);

				$db->Execute("
					CREATE TABLE `activityLog` (
					  `activityLogId` int(11) NOT NULL AUTO_INCREMENT,
					  `activityLogDate` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
					  `activityLogType` varchar(100) NOT NULL,
					  `activityLogData` text,
					  `userId` int(11) NOT NULL,
					  `outletId` int(11) NOT NULL,
					  `companyId` int(11) NOT NULL,
					  PRIMARY KEY (`activityLogId`)
					) ENGINE=MyISAM DEFAULT CHARSET=latin1;
				");

				$db->Execute("
				CREATE TABLE `company` (
				  `companyId` int(11) NOT NULL AUTO_INCREMENT,
				  `companyStatus` varchar(10) DEFAULT NULL,
				  `companyDate` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
				  `companyPlan` int(2) NOT NULL DEFAULT '0',
				  `companyUserActivated` int(1) NOT NULL DEFAULT '0',
				  `companyBalance` decimal(15,2) NOT NULL DEFAULT '0.00',
				  `companyLastUpdate` timestamp NULL DEFAULT NULL,
				  `customersLastUpdate` timestamp NULL DEFAULT NULL,
				  `itemsLastUpdate` timestamp NULL DEFAULT NULL,
				  `inventoryLastUpdate` timestamp NULL DEFAULT NULL,
				  `calendarLastUpdate` timestamp NULL DEFAULT NULL,
				  `companyExpiringDate` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
				  `companyDiscount` decimal(15,2) NOT NULL,
				  `companySMSCredit` int(11) DEFAULT NULL,
				  `accountId` int(11) DEFAULT NULL,
				  `parentId` int(11) DEFAULT NULL,
				  `isParent` tinyint(1) NOT NULL DEFAULT '0',
				  PRIMARY KEY (`companyId`)
				) ENGINE=InnoDB DEFAULT CHARSET=latin1;");

				$db->Execute("
				CREATE TABLE `contact` (
				  `contactId` int(11) NOT NULL AUTO_INCREMENT,
				  `contactRealId` int(11) NOT NULL,
				  `contactId` bigint(20) DEFAULT NULL,
				  `contactName` varchar(255) NOT NULL,
				  `contactSecondName` varchar(255) DEFAULT NULL,
				  `contactEmail` varchar(255) DEFAULT NULL,
				  `contactAddress` text,
				  `contactAddress2` varchar(255) DEFAULT NULL,
				  `contactPhone` varchar(255) DEFAULT NULL,
				  `contactPhone2` varchar(255) DEFAULT NULL,
				  `contactNote` text,
				  `contactCity` varchar(255) DEFAULT NULL,
				  `contactCountry` varchar(5) DEFAULT NULL,
				  `contactTIN` varchar(255) DEFAULT NULL,
				  `contactDate` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
				  `contactBirthDay` date DEFAULT NULL,
				  `contactPassword` char(68) DEFAULT NULL,
				  `contactLoyalty` int(1) NOT NULL DEFAULT '1',
				  `contactLoyaltyAmount` decimal(15,2) NOT NULL DEFAULT '0.00',
				  `contactStoreCredit` decimal(15,2) NOT NULL,
				  `contactCreditable` tinyint(1) DEFAULT '1',
				  `contactCreditLine` decimal(15,2) DEFAULT NULL,
				  `contactStatus` tinyint(2) DEFAULT '1',
				  `contactGender` varchar(10) DEFAULT NULL,
				  `contactColor` varchar(7) DEFAULT NULL,
				  `contactInCalendar` tinyint(1) DEFAULT NULL,
				  `categoryId` int(11) DEFAULT NULL,
				  `debtLastNotify` datetime DEFAULT NULL,
				  `type` smallint(2) NOT NULL DEFAULT '0' COMMENT '0 = User | 1 = Customer | 2 = Supplier',
				  `main` varchar(5) DEFAULT NULL,
				  `role` smallint(5) DEFAULT NULL,
				  `lockPass` smallint(4) DEFAULT NULL,
				  `salt` char(16) DEFAULT NULL,
				  `outletId` int(11) DEFAULT NULL,
				  `companyId` int(11) NOT NULL,
				  `updated_at` timestamp NULL DEFAULT NULL,
				  PRIMARY KEY (`contactId`),
				  KEY `companyId` (`companyId`),
				  KEY `contactId` (`contactId`),
				  KEY `contactId` (`contactId`),
				  KEY `contactRealId` (`contactRealId`),
				  KEY `type` (`type`)
				) ENGINE=MyISAM DEFAULT CHARSET=latin1;");

				$db->Execute("
				CREATE TABLE `cpayments` (
				  `cpaymentsId` bigint(20) NOT NULL AUTO_INCREMENT,
				  `cpaymentsDate` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
				  `cpaymentsAmount` decimal(15,2) NOT NULL,
				  `cpaymentsOrder` bigint(20) NOT NULL,
				  `cpaymentsInvoice` bigint(20) NOT NULL,
				  `cpaymentsStatus` tinyint(2) NOT NULL DEFAULT '0',
				  `companyId` int(11) NOT NULL,
				  PRIMARY KEY (`cpaymentsId`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8;");

				$db->Execute("
				CREATE TABLE `cRecordField` (
				  `cRecordFieldId` int(11) NOT NULL AUTO_INCREMENT,
				  `cRecordFieldName` varchar(255) NOT NULL,
				  `cRecordFieldType` tinyint(2) NOT NULL DEFAULT '0',
				  `cRecordFieldProgress` tinyint(1) DEFAULT NULL,
				  `customerRecordId` int(11) NOT NULL,
				  PRIMARY KEY (`cRecordFieldId`)
				) ENGINE=MyISAM DEFAULT CHARSET=latin1;");

				$db->Execute("
				CREATE TABLE `cRecordValue` (
				  `cRecordValueId` int(11) NOT NULL AUTO_INCREMENT,
				  `cRecordValueDate` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
				  `cRecordValueName` varchar(500) DEFAULT NULL,
				  `cRecordFieldId` int(11) NOT NULL,
				  `customerId` bigint(20) NOT NULL,
				  PRIMARY KEY (`cRecordValueId`)
				) ENGINE=MyISAM DEFAULT CHARSET=latin1;");

				$db->Execute("
				CREATE TABLE `customerRecord` (
				  `customerRecordId` int(11) NOT NULL AUTO_INCREMENT,
				  `customerRecordName` varchar(255) NOT NULL,
				  `companyId` int(11) NOT NULL,
				  PRIMARY KEY (`customerRecordId`)
				) ENGINE=MyISAM DEFAULT CHARSET=latin1;");

				$db->Execute("
				CREATE TABLE `drawer` (
				  `drawerId` int(11) NOT NULL AUTO_INCREMENT,
				  `drawerOpenDate` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
				  `drawerCloseDate` timestamp NULL DEFAULT '0000-00-00 00:00:00',
				  `drawerOpenAmount` decimal(15,2) DEFAULT NULL,
				  `drawerCloseAmount` decimal(15,2) DEFAULT NULL,
				  `drawerUID` bigint(15) NOT NULL,
				  `drawerUserOpen` int(11) NOT NULL,
				  `drawerUserClose` int(11) NOT NULL,
				  `drawerCloseDetails` text,
				  `registerId` int(11) NOT NULL,
				  `outletId` int(11) NOT NULL,
				  `companyId` int(11) NOT NULL,
				  PRIMARY KEY (`drawerId`)
				) ENGINE=MyISAM DEFAULT CHARSET=latin1;");

				$db->Execute("
				CREATE TABLE `expenses` (
				  `expensesId` int(11) NOT NULL AUTO_INCREMENT,
				  `expensesNameId` int(11) NOT NULL,
				  `expensesAmount` decimal(15,2) NOT NULL,
				  `expensesDescription` text,
				  `expensesDate` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
				  `expensesUID` bigint(20) DEFAULT NULL,
				  `userId` int(12) DEFAULT NULL,
				  `registerId` int(12) DEFAULT NULL,
				  `outletId` int(11) NOT NULL,
				  `companyId` int(11) NOT NULL,
				  PRIMARY KEY (`expensesId`),
				  UNIQUE KEY `expensesUID` (`expensesUID`),
				  KEY `registerId` (`registerId`,`outletId`,`companyId`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8;");

				$db->Execute("
				CREATE TABLE `giftCardSold` (
				  `giftCardSoldId` int(11) NOT NULL AUTO_INCREMENT,
				  `giftCardSoldValue` decimal(15,2) NOT NULL,
				  `giftCardSoldExpires` timestamp NULL DEFAULT NULL,
				  `giftCardSoldStatus` tinyint(1) NOT NULL DEFAULT '1',
				  `giftCardSoldCode` int(11) DEFAULT NULL,
				  `giftCardSoldNote` text,
				  `giftCardSoldLastUsed` timestamp NULL DEFAULT NULL,
				  `giftCardSoldSendDate` timestamp NULL DEFAULT NULL,
				  `giftCardSoldBeneficiaryNote` varchar(255) DEFAULT NULL,
				  `giftCardSoldBeneficiaryId` bigint(20) DEFAULT NULL,
				  `giftCardSoldColor` varchar(60) DEFAULT NULL,
				  `timestamp` bigint(20) DEFAULT NULL,
				  `transactionId` int(11) NOT NULL,
				  `outletId` int(11) NOT NULL,
				  `companyId` int(11) NOT NULL,
				  PRIMARY KEY (`giftCardSoldId`),
				  KEY `giftCardSoldCode` (`giftCardSoldCode`),
				  KEY `outletId` (`outletId`)
				) ENGINE=MyISAM DEFAULT CHARSET=latin1;");

				$db->Execute("
				CREATE TABLE `inventory` (
				  `inventoryId` int(11) NOT NULL AUTO_INCREMENT,
				  `inventoryDate` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
				  `inventoryChangedDate` timestamp NULL DEFAULT NULL,
				  `inventoryCount` decimal(15,3) NOT NULL,
				  `inventoryCOGS` decimal(15,2) DEFAULT NULL,
				  `inventoryUID` varchar(255) DEFAULT NULL,
				  `inventoryExpirationDate` timestamp NULL DEFAULT NULL,
				  `inventoryType` tinyint(1) DEFAULT '0' COMMENT '0 = Inventario activo, 1 = Merma o inactivo 2 = vendido',
				  `inventoryWastePercent` tinyint(3) DEFAULT NULL COMMENT 'ELIMINAR ahora se define en el producto',
				  `inventorySource` varchar(50) DEFAULT NULL,
				  `companyId` int(11) NOT NULL,
				  `outletId` int(11) NOT NULL,
				  `itemId` int(11) NOT NULL,
				  `supplierId` int(11) DEFAULT NULL,
				  PRIMARY KEY (`inventoryId`)
				) ENGINE=InnoDB DEFAULT CHARSET=latin1;");

				$db->Execute("
				CREATE TABLE `inventoryCount` (
				  `inventoryCountId` int(11) NOT NULL AUTO_INCREMENT,
				  `inventoryCountDate` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
				  `inventoryCountUpdated` timestamp NULL DEFAULT NULL,
				  `inventoryCountName` varchar(100) NOT NULL,
				  `inventoryCountStatus` tinyint(1) DEFAULT '0' COMMENT '0=pendiente,1=guardado,2=finalizado',
				  `inventoryCountCounted` decimal(15,2) DEFAULT NULL,
				  `inventoryCountNote` varchar(300) DEFAULT NULL,
				  `inventoryCountData` text NOT NULL,
				  `inventoryCountBlind` tinyint(1) DEFAULT NULL,
				  `inventoryCountedData` text,
				  `userId` int(11) NOT NULL,
				  `outletId` int(11) NOT NULL,
				  `companyId` int(11) NOT NULL,
				  PRIMARY KEY (`inventoryCountId`)
				) ENGINE=MyISAM DEFAULT CHARSET=latin1;");

				$db->Execute("
				CREATE TABLE `inventoryHistory` (
				  `inventoryHistoryId` int(11) NOT NULL AUTO_INCREMENT,
				  `inventoryHistoryDate` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
				  `inventoryHistoryCount` decimal(15,2) NOT NULL,
				  `inventoryHistoryReorder` decimal(15,2) DEFAULT NULL,
				  `inventoryHistoryType` varchar(255) NOT NULL,
				  `taxId` int(11) DEFAULT NULL,
				  `companyId` int(11) NOT NULL,
				  `outletId` int(11) NOT NULL,
				  `itemId` int(11) NOT NULL,
				  `userId` int(11) NOT NULL,
				  PRIMARY KEY (`inventoryHistoryId`)
				) ENGINE=MyISAM DEFAULT CHARSET=latin1;");

				$db->Execute("
				CREATE TABLE `inventorySold` (
				  `inventorySoldId` int(11) NOT NULL AUTO_INCREMENT,
				  `inventorySoldCount` decimal(15,3) NOT NULL,
				  `inventorySoldType` tinyint(2) DEFAULT '0' COMMENT '0 = venta, 1 = compra',
				  `itemSoldId` int(11) NOT NULL,
				  `inventoryId` int(11) NOT NULL,
				  PRIMARY KEY (`inventorySoldId`)
				) ENGINE=MyISAM DEFAULT CHARSET=latin1;");

				$db->Execute("
				CREATE TABLE `inventoryTransfer` (
				  `inventoryTransferId` int(13) NOT NULL AUTO_INCREMENT,
				  `inventoryTransferDate` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				  `inventoryTransferCount` decimal(15,3) NOT NULL,
				  `inventoryTransferFrom` int(13) NOT NULL,
				  `inventoryTransferTo` int(13) NOT NULL,
				  `inventoryTransferNote` varchar(500) DEFAULT NULL,
				  `inventoryTransferCOGS` decimal(15,2) DEFAULT NULL,
				  `itemId` int(13) NOT NULL,
				  `userId` int(13) NOT NULL,
				  `companyId` int(13) NOT NULL,
				  PRIMARY KEY (`inventoryTransferId`)
				) ENGINE=MyISAM DEFAULT CHARSET=latin1;");

				$db->Execute("
				CREATE TABLE `item` (
				  `itemId` int(11) NOT NULL AUTO_INCREMENT,
				  `itemName` varchar(255) NOT NULL,
				  `itemDate` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
				  `itemSKU` varchar(255) DEFAULT NULL,
				  `itemCOGS` decimal(15,2) DEFAULT NULL COMMENT 'se mantiene para reportes o se va  a copiar todo a average COGS',
				  `itemAverageCOGS` decimal(15,2) DEFAULT NULL COMMENT 'promedio de COGS de este producto, se actualiza con el inventory',
				  `itemPrice` decimal(15,2) DEFAULT NULL,
				  `itemPrice1` decimal(15,2) DEFAULT NULL,
				  `itemPrice2` decimal(15,2) DEFAULT NULL,
				  `itemPrice3` decimal(15,2) DEFAULT NULL,
				  `itemPrice4` decimal(15,2) DEFAULT NULL,
				  `itemDescription` text,
				  `itemTags` varchar(300) DEFAULT NULL,
				  `itemIsParent` tinyint(1) DEFAULT '0',
				  `itemParentId` int(11) DEFAULT '0',
				  `itemOnline` tinyint(1) DEFAULT '0',
				  `itemType` varchar(25) DEFAULT 'product',
				  `itemImage` varchar(10) DEFAULT 'false',
				  `itemStatus` int(2) DEFAULT '1',
				  `itemTrackInventory` tinyint(1) DEFAULT '0',
				  `itemCanSale` tinyint(1) DEFAULT '1',
				  `itemDiscount` decimal(15,10) DEFAULT '0.0000000000',
				  `itemProcedure` text,
				  `itemProduction` tinyint(1) DEFAULT '0',
				  `itemComissionPercent` tinyint(3) DEFAULT NULL,
				  `itemUOM` varchar(50) DEFAULT NULL COMMENT 'Units of Measurement',
				  `itemWaste` int(3) DEFAULT NULL,
				  `itemSessions` int(2) DEFAULT NULL,
				  `itemDuration` int(4) DEFAULT NULL,
				  `inventoryMethod` tinyint(2) DEFAULT NULL COMMENT '0 = FIFO 1= LIFO 2= Random 3 = FEFO',
				  `inventoryTrigger` int(15) DEFAULT NULL COMMENT 'A eliminar ahora se guarda en stockTrigger table',
				  `autoReOrder` tinyint(1) DEFAULT NULL,
				  `autoReOrderLevel` int(11) DEFAULT NULL,
				  `taxId` int(11) DEFAULT NULL,
				  `brandId` int(11) DEFAULT NULL,
				  `categoryId` int(11) DEFAULT NULL,
				  `supplierId` int(11) DEFAULT NULL,
				  `compoundId` text,
				  `outletId` int(11) DEFAULT NULL,
				  `companyId` int(11) NOT NULL,
				  `updated_at` timestamp NULL DEFAULT NULL,
				  PRIMARY KEY (`itemId`)
				) ENGINE=InnoDB DEFAULT CHARSET=latin1;");

				$db->Execute("
				CREATE TABLE `itemSold` (
				  `itemSoldId` int(11) NOT NULL AUTO_INCREMENT,
				  `itemSoldTotal` decimal(15,2) NOT NULL,
				  `itemSoldTax` decimal(15,2) DEFAULT NULL,
				  `itemSoldDate` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
				  `itemSoldUnits` float DEFAULT NULL,
				  `itemSoldDiscount` decimal(15,2) DEFAULT NULL,
				  `itemSoldCOGS` decimal(15,2) DEFAULT NULL,
				  `itemSoldComission` decimal(15,2) DEFAULT NULL,
				  `itemSoldDescription` varchar(255) DEFAULT NULL,
				  `itemId` int(11) NOT NULL,
				  `userId` int(11) DEFAULT NULL,
				  `transactionId` int(11) NOT NULL,
				  PRIMARY KEY (`itemSoldId`),
				  KEY `transactionId` (`transactionId`)
				) ENGINE=InnoDB DEFAULT CHARSET=latin1;");

				$db->Execute("
				CREATE TABLE `notify` (
				  `notifyId` int(13) unsigned NOT NULL AUTO_INCREMENT,
				  `notifyTitle` varchar(100) DEFAULT NULL,
				  `notifyMessage` varchar(255) DEFAULT NULL,
				  `notifySticky` tinyint(1) DEFAULT NULL,
				  `registerId` int(13) DEFAULT NULL,
				  `outletId` int(13) DEFAULT NULL,
				  `companyId` int(13) NOT NULL,
				  PRIMARY KEY (`notifyId`),
				  KEY `companyId` (`companyId`)
				) ENGINE=InnoDB DEFAULT CHARSET=latin1;");

				$db->Execute("
				CREATE TABLE `outlet` (
				  `outletId` int(11) NOT NULL AUTO_INCREMENT,
				  `outletLo` varchar(255) DEFAULT NULL,
				  `outletLa` varchar(255) DEFAULT NULL,
				  `outletName` varchar(255) NOT NULL,
				  `outletAddress` text,
				  `outletPhone` varchar(255) DEFAULT NULL,
				  `outletWhatsApp` varchar(20) DEFAULT NULL,
				  `outletEmail` varchar(255) DEFAULT NULL,
				  `outletBillingName` varchar(255) DEFAULT NULL,
				  `outletRUC` varchar(255) DEFAULT NULL,
				  `outletStatus` int(1) DEFAULT NULL,
				  `outletCreationDate` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
				  `outletNextExpirationDate` timestamp NULL DEFAULT '0000-00-00 00:00:00',
				  `outletLat` float(10,6) DEFAULT NULL,
				  `outletLng` float(10,6) DEFAULT NULL,
				  `companyId` int(11) NOT NULL,
				  PRIMARY KEY (`outletId`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8;");

				$db->Execute("
				CREATE TABLE `plans` (
				  `id` int(11) NOT NULL AUTO_INCREMENT,
				  `name` varchar(255) NOT NULL,
				  `type` varchar(255) NOT NULL,
				  `price` decimal(15,2) NOT NULL,
				  `max_items` int(11) NOT NULL DEFAULT '0',
				  `max_users` int(11) NOT NULL DEFAULT '0',
				  `max_customers` int(11) NOT NULL DEFAULT '0',
				  `max_outlets` int(11) NOT NULL DEFAULT '0',
				  `max_registers` int(11) NOT NULL DEFAULT '0',
				  `max_suppliers` int(11) NOT NULL DEFAULT '0',
				  `max_categories` int(11) NOT NULL DEFAULT '0',
				  `max_brands` int(11) NOT NULL DEFAULT '0',
				  `bulk_btns` tinyint(1) DEFAULT NULL,
				  `expenses` tinyint(1) DEFAULT NULL,
				  `purchase` tinyint(1) DEFAULT NULL,
				  `tags` tinyint(1) DEFAULT NULL,
				  `basicSettings` tinyint(1) DEFAULT NULL,
				  `clockinout` tinyint(1) DEFAULT NULL,
				  `satisfaction` tinyint(1) DEFAULT NULL,
				  `orders` tinyint(1) NOT NULL,
				  `geosales` tinyint(1) DEFAULT NULL,
				  `custom_payments` tinyint(1) DEFAULT NULL,
				  `ecommerce` tinyint(1) DEFAULT NULL,
				  `sms_receipt` tinyint(1) DEFAULT NULL,
				  `duration_days` int(11) NOT NULL DEFAULT '0',
				  `inventory` tinyint(1) DEFAULT NULL,
				  `batch_inventory` tinyint(1) DEFAULT NULL,
				  `inventory_count` tinyint(1) DEFAULT NULL,
				  `delivery` tinyint(1) DEFAULT NULL,
				  `production` tinyint(1) DEFAULT '0',
				  `drawerControl` tinyint(1) DEFAULT '1',
				  `item_options` tinyint(1) DEFAULT NULL,
				  `activityLog` tinyint(1) DEFAULT NULL,
				  `loyalty` tinyint(1) DEFAULT NULL,
				  `storeCredit` tinyint(1) DEFAULT NULL,
				  `storeTables` tinyint(1) DEFAULT NULL,
				  `schedule` tinyint(1) DEFAULT NULL,
				  `customerRecords` tinyint(1) DEFAULT NULL,
				  PRIMARY KEY (`id`)
				) ENGINE=MyISAM DEFAULT CHARSET=latin1;");

				$db->Execute("
				CREATE TABLE `price` (
				  `priceId` int(13) unsigned NOT NULL AUTO_INCREMENT,
				  `priceValue` decimal(15,2) DEFAULT NULL,
				  `priceListId` int(11) NOT NULL,
				  `itemId` int(13) NOT NULL,
				  PRIMARY KEY (`priceId`)
				) ENGINE=MyISAM DEFAULT CHARSET=latin1;");

				$db->Execute("
				CREATE TABLE `priceList` (
				  `priceListId` int(11) NOT NULL AUTO_INCREMENT,
				  `priceListName` varchar(255) CHARACTER SET latin1 COLLATE latin1_spanish_ci NOT NULL,
				  `companyId` int(11) NOT NULL,
				  PRIMARY KEY (`priceListId`),
				  KEY `companyId` (`companyId`)
				) ENGINE=MyISAM DEFAULT CHARSET=latin1;");

				$db->Execute("
				CREATE TABLE `production` (
				  `productionId` int(11) NOT NULL AUTO_INCREMENT,
				  `productionDate` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				  `productionCount` decimal(15,3) NOT NULL,
				  `productionRecipe` text,
				  `productionType` tinyint(1) DEFAULT NULL,
				  `productionCOGS` decimal(15,3) DEFAULT NULL,
				  `productionWasteValue` decimal(15,3) DEFAULT NULL,
				  `itemId` int(11) NOT NULL,
				  `userId` int(11) NOT NULL,
				  `outletId` int(11) NOT NULL,
				  `companyId` int(11) NOT NULL,
				  PRIMARY KEY (`productionId`),
				  KEY `itemId` (`itemId`,`userId`,`outletId`)
				) ENGINE=MyISAM DEFAULT CHARSET=latin1;");

				$db->Execute("
				CREATE TABLE `recurring` (
				  `recurringId` int(15) NOT NULL AUTO_INCREMENT,
				  `recurringNextDate` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Indica la próxima fecha en que se va a generar una nueva factura',
				  `recurringEndDate` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00' COMMENT '0000000 indica que no finaliza nunca',
				  `recurringFrecuency` varchar(50) NOT NULL COMMENT 'day, week, month, quarterly, year',
				  `recurringStatus` tinyint(1) NOT NULL DEFAULT '1' COMMENT '0: Finalizado, 1: Activo, 2: Pausado',
				  `recurringSaleData` text,
				  `recurringTransactionData` varchar(255) DEFAULT NULL,
				  `companyId` int(15) NOT NULL,
				  PRIMARY KEY (`recurringId`),
				  KEY `companyId` (`companyId`),
				  KEY `recurringNextDate` (`recurringNextDate`)
				) ENGINE=MyISAM DEFAULT CHARSET=latin1;");

				$db->Execute("
				CREATE TABLE `register` (
				  `registerId` int(11) NOT NULL AUTO_INCREMENT,
				  `registerName` varchar(255) NOT NULL,
				  `registerCreationDate` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
				  `registerInvoiceData` text,
				  `registerInvoiceAuth` int(15) DEFAULT NULL,
				  `registerInvoiceAuthExpiration` timestamp NULL DEFAULT NULL,
				  `registerInvoicePrefix` varchar(100) DEFAULT NULL,
				  `registerInvoiceSufix` varchar(100) DEFAULT NULL,
				  `registerInvoiceNumber` int(12) DEFAULT NULL,
				  `registerRemitoNumber` int(12) DEFAULT NULL,
				  `registerQuoteNumber` int(12) DEFAULT NULL,
				  `registerReturnNumber` int(12) DEFAULT NULL,
				  `registerTicketNumber` int(12) DEFAULT NULL,
				  `registerOrderNumber` int(12) DEFAULT NULL,
				  `registerPedidoNumber` int(12) DEFAULT NULL,
				  `registerBoletaNumber` int(12) DEFAULT NULL,
				  `registerScheduleNumber` int(12) DEFAULT NULL,
				  `registerDocsLeadingZeros` int(2) DEFAULT '0',
				  `registerStatus` tinyint(1) NOT NULL DEFAULT '0',
				  `registerHotkeys` text,
				  `registerPrinters` text,
				  `lastupdated` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
				  `outletId` int(11) NOT NULL,
				  `companyId` int(11) NOT NULL,
				  PRIMARY KEY (`registerId`)
				) ENGINE=MyISAM DEFAULT CHARSET=latin1;");

				$db->Execute("
				CREATE TABLE `satisfaction` (
				  `satisfactionId` int(12) NOT NULL AUTO_INCREMENT,
				  `satisfactionDate` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
				  `satisfactionLevel` tinyint(1) NOT NULL,
				  `satisfactionComment` text,
				  `transactionId` int(11) DEFAULT NULL,
				  `customerId` bigint(20) DEFAULT NULL,
				  `outletId` int(12) NOT NULL,
				  `companyId` int(12) NOT NULL,
				  PRIMARY KEY (`satisfactionId`)
				) ENGINE=MyISAM DEFAULT CHARSET=latin1;");

				$db->Execute("
				CREATE TABLE `setting` (
				  `settingId` int(11) NOT NULL AUTO_INCREMENT,
				  `settingName` varchar(255) DEFAULT NULL,
				  `settingAddress` varchar(255) DEFAULT NULL,
				  `settingEmail` varchar(255) DEFAULT NULL,
				  `settingBillingName` varchar(255) DEFAULT NULL,
				  `settingRUC` varchar(255) DEFAULT NULL,
				  `settingPhone` varchar(255) DEFAULT NULL,
				  `settingCity` varchar(255) DEFAULT NULL,
				  `settingCountry` varchar(255) DEFAULT NULL,
				  `settingCurrency` varchar(10) NOT NULL,
				  `settingLanguage` varchar(255) NOT NULL,
				  `settingTimeZone` varchar(255) NOT NULL,
				  `settingBillDetail` text,
				  `settingBillTemplate` tinyint(2) DEFAULT NULL,
				  `settingDecimal` varchar(5) DEFAULT NULL,
				  `settingThousandSeparator` varchar(5) DEFAULT NULL,
				  `settingWebSite` varchar(255) DEFAULT NULL,
				  `settingTaxName` varchar(10) DEFAULT NULL,
				  `settingTIN` varchar(20) DEFAULT NULL,
				  `settingCompanyCategoryId` varchar(5) DEFAULT NULL,
				  `settingSellSoldOut` varchar(3) DEFAULT 'yes',
				  `settingLockScreen` tinyint(1) DEFAULT '0',
				  `settingDelivery` varchar(5) DEFAULT NULL,
				  `settingDeliveryEmail` tinyint(1) DEFAULT NULL,
				  `settingDeliveryMsg` text,
				  `settingDisplayAllTransactionInOutlets` tinyint(1) NOT NULL DEFAULT '0',
				  `settingDrawerEmail` tinyint(1) DEFAULT '0',
				  `settingDrawerBlind` tinyint(1) DEFAULT '0',
				  `settingPaymentMethodId` tinyint(1) DEFAULT '0',
				  `settingItemSerialized` tinyint(1) DEFAULT '1',
				  `settingAcceptedTerms` tinyint(1) DEFAULT NULL,
				  `settingSellCredit` tinyint(1) NOT NULL DEFAULT '1',
				  `planExpired` tinyint(1) DEFAULT NULL,
				  `settingIsTrial` int(11) DEFAULT NULL,
				  `settingLoyalty` int(1) DEFAULT NULL,
				  `settingLoyaltyMin` decimal(15,2) DEFAULT NULL,
				  `settingLoyaltyInsentive` decimal(15,2) DEFAULT NULL,
				  `settingLoyaltyValue` decimal(15,2) DEFAULT '0.00',
				  `settingItemsSaleLimit` int(3) DEFAULT '20',
				  `settingStoreCredit` tinyint(2) DEFAULT NULL,
				  `settingStoreTables` tinyint(2) DEFAULT NULL,
				  `settingStoreCalendar` tinyint(1) DEFAULT NULL,
				  `settingOpenFrom` varchar(6) DEFAULT '8:00',
				  `settingOpenTo` varchar(6) DEFAULT '21:00',
				  `settingHideComboItems` tinyint(1) DEFAULT NULL,
				  `settingSocialMedia` varchar(500) DEFAULT NULL,
				  `settingInvoiceTemplate` tinyint(1) DEFAULT '0',
				  `settingForceCreditLine` tinyint(1) DEFAULT NULL,
				  `mixpanel_key` varchar(255) DEFAULT NULL,
				  `mixpanel_secret` varchar(255) DEFAULT NULL,
				  `holafactura_key` varchar(255) DEFAULT NULL,
				  `holafactura_token` varchar(255) DEFAULT NULL,
				  `companyId` int(11) NOT NULL,
				  PRIMARY KEY (`settingId`),
				  KEY `companyId` (`companyId`)
				) ENGINE=InnoDB DEFAULT CHARSET=latin1;");

				$db->Execute("
				CREATE TABLE `stockTrigger` (
				  `stockTriggerId` int(12) NOT NULL AUTO_INCREMENT,
				  `stockTriggerCount` decimal(15,3) NOT NULL DEFAULT '0.000',
				  `itemId` int(12) NOT NULL,
				  `outletId` int(12) NOT NULL,
				  PRIMARY KEY (`stockTriggerId`,`itemId`,`outletId`)
				) ENGINE=MyISAM DEFAULT CHARSET=latin1;");

				$db->Execute("
				CREATE TABLE `tableTags` (
				  `tableId` int(11) NOT NULL,
				  `taxonomyId` int(11) NOT NULL,
				  `type` varchar(50) NOT NULL,
				  PRIMARY KEY (`tableId`,`taxonomyId`,`type`)
				) ENGINE=MyISAM DEFAULT CHARSET=latin1;");

				$db->Execute("
				CREATE TABLE `taxonomy` (
				  `taxonomyId` int(11) NOT NULL AUTO_INCREMENT,
				  `taxonomyName` text NOT NULL,
				  `taxonomyType` varchar(255) NOT NULL,
				  `taxonomyExtra` text,
				  `outletId` int(11) DEFAULT NULL,
				  `companyId` int(11) DEFAULT NULL,
				  PRIMARY KEY (`taxonomyId`),
				  KEY `taxonomyType` (`taxonomyType`,`companyId`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8;");

				$db->Execute("
				CREATE TABLE `transaction` (
				  `transactionId` int(11) NOT NULL AUTO_INCREMENT,
				  `transactionDate` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
				  `transactionDiscount` decimal(15,2) DEFAULT NULL,
				  `transactionTax` decimal(15,2) DEFAULT NULL,
				  `transactionTotal` decimal(15,2) NOT NULL,
				  `transactionDetails` text,
				  `transactionUnitsSold` int(11) DEFAULT NULL,
				  `transactionPaymentType` text,
				  `transactionType` tinyint(3) DEFAULT NULL COMMENT '0 = Venta al contado  	    1 = Compra al contado 	    2 = Guardada  	    3 = Venta a crédito 	    4 = Compra a crédito 	    5 = Pago de ventas a crédito 	    6 = Devolución 	    7 = Venta anulada 	    8 = Venta recursiva  9 =Cotización 10 =  Delivery 11 = Mesa 12 = orden 13 = agendado',
				  `transactionName` varchar(100) DEFAULT NULL,
				  `transactionNote` varchar(255) DEFAULT NULL,
				  `transactionParentId` int(11) DEFAULT NULL,
				  `transactionComplete` tinyint(1) DEFAULT '1',
				  `transactionLocation` text,
				  `transactionDueDate` timestamp NULL DEFAULT NULL,
				  `transactionStatus` tinyint(2) DEFAULT '1' COMMENT '1. Pendiente o en espera 2. En proceso 3. En camino 4. Orden finalizada 5. Orden Anulada 6. Otro',
				  `transactionUID` bigint(50) DEFAULT NULL,
				  `fromDate` timestamp NULL DEFAULT NULL,
				  `toDate` timestamp NULL DEFAULT NULL,
				  `invoiceNo` bigint(20) DEFAULT NULL,
				  `invoicePrefix` varchar(150) DEFAULT NULL,
				  `tags` text,
				  `tableno` int(11) DEFAULT NULL,
				  `timestamp` bigint(20) DEFAULT NULL,
				  `categoryTransId` int(11) DEFAULT NULL,
				  `customerId` bigint(20) DEFAULT NULL,
				  `registerId` int(11) DEFAULT NULL,
				  `userId` int(11) NOT NULL,
				  `supplierId` int(11) DEFAULT NULL,
				  `outletId` int(11) NOT NULL,
				  `companyId` int(11) NOT NULL,
				  PRIMARY KEY (`transactionId`),
				  UNIQUE KEY `transactionId` (`transactionId`),
				  UNIQUE KEY `transactionUIDs` (`transactionUID`),
				  KEY `transactionDate` (`transactionDate`),
				  KEY `customerId` (`customerId`,`userId`),
				  KEY `transactionType` (`transactionType`),
				  KEY `companyId` (`companyId`),
				  KEY `outletId` (`outletId`),
				  KEY `registerId` (`registerId`),
				  KEY `transactionParentId` (`transactionParentId`)
				) ENGINE=InnoDB DEFAULT CHARSET=latin1;");

			}
		}
	}
}



?>