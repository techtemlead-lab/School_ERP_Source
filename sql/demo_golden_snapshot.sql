/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19-11.4.13-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: school_demo
-- ------------------------------------------------------
-- Server version	11.4.13-MariaDB-ubu2404

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;

--
-- Table structure for table `academic_classes`
--

DROP TABLE IF EXISTS `academic_classes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `academic_classes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `medium_id` int(11) DEFAULT NULL,
  `class_name` varchar(100) NOT NULL,
  `rank` int(11) NOT NULL DEFAULT 0,
  `class_code` varchar(5) DEFAULT NULL,
  `has_group` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `academic_classes`
--

LOCK TABLES `academic_classes` WRITE;
/*!40000 ALTER TABLE `academic_classes` DISABLE KEYS */;
INSERT INTO `academic_classes` VALUES
(1,1,'PLAY',1,'01',0,'Active'),
(2,1,'NURSERY',2,'02',0,'Active'),
(3,1,'KG',3,'03',0,'Active'),
(4,1,'CLASS ONE',4,'04',0,'Active'),
(5,1,'CLASS TWO',5,'05',0,'Active'),
(6,1,'CLASS THREE',6,'06',0,'Active'),
(7,1,'CLASS FOUR',7,'07',0,'Active'),
(8,1,'CLASS FIVE',8,'08',0,'Active'),
(9,1,'CLASS SIX',9,'09',0,'Active'),
(10,1,'CLASS SEVEN',10,'10',0,'Active'),
(11,1,'CLASS EIGHT',11,'11',0,'Active'),
(12,1,'CLASS NINE',12,'12',0,'Active'),
(13,1,'CLASS TEN',13,'13',0,'Active');
/*!40000 ALTER TABLE `academic_classes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `academic_groups`
--

DROP TABLE IF EXISTS `academic_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `academic_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) DEFAULT NULL,
  `group_name` varchar(100) NOT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `academic_groups`
--

LOCK TABLES `academic_groups` WRITE;
/*!40000 ALTER TABLE `academic_groups` DISABLE KEYS */;
/*!40000 ALTER TABLE `academic_groups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `academic_holidays`
--

DROP TABLE IF EXISTS `academic_holidays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `academic_holidays` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `holiday_name` varchar(150) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `holiday_type` varchar(30) NOT NULL DEFAULT 'General',
  `remarks` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `academic_holidays`
--

LOCK TABLES `academic_holidays` WRITE;
/*!40000 ALTER TABLE `academic_holidays` DISABLE KEYS */;
/*!40000 ALTER TABLE `academic_holidays` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `academic_mediums`
--

DROP TABLE IF EXISTS `academic_mediums`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `academic_mediums` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `medium_name` varchar(100) NOT NULL,
  `medium_code` varchar(20) DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `academic_mediums`
--

LOCK TABLES `academic_mediums` WRITE;
/*!40000 ALTER TABLE `academic_mediums` DISABLE KEYS */;
INSERT INTO `academic_mediums` VALUES
(1,'Bangla Medium','BM','Active'),
(2,'English Version','EV','Active');
/*!40000 ALTER TABLE `academic_mediums` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `academic_sections`
--

DROP TABLE IF EXISTS `academic_sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `academic_sections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) DEFAULT NULL,
  `shift_id` int(11) DEFAULT NULL,
  `section_name` varchar(100) NOT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `branch_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `academic_sections`
--

LOCK TABLES `academic_sections` WRITE;
/*!40000 ALTER TABLE `academic_sections` DISABLE KEYS */;
INSERT INTO `academic_sections` VALUES
(1,8,NULL,'Padma','Active',1),
(2,8,NULL,'Meghna','Active',1),
(3,13,NULL,'Jamuna','Active',1),
(4,4,NULL,'Surma','Active',1);
/*!40000 ALTER TABLE `academic_sections` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `academic_sessions`
--

DROP TABLE IF EXISTS `academic_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `academic_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_name` varchar(50) NOT NULL,
  `short_code` varchar(20) DEFAULT NULL,
  `is_fiscal_year` tinyint(1) NOT NULL DEFAULT 0,
  `start_month` varchar(20) DEFAULT NULL,
  `end_month` varchar(20) DEFAULT NULL,
  `is_current_session` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `academic_sessions`
--

LOCK TABLES `academic_sessions` WRITE;
/*!40000 ALTER TABLE `academic_sessions` DISABLE KEYS */;
INSERT INTO `academic_sessions` VALUES
(1,'2026',NULL,0,NULL,NULL,1,'Active');
/*!40000 ALTER TABLE `academic_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `academic_shifts`
--

DROP TABLE IF EXISTS `academic_shifts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `academic_shifts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `medium_id` int(11) DEFAULT NULL,
  `shift_name` varchar(100) NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `green_limit` int(11) NOT NULL DEFAULT 0,
  `orange_limit` int(11) NOT NULL DEFAULT 0,
  `red_limit` int(11) NOT NULL DEFAULT 0,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `branch_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `academic_shifts`
--

LOCK TABLES `academic_shifts` WRITE;
/*!40000 ALTER TABLE `academic_shifts` DISABLE KEYS */;
INSERT INTO `academic_shifts` VALUES
(1,NULL,'Morning Shift',NULL,NULL,0,0,0,'Active',1),
(2,NULL,'Day Shift',NULL,NULL,0,0,0,'Active',1);
/*!40000 ALTER TABLE `academic_shifts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `academic_subjects`
--

DROP TABLE IF EXISTS `academic_subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `academic_subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) DEFAULT NULL,
  `subject_name` varchar(150) NOT NULL,
  `combined_with_id` int(11) DEFAULT NULL,
  `subject_code` varchar(20) DEFAULT NULL,
  `full_marks` int(11) NOT NULL DEFAULT 100,
  `subject_type` enum('Compulsory','Optional','Continuous Assessment') NOT NULL DEFAULT 'Compulsory',
  `group_id` int(11) DEFAULT NULL,
  `optional_count` int(11) DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=86 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `academic_subjects`
--

LOCK TABLES `academic_subjects` WRITE;
/*!40000 ALTER TABLE `academic_subjects` DISABLE KEYS */;
/*!40000 ALTER TABLE `academic_subjects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `accounts_chart`
--

DROP TABLE IF EXISTS `accounts_chart`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounts_chart` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(150) NOT NULL,
  `account_type` enum('Asset','Liability','Equity','Income','Expense') NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `is_group` tinyint(1) NOT NULL DEFAULT 0,
  `opening_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `opening_balance_type` enum('Dr','Cr') NOT NULL DEFAULT 'Dr',
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_code` (`code`),
  KEY `idx_type` (`account_type`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `accounts_chart`
--

LOCK TABLES `accounts_chart` WRITE;
/*!40000 ALTER TABLE `accounts_chart` DISABLE KEYS */;
/*!40000 ALTER TABLE `accounts_chart` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `accounts_journal_lines`
--

DROP TABLE IF EXISTS `accounts_journal_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounts_journal_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `journal_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `debit` decimal(14,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(14,2) NOT NULL DEFAULT 0.00,
  `remarks` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_journal` (`journal_id`),
  KEY `idx_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `accounts_journal_lines`
--

LOCK TABLES `accounts_journal_lines` WRITE;
/*!40000 ALTER TABLE `accounts_journal_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `accounts_journal_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `accounts_journals`
--

DROP TABLE IF EXISTS `accounts_journals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounts_journals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `journal_no` varchar(30) NOT NULL,
  `journal_type` enum('Journal','Receipt','Payment','Contra') NOT NULL DEFAULT 'Journal',
  `journal_date` date NOT NULL,
  `narration` varchar(500) DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_journal_no` (`journal_no`),
  KEY `idx_date` (`journal_date`),
  KEY `idx_type` (`journal_type`),
  KEY `idx_ref` (`reference_type`,`reference_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `accounts_journals`
--

LOCK TABLES `accounts_journals` WRITE;
/*!40000 ALTER TABLE `accounts_journals` DISABLE KEYS */;
/*!40000 ALTER TABLE `accounts_journals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `accounts_vendor_bills`
--

DROP TABLE IF EXISTS `accounts_vendor_bills`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounts_vendor_bills` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vendor_id` int(11) NOT NULL,
  `bill_no` varchar(50) NOT NULL,
  `bill_date` date NOT NULL,
  `expense_account_id` int(11) NOT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `description` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Unpaid',
  `journal_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_vendor` (`vendor_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `accounts_vendor_bills`
--

LOCK TABLES `accounts_vendor_bills` WRITE;
/*!40000 ALTER TABLE `accounts_vendor_bills` DISABLE KEYS */;
/*!40000 ALTER TABLE `accounts_vendor_bills` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `accounts_vendor_payments`
--

DROP TABLE IF EXISTS `accounts_vendor_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounts_vendor_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vendor_id` int(11) NOT NULL,
  `bill_id` int(11) DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `payment_date` date NOT NULL,
  `payment_account_id` int(11) NOT NULL,
  `journal_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_vendor` (`vendor_id`),
  KEY `idx_bill` (`bill_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `accounts_vendor_payments`
--

LOCK TABLES `accounts_vendor_payments` WRITE;
/*!40000 ALTER TABLE `accounts_vendor_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `accounts_vendor_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `accounts_vendors`
--

DROP TABLE IF EXISTS `accounts_vendors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounts_vendors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `opening_due` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `accounts_vendors`
--

LOCK TABLES `accounts_vendors` WRITE;
/*!40000 ALTER TABLE `accounts_vendors` DISABLE KEYS */;
/*!40000 ALTER TABLE `accounts_vendors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `active_sessions`
--

DROP TABLE IF EXISTS `active_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `active_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_key` varchar(64) NOT NULL,
  `username` varchar(100) NOT NULL,
  `user_type` varchar(30) NOT NULL DEFAULT 'Unknown',
  `ip_address` varchar(45) DEFAULT NULL,
  `device_type` varchar(20) DEFAULT NULL,
  `browser` varchar(50) DEFAULT NULL,
  `os` varchar(50) DEFAULT NULL,
  `login_time` timestamp NULL DEFAULT current_timestamp(),
  `last_activity` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_session_key` (`session_key`),
  KEY `idx_username` (`username`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=75 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `active_sessions`
--

LOCK TABLES `active_sessions` WRITE;
/*!40000 ALTER TABLE `active_sessions` DISABLE KEYS */;
INSERT INTO `active_sessions` VALUES
(74,'9fabbc0bef1cb40eb6e9247751a298cf','demo_admin','Super Admin','103.23.205.4','Desktop','Chrome','Windows','2026-10-02 18:16:23','2026-10-02 18:16:53','Active');
/*!40000 ALTER TABLE `active_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL DEFAULT 'System',
  `user_type` varchar(30) DEFAULT NULL,
  `module` varchar(60) NOT NULL,
  `action` varchar(30) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_username` (`username`),
  KEY `idx_module` (`module`),
  KEY `idx_action` (`action`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=667 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_logs`
--

LOCK TABLES `activity_logs` WRITE;
/*!40000 ALTER TABLE `activity_logs` DISABLE KEYS */;
INSERT INTO `activity_logs` VALUES
(666,'demo_admin','Super Admin','Auth','Login','demo_admin লগইন করেছেন (Super Admin)','103.23.205.4','2026-10-02 18:16:23');
/*!40000 ALTER TABLE `activity_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admin`
--

DROP TABLE IF EXISTS `admin`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin`
--

LOCK TABLES `admin` WRITE;
/*!40000 ALTER TABLE `admin` DISABLE KEYS */;
INSERT INTO `admin` VALUES
(1,'demo_admin','$2y$10$DEvHX3epo0WVY.i63PZZ1uXrf1Pj7iD5BMkrIhUleEpaDPAkGn1X2','2026-10-02 17:53:29'),
(2,'admin','$2y$10$DEvHX3epo0WVY.i63PZZ1uXrf1Pj7iD5BMkrIhUleEpaDPAkGn1X2','2026-10-02 17:53:29');
/*!40000 ALTER TABLE `admin` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admission_fee_claims`
--

DROP TABLE IF EXISTS `admission_fee_claims`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admission_fee_claims` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_pk` int(11) DEFAULT NULL,
  `session_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `method` varchar(30) NOT NULL,
  `sender_number` varchar(30) DEFAULT NULL,
  `trx_id` varchar(100) DEFAULT NULL,
  `gateway_val_id` varchar(100) DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected','Failed') NOT NULL DEFAULT 'Pending',
  `submitted_at` timestamp NULL DEFAULT current_timestamp(),
  `verified_by` varchar(100) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `finance_collection_id` int(11) DEFAULT NULL,
  `pending_submission_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_student` (`student_pk`),
  KEY `idx_trx` (`trx_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admission_fee_claims`
--

LOCK TABLES `admission_fee_claims` WRITE;
/*!40000 ALTER TABLE `admission_fee_claims` DISABLE KEYS */;
/*!40000 ALTER TABLE `admission_fee_claims` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admission_fee_gateway_settings`
--

DROP TABLE IF EXISTS `admission_fee_gateway_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admission_fee_gateway_settings` (
  `id` int(11) NOT NULL DEFAULT 1,
  `ac_head_id` int(11) DEFAULT NULL,
  `online_payment_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `manual_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `bkash_number` varchar(30) DEFAULT NULL,
  `bkash_type` varchar(20) NOT NULL DEFAULT 'Personal',
  `nagad_number` varchar(30) DEFAULT NULL,
  `nagad_type` varchar(20) NOT NULL DEFAULT 'Personal',
  `manual_instructions` text DEFAULT NULL,
  `auto_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `auto_provider` varchar(20) NOT NULL DEFAULT 'sslcommerz',
  `sslcz_store_id` varchar(100) DEFAULT NULL,
  `sslcz_store_password` varchar(150) DEFAULT NULL,
  `sslcz_sandbox` tinyint(1) NOT NULL DEFAULT 1,
  `shurjopay_username` varchar(100) DEFAULT NULL,
  `shurjopay_password` varchar(150) DEFAULT NULL,
  `shurjopay_prefix` varchar(20) DEFAULT NULL,
  `shurjopay_sandbox` tinyint(1) NOT NULL DEFAULT 1,
  `paystation_merchant_id` varchar(100) DEFAULT NULL,
  `paystation_password` varchar(150) DEFAULT NULL,
  `paystation_sandbox` tinyint(1) NOT NULL DEFAULT 1,
  `eps_merchant_id` varchar(100) DEFAULT NULL,
  `eps_username` varchar(100) DEFAULT NULL,
  `eps_password` varchar(150) DEFAULT NULL,
  `eps_hash_key` varchar(150) DEFAULT NULL,
  `eps_sandbox` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `online_head_ids` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admission_fee_gateway_settings`
--

LOCK TABLES `admission_fee_gateway_settings` WRITE;
/*!40000 ALTER TABLE `admission_fee_gateway_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `admission_fee_gateway_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admission_pending_submissions`
--

DROP TABLE IF EXISTS `admission_pending_submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admission_pending_submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token` varchar(64) NOT NULL,
  `session_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payload` longtext NOT NULL,
  `status` enum('Pending','Completed','Expired') NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  `new_student_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admission_pending_submissions`
--

LOCK TABLES `admission_pending_submissions` WRITE;
/*!40000 ALTER TABLE `admission_pending_submissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `admission_pending_submissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admission_settings`
--

DROP TABLE IF EXISTS `admission_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admission_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `method` enum('None','Test','Lottery') NOT NULL DEFAULT 'None',
  `total_seats` int(11) DEFAULT NULL,
  `full_marks` int(11) DEFAULT NULL,
  `pass_marks` int(11) DEFAULT NULL,
  `admission_fee` decimal(10,2) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_session_class` (`session_id`,`class_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admission_settings`
--

LOCK TABLES `admission_settings` WRITE;
/*!40000 ALTER TABLE `admission_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `admission_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admissions`
--

DROP TABLE IF EXISTS `admissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_name_bn` varchar(150) NOT NULL,
  `student_name_en` varchar(150) NOT NULL,
  `apply_class` varchar(100) NOT NULL,
  `apply_group` varchar(150) NOT NULL,
  `dob` date NOT NULL,
  `religion` varchar(30) NOT NULL,
  `blood_group` varchar(10) DEFAULT NULL,
  `gender` varchar(10) NOT NULL,
  `father_name` varchar(150) NOT NULL,
  `mother_name` varchar(150) NOT NULL,
  `guardian_phone` varchar(15) NOT NULL,
  `guardian_email` varchar(150) DEFAULT NULL,
  `address` text NOT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `marksheet_path` varchar(255) DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admissions`
--

LOCK TABLES `admissions` WRITE;
/*!40000 ALTER TABLE `admissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `admissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance_agent_settings`
--

DROP TABLE IF EXISTS `attendance_agent_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_agent_settings` (
  `id` int(11) NOT NULL,
  `secret` varchar(64) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance_agent_settings`
--

LOCK TABLES `attendance_agent_settings` WRITE;
/*!40000 ALTER TABLE `attendance_agent_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance_agent_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance_devices`
--

DROP TABLE IF EXISTS `attendance_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_devices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_name` varchar(150) NOT NULL,
  `brand` varchar(50) NOT NULL,
  `device_model` varchar(100) DEFAULT NULL,
  `device_for` enum('student','employee','both') NOT NULL DEFAULT 'both',
  `branch_id` int(11) DEFAULT NULL,
  `connection_type` enum('cloud','local') NOT NULL DEFAULT 'local',
  `cloud_provider` varchar(100) DEFAULT NULL,
  `api_base_url` varchar(255) DEFAULT NULL,
  `api_key` varchar(255) DEFAULT NULL,
  `api_secret` varchar(255) DEFAULT NULL,
  `cloud_device_sn` varchar(100) DEFAULT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `port` int(11) NOT NULL DEFAULT 4370,
  `device_serial` varchar(100) DEFAULT NULL,
  `comm_key` varchar(50) DEFAULT NULL,
  `protocol` varchar(30) NOT NULL DEFAULT 'zkteco_tcp',
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `notes` text DEFAULT NULL,
  `live_status` varchar(20) DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance_devices`
--

LOCK TABLES `attendance_devices` WRITE;
/*!40000 ALTER TABLE `attendance_devices` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance_devices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `branches`
--

DROP TABLE IF EXISTS `branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `branches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `short_name` varchar(30) NOT NULL,
  `branch_name` varchar(200) NOT NULL,
  `address` text NOT NULL,
  `city` varchar(100) NOT NULL,
  `zip_code` varchar(20) NOT NULL,
  `phone` varchar(30) NOT NULL,
  `fax` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `signature_path` varchar(255) DEFAULT NULL,
  `weekly_holidays` varchar(100) NOT NULL DEFAULT 'Friday',
  `map_iframe` text DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `branches`
--

LOCK TABLES `branches` WRITE;
/*!40000 ALTER TABLE `branches` DISABLE KEYS */;
INSERT INTO `branches` VALUES
(1,'Main Campus','Smart Model Academy - Main Campus','House #12, Road #05, Block-D, Mirpur-11, Dhaka','Dhaka','1216','01700000001',NULL,'main@schooledo.com',NULL,'Friday',NULL,'Active','2026-10-02 17:53:29','2026-10-02 17:53:29'),
(2,'Junior Campus','Smart Model Academy - Junior Section','House #24, Road #02, Mirpur-10, Dhaka','Dhaka','1216','01700000002',NULL,'junior@schooledo.com',NULL,'Friday',NULL,'Active','2026-10-02 17:53:29','2026-10-02 17:53:29');
/*!40000 ALTER TABLE `branches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `canteen_categories`
--

DROP TABLE IF EXISTS `canteen_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `canteen_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `canteen_categories`
--

LOCK TABLES `canteen_categories` WRITE;
/*!40000 ALTER TABLE `canteen_categories` DISABLE KEYS */;
/*!40000 ALTER TABLE `canteen_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `canteen_menu_items`
--

DROP TABLE IF EXISTS `canteen_menu_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `canteen_menu_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `photo_path` varchar(255) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `canteen_menu_items`
--

LOCK TABLES `canteen_menu_items` WRITE;
/*!40000 ALTER TABLE `canteen_menu_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `canteen_menu_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `canteen_purchase_items`
--

DROP TABLE IF EXISTS `canteen_purchase_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `canteen_purchase_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `purchase_id` int(11) NOT NULL,
  `raw_material_id` int(11) NOT NULL,
  `qty` decimal(10,3) NOT NULL,
  `rate` decimal(10,2) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `canteen_purchase_items`
--

LOCK TABLES `canteen_purchase_items` WRITE;
/*!40000 ALTER TABLE `canteen_purchase_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `canteen_purchase_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `canteen_purchases`
--

DROP TABLE IF EXISTS `canteen_purchases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `canteen_purchases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `purchase_no` varchar(40) NOT NULL,
  `supplier_name` varchar(150) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `due_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `canteen_purchases`
--

LOCK TABLES `canteen_purchases` WRITE;
/*!40000 ALTER TABLE `canteen_purchases` DISABLE KEYS */;
/*!40000 ALTER TABLE `canteen_purchases` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `canteen_raw_materials`
--

DROP TABLE IF EXISTS `canteen_raw_materials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `canteen_raw_materials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `unit_id` int(11) DEFAULT NULL,
  `current_stock` decimal(10,3) NOT NULL DEFAULT 0.000,
  `reorder_level` decimal(10,3) NOT NULL DEFAULT 0.000,
  `unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `canteen_raw_materials`
--

LOCK TABLES `canteen_raw_materials` WRITE;
/*!40000 ALTER TABLE `canteen_raw_materials` DISABLE KEYS */;
/*!40000 ALTER TABLE `canteen_raw_materials` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `canteen_recipe_items`
--

DROP TABLE IF EXISTS `canteen_recipe_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `canteen_recipe_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `menu_item_id` int(11) NOT NULL,
  `raw_material_id` int(11) NOT NULL,
  `qty_required` decimal(10,3) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `canteen_recipe_items`
--

LOCK TABLES `canteen_recipe_items` WRITE;
/*!40000 ALTER TABLE `canteen_recipe_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `canteen_recipe_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `canteen_sale_items`
--

DROP TABLE IF EXISTS `canteen_sale_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `canteen_sale_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sale_id` int(11) NOT NULL,
  `menu_item_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  `rate` decimal(10,2) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `canteen_sale_items`
--

LOCK TABLES `canteen_sale_items` WRITE;
/*!40000 ALTER TABLE `canteen_sale_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `canteen_sale_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `canteen_sale_payments`
--

DROP TABLE IF EXISTS `canteen_sale_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `canteen_sale_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sale_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `paid_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `canteen_sale_payments`
--

LOCK TABLES `canteen_sale_payments` WRITE;
/*!40000 ALTER TABLE `canteen_sale_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `canteen_sale_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `canteen_sales`
--

DROP TABLE IF EXISTS `canteen_sales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `canteen_sales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_no` varchar(40) NOT NULL,
  `buyer_type` varchar(10) NOT NULL DEFAULT 'walkin',
  `student_pk` int(11) DEFAULT NULL,
  `student_code` varchar(50) DEFAULT NULL,
  `employee_pk` int(11) DEFAULT NULL,
  `employee_code` varchar(50) DEFAULT NULL,
  `customer_name` varchar(150) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `due_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `canteen_sales`
--

LOCK TABLES `canteen_sales` WRITE;
/*!40000 ALTER TABLE `canteen_sales` DISABLE KEYS */;
/*!40000 ALTER TABLE `canteen_sales` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `canteen_settings`
--

DROP TABLE IF EXISTS `canteen_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `canteen_settings` (
  `id` int(11) NOT NULL,
  `allow_due_sale` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `canteen_settings`
--

LOCK TABLES `canteen_settings` WRITE;
/*!40000 ALTER TABLE `canteen_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `canteen_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `canteen_stock_adjustments`
--

DROP TABLE IF EXISTS `canteen_stock_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `canteen_stock_adjustments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `raw_material_id` int(11) NOT NULL,
  `adj_type` varchar(20) NOT NULL,
  `qty` decimal(10,3) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `canteen_stock_adjustments`
--

LOCK TABLES `canteen_stock_adjustments` WRITE;
/*!40000 ALTER TABLE `canteen_stock_adjustments` DISABLE KEYS */;
/*!40000 ALTER TABLE `canteen_stock_adjustments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `canteen_supplier_payments`
--

DROP TABLE IF EXISTS `canteen_supplier_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `canteen_supplier_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `purchase_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `paid_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `canteen_supplier_payments`
--

LOCK TABLES `canteen_supplier_payments` WRITE;
/*!40000 ALTER TABLE `canteen_supplier_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `canteen_supplier_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `canteen_suppliers`
--

DROP TABLE IF EXISTS `canteen_suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `canteen_suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `canteen_suppliers`
--

LOCK TABLES `canteen_suppliers` WRITE;
/*!40000 ALTER TABLE `canteen_suppliers` DISABLE KEYS */;
/*!40000 ALTER TABLE `canteen_suppliers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `canteen_units`
--

DROP TABLE IF EXISTS `canteen_units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `canteen_units` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(30) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `canteen_units`
--

LOCK TABLES `canteen_units` WRITE;
/*!40000 ALTER TABLE `canteen_units` DISABLE KEYS */;
/*!40000 ALTER TABLE `canteen_units` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `class_teachers`
--

DROP TABLE IF EXISTS `class_teachers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `class_teachers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_class_section` (`class_id`,`section_id`),
  KEY `idx_employee` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `class_teachers`
--

LOCK TABLES `class_teachers` WRITE;
/*!40000 ALTER TABLE `class_teachers` DISABLE KEYS */;
/*!40000 ALTER TABLE `class_teachers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `designations`
--

DROP TABLE IF EXISTS `designations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `designations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` enum('Teachers','Staffs','Board Of Directors','Academic Committee') NOT NULL DEFAULT 'Teachers',
  `title` varchar(150) NOT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `designations`
--

LOCK TABLES `designations` WRITE;
/*!40000 ALTER TABLE `designations` DISABLE KEYS */;
/*!40000 ALTER TABLE `designations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `device_attendance_logs`
--

DROP TABLE IF EXISTS `device_attendance_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `device_attendance_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` int(11) DEFAULT NULL,
  `person_type` enum('student','employee') DEFAULT NULL,
  `person_uid` varchar(100) NOT NULL,
  `punch_type` enum('in','out') NOT NULL DEFAULT 'in',
  `punch_time` datetime NOT NULL,
  `raw_payload` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `verify_mode` varchar(20) DEFAULT NULL,
  `is_processed` tinyint(1) NOT NULL DEFAULT 0,
  `processed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_device_uid_time` (`device_id`,`person_uid`,`punch_time`),
  KEY `idx_person` (`person_type`,`person_uid`,`punch_time`),
  KEY `idx_device` (`device_id`,`punch_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `device_attendance_logs`
--

LOCK TABLES `device_attendance_logs` WRITE;
/*!40000 ALTER TABLE `device_attendance_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `device_attendance_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `elearning_homework`
--

DROP TABLE IF EXISTS `elearning_homework`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `elearning_homework` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `section_id` int(11) DEFAULT NULL,
  `subject_id` int(11) NOT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `deadline` datetime DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_class_section` (`class_id`,`section_id`),
  KEY `idx_subject` (`subject_id`),
  KEY `idx_teacher` (`teacher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `elearning_homework`
--

LOCK TABLES `elearning_homework` WRITE;
/*!40000 ALTER TABLE `elearning_homework` DISABLE KEYS */;
/*!40000 ALTER TABLE `elearning_homework` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `elearning_homework_submissions`
--

DROP TABLE IF EXISTS `elearning_homework_submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `elearning_homework_submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `homework_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `submission_text` text DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT current_timestamp(),
  `marks` decimal(5,2) DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `status` enum('Submitted','Checked') NOT NULL DEFAULT 'Submitted',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_submission` (`homework_id`,`student_id`),
  KEY `idx_homework` (`homework_id`),
  KEY `idx_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `elearning_homework_submissions`
--

LOCK TABLES `elearning_homework_submissions` WRITE;
/*!40000 ALTER TABLE `elearning_homework_submissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `elearning_homework_submissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `elearning_live_classes`
--

DROP TABLE IF EXISTS `elearning_live_classes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `elearning_live_classes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `section_id` int(11) DEFAULT NULL,
  `subject_id` int(11) NOT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `scheduled_at` datetime NOT NULL,
  `duration_minutes` int(11) NOT NULL DEFAULT 40,
  `room_id` varchar(255) DEFAULT NULL,
  `room_error` varchar(500) DEFAULT NULL,
  `status` enum('Scheduled','Live','Ended','Cancelled') NOT NULL DEFAULT 'Scheduled',
  `branch_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_class_section` (`class_id`,`section_id`),
  KEY `idx_subject` (`subject_id`),
  KEY `idx_teacher` (`teacher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `elearning_live_classes`
--

LOCK TABLES `elearning_live_classes` WRITE;
/*!40000 ALTER TABLE `elearning_live_classes` DISABLE KEYS */;
/*!40000 ALTER TABLE `elearning_live_classes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `elearning_live_settings`
--

DROP TABLE IF EXISTS `elearning_live_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `elearning_live_settings` (
  `id` int(11) NOT NULL DEFAULT 1,
  `hms_access_key` text DEFAULT NULL,
  `hms_secret` text DEFAULT NULL,
  `hms_template_id` varchar(150) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `youtube_refresh_token` text DEFAULT NULL,
  `youtube_channel_title` varchar(150) DEFAULT NULL,
  `youtube_oauth_pending` varchar(64) DEFAULT NULL,
  `hms_webhook_secret` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `elearning_live_settings`
--

LOCK TABLES `elearning_live_settings` WRITE;
/*!40000 ALTER TABLE `elearning_live_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `elearning_live_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `elearning_recorded_classes`
--

DROP TABLE IF EXISTS `elearning_recorded_classes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `elearning_recorded_classes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `section_id` int(11) DEFAULT NULL,
  `subject_id` int(11) NOT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `video_type` enum('youtube','vimeo') NOT NULL DEFAULT 'youtube',
  `video_url` varchar(500) NOT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_class_section` (`class_id`,`section_id`),
  KEY `idx_subject` (`subject_id`),
  KEY `idx_teacher` (`teacher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `elearning_recorded_classes`
--

LOCK TABLES `elearning_recorded_classes` WRITE;
/*!40000 ALTER TABLE `elearning_recorded_classes` DISABLE KEYS */;
/*!40000 ALTER TABLE `elearning_recorded_classes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_daily_attendance`
--

DROP TABLE IF EXISTS `employee_daily_attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_daily_attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `check_in_time` datetime DEFAULT NULL,
  `check_out_time` datetime DEFAULT NULL,
  `status` enum('Present','Absent') NOT NULL DEFAULT 'Present',
  `marked_by` varchar(100) DEFAULT NULL,
  `source` varchar(30) NOT NULL DEFAULT 'device',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_employee_date` (`employee_id`,`attendance_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_daily_attendance`
--

LOCK TABLES `employee_daily_attendance` WRITE;
/*!40000 ALTER TABLE `employee_daily_attendance` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_daily_attendance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_educations`
--

DROP TABLE IF EXISTS `employee_educations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_educations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `institute` varchar(255) DEFAULT NULL,
  `degree` varchar(150) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `employee_educations_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_educations`
--

LOCK TABLES `employee_educations` WRITE;
/*!40000 ALTER TABLE `employee_educations` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_educations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_grades`
--

DROP TABLE IF EXISTS `employee_grades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_grades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `grade_name` varchar(100) NOT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_grades`
--

LOCK TABLES `employee_grades` WRITE;
/*!40000 ALTER TABLE `employee_grades` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_grades` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_shifts`
--

DROP TABLE IF EXISTS `employee_shifts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_shifts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `green_limit` int(11) NOT NULL DEFAULT 0,
  `orange_limit` int(11) NOT NULL DEFAULT 0,
  `red_limit` int(11) NOT NULL DEFAULT 0,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_shifts`
--

LOCK TABLES `employee_shifts` WRITE;
/*!40000 ALTER TABLE `employee_shifts` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_shifts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_types`
--

DROP TABLE IF EXISTS `employee_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` enum('Full-time','Part-time') NOT NULL DEFAULT 'Full-time',
  `name` varchar(100) NOT NULL,
  `deduct_salary_on_absent` tinyint(1) NOT NULL DEFAULT 0,
  `for_absent_days` int(11) NOT NULL DEFAULT 0,
  `salary_deduct_days` int(11) NOT NULL DEFAULT 0,
  `has_overtime` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_types`
--

LOCK TABLES `employee_types` WRITE;
/*!40000 ALTER TABLE `employee_types` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employees`
--

DROP TABLE IF EXISTS `employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` enum('Teachers','Staffs','Board Of Directors','Academic Committee') NOT NULL DEFAULT 'Teachers',
  `employee_type_id` int(11) DEFAULT NULL,
  `designation_id` int(11) DEFAULT NULL,
  `shift_id` int(11) DEFAULT NULL,
  `grade_id` int(11) DEFAULT NULL,
  `rank` int(11) DEFAULT NULL,
  `employee_id` varchar(50) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 1,
  `device_serial` varchar(100) DEFAULT NULL,
  `tracking_id` varchar(100) DEFAULT NULL,
  `rfid_card_no` varchar(100) DEFAULT NULL,
  `joining_date` date DEFAULT NULL,
  `bank_account_no` varchar(100) DEFAULT NULL,
  `previous_institute` varchar(255) DEFAULT NULL,
  `previous_designation` varchar(255) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `mobile` varchar(20) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `national_id` varchar(50) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `fathers_name` varchar(150) DEFAULT NULL,
  `mothers_name` varchar(150) DEFAULT NULL,
  `marital_status` varchar(20) DEFAULT NULL,
  `religion` varchar(50) DEFAULT NULL,
  `blood_group` varchar(5) DEFAULT NULL,
  `gender` varchar(10) DEFAULT NULL,
  `facebook_link` varchar(255) DEFAULT NULL,
  `major_subject` varchar(150) DEFAULT NULL,
  `extra_qualification` text DEFAULT NULL,
  `present_address` text DEFAULT NULL,
  `permanent_address` text DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `is_current` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `branch_id` int(11) DEFAULT NULL,
  `section_level` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_id` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employees`
--

LOCK TABLES `employees` WRITE;
/*!40000 ALTER TABLE `employees` DISABLE KEYS */;
INSERT INTO `employees` VALUES
(1,'Teachers',NULL,NULL,NULL,NULL,NULL,'EMP-01',NULL,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Dr. Mohammad Rafiqul Islam (Principal)','01711000001','principal@schooledo.com',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Male',NULL,NULL,NULL,NULL,NULL,'Active',1,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,NULL),
(2,'Teachers',NULL,NULL,NULL,NULL,NULL,'EMP-02',NULL,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Nasreen Sultana (Vice Principal)','01711000002','viceprincipal@schooledo.com',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Female',NULL,NULL,NULL,NULL,NULL,'Active',1,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,NULL),
(3,'Teachers',NULL,NULL,NULL,NULL,NULL,'EMP-03',NULL,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Kazi Tanvir Ahmed (Senior Math Teacher)','01711000003','tanvir@schooledo.com',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Male',NULL,NULL,NULL,NULL,NULL,'Active',1,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,NULL),
(4,'Teachers',NULL,NULL,NULL,NULL,NULL,'EMP-04',NULL,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Shamima Akhter (English Teacher)','01711000004','shamima@schooledo.com',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Female',NULL,NULL,NULL,NULL,NULL,'Active',1,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,NULL),
(5,'Teachers',NULL,NULL,NULL,NULL,NULL,'EMP-05',NULL,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Mahmudul Hasan (Science Teacher)','01711000005','mahmud@schooledo.com',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Male',NULL,NULL,NULL,NULL,NULL,'Active',1,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,NULL),
(6,'Teachers',NULL,NULL,NULL,NULL,NULL,'EMP-06',NULL,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Fatema Tuz Zohra (Bangla Teacher)','01711000006','fatema@schooledo.com',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Female',NULL,NULL,NULL,NULL,NULL,'Active',1,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,NULL);
/*!40000 ALTER TABLE `employees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_assign_subjects`
--

DROP TABLE IF EXISTS `exam_assign_subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_assign_subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `part_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `full_mark_subject` decimal(6,2) NOT NULL DEFAULT 100.00,
  `mark_for_term_percent` decimal(6,2) NOT NULL DEFAULT 100.00,
  `full_mark_exam` decimal(6,2) NOT NULL DEFAULT 100.00,
  `pass_mark_exam` decimal(6,2) NOT NULL DEFAULT 33.00,
  `pass_individual` tinyint(1) NOT NULL DEFAULT 1,
  `fail_on_absent` tinyint(1) NOT NULL DEFAULT 1,
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_assign` (`term_id`,`part_id`,`subject_id`),
  KEY `idx_class` (`class_id`),
  KEY `idx_session` (`session_id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_assign_subjects`
--

LOCK TABLES `exam_assign_subjects` WRITE;
/*!40000 ALTER TABLE `exam_assign_subjects` DISABLE KEYS */;
/*!40000 ALTER TABLE `exam_assign_subjects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_attendance_subject`
--

DROP TABLE IF EXISTS `exam_attendance_subject`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_attendance_subject` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `part_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `shift_id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `status` enum('Present','Absent') NOT NULL DEFAULT 'Absent',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_att` (`term_id`,`part_id`,`subject_id`,`student_id`),
  KEY `idx_lookup` (`class_id`,`shift_id`,`section_id`,`term_id`,`part_id`,`subject_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1084 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_attendance_subject`
--

LOCK TABLES `exam_attendance_subject` WRITE;
/*!40000 ALTER TABLE `exam_attendance_subject` DISABLE KEYS */;
/*!40000 ALTER TABLE `exam_attendance_subject` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_eligibility`
--

DROP TABLE IF EXISTS `exam_eligibility`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_eligibility` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `shift_id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `is_eligible` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_elig` (`term_id`,`student_id`,`subject_id`),
  KEY `idx_lookup` (`class_id`,`shift_id`,`section_id`,`term_id`)
) ENGINE=InnoDB AUTO_INCREMENT=546 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_eligibility`
--

LOCK TABLES `exam_eligibility` WRITE;
/*!40000 ALTER TABLE `exam_eligibility` DISABLE KEYS */;
/*!40000 ALTER TABLE `exam_eligibility` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_fee_gateway_settings`
--

DROP TABLE IF EXISTS `exam_fee_gateway_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_fee_gateway_settings` (
  `id` int(11) NOT NULL DEFAULT 1,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `bkash_number` varchar(30) DEFAULT NULL,
  `bkash_type` varchar(20) NOT NULL DEFAULT 'Personal',
  `nagad_number` varchar(30) DEFAULT NULL,
  `nagad_type` varchar(20) NOT NULL DEFAULT 'Personal',
  `instructions` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_fee_gateway_settings`
--

LOCK TABLES `exam_fee_gateway_settings` WRITE;
/*!40000 ALTER TABLE `exam_fee_gateway_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `exam_fee_gateway_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_fee_online_claims`
--

DROP TABLE IF EXISTS `exam_fee_online_claims`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_fee_online_claims` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `ac_head_id` int(11) NOT NULL,
  `month` tinyint(4) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `method` varchar(20) NOT NULL DEFAULT 'bKash',
  `trx_id` varchar(100) DEFAULT NULL,
  `gateway_val_id` varchar(100) DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `submitted_at` timestamp NULL DEFAULT current_timestamp(),
  `verified_by` varchar(100) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `finance_collection_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_claim` (`term_id`,`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_fee_online_claims`
--

LOCK TABLES `exam_fee_online_claims` WRITE;
/*!40000 ALTER TABLE `exam_fee_online_claims` DISABLE KEYS */;
/*!40000 ALTER TABLE `exam_fee_online_claims` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_grade_points`
--

DROP TABLE IF EXISTS `exam_grade_points`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_grade_points` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `grade_letter` varchar(10) NOT NULL,
  `gpa` decimal(4,2) NOT NULL DEFAULT 0.00,
  `mark_from` decimal(6,2) NOT NULL DEFAULT 0.00,
  `mark_to` decimal(6,2) NOT NULL DEFAULT 0.00,
  `remarks` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_grade_points`
--

LOCK TABLES `exam_grade_points` WRITE;
/*!40000 ALTER TABLE `exam_grade_points` DISABLE KEYS */;
/*!40000 ALTER TABLE `exam_grade_points` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_marks`
--

DROP TABLE IF EXISTS `exam_marks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_marks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `part_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `shift_id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `marks_obtained` decimal(6,2) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_mark` (`term_id`,`part_id`,`subject_id`,`student_id`),
  KEY `idx_lookup` (`class_id`,`shift_id`,`section_id`,`term_id`,`part_id`,`subject_id`)
) ENGINE=InnoDB AUTO_INCREMENT=890 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_marks`
--

LOCK TABLES `exam_marks` WRITE;
/*!40000 ALTER TABLE `exam_marks` DISABLE KEYS */;
/*!40000 ALTER TABLE `exam_marks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_parts`
--

DROP TABLE IF EXISTS `exam_parts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_parts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `main_part_id` int(11) DEFAULT NULL,
  `part_name` varchar(100) NOT NULL,
  `part_short_name` varchar(30) NOT NULL,
  `calc_method` varchar(20) NOT NULL DEFAULT 'Average',
  `sub_part_full_marks` decimal(6,2) DEFAULT NULL,
  `rank` int(11) NOT NULL DEFAULT 1,
  `term_combine_method` varchar(20) NOT NULL DEFAULT 'None',
  `attach_scope` varchar(20) NOT NULL DEFAULT 'Every Term',
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_session` (`session_id`),
  KEY `idx_main_part` (`main_part_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_parts`
--

LOCK TABLES `exam_parts` WRITE;
/*!40000 ALTER TABLE `exam_parts` DISABLE KEYS */;
/*!40000 ALTER TABLE `exam_parts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_result_settings`
--

DROP TABLE IF EXISTS `exam_result_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_result_settings` (
  `id` int(11) NOT NULL DEFAULT 1,
  `show_medium_name` tinyint(1) NOT NULL DEFAULT 1,
  `show_student_roll_no` tinyint(1) NOT NULL DEFAULT 1,
  `show_student_picture_qr` tinyint(1) NOT NULL DEFAULT 0,
  `add_watermark_background` tinyint(1) NOT NULL DEFAULT 1,
  `show_banner_instead_logo` tinyint(1) NOT NULL DEFAULT 0,
  `pass_fail_summary_merit_list` tinyint(1) NOT NULL DEFAULT 1,
  `merit_list_with_subjects` tinyint(1) NOT NULL DEFAULT 1,
  `show_final_grade_letter_point` tinyint(1) NOT NULL DEFAULT 1,
  `show_subject_grade_letter` tinyint(1) NOT NULL DEFAULT 1,
  `show_subject_grade_point` tinyint(1) NOT NULL DEFAULT 1,
  `show_result_remarks` tinyint(1) NOT NULL DEFAULT 1,
  `show_working_days_attend_absent` tinyint(1) NOT NULL DEFAULT 1,
  `show_absent_count` tinyint(1) NOT NULL DEFAULT 1,
  `write_manual_attendance_count` tinyint(1) NOT NULL DEFAULT 1,
  `show_discipline_cleanliness` tinyint(1) NOT NULL DEFAULT 1,
  `show_interpretation` tinyint(1) NOT NULL DEFAULT 1,
  `show_total_subject_fail_count` tinyint(1) NOT NULL DEFAULT 0,
  `show_part_marks_calculation` tinyint(1) NOT NULL DEFAULT 1,
  `calculate_merit_position_by_marks` tinyint(1) NOT NULL DEFAULT 0,
  `enable_merit_tiebreaker` tinyint(1) NOT NULL DEFAULT 1,
  `aggregate_result_type` varchar(50) NOT NULL DEFAULT 'Terms Wise Percentage',
  `report_card_template` varchar(30) NOT NULL DEFAULT 'Govt. / MPO',
  `left_signature` varchar(100) NOT NULL DEFAULT 'Signature of Teacher',
  `middle_signature` varchar(100) NOT NULL DEFAULT 'Signature of Coordinator',
  `right_signature` varchar(100) NOT NULL DEFAULT 'Signature of Headmaster',
  `body_font_size` int(11) NOT NULL DEFAULT 12,
  `body_font_color` varchar(10) NOT NULL DEFAULT '#333333',
  `institute_name_color` varchar(10) NOT NULL DEFAULT '#000080',
  `table_header_color` varchar(10) NOT NULL DEFAULT '#333333',
  `fail_letter_grade_color` varchar(10) NOT NULL DEFAULT '#ff0000',
  `body_font_style` varchar(20) NOT NULL DEFAULT 'Normal',
  `gpa_color` varchar(10) NOT NULL DEFAULT '#00B1E1',
  `remarks_progress_color` varchar(10) NOT NULL DEFAULT '#00B1E1',
  `table_footer_color` varchar(10) NOT NULL DEFAULT '#00B1E1',
  `fail_part_marks_color` varchar(10) NOT NULL DEFAULT '#ff0000',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_result_settings`
--

LOCK TABLES `exam_result_settings` WRITE;
/*!40000 ALTER TABLE `exam_result_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `exam_result_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_routines`
--

DROP TABLE IF EXISTS `exam_routines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_routines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `shift_id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `part_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `exam_date` date DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_routine` (`class_id`,`shift_id`,`section_id`,`term_id`,`part_id`,`subject_id`),
  KEY `idx_session` (`session_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_routines`
--

LOCK TABLES `exam_routines` WRITE;
/*!40000 ALTER TABLE `exam_routines` DISABLE KEYS */;
/*!40000 ALTER TABLE `exam_routines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_seat_rooms`
--

DROP TABLE IF EXISTS `exam_seat_rooms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_seat_rooms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `part_id` int(11) NOT NULL DEFAULT 0,
  `class_id` int(11) NOT NULL,
  `shift_id` int(11) NOT NULL DEFAULT 0,
  `section_id` int(11) NOT NULL DEFAULT 0,
  `room` varchar(100) NOT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_room` (`session_id`,`term_id`,`part_id`,`class_id`,`shift_id`,`section_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_seat_rooms`
--

LOCK TABLES `exam_seat_rooms` WRITE;
/*!40000 ALTER TABLE `exam_seat_rooms` DISABLE KEYS */;
/*!40000 ALTER TABLE `exam_seat_rooms` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_terms`
--

DROP TABLE IF EXISTS `exam_terms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_terms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `term_name` varchar(100) NOT NULL,
  `fee_month` tinyint(4) DEFAULT NULL,
  `exam_fee_head_id` int(11) DEFAULT NULL,
  `rank` int(11) NOT NULL DEFAULT 1,
  `is_final_term` tinyint(1) NOT NULL DEFAULT 0,
  `is_aggregated_result` tinyint(1) NOT NULL DEFAULT 0,
  `admit_card_with_routine` tinyint(1) NOT NULL DEFAULT 0,
  `admit_card_with_instruction` tinyint(1) NOT NULL DEFAULT 0,
  `exam_routine_with_instruction` tinyint(1) NOT NULL DEFAULT 0,
  `website_result_publish` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_session` (`session_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_terms`
--

LOCK TABLES `exam_terms` WRITE;
/*!40000 ALTER TABLE `exam_terms` DISABLE KEYS */;
/*!40000 ALTER TABLE `exam_terms` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_working_days`
--

DROP TABLE IF EXISTS `exam_working_days`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_working_days` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `total_working_days` int(11) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_wd` (`term_id`,`class_id`),
  KEY `idx_session` (`session_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_working_days`
--

LOCK TABLES `exam_working_days` WRITE;
/*!40000 ALTER TABLE `exam_working_days` DISABLE KEYS */;
/*!40000 ALTER TABLE `exam_working_days` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_account_heads`
--

DROP TABLE IF EXISTS `finance_account_heads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_account_heads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ac_type` varchar(30) NOT NULL,
  `ac_category` varchar(20) NOT NULL DEFAULT 'Receipts',
  `parent_id` int(11) NOT NULL DEFAULT 0,
  `ac_code` varchar(30) NOT NULL,
  `ac_head` varchar(150) NOT NULL,
  `period_type` varchar(10) NOT NULL DEFAULT 'Monthly',
  `has_child` varchar(5) NOT NULL DEFAULT 'No',
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ac_code` (`ac_code`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_type` (`ac_type`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_account_heads`
--

LOCK TABLES `finance_account_heads` WRITE;
/*!40000 ALTER TABLE `finance_account_heads` DISABLE KEYS */;
/*!40000 ALTER TABLE `finance_account_heads` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_admission_fee_items`
--

DROP TABLE IF EXISTS `finance_admission_fee_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_admission_fee_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_id` int(11) NOT NULL,
  `ac_head_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_setting` (`setting_id`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_admission_fee_items`
--

LOCK TABLES `finance_admission_fee_items` WRITE;
/*!40000 ALTER TABLE `finance_admission_fee_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `finance_admission_fee_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_admission_fee_settings`
--

DROP TABLE IF EXISTS `finance_admission_fee_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_admission_fee_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `shift_id` int(11) NOT NULL DEFAULT 0,
  `section_id` int(11) NOT NULL DEFAULT 0,
  `gender` varchar(10) NOT NULL DEFAULT '',
  `applicability` varchar(30) NOT NULL DEFAULT 'New',
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lookup` (`session_id`,`class_id`,`shift_id`,`section_id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_admission_fee_settings`
--

LOCK TABLES `finance_admission_fee_settings` WRITE;
/*!40000 ALTER TABLE `finance_admission_fee_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `finance_admission_fee_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_fee_collections`
--

DROP TABLE IF EXISTS `finance_fee_collections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_fee_collections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `session_id` int(11) NOT NULL,
  `period_type` enum('Admission','Monthly') NOT NULL,
  `month` tinyint(4) NOT NULL DEFAULT 0,
  `ac_head_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(30) NOT NULL DEFAULT 'Cash',
  `trx_id` varchar(100) DEFAULT NULL,
  `folio_no` varchar(50) DEFAULT NULL,
  `receipt_token` varchar(40) DEFAULT NULL,
  `receipt_no` varchar(50) DEFAULT NULL,
  `collected_by` varchar(100) DEFAULT NULL,
  `collected_at` timestamp NULL DEFAULT current_timestamp(),
  `status` varchar(20) NOT NULL DEFAULT 'Paid',
  PRIMARY KEY (`id`),
  KEY `idx_lookup` (`student_id`,`session_id`,`period_type`,`month`),
  KEY `idx_receipt_token` (`receipt_token`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_fee_collections`
--

LOCK TABLES `finance_fee_collections` WRITE;
/*!40000 ALTER TABLE `finance_fee_collections` DISABLE KEYS */;
INSERT INTO `finance_fee_collections` VALUES
(1,1,1,'Monthly',10,NULL,2500.00,'Cash',NULL,NULL,NULL,'MR-26-001',NULL,'2026-10-02 17:53:29','Paid'),
(2,2,1,'Monthly',10,NULL,2500.00,'Cash',NULL,NULL,NULL,'MR-26-002',NULL,'2026-10-02 17:53:29','Paid'),
(3,3,1,'Monthly',10,NULL,1500.00,'Cash',NULL,NULL,NULL,'MR-26-003',NULL,'2026-10-02 17:53:29','Paid'),
(4,4,1,'Monthly',10,NULL,3000.00,'Cash',NULL,NULL,NULL,'MR-26-004',NULL,'2026-10-02 17:53:29','Paid'),
(5,5,1,'Monthly',10,NULL,2000.00,'Cash',NULL,NULL,NULL,'MR-26-005',NULL,'2026-10-02 17:53:29','Paid'),
(6,11,1,'Monthly',10,NULL,2000.00,'Cash',NULL,NULL,NULL,'MR-26-006',NULL,'2026-10-02 17:53:29','Paid'),
(7,12,1,'Monthly',10,NULL,2000.00,'Cash',NULL,NULL,NULL,'MR-26-007',NULL,'2026-10-02 17:53:29','Paid'),
(8,16,1,'Monthly',10,NULL,3500.00,'Cash',NULL,NULL,NULL,'MR-26-008',NULL,'2026-10-02 17:53:29','Paid'),
(9,17,1,'Monthly',10,NULL,2000.00,'Cash',NULL,NULL,NULL,'MR-26-009',NULL,'2026-10-02 17:53:29','Paid'),
(10,21,1,'Monthly',10,NULL,2800.00,'Cash',NULL,NULL,NULL,'MR-26-010',NULL,'2026-10-02 17:53:29','Paid');
/*!40000 ALTER TABLE `finance_fee_collections` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_money_receipt_settings`
--

DROP TABLE IF EXISTS `finance_money_receipt_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_money_receipt_settings` (
  `id` int(11) NOT NULL DEFAULT 1,
  `copy_type` varchar(20) NOT NULL DEFAULT 'student',
  `paper_size` varchar(20) NOT NULL DEFAULT 'a4_portrait',
  `show_due` tinyint(1) NOT NULL DEFAULT 1,
  `show_waiver` tinyint(1) NOT NULL DEFAULT 1,
  `show_waiver_student` tinyint(1) NOT NULL DEFAULT 1,
  `allow_instant_waiver` tinyint(1) NOT NULL DEFAULT 0,
  `show_logo` tinyint(1) NOT NULL DEFAULT 1,
  `show_signature` tinyint(1) NOT NULL DEFAULT 1,
  `footer_note` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_money_receipt_settings`
--

LOCK TABLES `finance_money_receipt_settings` WRITE;
/*!40000 ALTER TABLE `finance_money_receipt_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `finance_money_receipt_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_monthly_fee_items`
--

DROP TABLE IF EXISTS `finance_monthly_fee_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_monthly_fee_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_id` int(11) NOT NULL,
  `month` tinyint(4) NOT NULL,
  `ac_head_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_item` (`setting_id`,`month`,`ac_head_id`),
  KEY `idx_setting` (`setting_id`)
) ENGINE=InnoDB AUTO_INCREMENT=336 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_monthly_fee_items`
--

LOCK TABLES `finance_monthly_fee_items` WRITE;
/*!40000 ALTER TABLE `finance_monthly_fee_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `finance_monthly_fee_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_monthly_fee_settings`
--

DROP TABLE IF EXISTS `finance_monthly_fee_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_monthly_fee_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `shift_id` int(11) NOT NULL DEFAULT 0,
  `section_id` int(11) NOT NULL DEFAULT 0,
  `gender` varchar(10) NOT NULL DEFAULT '',
  `applicability` varchar(30) NOT NULL DEFAULT 'New',
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lookup` (`session_id`,`class_id`,`shift_id`,`section_id`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_monthly_fee_settings`
--

LOCK TABLES `finance_monthly_fee_settings` WRITE;
/*!40000 ALTER TABLE `finance_monthly_fee_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `finance_monthly_fee_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_student_admission_fees`
--

DROP TABLE IF EXISTS `finance_student_admission_fees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_student_admission_fees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `session_id` int(11) NOT NULL,
  `ac_head_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_item` (`student_id`,`session_id`,`ac_head_id`),
  KEY `idx_student` (`student_id`,`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_student_admission_fees`
--

LOCK TABLES `finance_student_admission_fees` WRITE;
/*!40000 ALTER TABLE `finance_student_admission_fees` DISABLE KEYS */;
/*!40000 ALTER TABLE `finance_student_admission_fees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_student_monthly_fees`
--

DROP TABLE IF EXISTS `finance_student_monthly_fees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_student_monthly_fees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `session_id` int(11) NOT NULL,
  `month` tinyint(4) NOT NULL,
  `ac_head_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_item` (`student_id`,`session_id`,`month`,`ac_head_id`),
  KEY `idx_student` (`student_id`,`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_student_monthly_fees`
--

LOCK TABLES `finance_student_monthly_fees` WRITE;
/*!40000 ALTER TABLE `finance_student_monthly_fees` DISABLE KEYS */;
/*!40000 ALTER TABLE `finance_student_monthly_fees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_student_waivers`
--

DROP TABLE IF EXISTS `finance_student_waivers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_student_waivers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `session_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `month` tinyint(4) NOT NULL,
  `ac_head_id` int(11) NOT NULL,
  `waiver_type` enum('Percentage','Fixed') NOT NULL DEFAULT 'Fixed',
  `waiver_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `folio_no` varchar(50) DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lookup` (`student_id`,`session_id`,`month`,`ac_head_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_student_waivers`
--

LOCK TABLES `finance_student_waivers` WRITE;
/*!40000 ALTER TABLE `finance_student_waivers` DISABLE KEYS */;
/*!40000 ALTER TABLE `finance_student_waivers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_voucher_details`
--

DROP TABLE IF EXISTS `finance_voucher_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_voucher_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `voucher_id` int(11) NOT NULL,
  `ac_head_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `remarks` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_voucher` (`voucher_id`),
  KEY `idx_head` (`ac_head_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_voucher_details`
--

LOCK TABLES `finance_voucher_details` WRITE;
/*!40000 ALTER TABLE `finance_voucher_details` DISABLE KEYS */;
/*!40000 ALTER TABLE `finance_voucher_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_vouchers`
--

DROP TABLE IF EXISTS `finance_vouchers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_vouchers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `voucher_no` varchar(50) NOT NULL,
  `voucher_type` varchar(20) NOT NULL,
  `transaction_date` date NOT NULL,
  `payment_type` varchar(10) NOT NULL DEFAULT 'Cash',
  `description` text DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_voucher_no` (`voucher_no`),
  KEY `idx_type` (`voucher_type`),
  KEY `idx_date` (`transaction_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_vouchers`
--

LOCK TABLES `finance_vouchers` WRITE;
/*!40000 ALTER TABLE `finance_vouchers` DISABLE KEYS */;
/*!40000 ALTER TABLE `finance_vouchers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gallery_items`
--

DROP TABLE IF EXISTS `gallery_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `gallery_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `caption` varchar(255) NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gallery_items`
--

LOCK TABLES `gallery_items` WRITE;
/*!40000 ALTER TABLE `gallery_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `gallery_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `id_card_settings`
--

DROP TABLE IF EXISTS `id_card_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `id_card_settings` (
  `id` int(11) NOT NULL DEFAULT 1,
  `show_session` tinyint(1) NOT NULL DEFAULT 0,
  `show_class_section` tinyint(1) NOT NULL DEFAULT 0,
  `show_shift` tinyint(1) NOT NULL DEFAULT 0,
  `show_group` tinyint(1) NOT NULL DEFAULT 0,
  `show_roll` tinyint(1) NOT NULL DEFAULT 0,
  `show_dob` tinyint(1) NOT NULL DEFAULT 0,
  `show_blood` tinyint(1) NOT NULL DEFAULT 0,
  `show_parent_photo` tinyint(1) NOT NULL DEFAULT 0,
  `show_parent_phone` tinyint(1) NOT NULL DEFAULT 0,
  `front_part` varchar(20) NOT NULL DEFAULT 'general',
  `back_part` varchar(20) NOT NULL DEFAULT 'general',
  `orientation` varchar(15) NOT NULL DEFAULT 'Portrait',
  `top_color` varchar(10) NOT NULL DEFAULT '#ef4444',
  `bottom_color` varchar(10) NOT NULL DEFAULT '#1e293b',
  `establishment` varchar(50) DEFAULT NULL,
  `show_qr` tinyint(1) NOT NULL DEFAULT 0,
  `qr_side` varchar(10) NOT NULL DEFAULT 'Back',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `id_card_settings`
--

LOCK TABLES `id_card_settings` WRITE;
/*!40000 ALTER TABLE `id_card_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `id_card_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `id_card_templates`
--

DROP TABLE IF EXISTS `id_card_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `id_card_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `side` enum('front','back') NOT NULL DEFAULT 'front',
  `image_path` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `id_card_templates`
--

LOCK TABLES `id_card_templates` WRITE;
/*!40000 ALTER TABLE `id_card_templates` DISABLE KEYS */;
/*!40000 ALTER TABLE `id_card_templates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `institute_settings`
--

DROP TABLE IF EXISTS `institute_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `institute_settings` (
  `id` int(11) NOT NULL DEFAULT 1,
  `facebook_page` varchar(255) DEFAULT NULL,
  `youtube_video` varchar(255) DEFAULT NULL,
  `admin_theme` varchar(30) NOT NULL DEFAULT 'Deep Green',
  `website_theme` varchar(30) NOT NULL DEFAULT 'E-Learning',
  `eiin_no` varchar(30) DEFAULT NULL,
  `institute_name` varchar(200) NOT NULL DEFAULT '',
  `phone_number` varchar(30) DEFAULT NULL,
  `slogan` varchar(255) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `favicon_path` varchar(255) DEFAULT NULL,
  `banner_path` varchar(255) DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `short_description` text DEFAULT NULL,
  `why_choose_institute` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `admission_type` varchar(20) NOT NULL DEFAULT 'General',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `institute_settings`
--

LOCK TABLES `institute_settings` WRITE;
/*!40000 ALTER TABLE `institute_settings` DISABLE KEYS */;
INSERT INTO `institute_settings` VALUES
(1,NULL,NULL,'Dark','Modern','134589','Smart Model Academy & College (Live Demo)','01700-000000','Empowering Minds, Shaping Tomorrow','demo@schooledo.com',NULL,NULL,NULL,NULL,NULL,'2026-10-02 17:53:29','General');
/*!40000 ALTER TABLE `institute_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_items`
--

DROP TABLE IF EXISTS `inventory_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `stock_qty` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_items`
--

LOCK TABLES `inventory_items` WRITE;
/*!40000 ALTER TABLE `inventory_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventory_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `login_logs`
--

DROP TABLE IF EXISTS `login_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `login_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `user_type` varchar(30) NOT NULL,
  `login_status` varchar(10) NOT NULL DEFAULT 'Success',
  `ip_address` varchar(45) DEFAULT NULL,
  `device_type` varchar(20) DEFAULT NULL,
  `browser` varchar(50) DEFAULT NULL,
  `os` varchar(50) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `login_time` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_username` (`username`),
  KEY `idx_login_time` (`login_time`)
) ENGINE=InnoDB AUTO_INCREMENT=87 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `login_logs`
--

LOCK TABLES `login_logs` WRITE;
/*!40000 ALTER TABLE `login_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `login_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `message_corner`
--

DROP TABLE IF EXISTS `message_corner`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `message_corner` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `designation` varchar(150) NOT NULL,
  `role_key` varchar(30) NOT NULL DEFAULT 'other',
  `photo_path` varchar(255) DEFAULT NULL,
  `message` text NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `message_corner`
--

LOCK TABLES `message_corner` WRITE;
/*!40000 ALTER TABLE `message_corner` DISABLE KEYS */;
/*!40000 ALTER TABLE `message_corner` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `message_role_settings`
--

DROP TABLE IF EXISTS `message_role_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `message_role_settings` (
  `role_key` varchar(30) NOT NULL,
  `is_shown` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `label_bn` varchar(100) DEFAULT NULL,
  `is_custom` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`role_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `message_role_settings`
--

LOCK TABLES `message_role_settings` WRITE;
/*!40000 ALTER TABLE `message_role_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `message_role_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notices`
--

DROP TABLE IF EXISTS `notices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `notice_date` date NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `body` longtext DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `attachment_name` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notices`
--

LOCK TABLES `notices` WRITE;
/*!40000 ALTER TABLE `notices` DISABLE KEYS */;
INSERT INTO `notices` VALUES
(1,'২০২৬ শিক্ষাবর্ষের অর্ধবার্ষিক পরীক্ষার সময়সূচি প্রকাশ সংক্রান্ত বিজ্ঞপ্তি','2026-10-02',NULL,NULL,NULL,NULL,1,'2026-10-02 17:53:29'),
(2,'আসন্ন গ্রীষ্মকালীন ছুটি ও অনলাইন ক্লাস কার্যক্রম প্রসঙ্গে','2026-10-02',NULL,NULL,NULL,NULL,1,'2026-10-02 17:53:29');
/*!40000 ALTER TABLE `notices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `poor_fund_aids`
--

DROP TABLE IF EXISTS `poor_fund_aids`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `poor_fund_aids` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `session_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `reason` varchar(255) DEFAULT NULL,
  `voucher_id` int(11) DEFAULT NULL,
  `receipt_no` varchar(50) DEFAULT NULL,
  `given_by` varchar(100) DEFAULT NULL,
  `given_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_student` (`student_id`),
  KEY `idx_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `poor_fund_aids`
--

LOCK TABLES `poor_fund_aids` WRITE;
/*!40000 ALTER TABLE `poor_fund_aids` DISABLE KEYS */;
/*!40000 ALTER TABLE `poor_fund_aids` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `promo_contacts`
--

DROP TABLE IF EXISTS `promo_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `promo_contacts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` int(11) NOT NULL,
  `mobile` varchar(20) NOT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_group` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `promo_contacts`
--

LOCK TABLES `promo_contacts` WRITE;
/*!40000 ALTER TABLE `promo_contacts` DISABLE KEYS */;
/*!40000 ALTER TABLE `promo_contacts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `promo_groups`
--

DROP TABLE IF EXISTS `promo_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `promo_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_name` varchar(150) NOT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `promo_groups`
--

LOCK TABLES `promo_groups` WRITE;
/*!40000 ALTER TABLE `promo_groups` DISABLE KEYS */;
/*!40000 ALTER TABLE `promo_groups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `result_lock_audit`
--

DROP TABLE IF EXISTS `result_lock_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `result_lock_audit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `action` varchar(20) NOT NULL,
  `acted_by` varchar(100) DEFAULT NULL,
  `acted_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lookup` (`session_id`,`class_id`,`term_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `result_lock_audit`
--

LOCK TABLES `result_lock_audit` WRITE;
/*!40000 ALTER TABLE `result_lock_audit` DISABLE KEYS */;
/*!40000 ALTER TABLE `result_lock_audit` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `result_locks`
--

DROP TABLE IF EXISTS `result_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `result_locks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Unlocked',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lock` (`session_id`,`class_id`,`term_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `result_locks`
--

LOCK TABLES `result_locks` WRITE;
/*!40000 ALTER TABLE `result_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `result_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `result_merit_tiebreakers`
--

DROP TABLE IF EXISTS `result_merit_tiebreakers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `result_merit_tiebreakers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL DEFAULT 0,
  `criterion_type` varchar(20) NOT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `priority_order` int(11) NOT NULL DEFAULT 1,
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_session_class` (`session_id`,`class_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `result_merit_tiebreakers`
--

LOCK TABLES `result_merit_tiebreakers` WRITE;
/*!40000 ALTER TABLE `result_merit_tiebreakers` DISABLE KEYS */;
/*!40000 ALTER TABLE `result_merit_tiebreakers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `routines`
--

DROP TABLE IF EXISTS `routines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `routines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) DEFAULT NULL,
  `shift_id` int(11) DEFAULT NULL,
  `section_id` int(11) NOT NULL,
  `routine_days` varchar(100) DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `has_break` tinyint(1) NOT NULL DEFAULT 0,
  `subject_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `branch_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lookup` (`class_id`,`shift_id`,`section_id`),
  KEY `idx_teacher` (`teacher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `routines`
--

LOCK TABLES `routines` WRITE;
/*!40000 ALTER TABLE `routines` DISABLE KEYS */;
/*!40000 ALTER TABLE `routines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sales_invoices`
--

DROP TABLE IF EXISTS `sales_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_name` varchar(150) NOT NULL,
  `invoice_no` varchar(50) NOT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `due_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `date` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_no` (`invoice_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sales_invoices`
--

LOCK TABLES `sales_invoices` WRITE;
/*!40000 ALTER TABLE `sales_invoices` DISABLE KEYS */;
/*!40000 ALTER TABLE `sales_invoices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `scholarship_awards`
--

DROP TABLE IF EXISTS `scholarship_awards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scholarship_awards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `scholarship_type_id` int(11) NOT NULL,
  `session_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('Pending','Disbursed','Cancelled') NOT NULL DEFAULT 'Pending',
  `remarks` varchar(255) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_student` (`student_id`),
  KEY `idx_session` (`session_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `scholarship_awards`
--

LOCK TABLES `scholarship_awards` WRITE;
/*!40000 ALTER TABLE `scholarship_awards` DISABLE KEYS */;
/*!40000 ALTER TABLE `scholarship_awards` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `scholarship_disbursement_lines`
--

DROP TABLE IF EXISTS `scholarship_disbursement_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scholarship_disbursement_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `disbursement_id` int(11) NOT NULL,
  `ac_head_id` int(11) DEFAULT NULL,
  `period` tinyint(4) NOT NULL DEFAULT 0,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `finance_collection_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_disbursement` (`disbursement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `scholarship_disbursement_lines`
--

LOCK TABLES `scholarship_disbursement_lines` WRITE;
/*!40000 ALTER TABLE `scholarship_disbursement_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `scholarship_disbursement_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `scholarship_disbursements`
--

DROP TABLE IF EXISTS `scholarship_disbursements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scholarship_disbursements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `award_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `adjusted_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `cash_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(30) NOT NULL DEFAULT 'Cash',
  `trx_id` varchar(100) DEFAULT NULL,
  `receipt_no` varchar(50) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `disbursed_by` varchar(100) DEFAULT NULL,
  `disbursed_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_award` (`award_id`),
  KEY `idx_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `scholarship_disbursements`
--

LOCK TABLES `scholarship_disbursements` WRITE;
/*!40000 ALTER TABLE `scholarship_disbursements` DISABLE KEYS */;
/*!40000 ALTER TABLE `scholarship_disbursements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `scholarship_types`
--

DROP TABLE IF EXISTS `scholarship_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scholarship_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `category` enum('Government','Board','NGO','School') NOT NULL DEFAULT 'Government',
  `description` text DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `scholarship_types`
--

LOCK TABLES `scholarship_types` WRITE;
/*!40000 ALTER TABLE `scholarship_types` DISABLE KEYS */;
/*!40000 ALTER TABLE `scholarship_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `security_settings`
--

DROP TABLE IF EXISTS `security_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `security_settings` (
  `id` int(11) NOT NULL DEFAULT 1,
  `session_timeout_minutes` int(11) NOT NULL DEFAULT 60,
  `force_single_session` tinyint(1) NOT NULL DEFAULT 0,
  `updated_by` varchar(100) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `security_settings`
--

LOCK TABLES `security_settings` WRITE;
/*!40000 ALTER TABLE `security_settings` DISABLE KEYS */;
INSERT INTO `security_settings` VALUES
(1,60,0,NULL,'2026-10-02 18:16:23');
/*!40000 ALTER TABLE `security_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sliders`
--

DROP TABLE IF EXISTS `sliders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sliders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `subtitle` varchar(500) DEFAULT NULL,
  `image_path` varchar(255) NOT NULL,
  `button_text` varchar(100) DEFAULT NULL,
  `button_link` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sliders`
--

LOCK TABLES `sliders` WRITE;
/*!40000 ALTER TABLE `sliders` DISABLE KEYS */;
INSERT INTO `sliders` VALUES
(1,'স্মার্ট শিক্ষা ব্যবস্থাপনায় স্বাগতম','ডিজিটাল উদ্ভাবন ও আধুনিক শিক্ষাপদ্ধতির সমন্বয়','assets/images/slider/slide1.jpg',NULL,NULL,1,1,'2026-10-02 17:53:29'),
(2,'বার্ষিক ক্রীড়া ও সাংস্কৃতিক সপ্তাহ','শিক্ষার্থীদের মেধা ও ক্রীড়া প্রতিভা বিকাশের মহোৎসব','assets/images/slider/slide2.jpg',NULL,NULL,2,1,'2026-10-02 17:53:29');
/*!40000 ALTER TABLE `sliders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sms_logs`
--

DROP TABLE IF EXISTS `sms_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sms_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) DEFAULT NULL,
  `phone` varchar(20) NOT NULL,
  `purpose` varchar(50) NOT NULL DEFAULT 'absent',
  `channel` varchar(20) NOT NULL DEFAULT 'SMS',
  `message` text DEFAULT NULL,
  `status` enum('Sent','Failed') NOT NULL DEFAULT 'Failed',
  `response` text DEFAULT NULL,
  `sent_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sms_logs`
--

LOCK TABLES `sms_logs` WRITE;
/*!40000 ALTER TABLE `sms_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `sms_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sms_settings`
--

DROP TABLE IF EXISTS `sms_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sms_settings` (
  `id` int(11) NOT NULL DEFAULT 1,
  `show_teachers_home` tinyint(1) NOT NULL DEFAULT 1,
  `show_teachers_mobile` tinyint(1) NOT NULL DEFAULT 1,
  `show_teachers_address` tinyint(1) NOT NULL DEFAULT 1,
  `show_teachers_email` tinyint(1) NOT NULL DEFAULT 1,
  `show_count_home` tinyint(1) NOT NULL DEFAULT 1,
  `sms_admission` tinyint(1) NOT NULL DEFAULT 1,
  `sms_congratulation` tinyint(1) NOT NULL DEFAULT 0,
  `sms_punch_in` tinyint(1) NOT NULL DEFAULT 0,
  `sms_punch_out` tinyint(1) NOT NULL DEFAULT 0,
  `sms_add_employee` tinyint(1) NOT NULL DEFAULT 0,
  `sms_absent` tinyint(1) NOT NULL DEFAULT 0,
  `sms_due` tinyint(1) NOT NULL DEFAULT 1,
  `sms_fee_collection` tinyint(1) NOT NULL DEFAULT 1,
  `content_admission` text DEFAULT NULL,
  `content_congratulation` text DEFAULT NULL,
  `content_teacher_add` text DEFAULT NULL,
  `content_punch_in` text DEFAULT NULL,
  `content_punch_out` text DEFAULT NULL,
  `content_absent` text DEFAULT NULL,
  `content_due` text DEFAULT NULL,
  `content_fee_collection` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `api_provider` varchar(50) DEFAULT 'GreenWeb',
  `api_method` enum('GET','POST') NOT NULL DEFAULT 'GET',
  `api_url` text DEFAULT NULL,
  `api_token` varchar(255) DEFAULT NULL,
  `api_user` varchar(150) DEFAULT NULL,
  `api_sender_id` varchar(50) DEFAULT NULL,
  `api_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `show_video_home` tinyint(1) NOT NULL DEFAULT 1,
  `show_gallery_home` tinyint(1) NOT NULL DEFAULT 1,
  `balance_api_url` text DEFAULT NULL,
  `sms_balance` bigint(20) NOT NULL DEFAULT 0,
  `sms_balance_taka` decimal(12,2) NOT NULL DEFAULT 0.00,
  `sms_buy` bigint(20) NOT NULL DEFAULT 0,
  `balance_checked_at` timestamp NULL DEFAULT NULL,
  `breaking_news_position` varchar(20) NOT NULL DEFAULT 'above_hero',
  `show_notices_home` tinyint(1) NOT NULL DEFAULT 1,
  `show_events_home` tinyint(1) NOT NULL DEFAULT 1,
  `show_section_filter` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sms_settings`
--

LOCK TABLES `sms_settings` WRITE;
/*!40000 ALTER TABLE `sms_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `sms_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_daily_attendance`
--

DROP TABLE IF EXISTS `student_daily_attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_daily_attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `class_id` int(11) DEFAULT NULL,
  `section_id` int(11) DEFAULT NULL,
  `shift_id` int(11) DEFAULT NULL,
  `attendance_date` date NOT NULL,
  `status` enum('Present','Absent') NOT NULL DEFAULT 'Absent',
  `marked_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `check_in_time` datetime DEFAULT NULL,
  `check_out_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_student_date` (`student_id`,`attendance_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_daily_attendance`
--

LOCK TABLES `student_daily_attendance` WRITE;
/*!40000 ALTER TABLE `student_daily_attendance` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_daily_attendance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `students`
--

DROP TABLE IF EXISTS `students`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `students` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) DEFAULT NULL,
  `shift_id` int(11) DEFAULT NULL,
  `class_id` int(11) DEFAULT NULL,
  `section_id` int(11) DEFAULT NULL,
  `device_serial` varchar(50) DEFAULT NULL,
  `student_id` varchar(50) NOT NULL,
  `tracking_id` varchar(50) DEFAULT NULL,
  `rfid_card_no` varchar(50) DEFAULT NULL,
  `roll_no` varchar(20) NOT NULL,
  `attendance_sms` tinyint(1) NOT NULL DEFAULT 1,
  `name_en` varchar(150) NOT NULL,
  `name_bn` varchar(150) DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `birth_reg_no` varchar(50) DEFAULT NULL,
  `gender` varchar(10) NOT NULL,
  `orphan` varchar(5) DEFAULT NULL,
  `disability_status` varchar(30) DEFAULT NULL,
  `blood_group` varchar(10) NOT NULL,
  `religion` varchar(30) NOT NULL,
  `nationality` varchar(50) NOT NULL DEFAULT 'Bangladeshi',
  `working_student` varchar(5) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `mobile` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `present_address` text NOT NULL,
  `permanent_address` text NOT NULL,
  `prev_school_name` varchar(200) DEFAULT NULL,
  `prev_class_id` int(11) DEFAULT NULL,
  `prev_section` varchar(50) DEFAULT NULL,
  `prev_roll` varchar(20) DEFAULT NULL,
  `prev_result` varchar(50) DEFAULT NULL,
  `father_name` varchar(150) NOT NULL,
  `father_phone` varchar(20) DEFAULT NULL,
  `father_nid` varchar(30) DEFAULT NULL,
  `father_profession` varchar(100) DEFAULT NULL,
  `father_designation` varchar(100) DEFAULT NULL,
  `father_office_address` text DEFAULT NULL,
  `father_office_phone` varchar(20) DEFAULT NULL,
  `father_photo_path` varchar(255) DEFAULT NULL,
  `mother_name` varchar(150) NOT NULL,
  `mother_phone` varchar(20) DEFAULT NULL,
  `mother_nid` varchar(30) DEFAULT NULL,
  `mother_profession` varchar(100) DEFAULT NULL,
  `mother_designation` varchar(100) DEFAULT NULL,
  `mother_office_address` text DEFAULT NULL,
  `mother_office_phone` varchar(20) DEFAULT NULL,
  `mother_photo_path` varchar(255) DEFAULT NULL,
  `yearly_income` varchar(50) DEFAULT NULL,
  `landless` varchar(5) DEFAULT NULL,
  `guardian_type` varchar(20) NOT NULL DEFAULT 'Father',
  `guardian_name` varchar(150) NOT NULL,
  `guardian_relation` varchar(100) DEFAULT NULL,
  `guardian_phone` varchar(20) NOT NULL,
  `guardian_profession` varchar(100) DEFAULT NULL,
  `guardian_designation` varchar(100) DEFAULT NULL,
  `guardian_office_contact` varchar(20) DEFAULT NULL,
  `guardian_office_address` text DEFAULT NULL,
  `group_id` int(11) DEFAULT NULL,
  `admission_id` int(11) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `comments` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `branch_id` int(11) DEFAULT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT 1,
  `test_marks` decimal(6,2) DEFAULT NULL,
  `admission_result` varchar(20) DEFAULT NULL,
  `result_published_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_student_id` (`student_id`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `students`
--

LOCK TABLES `students` WRITE;
/*!40000 ALTER TABLE `students` DISABLE KEYS */;
INSERT INTO `students` VALUES
(1,1,NULL,8,1,NULL,'STD-26-001',NULL,NULL,'01',1,'Ayman Zarif','আয়মান জারিফ',NULL,NULL,'Male',NULL,NULL,'B+','Islam','Bangladeshi',NULL,NULL,'01712000001',NULL,'Mirpur, Dhaka','Mirpur, Dhaka',NULL,NULL,NULL,NULL,NULL,'Md. Zahirul Islam',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Salma Begum',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Father','Md. Zahirul Islam',NULL,'01712000001',NULL,NULL,NULL,NULL,NULL,NULL,'Active',NULL,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,1,NULL,NULL,NULL,NULL,0),
(2,1,NULL,8,1,NULL,'STD-26-002',NULL,NULL,'02',1,'Sadia Jahan','সাদিয়া জাহান',NULL,NULL,'Female',NULL,NULL,'B+','Islam','Bangladeshi',NULL,NULL,'01712000002',NULL,'Mirpur, Dhaka','Mirpur, Dhaka',NULL,NULL,NULL,NULL,NULL,'Kamal Hossain',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Nasima Khatun',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Father','Kamal Hossain',NULL,'01712000002',NULL,NULL,NULL,NULL,NULL,NULL,'Active',NULL,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,1,NULL,NULL,NULL,NULL,0),
(3,1,NULL,8,1,NULL,'STD-26-003',NULL,NULL,'03',1,'Tanvir Hasan','তানভীর হাসান',NULL,NULL,'Male',NULL,NULL,'B+','Islam','Bangladeshi',NULL,NULL,'01712000003',NULL,'Mirpur, Dhaka','Mirpur, Dhaka',NULL,NULL,NULL,NULL,NULL,'Abul Kashem',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Rehana Parveen',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Father','Abul Kashem',NULL,'01712000003',NULL,NULL,NULL,NULL,NULL,NULL,'Active',NULL,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,1,NULL,NULL,NULL,NULL,0),
(4,1,NULL,8,1,NULL,'STD-26-004',NULL,NULL,'04',1,'Tasnim Anjum','তাসনিম আনজুম',NULL,NULL,'Female',NULL,NULL,'B+','Islam','Bangladeshi',NULL,NULL,'01712000004',NULL,'Mirpur, Dhaka','Mirpur, Dhaka',NULL,NULL,NULL,NULL,NULL,'Faruk Ahmed',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Shirina Akhter',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Father','Faruk Ahmed',NULL,'01712000004',NULL,NULL,NULL,NULL,NULL,NULL,'Active',NULL,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,1,NULL,NULL,NULL,NULL,0),
(5,1,NULL,8,1,NULL,'STD-26-005',NULL,NULL,'05',1,'Nafis Iqbal','নাফিস ইকবাল',NULL,NULL,'Male',NULL,NULL,'B+','Islam','Bangladeshi',NULL,NULL,'01712000005',NULL,'Mirpur, Dhaka','Mirpur, Dhaka',NULL,NULL,NULL,NULL,NULL,'Iqbal Bahar',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Lutfun Nahar',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Father','Iqbal Bahar',NULL,'01712000005',NULL,NULL,NULL,NULL,NULL,NULL,'Active',NULL,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,1,NULL,NULL,NULL,NULL,0),
(6,1,NULL,8,2,NULL,'STD-26-006',NULL,NULL,'06',1,'Humaira Tabassum','হুমায়রা তাবাসসুম',NULL,NULL,'Female',NULL,NULL,'B+','Islam','Bangladeshi',NULL,NULL,'01712000006',NULL,'Mirpur, Dhaka','Mirpur, Dhaka',NULL,NULL,NULL,NULL,NULL,'Mizanur Rahman',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Rokeya Begum',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Father','Mizanur Rahman',NULL,'01712000006',NULL,NULL,NULL,NULL,NULL,NULL,'Active',NULL,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,1,NULL,NULL,NULL,NULL,0),
(7,1,NULL,8,2,NULL,'STD-26-007',NULL,NULL,'07',1,'Samiul Alim','সামিউল আলিম',NULL,NULL,'Male',NULL,NULL,'B+','Islam','Bangladeshi',NULL,NULL,'01712000007',NULL,'Mirpur, Dhaka','Mirpur, Dhaka',NULL,NULL,NULL,NULL,NULL,'Abdul Alim',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Tahmina Begum',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Father','Abdul Alim',NULL,'01712000007',NULL,NULL,NULL,NULL,NULL,NULL,'Active',NULL,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,1,NULL,NULL,NULL,NULL,0),
(8,1,NULL,8,2,NULL,'STD-26-008',NULL,NULL,'08',1,'Jannatul Ferdous','জান্নাতুল ফেরদৌস',NULL,NULL,'Female',NULL,NULL,'B+','Islam','Bangladeshi',NULL,NULL,'01712000008',NULL,'Mirpur, Dhaka','Mirpur, Dhaka',NULL,NULL,NULL,NULL,NULL,'Nurul Islam',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Ferdousi Begum',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Father','Nurul Islam',NULL,'01712000008',NULL,NULL,NULL,NULL,NULL,NULL,'Active',NULL,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,1,NULL,NULL,NULL,NULL,0),
(9,1,NULL,8,2,NULL,'STD-26-009',NULL,NULL,'09',1,'Farhan Labib','ফারহান লাবিব',NULL,NULL,'Male',NULL,NULL,'B+','Islam','Bangladeshi',NULL,NULL,'01712000009',NULL,'Mirpur, Dhaka','Mirpur, Dhaka',NULL,NULL,NULL,NULL,NULL,'Delwar Hossain',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Shahida Sultana',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Father','Delwar Hossain',NULL,'01712000009',NULL,NULL,NULL,NULL,NULL,NULL,'Active',NULL,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,1,NULL,NULL,NULL,NULL,0),
(10,1,NULL,8,2,NULL,'STD-26-010',NULL,NULL,'10',1,'Mahira Khan','মাহিরা খান',NULL,NULL,'Female',NULL,NULL,'B+','Islam','Bangladeshi',NULL,NULL,'01712000010',NULL,'Mirpur, Dhaka','Mirpur, Dhaka',NULL,NULL,NULL,NULL,NULL,'Amir Khan',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Monira Begum',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Father','Amir Khan',NULL,'01712000010',NULL,NULL,NULL,NULL,NULL,NULL,'Active',NULL,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,1,NULL,NULL,NULL,NULL,0),
(11,1,NULL,4,4,NULL,'STD-26-011',NULL,NULL,'01',1,'Zayan Al Din','জায়ান আল দ্বীন',NULL,NULL,'Male',NULL,NULL,'B+','Islam','Bangladeshi',NULL,NULL,'01712000011',NULL,'Mirpur, Dhaka','Mirpur, Dhaka',NULL,NULL,NULL,NULL,NULL,'Saifuddin',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Asma Ul Husna',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Father','Saifuddin',NULL,'01712000011',NULL,NULL,NULL,NULL,NULL,NULL,'Active',NULL,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,1,NULL,NULL,NULL,NULL,0),
(12,1,NULL,4,4,NULL,'STD-26-012',NULL,NULL,'02',1,'Afrin Sultana','আফরিন সুলতানা',NULL,NULL,'Female',NULL,NULL,'B+','Islam','Bangladeshi',NULL,NULL,'01712000012',NULL,'Mirpur, Dhaka','Mirpur, Dhaka',NULL,NULL,NULL,NULL,NULL,'Sultan Ahmed',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Rasheda Begum',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Father','Sultan Ahmed',NULL,'01712000012',NULL,NULL,NULL,NULL,NULL,NULL,'Active',NULL,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,1,NULL,NULL,NULL,NULL,0),
(13,1,NULL,4,4,NULL,'STD-26-013',NULL,NULL,'03',1,'Rayhan Kabir','রায়হান কবির',NULL,NULL,'Male',NULL,NULL,'B+','Islam','Bangladeshi',NULL,NULL,'01712000013',NULL,'Mirpur, Dhaka','Mirpur, Dhaka',NULL,NULL,NULL,NULL,NULL,'Humayun Kabir',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Shamsun Nahar',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Father','Humayun Kabir',NULL,'01712000013',NULL,NULL,NULL,NULL,NULL,NULL,'Active',NULL,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,1,NULL,NULL,NULL,NULL,0),
(14,1,NULL,4,4,NULL,'STD-26-014',NULL,NULL,'04',1,'Nusrat Jahan','নুসরাত জাহান',NULL,NULL,'Female',NULL,NULL,'B+','Islam','Bangladeshi',NULL,NULL,'01712000014',NULL,'Mirpur, Dhaka','Mirpur, Dhaka',NULL,NULL,NULL,NULL,NULL,'Jahangir Alam',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Farida Yasmin',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Father','Jahangir Alam',NULL,'01712000014',NULL,NULL,NULL,NULL,NULL,NULL,'Active',NULL,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,1,NULL,NULL,NULL,NULL,0),
(15,1,NULL,4,4,NULL,'STD-26-015',NULL,NULL,'05',1,'Tahmid Hasan','তাহমিদ হাসান',NULL,NULL,'Male',NULL,NULL,'B+','Islam','Bangladeshi',NULL,NULL,'01712000015',NULL,'Mirpur, Dhaka','Mirpur, Dhaka',NULL,NULL,NULL,NULL,NULL,'Hasan Ali',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Hasina Akter',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Father','Hasan Ali',NULL,'01712000015',NULL,NULL,NULL,NULL,NULL,NULL,'Active',NULL,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,1,NULL,NULL,NULL,NULL,0),
(16,1,NULL,13,3,NULL,'STD-26-016',NULL,NULL,'01',1,'Shadman Sakib','শাদমান সাকিব',NULL,NULL,'Male',NULL,NULL,'B+','Islam','Bangladeshi',NULL,NULL,'01712000016',NULL,'Mirpur, Dhaka','Mirpur, Dhaka',NULL,NULL,NULL,NULL,NULL,'Nazmul Huda',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Dilruba Begum',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Father','Nazmul Huda',NULL,'01712000016',NULL,NULL,NULL,NULL,NULL,NULL,'Active',NULL,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,1,NULL,NULL,NULL,NULL,0),
(17,1,NULL,13,3,NULL,'STD-26-017',NULL,NULL,'02',1,'Fatima Binte Rafiq','ফাতিমা বিনতে রফিক',NULL,NULL,'Female',NULL,NULL,'B+','Islam','Bangladeshi',NULL,NULL,'01712000017',NULL,'Mirpur, Dhaka','Mirpur, Dhaka',NULL,NULL,NULL,NULL,NULL,'Md. Rafiqul Islam',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Shahana Parveen',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Father','Md. Rafiqul Islam',NULL,'01712000017',NULL,NULL,NULL,NULL,NULL,NULL,'Active',NULL,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,1,NULL,NULL,NULL,NULL,0),
(18,1,NULL,13,3,NULL,'STD-26-018',NULL,NULL,'03',1,'Ahsan Habib','আহসান হাবিব',NULL,NULL,'Male',NULL,NULL,'B+','Islam','Bangladeshi',NULL,NULL,'01712000018',NULL,'Mirpur, Dhaka','Mirpur, Dhaka',NULL,NULL,NULL,NULL,NULL,'Habibur Rahman',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Zobaida Khatun',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Father','Habibur Rahman',NULL,'01712000018',NULL,NULL,NULL,NULL,NULL,NULL,'Active',NULL,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,1,NULL,NULL,NULL,NULL,0),
(19,1,NULL,13,3,NULL,'STD-26-019',NULL,NULL,'04',1,'Sumaiya Islam','সুমাইয়া ইসলাম',NULL,NULL,'Female',NULL,NULL,'B+','Islam','Bangladeshi',NULL,NULL,'01712000019',NULL,'Mirpur, Dhaka','Mirpur, Dhaka',NULL,NULL,NULL,NULL,NULL,'Sirajul Islam',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Morium Begum',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Father','Sirajul Islam',NULL,'01712000019',NULL,NULL,NULL,NULL,NULL,NULL,'Active',NULL,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,1,NULL,NULL,NULL,NULL,0),
(20,1,NULL,13,3,NULL,'STD-26-020',NULL,NULL,'05',1,'Abdullah Al Mamun','আব্দুল্লাহ আল মামুন',NULL,NULL,'Male',NULL,NULL,'B+','Islam','Bangladeshi',NULL,NULL,'01712000020',NULL,'Mirpur, Dhaka','Mirpur, Dhaka',NULL,NULL,NULL,NULL,NULL,'Al Amin',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Parul Akter',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Father','Al Amin',NULL,'01712000020',NULL,NULL,NULL,NULL,NULL,NULL,'Active',NULL,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,1,NULL,NULL,NULL,NULL,0),
(21,1,NULL,10,1,NULL,'STD-26-021',NULL,NULL,'01',1,'Ruhul Amin','রুহুল আমিন',NULL,NULL,'Male',NULL,NULL,'B+','Islam','Bangladeshi',NULL,NULL,'01712000021',NULL,'Mirpur, Dhaka','Mirpur, Dhaka',NULL,NULL,NULL,NULL,NULL,'Mokbul Hossain',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Sabina Yasmin',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Father','Mokbul Hossain',NULL,'01712000021',NULL,NULL,NULL,NULL,NULL,NULL,'Active',NULL,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,1,NULL,NULL,NULL,NULL,0),
(22,1,NULL,10,1,NULL,'STD-26-022',NULL,NULL,'02',1,'Sabrina Mostafa','সাবরিনা মোস্তফা',NULL,NULL,'Female',NULL,NULL,'B+','Islam','Bangladeshi',NULL,NULL,'01712000022',NULL,'Mirpur, Dhaka','Mirpur, Dhaka',NULL,NULL,NULL,NULL,NULL,'Golam Mostafa',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Mamtaz Begum',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Father','Golam Mostafa',NULL,'01712000022',NULL,NULL,NULL,NULL,NULL,NULL,'Active',NULL,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,1,NULL,NULL,NULL,NULL,0),
(23,1,NULL,10,1,NULL,'STD-26-023',NULL,NULL,'03',1,'Mehedi Hasan','মেহেদী হাসান',NULL,NULL,'Male',NULL,NULL,'B+','Islam','Bangladeshi',NULL,NULL,'01712000023',NULL,'Mirpur, Dhaka','Mirpur, Dhaka',NULL,NULL,NULL,NULL,NULL,'Ziaul Haque',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Taslima Akter',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Father','Ziaul Haque',NULL,'01712000023',NULL,NULL,NULL,NULL,NULL,NULL,'Active',NULL,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,1,NULL,NULL,NULL,NULL,0),
(24,1,NULL,10,1,NULL,'STD-26-024',NULL,NULL,'04',1,'Anika Bushra','আনিকা বুশরা',NULL,NULL,'Female',NULL,NULL,'B+','Islam','Bangladeshi',NULL,NULL,'01712000024',NULL,'Mirpur, Dhaka','Mirpur, Dhaka',NULL,NULL,NULL,NULL,NULL,'Bazlur Rashid',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Shahida Akter',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Father','Bazlur Rashid',NULL,'01712000024',NULL,NULL,NULL,NULL,NULL,NULL,'Active',NULL,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,1,NULL,NULL,NULL,NULL,0),
(25,1,NULL,10,1,NULL,'STD-26-025',NULL,NULL,'05',1,'Mushfiqur Rahman','মুশফিকুর রহমান',NULL,NULL,'Male',NULL,NULL,'B+','Islam','Bangladeshi',NULL,NULL,'01712000025',NULL,'Mirpur, Dhaka','Mirpur, Dhaka',NULL,NULL,NULL,NULL,NULL,'Lutfur Rahman',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Suraiya Begum',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Father','Lutfur Rahman',NULL,'01712000025',NULL,NULL,NULL,NULL,NULL,NULL,'Active',NULL,'2026-10-02 17:53:29','2026-10-02 18:23:40',1,1,NULL,NULL,NULL,NULL,0);
/*!40000 ALTER TABLE `students` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `supplier_ledger`
--

DROP TABLE IF EXISTS `supplier_ledger`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_ledger` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_name` varchar(150) NOT NULL,
  `item_name` varchar(150) NOT NULL,
  `qty` int(11) NOT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `due_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `date` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `supplier_ledger`
--

LOCK TABLES `supplier_ledger` WRITE;
/*!40000 ALTER TABLE `supplier_ledger` DISABLE KEYS */;
/*!40000 ALTER TABLE `supplier_ledger` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_users`
--

DROP TABLE IF EXISTS `system_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `branch_id` int(11) DEFAULT NULL,
  `user_type` varchar(30) NOT NULL,
  `username` varchar(100) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `permissions_restricted` tinyint(1) NOT NULL DEFAULT 0,
  `expires_at` datetime DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_users`
--

LOCK TABLES `system_users` WRITE;
/*!40000 ALTER TABLE `system_users` DISABLE KEYS */;
INSERT INTO `system_users` VALUES
(1,1,'Admin','demo_admin','admin@schooledo.com','$2y$10$DEvHX3epo0WVY.i63PZZ1uXrf1Pj7iD5BMkrIhUleEpaDPAkGn1X2','Active',0,NULL,NULL,'2026-10-02 17:53:29','2026-10-02 17:53:29'),
(2,1,'Teacher','demo_teacher','teacher@schooledo.com','$2y$10$RLcqmOYS0LHQ5lAR0VaToOIRiRiSicspiLDXjVQPuOTn5MqgLHUvO','Active',0,NULL,NULL,'2026-10-02 17:53:29','2026-10-02 17:53:29'),
(3,1,'Student','demo_student','student@schooledo.com','$2y$10$avoskTHSr0uV9q4DCmg35unMQg9Pwohb8LLPiw.C35hY4sSGfY0EO','Active',0,NULL,NULL,'2026-10-02 17:53:29','2026-10-02 17:53:29'),
(4,1,'Accountant','demo_accountant','accountant@schooledo.com','$2y$10$c2wRlKw0YzlpTWgRU37obOfLTkwLfP0iyjjhkFLghu95k8EIlnTl6','Active',0,NULL,NULL,'2026-10-02 17:53:29','2026-10-02 17:53:29');
/*!40000 ALTER TABLE `system_users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `teacher_subject_assignments`
--

DROP TABLE IF EXISTS `teacher_subject_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `teacher_subject_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_assign` (`employee_id`,`class_id`,`subject_id`),
  KEY `idx_employee` (`employee_id`),
  KEY `idx_class_subject` (`class_id`,`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `teacher_subject_assignments`
--

LOCK TABLES `teacher_subject_assignments` WRITE;
/*!40000 ALTER TABLE `teacher_subject_assignments` DISABLE KEYS */;
/*!40000 ALTER TABLE `teacher_subject_assignments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transport_events`
--

DROP TABLE IF EXISTS `transport_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transport_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vehicle_id` int(11) NOT NULL,
  `event_type` enum('Arrived School','Departed School','Stop Reached','Offline Alert') NOT NULL,
  `stop_id` int(11) DEFAULT NULL,
  `event_time` datetime NOT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `sms_sent` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_vehicle_event_time` (`vehicle_id`,`event_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transport_events`
--

LOCK TABLES `transport_events` WRITE;
/*!40000 ALTER TABLE `transport_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `transport_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transport_fee_invoices`
--

DROP TABLE IF EXISTS `transport_fee_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transport_fee_invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `route_id` int(11) NOT NULL,
  `month_year` varchar(7) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('Unpaid','Partial','Paid') NOT NULL DEFAULT 'Unpaid',
  `payment_date` date DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `trx_id` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_student_month` (`student_id`,`month_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transport_fee_invoices`
--

LOCK TABLES `transport_fee_invoices` WRITE;
/*!40000 ALTER TABLE `transport_fee_invoices` DISABLE KEYS */;
/*!40000 ALTER TABLE `transport_fee_invoices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transport_gps_logs`
--

DROP TABLE IF EXISTS `transport_gps_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transport_gps_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `vehicle_id` int(11) NOT NULL,
  `lat` decimal(10,7) NOT NULL,
  `lng` decimal(10,7) NOT NULL,
  `speed_kmh` decimal(6,2) NOT NULL DEFAULT 0.00,
  `course_deg` decimal(6,2) NOT NULL DEFAULT 0.00,
  `ignition` tinyint(1) NOT NULL DEFAULT 0,
  `device_time` datetime NOT NULL,
  `received_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_vehicle_time` (`vehicle_id`,`device_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transport_gps_logs`
--

LOCK TABLES `transport_gps_logs` WRITE;
/*!40000 ALTER TABLE `transport_gps_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `transport_gps_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transport_gps_settings`
--

DROP TABLE IF EXISTS `transport_gps_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transport_gps_settings` (
  `id` int(11) NOT NULL DEFAULT 1,
  `provider_name` varchar(50) NOT NULL DEFAULT 'traccar',
  `base_url` varchar(255) DEFAULT NULL,
  `username` varchar(150) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `poll_interval_sec` int(11) NOT NULL DEFAULT 20,
  `school_lat` decimal(10,7) DEFAULT NULL,
  `school_lng` decimal(10,7) DEFAULT NULL,
  `geofence_radius_m` int(11) NOT NULL DEFAULT 200,
  `connection_status` enum('Not Tested','Connected','Failed') NOT NULL DEFAULT 'Not Tested',
  `last_tested_at` datetime DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transport_gps_settings`
--

LOCK TABLES `transport_gps_settings` WRITE;
/*!40000 ALTER TABLE `transport_gps_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `transport_gps_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transport_routes`
--

DROP TABLE IF EXISTS `transport_routes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transport_routes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `route_no` varchar(20) DEFAULT NULL,
  `route_name` varchar(150) NOT NULL,
  `route_category` enum('Student','Staff','Mixed') NOT NULL DEFAULT 'Student',
  `vehicle_id` int(11) DEFAULT NULL,
  `monthly_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transport_routes`
--

LOCK TABLES `transport_routes` WRITE;
/*!40000 ALTER TABLE `transport_routes` DISABLE KEYS */;
/*!40000 ALTER TABLE `transport_routes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transport_staff_assign`
--

DROP TABLE IF EXISTS `transport_staff_assign`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transport_staff_assign` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `route_id` int(11) NOT NULL,
  `stop_id` int(11) NOT NULL,
  `fee_override` decimal(10,2) DEFAULT NULL,
  `assigned_date` date NOT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `remarks` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_employee` (`employee_id`),
  KEY `idx_route2` (`route_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transport_staff_assign`
--

LOCK TABLES `transport_staff_assign` WRITE;
/*!40000 ALTER TABLE `transport_staff_assign` DISABLE KEYS */;
/*!40000 ALTER TABLE `transport_staff_assign` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transport_stops`
--

DROP TABLE IF EXISTS `transport_stops`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transport_stops` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `route_id` int(11) NOT NULL,
  `stop_name` varchar(150) NOT NULL,
  `stop_order` int(11) NOT NULL DEFAULT 0,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `radius_m` int(11) NOT NULL DEFAULT 100,
  `pickup_time` time DEFAULT NULL,
  `drop_time` time DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_route_order` (`route_id`,`stop_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transport_stops`
--

LOCK TABLES `transport_stops` WRITE;
/*!40000 ALTER TABLE `transport_stops` DISABLE KEYS */;
/*!40000 ALTER TABLE `transport_stops` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transport_student_assign`
--

DROP TABLE IF EXISTS `transport_student_assign`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transport_student_assign` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `route_id` int(11) NOT NULL,
  `stop_id` int(11) NOT NULL,
  `fee_override` decimal(10,2) DEFAULT NULL,
  `tracking_token` varchar(64) DEFAULT NULL,
  `assigned_date` date NOT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `remarks` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token` (`tracking_token`),
  KEY `idx_student` (`student_id`),
  KEY `idx_route` (`route_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transport_student_assign`
--

LOCK TABLES `transport_student_assign` WRITE;
/*!40000 ALTER TABLE `transport_student_assign` DISABLE KEYS */;
/*!40000 ALTER TABLE `transport_student_assign` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transport_vehicle_live`
--

DROP TABLE IF EXISTS `transport_vehicle_live`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transport_vehicle_live` (
  `vehicle_id` int(11) NOT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `speed_kmh` decimal(6,2) NOT NULL DEFAULT 0.00,
  `course_deg` decimal(6,2) NOT NULL DEFAULT 0.00,
  `ignition` tinyint(1) NOT NULL DEFAULT 0,
  `device_time` datetime DEFAULT NULL,
  `connection_status` enum('Online','Offline','Unknown') NOT NULL DEFAULT 'Unknown',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`vehicle_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transport_vehicle_live`
--

LOCK TABLES `transport_vehicle_live` WRITE;
/*!40000 ALTER TABLE `transport_vehicle_live` DISABLE KEYS */;
/*!40000 ALTER TABLE `transport_vehicle_live` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transport_vehicles`
--

DROP TABLE IF EXISTS `transport_vehicles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transport_vehicles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vehicle_no` varchar(50) NOT NULL,
  `bus_no` varchar(20) DEFAULT NULL,
  `vehicle_name` varchar(100) DEFAULT NULL,
  `vehicle_type` enum('Bus','Microbus','Car','Van','Other') NOT NULL DEFAULT 'Bus',
  `capacity` int(11) NOT NULL DEFAULT 0,
  `driver_id` int(11) DEFAULT NULL,
  `helper_id` int(11) DEFAULT NULL,
  `gps_device_identifier` varchar(150) DEFAULT NULL,
  `gps_device_ref_id` varchar(100) DEFAULT NULL,
  `fitness_expiry` date DEFAULT NULL,
  `insurance_expiry` date DEFAULT NULL,
  `tax_token_expiry` date DEFAULT NULL,
  `status` enum('Active','Inactive','Maintenance') NOT NULL DEFAULT 'Active',
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_vehicle_no` (`vehicle_no`),
  KEY `idx_gps_device` (`gps_device_identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transport_vehicles`
--

LOCK TABLES `transport_vehicles` WRITE;
/*!40000 ALTER TABLE `transport_vehicles` DISABLE KEYS */;
/*!40000 ALTER TABLE `transport_vehicles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_menu_permissions`
--

DROP TABLE IF EXISTS `user_menu_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_menu_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `system_user_id` int(11) NOT NULL,
  `page_key` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_page` (`system_user_id`,`page_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_menu_permissions`
--

LOCK TABLES `user_menu_permissions` WRITE;
/*!40000 ALTER TABLE `user_menu_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_menu_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `website_albums`
--

DROP TABLE IF EXISTS `website_albums`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_albums` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `cover_image` varchar(255) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_albums`
--

LOCK TABLES `website_albums` WRITE;
/*!40000 ALTER TABLE `website_albums` DISABLE KEYS */;
/*!40000 ALTER TABLE `website_albums` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `website_board_results`
--

DROP TABLE IF EXISTS `website_board_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_board_results` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `exam_year` varchar(20) NOT NULL,
  `result_link` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_board_results`
--

LOCK TABLES `website_board_results` WRITE;
/*!40000 ALTER TABLE `website_board_results` DISABLE KEYS */;
/*!40000 ALTER TABLE `website_board_results` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `website_breaking_news`
--

DROP TABLE IF EXISTS `website_breaking_news`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_breaking_news` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `news_text` varchar(500) NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_breaking_news`
--

LOCK TABLES `website_breaking_news` WRITE;
/*!40000 ALTER TABLE `website_breaking_news` DISABLE KEYS */;
/*!40000 ALTER TABLE `website_breaking_news` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `website_careers`
--

DROP TABLE IF EXISTS `website_careers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_careers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `position_title` varchar(150) NOT NULL,
  `department` varchar(150) DEFAULT NULL,
  `deadline_date` date DEFAULT NULL,
  `description` text DEFAULT NULL,
  `apply_link` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_careers`
--

LOCK TABLES `website_careers` WRITE;
/*!40000 ALTER TABLE `website_careers` DISABLE KEYS */;
/*!40000 ALTER TABLE `website_careers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `website_contents`
--

DROP TABLE IF EXISTS `website_contents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_contents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `content` text NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `menu_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_contents`
--

LOCK TABLES `website_contents` WRITE;
/*!40000 ALTER TABLE `website_contents` DISABLE KEYS */;
/*!40000 ALTER TABLE `website_contents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `website_events`
--

DROP TABLE IF EXISTS `website_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `event_date` date NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_events`
--

LOCK TABLES `website_events` WRITE;
/*!40000 ALTER TABLE `website_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `website_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `website_facilities`
--

DROP TABLE IF EXISTS `website_facilities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_facilities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `title_en` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `description_en` text DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_facilities`
--

LOCK TABLES `website_facilities` WRITE;
/*!40000 ALTER TABLE `website_facilities` DISABLE KEYS */;
/*!40000 ALTER TABLE `website_facilities` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `website_menu_items`
--

DROP TABLE IF EXISTS `website_menu_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_menu_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `link` varchar(255) NOT NULL DEFAULT '',
  `open_new_tab` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `is_core` tinyint(1) NOT NULL DEFAULT 0,
  `parent_id` int(11) NOT NULL DEFAULT 0,
  `title_key` varchar(50) DEFAULT NULL,
  `icon` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_menu_items`
--

LOCK TABLES `website_menu_items` WRITE;
/*!40000 ALTER TABLE `website_menu_items` DISABLE KEYS */;
INSERT INTO `website_menu_items` VALUES
(12,'হোম','index.php',0,10,1,'2026-10-02 18:18:00',1,0,'nav_home','fa-house'),
(13,'আমাদের সম্পর্কে','about.php',0,20,1,'2026-10-02 18:18:00',1,0,'nav_about','fa-circle-info'),
(14,'একাডেমিক','#academic',0,30,1,'2026-10-02 18:18:00',1,0,'nav_academic','fa-book-open'),
(15,'শিক্ষক ও স্টাফ','teachers.php',0,40,1,'2026-10-02 18:18:00',1,0,'nav_teachers','fa-chalkboard-user'),
(16,'নোটিশ','notices.php',0,50,1,'2026-10-02 18:18:00',1,0,'nav_notice','fa-bullhorn'),
(17,'ফলাফল','#result',0,60,1,'2026-10-02 18:18:00',1,0,'nav_result','fa-square-poll-vertical'),
(18,'ভিডিও ক্লাস','#videos',0,70,1,'2026-10-02 18:18:00',1,0,'nav_video','fa-circle-play'),
(19,'নিউজ','#news',0,80,1,'2026-10-02 18:18:00',1,0,'nav_news','fa-newspaper'),
(20,'ক্যারিয়ার','#career',0,90,1,'2026-10-02 18:18:00',1,0,'nav_career','fa-briefcase'),
(21,'গ্যালারি','gallery.php',0,100,1,'2026-10-02 18:18:00',1,0,'nav_gallery','fa-images'),
(22,'যোগাযোগ','#contact',0,110,1,'2026-10-02 18:18:00',1,0,'nav_contact','fa-envelope');
/*!40000 ALTER TABLE `website_menu_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `website_menu_meta`
--

DROP TABLE IF EXISTS `website_menu_meta`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_menu_meta` (
  `k` varchar(50) NOT NULL,
  `v` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_menu_meta`
--

LOCK TABLES `website_menu_meta` WRITE;
/*!40000 ALTER TABLE `website_menu_meta` DISABLE KEYS */;
INSERT INTO `website_menu_meta` VALUES
('core_seed_v2','1');
/*!40000 ALTER TABLE `website_menu_meta` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `website_news`
--

DROP TABLE IF EXISTS `website_news`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_news` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `short_desc` varchar(500) DEFAULT NULL,
  `full_desc` text DEFAULT NULL,
  `news_date` date NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_news`
--

LOCK TABLES `website_news` WRITE;
/*!40000 ALTER TABLE `website_news` DISABLE KEYS */;
/*!40000 ALTER TABLE `website_news` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `website_social_media`
--

DROP TABLE IF EXISTS `website_social_media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_social_media` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `platform` varchar(50) NOT NULL,
  `url` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_social_media`
--

LOCK TABLES `website_social_media` WRITE;
/*!40000 ALTER TABLE `website_social_media` DISABLE KEYS */;
/*!40000 ALTER TABLE `website_social_media` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `website_useful_links`
--

DROP TABLE IF EXISTS `website_useful_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_useful_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `url` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_useful_links`
--

LOCK TABLES `website_useful_links` WRITE;
/*!40000 ALTER TABLE `website_useful_links` DISABLE KEYS */;
/*!40000 ALTER TABLE `website_useful_links` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `website_why_choose`
--

DROP TABLE IF EXISTS `website_why_choose`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_why_choose` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `image_path` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_why_choose`
--

LOCK TABLES `website_why_choose` WRITE;
/*!40000 ALTER TABLE `website_why_choose` DISABLE KEYS */;
/*!40000 ALTER TABLE `website_why_choose` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `whatsapp_settings`
--

DROP TABLE IF EXISTS `whatsapp_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `whatsapp_settings` (
  `id` int(11) NOT NULL,
  `api_phone_number_id` varchar(50) DEFAULT NULL,
  `api_access_token` text DEFAULT NULL,
  `api_business_account_id` varchar(50) DEFAULT NULL,
  `api_version` varchar(10) NOT NULL DEFAULT 'v20.0',
  `api_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `whatsapp_settings`
--

LOCK TABLES `whatsapp_settings` WRITE;
/*!40000 ALTER TABLE `whatsapp_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `whatsapp_settings` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

-- Dump completed on 2026-10-02 18:23:57
