/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.5.29-MariaDB, for debian-linux-gnu (aarch64)
--
-- Host: localhost    Database: encomdb
-- ------------------------------------------------------
-- Server version	10.5.29-MariaDB-ubu2004

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `accountCategory`
--

DROP TABLE IF EXISTS `accountCategory`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `accountCategory` (
  `accountCategoryId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `accountCategoryName` varchar(255) NOT NULL DEFAULT '',
  `accountCategoryParentId` int(11) NOT NULL,
  `accountCategoryPosition` tinyint(4) DEFAULT NULL,
  `accountCategoryExternalId` int(11) DEFAULT NULL,
  `companyId` int(11) DEFAULT NULL,
  PRIMARY KEY (`accountCategoryId`),
  KEY `accountCategoryParentId` (`accountCategoryParentId`),
  KEY `companyId` (`companyId`),
  CONSTRAINT `fk_accountcategory_company` FOREIGN KEY (`companyId`) REFERENCES `company` (`companyId`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `accountCategory`
--

LOCK TABLES `accountCategory` WRITE;
/*!40000 ALTER TABLE `accountCategory` DISABLE KEYS */;
/*!40000 ALTER TABLE `accountCategory` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `activityLog`
--

DROP TABLE IF EXISTS `activityLog`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `activityLog` (
  `activityLogId` int(11) NOT NULL AUTO_INCREMENT,
  `activityLogDate` timestamp NOT NULL DEFAULT current_timestamp(),
  `activityLogType` varchar(100) NOT NULL,
  `activityLogData` text DEFAULT NULL,
  `userId` int(11) NOT NULL,
  `outletId` int(11) NOT NULL,
  `companyId` int(11) NOT NULL,
  PRIMARY KEY (`activityLogId`),
  KEY `companyId` (`companyId`),
  KEY `outletId` (`outletId`),
  KEY `userId` (`userId`),
  CONSTRAINT `fk_activitylog_company` FOREIGN KEY (`companyId`) REFERENCES `company` (`companyId`),
  CONSTRAINT `fk_activitylog_outlet` FOREIGN KEY (`outletId`) REFERENCES `outlet` (`outletId`),
  CONSTRAINT `fk_activitylog_user` FOREIGN KEY (`userId`) REFERENCES `user` (`userId`)
) ENGINE=InnoDB AUTO_INCREMENT=23418 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activityLog`
--

LOCK TABLES `activityLog` WRITE;
/*!40000 ALTER TABLE `activityLog` DISABLE KEYS */;
/*!40000 ALTER TABLE `activityLog` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance`
--

DROP TABLE IF EXISTS `attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance` (
  `attendanceId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `attendanceOpenDate` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `attendanceCloseDate` timestamp NULL DEFAULT NULL,
  `userId` int(10) unsigned NOT NULL,
  `outletId` int(10) unsigned NOT NULL,
  `companyId` int(10) unsigned NOT NULL,
  PRIMARY KEY (`attendanceId`),
  KEY `companyId` (`companyId`),
  KEY `outletId` (`outletId`),
  KEY `userId` (`userId`)
) ENGINE=InnoDB AUTO_INCREMENT=20399 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance`
--

LOCK TABLES `attendance` WRITE;
/*!40000 ALTER TABLE `attendance` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `banks`
--

DROP TABLE IF EXISTS `banks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `banks` (
  `bankId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `bankName` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`bankId`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `banks`
--

LOCK TABLES `banks` WRITE;
/*!40000 ALTER TABLE `banks` DISABLE KEYS */;
/*!40000 ALTER TABLE `banks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cRecordField`
--

DROP TABLE IF EXISTS `cRecordField`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cRecordField` (
  `cRecordFieldId` int(11) NOT NULL AUTO_INCREMENT,
  `cRecordFieldName` varchar(255) NOT NULL,
  `cRecordFieldType` tinyint(4) NOT NULL DEFAULT 0,
  `cRecordFieldProgress` tinyint(1) DEFAULT NULL,
  `cRecordFieldExtra` tinyint(1) DEFAULT NULL,
  `cRecordFieldSort` tinyint(4) DEFAULT NULL,
  `customerRecordId` int(11) NOT NULL,
  PRIMARY KEY (`cRecordFieldId`)
) ENGINE=InnoDB AUTO_INCREMENT=3814 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cRecordField`
--

LOCK TABLES `cRecordField` WRITE;
/*!40000 ALTER TABLE `cRecordField` DISABLE KEYS */;
/*!40000 ALTER TABLE `cRecordField` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cRecordValue`
--

DROP TABLE IF EXISTS `cRecordValue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cRecordValue` (
  `cRecordValueId` int(11) NOT NULL AUTO_INCREMENT,
  `cRecordValueDate` timestamp NOT NULL DEFAULT current_timestamp(),
  `cRecordValueName` varchar(500) DEFAULT NULL,
  `cRecordFieldId` int(11) NOT NULL,
  `customerId` bigint(20) NOT NULL,
  PRIMARY KEY (`cRecordValueId`),
  KEY `cRecordValue_customerId_IDX` (`customerId`,`cRecordValueDate`,`cRecordFieldId`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=153796 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cRecordValue`
--

LOCK TABLES `cRecordValue` WRITE;
/*!40000 ALTER TABLE `cRecordValue` DISABLE KEYS */;
/*!40000 ALTER TABLE `cRecordValue` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `campaign`
--

DROP TABLE IF EXISTS `campaign`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `campaign` (
  `campaignId` int(11) NOT NULL AUTO_INCREMENT,
  `campaignName` varchar(255) NOT NULL,
  `campaignDate` timestamp NULL DEFAULT NULL,
  `campaignQtySent` int(11) DEFAULT NULL,
  `campaignViewed` int(11) DEFAULT NULL,
  `campaignSales` int(11) DEFAULT NULL,
  `campaignAmount` decimal(15,2) DEFAULT NULL,
  `userId` int(11) DEFAULT NULL,
  `outletId` int(11) DEFAULT NULL,
  `companyId` int(11) NOT NULL,
  PRIMARY KEY (`campaignId`),
  KEY `companyId` (`companyId`),
  KEY `outletId` (`outletId`),
  KEY `userId` (`userId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `campaign`
--

LOCK TABLES `campaign` WRITE;
/*!40000 ALTER TABLE `campaign` DISABLE KEYS */;
/*!40000 ALTER TABLE `campaign` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `comission`
--

DROP TABLE IF EXISTS `comission`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `comission` (
  `comissionId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `comissionDate` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `comissionTotal` decimal(15,2) DEFAULT NULL,
  `comissionSource` varchar(20) DEFAULT NULL,
  `transactionId` int(11) DEFAULT NULL,
  `userId` int(11) NOT NULL,
  `outletId` int(11) DEFAULT NULL,
  `companyId` int(11) DEFAULT NULL,
  PRIMARY KEY (`comissionId`),
  KEY `companyId` (`companyId`),
  KEY `outletId` (`outletId`)
) ENGINE=InnoDB AUTO_INCREMENT=26619 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `comission`
--

LOCK TABLES `comission` WRITE;
/*!40000 ALTER TABLE `comission` DISABLE KEYS */;
/*!40000 ALTER TABLE `comission` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `company`
--

DROP TABLE IF EXISTS `company`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `company` (
  `companyId` int(11) NOT NULL AUTO_INCREMENT,
  `companyStatus` varchar(10) DEFAULT NULL,
  `companyDate` timestamp NOT NULL DEFAULT current_timestamp(),
  `companyPlan` int(11) NOT NULL DEFAULT 0,
  `companyUserActivated` int(11) NOT NULL DEFAULT 0,
  `companyBalance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `companyLastUpdate` timestamp NULL DEFAULT NULL,
  `customersLastUpdate` timestamp NULL DEFAULT NULL,
  `itemsLastUpdate` timestamp NULL DEFAULT NULL,
  `inventoryLastUpdate` timestamp NULL DEFAULT NULL,
  `calendarLastUpdate` timestamp NULL DEFAULT NULL,
  `orderLastUpdate` timestamp NULL DEFAULT NULL,
  `companyExpiringDate` timestamp NULL DEFAULT NULL,
  `companyDiscount` decimal(15,2) NOT NULL,
  `companySMSCredit` int(11) DEFAULT NULL,
  `companyDB` varchar(200) NOT NULL DEFAULT '905',
  `accountId` int(11) DEFAULT NULL,
  `parentId` int(11) DEFAULT NULL,
  `isParent` tinyint(1) NOT NULL DEFAULT 0,
  `encomUsers` varchar(500) DEFAULT '',
  PRIMARY KEY (`companyId`),
  KEY `idx_company_companyPlan` (`companyPlan`)
) ENGINE=InnoDB AUTO_INCREMENT=6256 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `company`
--

LOCK TABLES `company` WRITE;
/*!40000 ALTER TABLE `company` DISABLE KEYS */;
/*!40000 ALTER TABLE `company` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `companyHours`
--

DROP TABLE IF EXISTS `companyHours`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `companyHours` (
  `companyHoursId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sunday` float(2,2) DEFAULT NULL,
  `sundayTo` float(2,2) DEFAULT NULL,
  `monday` float(2,2) DEFAULT NULL,
  `mondayTo` float(2,2) DEFAULT NULL,
  `tuesday` float(2,2) DEFAULT NULL,
  `tuesdayTo` float(2,2) DEFAULT NULL,
  `wednesday` float(2,2) DEFAULT NULL,
  `wednesdayTo` float(2,2) DEFAULT NULL,
  `thursday` float(2,2) DEFAULT NULL,
  `thursdayTo` float(2,2) DEFAULT NULL,
  `friday` float(2,2) DEFAULT NULL,
  `fridayTo` float(2,2) DEFAULT NULL,
  `saturday` float(2,2) DEFAULT NULL,
  `saturdayTo` float(2,2) DEFAULT NULL,
  `userId` int(11) DEFAULT NULL,
  `outletId` int(11) DEFAULT NULL,
  `companyId` int(11) NOT NULL,
  PRIMARY KEY (`companyHoursId`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `companyHours`
--

LOCK TABLES `companyHours` WRITE;
/*!40000 ALTER TABLE `companyHours` DISABLE KEYS */;
/*!40000 ALTER TABLE `companyHours` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contact`
--

DROP TABLE IF EXISTS `contact`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact` (
  `contactId` int(11) NOT NULL AUTO_INCREMENT,
  `contactRealId` int(11) NOT NULL,
  `contactUID` bigint(20) DEFAULT NULL,
  `contactName` varchar(255) NOT NULL DEFAULT '',
  `contactSecondName` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `contactEmail` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `contactAddress` text CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `contactAddress2` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `contactPhone` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `contactPhone2` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `contactNote` text CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `contactCity` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `contactLocation` varchar(255) DEFAULT NULL,
  `contactCountry` varchar(5) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `contactTIN` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `contactCI` int(11) DEFAULT NULL,
  `contactDate` timestamp NOT NULL DEFAULT current_timestamp(),
  `contactBirthDay` date DEFAULT NULL,
  `contactPassword` char(68) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `contactLoyalty` int(11) NOT NULL DEFAULT 1,
  `contactLoyaltyAmount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `contactStoreCredit` decimal(15,2) NOT NULL,
  `contactCreditable` tinyint(1) DEFAULT 1,
  `contactCreditLine` decimal(15,2) DEFAULT NULL,
  `contactStatus` tinyint(4) DEFAULT 1,
  `contactGender` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `contactColor` varchar(7) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `contactInCalendar` tinyint(1) DEFAULT NULL,
  `contactCalendarPosition` tinyint(4) DEFAULT NULL,
  `contactTrackLocation` tinyint(3) unsigned DEFAULT NULL,
  `contactLastNotificationSeen` timestamp NULL DEFAULT NULL,
  `contactFixedComission` int(11) DEFAULT NULL,
  `contactLatLng` varchar(100) DEFAULT NULL,
  `categoryId` int(11) DEFAULT NULL,
  `data` text DEFAULT NULL,
  `type` smallint(6) NOT NULL DEFAULT 0 COMMENT '0 = User | 1 = Customer | 2 = Supplier',
  `debtLastNotify` datetime DEFAULT NULL,
  `main` varchar(5) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `role` smallint(6) DEFAULT NULL,
  `lockPass` smallint(6) DEFAULT NULL,
  `salt` char(16) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `parentId` bigint(20) DEFAULT NULL,
  `userId` int(11) DEFAULT NULL,
  `outletId` int(11) DEFAULT NULL,
  `companyId` int(11) NOT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`contactId`),
  KEY `companyId` (`companyId`),
  KEY `contactId` (`contactId`),
  KEY `contactUID` (`contactUID`),
  KEY `contactRealId` (`contactRealId`),
  KEY `type` (`type`)
) ENGINE=InnoDB AUTO_INCREMENT=1510299 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contact`
--

LOCK TABLES `contact` WRITE;
/*!40000 ALTER TABLE `contact` DISABLE KEYS */;
/*!40000 ALTER TABLE `contact` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contactNote`
--

DROP TABLE IF EXISTS `contactNote`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contactNote` (
  `contactNoteId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `contactNoteText` text DEFAULT NULL,
  `contactNoteDate` timestamp NULL DEFAULT current_timestamp(),
  `customerId` bigint(20) NOT NULL,
  `companyId` int(11) NOT NULL,
  PRIMARY KEY (`contactNoteId`)
) ENGINE=InnoDB AUTO_INCREMENT=392 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contactNote`
--

LOCK TABLES `contactNote` WRITE;
/*!40000 ALTER TABLE `contactNote` DISABLE KEYS */;
/*!40000 ALTER TABLE `contactNote` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cpayments`
--

DROP TABLE IF EXISTS `cpayments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cpayments` (
  `cpaymentsId` bigint(20) NOT NULL AUTO_INCREMENT,
  `cpaymentsDate` timestamp NOT NULL DEFAULT current_timestamp(),
  `cpaymentsAmount` decimal(15,2) NOT NULL,
  `cpaymentsOrder` bigint(20) NOT NULL,
  `cpaymentsInvoice` bigint(20) NOT NULL,
  `cpaymentsStatus` tinyint(4) NOT NULL DEFAULT 0,
  `companyId` int(11) NOT NULL,
  PRIMARY KEY (`cpaymentsId`)
) ENGINE=InnoDB AUTO_INCREMENT=205 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cpayments`
--

LOCK TABLES `cpayments` WRITE;
/*!40000 ALTER TABLE `cpayments` DISABLE KEYS */;
/*!40000 ALTER TABLE `cpayments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customerAddress`
--

DROP TABLE IF EXISTS `customerAddress`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `customerAddress` (
  `customerAddressId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `customerAddressDate` varchar(100) DEFAULT NULL,
  `customerAddressName` varchar(100) DEFAULT NULL,
  `customerAddressText` varchar(500) DEFAULT NULL,
  `customerAddressLat` decimal(10,8) DEFAULT NULL,
  `customerAddressLng` decimal(10,8) DEFAULT NULL,
  `customerAddressDefault` tinyint(1) DEFAULT NULL,
  `customerAddressLocation` varchar(100) DEFAULT NULL,
  `customerAddressCity` varchar(100) DEFAULT NULL,
  `customerId` bigint(20) unsigned NOT NULL,
  `companyId` int(10) unsigned NOT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`customerAddressId`),
  KEY `customerAddress_companyId_IDX` (`companyId`,`customerId`,`updated_at`) USING BTREE,
  KEY `customerId` (`customerId`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=571534 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customerAddress`
--

LOCK TABLES `customerAddress` WRITE;
/*!40000 ALTER TABLE `customerAddress` DISABLE KEYS */;
/*!40000 ALTER TABLE `customerAddress` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customerRecord`
--

DROP TABLE IF EXISTS `customerRecord`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `customerRecord` (
  `customerRecordId` int(11) NOT NULL AUTO_INCREMENT,
  `customerRecordSort` tinyint(4) DEFAULT NULL,
  `customerRecordName` varchar(255) NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
  `companyId` int(11) NOT NULL,
  PRIMARY KEY (`customerRecordId`),
  KEY `customerRecord_companyId_IDX` (`companyId`,`customerRecordSort`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=334 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customerRecord`
--

LOCK TABLES `customerRecord` WRITE;
/*!40000 ALTER TABLE `customerRecord` DISABLE KEYS */;
/*!40000 ALTER TABLE `customerRecord` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `drawer`
--

DROP TABLE IF EXISTS `drawer`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `drawer` (
  `drawerId` int(11) NOT NULL AUTO_INCREMENT,
  `drawerOpenDate` timestamp NULL DEFAULT current_timestamp(),
  `drawerCloseDate` timestamp NULL DEFAULT '0000-00-00 00:00:00',
  `drawerOpenAmount` decimal(15,2) DEFAULT NULL,
  `drawerCloseAmount` decimal(15,2) DEFAULT NULL,
  `drawerUID` bigint(20) NOT NULL,
  `drawerUserOpen` int(11) NOT NULL,
  `drawerUserClose` int(11) NOT NULL,
  `drawerCloseDetails` text DEFAULT NULL,
  `registerId` int(11) NOT NULL,
  `outletId` int(11) NOT NULL,
  `companyId` int(11) NOT NULL,
  PRIMARY KEY (`drawerId`)
) ENGINE=InnoDB AUTO_INCREMENT=254555 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `drawer`
--

LOCK TABLES `drawer` WRITE;
/*!40000 ALTER TABLE `drawer` DISABLE KEYS */;
/*!40000 ALTER TABLE `drawer` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `expenses`
--

DROP TABLE IF EXISTS `expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `expenses` (
  `expensesId` int(11) NOT NULL AUTO_INCREMENT,
  `expensesNameId` int(11) NOT NULL,
  `expensesAmount` decimal(15,2) NOT NULL,
  `expensesDescription` text DEFAULT NULL,
  `expensesDate` timestamp NOT NULL DEFAULT current_timestamp(),
  `expensesUID` bigint(20) DEFAULT NULL,
  `type` tinyint(4) DEFAULT NULL,
  `userId` int(11) DEFAULT NULL,
  `registerId` int(11) DEFAULT NULL,
  `outletId` int(11) NOT NULL,
  `companyId` int(11) NOT NULL,
  PRIMARY KEY (`expensesId`),
  UNIQUE KEY `expensesUID` (`expensesUID`),
  KEY `registerId` (`registerId`,`outletId`,`companyId`)
) ENGINE=InnoDB AUTO_INCREMENT=223893 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expenses`
--

LOCK TABLES `expenses` WRITE;
/*!40000 ALTER TABLE `expenses` DISABLE KEYS */;
/*!40000 ALTER TABLE `expenses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `files`
--

DROP TABLE IF EXISTS `files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `files` (
  `filesId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `filesName` varchar(100) NOT NULL,
  `filesType` varchar(20) NOT NULL,
  `sourceId` int(10) unsigned DEFAULT NULL,
  `companyId` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`filesId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `files`
--

LOCK TABLES `files` WRITE;
/*!40000 ALTER TABLE `files` DISABLE KEYS */;
/*!40000 ALTER TABLE `files` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `giftCardSold`
--

DROP TABLE IF EXISTS `giftCardSold`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `giftCardSold` (
  `giftCardSoldId` int(11) NOT NULL AUTO_INCREMENT,
  `giftCardSoldValue` decimal(15,2) NOT NULL,
  `giftCardSoldExpires` timestamp NULL DEFAULT NULL,
  `giftCardSoldStatus` tinyint(1) NOT NULL DEFAULT 1,
  `giftCardSoldCode` int(11) DEFAULT NULL,
  `giftCardSoldNote` text DEFAULT NULL,
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
  KEY `outletId` (`outletId`),
  KEY `giftCardSold_companyId_IDX` (`companyId`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=19334 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `giftCardSold`
--

LOCK TABLES `giftCardSold` WRITE;
/*!40000 ALTER TABLE `giftCardSold` DISABLE KEYS */;
/*!40000 ALTER TABLE `giftCardSold` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory`
--

DROP TABLE IF EXISTS `inventory`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory` (
  `inventoryId` int(11) NOT NULL AUTO_INCREMENT,
  `inventoryDate` timestamp NOT NULL DEFAULT current_timestamp(),
  `inventoryChangedDate` timestamp NULL DEFAULT NULL,
  `inventoryCount` decimal(15,3) NOT NULL,
  `inventoryCOGS` decimal(15,2) DEFAULT NULL,
  `inventoryUID` varchar(255) DEFAULT NULL,
  `inventoryExpirationDate` timestamp NULL DEFAULT NULL,
  `inventoryType` tinyint(1) DEFAULT 0 COMMENT '0 = Inventario activo, 1 = Merma o inactivo 2 = vendido',
  `inventorySource` varchar(50) DEFAULT NULL,
  `companyId` int(11) NOT NULL,
  `outletId` int(11) NOT NULL,
  `itemId` int(11) NOT NULL,
  `supplierId` int(11) DEFAULT NULL,
  PRIMARY KEY (`inventoryId`)
) ENGINE=InnoDB AUTO_INCREMENT=216440 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory`
--

LOCK TABLES `inventory` WRITE;
/*!40000 ALTER TABLE `inventory` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventory` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventoryCount`
--

DROP TABLE IF EXISTS `inventoryCount`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventoryCount` (
  `inventoryCountId` int(11) NOT NULL AUTO_INCREMENT,
  `inventoryCountDate` timestamp NOT NULL DEFAULT current_timestamp(),
  `inventoryCountUpdated` timestamp NULL DEFAULT NULL,
  `inventoryCountName` varchar(100) NOT NULL,
  `inventoryCountStatus` tinyint(1) DEFAULT 0 COMMENT '0=pendiente,1=guardado,2=finalizado',
  `inventoryCountCounted` decimal(15,2) DEFAULT NULL,
  `inventoryCountNote` varchar(300) DEFAULT NULL,
  `inventoryCountData` text NOT NULL,
  `inventoryCountBlind` tinyint(1) DEFAULT NULL,
  `inventoryCountedData` text DEFAULT NULL,
  `data` text DEFAULT NULL,
  `userId` int(11) NOT NULL,
  `outletId` int(11) NOT NULL,
  `companyId` int(11) NOT NULL,
  PRIMARY KEY (`inventoryCountId`)
) ENGINE=InnoDB AUTO_INCREMENT=14736 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventoryCount`
--

LOCK TABLES `inventoryCount` WRITE;
/*!40000 ALTER TABLE `inventoryCount` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventoryCount` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `item`
--

DROP TABLE IF EXISTS `item`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `item` (
  `itemId` int(11) NOT NULL AUTO_INCREMENT,
  `itemName` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `itemDate` timestamp NOT NULL DEFAULT current_timestamp(),
  `itemSKU` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `itemCost` decimal(15,2) DEFAULT NULL COMMENT 'promedio de COGS de este producto, se actualiza con el inventory',
  `itemPrice` decimal(15,2) DEFAULT NULL,
  `itemDescription` text CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `itemIsParent` tinyint(1) DEFAULT 0,
  `itemParentId` int(11) DEFAULT 0,
  `itemType` varchar(25) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT 'product',
  `itemImage` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT 'false',
  `itemStatus` int(11) DEFAULT 1,
  `itemTrackInventory` tinyint(1) DEFAULT 0,
  `itemCanSale` tinyint(1) DEFAULT 1,
  `itemTaxExcluded` decimal(15,2) DEFAULT NULL,
  `itemDiscount` decimal(15,10) DEFAULT 0.0000000000,
  `itemProcedure` text CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `itemProduction` tinyint(1) DEFAULT 0,
  `itemComissionPercent` decimal(15,2) DEFAULT NULL,
  `itemComissionType` tinyint(1) DEFAULT NULL,
  `itemPricePercent` int(11) DEFAULT NULL,
  `itemPriceType` int(11) DEFAULT NULL,
  `itemUOM` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL COMMENT 'Units of Measurement',
  `itemWaste` int(11) DEFAULT NULL,
  `itemSessions` int(11) DEFAULT NULL,
  `itemDuration` int(11) DEFAULT NULL,
  `itemComboAddons` tinyint(1) DEFAULT NULL,
  `itemUpsellDescription` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `itemEcom` tinyint(1) DEFAULT NULL,
  `itemFeatured` tinyint(1) DEFAULT NULL,
  `itemDateHour` varchar(500) DEFAULT NULL,
  `itemCurrencies` varchar(500) DEFAULT NULL,
  `itemSort` int(10) unsigned DEFAULT 99999,
  `autoReOrder` tinyint(1) DEFAULT NULL,
  `autoReOrderLevel` int(11) DEFAULT NULL,
  `data` text DEFAULT NULL,
  `taxId` int(11) DEFAULT NULL,
  `brandId` int(11) DEFAULT NULL,
  `categoryId` int(11) DEFAULT NULL,
  `supplierId` int(11) DEFAULT NULL,
  `locationId` int(11) DEFAULT NULL,
  `outletId` int(11) DEFAULT NULL,
  `companyId` int(11) NOT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`itemId`),
  KEY `idx_item_inventory_status_type_company` (`itemTrackInventory`,`itemStatus`,`itemType`,`companyId`)
) ENGINE=InnoDB AUTO_INCREMENT=1054421 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `item`
--

LOCK TABLES `item` WRITE;
/*!40000 ALTER TABLE `item` DISABLE KEYS */;
/*!40000 ALTER TABLE `item` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `itemDeleted`
--

DROP TABLE IF EXISTS `itemDeleted`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `itemDeleted` (
  `itemId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `date` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `data` varchar(255) DEFAULT NULL,
  `outletId` int(10) unsigned NOT NULL,
  `companyId` int(10) unsigned NOT NULL,
  PRIMARY KEY (`itemId`),
  KEY `itemDeleted_companyId_IDX` (`companyId`,`outletId`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=296795 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `itemDeleted`
--

LOCK TABLES `itemDeleted` WRITE;
/*!40000 ALTER TABLE `itemDeleted` DISABLE KEYS */;
/*!40000 ALTER TABLE `itemDeleted` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `itemSold`
--

DROP TABLE IF EXISTS `itemSold`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `itemSold` (
  `itemSoldId` int(11) NOT NULL AUTO_INCREMENT,
  `itemSoldTotal` decimal(15,2) NOT NULL,
  `itemSoldTax` decimal(15,2) DEFAULT NULL,
  `itemSoldDate` timestamp NOT NULL DEFAULT current_timestamp(),
  `itemSoldUnits` float DEFAULT NULL,
  `itemSoldDiscount` decimal(15,2) DEFAULT NULL,
  `itemSoldCOGS` decimal(15,2) DEFAULT NULL,
  `itemSoldComission` decimal(15,2) DEFAULT NULL,
  `itemSoldDescription` varchar(255) DEFAULT NULL,
  `itemSoldParent` int(11) DEFAULT NULL,
  `itemSoldCategory` int(11) DEFAULT NULL,
  `itemId` int(11) NOT NULL,
  `userId` int(11) DEFAULT NULL,
  `transactionId` int(11) NOT NULL,
  PRIMARY KEY (`itemSoldId`),
  KEY `transactionId` (`transactionId`),
  KEY `itemSold_itemSoldDate_IDX` (`itemSoldDate`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=24714221 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `itemSold`
--

LOCK TABLES `itemSold` WRITE;
/*!40000 ALTER TABLE `itemSold` DISABLE KEYS */;
/*!40000 ALTER TABLE `itemSold` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `module`
--

DROP TABLE IF EXISTS `module`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `module` (
  `moduleId` int(11) NOT NULL AUTO_INCREMENT,
  `loyalty` tinyint(1) DEFAULT NULL,
  `loyaltyMin` decimal(15,2) DEFAULT NULL,
  `loyaltyValue` decimal(15,2) DEFAULT NULL,
  `feedback` tinyint(1) DEFAULT 1,
  `feedbackQuestion` varchar(300) DEFAULT NULL,
  `campaigns` tinyint(1) DEFAULT 1,
  `crm` tinyint(1) DEFAULT NULL,
  `tables` tinyint(1) DEFAULT NULL,
  `tablesCount` tinyint(3) unsigned DEFAULT NULL,
  `production` tinyint(1) DEFAULT NULL,
  `kds` tinyint(1) DEFAULT NULL,
  `calendar` tinyint(1) DEFAULT NULL,
  `dunning` tinyint(1) DEFAULT NULL,
  `recurring` tinyint(1) DEFAULT NULL,
  `reminder` tinyint(1) DEFAULT NULL,
  `extraUsers` tinyint(3) unsigned DEFAULT NULL,
  `extraRegisters` tinyint(3) unsigned DEFAULT NULL,
  `ordersPanel` tinyint(1) DEFAULT NULL,
  `orderAverageTime` tinyint(3) unsigned DEFAULT NULL,
  `salesSummaryDaily` tinyint(1) DEFAULT NULL,
  `spotify` tinyint(1) DEFAULT NULL,
  `spotifyUrl` varchar(30) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `tusfacturas` tinyint(1) DEFAULT NULL,
  `tusfacturas_apitoken` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `tusfacturas_usertoken` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `tusfacturas_apikey` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `ecom` tinyint(4) DEFAULT NULL,
  `ecom_data` varchar(1800) DEFAULT NULL,
  `newton` tinyint(4) DEFAULT 0,
  `dropbox` tinyint(1) DEFAULT NULL,
  `dropboxToken` varchar(400) DEFAULT NULL,
  `digitalInvoice` tinyint(3) unsigned DEFAULT NULL,
  `digitalInvoiceData` text DEFAULT NULL,
  `epos` tinyint(3) unsigned DEFAULT NULL,
  `eposData` text DEFAULT NULL,
  `moduleData` text DEFAULT NULL,
  `companyId` int(11) NOT NULL,
  PRIMARY KEY (`moduleId`),
  KEY `companyId` (`companyId`)
) ENGINE=InnoDB AUTO_INCREMENT=2493 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `module`
--

LOCK TABLES `module` WRITE;
/*!40000 ALTER TABLE `module` DISABLE KEYS */;
/*!40000 ALTER TABLE `module` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notify`
--

DROP TABLE IF EXISTS `notify`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notify` (
  `notifyId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `notifyTitle` varchar(100) DEFAULT NULL,
  `notifyDate` timestamp NULL DEFAULT NULL,
  `notifyMessage` varchar(300) DEFAULT NULL,
  `notifyLink` varchar(255) DEFAULT NULL,
  `notifyType` tinyint(1) DEFAULT NULL,
  `notifyMode` tinyint(1) DEFAULT NULL,
  `notifyStatus` tinyint(1) DEFAULT NULL,
  `notifyRegister` tinyint(1) DEFAULT NULL,
  `outletId` int(11) DEFAULT NULL,
  `companyId` int(11) DEFAULT NULL,
  PRIMARY KEY (`notifyId`),
  KEY `companyId` (`companyId`)
) ENGINE=InnoDB AUTO_INCREMENT=300839 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notify`
--

LOCK TABLES `notify` WRITE;
/*!40000 ALTER TABLE `notify` DISABLE KEYS */;
/*!40000 ALTER TABLE `notify` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `outlet`
--

DROP TABLE IF EXISTS `outlet`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `outlet` (
  `outletId` int(11) NOT NULL AUTO_INCREMENT,
  `outletName` varchar(255) NOT NULL,
  `outletAddress` text DEFAULT NULL,
  `outletPhone` varchar(255) DEFAULT NULL,
  `outletWhatsApp` varchar(20) DEFAULT NULL,
  `outletEmail` varchar(255) DEFAULT NULL,
  `outletBillingName` varchar(255) DEFAULT NULL,
  `outletRUC` varchar(255) DEFAULT NULL,
  `outletStatus` int(11) DEFAULT 1,
  `outletCreationDate` timestamp NULL DEFAULT current_timestamp(),
  `outletLatLng` varchar(100) DEFAULT NULL,
  `outletDescription` varchar(255) DEFAULT NULL,
  `outletNextExpirationDate` timestamp NULL DEFAULT current_timestamp(),
  `outletBusinessHours` varchar(500) DEFAULT NULL,
  `outletPurchaseOrderNo` int(11) DEFAULT NULL,
  `outletOrderTransferNo` int(11) DEFAULT NULL,
  `outletEcom` tinyint(3) unsigned DEFAULT NULL,
  `data` text DEFAULT NULL,
  `taxId` int(10) unsigned DEFAULT NULL,
  `companyId` int(11) NOT NULL,
  PRIMARY KEY (`outletId`)
) ENGINE=InnoDB AUTO_INCREMENT=6766 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `outlet`
--

LOCK TABLES `outlet` WRITE;
/*!40000 ALTER TABLE `outlet` DISABLE KEYS */;
/*!40000 ALTER TABLE `outlet` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `password_resets_email_index` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_resets`
--

LOCK TABLES `password_resets` WRITE;
/*!40000 ALTER TABLE `password_resets` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_resets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `paymentMethods`
--

DROP TABLE IF EXISTS `paymentMethods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `paymentMethods` (
  `paymentMethodsId` int(11) NOT NULL AUTO_INCREMENT,
  `paymentMethodsName` varchar(100) NOT NULL,
  `companyId` int(11) NOT NULL,
  PRIMARY KEY (`paymentMethodsId`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `paymentMethods`
--

LOCK TABLES `paymentMethods` WRITE;
/*!40000 ALTER TABLE `paymentMethods` DISABLE KEYS */;
/*!40000 ALTER TABLE `paymentMethods` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `plans`
--

DROP TABLE IF EXISTS `plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `type` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `price` decimal(15,2) NOT NULL,
  `max_items` int(11) NOT NULL DEFAULT 0,
  `max_users` int(11) NOT NULL DEFAULT 0,
  `max_customers` int(11) NOT NULL DEFAULT 0,
  `max_outlets` int(11) NOT NULL DEFAULT 0,
  `max_registers` int(11) NOT NULL DEFAULT 0,
  `max_suppliers` int(11) NOT NULL DEFAULT 0,
  `max_categories` int(11) NOT NULL DEFAULT 0,
  `max_brands` int(11) NOT NULL DEFAULT 0,
  `max_kds` tinyint(1) DEFAULT NULL,
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
  `duration_days` int(11) NOT NULL DEFAULT 0,
  `inventory` tinyint(1) DEFAULT NULL,
  `batch_inventory` tinyint(1) DEFAULT NULL,
  `inventory_count` tinyint(1) DEFAULT NULL,
  `delivery` tinyint(1) DEFAULT NULL,
  `production` tinyint(1) DEFAULT 0,
  `drawerControl` tinyint(1) DEFAULT 1,
  `item_options` tinyint(1) DEFAULT NULL,
  `activityLog` tinyint(1) DEFAULT NULL,
  `loyalty` tinyint(1) DEFAULT NULL,
  `storeCredit` tinyint(1) DEFAULT NULL,
  `storeTables` tinyint(1) DEFAULT NULL,
  `schedule` tinyint(1) DEFAULT NULL,
  `customerRecords` tinyint(1) DEFAULT NULL,
  `notify` tinyint(1) DEFAULT NULL,
  `customRoles` tinyint(4) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plans`
--

LOCK TABLES `plans` WRITE;
/*!40000 ALTER TABLE `plans` DISABLE KEYS */;
/*!40000 ALTER TABLE `plans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `priceList`
--

DROP TABLE IF EXISTS `priceList`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `priceList` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `data` text CHARACTER SET latin1 COLLATE latin1_spanish_ci NOT NULL,
  `companyId` int(11) NOT NULL,
  PRIMARY KEY (`ID`),
  KEY `companyId` (`companyId`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `priceList`
--

LOCK TABLES `priceList` WRITE;
/*!40000 ALTER TABLE `priceList` DISABLE KEYS */;
/*!40000 ALTER TABLE `priceList` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `printServer`
--

DROP TABLE IF EXISTS `printServer`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `printServer` (
  `ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `date` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
  `status` tinyint(3) unsigned DEFAULT NULL,
  `transactionId` int(10) unsigned NOT NULL,
  `outletId` int(10) unsigned DEFAULT NULL,
  `companyId` int(10) unsigned NOT NULL,
  PRIMARY KEY (`ID`),
  KEY `printServer_date_IDX` (`date`,`status`,`transactionId`,`outletId`,`companyId`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=341 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `printServer`
--

LOCK TABLES `printServer` WRITE;
/*!40000 ALTER TABLE `printServer` DISABLE KEYS */;
/*!40000 ALTER TABLE `printServer` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `processorId`
--

DROP TABLE IF EXISTS `processorId`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `processorId` (
  `processorId` int(11) NOT NULL AUTO_INCREMENT,
  `processorName` varchar(100) NOT NULL,
  `processorComission` float(3,2) DEFAULT NULL,
  `companyId` int(11) NOT NULL,
  PRIMARY KEY (`processorId`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `processorId`
--

LOCK TABLES `processorId` WRITE;
/*!40000 ALTER TABLE `processorId` DISABLE KEYS */;
/*!40000 ALTER TABLE `processorId` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `production`
--

DROP TABLE IF EXISTS `production`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `production` (
  `productionId` int(11) NOT NULL AUTO_INCREMENT,
  `productionDate` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `productionCount` decimal(15,3) NOT NULL,
  `productionRecipe` text DEFAULT NULL,
  `productionType` tinyint(1) DEFAULT NULL,
  `productionCOGS` decimal(15,3) DEFAULT NULL,
  `productionWasteValue` decimal(15,3) DEFAULT NULL,
  `itemId` int(11) NOT NULL,
  `userId` int(11) NOT NULL,
  `outletId` int(11) NOT NULL,
  `companyId` int(11) NOT NULL,
  PRIMARY KEY (`productionId`),
  KEY `itemId` (`itemId`,`userId`,`outletId`)
) ENGINE=InnoDB AUTO_INCREMENT=9829 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `production`
--

LOCK TABLES `production` WRITE;
/*!40000 ALTER TABLE `production` DISABLE KEYS */;
/*!40000 ALTER TABLE `production` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `recurring`
--

DROP TABLE IF EXISTS `recurring`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `recurring` (
  `recurringId` int(11) NOT NULL AUTO_INCREMENT,
  `recurringNextDate` timestamp NULL DEFAULT current_timestamp() COMMENT 'Indica la próxima fecha en que se va a generar una nueva factura',
  `recurringEndDate` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00' COMMENT '0000000 indica que no finaliza nunca',
  `recurringFrecuency` varchar(50) NOT NULL COMMENT 'day, week, month, quarterly, year',
  `recurringStatus` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0: Finalizado, 1: Activo, 2: Pausado',
  `recurringSaleData` text DEFAULT NULL,
  `recurringTransactionData` varchar(255) DEFAULT NULL,
  `companyId` int(11) NOT NULL,
  PRIMARY KEY (`recurringId`),
  KEY `companyId` (`companyId`),
  KEY `recurringNextDate` (`recurringNextDate`)
) ENGINE=InnoDB AUTO_INCREMENT=2570 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `recurring`
--

LOCK TABLES `recurring` WRITE;
/*!40000 ALTER TABLE `recurring` DISABLE KEYS */;
/*!40000 ALTER TABLE `recurring` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `register`
--

DROP TABLE IF EXISTS `register`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `register` (
  `registerId` int(11) NOT NULL AUTO_INCREMENT,
  `registerName` varchar(255) NOT NULL,
  `data` text DEFAULT NULL,
  `registerCreationDate` timestamp NOT NULL DEFAULT current_timestamp(),
  `registerInvoiceData` text DEFAULT NULL,
  `registerInvoiceAuth` int(11) DEFAULT NULL,
  `registerInvoiceAuthExpiration` timestamp NULL DEFAULT NULL,
  `registerInvoicePrefix` varchar(100) DEFAULT NULL,
  `registerInvoiceSufix` varchar(100) DEFAULT NULL,
  `registerInvoiceNumber` int(11) DEFAULT NULL,
  `registerRemitoNumber` int(11) DEFAULT NULL,
  `registerQuoteNumber` int(11) DEFAULT NULL,
  `registerReturnNumber` int(11) DEFAULT NULL,
  `registerTicketNumber` int(11) DEFAULT NULL,
  `registerOrderNumber` int(11) DEFAULT NULL,
  `registerPedidoNumber` int(11) DEFAULT NULL,
  `registerBoletaNumber` int(11) DEFAULT NULL,
  `registerScheduleNumber` int(11) DEFAULT NULL,
  `registerDocsLeadingZeros` int(11) DEFAULT 0,
  `registerStatus` tinyint(1) NOT NULL DEFAULT 0,
  `registerHotkeys` text DEFAULT NULL,
  `registerPrinters` text DEFAULT NULL,
  `lastupdated` timestamp NOT NULL DEFAULT '2018-01-01 00:00:00',
  `outletId` int(11) NOT NULL,
  `companyId` int(11) NOT NULL,
  `sessionId` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`registerId`)
) ENGINE=InnoDB AUTO_INCREMENT=7700 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `register`
--

LOCK TABLES `register` WRITE;
/*!40000 ALTER TABLE `register` DISABLE KEYS */;
/*!40000 ALTER TABLE `register` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reminder`
--

DROP TABLE IF EXISTS `reminder`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reminder` (
  `reminderId` int(11) NOT NULL AUTO_INCREMENT,
  `reminderNote` varchar(500) NOT NULL,
  `reminderDate` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `userId` int(11) NOT NULL,
  `itemId` int(11) DEFAULT NULL,
  `contactUID` mediumint(9) DEFAULT NULL,
  `companyId` int(11) NOT NULL,
  PRIMARY KEY (`reminderId`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reminder`
--

LOCK TABLES `reminder` WRITE;
/*!40000 ALTER TABLE `reminder` DISABLE KEYS */;
/*!40000 ALTER TABLE `reminder` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `satisfaction`
--

DROP TABLE IF EXISTS `satisfaction`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `satisfaction` (
  `satisfactionId` int(11) NOT NULL AUTO_INCREMENT,
  `satisfactionDate` timestamp NOT NULL DEFAULT current_timestamp(),
  `satisfactionLevel` tinyint(1) NOT NULL,
  `satisfactionComment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `transactionId` int(11) DEFAULT NULL,
  `customerId` bigint(20) DEFAULT NULL,
  `outletId` int(11) NOT NULL,
  `companyId` int(11) NOT NULL,
  PRIMARY KEY (`satisfactionId`)
) ENGINE=InnoDB AUTO_INCREMENT=4088 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='feedback de clientes';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `satisfaction`
--

LOCK TABLES `satisfaction` WRITE;
/*!40000 ALTER TABLE `satisfaction` DISABLE KEYS */;
/*!40000 ALTER TABLE `satisfaction` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `setting`
--

DROP TABLE IF EXISTS `setting`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `setting` (
  `settingId` int(11) NOT NULL AUTO_INCREMENT,
  `settingName` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `settingAddress` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `settingEmail` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `settingBillingName` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `settingRUC` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `settingPhone` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `settingCity` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `settingCountry` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `settingCurrency` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `settingLanguage` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `settingTimeZone` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `settingBillDetail` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `settingBillTemplate` tinyint(4) DEFAULT NULL,
  `settingDecimal` varchar(5) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `settingThousandSeparator` varchar(5) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `settingWebSite` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `settingTaxName` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `settingRemoveTaxes` tinyint(1) DEFAULT 1,
  `settingTIN` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `settingCompanyCategoryId` varchar(5) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `settingSellSoldOut` varchar(3) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT 'yes',
  `settingLockScreen` tinyint(1) DEFAULT 0,
  `settingDelivery` varchar(5) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `settingDeliveryEmail` tinyint(1) DEFAULT NULL,
  `settingDeliveryMsg` text CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `settingDisplayAllTransactionInOutlets` tinyint(1) NOT NULL DEFAULT 0,
  `settingDrawerEmail` tinyint(1) DEFAULT 0,
  `settingDrawerBlind` tinyint(1) DEFAULT 0,
  `settingPaymentMethodId` tinyint(1) DEFAULT 0,
  `settingItemSerialized` tinyint(1) DEFAULT 1,
  `settingAcceptedTerms` tinyint(1) DEFAULT NULL,
  `settingSellCredit` tinyint(1) NOT NULL DEFAULT 1,
  `settingPlanExpired` tinyint(1) DEFAULT NULL,
  `settingPartialBlock` tinyint(4) DEFAULT NULL,
  `settingBlocked` int(11) DEFAULT NULL,
  `settingIsTrial` int(11) DEFAULT NULL,
  `settingLoyalty` int(11) DEFAULT NULL,
  `settingLoyaltyMin` decimal(15,2) DEFAULT NULL,
  `settingLoyaltyValue` decimal(15,2) DEFAULT 0.00,
  `settingItemsSaleLimit` int(11) DEFAULT 20,
  `settingStoreCredit` tinyint(4) DEFAULT NULL,
  `settingStoreTables` tinyint(4) DEFAULT NULL,
  `settingStoreCalendar` tinyint(1) DEFAULT NULL,
  `settingOpenFrom` varchar(6) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT '8:00',
  `settingOpenTo` varchar(6) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT '21:00',
  `settingHideComboItems` tinyint(1) DEFAULT NULL,
  `settingSocialMedia` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `settingInvoiceTemplate` tinyint(1) DEFAULT 0,
  `settingForceCreditLine` tinyint(1) DEFAULT NULL,
  `settingEncomID` bigint(20) DEFAULT NULL,
  `settingVirtualInvoice` tinyint(1) DEFAULT NULL,
  `settingExtraUsers` tinyint(4) DEFAULT NULL,
  `settingMandatoryContactFields` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `settingAutoSMSCredit` tinyint(3) unsigned DEFAULT NULL,
  `settingObj` text DEFAULT NULL,
  `mixpanel_key` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `mixpanel_secret` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `companyId` int(11) NOT NULL,
  `settingSlug` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  PRIMARY KEY (`settingId`,`companyId`),
  KEY `companyId` (`companyId`),
  KEY `setting_settingSlug_IDX` (`settingSlug`) USING BTREE,
  KEY `idx_setting_companyId` (`companyId`)
) ENGINE=InnoDB AUTO_INCREMENT=6244 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `setting`
--

LOCK TABLES `setting` WRITE;
/*!40000 ALTER TABLE `setting` DISABLE KEYS */;
/*!40000 ALTER TABLE `setting` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock`
--

DROP TABLE IF EXISTS `stock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock` (
  `stockId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `stockDate` timestamp NULL DEFAULT current_timestamp(),
  `stockSource` varchar(255) DEFAULT NULL,
  `stockCount` decimal(15,3) DEFAULT NULL,
  `stockCOGS` decimal(15,2) DEFAULT 0.00,
  `stockOnHand` decimal(15,3) DEFAULT 0.000,
  `stockOnHandCOGS` decimal(15,2) DEFAULT NULL,
  `stockNote` varchar(255) DEFAULT NULL,
  `itemId` int(11) NOT NULL,
  `transactionId` int(11) DEFAULT NULL,
  `userId` int(11) DEFAULT NULL,
  `supplierId` int(11) DEFAULT NULL,
  `outletId` int(11) NOT NULL,
  `locationId` int(11) DEFAULT NULL,
  `companyId` int(11) NOT NULL,
  PRIMARY KEY (`stockId`),
  KEY `itemId` (`itemId`),
  KEY `companyId` (`companyId`),
  KEY `outletId` (`outletId`),
  KEY `locationId` (`locationId`),
  KEY `idx_stockDate` (`stockDate`)
) ENGINE=InnoDB AUTO_INCREMENT=21680583 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock`
--

LOCK TABLES `stock` WRITE;
/*!40000 ALTER TABLE `stock` DISABLE KEYS */;
/*!40000 ALTER TABLE `stock` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stockTrigger`
--

DROP TABLE IF EXISTS `stockTrigger`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `stockTrigger` (
  `stockTriggerId` int(11) NOT NULL AUTO_INCREMENT,
  `stockTriggerCount` decimal(15,3) NOT NULL DEFAULT 0.000,
  `itemId` int(11) NOT NULL,
  `outletId` int(11) NOT NULL,
  PRIMARY KEY (`stockTriggerId`,`itemId`,`outletId`),
  KEY `idx_stocktrigger_outlet_item` (`outletId`,`itemId`)
) ENGINE=InnoDB AUTO_INCREMENT=50875 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stockTrigger`
--

LOCK TABLES `stockTrigger` WRITE;
/*!40000 ALTER TABLE `stockTrigger` DISABLE KEYS */;
/*!40000 ALTER TABLE `stockTrigger` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tableTags`
--

DROP TABLE IF EXISTS `tableTags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tableTags` (
  `tableId` int(11) NOT NULL,
  `taxonomyId` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  PRIMARY KEY (`tableId`,`taxonomyId`,`type`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tableTags`
--

LOCK TABLES `tableTags` WRITE;
/*!40000 ALTER TABLE `tableTags` DISABLE KEYS */;
/*!40000 ALTER TABLE `tableTags` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tasks`
--

DROP TABLE IF EXISTS `tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tasks` (
  `ID` bigint(20) NOT NULL AUTO_INCREMENT,
  `date` date DEFAULT NULL,
  `dueDate` date DEFAULT NULL,
  `type` varchar(45) DEFAULT NULL,
  `sourceId` varchar(255) DEFAULT NULL,
  `status` varchar(20) DEFAULT NULL,
  `data` text DEFAULT NULL,
  `outletId` int(10) unsigned DEFAULT NULL,
  `companyId` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB AUTO_INCREMENT=10212 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tasks`
--

LOCK TABLES `tasks` WRITE;
/*!40000 ALTER TABLE `tasks` DISABLE KEYS */;
/*!40000 ALTER TABLE `tasks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `taxonomy`
--

DROP TABLE IF EXISTS `taxonomy`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `taxonomy` (
  `taxonomyId` int(11) NOT NULL AUTO_INCREMENT,
  `taxonomyName` text NOT NULL,
  `taxonomyType` varchar(255) NOT NULL,
  `taxonomyExtra` text DEFAULT NULL,
  `sourceId` int(11) DEFAULT NULL,
  `outletId` int(11) DEFAULT NULL,
  `companyId` int(11) DEFAULT NULL,
  PRIMARY KEY (`taxonomyId`),
  KEY `taxonomyType` (`taxonomyType`,`companyId`),
  KEY `idx_taxonomy_type_outlet_name` (`taxonomyType`,`outletId`,`taxonomyName`(50))
) ENGINE=InnoDB AUTO_INCREMENT=252349 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `taxonomy`
--

LOCK TABLES `taxonomy` WRITE;
/*!40000 ALTER TABLE `taxonomy` DISABLE KEYS */;
/*!40000 ALTER TABLE `taxonomy` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `toAddress`
--

DROP TABLE IF EXISTS `toAddress`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `toAddress` (
  `toAddressId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `customerAddressId` int(10) unsigned NOT NULL,
  `transactionId` int(10) unsigned NOT NULL,
  PRIMARY KEY (`toAddressId`),
  KEY `toAddress_customerAddressId_IDX` (`customerAddressId`,`transactionId`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=69487 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `toAddress`
--

LOCK TABLES `toAddress` WRITE;
/*!40000 ALTER TABLE `toAddress` DISABLE KEYS */;
/*!40000 ALTER TABLE `toAddress` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `toCategory`
--

DROP TABLE IF EXISTS `toCategory`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `toCategory` (
  `toCategoryId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `categoryId` int(10) unsigned NOT NULL,
  `parentId` int(10) unsigned NOT NULL,
  PRIMARY KEY (`toCategoryId`)
) ENGINE=InnoDB AUTO_INCREMENT=1105 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `toCategory`
--

LOCK TABLES `toCategory` WRITE;
/*!40000 ALTER TABLE `toCategory` DISABLE KEYS */;
/*!40000 ALTER TABLE `toCategory` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `toCompound`
--

DROP TABLE IF EXISTS `toCompound`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `toCompound` (
  `toCompoundId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `toCompoundQty` decimal(15,3) DEFAULT NULL,
  `toCompoundOrder` tinyint(4) NOT NULL,
  `toCompoundPreselected` int(10) unsigned DEFAULT NULL,
  `itemId` int(11) DEFAULT NULL,
  `compoundId` int(11) DEFAULT NULL,
  PRIMARY KEY (`toCompoundId`)
) ENGINE=InnoDB AUTO_INCREMENT=174123 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `toCompound`
--

LOCK TABLES `toCompound` WRITE;
/*!40000 ALTER TABLE `toCompound` DISABLE KEYS */;
/*!40000 ALTER TABLE `toCompound` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `toContact`
--

DROP TABLE IF EXISTS `toContact`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `toContact` (
  `toContactId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `parentId` int(11) DEFAULT NULL,
  `contactId` int(11) DEFAULT NULL,
  PRIMARY KEY (`toContactId`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `toContact`
--

LOCK TABLES `toContact` WRITE;
/*!40000 ALTER TABLE `toContact` DISABLE KEYS */;
/*!40000 ALTER TABLE `toContact` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `toLocation`
--

DROP TABLE IF EXISTS `toLocation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `toLocation` (
  `toLocationId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `toLocationCount` decimal(15,3) DEFAULT 0.000,
  `locationId` int(11) NOT NULL,
  `outletId` int(11) DEFAULT NULL,
  `itemId` int(11) DEFAULT NULL,
  PRIMARY KEY (`toLocationId`),
  KEY `locationId` (`locationId`),
  KEY `itemId` (`itemId`),
  KEY `idx_tolocation_location_item` (`locationId`,`itemId`)
) ENGINE=InnoDB AUTO_INCREMENT=49077 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `toLocation`
--

LOCK TABLES `toLocation` WRITE;
/*!40000 ALTER TABLE `toLocation` DISABLE KEYS */;
/*!40000 ALTER TABLE `toLocation` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `toPaymentMethod`
--

DROP TABLE IF EXISTS `toPaymentMethod`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `toPaymentMethod` (
  `toPaymentMethodId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `toPaymentMethodType` tinyint(3) unsigned NOT NULL DEFAULT 0 COMMENT '0 = transaccion con el cliente ej. venta, recibo. 1 = Egreso (compra, orden de compra, pago recibos a credito)',
  `toPaymentMethodExtras` varchar(255) DEFAULT NULL,
  `paymentMethodId` varchar(15) NOT NULL COMMENT 'pueden haber Ids alphanumericos como cash, creditcard y numericos INT para los medios personalizados',
  `parentId` int(10) unsigned NOT NULL,
  PRIMARY KEY (`toPaymentMethodId`),
  KEY `toPaymentMethod_toPaymentMethodType_IDX` (`toPaymentMethodType`,`parentId`,`paymentMethodId`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=213 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `toPaymentMethod`
--

LOCK TABLES `toPaymentMethod` WRITE;
/*!40000 ALTER TABLE `toPaymentMethod` DISABLE KEYS */;
/*!40000 ALTER TABLE `toPaymentMethod` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `toScheduleUID`
--

DROP TABLE IF EXISTS `toScheduleUID`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `toScheduleUID` (
  `toScheduleUID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `scheduleId` int(11) DEFAULT NULL,
  `transactionUID` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`toScheduleUID`),
  KEY `scheduleId` (`scheduleId`),
  KEY `transactionUID` (`transactionUID`)
) ENGINE=InnoDB AUTO_INCREMENT=198159 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `toScheduleUID`
--

LOCK TABLES `toScheduleUID` WRITE;
/*!40000 ALTER TABLE `toScheduleUID` DISABLE KEYS */;
/*!40000 ALTER TABLE `toScheduleUID` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `toTag`
--

DROP TABLE IF EXISTS `toTag`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `toTag` (
  `toTagId` int(11) NOT NULL AUTO_INCREMENT,
  `toTagType` tinyint(4) NOT NULL DEFAULT 0 COMMENT '0:etiquetas para ventas y productos en venta,1:contactos,2:items busqueda',
  `parentId` int(11) NOT NULL,
  `tagId` int(11) NOT NULL,
  PRIMARY KEY (`toTagId`),
  KEY `parentId` (`parentId`),
  KEY `tagId` (`tagId`)
) ENGINE=InnoDB AUTO_INCREMENT=1284942 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `toTag`
--

LOCK TABLES `toTag` WRITE;
/*!40000 ALTER TABLE `toTag` DISABLE KEYS */;
/*!40000 ALTER TABLE `toTag` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `toTaxObj`
--

DROP TABLE IF EXISTS `toTaxObj`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `toTaxObj` (
  `toTaxObjId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `toTaxObjText` varchar(255) NOT NULL,
  `transactionId` int(10) unsigned NOT NULL,
  `companyId` int(10) unsigned NOT NULL,
  PRIMARY KEY (`toTaxObjId`),
  KEY `toTaxObj_transactionId_IDX` (`transactionId`,`companyId`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=8227358 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `toTaxObj`
--

LOCK TABLES `toTaxObj` WRITE;
/*!40000 ALTER TABLE `toTaxObj` DISABLE KEYS */;
/*!40000 ALTER TABLE `toTaxObj` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `toTransaction`
--

DROP TABLE IF EXISTS `toTransaction`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `toTransaction` (
  `toTransactionId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `parentId` int(11) DEFAULT NULL,
  `transactionId` int(11) DEFAULT NULL,
  PRIMARY KEY (`toTransactionId`),
  KEY `parentId` (`parentId`),
  KEY `transactionId` (`transactionId`)
) ENGINE=InnoDB AUTO_INCREMENT=8420655 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `toTransaction`
--

LOCK TABLES `toTransaction` WRITE;
/*!40000 ALTER TABLE `toTransaction` DISABLE KEYS */;
/*!40000 ALTER TABLE `toTransaction` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transaction`
--

DROP TABLE IF EXISTS `transaction`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transaction` (
  `transactionId` int(11) NOT NULL AUTO_INCREMENT,
  `transactionDate` timestamp NOT NULL DEFAULT current_timestamp(),
  `transactionDiscount` decimal(15,2) DEFAULT NULL,
  `transactionTax` decimal(15,2) DEFAULT NULL,
  `transactionTotal` decimal(15,2) NOT NULL,
  `transactionDetails` text DEFAULT NULL,
  `transactionUnitsSold` int(11) DEFAULT NULL,
  `transactionPaymentType` text DEFAULT NULL,
  `transactionType` tinyint(4) DEFAULT NULL COMMENT '0 = Venta al contado  	    1 = Compra al contado 	    2 = Guardada  	    3 = Venta a crédito 	    4 = Compra a crédito 	    5 = Pago de ventas a crédito 	    6 = Devolución 	    7 = Venta anulada 	    8 = Venta recursiva  9 =Cotización 10 =  Delivery 11 = Mesa 12 = orden 13 = agendado',
  `transactionName` varchar(100) DEFAULT NULL,
  `transactionNote` varchar(255) DEFAULT NULL,
  `transactionParentId` bigint(20) unsigned DEFAULT NULL,
  `transactionComplete` tinyint(1) DEFAULT 1,
  `transactionLocation` text DEFAULT NULL,
  `transactionDueDate` timestamp NULL DEFAULT NULL,
  `transactionStatus` tinyint(4) DEFAULT 1 COMMENT '1. Pendiente o en espera 2. En proceso 3. En camino 4. Orden finalizada 5. Orden Anulada 6. Otro',
  `transactionUID` bigint(20) DEFAULT NULL,
  `transactionCurrency` varchar(3) DEFAULT NULL,
  `fromDate` timestamp NULL DEFAULT NULL,
  `toDate` timestamp NULL DEFAULT NULL,
  `invoiceNo` bigint(20) DEFAULT NULL,
  `invoicePrefix` varchar(150) DEFAULT NULL,
  `tags` text DEFAULT NULL,
  `tableno` int(11) DEFAULT NULL,
  `timestamp` bigint(20) DEFAULT NULL,
  `packageId` int(11) DEFAULT NULL,
  `categoryTransId` int(11) DEFAULT NULL,
  `customerId` bigint(20) DEFAULT NULL,
  `registerId` int(11) DEFAULT NULL,
  `userId` int(11) NOT NULL,
  `responsibleId` int(11) DEFAULT NULL,
  `supplierId` int(11) DEFAULT NULL,
  `outletId` int(11) NOT NULL,
  `companyId` int(11) NOT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`transactionId`),
  UNIQUE KEY `transactionId` (`transactionId`),
  UNIQUE KEY `transactionUIDs` (`transactionUID`),
  KEY `transactionDate` (`transactionDate`),
  KEY `customerId` (`customerId`,`userId`),
  KEY `transactionType` (`transactionType`),
  KEY `companyId` (`companyId`),
  KEY `outletId` (`outletId`),
  KEY `registerId` (`registerId`),
  KEY `transactionParentId` (`transactionParentId`),
  KEY `userId` (`userId`) USING BTREE,
  KEY `idx_transaction_optimization` (`companyId`,`registerId`,`transactionType`,`transactionDate`),
  KEY `idx_transaction_optimization_2` (`companyId`,`registerId`,`transactionType`,`invoiceNo`,`transactionDate`)
) ENGINE=InnoDB AUTO_INCREMENT=13612544 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transaction`
--

LOCK TABLES `transaction` WRITE;
/*!40000 ALTER TABLE `transaction` DISABLE KEYS */;
/*!40000 ALTER TABLE `transaction` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `upsell`
--

DROP TABLE IF EXISTS `upsell`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `upsell` (
  `upsellId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `upsellParentId` int(11) DEFAULT NULL,
  `upsellChildId` int(11) DEFAULT NULL,
  `companyId` int(11) DEFAULT NULL,
  PRIMARY KEY (`upsellId`)
) ENGINE=InnoDB AUTO_INCREMENT=1257 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `upsell`
--

LOCK TABLES `upsell` WRITE;
/*!40000 ALTER TABLE `upsell` DISABLE KEYS */;
/*!40000 ALTER TABLE `upsell` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `perfil` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `vPayments`
--

DROP TABLE IF EXISTS `vPayments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vPayments` (
  `ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `date` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `payoutDate` timestamp NULL DEFAULT NULL,
  `depositedDate` timestamp NULL DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payoutAmount` decimal(15,2) DEFAULT NULL,
  `comission` decimal(15,2) DEFAULT NULL,
  `tax` decimal(15,2) DEFAULT NULL,
  `deposited` tinyint(3) unsigned DEFAULT NULL,
  `orderNo` varchar(255) NOT NULL,
  `authCode` varchar(255) DEFAULT NULL,
  `operationNo` bigint(20) unsigned DEFAULT NULL,
  `inBank` tinyint(3) unsigned DEFAULT NULL,
  `status` varchar(100) NOT NULL,
  `UID` bigint(20) unsigned DEFAULT NULL,
  `source` varchar(100) DEFAULT NULL,
  `data` text DEFAULT NULL,
  `customerId` bigint(20) unsigned DEFAULT NULL,
  `userId` int(10) unsigned DEFAULT NULL,
  `outletId` int(10) unsigned NOT NULL,
  `companyId` int(10) unsigned NOT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`ID`),
  KEY `vPayments_orderNo_IDX` (`orderNo`,`UID`,`outletId`,`companyId`) USING BTREE,
  KEY `vPayments_operationNo_IDX` (`operationNo`,`authCode`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=89465 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `vPayments`
--

LOCK TABLES `vPayments` WRITE;
/*!40000 ALTER TABLE `vPayments` DISABLE KEYS */;
/*!40000 ALTER TABLE `vPayments` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-08-14  1:09:21
