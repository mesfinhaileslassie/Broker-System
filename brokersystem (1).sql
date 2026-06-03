-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 03, 2026 at 07:01 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `brokersystem`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin_logs`
--

CREATE TABLE `admin_logs` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `target_type` varchar(50) DEFAULT NULL,
  `target_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admin_notifications`
--

CREATE TABLE `admin_notifications` (
  `id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text DEFAULT NULL,
  `listing_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_notifications`
--

INSERT INTO `admin_notifications` (`id`, `type`, `title`, `message`, `listing_id`, `user_id`, `is_read`, `created_at`) VALUES
(1, 'new_listing', 'New Listing Pending Approval', 'User Mesfin Haileslassie posted a new product: PC for 100000 ETB', 5, 5, 0, '2026-05-04 08:41:02'),
(2, 'new_listing', 'New Listing Pending Approval', 'User Mesfin Haileslassie posted a new rental: g+1 couse for 50000 ETB', 6, 5, 0, '2026-05-04 12:56:05'),
(3, 'new_listing', 'New Listing Pending Approval', 'User Mesfin Haileslassie posted a new product: Iphone 16 for 122999.92 ETB', 7, 5, 0, '2026-05-04 17:17:20'),
(4, 'new_listing', 'New Listing Pending Approval', 'User Mesfin Haileslassie posted a new product: Water Bottle for 800 ETB', 8, 5, 0, '2026-05-04 19:34:30'),
(5, 'new_listing', 'New Listing Pending Approval', 'User Buyer one posted a new product: iPhone 15 Pro for 49999.97 ETB', 9, 12, 0, '2026-05-04 19:45:17'),
(6, 'new_listing', 'New Listing Pending Approval', 'User Mesfin Haileslassie posted a new product: Addidas Shoe for 1300.02 ETB', 10, 5, 0, '2026-05-05 14:14:52');

-- --------------------------------------------------------

--
-- Table structure for table `availability_calendar`
--

CREATE TABLE `availability_calendar` (
  `id` int(11) NOT NULL,
  `listing_id` int(11) NOT NULL,
  `booking_date` date NOT NULL,
  `is_available` tinyint(1) DEFAULT 1,
  `reservation_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `balance_adjustments`
--

CREATE TABLE `balance_adjustments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `operation` enum('add','subtract') NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `type` enum('product','job','rental') NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `name`, `slug`, `type`, `parent_id`, `is_active`, `created_at`) VALUES
(1, 'Apartment', 'apartment', 'rental', NULL, 1, '2026-05-05 16:03:32'),
(2, 'Condominium', 'condominium', 'rental', NULL, 1, '2026-05-05 16:03:32'),
(3, 'Villa', 'villa', 'rental', NULL, 1, '2026-05-05 16:03:32'),
(4, 'Townhouse', 'townhouse', 'rental', NULL, 1, '2026-05-05 16:03:32'),
(5, 'Land', 'land', 'rental', NULL, 1, '2026-05-05 16:03:32'),
(6, 'Commercial', 'commercial', 'rental', NULL, 1, '2026-05-05 16:03:32'),
(7, 'Toyota', 'toyota', 'product', NULL, 1, '2026-05-05 16:03:32'),
(8, 'Honda', 'honda', 'product', NULL, 1, '2026-05-05 16:03:32'),
(9, 'Hyundai', 'hyundai', 'product', NULL, 1, '2026-05-05 16:03:32'),
(10, 'Kia', 'kia', 'product', NULL, 1, '2026-05-05 16:03:32'),
(11, 'BMW', 'bmw', 'product', NULL, 1, '2026-05-05 16:03:32'),
(12, 'Mercedes', 'mercedes', 'product', NULL, 1, '2026-05-05 16:03:32'),
(13, 'Volkswagen', 'volkswagen', 'product', NULL, 1, '2026-05-05 16:03:32'),
(14, 'Ford', 'ford', 'product', NULL, 1, '2026-05-05 16:03:32'),
(15, 'Nissan', 'nissan', 'product', NULL, 1, '2026-05-05 16:03:32'),
(16, 'Mitsubishi', 'mitsubishi', 'product', NULL, 1, '2026-05-05 16:03:32'),
(17, 'Suzuki', 'suzuki', 'product', NULL, 1, '2026-05-05 16:03:32'),
(18, 'Geely', 'geely', 'product', NULL, 1, '2026-05-05 16:03:32'),
(19, 'Chery', 'chery', 'product', NULL, 1, '2026-05-05 16:03:32'),
(20, 'Other', 'other_car', 'product', NULL, 1, '2026-05-05 16:03:32'),
(21, 'Technology', 'technology', 'job', NULL, 1, '2026-05-05 16:03:32'),
(22, 'Construction', 'construction', 'job', NULL, 1, '2026-05-05 16:03:32'),
(23, 'Healthcare', 'healthcare', 'job', NULL, 1, '2026-05-05 16:03:32'),
(24, 'Education', 'education', 'job', NULL, 1, '2026-05-05 16:03:32'),
(25, 'Finance', 'finance', 'job', NULL, 1, '2026-05-05 16:03:32'),
(26, 'Marketing', 'marketing', 'job', NULL, 1, '2026-05-05 16:03:32'),
(27, 'Sales', 'sales', 'job', NULL, 1, '2026-05-05 16:03:32'),
(28, 'Administration', 'administration', 'job', NULL, 1, '2026-05-05 16:03:32'),
(29, 'Driver', 'driver', 'job', NULL, 1, '2026-05-05 16:03:32'),
(30, 'Security', 'security', 'job', NULL, 1, '2026-05-05 16:03:32'),
(31, 'Cleaning', 'cleaning', 'job', NULL, 1, '2026-05-05 16:03:32'),
(32, 'Hospitality', 'hospitality', 'job', NULL, 1, '2026-05-05 16:03:32'),
(33, 'Retail', 'retail', 'job', NULL, 1, '2026-05-05 16:03:32'),
(34, 'Manufacturing', 'manufacturing', 'job', NULL, 1, '2026-05-05 16:03:32');

-- --------------------------------------------------------

--
-- Table structure for table `companies`
--

CREATE TABLE `companies` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `business_name` varchar(150) NOT NULL,
  `business_type` varchar(100) DEFAULT NULL,
  `tax_id` varchar(50) DEFAULT NULL,
  `business_license` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `subscription_plan` enum('none','basic','premium','enterprise','monthly','yearly') DEFAULT 'none',
  `subscription_expiry` date DEFAULT NULL,
  `subscription_amount` decimal(10,2) DEFAULT NULL,
  `is_approved` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `subscription_plan_id` int(11) DEFAULT NULL,
  `subscription_start` date DEFAULT NULL,
  `job_posts_used` int(11) DEFAULT 0,
  `job_posts_limit` int(11) DEFAULT 0,
  `approved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `companies`
--

INSERT INTO `companies` (`id`, `user_id`, `business_name`, `business_type`, `tax_id`, `business_license`, `address`, `website`, `subscription_plan`, `subscription_expiry`, `subscription_amount`, `is_approved`, `created_at`, `updated_at`, `subscription_plan_id`, `subscription_start`, `job_posts_used`, `job_posts_limit`, `approved_at`) VALUES
(1, 3, 'Demo Business PLC', NULL, 'TAX123456', NULL, NULL, NULL, 'basic', '0000-00-00', NULL, 1, '2026-05-04 06:24:52', '2026-05-06 18:47:02', NULL, NULL, 0, 20, NULL),
(2, 15, 'TechEthiopia', 'Technology', '28931121983', NULL, 'Ethiopian', NULL, 'none', NULL, NULL, 0, '2026-05-06 18:51:04', '2026-05-06 18:51:04', NULL, NULL, 0, 0, NULL),
(3, 16, 'TechEthiopia', 'Technology', '12345678911', NULL, 'Ethiopian', NULL, 'none', NULL, NULL, 0, '2026-05-06 19:23:20', '2026-05-06 19:23:20', NULL, NULL, 0, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `conversations`
--

CREATE TABLE `conversations` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `broker_id` int(11) NOT NULL,
  `last_message` text DEFAULT NULL,
  `last_message_time` timestamp NULL DEFAULT NULL,
  `user_unread_count` int(11) DEFAULT 0,
  `broker_unread_count` int(11) DEFAULT 0,
  `status` enum('active','archived','blocked') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `conversations`
--

INSERT INTO `conversations` (`id`, `user_id`, `broker_id`, `last_message`, `last_message_time`, `user_unread_count`, `broker_unread_count`, `status`, `created_at`, `updated_at`) VALUES
(1, 5, 1, NULL, NULL, 0, 0, 'active', '2026-05-05 12:11:47', '2026-05-10 07:29:11'),
(2, 1, 1, NULL, NULL, 0, 0, 'active', '2026-05-06 18:54:28', '2026-05-10 07:28:34');

-- --------------------------------------------------------

--
-- Table structure for table `conversation_typing`
--

CREATE TABLE `conversation_typing` (
  `conversation_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `typing_until` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `delivery_proofs`
--

CREATE TABLE `delivery_proofs` (
  `id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `proof_text` text DEFAULT NULL,
  `uploaded_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `disputes`
--

CREATE TABLE `disputes` (
  `id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `raised_by` int(11) NOT NULL,
  `reason` text NOT NULL,
  `evidence` text DEFAULT NULL,
  `status` enum('open','under_review','resolved','rejected') DEFAULT 'open',
  `admin_decision` text DEFAULT NULL,
  `decision_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `resolved_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `disputes`
--

INSERT INTO `disputes` (`id`, `transaction_id`, `raised_by`, `reason`, `evidence`, `status`, `admin_decision`, `decision_notes`, `created_at`, `resolved_at`) VALUES
(1, 3, 1, 'I did not agree', NULL, 'open', NULL, NULL, '2026-05-08 12:32:16', NULL),
(2, 79, 20, '0i2Q9HBUF', NULL, 'open', NULL, NULL, '2026-06-02 05:06:19', NULL),
(3, 81, 20, 'IOH3-jE\'NTGKJNBOI34KEWMLPC\'[KM Z.K/NfD L BVKF J; [A\'0PO;', NULL, 'open', NULL, NULL, '2026-06-02 05:08:13', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `escrow_accounts`
--

CREATE TABLE `escrow_accounts` (
  `id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'deposit',
  `status` varchar(50) NOT NULL DEFAULT 'held',
  `reference` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `released_at` datetime DEFAULT NULL,
  `release_trigger` enum('viewing','move_out','completion') DEFAULT 'completion'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `escrow_accounts`
--

INSERT INTO `escrow_accounts` (`id`, `transaction_id`, `user_id`, `amount`, `type`, `status`, `reference`, `notes`, `created_at`, `released_at`, `release_trigger`) VALUES
(1, 1, 7, 500.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(2, 5, 5, 47000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(3, 6, 5, 20000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(4, 7, 9, 54000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(5, 8, 6, 450.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(6, 8, 9, 450.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(7, 9, 5, 15000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(8, 9, 9, 15000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(9, 10, 7, 55349.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(10, 11, 7, 54000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(11, 12, 7, 29997.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(12, 16, 7, 9999.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(13, 16, 11, 0.80, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(14, 17, 5, 9999.80, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(15, 20, 7, 30.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(16, 21, 7, 1332.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(17, 22, 7, 720.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(18, 28, 7, 10800.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(19, 29, 7, 1014.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(20, 30, 7, 16200.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(21, 31, 5, 21600.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(22, 31, 7, 21600.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(23, 32, 5, 16200.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(24, 34, 7, 21600.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(25, 36, 7, 1080000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(26, 37, 7, 540000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(27, 38, 7, 600000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(28, 39, 7, 800000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(29, 40, 7, 800000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(30, 41, 7, 4099144.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(31, 42, 17, 540000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(32, 43, 7, 600000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(33, 44, 7, 240000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(34, 45, 7, 3780000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(35, 46, 7, 1080000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(36, 47, 7, 720000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(37, 48, 7, 3509998.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(38, 49, 7, 3780000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(39, 50, 7, 460000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(40, 51, 7, 2460000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(41, 52, 7, 390000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(42, 53, 7, 396000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(43, 54, 7, 195000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(44, 55, 7, 400000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(45, 56, 7, 3509998.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(46, 58, 7, 230000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(47, 59, 7, 400000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(48, 60, 7, 300000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 10:59:54', NULL, 'completion'),
(64, 62, 7, 1890000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 11:27:26', NULL, 'completion'),
(65, 63, 7, 300000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 11:43:30', NULL, 'completion'),
(66, 64, 7, 360000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-05-10 11:54:18', NULL, 'completion'),
(67, 67, 7, 1890000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-06-01 10:08:23', NULL, 'completion'),
(68, 68, 7, 396000.00, 'buyer_deposit', 'released', NULL, NULL, '2026-06-01 10:44:05', '2026-06-01 14:21:05', 'completion'),
(69, 69, 7, 195000.00, 'buyer_deposit', 'released', NULL, NULL, '2026-06-01 11:31:37', '2026-06-01 14:33:04', 'completion'),
(70, 70, 7, 540000.00, 'buyer_deposit', 'released', NULL, NULL, '2026-06-01 11:35:30', '2026-06-01 14:37:22', 'completion'),
(71, 71, 7, 300000.00, 'buyer_deposit', 'released', NULL, NULL, '2026-06-01 11:51:37', '2026-06-01 14:53:20', 'completion'),
(72, 72, 7, 230000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-06-01 12:08:16', NULL, 'completion'),
(73, 75, 19, 300000.00, 'buyer_deposit', 'released', NULL, NULL, '2026-06-01 16:43:42', '2026-06-01 19:54:39', 'completion'),
(74, 76, 19, 360000.00, 'buyer_deposit', 'released', NULL, NULL, '2026-06-01 16:44:56', '2026-06-01 19:53:08', 'completion'),
(75, 75, 19, 800000.00, 'remaining_payment', 'released', NULL, NULL, '2026-06-01 16:54:17', '2026-06-01 19:54:39', 'completion'),
(76, 77, 20, 195000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-06-01 17:06:30', NULL, 'completion'),
(77, 79, 20, 360000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-06-01 17:21:24', NULL, 'completion'),
(78, 80, 19, 408000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-06-01 17:30:11', NULL, 'completion'),
(79, 81, 20, 300000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-06-01 17:32:14', NULL, 'completion'),
(80, 82, 20, 230000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-06-01 17:33:18', NULL, 'completion'),
(82, 89, 7, 360000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-06-02 17:14:28', NULL, 'completion'),
(83, 90, 7, 408000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-06-02 17:19:10', NULL, 'completion'),
(84, 91, 7, 1890000.00, 'buyer_deposit', 'released', NULL, NULL, '2026-06-02 17:43:27', '2026-06-02 21:06:32', 'completion'),
(85, 91, 7, 2310000.00, 'buyer_deposit', 'held', NULL, NULL, '2026-06-02 18:32:46', NULL, 'completion'),
(86, 97, 5, 5400.00, 'buyer_deposit', 'held', NULL, NULL, '2026-06-02 20:14:23', NULL, 'completion');

-- --------------------------------------------------------

--
-- Table structure for table `escrow_release_history`
--

CREATE TABLE `escrow_release_history` (
  `id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `released_by` int(11) DEFAULT NULL,
  `released_by_type` enum('system','buyer','seller','admin') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `release_type` varchar(50) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `escrow_release_history`
--

INSERT INTO `escrow_release_history` (`id`, `transaction_id`, `released_by`, `released_by_type`, `amount`, `release_type`, `notes`, `created_at`) VALUES
(1, 68, 19, 'buyer', 1164000.00, '', 'I have received\r\n', '2026-06-01 14:21:05'),
(2, 69, 19, 'buyer', 1235000.00, '', 'suushHULSVYAJIB', '2026-06-01 14:33:04'),
(3, 70, 19, 'buyer', 1020000.00, '', '', '2026-06-01 14:37:22'),
(4, 71, 19, 'buyer', 2850000.00, '', 'kdjkjdja', '2026-06-01 14:53:20'),
(5, 76, 19, 'buyer', 3420000.00, '', '', '2026-06-01 19:53:08'),
(6, 75, 19, 'buyer', 900000.00, '', '', '2026-06-01 19:54:39'),
(7, 78, 20, 'buyer', 3570000.00, '', 'Auto-release after remaining balance paid.', '2026-06-01 20:13:18'),
(8, 83, 20, 'buyer', 1020000.00, '', 'Auto-release after remaining balance paid.', '2026-06-01 20:44:10'),
(9, 84, 20, 'buyer', 1164000.00, '', 'Auto-release after remaining balance paid.', '2026-06-02 08:00:05'),
(10, 85, 20, 'buyer', 1800000.00, '', 'Auto-release after remaining balance paid.', '2026-06-02 10:03:39'),
(11, 91, 21, '', 3570000.00, '', 'Both parties confirmed delivery', '2026-06-02 21:06:32');

-- --------------------------------------------------------

--
-- Table structure for table `escrow_release_queue`
--

CREATE TABLE `escrow_release_queue` (
  `id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `scheduled_release_date` datetime NOT NULL,
  `status` enum('pending','processed','cancelled') DEFAULT 'pending',
  `created_at` datetime DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `escrow_release_queue`
--

INSERT INTO `escrow_release_queue` (`id`, `transaction_id`, `scheduled_release_date`, `status`, `created_at`, `processed_at`) VALUES
(1, 69, '2026-06-06 13:31:37', 'cancelled', '2026-06-01 14:31:37', NULL),
(2, 70, '2026-06-06 13:35:30', 'cancelled', '2026-06-01 14:35:30', NULL),
(3, 71, '2026-06-06 13:51:37', 'cancelled', '2026-06-01 14:51:37', NULL),
(4, 72, '2026-06-06 14:08:16', 'pending', '2026-06-01 15:08:16', NULL),
(5, 75, '2026-06-06 18:43:42', 'cancelled', '2026-06-01 19:43:42', NULL),
(6, 76, '2026-06-06 18:44:56', 'cancelled', '2026-06-01 19:44:56', NULL),
(7, 77, '2026-06-06 19:06:30', 'pending', '2026-06-01 20:06:30', NULL),
(8, 79, '2026-06-06 19:21:24', 'pending', '2026-06-01 20:21:24', NULL),
(9, 80, '2026-06-06 19:30:11', 'pending', '2026-06-01 20:30:11', NULL),
(10, 81, '2026-06-06 19:32:14', 'pending', '2026-06-01 20:32:14', NULL),
(11, 82, '2026-06-06 19:33:18', 'pending', '2026-06-01 20:33:18', NULL),
(13, 89, '2026-06-07 19:14:28', 'pending', '2026-06-02 20:14:28', NULL),
(14, 90, '2026-06-07 19:19:11', 'pending', '2026-06-02 20:19:11', NULL),
(15, 91, '2026-06-07 19:43:27', 'cancelled', '2026-06-02 20:43:27', NULL),
(16, 91, '2026-06-07 20:32:46', 'pending', '2026-06-02 21:32:46', NULL),
(17, 97, '2026-06-12 22:14:23', 'pending', '2026-06-02 23:14:23', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `favorites`
--

CREATE TABLE `favorites` (
  `user_id` int(11) NOT NULL,
  `listing_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_applications`
--

CREATE TABLE `job_applications` (
  `id` int(11) NOT NULL,
  `job_id` int(11) NOT NULL,
  `applicant_id` int(11) NOT NULL,
  `employer_id` int(11) NOT NULL,
  `cover_letter` text DEFAULT NULL,
  `expected_salary` decimal(12,2) DEFAULT NULL,
  `status` enum('pending','accepted','rejected','hired') DEFAULT 'pending',
  `hired_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `legal_documents`
--

CREATE TABLE `legal_documents` (
  `id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `document_type` varchar(50) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `legal_documents`
--

INSERT INTO `legal_documents` (`id`, `transaction_id`, `user_id`, `document_type`, `file_path`, `notes`, `status`, `uploaded_at`, `approved_by`, `approved_at`) VALUES
(1, 8, 6, 'property_doc', '../uploads/legal_docs/1777906537_8_6_download1.png', '', 'pending', '2026-05-04 14:55:37', NULL, NULL),
(2, 9, 6, 'property_doc', '../uploads/legal_docs/1777907063_9_6_download1.png', '', 'pending', '2026-05-04 15:04:23', NULL, NULL),
(3, 6, 5, 'property_doc', '../uploads/legal_docs/1777907120_6_5_download1.png', '', 'pending', '2026-05-04 15:05:20', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `listings`
--

CREATE TABLE `listings` (
  `id` int(11) NOT NULL,
  `seller_id` int(11) NOT NULL,
  `type` enum('product','job','rental') NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(12,2) NOT NULL,
  `deposit_percent` int(11) DEFAULT 30,
  `commission_percent` int(11) DEFAULT 15,
  `status` enum('active','sold','cancelled','pending') DEFAULT 'active',
  `availability_status` enum('available','reserved','rented','unavailable') DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `custom_deposit_percent` int(11) DEFAULT NULL,
  `custom_commission_percent` int(11) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `images` text DEFAULT NULL,
  `location` varchar(200) DEFAULT NULL,
  `views` int(11) DEFAULT 0,
  `featured` tinyint(1) DEFAULT 0,
  `cover_image` varchar(255) DEFAULT NULL,
  `gallery_images` text DEFAULT NULL,
  `approval_status` enum('pending','approved','rejected') DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `admin_deposit_percent` int(11) DEFAULT NULL,
  `admin_commission_percent` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `additional_details` text DEFAULT NULL,
  `rental_unit` enum('day','month','year') DEFAULT 'month',
  `is_rented` tinyint(4) DEFAULT 0,
  `rented_at` datetime DEFAULT NULL,
  `rented_by` int(11) DEFAULT NULL,
  `negotiation_id` int(11) DEFAULT NULL,
  `negotiation_status` varchar(50) DEFAULT 'draft',
  `agreed_commission_percent` decimal(5,2) DEFAULT NULL,
  `agreed_deposit_amount` decimal(12,2) DEFAULT NULL,
  `sold_to_user_id` int(11) DEFAULT NULL,
  `sold_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `listings`
--

INSERT INTO `listings` (`id`, `seller_id`, `type`, `title`, `description`, `price`, `deposit_percent`, `commission_percent`, `status`, `availability_status`, `created_at`, `updated_at`, `custom_deposit_percent`, `custom_commission_percent`, `category`, `category_id`, `images`, `location`, `views`, `featured`, `cover_image`, `gallery_images`, `approval_status`, `admin_notes`, `admin_deposit_percent`, `admin_commission_percent`, `approved_at`, `approved_by`, `additional_details`, `rental_unit`, `is_rented`, `rented_at`, `rented_by`, `negotiation_id`, `negotiation_status`, `agreed_commission_percent`, `agreed_deposit_amount`, `sold_to_user_id`, `sold_at`) VALUES
(1, 2, 'product', 'Sample Product', 'This is a demo product for testing', 1000.00, 30, 15, 'active', 'reserved', '2026-05-04 06:24:52', '2026-06-02 17:46:27', NULL, NULL, NULL, NULL, NULL, NULL, 7, 0, NULL, NULL, 'approved', NULL, 30, 15, '2026-05-04 12:48:13', 1, NULL, 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, 6, '2026-06-02 20:46:27'),
(2, 3, 'job', 'Web Developer Needed', 'Looking for an experienced web developer', 5000.00, 30, 15, 'active', 'available', '2026-05-04 06:24:52', '2026-05-04 08:08:19', NULL, NULL, NULL, NULL, NULL, NULL, 2, 0, NULL, NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL, 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, NULL, NULL),
(3, 2, 'rental', 'Apartment for Rent', '2 bedroom apartment in Bole', 15000.00, 30, 15, 'active', 'available', '2026-05-04 06:24:52', '2026-05-04 08:08:29', NULL, NULL, NULL, NULL, NULL, NULL, 2, 0, NULL, NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL, 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, NULL, NULL),
(4, 5, 'product', 'Iphone', 'New product', 120000.00, 30, 15, 'active', 'reserved', '2026-05-04 08:09:35', '2026-06-02 17:46:27', NULL, NULL, NULL, NULL, NULL, 'DB', 13, 0, NULL, NULL, 'approved', NULL, 30, 15, '2026-05-04 08:48:03', 1, NULL, 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, 10, '2026-06-02 20:46:27'),
(5, 5, 'product', 'PC', 'New HP brand', 100000.00, 30, 15, 'active', 'available', '2026-05-04 08:41:02', '2026-05-05 16:40:35', NULL, NULL, NULL, NULL, NULL, 'AA', 4, 0, '1777884062_69f85b9e19d1f_App macbook pro pc.png', '0', 'approved', NULL, 37, 10, '2026-05-04 08:45:25', 1, NULL, 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, NULL, NULL),
(6, 5, 'rental', 'g+1 couse', '3 bedroom , 2baseroom', 50000.00, 30, 15, 'active', 'available', '2026-05-04 12:56:05', '2026-05-08 14:53:33', NULL, NULL, NULL, NULL, NULL, 'AA', 22, 0, '1777899365_69f897653ed4f_download.png', '0', 'approved', NULL, 30, 10, '2026-05-04 12:58:08', 1, NULL, 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, NULL, NULL),
(7, 5, 'product', 'Iphone 16', 'New brand iphone', 122999.92, 30, 15, 'active', 'available', '2026-05-04 17:17:20', '2026-05-05 16:03:32', NULL, NULL, NULL, NULL, NULL, 'DB', 2, 0, '1777915040_69f8d4a03618d_iphone 161.png', '0', 'approved', NULL, 30, 15, '2026-05-04 17:19:55', 1, NULL, 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, NULL, NULL),
(8, 5, 'product', 'Water Bottle', 'New WB', 800.00, 30, 15, 'active', 'reserved', '2026-05-04 19:34:30', '2026-06-02 17:46:27', NULL, NULL, NULL, NULL, NULL, 'Chacha', 9, 0, '1777923270_69f8f4c6808a9_Water bottle.png', NULL, 'approved', NULL, 30, 15, '2026-05-05 16:08:04', NULL, NULL, 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, 5, '2026-06-02 20:46:27'),
(9, 12, 'product', 'iPhone 15 Pro', 'Brand new iphone', 49999.97, 30, 15, 'active', 'reserved', '2026-05-04 19:45:17', '2026-06-02 17:46:27', NULL, NULL, NULL, NULL, NULL, 'AA', 17, 0, '1777923917_69f8f74d48b69_iphone 15.png', NULL, 'approved', NULL, 10, 10, '2026-05-04 19:48:09', NULL, NULL, 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, 5, '2026-06-02 20:46:27'),
(10, 5, 'product', 'Addidas Shoe', 'Made in Ethiopia', 1300.02, 30, 15, 'active', 'reserved', '2026-05-05 14:14:52', '2026-06-02 17:46:27', NULL, NULL, NULL, NULL, NULL, 'AA', 24, 0, '1777990492_69f9fb5c1d856_Adidas Ultraboost 22 Running Shoes.png', '0', 'approved', NULL, 24, 15, '2026-05-05 14:16:58', NULL, NULL, 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, 17, '2026-06-02 20:46:27'),
(11, 5, 'product', 'Plastic bottle', '500ml dega wuha  plastic bottle', 69.97, 30, 15, 'active', 'reserved', '2026-05-05 15:01:35', '2026-06-02 17:46:27', NULL, NULL, NULL, NULL, NULL, 'Debre Birhan', 6, 0, '1777993295_69fa064f413ef_Plastic bottle.jpg', '0', 'approved', NULL, 10, 5, '2026-05-05 15:02:38', NULL, NULL, 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, 5, '2026-06-02 20:46:27'),
(12, 5, 'product', 'Modern Watch2026', 'New chinese watch', 2299.99, 30, 15, 'active', 'reserved', '2026-05-05 15:28:31', '2026-06-02 17:46:27', NULL, NULL, NULL, NULL, NULL, 'Debre Birhan', 14, 0, '1777994911_69fa0c9f75116_Apple Watch Series 9 - GPS + Cellular.png', '0', 'approved', NULL, 20, 9, '2026-05-05 15:30:48', NULL, NULL, 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, 5, '2026-06-02 20:46:27'),
(13, 15, 'job', 'Senier Web developer', 'Web development work', 32001.00, 30, 15, 'active', 'available', '2026-05-06 19:10:36', '2026-05-08 19:05:07', NULL, NULL, NULL, 21, NULL, 'AA', 0, 0, NULL, NULL, 'approved', NULL, 30, 15, '2026-05-06 19:12:29', NULL, '{\"employment_type\":\"Full-time\",\"requirements\":\"CV,PPortfolio\"}', 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, NULL, NULL),
(14, 16, 'job', 'Mobile Application Developer', 'ws{GKPOhjn', 36001.00, 30, 15, 'active', 'available', '2026-05-06 19:39:24', '2026-06-02 19:25:11', NULL, NULL, NULL, 21, NULL, 'AA', 10, 0, NULL, NULL, 'approved', NULL, 5, 15, '2026-05-06 19:40:45', NULL, '{\"employment_type\":\"Full-time\",\"requirements\":\"qw][epkorgtjn\"}', 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, NULL, NULL),
(15, 17, 'rental', 'asqaq', 'wddddddddddddddddddddddddddddddddddd fddddddddddddddddddddddddddddddddddzzzzzzzzzzz', 12000.00, 30, 15, 'active', 'available', '2026-05-08 14:57:42', '2026-05-08 17:47:02', NULL, NULL, NULL, 1, NULL, 'AA', 17, 0, '1778252262_69fdf9e6998f2_bp.PNG', NULL, 'approved', NULL, 30, 15, '2026-05-08 14:58:17', NULL, '{\"bedrooms\":2,\"bathrooms\":1,\"area\":121}', 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, NULL, NULL),
(16, 17, 'rental', 'Car1', 'SV SDLLLLLLLLLLLLLLLLLLLLLLLMKLSMAIOSFMo&#039;v akmv&#039;\r\nL\r\n ej lkamiosfA,P,cmadmdpmq0wejfoj,odsIFJEPQHFNA9PUCBBX', 1200000.00, 30, 15, 'active', 'available', '2026-05-08 18:27:36', '2026-05-10 11:55:59', NULL, NULL, NULL, 11, NULL, 'DB', 1, 0, '', NULL, 'approved', NULL, 5, 5, '2026-05-09 07:35:03', NULL, '{\"bedrooms\":0,\"bathrooms\":0,\"area\":0}', 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, NULL, NULL),
(17, 17, 'product', 'Car1', 'rsbfdbnvmbhcfnxbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbfgaAFez', 1200000.00, 30, 15, 'active', 'available', '2026-05-08 18:36:12', '2026-05-08 19:05:07', NULL, NULL, NULL, 16, NULL, 'AA', 0, 0, '', NULL, 'approved', NULL, 30, 15, '2026-05-08 18:41:00', NULL, '{\"year\":2023,\"mileage\":122,\"fuel_type\":\"Petrol\",\"transmission\":\"Manual\"}', 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, NULL, NULL),
(18, 17, 'product', 'Car1', 'rsbfdbnvmbhcfnxbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbfgaAFez', 1200000.00, 30, 15, 'active', 'available', '2026-05-08 18:36:12', '2026-05-09 07:25:09', NULL, NULL, NULL, 16, NULL, 'AA', 0, 0, '', NULL, 'approved', NULL, 30, 15, '2026-05-09 07:23:45', NULL, '{\"year\":2023,\"mileage\":122,\"fuel_type\":\"Petrol\",\"transmission\":\"Manual\"}', 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, NULL, NULL),
(19, 5, 'product', 'Car12', 'E222222222222222222222222222222222ACSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSS', 1230000.00, 30, 15, 'active', 'available', '2026-05-08 18:39:41', '2026-05-08 19:05:21', NULL, NULL, NULL, 11, NULL, 'AA', 1, 0, '', NULL, 'approved', NULL, 30, 15, '2026-05-08 18:44:19', NULL, '{\"year\":2023,\"mileage\":123,\"fuel_type\":\"Petrol\",\"transmission\":\"Automatic\"}', 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, NULL, NULL),
(20, 5, 'product', 'dsf', 'sdddddddddddddddddddddddddddddddddddddddddddddddddddddddddc', 1200000.00, 30, 15, 'active', 'available', '2026-05-08 18:50:23', '2026-05-08 19:16:50', NULL, NULL, NULL, 15, NULL, 'AA', 2, 0, '', NULL, 'approved', NULL, 30, 15, '2026-05-08 18:51:26', NULL, '{\"year\":2020,\"mileage\":123,\"fuel_type\":\"\",\"transmission\":\"Automatic\"}', 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, NULL, NULL),
(21, 17, 'product', 'Suzuki', 'pfemmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmm', 1200000.00, 30, 15, 'active', 'available', '2026-05-08 19:36:59', '2026-06-02 20:47:36', NULL, NULL, NULL, 17, NULL, 'Debre Birhan', 22, 0, '1778269019_69fe3b5bd156e_lamborgini.png', NULL, 'approved', NULL, 30, 15, '2026-05-08 19:37:31', NULL, '{\"year\":2020,\"mileage\":121,\"fuel_type\":\"Diesel\",\"transmission\":\"Manual\"}', 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, NULL, NULL),
(22, 5, 'product', 'h54wt', 'tqaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaanj', 9109211.00, 30, 15, 'active', 'reserved', '2026-05-08 19:56:07', '2026-06-02 17:46:27', NULL, NULL, NULL, 20, NULL, 'AA', 0, 0, '1778270167_69fe3fd751bd0_iPad Pro 11-inch - M2 Chip.png', NULL, 'approved', NULL, 30, 15, '2026-05-09 07:15:08', NULL, '{\"year\":2020,\"mileage\":2111,\"fuel_type\":\"Diesel\",\"transmission\":\"Automatic\"}', 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, 5, '2026-06-02 20:46:27'),
(23, 5, 'product', 'dddd', 'gklssssssssssssssssssssssssssssssssssssssssssssssssss', 12300000.00, 30, 15, 'active', 'reserved', '2026-05-08 20:25:33', '2026-06-02 17:46:27', NULL, NULL, NULL, 6, NULL, 'AA', 1, 0, '1778271933_69fe46bd20d11_iphone 15.png', NULL, 'approved', NULL, 5, 5, '2026-05-09 07:07:29', NULL, '{\"year\":2020,\"mileage\":121,\"fuel_type\":\"Electric\",\"transmission\":\"Manual\"}', 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, 5, '2026-06-02 20:46:27'),
(24, 17, 'product', 'Modern Nissan', 'amkkkkkkkkkkkkkkkkaea&#039;mmmmmmmELLLLLLLLLOSLralskdn;awkdmKSN;CCQPNSK JQDIEFNAW;OSNDINQLDC ; V', 4000000.00, 30, 15, 'active', 'reserved', '2026-05-08 21:04:02', '2026-06-02 17:46:27', NULL, NULL, NULL, 15, NULL, 'Chacha', 9, 0, '1778274242_69fe4fc25b739_lamborgini.png', NULL, 'approved', NULL, 5, 5, '2026-05-09 07:03:52', NULL, '{\"year\":2020,\"mileage\":12321,\"fuel_type\":\"Petrol\",\"transmission\":\"Manual\"}', 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, 20, '2026-06-02 20:46:27'),
(25, 5, 'product', '2020 toyota', 'I&#039;Pnf fiN;SMk FSIN&#039;woz\r\nXJm Ian&#039;PFJ;aw;pIIJFA;IJRFDOFNFOI;AF;LN ;aN;MAIS;EJMAVVVVUE0', 1200000.00, 30, 15, 'active', 'reserved', '2026-05-09 05:42:53', '2026-06-02 17:46:27', NULL, NULL, NULL, 11, NULL, 'AA', 14, 0, '1778305373_69fec95d4ab44_lamborgini.png', NULL, 'approved', NULL, 30, 15, '2026-05-09 05:52:16', NULL, '{\"year\":2020,\"mileage\":1231,\"fuel_type\":\"Diesel\",\"transmission\":\"Manual\"}', 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, 17, '2026-06-02 20:46:27'),
(26, 5, 'product', 'Newsssssssssssssssssssssssssssss', 'ajfoj&#039;FOIJEJREKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKFIERRRRRRRRRRRRRRHGHGGHGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGHGGGGGGGGGGGGGGGGGGGGG', 1000000.00, 30, 15, 'active', 'reserved', '2026-05-09 06:42:17', '2026-06-02 17:46:27', NULL, NULL, NULL, 14, NULL, 'Debre Birhan', 14, 0, '1778308937_69fed749eeb07_green car.png', NULL, 'approved', NULL, 20, 10, '2026-05-09 06:43:10', NULL, '{\"year\":2024,\"mileage\":11112,\"fuel_type\":\"Diesel\",\"transmission\":\"Automatic\"}', 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, 19, '2026-06-02 20:46:27'),
(27, 5, 'product', 'Modern Electric Car', 'JJJJJJJJJJJJJJJJKCIPNAAAACDPIGDJPekip&#039;daija idaidlm&#039;p  4ti kcwkiodoqnlwfjmMl;mf v', 2000000.00, 30, 15, 'active', 'reserved', '2026-05-09 06:56:36', '2026-06-02 17:46:27', NULL, NULL, NULL, 12, NULL, 'Debre Birhan', 18, 0, '1778309796_69fedaa41273a_non white.png', NULL, 'approved', NULL, 10, 10, '2026-05-09 06:57:20', NULL, '{\"year\":2025,\"mileage\":1200,\"fuel_type\":\"Electric\",\"transmission\":\"Manual\"}', 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, 20, '2026-06-02 20:46:27'),
(28, 5, 'product', 'ABCD', 'amkllvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvtrppp teokqqqqqqqqqqqqqqqtpj', 3000000.00, 30, 15, 'active', 'reserved', '2026-05-09 07:27:14', '2026-06-02 17:46:27', NULL, NULL, NULL, 14, NULL, 'DB', 14, 0, '1778311634_69fee1d25e17c_non white.png', NULL, 'approved', NULL, 5, 5, '2026-05-09 07:27:45', NULL, '{\"year\":2026,\"mileage\":4000,\"fuel_type\":\"Diesel\",\"transmission\":\"Automatic\"}', 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, 20, '2026-06-02 20:46:27'),
(29, 5, 'product', 'MHHHHHHHHMMMMMM', 'kasssssssssssssssssssssssssssssssssssssssssssssddddddddddddddddddddddddddnkknnnnnnnnnnnnnnnnnn', 4200000.00, 30, 15, 'active', 'reserved', '2026-05-09 07:40:56', '2026-06-02 17:46:27', NULL, NULL, NULL, 7, NULL, 'Debre Birhan', 4, 0, '1778312456_69fee50880b48_green car.png', NULL, 'approved', NULL, 30, 15, '2026-05-09 07:41:48', NULL, '{\"year\":2026,\"mileage\":12300,\"fuel_type\":\"Hybrid\",\"transmission\":\"\"}', 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, 19, '2026-06-02 20:46:27'),
(30, 5, 'product', 'aaaaaaa', 'ddddddddddddddddddddddddddddddkkkkkkkkkkkkkkkkkkkkkkkffffffffffffffffffffffffffffffffff', 1200000.00, 30, 15, 'active', 'reserved', '2026-05-09 07:54:56', '2026-06-02 17:46:27', NULL, NULL, NULL, 17, NULL, 'AA', 15, 0, '1778313296_69fee850b997d_green car.png', NULL, 'approved', NULL, 30, 15, '2026-05-09 07:56:18', NULL, '{\"year\":2002,\"mileage\":1221,\"fuel_type\":\"Diesel\",\"transmission\":\"Automatic\"}', 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, 20, '2026-06-02 20:46:27'),
(31, 5, 'product', 'Nissan 2026', 'fjjKJKJDWdoajeopkp fjjKJKJDWdoajeopkpfjjKJKJDWdoajeopkpfjjKJKJDWdoajeopkpfjjKJKJDWdoajeopkp', 3600000.00, 30, 15, 'active', 'reserved', '2026-05-09 08:07:12', '2026-06-02 17:46:27', NULL, NULL, NULL, 15, NULL, 'AA', 27, 0, '1778314032_69feeb301639d_Eclectriccar.png', NULL, 'approved', NULL, 5, 5, '2026-05-09 08:07:59', NULL, '{\"year\":2026,\"mileage\":2000,\"fuel_type\":\"Electric\",\"transmission\":\"Automatic\"}', 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, 21, '2026-06-02 20:46:27'),
(32, 5, 'product', 'NLs', 'ak;N;NFn;JRNJEI ak;N;NFn;JRNJEIak;N;NFn;JRNJEIak;N;NFn;JRNJEIak;N;NFn;JRNJEI', 3899999.99, 30, 15, 'active', 'reserved', '2026-05-09 08:34:06', '2026-06-02 17:46:27', NULL, NULL, NULL, 17, NULL, 'AA', 5, 0, '1778315646_69fef17e7a8e9_Eclectriccar.png', NULL, 'approved', NULL, 30, 15, '2026-05-09 08:34:40', NULL, '{\"year\":2026,\"mileage\":2000,\"fuel_type\":\"Electric\",\"transmission\":\"Manual\"}', 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, 17, '2026-06-02 20:46:27'),
(33, 5, 'product', 'FNL car', 'djnIOIP djnIOIP djnIOIPdjnIOIPdjnIOIPdjnIOIPdjnIOIPdjnIOIPdjnIOIPdjnIOIPdjnIOIPdjnIOIPdjnIOIP djnIOIP djnIOIPdjnIOIPdjnIOIPdjnIOIPdjnIOIPdjnIOIPdjnIOIPdjnIOIPdjnIOIPdjnIOIP', 4200000.00, 30, 15, 'active', 'reserved', '2026-05-09 08:52:23', '2026-06-02 17:46:27', NULL, NULL, NULL, 10, NULL, 'Chacha', 27, 0, '1778316743_69fef5c79b85d_nissan.png', NULL, 'approved', NULL, 30, 15, '2026-05-09 08:52:57', NULL, '{\"year\":2024,\"mileage\":1300,\"fuel_type\":\"Petrol\",\"transmission\":\"Manual\"}', 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, 21, '2026-06-02 20:46:27'),
(34, 5, 'product', 'BMW', 'wakaksfnak/lnsKN wakaksfnak/lnsKN wakaksfnak/lnsKN wakaksfnak/lnsKN wakaksfnak/lnsKN wakaksfnak/lnsKN wakaksfnak/lnsKN wakaksfnak/lnsKN wakaksfnak/lnsKN wakaksfnak/lnsKN', 2300000.00, 30, 15, 'active', 'reserved', '2026-05-09 09:04:11', '2026-06-02 17:46:27', NULL, NULL, NULL, 11, NULL, 'Debre Birhan', 22, 0, '1778317451_69fef88bae4d2_non white.png', NULL, 'approved', NULL, 5, 5, '2026-05-09 09:04:52', NULL, '{\"year\":2020,\"mileage\":1500,\"fuel_type\":\"Diesel\",\"transmission\":\"Automatic\"}', 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, 20, '2026-06-02 20:46:27'),
(35, 5, 'product', 'BNM', 'KENF;;O EWLKJ0I[m KENF;;O EWLKJ0I[m KENF;;O EWLKJ0I[mKENF;;O EWLKJ0I[m', 1200000.00, 30, 15, 'pending', 'available', '2026-05-09 10:12:59', '2026-05-10 12:24:15', NULL, NULL, NULL, 7, NULL, 'AA', 0, 0, '1778321579_69ff08abd2311_nissan.png', NULL, 'approved', NULL, NULL, NULL, NULL, NULL, '{\"year\":2019,\"mileage\":2100,\"fuel_type\":\"Electric\",\"transmission\":\"Manual\"}', 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, NULL, NULL),
(36, 5, 'product', 'Toyota 2017', '21en;2dnjndfjjnernosm;wpfijeinpqo3en 21en;2dnjndfjjnernosm;wpfijeinpqo3en 21en;2dnjndfjjnernosm;wpfijeinpqo3en', 1300000.00, 30, 15, 'active', 'reserved', '2026-05-09 10:16:27', '2026-06-02 17:46:27', NULL, NULL, NULL, 7, NULL, 'DB', 23, 0, '1778321787_69ff097b7174b_toyota.png', NULL, 'approved', NULL, 10, 5, '2026-05-09 10:17:27', NULL, '{\"year\":2017,\"mileage\":1200,\"fuel_type\":\"Diesel\",\"transmission\":\"Manual\"}', 'month', 0, NULL, NULL, NULL, 'draft', NULL, NULL, 20, '2026-06-02 20:46:27'),
(43, 5, 'product', 'yrflk', 'hkf\'gmrfiugnerf jglinwe;os\'fv[ r[xdrlkfgr rd;kionv rfnp wihkf\'gmrfiugnerf jglinwe;os\'fv[ r[xdrlkfgr rd;kionv rfnp wihkf\'gmrfiugnerf jglinwe;os\'fv[ r[xdrlkfgr rd;kionv rfnp wi', 1200000.00, 30, 15, 'active', 'reserved', '2026-05-09 11:53:16', '2026-06-02 17:46:27', NULL, NULL, NULL, 17, NULL, 'Debre Birhan', 23, 0, '1778327596_69ff202cb79d9_Eclectriccar.png', NULL, 'approved', NULL, NULL, 3, NULL, NULL, '{\"year\":2021,\"mileage\":1200,\"fuel_type\":\"Electric\",\"transmission\":\"Manual\"}', 'month', 0, NULL, NULL, 1, 'draft', NULL, NULL, 20, '2026-06-02 20:46:27'),
(44, 1, 'product', 'BJH', 'dslskf aeomsoMSXO\'MDQWOSMX[PF\'KEOAD]M,ROW3ESFJQ4REFOI3EJQ98JF9IRFMPIEQMFIEQFWR3R78H9', 1200000.00, 30, 15, 'pending', 'available', '2026-05-09 12:28:18', '2026-05-09 12:28:18', NULL, NULL, NULL, 9, NULL, 'AA', 0, 0, '1778329698_69ff2862bfa93_green car.png', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, '{\"year\":2023,\"mileage\":1247,\"fuel_type\":\"Diesel\",\"transmission\":\"Automatic\"}', 'month', 0, NULL, NULL, 2, 'draft', NULL, NULL, NULL, NULL),
(45, 5, 'rental', 'Moder HS', 'scdkjsdlk\'pmcale\'mndokmer\'[nmf k;xnfern ofg;onr f;n vtr;nbf', 12000.00, 30, 15, 'pending', 'available', '2026-05-09 13:57:00', '2026-05-09 13:57:00', NULL, NULL, NULL, 1, NULL, 'AA', 0, 0, '1778335020_69ff3d2c588ce_App macbook pro pc.png', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, '{\"bedrooms\":3,\"bathrooms\":1,\"area\":120}', 'month', 0, NULL, NULL, 3, 'draft', NULL, NULL, NULL, NULL),
(46, 17, 'product', 'edpkoj\'p4tr\'f', 'dpmgklgnaz/skdpmao\r\nwf\'aejocio;gjn5wi4\'[0ry9uj5wirhyjginrju9owpesurkq[9oidqngv ;oainhfo\'nqake\'nop\'AMPOEAD', 1200000.00, 30, 15, 'active', 'reserved', '2026-05-10 06:19:41', '2026-06-02 17:46:27', NULL, NULL, NULL, 15, NULL, 'Gonder', 28, 0, '1778393981_6a00237de01cb_green car.png', '[\"1778393981_6a00237de1991_lamborgini.png\",\"1778393981_6a00237de705f_nissan.png\",\"1778393981_6a00237de76c9_non white.png\",\"1778393981_6a00237de7dc5_toyota.png\"]', 'approved', NULL, NULL, 4, NULL, NULL, '{\"year\":0,\"mileage\":1200,\"fuel_type\":\"Diesel\",\"transmission\":\"Manual\"}', 'month', 0, NULL, NULL, 4, 'draft', NULL, NULL, 21, '2026-06-02 20:46:27'),
(47, 5, 'product', 'TDY', 'g vlsmd;maZ,m\'Lferpodcp\'g vlsmd;maZ,m\'Lferpodcp\'g vlsmd;maZ,m\'Lferpodcp\'g vlsmd;maZ,m\'Lferpodcp\'g vlsmd;maZ,m\'Lferpodcp\'g vlsmd;maZ,m\'Lferpodcp\'g vlsmd;maZ,m\'Lferpodcp\'g vlsmd;maZ,m\'Lferpodcp\'', 3000000.00, 30, 15, 'pending', 'available', '2026-05-10 12:11:51', '2026-05-10 12:11:51', NULL, NULL, NULL, 17, NULL, 'Debre Birhan', 0, 0, '1778415111_6a00760787527_Eclectriccar.png', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, '{\"year\":2025,\"mileage\":2300,\"fuel_type\":\"Diesel\",\"transmission\":\"Automatic\"}', 'month', 0, NULL, NULL, 5, 'draft', NULL, NULL, NULL, NULL),
(48, 21, 'job', 'Mobile Application Developer', 'We need only 10 skilled developers', 12000.00, 30, 15, 'pending', 'available', '2026-06-02 19:10:34', '2026-06-02 19:10:34', NULL, NULL, NULL, 21, NULL, 'AA', 0, 0, '1780427434_6a1f2aaaef76b_TSp.png', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, '{\"employment_type\":\"Full-time\",\"requirements\":\"1 year experience in coding\",\"experience_level\":\"Entry Level\",\"deadline\":\"2026-06-04\"}', 'month', 0, NULL, NULL, 7, 'draft', NULL, NULL, NULL, NULL),
(49, 5, 'rental', 'Villa 18', 'kneaklama;lmFMS;Lmdmmepsl;m; pmfmeapamfopwkfempomqp', 119999.00, 30, 15, 'pending', 'available', '2026-06-02 20:35:17', '2026-06-02 20:35:17', NULL, NULL, NULL, 3, NULL, 'Debre Birhan', 0, 0, '1780432517_6a1f3e85d221b_villa.png', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, '{\"bedrooms\":2,\"bathrooms\":1,\"area\":123,\"parking\":\"Yes\",\"furnished\":\"Fully Furnished\"}', 'month', 0, NULL, NULL, 8, 'draft', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `listing_negotiations`
--

CREATE TABLE `listing_negotiations` (
  `id` int(11) NOT NULL,
  `listing_id` int(11) NOT NULL,
  `seller_id` int(11) NOT NULL,
  `status` varchar(50) DEFAULT 'under_review',
  `proposed_commission` decimal(5,2) DEFAULT NULL,
  `proposed_deposit` decimal(12,2) DEFAULT NULL,
  `counter_commission` decimal(5,2) DEFAULT NULL,
  `counter_deposit` decimal(12,2) DEFAULT NULL,
  `counter_message` text DEFAULT NULL,
  `featured_listing_fee` decimal(12,2) DEFAULT 0.00,
  `admin_notes` text DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `risk_score` int(11) DEFAULT 0,
  `seller_trust_score` int(11) DEFAULT 0,
  `accepted_at` datetime DEFAULT NULL,
  `deposit_paid_at` datetime DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `listing_negotiations`
--

INSERT INTO `listing_negotiations` (`id`, `listing_id`, `seller_id`, `status`, `proposed_commission`, `proposed_deposit`, `counter_commission`, `counter_deposit`, `counter_message`, `featured_listing_fee`, `admin_notes`, `rejection_reason`, `risk_score`, `seller_trust_score`, `accepted_at`, `deposit_paid_at`, `published_at`, `created_at`, `updated_at`) VALUES
(1, 43, 5, 'published', 3.00, 5000.00, NULL, NULL, NULL, 0.00, 'This is our rule', NULL, 0, 0, '2026-05-09 15:34:33', NULL, '2026-05-09 15:52:18', '2026-05-09 14:53:16', '2026-05-09 15:52:18'),
(2, 44, 1, 'under_review', NULL, NULL, NULL, NULL, NULL, 0.00, NULL, NULL, 0, 0, NULL, NULL, NULL, '2026-05-09 15:28:18', '2026-05-09 15:28:18'),
(3, 45, 5, 'agreement_accepted', 3.00, 3000.00, NULL, NULL, NULL, 0.00, '', NULL, 0, 0, '2026-05-09 17:00:12', NULL, NULL, '2026-05-09 16:57:00', '2026-05-09 17:00:12'),
(4, 46, 17, 'published', 3.50, 50000.00, NULL, NULL, '', 0.00, 'dojs;HSIFWIENPOAjemdo[', NULL, 0, 0, '2026-05-10 09:22:57', NULL, '2026-05-10 09:23:11', '2026-05-10 09:19:41', '2026-05-10 09:23:11'),
(5, 47, 5, 'under_review', NULL, NULL, NULL, NULL, NULL, 0.00, NULL, NULL, 0, 0, NULL, NULL, NULL, '2026-05-10 15:11:51', '2026-05-10 15:11:51'),
(6, 35, 5, 'agreement_accepted', 5.00, 50000.00, NULL, NULL, NULL, 0.00, '', NULL, 0, 0, '2026-05-10 15:24:51', NULL, NULL, '2026-05-10 15:17:28', '2026-05-10 15:24:51'),
(7, 48, 21, 'under_review', NULL, NULL, NULL, NULL, NULL, 0.00, NULL, NULL, 0, 0, NULL, NULL, NULL, '2026-06-02 22:10:34', '2026-06-02 22:10:34'),
(8, 49, 5, 'under_review', NULL, NULL, NULL, NULL, NULL, 0.00, NULL, NULL, 0, 0, NULL, NULL, NULL, '2026-06-02 23:35:17', '2026-06-02 23:35:17');

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `conversation_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `reactions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`reactions`)),
  `status` enum('sent','delivered','read') DEFAULT 'sent',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_by_sender` tinyint(1) DEFAULT 0,
  `deleted_by_receiver` tinyint(1) DEFAULT 0,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `messages`
--

INSERT INTO `messages` (`id`, `conversation_id`, `sender_id`, `receiver_id`, `message`, `is_read`, `read_at`, `reactions`, `status`, `created_at`, `deleted_by_sender`, `deleted_by_receiver`, `deleted_at`) VALUES
(1, 1, 5, 1, 'Hi', 1, '2026-05-05 12:23:36', NULL, 'sent', '2026-05-05 12:13:58', 0, 1, '2026-05-10 07:29:11'),
(2, 1, 5, 1, 'kfkl', 1, '2026-05-05 12:23:36', NULL, 'sent', '2026-05-05 12:15:50', 0, 1, '2026-05-10 07:29:11'),
(3, 1, 5, 1, 'Hi,Admin', 1, '2026-05-05 12:24:37', NULL, 'sent', '2026-05-05 12:24:34', 0, 1, '2026-05-10 07:29:11'),
(4, 1, 1, 5, 'Hi Mesfin How are you ?', 1, '2026-05-05 12:30:38', NULL, 'sent', '2026-05-05 12:30:38', 1, 0, '2026-05-10 07:29:11'),
(5, 1, 1, 5, 'How are you doing ?', 1, '2026-05-05 12:52:24', NULL, 'sent', '2026-05-05 12:52:23', 1, 0, '2026-05-10 07:29:11'),
(6, 1, 5, 1, 'A', 1, '2026-05-05 12:52:54', NULL, 'sent', '2026-05-05 12:52:45', 0, 1, '2026-05-10 07:29:11'),
(7, 1, 1, 5, 'Hi', 1, '2026-05-05 12:53:16', NULL, 'sent', '2026-05-05 12:53:13', 1, 0, '2026-05-10 07:29:11'),
(8, 1, 1, 5, 'abcd', 1, '2026-05-05 12:53:25', NULL, 'sent', '2026-05-05 12:53:24', 1, 0, '2026-05-10 07:29:11'),
(9, 1, 5, 1, 'ef', 1, '2026-05-05 12:55:07', NULL, 'sent', '2026-05-05 12:53:44', 0, 1, '2026-05-10 07:29:11'),
(10, 1, 5, 1, 'Nn', 1, '2026-05-05 12:57:30', NULL, 'sent', '2026-05-05 12:57:25', 0, 1, '2026-05-10 07:29:11'),
(11, 1, 1, 5, 'Dd', 1, '2026-05-05 12:59:24', NULL, 'sent', '2026-05-05 12:59:23', 1, 0, '2026-05-10 07:29:11'),
(12, 1, 1, 5, 'bye', 1, '2026-05-05 13:08:42', NULL, 'sent', '2026-05-05 13:08:32', 1, 0, '2026-05-10 07:29:11'),
(13, 1, 5, 1, 'Thanks', 1, '2026-05-05 13:08:57', NULL, 'sent', '2026-05-05 13:08:56', 0, 1, '2026-05-10 07:29:11'),
(14, 1, 5, 1, 'Hi Guad!', 1, '2026-05-05 13:20:01', NULL, 'sent', '2026-05-05 13:19:52', 0, 1, '2026-05-10 07:29:11'),
(15, 1, 1, 5, 'hi', 1, '2026-05-05 13:40:07', NULL, 'sent', '2026-05-05 13:39:35', 1, 0, '2026-05-10 07:29:11'),
(16, 1, 5, 1, 'Fine', 1, '2026-05-05 13:40:13', NULL, 'sent', '2026-05-05 13:40:13', 0, 1, '2026-05-10 07:29:11'),
(17, 1, 1, 5, 'Hi', 1, '2026-05-05 13:53:53', NULL, 'sent', '2026-05-05 13:53:50', 1, 0, '2026-05-10 07:29:11'),
(18, 1, 5, 1, 'Hi Admin', 1, '2026-05-05 15:25:32', NULL, 'sent', '2026-05-05 15:21:40', 0, 1, '2026-05-10 07:29:11'),
(19, 1, 1, 5, 'I am good', 1, '2026-05-05 15:25:53', NULL, 'sent', '2026-05-05 15:25:50', 1, 0, '2026-05-10 07:29:11'),
(20, 1, 1, 5, 'HI', 1, '2026-05-08 15:51:45', NULL, 'sent', '2026-05-08 09:49:41', 1, 0, '2026-05-10 07:29:11');

-- --------------------------------------------------------

--
-- Table structure for table `messages_backup`
--

CREATE TABLE `messages_backup` (
  `id` int(11) NOT NULL DEFAULT 0,
  `from_user_id` int(11) DEFAULT NULL,
  `from_company_id` int(11) DEFAULT NULL,
  `to_admin` tinyint(1) DEFAULT 1,
  `subject` varchar(200) DEFAULT NULL,
  `message` text NOT NULL,
  `is_replied` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `message_reactions`
--

CREATE TABLE `message_reactions` (
  `id` int(11) NOT NULL,
  `message_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `reaction_type` enum('like','dislike','love','laugh','sad') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `message_reactions`
--

INSERT INTO `message_reactions` (`id`, `message_id`, `user_id`, `reaction_type`, `created_at`) VALUES
(1, 2, 5, 'like', '2026-05-05 12:24:18'),
(2, 4, 5, 'dislike', '2026-05-05 12:32:49'),
(3, 5, 5, 'like', '2026-05-05 12:52:38'),
(4, 12, 5, 'like', '2026-05-05 13:08:46'),
(5, 12, 1, 'like', '2026-05-05 13:08:50'),
(6, 14, 1, 'love', '2026-05-05 13:25:07'),
(7, 13, 1, 'like', '2026-05-05 13:39:54'),
(8, 16, 1, 'love', '2026-05-05 13:40:17'),
(9, 16, 5, 'like', '2026-05-05 13:40:23'),
(10, 17, 5, 'like', '2026-05-05 13:53:58'),
(11, 19, 5, 'like', '2026-05-05 15:25:59'),
(12, 20, 1, 'like', '2026-05-10 07:29:04');

-- --------------------------------------------------------

--
-- Table structure for table `negotiation_history`
--

CREATE TABLE `negotiation_history` (
  `id` int(11) NOT NULL,
  `negotiation_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `performed_by` int(11) NOT NULL,
  `performed_by_type` varchar(20) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `negotiation_messages`
--

CREATE TABLE `negotiation_messages` (
  `id` int(11) NOT NULL,
  `negotiation_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `sender_type` varchar(20) DEFAULT 'seller',
  `message` text NOT NULL,
  `attachments` text DEFAULT NULL,
  `is_read` tinyint(4) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `negotiation_messages`
--

INSERT INTO `negotiation_messages` (`id`, `negotiation_id`, `sender_id`, `sender_type`, `message`, `attachments`, `is_read`, `created_at`) VALUES
(1, 1, 5, 'seller', 'Hi\r\n', NULL, 0, '2026-05-09 15:11:53'),
(2, 2, 1, 'seller', 'Hi,How are you ?\r\n\r\n', NULL, 0, '2026-05-09 15:30:00'),
(3, 1, 0, 'system', 'Admin has proposed 5% commission and 5,000.00 ETB deposit. Please review and respond.', NULL, 0, '2026-05-09 15:30:53'),
(4, 2, 1, 'seller', 'Hi,How are you ?\r\n\r\n', NULL, 0, '2026-05-09 15:30:58'),
(5, 2, 1, 'seller', 'Hi,How are you ?\r\n\r\n', NULL, 0, '2026-05-09 15:31:04'),
(6, 1, 1, 'admin', 'Ok\r\n', NULL, 0, '2026-05-09 15:34:19'),
(7, 1, 0, 'system', 'Admin has accepted your counter offer! Please proceed with deposit payment.', NULL, 0, '2026-05-09 15:34:33'),
(8, 1, 0, 'system', '🎉 Congratulations! Your listing has been published and is now live!', NULL, 0, '2026-05-09 15:52:18'),
(9, 3, 0, 'system', 'Admin has proposed 3% commission and 3,000.00 ETB deposit. Please review.', NULL, 0, '2026-05-09 16:58:57'),
(10, 4, 0, 'system', 'Admin has proposed 4% commission and 50,000.00 ETB deposit. Please review.', NULL, 0, '2026-05-10 09:20:49'),
(11, 4, 0, 'system', 'Admin accepted your counter offer! Please proceed with deposit payment.', NULL, 0, '2026-05-10 09:22:57'),
(12, 4, 0, 'system', '🎉 Congratulations! Your listing has been published and is now live!', NULL, 0, '2026-05-10 09:23:11'),
(13, 8, 5, 'seller', 'Hi\r\n', NULL, 0, '2026-06-02 23:48:29'),
(14, 8, 5, 'seller', 'Hi\r\n', NULL, 0, '2026-06-02 23:55:00'),
(15, 8, 5, 'seller', 'Hi\r\n', NULL, 0, '2026-06-02 23:55:11'),
(16, 8, 5, 'seller', 'Hi\r\n', NULL, 0, '2026-06-03 00:03:22');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `company_id` int(11) DEFAULT NULL,
  `title` varchar(100) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `link` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `company_id`, `title`, `message`, `is_read`, `created_at`, `link`) VALUES
(1, 2, NULL, 'Listing Approved - Payment Required', 'Your listing \'Sample Product\' has been approved! Deposit: 30% (300.00 ETB) Commission: 15% (150.00 ETB) Total to pay: 450.00 ETB. Pay now to activate your listing.', 0, '2026-05-04 12:48:13', NULL),
(2, 5, NULL, 'Listing Approved - Payment Required', 'Your listing \'g+1 couse\' has been approved! Deposit: 30% (15,000.00 ETB) Commission: 10% (5,000.00 ETB) Total to pay: 20,000.00 ETB. Pay now to activate your listing.', 1, '2026-05-04 12:58:08', NULL),
(3, 5, NULL, 'Listing Approved - Payment Required', 'Your listing \'Iphone 16\' has been approved! Deposit: 30% (36,899.98 ETB) Commission: 15% (18,449.99 ETB) Total to pay: 55,349.96 ETB. Pay now to activate your listing.', 1, '2026-05-04 17:19:55', NULL),
(4, 5, NULL, 'New Rental Interest', 'A customer is interested in your listing: Plastic bottle. Please pay the deposit to confirm.', 1, '2026-05-08 14:54:26', NULL),
(5, 17, NULL, '💰 New Paid Booking', 'A tenant has paid the deposit for asqaq. Booking dates: Nov 30 - May 12', 1, '2026-05-08 17:01:55', 'owner_bookings.php'),
(6, 17, NULL, '💰 New Paid Booking', 'A tenant has paid the deposit for asqaq. Booking dates: Nov 30 - May 11', 1, '2026-05-08 17:02:28', 'owner_bookings.php'),
(18, 5, NULL, 'Commission Proposal for yrflk', 'Admin has proposed 5% commission and 5,000.00 ETB deposit for your listing. Please review and respond.', 1, '2026-05-09 12:30:53', '/broker_system/user/negotiations.php'),
(19, 5, NULL, 'New Message', 'Ok\r\n', 1, '2026-05-09 12:34:19', '/broker_system/user/negotiate.php?id=1'),
(20, 5, NULL, 'Counter Offer Accepted - yrflk', 'Great news! Your counter offer has been accepted. Please proceed with deposit payment to publish your listing.', 1, '2026-05-09 12:34:33', '/broker_system/user/negotiations.php'),
(21, 5, NULL, '🎉 Your Listing is Live!', 'Congratulations! Your listing \'yrflk\' has been published and is now visible to buyers.', 1, '2026-05-09 12:52:18', NULL),
(22, 5, NULL, 'Commission Proposal for Moder HS', 'Admin has proposed 3% commission and 3,000.00 ETB deposit for your listing.', 1, '2026-05-09 13:58:57', NULL),
(23, 17, NULL, 'Commission Proposal for edpkoj\'p4tr\'f', 'Admin has proposed 4% commission and 50,000.00 ETB deposit for your listing.', 1, '2026-05-10 06:20:49', NULL),
(24, 17, NULL, 'Counter Offer Accepted!', 'Great news! Your counter offer has been accepted. Please proceed with deposit payment.', 1, '2026-05-10 06:22:57', NULL),
(25, 17, NULL, '🎉 Your Listing is Live!', 'Congratulations! Your listing \'edpkoj\'p4tr\'f\' has been published and is now visible to buyers.', 1, '2026-05-10 06:23:11', NULL),
(26, 5, NULL, '💰 TEST PAYMENT NOTIFICATION', 'A guest has paid 6,750.00 ETB (30% deposit) for your property. Please check your dashboard.', 1, '2026-05-10 12:00:44', 'owner_bookings.php'),
(27, 5, NULL, '💰 NEW PAYMENT RECEIVED - Guest Paid 30% Deposit', '💰💰 PAYMENT RECEIVED! 💰💰\n\nGuest: Abebe Alemu\nProperty: aaaaaaa\nAmount Paid: 540,000.00 ETB (30% deposit)\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n✅ Payment is held in escrow.\n📌 You will receive the remaining balance after check-out.\n📱 Click to view booking details.', 1, '2026-05-10 12:02:09', 'owner_bookings.php'),
(28, 5, NULL, 'Commission Proposal', 'Admin has proposed 5% commission and 50,000.00 ETB deposit for your listing \"BNM\". Please review.', 1, '2026-05-10 12:17:28', NULL),
(29, 5, NULL, 'Commission Proposal', 'Admin has proposed 5% commission and 50,000.00 ETB deposit for your listing \"BNM\". Please accept to publish.', 1, '2026-05-10 12:24:15', NULL),
(30, 19, NULL, 'Delivery completed', 'The seller marked your order as delivered. Please confirm receipt to release escrow.', 1, '2026-06-01 11:16:13', NULL),
(31, 5, NULL, '💰 Payment Released', 'Payment of 1,164,000.00 ETB has been released to your wallet for yrflk', 1, '2026-06-01 11:21:05', NULL),
(32, 19, NULL, '✅ Transaction Completed', 'Your transaction for yrflk has been completed successfully.', 1, '2026-06-01 11:21:05', NULL),
(33, 5, NULL, 'Payment Received', 'Payment received for Toyota 2017: 195,000.00 ETB. Funds are in escrow.', 1, '2026-06-01 11:31:38', 'owner_bookings.php'),
(34, 19, NULL, 'Delivery completed', 'The seller marked your order as delivered. Please confirm receipt to release escrow.', 1, '2026-06-01 11:32:40', NULL),
(35, 5, NULL, '💰 Payment Released', 'Payment of 1,235,000.00 ETB has been released to your wallet for Toyota 2017', 1, '2026-06-01 11:33:04', NULL),
(36, 19, NULL, '✅ Transaction Completed', 'Your transaction for Toyota 2017 has been completed successfully.', 1, '2026-06-01 11:33:04', NULL),
(37, 5, NULL, 'Payment Received', 'Payment received for aaaaaaa: 540,000.00 ETB. Funds are in escrow.', 1, '2026-06-01 11:35:30', 'owner_bookings.php'),
(38, 19, NULL, 'Delivery completed', 'The seller marked your order as delivered. Please confirm receipt to release escrow.', 1, '2026-06-01 11:36:03', NULL),
(39, 5, NULL, '💰 Payment Released', 'Payment of 1,020,000.00 ETB has been released to your wallet for aaaaaaa', 1, '2026-06-01 11:37:22', NULL),
(40, 19, NULL, '✅ Transaction Completed', 'Your transaction for aaaaaaa has been completed successfully.', 1, '2026-06-01 11:37:22', NULL),
(41, 5, NULL, 'Payment Received', 'Payment received for ABCD: 300,000.00 ETB. Funds are in escrow.', 1, '2026-06-01 11:51:37', 'owner_bookings.php'),
(42, 19, NULL, 'Delivery completed', 'The seller marked your order as delivered. Please confirm receipt to release escrow.', 1, '2026-06-01 11:52:51', NULL),
(43, 5, NULL, '💰 Payment Released', 'Payment of 2,850,000.00 ETB has been released to your wallet for ABCD', 1, '2026-06-01 11:53:20', NULL),
(44, 19, NULL, '✅ Transaction Completed', 'Your transaction for ABCD has been completed successfully.', 1, '2026-06-01 11:53:20', NULL),
(45, 5, NULL, 'Payment Received', 'Payment received for BMW: 230,000.00 ETB. Funds are in escrow.', 1, '2026-06-01 12:08:16', 'owner_bookings.php'),
(46, 19, NULL, 'Delivery completed', 'The seller marked your order as delivered. Please confirm receipt to release escrow.', 1, '2026-06-01 12:09:17', NULL),
(47, 19, NULL, 'Delivery completed', 'The seller marked your order as delivered. Please confirm receipt to release escrow.', 1, '2026-06-01 16:26:28', NULL),
(48, 19, NULL, 'Delivery completed', 'The seller marked your order as delivered. Please confirm receipt to release escrow.', 1, '2026-06-01 16:35:00', NULL),
(49, 19, NULL, 'Delivery completed', 'The seller marked your order as delivered. Please confirm receipt to release escrow.', 1, '2026-06-01 16:39:14', NULL),
(50, 19, NULL, 'Delivery completed', 'The seller marked your order as delivered. Please confirm receipt to release escrow.', 0, '2026-06-01 16:45:51', NULL),
(51, 5, NULL, '💰 Payment Released', 'Payment of 3,420,000.00 ETB has been released to your wallet for Nissan 2026', 1, '2026-06-01 16:53:08', NULL),
(52, 19, NULL, '✅ Transaction Completed', 'Your transaction for Nissan 2026 has been completed successfully.', 0, '2026-06-01 16:53:08', NULL),
(53, 19, NULL, 'Delivery completed', 'The seller marked your order as delivered. Please confirm receipt to release escrow.', 0, '2026-06-01 16:53:36', NULL),
(54, 5, NULL, '💰 Payment Released', 'Payment of 900,000.00 ETB has been released to your wallet for Newsssssssssssssssssssssssssssss', 1, '2026-06-01 16:54:39', NULL),
(55, 19, NULL, '✅ Transaction Completed', 'Your transaction for Newsssssssssssssssssssssssssssss has been completed successfully.', 0, '2026-06-01 16:54:39', NULL),
(56, 20, NULL, 'Delivery completed', 'The seller marked your order as delivered. Please confirm receipt to release escrow.', 0, '2026-06-01 17:12:31', NULL),
(57, 5, NULL, '💰 Payment Released', 'Payment of 3,570,000.00 ETB has been released to your wallet for FNL car', 1, '2026-06-01 17:13:18', NULL),
(58, 20, NULL, '✅ Transaction Completed', 'Your transaction for FNL car has been completed successfully.', 0, '2026-06-01 17:13:18', NULL),
(59, 20, NULL, 'Delivery completed', 'The seller marked your order as delivered. Please confirm receipt to release escrow.', 0, '2026-06-01 17:43:20', NULL),
(60, 5, NULL, '💰 Payment Released', 'Payment of 1,020,000.00 ETB has been released to your wallet for aaaaaaa', 1, '2026-06-01 17:44:10', NULL),
(61, 20, NULL, '✅ Transaction Completed', 'Your transaction for aaaaaaa has been completed successfully.', 0, '2026-06-01 17:44:10', NULL),
(62, 20, NULL, 'Delivery completed', 'The seller marked your order as delivered. Please confirm receipt to release escrow.', 0, '2026-06-02 04:59:04', NULL),
(63, 5, NULL, '💰 Payment Released', 'Payment of 1,164,000.00 ETB has been released to your wallet for yrflk', 1, '2026-06-02 05:00:05', NULL),
(64, 20, NULL, '✅ Transaction Completed', 'Your transaction for yrflk has been completed successfully.', 0, '2026-06-02 05:00:05', NULL),
(65, 2, NULL, 'Withdrawal Submitted', 'Your withdrawal of 150.00 ETB to Telebirr +251912345678 was submitted and is being processed.', 0, '2026-06-02 05:44:44', NULL),
(66, 1, NULL, 'New Telebirr Withdrawal Request', 'Demo User requested 150.00 ETB via Telebirr.', 0, '2026-06-02 05:44:44', NULL),
(67, 2, NULL, 'Telebirr Transfer Successful', 'Your withdrawal of 150.00 ETB was sent to your Telebirr account. Reference: TBW2026060207444423D27B87. Awaiting admin approval.', 0, '2026-06-02 05:44:44', NULL),
(68, 5, NULL, 'Withdrawal Submitted', 'Your withdrawal of 100,000.00 ETB to Telebirr +251992116527 was submitted and is being processed.', 1, '2026-06-02 05:47:57', NULL),
(69, 1, NULL, 'New Telebirr Withdrawal Request', 'Mesfin Haileslassie requested 100,000.00 ETB via Telebirr.', 0, '2026-06-02 05:47:57', NULL),
(70, 5, NULL, 'Telebirr Transfer Successful', 'Your withdrawal of 100,000.00 ETB was sent to your Telebirr account. Reference: TBW202606020747570AE8021C. Awaiting admin approval.', 1, '2026-06-02 05:47:57', NULL),
(71, 1, NULL, '💰 New Withdrawal Request', 'User #5 requested a Telebirr withdrawal of 42,999.99 ETB.', 0, '2026-06-02 06:53:48', NULL),
(72, 1, NULL, '💰 New Withdrawal Request', 'User #5 requested a Telebirr withdrawal of 100,000.00 ETB.', 0, '2026-06-02 06:59:00', NULL),
(73, 1, NULL, '💰 New Withdrawal Request', 'User #5 requested a Telebirr withdrawal of 100,000.00 ETB.', 0, '2026-06-02 06:59:10', NULL),
(74, 20, NULL, 'Delivery completed', 'The seller marked your order as delivered. Please confirm receipt to release escrow.', 0, '2026-06-02 07:03:04', NULL),
(75, 5, NULL, '💰 Payment Released', 'Payment of 1,800,000.00 ETB has been released to your wallet for Modern Electric Car', 1, '2026-06-02 07:03:39', NULL),
(76, 20, NULL, '✅ Transaction Completed', 'Your transaction for Modern Electric Car has been completed successfully.', 0, '2026-06-02 07:03:39', NULL),
(78, 5, NULL, '💰 Payment Received', '💰 Payment Received!\n\nItem: Nissan 2026\nAmount: 360,000.00 ETB\n\nThe payment is held securely in escrow. Please prepare for delivery.', 1, '2026-06-02 17:14:28', '/broker_system/user/transaction.php?id=89'),
(79, 5, NULL, '💰 Payment Received', '💰 Payment Received!\n\nItem: FNL car\nAmount: 1,890,000.00 ETB\n\nThe payment is held securely in escrow. Please prepare for delivery.', 1, '2026-06-02 17:43:27', '/broker_system/user/transaction.php?id=91'),
(80, 5, NULL, 'Payment Released', 'Payment of 3,570,000.00 ETB has been released to your wallet for FNL car', 1, '2026-06-02 18:06:32', NULL),
(81, 5, NULL, '💰 Payment Received', '💰 Payment Received!\n\nItem: FNL car\nAmount: 2,310,000.00 ETB\n\nThe payment is held securely in escrow. Please prepare for delivery.', 1, '2026-06-02 18:32:46', '/broker_system/user/transaction.php?id=91'),
(82, 16, NULL, 'New Job Application', 'A new application has been submitted for Mobile Application Developer', 0, '2026-06-02 20:00:09', 'transaction.php?id=94'),
(83, 16, NULL, 'New Job Application', 'A new application has been submitted for Mobile Application Developer', 0, '2026-06-02 20:02:31', 'transaction.php?id=95'),
(84, 15, NULL, 'New Job Application', 'A new application has been submitted for Senier Web developer', 0, '2026-06-02 20:05:46', 'transaction.php?id=96'),
(85, 16, NULL, 'New Job Application', 'A new application has been submitted for Mobile Application Developer', 0, '2026-06-02 20:13:51', 'transaction.php?id=97'),
(86, 16, NULL, '💰 Payment Received', '💰 Payment Received!\n\nItem: Mobile Application Developer\nAmount: 5,400.00 ETB\n\nThe payment is held securely in escrow. Please prepare for delivery.', 0, '2026-06-02 20:14:23', '/broker_system/user/transaction.php?id=97');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `type` enum('deposit_buyer','deposit_seller','commission','remaining_balance','full_payment','release_to_seller') NOT NULL,
  `telebirr_code_5digit` varchar(5) DEFAULT NULL,
  `status` enum('pending','confirmed','failed') DEFAULT 'pending',
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `transaction_id`, `user_id`, `amount`, `type`, `telebirr_code_5digit`, `status`, `confirmed_at`, `created_at`) VALUES
(1, 1, 7, 500.00, 'deposit_buyer', '12345', 'confirmed', '2026-05-04 11:50:34', '2026-05-04 11:50:34'),
(2, 5, 5, 47000.00, '', '31543', 'confirmed', '2026-05-04 12:43:07', '2026-05-04 12:43:07'),
(3, 6, 5, 20000.00, '', '30529', 'confirmed', '2026-05-04 13:01:11', '2026-05-04 13:01:11'),
(4, 7, 9, 54000.00, 'deposit_buyer', '25324', 'confirmed', '2026-05-04 14:07:00', '2026-05-04 14:07:00'),
(5, 8, 9, 450.00, 'deposit_buyer', '45965', 'confirmed', '2026-05-04 14:23:42', '2026-05-04 14:23:42'),
(6, 8, 6, 450.00, 'deposit_buyer', '45965', 'confirmed', '2026-05-04 14:24:12', '2026-05-04 14:24:12'),
(7, 9, 9, 15000.00, 'deposit_seller', '40069', 'confirmed', '2026-05-04 14:47:11', '2026-05-04 14:47:11'),
(8, 9, 5, 15000.00, 'deposit_seller', '40069', 'confirmed', '2026-05-04 14:47:23', '2026-05-04 14:47:23'),
(9, 10, 7, 55349.00, 'deposit_seller', '42106', 'confirmed', '2026-05-04 17:28:08', '2026-05-04 17:28:08'),
(10, 11, 7, 54000.00, 'deposit_buyer', '39405', 'confirmed', '2026-05-04 17:36:38', '2026-05-04 17:36:38'),
(11, 12, 7, 9999.00, 'deposit_seller', '75000', 'confirmed', '2026-05-04 19:53:10', '2026-05-04 19:53:10'),
(12, 12, 7, 9999.00, 'deposit_seller', '57978', 'confirmed', '2026-05-04 19:58:59', '2026-05-04 19:58:59'),
(13, 12, 7, 9999.00, 'deposit_seller', '02182', 'confirmed', '2026-05-04 20:01:58', '2026-05-04 20:01:58'),
(14, 16, 7, 9999.00, 'deposit_buyer', '86638', 'confirmed', '2026-05-04 20:24:32', '2026-05-04 20:24:32'),
(15, 16, 11, 0.80, 'deposit_buyer', '86638', 'confirmed', '2026-05-04 20:25:44', '2026-05-04 20:25:44'),
(16, 17, 5, 9999.80, 'deposit_buyer', '10311', 'confirmed', '2026-05-05 05:22:40', '2026-05-05 05:22:40'),
(17, 17, 5, 0.00, 'deposit_buyer', '10311', 'confirmed', '2026-05-05 05:22:53', '2026-05-05 05:22:53'),
(18, 20, 7, 10.00, 'deposit_seller', '44605', 'confirmed', '2026-05-05 15:11:13', '2026-05-05 15:11:13'),
(19, 20, 7, 10.00, 'deposit_seller', '03940', 'confirmed', '2026-05-05 15:12:36', '2026-05-05 15:12:36'),
(20, 20, 7, 10.00, 'deposit_buyer', '17640', 'confirmed', '2026-05-05 15:18:11', '2026-05-05 15:18:11'),
(21, 21, 7, 666.00, 'deposit_buyer', '71818', 'confirmed', '2026-05-05 15:33:26', '2026-05-05 15:33:26'),
(22, 21, 7, 666.00, 'deposit_buyer', '60382', 'confirmed', '2026-05-05 15:37:10', '2026-05-05 15:37:10'),
(23, 22, 7, 360.00, 'deposit_buyer', '76432', 'confirmed', '2026-05-05 16:08:52', '2026-05-05 16:08:52'),
(24, 22, 7, 360.00, 'deposit_buyer', '75789', 'confirmed', '2026-05-08 12:45:07', '2026-05-08 12:45:07'),
(25, 28, 7, 5400.00, 'deposit_buyer', '23494', 'confirmed', '2026-05-08 15:02:40', '2026-05-08 15:02:40'),
(26, 29, 7, 507.00, 'deposit_buyer', '84131', 'confirmed', '2026-05-08 15:15:53', '2026-05-08 15:15:53'),
(27, 28, 7, 5400.00, 'deposit_buyer', '31831', 'confirmed', '2026-05-08 15:18:57', '2026-05-08 15:18:57'),
(28, 30, 7, 5400.00, 'deposit_buyer', '78814', 'confirmed', '2026-05-08 15:20:45', '2026-05-08 15:20:45'),
(29, 29, 7, 507.00, 'deposit_buyer', '90352', 'confirmed', '2026-05-08 15:47:52', '2026-05-08 15:47:52'),
(30, 30, 7, 5400.00, 'deposit_buyer', '16378', 'confirmed', '2026-05-08 16:39:35', '2026-05-08 16:39:35'),
(31, 30, 7, 5400.00, 'deposit_buyer', '27168', 'confirmed', '2026-05-08 16:51:56', '2026-05-08 16:51:56'),
(32, 31, 7, 21600.00, 'deposit_buyer', '39756', 'confirmed', '2026-05-08 17:00:14', '2026-05-08 17:00:14'),
(34, 31, 5, 21600.00, 'deposit_buyer', '34454', 'confirmed', '2026-05-08 17:01:55', '2026-05-08 17:01:55'),
(35, 32, 5, 16200.00, 'deposit_buyer', '97570', 'confirmed', '2026-05-08 17:02:28', '2026-05-08 17:02:28'),
(36, 34, 7, 21600.00, 'deposit_buyer', '30258', 'confirmed', '2026-05-08 17:19:02', '2026-05-08 17:19:02'),
(37, 36, 7, 540000.00, 'deposit_buyer', '14918', 'confirmed', '2026-05-09 06:01:01', '2026-05-09 06:01:01'),
(38, 36, 7, 540000.00, 'deposit_buyer', '77829', 'confirmed', '2026-05-09 06:01:31', '2026-05-09 06:01:31'),
(39, 37, 7, 540000.00, 'deposit_buyer', '30862', 'confirmed', '2026-05-09 06:22:08', '2026-05-09 06:22:08'),
(40, 38, 7, 300000.00, 'deposit_buyer', '08808', 'confirmed', '2026-05-09 06:44:08', '2026-05-09 06:44:08'),
(41, 38, 7, 300000.00, 'deposit_buyer', '25652', 'confirmed', '2026-05-09 06:45:15', '2026-05-09 06:45:15'),
(42, 39, 7, 400000.00, 'deposit_buyer', '42140', 'confirmed', '2026-05-09 06:58:16', '2026-05-09 06:58:16'),
(43, 40, 7, 400000.00, 'deposit_buyer', '04914', 'confirmed', '2026-05-09 07:04:45', '2026-05-09 07:04:45'),
(44, 40, 7, 400000.00, 'deposit_buyer', '76497', 'confirmed', '2026-05-09 07:06:41', '2026-05-09 07:06:41'),
(45, 39, 7, 400000.00, 'deposit_buyer', '22676', 'confirmed', '2026-05-09 07:14:23', '2026-05-09 07:14:23'),
(47, 42, 17, 540000.00, 'deposit_seller', '19371', 'confirmed', '2026-05-09 07:25:09', '2026-05-09 07:25:09'),
(48, 43, 7, 300000.00, 'deposit_buyer', '96448', 'confirmed', '2026-05-09 07:28:45', '2026-05-09 07:28:45'),
(49, 44, 7, 120000.00, 'deposit_buyer', '31803', 'confirmed', '2026-05-09 07:36:03', '2026-05-09 07:36:03'),
(50, 44, 7, 120000.00, 'deposit_buyer', '82747', 'confirmed', '2026-05-09 07:39:18', '2026-05-09 07:39:18'),
(51, 45, 7, 1890000.00, 'deposit_buyer', '08620', 'confirmed', '2026-05-09 07:42:50', '2026-05-09 07:42:50'),
(52, 45, 7, 1890000.00, 'deposit_buyer', '28730', 'confirmed', '2026-05-09 07:51:56', '2026-05-09 07:51:56'),
(53, 46, 7, 540000.00, 'deposit_buyer', '76263', 'confirmed', '2026-05-09 07:57:58', '2026-05-09 07:57:58'),
(54, 47, 7, 360000.00, 'deposit_buyer', '44648', 'confirmed', '2026-05-09 08:20:40', '2026-05-09 08:20:40'),
(55, 47, 7, 360000.00, 'deposit_buyer', '25169', 'confirmed', '2026-05-09 08:28:49', '2026-05-09 08:28:49'),
(56, 48, 7, 1754999.00, 'deposit_buyer', '28274', 'confirmed', '2026-05-09 08:35:52', '2026-05-09 08:35:52'),
(57, 48, 7, 1754999.00, 'deposit_buyer', '75470', 'confirmed', '2026-05-09 08:43:50', '2026-05-09 08:43:50'),
(58, 49, 7, 1890000.00, 'deposit_buyer', '21573', 'confirmed', '2026-05-09 08:53:37', '2026-05-09 08:53:37'),
(59, 49, 7, 1890000.00, 'deposit_buyer', '82166', 'confirmed', '2026-05-09 09:02:45', '2026-05-09 09:02:45'),
(60, 50, 7, 230000.00, 'deposit_buyer', '69451', 'confirmed', '2026-05-09 09:05:41', '2026-05-09 09:05:41'),
(61, 50, 7, 230000.00, 'deposit_buyer', '67068', 'confirmed', '2026-05-09 09:13:46', '2026-05-09 09:13:46'),
(62, 46, 7, 540000.00, 'deposit_buyer', '75854', 'confirmed', '2026-05-09 09:14:22', '2026-05-09 09:14:22'),
(63, 43, 7, 300000.00, 'deposit_buyer', '61649', 'confirmed', '2026-05-09 09:20:10', '2026-05-09 09:20:10'),
(64, 51, 7, 1230000.00, 'deposit_buyer', '19323', 'confirmed', '2026-05-09 09:27:45', '2026-05-09 09:27:45'),
(65, 51, 7, 1230000.00, 'deposit_buyer', '36906', 'confirmed', '2026-05-09 09:34:23', '2026-05-09 09:34:23'),
(66, 41, 7, 4099144.00, 'deposit_buyer', '57676', 'confirmed', '2026-05-09 09:35:12', '2026-05-09 09:35:12'),
(67, 52, 7, 195000.00, 'deposit_buyer', '91943', 'confirmed', '2026-05-09 10:19:22', '2026-05-09 10:19:22'),
(68, 52, 7, 195000.00, 'deposit_buyer', '52908', 'confirmed', '2026-05-09 10:20:16', '2026-05-09 10:20:16'),
(70, 53, 7, 396000.00, 'deposit_buyer', '37854', 'confirmed', '2026-05-09 13:37:03', '2026-05-09 13:37:03'),
(71, 54, 7, 195000.00, 'deposit_buyer', '05901', 'confirmed', '2026-05-09 13:44:03', '2026-05-09 13:44:03'),
(72, 55, 7, 400000.00, 'deposit_buyer', '96765', 'confirmed', '2026-05-09 16:06:04', '2026-05-09 16:06:04'),
(73, 56, 7, 1754999.00, 'deposit_buyer', '61929', 'confirmed', '2026-05-09 17:11:26', '2026-05-09 17:11:26'),
(74, 56, 7, 1754999.00, 'deposit_buyer', '14212', 'confirmed', '2026-05-09 17:12:53', '2026-05-09 17:12:53'),
(75, 58, 7, 230000.00, 'deposit_buyer', '26458', 'confirmed', '2026-05-10 10:21:19', '2026-05-10 10:21:19'),
(76, 59, 7, 400000.00, 'deposit_buyer', '66855', 'confirmed', '2026-05-10 10:49:57', '2026-05-10 10:49:57'),
(77, 60, 7, 300000.00, 'deposit_buyer', '71972', 'confirmed', '2026-05-10 10:58:12', '2026-05-10 10:58:12'),
(78, 62, 7, 1890000.00, 'deposit_buyer', '61720', 'confirmed', '2026-05-10 11:27:23', '2026-05-10 11:27:23'),
(79, 63, 7, 300000.00, 'deposit_buyer', '80193', 'confirmed', '2026-05-10 11:43:28', '2026-05-10 11:43:28'),
(80, 64, 7, 360000.00, 'deposit_buyer', '78607', 'confirmed', '2026-05-10 11:54:18', '2026-05-10 11:54:18'),
(81, 67, 7, 1890000.00, 'deposit_buyer', '67943', 'confirmed', '2026-06-01 10:08:22', '2026-06-01 10:08:22'),
(82, 67, 7, 1890000.00, 'deposit_buyer', '97434', 'confirmed', '2026-06-01 10:11:25', '2026-06-01 10:11:25'),
(83, 67, 7, 1890000.00, 'deposit_buyer', '93745', 'confirmed', '2026-06-01 10:16:02', '2026-06-01 10:16:02'),
(84, 68, 7, 396000.00, 'deposit_buyer', '64368', 'confirmed', '2026-06-01 10:44:02', '2026-06-01 10:44:02'),
(85, 68, 7, 396000.00, 'deposit_buyer', '91237', 'confirmed', '2026-06-01 11:17:18', '2026-06-01 11:17:18'),
(86, 69, 7, 195000.00, 'deposit_buyer', '09506', 'confirmed', '2026-06-01 11:31:36', '2026-06-01 11:31:36'),
(87, 70, 7, 540000.00, 'deposit_buyer', '21117', 'confirmed', '2026-06-01 11:35:29', '2026-06-01 11:35:29'),
(88, 70, 7, 540000.00, 'deposit_buyer', '53129', 'confirmed', '2026-06-01 11:37:05', '2026-06-01 11:37:05'),
(89, 71, 7, 300000.00, 'deposit_buyer', '56696', 'confirmed', '2026-06-01 11:51:35', '2026-06-01 11:51:35'),
(90, 72, 7, 230000.00, 'deposit_buyer', '14268', 'confirmed', '2026-06-01 12:08:16', '2026-06-01 12:08:16'),
(91, 72, 7, 2185000.00, 'deposit_buyer', '90381', 'confirmed', '2026-06-01 12:10:16', '2026-06-01 12:10:16'),
(92, 72, 7, 2185000.00, 'deposit_buyer', '35891', 'confirmed', '2026-06-01 12:11:18', '2026-06-01 12:11:18'),
(93, 72, 7, 2185000.00, 'deposit_buyer', '85790', 'confirmed', '2026-06-01 12:12:28', '2026-06-01 12:12:28'),
(94, 72, 7, 2185000.00, 'deposit_buyer', '37337', 'confirmed', '2026-06-01 12:17:52', '2026-06-01 12:17:52'),
(95, 72, 7, 2185000.00, 'deposit_buyer', '95400', 'confirmed', '2026-06-01 12:18:34', '2026-06-01 12:18:34'),
(96, 74, 7, 1890000.00, 'deposit_buyer', '51221', 'confirmed', '2026-06-01 16:25:02', '2026-06-01 16:25:02'),
(97, 74, 7, 2940000.00, 'deposit_buyer', '85427', 'confirmed', '2026-06-01 16:27:05', '2026-06-01 16:27:05'),
(98, 74, 7, 2940000.00, 'deposit_buyer', '43201', 'confirmed', '2026-06-01 16:35:33', '2026-06-01 16:35:33'),
(99, 75, 19, 300000.00, 'deposit_buyer', '43243', 'confirmed', '2026-06-01 16:43:42', '2026-06-01 16:43:42'),
(100, 76, 19, 360000.00, 'deposit_buyer', '70164', 'confirmed', '2026-06-01 16:44:56', '2026-06-01 16:44:56'),
(101, 76, 7, 3420000.00, 'deposit_buyer', '09376', 'confirmed', '2026-06-01 16:46:49', '2026-06-01 16:46:49'),
(102, 76, 7, 3420000.00, 'deposit_buyer', '21822', 'confirmed', '2026-06-01 16:47:45', '2026-06-01 16:47:45'),
(103, 76, 7, 3420000.00, 'deposit_buyer', '07501', 'confirmed', '2026-06-01 16:49:06', '2026-06-01 16:49:06'),
(104, 75, 19, 800000.00, 'remaining_balance', '42035', 'confirmed', '2026-06-01 16:54:17', '2026-06-01 16:54:17'),
(105, 77, 20, 195000.00, 'deposit_buyer', '40832', 'confirmed', '2026-06-01 17:06:30', '2026-06-01 17:06:30'),
(106, 78, 7, 1890000.00, 'deposit_buyer', '48226', 'confirmed', '2026-06-01 17:11:30', '2026-06-01 17:11:30'),
(107, 78, 7, 2940000.00, 'deposit_buyer', '47208', 'confirmed', '2026-06-01 17:13:10', '2026-06-01 17:13:10'),
(108, 79, 20, 360000.00, 'deposit_buyer', '84881', 'confirmed', '2026-06-01 17:21:24', '2026-06-01 17:21:24'),
(109, 80, 19, 408000.00, 'deposit_buyer', '75219', 'confirmed', '2026-06-01 17:30:11', '2026-06-01 17:30:11'),
(110, 81, 20, 300000.00, 'deposit_buyer', '98832', 'confirmed', '2026-06-01 17:32:14', '2026-06-01 17:32:14'),
(111, 82, 20, 230000.00, 'deposit_buyer', '75077', 'confirmed', '2026-06-01 17:33:18', '2026-06-01 17:33:18'),
(112, 83, 7, 540000.00, 'deposit_buyer', '26700', 'confirmed', '2026-06-01 17:42:30', '2026-06-01 17:42:30'),
(113, 83, 7, 840000.00, 'deposit_buyer', '55300', 'confirmed', '2026-06-01 17:44:06', '2026-06-01 17:44:06'),
(114, 84, 7, 396000.00, 'deposit_buyer', '25474', 'confirmed', '2026-06-02 04:57:57', '2026-06-02 04:57:57'),
(115, 84, 7, 840000.00, 'deposit_buyer', '53131', 'confirmed', '2026-06-02 05:00:02', '2026-06-02 05:00:02'),
(116, 85, 7, 400000.00, 'deposit_buyer', '57913', 'confirmed', '2026-06-02 07:02:32', '2026-06-02 07:02:32'),
(117, 85, 7, 1800000.00, 'deposit_buyer', '04538', 'confirmed', '2026-06-02 07:03:37', '2026-06-02 07:03:37'),
(118, 86, 7, 400000.00, 'deposit_buyer', '31739', 'confirmed', '2026-06-02 12:47:35', '2026-06-02 12:47:35'),
(120, 89, 7, 360000.00, 'deposit_buyer', '90029', 'confirmed', '2026-06-02 17:14:27', '2026-06-02 17:14:27'),
(121, 90, 7, 408000.00, 'deposit_buyer', '27230', 'confirmed', '2026-06-02 17:19:09', '2026-06-02 17:19:09'),
(122, 91, 7, 1890000.00, 'deposit_buyer', '85302', 'confirmed', '2026-06-02 17:43:25', '2026-06-02 17:43:25'),
(123, 91, 7, 2310000.00, 'deposit_buyer', '34154', 'confirmed', '2026-06-02 18:32:45', '2026-06-02 18:32:45'),
(124, 95, 7, 5400.00, 'deposit_buyer', '36610', 'confirmed', '2026-06-02 20:03:15', '2026-06-02 20:03:15'),
(125, 96, 5, 4800.00, 'deposit_buyer', '36037', 'confirmed', '2026-06-02 20:06:10', '2026-06-02 20:06:10'),
(126, 97, 5, 5400.00, 'deposit_buyer', '85402', 'confirmed', '2026-06-02 20:14:22', '2026-06-02 20:14:22');

-- --------------------------------------------------------

--
-- Table structure for table `payment_codes`
--

CREATE TABLE `payment_codes` (
  `id` int(11) NOT NULL,
  `code` varchar(5) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` enum('deposit_buyer','deposit_seller','commission','remaining') NOT NULL,
  `status` enum('pending','used','expired') DEFAULT 'pending',
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payment_codes`
--

INSERT INTO `payment_codes` (`id`, `code`, `transaction_id`, `amount`, `user_id`, `type`, `status`, `expires_at`, `created_at`) VALUES
(3, '43395', 1, 500.00, 1, 'deposit_buyer', 'pending', '2026-05-04 12:05:42', '2026-05-04 11:35:42'),
(4, '35780', 1, 500.00, 1, 'deposit_buyer', 'pending', '2026-05-04 12:06:43', '2026-05-04 11:36:43'),
(7, '12345', 1, 500.00, 1, 'deposit_buyer', 'used', '2026-05-04 11:50:34', '2026-05-04 11:46:02'),
(8, '31543', 5, 47000.00, 5, 'deposit_seller', 'used', '2026-05-04 12:43:07', '2026-05-04 12:39:36'),
(9, '30529', 6, 20000.00, 5, 'deposit_seller', 'used', '2026-05-04 13:01:11', '2026-05-04 13:00:40'),
(10, '96284', 7, 54000.00, 6, 'deposit_buyer', 'expired', '2026-05-04 13:49:08', '2026-05-04 13:32:53'),
(11, '25324', 7, 54000.00, 6, 'deposit_buyer', 'used', '2026-05-04 14:07:00', '2026-05-04 13:58:46'),
(12, '45965', 8, 450.00, 6, 'deposit_buyer', 'used', '2026-05-04 14:23:42', '2026-05-04 14:15:10'),
(13, '96980', 9, 20000.00, 6, 'deposit_buyer', 'pending', '2026-05-04 13:55:46', '2026-05-04 14:45:46'),
(14, '40069', 9, 15000.00, 5, 'deposit_seller', 'used', '2026-05-04 14:47:11', '2026-05-04 14:46:42'),
(15, '42106', 10, 55349.00, 5, 'deposit_seller', 'used', '2026-05-04 17:28:08', '2026-05-04 17:25:07'),
(16, '91459', 10, 55349.00, 5, 'deposit_seller', 'used', '2026-05-04 18:30:37', '2026-05-04 17:29:35'),
(17, '39405', 11, 54000.00, 10, 'deposit_buyer', 'used', '2026-05-04 17:36:38', '2026-05-04 17:33:58'),
(18, '75000', 12, 9999.00, 12, 'deposit_seller', 'used', '2026-05-04 19:53:10', '2026-05-04 19:49:03'),
(19, '57978', 12, 9999.00, 12, 'deposit_seller', 'used', '2026-05-04 19:58:59', '2026-05-04 19:53:47'),
(20, '02182', 12, 9999.00, 12, 'deposit_seller', 'used', '2026-05-04 20:01:58', '2026-05-04 19:59:24'),
(21, '38299', 12, 9999.00, 12, 'deposit_seller', 'used', '2026-05-04 20:03:06', '2026-05-04 20:02:13'),
(22, '47072', 13, 9999.00, 11, 'deposit_buyer', 'pending', '2026-05-04 19:23:42', '2026-05-04 20:13:42'),
(23, '42834', 14, 9999.00, 11, 'deposit_buyer', 'pending', '2026-05-04 19:24:49', '2026-05-04 20:14:49'),
(24, '63438', 15, 9999.00, 11, 'deposit_buyer', 'pending', '2026-05-04 19:27:18', '2026-05-04 20:17:18'),
(25, '86638', 16, 9999.00, 11, 'deposit_buyer', 'used', '2026-05-04 20:24:32', '2026-05-04 20:23:31'),
(26, '10311', 17, 9999.00, 5, 'deposit_buyer', 'used', '2026-05-05 05:22:40', '2026-05-05 05:22:18'),
(27, '58451', 18, 450.00, 5, 'deposit_buyer', 'pending', '2026-05-05 13:34:36', '2026-05-05 14:24:36'),
(28, '96235', 19, 507.00, 5, 'deposit_seller', 'used', '2026-05-05 14:39:29', '2026-05-05 14:39:12'),
(29, '44605', 20, 10.00, 5, 'deposit_seller', 'used', '2026-05-05 15:11:13', '2026-05-05 15:03:43'),
(30, '03940', 20, 10.00, 5, 'deposit_seller', 'used', '2026-05-05 15:12:36', '2026-05-05 15:12:13'),
(31, '17640', 20, 10.00, 5, 'deposit_seller', 'used', '2026-05-05 15:18:11', '2026-05-05 15:16:50'),
(32, '71818', 21, 666.00, 5, 'deposit_seller', 'used', '2026-05-05 15:33:26', '2026-05-05 15:32:13'),
(33, '60382', 21, 666.00, 5, 'deposit_seller', 'used', '2026-05-05 15:37:10', '2026-05-05 15:36:36'),
(34, '76432', 22, 360.00, 5, 'deposit_seller', 'used', '2026-05-05 16:08:52', '2026-05-05 16:08:26'),
(35, '99359', 22, 360.00, 5, 'deposit_seller', 'expired', '2026-05-08 12:44:36', '2026-05-06 20:12:00'),
(36, '75789', 22, 360.00, 5, 'deposit_seller', 'used', '2026-05-08 12:45:07', '2026-05-08 12:44:39'),
(37, '10396', 23, 360.00, 17, 'deposit_buyer', 'pending', '2026-05-08 13:37:13', '2026-05-08 14:27:13'),
(38, '08523', 24, 360.00, 17, 'deposit_buyer', 'pending', '2026-05-08 13:37:41', '2026-05-08 14:27:41'),
(39, '79692', 25, 20000.00, 17, 'deposit_buyer', 'pending', '2026-05-08 13:39:36', '2026-05-08 14:29:36'),
(40, '43755', 26, 20000.00, 17, 'deposit_buyer', 'pending', '2026-05-08 13:40:40', '2026-05-08 14:30:40'),
(41, '23494', 28, 5400.00, 17, 'deposit_seller', 'used', '2026-05-08 15:02:40', '2026-05-08 14:59:03'),
(42, '75997', 28, 5400.00, 17, 'deposit_seller', 'expired', '2026-05-08 15:18:07', '2026-05-08 15:06:36'),
(43, '84131', 29, 507.00, 17, 'deposit_buyer', 'used', '2026-05-08 15:15:53', '2026-05-08 15:14:35'),
(44, '31831', 28, 5400.00, 17, 'deposit_seller', 'used', '2026-05-08 15:18:57', '2026-05-08 15:18:11'),
(45, '78814', 30, 5400.00, 5, 'deposit_buyer', 'used', '2026-05-08 15:20:45', '2026-05-08 15:20:22'),
(46, '90352', 29, 507.00, 17, 'deposit_buyer', 'used', '2026-05-08 15:47:52', '2026-05-08 15:47:33'),
(47, '16378', 30, 5400.00, 5, 'deposit_buyer', 'used', '2026-05-08 16:39:35', '2026-05-08 16:39:15'),
(48, '27168', 30, 5400.00, 5, 'deposit_buyer', 'used', '2026-05-08 16:51:56', '2026-05-08 16:51:25'),
(49, '44876', 30, 5400.00, 5, 'deposit_buyer', 'pending', '2026-05-08 16:24:44', '2026-05-08 16:54:44'),
(50, '39756', 31, 21600.00, 5, 'deposit_buyer', 'used', '2026-05-08 17:00:14', '2026-05-08 16:59:39'),
(51, '34454', 31, 21600.00, 5, 'deposit_buyer', 'used', '2026-05-08 17:01:55', '2026-05-08 17:00:48'),
(52, '97570', 32, 16200.00, 5, 'deposit_buyer', 'used', '2026-05-08 17:02:28', '2026-05-08 17:02:12'),
(53, '15782', 33, 666.00, 17, 'deposit_buyer', 'pending', '2026-05-08 16:34:57', '2026-05-08 17:04:57'),
(54, '30258', 34, 21600.00, 6, 'deposit_buyer', 'used', '2026-05-08 17:19:02', '2026-05-08 17:17:50'),
(55, '52485', 35, 540000.00, 5, 'deposit_buyer', 'pending', '2026-05-08 19:56:28', '2026-05-08 20:26:28'),
(56, '14918', 36, 540000.00, 5, 'deposit_seller', 'used', '2026-05-09 06:01:01', '2026-05-09 05:52:50'),
(57, '77829', 36, 540000.00, 5, 'deposit_seller', 'used', '2026-05-09 06:01:31', '2026-05-09 06:01:14'),
(58, '30862', 37, 540000.00, 17, 'deposit_buyer', 'used', '2026-05-09 06:22:08', '2026-05-09 06:17:16'),
(59, '08808', 38, 300000.00, 5, 'deposit_seller', 'used', '2026-05-09 06:44:08', '2026-05-09 06:43:32'),
(60, '25652', 38, 300000.00, 5, 'deposit_seller', 'used', '2026-05-09 06:45:15', '2026-05-09 06:44:27'),
(61, '42140', 39, 400000.00, 5, 'deposit_seller', 'used', '2026-05-09 06:58:16', '2026-05-09 06:57:50'),
(62, '04914', 40, 400000.00, 17, 'deposit_seller', 'used', '2026-05-09 07:04:45', '2026-05-09 07:04:18'),
(63, '76497', 40, 400000.00, 17, 'deposit_seller', 'used', '2026-05-09 07:06:41', '2026-05-09 07:05:50'),
(64, '22676', 39, 400000.00, 5, 'deposit_seller', 'used', '2026-05-09 07:14:23', '2026-05-09 07:13:30'),
(65, '94999', 41, 4099144.00, 5, 'deposit_seller', 'used', '2026-05-09 07:16:17', '2026-05-09 07:15:29'),
(66, '09396', 41, 4099144.00, 5, 'deposit_seller', 'pending', '2026-05-09 06:46:26', '2026-05-09 07:16:26'),
(67, '19371', 42, 540000.00, 17, 'deposit_seller', 'used', '2026-05-09 07:25:09', '2026-05-09 07:24:43'),
(68, '96448', 43, 300000.00, 5, 'deposit_seller', 'used', '2026-05-09 07:28:45', '2026-05-09 07:28:11'),
(69, '31803', 44, 120000.00, 17, 'deposit_seller', 'used', '2026-05-09 07:36:03', '2026-05-09 07:35:27'),
(72, '82747', 44, 120000.00, 17, 'deposit_seller', 'used', '2026-05-09 07:39:18', '2026-05-09 07:36:44'),
(73, '08620', 45, 1890000.00, 5, 'deposit_seller', 'used', '2026-05-09 07:42:50', '2026-05-09 07:42:16'),
(75, '28730', 45, 1890000.00, 5, 'deposit_seller', 'used', '2026-05-09 07:51:56', '2026-05-09 07:51:24'),
(77, '76263', 46, 540000.00, 5, 'deposit_seller', 'used', '2026-05-09 07:57:58', '2026-05-09 07:56:50'),
(78, '11239', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:08:32', '2026-05-09 08:08:28'),
(79, '04015', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:08:35', '2026-05-09 08:08:32'),
(80, '01534', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:08:38', '2026-05-09 08:08:35'),
(81, '99605', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:08:41', '2026-05-09 08:08:38'),
(82, '61011', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:08:44', '2026-05-09 08:08:41'),
(83, '60719', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:08:47', '2026-05-09 08:08:44'),
(84, '29352', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:08:50', '2026-05-09 08:08:47'),
(85, '93417', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:08:53', '2026-05-09 08:08:50'),
(86, '35624', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:08:56', '2026-05-09 08:08:53'),
(87, '41134', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:08:59', '2026-05-09 08:08:56'),
(88, '88262', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:09:02', '2026-05-09 08:08:59'),
(89, '84859', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:09:05', '2026-05-09 08:09:02'),
(90, '96672', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:09:08', '2026-05-09 08:09:05'),
(91, '52039', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:09:11', '2026-05-09 08:09:08'),
(92, '43376', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:09:14', '2026-05-09 08:09:11'),
(93, '10153', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:09:17', '2026-05-09 08:09:14'),
(94, '71754', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:09:20', '2026-05-09 08:09:17'),
(95, '86950', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:09:23', '2026-05-09 08:09:20'),
(96, '09327', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:09:26', '2026-05-09 08:09:23'),
(97, '29570', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:09:29', '2026-05-09 08:09:26'),
(98, '41796', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:09:29', '2026-05-09 08:09:29'),
(99, '01193', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:09:32', '2026-05-09 08:09:29'),
(100, '13718', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:09:35', '2026-05-09 08:09:32'),
(101, '45311', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:09:38', '2026-05-09 08:09:35'),
(102, '35807', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:09:41', '2026-05-09 08:09:38'),
(103, '63407', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:09:44', '2026-05-09 08:09:41'),
(104, '26701', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:09:47', '2026-05-09 08:09:44'),
(105, '42815', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:09:49', '2026-05-09 08:09:47'),
(106, '05453', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:09:51', '2026-05-09 08:09:49'),
(107, '41229', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:09:54', '2026-05-09 08:09:51'),
(108, '57644', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:09:57', '2026-05-09 08:09:54'),
(109, '91178', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:10:00', '2026-05-09 08:09:57'),
(110, '88643', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:10:03', '2026-05-09 08:10:00'),
(111, '04661', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:10:06', '2026-05-09 08:10:03'),
(112, '41298', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:10:09', '2026-05-09 08:10:06'),
(113, '53112', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:10:12', '2026-05-09 08:10:09'),
(114, '78021', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:10:15', '2026-05-09 08:10:12'),
(115, '88822', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:10:18', '2026-05-09 08:10:15'),
(116, '10937', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:10:21', '2026-05-09 08:10:18'),
(117, '58238', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:10:24', '2026-05-09 08:10:21'),
(118, '22000', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:10:27', '2026-05-09 08:10:24'),
(119, '86303', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:10:30', '2026-05-09 08:10:27'),
(120, '29128', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:10:33', '2026-05-09 08:10:30'),
(121, '16235', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:10:36', '2026-05-09 08:10:33'),
(122, '33353', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:10:39', '2026-05-09 08:10:36'),
(123, '62931', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:10:42', '2026-05-09 08:10:39'),
(124, '50335', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:10:45', '2026-05-09 08:10:42'),
(125, '32581', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:10:48', '2026-05-09 08:10:45'),
(126, '81602', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:10:51', '2026-05-09 08:10:48'),
(127, '08390', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:10:54', '2026-05-09 08:10:51'),
(128, '81558', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:10:57', '2026-05-09 08:10:54'),
(129, '17756', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:11:00', '2026-05-09 08:10:57'),
(130, '78792', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:11:03', '2026-05-09 08:11:00'),
(131, '00131', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:11:21', '2026-05-09 08:11:03'),
(132, '29375', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:12:21', '2026-05-09 08:11:21'),
(133, '29412', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:13:21', '2026-05-09 08:12:21'),
(134, '10941', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:13:56', '2026-05-09 08:13:21'),
(135, '74141', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:13:57', '2026-05-09 08:13:56'),
(136, '87332', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:14:00', '2026-05-09 08:13:57'),
(137, '86856', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:14:03', '2026-05-09 08:14:00'),
(138, '88593', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:14:06', '2026-05-09 08:14:03'),
(139, '63286', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:14:09', '2026-05-09 08:14:06'),
(140, '10688', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:14:12', '2026-05-09 08:14:09'),
(141, '50635', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:14:15', '2026-05-09 08:14:12'),
(142, '10388', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:14:18', '2026-05-09 08:14:15'),
(143, '54466', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:14:21', '2026-05-09 08:14:18'),
(144, '37061', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:14:24', '2026-05-09 08:14:21'),
(145, '40210', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:14:27', '2026-05-09 08:14:24'),
(146, '45961', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:14:30', '2026-05-09 08:14:27'),
(147, '46842', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:14:33', '2026-05-09 08:14:30'),
(148, '17016', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:14:36', '2026-05-09 08:14:33'),
(149, '09663', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:14:39', '2026-05-09 08:14:36'),
(150, '53832', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:14:42', '2026-05-09 08:14:39'),
(151, '39359', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:14:45', '2026-05-09 08:14:42'),
(152, '98547', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:14:48', '2026-05-09 08:14:45'),
(153, '04384', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:14:51', '2026-05-09 08:14:48'),
(154, '13375', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:14:54', '2026-05-09 08:14:51'),
(155, '71708', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:15:21', '2026-05-09 08:14:54'),
(156, '60148', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:16:21', '2026-05-09 08:15:21'),
(157, '56142', 47, 360000.00, 5, 'deposit_seller', 'expired', '2026-05-09 08:16:40', '2026-05-09 08:16:21'),
(158, '44648', 47, 360000.00, 5, 'deposit_seller', 'used', '2026-05-09 08:20:40', '2026-05-09 08:19:40'),
(159, '25169', 47, 360000.00, 5, 'deposit_seller', 'used', '2026-05-09 08:28:49', '2026-05-09 08:20:42'),
(160, '28274', 48, 1754999.00, 5, 'deposit_seller', 'used', '2026-05-09 08:35:52', '2026-05-09 08:35:16'),
(161, '75470', 48, 1754999.00, 5, 'deposit_seller', 'used', '2026-05-09 08:43:50', '2026-05-09 08:35:52'),
(162, '21573', 49, 1890000.00, 5, 'deposit_seller', 'used', '2026-05-09 08:53:37', '2026-05-09 08:53:17'),
(163, '82166', 49, 1890000.00, 5, 'deposit_seller', 'used', '2026-05-09 09:02:45', '2026-05-09 08:53:38'),
(164, '69451', 50, 230000.00, 5, 'deposit_seller', 'used', '2026-05-09 09:05:41', '2026-05-09 09:05:10'),
(165, '67068', 50, 230000.00, 5, 'deposit_seller', 'used', '2026-05-09 09:13:46', '2026-05-09 09:13:03'),
(166, '75854', 46, 540000.00, 5, 'deposit_seller', 'used', '2026-05-09 09:14:22', '2026-05-09 09:14:02'),
(167, '61649', 43, 300000.00, 5, 'deposit_seller', 'used', '2026-05-09 09:20:10', '2026-05-09 09:19:45'),
(168, '19323', 51, 1230000.00, 5, 'deposit_seller', 'used', '2026-05-09 09:27:45', '2026-05-09 09:27:23'),
(169, '36906', 51, 1230000.00, 5, 'deposit_seller', 'used', '2026-05-09 09:34:23', '2026-05-09 09:33:17'),
(170, '57676', 41, 4099144.00, 5, 'deposit_seller', 'used', '2026-05-09 09:35:12', '2026-05-09 09:34:42'),
(171, '91943', 52, 195000.00, 5, 'deposit_seller', 'used', '2026-05-09 10:19:22', '2026-05-09 10:17:45'),
(172, '52908', 52, 195000.00, 5, 'deposit_seller', 'used', '2026-05-09 10:20:16', '2026-05-09 10:19:49'),
(173, '37854', 53, 396000.00, 17, 'deposit_buyer', 'used', '2026-05-09 13:37:03', '2026-05-09 13:35:40'),
(174, '05901', 54, 195000.00, 17, 'deposit_buyer', 'used', '2026-05-09 13:44:03', '2026-05-09 13:43:32'),
(175, '96765', 55, 400000.00, 5, 'deposit_buyer', 'used', '2026-05-09 16:06:04', '2026-05-09 16:05:01'),
(176, '61929', 56, 1754999.00, 17, 'deposit_buyer', 'used', '2026-05-09 17:11:26', '2026-05-09 17:10:46'),
(177, '14212', 56, 1754999.00, 17, 'deposit_buyer', 'used', '2026-05-09 17:12:53', '2026-05-09 17:12:01'),
(178, '08931', 57, 408000.00, 1, 'deposit_buyer', 'pending', '2026-05-10 07:26:27', '2026-05-10 07:56:27'),
(179, '26458', 58, 230000.00, 17, 'deposit_buyer', 'used', '2026-05-10 10:21:19', '2026-05-10 10:20:47'),
(180, '66855', 59, 400000.00, 17, 'deposit_buyer', 'used', '2026-05-10 10:49:57', '2026-05-10 10:49:35'),
(181, '71972', 60, 300000.00, 17, 'deposit_buyer', 'used', '2026-05-10 10:58:12', '2026-05-10 10:57:49'),
(182, '53428', 61, 540000.00, 17, 'deposit_buyer', 'pending', '2026-05-10 10:45:24', '2026-05-10 11:15:24'),
(183, '61720', 62, 1890000.00, 17, 'deposit_buyer', 'used', '2026-05-10 11:27:23', '2026-05-10 11:26:50'),
(184, '80193', 63, 300000.00, 17, 'deposit_buyer', 'used', '2026-05-10 11:43:28', '2026-05-10 11:43:03'),
(185, '78607', 64, 360000.00, 17, 'deposit_buyer', 'used', '2026-05-10 11:54:18', '2026-05-10 11:53:57'),
(186, '09609', 65, 1890000.00, 17, 'deposit_buyer', 'pending', '2026-05-10 11:28:01', '2026-05-10 11:58:01'),
(187, '90578', 66, 408000.00, 5, 'deposit_buyer', 'pending', '2026-06-01 07:28:15', '2026-06-01 07:58:15'),
(188, '67943', 67, 1890000.00, 18, 'deposit_buyer', 'used', '2026-06-01 10:08:22', '2026-06-01 10:06:49'),
(189, '97434', 67, 1890000.00, 18, 'deposit_buyer', 'used', '2026-06-01 10:11:25', '2026-06-01 10:09:01'),
(190, '93745', 67, 1890000.00, 18, 'deposit_buyer', 'used', '2026-06-01 10:16:02', '2026-06-01 10:11:38'),
(191, '64368', 68, 396000.00, 19, 'deposit_buyer', 'used', '2026-06-01 10:44:02', '2026-06-01 10:43:44'),
(192, '91237', 68, 396000.00, 19, 'deposit_buyer', 'used', '2026-06-01 11:17:18', '2026-06-01 10:55:10'),
(193, '37479', 68, 396000.00, 19, 'deposit_buyer', 'pending', '2026-06-01 11:00:51', '2026-06-01 11:30:51'),
(194, '09506', 69, 195000.00, 19, 'deposit_buyer', 'used', '2026-06-01 11:31:36', '2026-06-01 11:31:16'),
(195, '58007', 69, 195000.00, 19, 'deposit_buyer', 'pending', '2026-06-01 11:03:36', '2026-06-01 11:33:36'),
(196, '21117', 70, 540000.00, 19, 'deposit_buyer', 'used', '2026-06-01 11:35:29', '2026-06-01 11:35:09'),
(197, '53129', 70, 540000.00, 19, 'deposit_buyer', 'used', '2026-06-01 11:37:05', '2026-06-01 11:36:34'),
(198, '56696', 71, 300000.00, 19, 'deposit_buyer', 'used', '2026-06-01 11:51:35', '2026-06-01 11:51:10'),
(199, '14268', 72, 230000.00, 19, 'deposit_buyer', 'used', '2026-06-01 12:08:16', '2026-06-01 12:07:58'),
(200, '32404', 72, 2185000.00, 19, '', 'pending', '2026-06-01 11:39:34', '2026-06-01 12:09:34'),
(201, '77447', 72, 2185000.00, 19, '', 'pending', '2026-06-01 11:39:42', '2026-06-01 12:09:42'),
(202, '90381', 72, 2185000.00, 19, '', 'used', '2026-06-01 12:10:16', '2026-06-01 12:09:49'),
(203, '60885', 72, 2185000.00, 19, '', 'pending', '2026-06-01 11:40:54', '2026-06-01 12:10:54'),
(204, '35891', 72, 2185000.00, 19, '', 'used', '2026-06-01 12:11:18', '2026-06-01 12:10:58'),
(205, '72930', 72, 2185000.00, 19, '', 'pending', '2026-06-01 11:41:40', '2026-06-01 12:11:40'),
(206, '18441', 72, 2185000.00, 19, '', 'pending', '2026-06-01 11:41:50', '2026-06-01 12:11:50'),
(207, '85790', 72, 2185000.00, 19, '', 'used', '2026-06-01 12:12:28', '2026-06-01 12:11:53'),
(208, '37337', 72, 2185000.00, 19, '', 'used', '2026-06-01 12:17:52', '2026-06-01 12:16:54'),
(209, '95400', 72, 2185000.00, 19, '', 'used', '2026-06-01 12:18:34', '2026-06-01 12:18:01'),
(210, '52200', 72, 2185000.00, 19, '', 'pending', '2026-06-01 11:48:39', '2026-06-01 12:18:39'),
(211, '89043', 72, 2185000.00, 19, '', 'expired', '2026-06-01 12:23:40', '2026-06-01 12:23:36'),
(212, '57594', 72, 2185000.00, 19, '', 'expired', '2026-06-01 12:23:51', '2026-06-01 12:23:47'),
(213, '57533', 72, 2185000.00, 19, '', 'expired', '2026-06-01 12:25:02', '2026-06-01 12:24:58'),
(214, '78951', 72, 2185000.00, 19, '', 'expired', '2026-06-01 12:25:21', '2026-06-01 12:25:17'),
(215, '06277', 72, 2185000.00, 19, '', 'pending', '2026-06-01 11:55:37', '2026-06-01 12:25:37'),
(216, '55023', 72, 2185000.00, 19, '', 'pending', '2026-06-01 11:55:38', '2026-06-01 12:25:38'),
(217, '00941', 72, 2185000.00, 19, '', 'pending', '2026-06-01 11:55:39', '2026-06-01 12:25:39'),
(218, '94751', 72, 2185000.00, 19, '', 'pending', '2026-06-01 11:55:39', '2026-06-01 12:25:39'),
(219, '02673', 72, 2185000.00, 19, '', 'pending', '2026-06-01 11:55:39', '2026-06-01 12:25:39'),
(220, '59027', 72, 2185000.00, 19, '', 'expired', '2026-06-01 12:25:43', '2026-06-01 12:25:39'),
(221, '02761', 72, 2185000.00, 19, '', 'expired', '2026-06-01 12:42:01', '2026-06-01 12:41:56'),
(222, '30341', 72, 2185000.00, 19, '', 'expired', '2026-06-01 12:42:19', '2026-06-01 12:42:15'),
(223, '99468', 72, 2185000.00, 19, '', 'pending', '2026-06-01 12:12:44', '2026-06-01 12:42:44'),
(224, '57244', 72, 2185000.00, 19, '', 'pending', '2026-06-01 12:12:47', '2026-06-01 12:42:47'),
(225, '42645', 72, 2185000.00, 19, '', 'expired', '2026-06-01 12:42:52', '2026-06-01 12:42:48'),
(226, '85456', 72, 2185000.00, 19, '', 'pending', '2026-06-01 12:16:47', '2026-06-01 12:46:47'),
(227, '49795', 72, 2185000.00, 19, '', 'expired', '2026-06-01 12:46:53', '2026-06-01 12:46:49'),
(228, '88523', 72, 2185000.00, 19, '', 'pending', '2026-06-01 12:17:08', '2026-06-01 12:47:08'),
(229, '87986', 72, 2185000.00, 19, '', 'pending', '2026-06-01 12:17:08', '2026-06-01 12:47:08'),
(230, '19868', 72, 2185000.00, 19, '', 'pending', '2026-06-01 12:17:08', '2026-06-01 12:47:08'),
(231, '92713', 72, 2185000.00, 19, '', 'pending', '2026-06-01 12:17:08', '2026-06-01 12:47:08'),
(232, '90847', 72, 2185000.00, 19, '', 'pending', '2026-06-01 12:17:09', '2026-06-01 12:47:09'),
(233, '36278', 72, 2185000.00, 19, '', 'pending', '2026-06-01 12:17:09', '2026-06-01 12:47:09'),
(234, '78317', 72, 2185000.00, 19, '', 'pending', '2026-06-01 12:17:09', '2026-06-01 12:47:09'),
(235, '04053', 72, 2185000.00, 19, '', 'pending', '2026-06-01 12:17:09', '2026-06-01 12:47:09'),
(236, '93533', 72, 2185000.00, 19, '', 'pending', '2026-06-01 12:17:09', '2026-06-01 12:47:09'),
(237, '17816', 72, 2185000.00, 19, '', 'expired', '2026-06-01 12:47:14', '2026-06-01 12:47:10'),
(238, '15491', 72, 2185000.00, 19, '', 'expired', '2026-06-01 12:52:36', '2026-06-01 12:52:32'),
(239, '62737', 72, 2185000.00, 19, '', 'expired', '2026-06-01 12:56:40', '2026-06-01 12:56:36'),
(240, '36337', 73, 1890000.00, 19, 'deposit_buyer', 'expired', '2026-06-01 15:57:58', '2026-06-01 15:57:53'),
(241, '42267', 73, 1890000.00, 19, 'deposit_buyer', 'expired', '2026-06-01 15:58:31', '2026-06-01 15:58:27'),
(242, '50476', 73, 1890000.00, 19, 'deposit_buyer', 'pending', '2026-06-01 15:40:26', '2026-06-01 16:10:26'),
(243, '51221', 74, 1890000.00, 19, 'deposit_buyer', 'used', '2026-06-01 16:25:02', '2026-06-01 16:24:06'),
(244, '88205', 74, 2940000.00, 19, '', 'pending', '2026-06-01 15:55:11', '2026-06-01 16:25:12'),
(245, '80970', 74, 2940000.00, 19, '', 'pending', '2026-06-01 15:56:37', '2026-06-01 16:26:37'),
(246, '85427', 74, 2940000.00, 19, '', 'used', '2026-06-01 16:27:05', '2026-06-01 16:26:42'),
(247, '18574', 74, 2940000.00, 19, '', 'pending', '2026-06-01 15:57:10', '2026-06-01 16:27:10'),
(248, '66747', 74, 2940000.00, 19, '', 'pending', '2026-06-01 16:04:32', '2026-06-01 16:34:32'),
(249, '43201', 74, 2940000.00, 19, '', 'used', '2026-06-01 16:35:33', '2026-06-01 16:34:48'),
(250, '50583', 74, 2940000.00, 19, '', 'pending', '2026-06-01 16:05:38', '2026-06-01 16:35:38'),
(251, '85952', 74, 2940000.00, 19, '', 'expired', '2026-06-01 16:38:21', '2026-06-01 16:38:17'),
(252, '11651', 74, 2940000.00, 19, '', 'pending', '2026-06-01 17:28:42', '2026-06-01 16:38:38'),
(253, '43243', 75, 300000.00, 19, 'deposit_buyer', 'used', '2026-06-01 16:43:42', '2026-06-01 16:40:57'),
(254, '70164', 76, 360000.00, 19, 'deposit_buyer', 'used', '2026-06-01 16:44:56', '2026-06-01 16:44:56'),
(255, '09376', 76, 3420000.00, 19, '', 'used', '2026-06-01 16:46:49', '2026-06-01 16:46:20'),
(256, '21822', 76, 3420000.00, 19, '', 'used', '2026-06-01 16:47:45', '2026-06-01 16:47:24'),
(257, '07501', 76, 3420000.00, 19, '', 'used', '2026-06-01 16:49:06', '2026-06-01 16:48:47'),
(258, '42035', 75, 800000.00, 19, '', 'used', '2026-06-01 16:54:17', '2026-06-01 16:54:17'),
(259, '40832', 77, 195000.00, 20, 'deposit_buyer', 'used', '2026-06-01 17:06:30', '2026-06-01 17:06:30'),
(260, '48226', 78, 1890000.00, 20, 'deposit_buyer', 'used', '2026-06-01 17:11:30', '2026-06-01 17:10:03'),
(261, '47208', 78, 2940000.00, 20, '', 'used', '2026-06-01 17:13:10', '2026-06-01 17:12:52'),
(262, '84881', 79, 360000.00, 20, 'deposit_buyer', 'used', '2026-06-01 17:21:24', '2026-06-01 17:21:19'),
(263, '75219', 80, 408000.00, 19, 'deposit_buyer', 'used', '2026-06-01 17:30:11', '2026-06-01 17:29:57'),
(264, '98832', 81, 300000.00, 20, 'deposit_buyer', 'used', '2026-06-01 17:32:14', '2026-06-01 17:31:53'),
(265, '75077', 82, 230000.00, 20, 'deposit_buyer', 'used', '2026-06-01 17:33:18', '2026-06-01 17:33:04'),
(266, '26700', 83, 540000.00, 20, 'deposit_buyer', 'used', '2026-06-01 17:42:30', '2026-06-01 17:41:58'),
(267, '55300', 83, 840000.00, 20, '', 'used', '2026-06-01 17:44:06', '2026-06-01 17:43:38'),
(268, '25474', 84, 396000.00, 20, 'deposit_buyer', 'used', '2026-06-02 04:57:57', '2026-06-02 04:56:12'),
(269, '53131', 84, 840000.00, 20, '', 'used', '2026-06-02 05:00:02', '2026-06-02 04:59:27'),
(270, '26781', 85, 400000.00, 20, 'deposit_buyer', 'expired', '2026-06-02 05:29:39', '2026-06-02 05:19:14'),
(271, '57913', 85, 400000.00, 20, 'deposit_buyer', 'used', '2026-06-02 07:02:32', '2026-06-02 07:02:12'),
(272, '04538', 85, 1800000.00, 20, '', 'used', '2026-06-02 07:03:37', '2026-06-02 07:03:19'),
(273, '31739', 86, 400000.00, 20, 'deposit_buyer', 'used', '2026-06-02 12:47:35', '2026-06-02 12:46:29'),
(274, '69092', 87, 540000.00, 20, 'deposit_buyer', 'pending', '2026-06-02 12:58:09', '2026-06-02 12:48:09'),
(275, '31768', 89, 360000.00, 21, 'deposit_buyer', 'expired', '2026-06-02 17:06:15', '2026-06-02 17:05:51'),
(276, '90029', 89, 360000.00, 21, 'deposit_buyer', 'used', '2026-06-02 17:14:27', '2026-06-02 17:14:04'),
(277, '27230', 90, 408000.00, 21, 'deposit_buyer', 'used', '2026-06-02 17:19:09', '2026-06-02 17:18:16'),
(278, '85302', 91, 1890000.00, 21, 'deposit_buyer', 'used', '2026-06-02 17:43:25', '2026-06-02 17:43:06'),
(279, '76942', 92, 540000.00, 21, 'deposit_buyer', 'pending', '2026-06-02 17:52:23', '2026-06-02 18:22:23'),
(280, '46987', 91, 2310000.00, 21, '', 'pending', '2026-06-02 18:01:51', '2026-06-02 18:31:51'),
(281, '34154', 91, 2310000.00, 21, '', 'used', '2026-06-02 18:32:45', '2026-06-02 18:32:19'),
(283, '65219', 94, 5400.00, 5, '', 'pending', '2026-06-02 19:30:09', '2026-06-02 20:00:09'),
(284, '36610', 95, 5400.00, 21, '', 'used', '2026-06-02 20:03:15', '2026-06-02 20:02:31'),
(285, '36037', 96, 4800.00, 5, '', 'used', '2026-06-02 20:06:10', '2026-06-02 20:05:46'),
(286, '85402', 97, 5400.00, 20, '', 'used', '2026-06-02 20:14:22', '2026-06-02 20:13:51');

-- --------------------------------------------------------

--
-- Table structure for table `ratings`
--

CREATE TABLE `ratings` (
  `id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `rater_id` int(11) NOT NULL,
  `rated_id` int(11) NOT NULL,
  `score` tinyint(4) DEFAULT NULL CHECK (`score` between 1 and 5),
  `comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rental_bookings`
--

CREATE TABLE `rental_bookings` (
  `id` int(11) NOT NULL,
  `reservation_id` int(11) DEFAULT NULL,
  `transaction_id` int(11) NOT NULL,
  `property_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `owner_id` int(11) NOT NULL,
  `check_in_date` date NOT NULL,
  `check_out_date` date NOT NULL,
  `total_nights` int(11) NOT NULL,
  `total_amount` decimal(12,2) NOT NULL,
  `deposit_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `remaining_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(50) NOT NULL DEFAULT 'pending',
  `guest_name` varchar(255) DEFAULT NULL,
  `guest_phone` varchar(20) DEFAULT NULL,
  `special_requests` text DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `rental_unit` varchar(20) DEFAULT 'month',
  `total_months` int(11) DEFAULT 0,
  `viewing_date` datetime DEFAULT NULL,
  `viewing_confirmed` tinyint(4) DEFAULT 0,
  `tenant_agreed` tinyint(4) DEFAULT 0,
  `move_in_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `rental_bookings`
--

INSERT INTO `rental_bookings` (`id`, `reservation_id`, `transaction_id`, `property_id`, `tenant_id`, `owner_id`, `check_in_date`, `check_out_date`, `total_nights`, `total_amount`, `deposit_paid`, `remaining_balance`, `status`, `guest_name`, `guest_phone`, `special_requests`, `cancelled_at`, `cancelled_by`, `created_at`, `updated_at`, `rental_unit`, `total_months`, `viewing_date`, `viewing_confirmed`, `tenant_agreed`, `move_in_date`) VALUES
(1, NULL, 31, 15, 5, 17, '0000-00-00', '0000-00-00', 4, 48000.00, 14400.00, 0.00, 'confirmed', 'Mesfin Haileslassie', '0992116527', '', NULL, NULL, '2026-05-08 16:59:39', '2026-05-08 18:11:28', 'month', 0, NULL, 0, 0, NULL),
(2, NULL, 32, 15, 5, 17, '0000-00-00', '0000-00-00', 3, 36000.00, 10800.00, 0.00, 'confirmed', 'Mesfin Haileslassie', '0992116527', '', NULL, NULL, '2026-05-08 17:02:12', '2026-05-08 18:11:28', 'month', 0, NULL, 0, 0, NULL),
(3, NULL, 34, 15, 6, 17, '0000-00-00', '0000-00-00', 4, 48000.00, 14400.00, 0.00, 'pending', 'Biniam Emishaw', '+251912345678', '', NULL, NULL, '2026-05-08 17:17:50', '2026-05-08 18:11:28', 'month', 0, NULL, 0, 0, NULL);

--
-- Triggers `rental_bookings`
--
DELIMITER $$
CREATE TRIGGER `prevent_double_booking` BEFORE INSERT ON `rental_bookings` FOR EACH ROW BEGIN
    DECLARE conflict_count INT;
    
    SELECT COUNT(*) INTO conflict_count
    FROM rental_bookings
    WHERE property_id = NEW.property_id 
    AND status IN ('pending', 'confirmed', 'active')
    AND (
        (check_in_date <= NEW.check_out_date AND check_out_date >= NEW.check_in_date)
    );
    
    IF conflict_count > 0 THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'These dates are already booked';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `reservation_records`
--

CREATE TABLE `reservation_records` (
  `id` int(11) NOT NULL,
  `listing_id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `buyer_id` int(11) NOT NULL,
  `seller_id` int(11) NOT NULL,
  `check_in_date` date NOT NULL,
  `check_out_date` date NOT NULL,
  `total_nights` int(11) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `deposit_amount` decimal(10,2) NOT NULL,
  `status` enum('reserved','active','completed','cancelled','refunded') DEFAULT 'reserved',
  `payment_reference` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reservation_status_history`
--

CREATE TABLE `reservation_status_history` (
  `id` int(11) NOT NULL,
  `reservation_id` int(11) NOT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) NOT NULL,
  `changed_by` int(11) NOT NULL,
  `changed_by_type` enum('buyer','seller','admin','system') DEFAULT 'system',
  `reason` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `saved_jobs`
--

CREATE TABLE `saved_jobs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `job_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subscription_plans`
--

CREATE TABLE `subscription_plans` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `type` enum('monthly','yearly') NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `features` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subscription_plans`
--

INSERT INTO `subscription_plans` (`id`, `name`, `type`, `price`, `features`, `is_active`, `created_at`) VALUES
(1, 'Basic Monthly', 'monthly', 500.00, '{\"job_posts\":10,\"featured_listings\":1,\"priority_support\":false}', 1, '2026-05-04 07:03:34'),
(2, 'Pro Monthly', 'monthly', 1000.00, '{\"job_posts\":50,\"featured_listings\":5,\"priority_support\":true}', 1, '2026-05-04 07:03:34'),
(3, 'Basic Yearly', 'yearly', 5000.00, '{\"job_posts\":120,\"featured_listings\":12,\"priority_support\":false}', 1, '2026-05-04 07:03:34'),
(4, 'Pro Yearly', 'yearly', 10000.00, '{\"job_posts\":600,\"featured_listings\":60,\"priority_support\":true}', 1, '2026-05-04 07:03:34');

-- --------------------------------------------------------

--
-- Table structure for table `support_tickets`
--

CREATE TABLE `support_tickets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `ticket_number` varchar(20) DEFAULT NULL,
  `subject` varchar(200) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
  `status` enum('open','in_progress','resolved','closed') DEFAULT 'open',
  `assigned_to` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `resolved_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES
('auto_commission_enabled', '1', '2026-05-09 11:48:35'),
('auto_release_rental_days', '14', '2026-06-02 07:23:48'),
('availability_check_enabled', '1', '2026-06-02 07:23:48'),
('commission_percent', '15', '2026-05-04 06:24:52'),
('currency', 'ETB', '2026-05-04 06:24:52'),
('default_deposit_percent', '25', '2026-05-09 11:48:35'),
('deposit_percent', '30', '2026-05-04 06:24:52'),
('escrow_days', '14', '2026-05-04 06:24:52'),
('max_booking_window_days', '365', '2026-06-02 07:23:48'),
('max_commission_percent', '15', '2026-05-09 11:48:35'),
('max_negotiation_days', '14', '2026-05-09 11:48:35'),
('min_booking_nights', '1', '2026-06-02 07:23:48'),
('min_commission_percent', '3', '2026-05-09 11:48:35'),
('negotiation_enabled', '1', '2026-05-09 11:48:35'),
('platform_telebirr_name', 'Ethio Brokerplace Platform', '2026-06-02 05:43:18'),
('platform_telebirr_phone', '+251900000001', '2026-06-02 05:43:18'),
('site_name', 'Ethio Brokerplace', '2026-05-04 06:24:52'),
('telebirr_simulation', '1', '2026-05-04 06:24:52');

-- --------------------------------------------------------

--
-- Table structure for table `telebirr_accounts`
--

CREATE TABLE `telebirr_accounts` (
  `id` int(11) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `account_name` varchar(100) DEFAULT NULL,
  `balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `is_platform` tinyint(1) NOT NULL DEFAULT 0,
  `user_id` int(11) DEFAULT NULL,
  `pin_hash` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `telebirr_accounts`
--

INSERT INTO `telebirr_accounts` (`id`, `phone`, `account_name`, `balance`, `is_platform`, `user_id`, `pin_hash`, `is_active`, `created_at`, `updated_at`) VALUES
(1, '+251900000001', 'Ethio Brokerplace Platform', 49899850.00, 1, NULL, NULL, 1, '2026-06-02 05:43:18', '2026-06-02 05:47:57'),
(2, '+251912345678', 'Demo User', 150.00, 0, 2, NULL, 1, '2026-06-02 05:44:44', '2026-06-02 05:44:44'),
(3, '+251992116527', 'Mesfin Haileslassie', 100000.00, 0, 5, NULL, 1, '2026-06-02 05:47:57', '2026-06-02 05:47:57');

-- --------------------------------------------------------

--
-- Table structure for table `telebirr_transfers`
--

CREATE TABLE `telebirr_transfers` (
  `id` int(11) NOT NULL,
  `transfer_reference` varchar(64) NOT NULL,
  `sender_account_id` int(11) NOT NULL,
  `receiver_account_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `transfer_type` enum('payment_in','withdrawal_out','adjustment') NOT NULL DEFAULT 'withdrawal_out',
  `status` enum('pending','success','failed') NOT NULL DEFAULT 'pending',
  `withdrawal_request_id` int(11) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `telebirr_transfers`
--

INSERT INTO `telebirr_transfers` (`id`, `transfer_reference`, `sender_account_id`, `receiver_account_id`, `amount`, `transfer_type`, `status`, `withdrawal_request_id`, `error_message`, `created_at`, `completed_at`) VALUES
(1, 'TBW2026060207444423D27B87', 1, 2, 150.00, 'withdrawal_out', 'success', 1, NULL, '2026-06-02 05:44:44', '2026-06-02 05:44:44'),
(2, 'TBW202606020747570AE8021C', 1, 3, 100000.00, 'withdrawal_out', 'success', 2, NULL, '2026-06-02 05:47:57', '2026-06-02 05:47:57');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_replies`
--

CREATE TABLE `ticket_replies` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `is_admin` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `listing_id` int(11) DEFAULT NULL,
  `buyer_id` int(11) NOT NULL,
  `seller_id` int(11) NOT NULL,
  `total_amount` decimal(12,2) NOT NULL,
  `deposit_amount` decimal(12,2) NOT NULL,
  `commission_amount` decimal(12,2) NOT NULL,
  `remaining_balance` decimal(12,2) NOT NULL,
  `amount_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('pending','deposit_paid','partially_paid','fully_paid') NOT NULL DEFAULT 'pending',
  `funds_status` enum('pending','held_in_escrow','seller_confirmed','buyer_confirmed','ready_for_release','released','completed','disputed','cancelled') NOT NULL DEFAULT 'pending',
  `status` enum('pending_deposit','awaiting_buyer_deposit','awaiting_seller_deposit','deposits_complete','in_progress','completed','disputed','cancelled') DEFAULT 'pending_deposit',
  `payment_code_5digit` varchar(5) DEFAULT NULL,
  `code_expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `escrow_held` decimal(12,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `buyer_confirmed` tinyint(1) DEFAULT 0,
  `seller_confirmed` tinyint(1) DEFAULT 0,
  `escrow_released` tinyint(1) DEFAULT 0,
  `admin_processed` tinyint(1) DEFAULT 0,
  `buyer_legal_confirmed` tinyint(1) DEFAULT 0,
  `seller_legal_confirmed` tinyint(1) DEFAULT 0,
  `legal_documents` text DEFAULT NULL,
  `legal_notes` text DEFAULT NULL,
  `buyer_delivery_confirmed` tinyint(1) DEFAULT 0,
  `seller_delivery_confirmed` tinyint(1) DEFAULT 0,
  `deposit_paid` tinyint(4) DEFAULT 0,
  `remaining_paid` tinyint(4) DEFAULT 0,
  `payment_stage` enum('deposit_only','deposit_paid','fully_paid') DEFAULT 'deposit_only',
  `escrow_status` varchar(50) DEFAULT 'pending',
  `escrow_release_date` datetime DEFAULT NULL,
  `auto_release_days` int(11) DEFAULT 7,
  `buyer_confirmed_at` datetime DEFAULT NULL,
  `seller_confirmed_at` datetime DEFAULT NULL,
  `payment_released_at` datetime DEFAULT NULL,
  `admin_frozen` tinyint(4) DEFAULT 0,
  `frozen_reason` text DEFAULT NULL,
  `release_method` varchar(20) DEFAULT 'auto',
  `escrow_release_method` varchar(20) DEFAULT 'auto',
  `delivery_status` varchar(50) DEFAULT 'pending',
  `delivered_at` datetime DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `listing_type` varchar(50) DEFAULT NULL,
  `employer_id` int(11) DEFAULT NULL,
  `worker_id` int(11) DEFAULT NULL,
  `hired_at` datetime DEFAULT NULL,
  `work_submitted_at` datetime DEFAULT NULL,
  `work_approved_at` datetime DEFAULT NULL,
  `work_rejected_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `full_payment_paid` tinyint(4) DEFAULT 0,
  `full_payment_paid_at` datetime DEFAULT NULL,
  `handover_confirmed` tinyint(4) DEFAULT 0,
  `handover_confirmed_at` datetime DEFAULT NULL,
  `delivery_proof` text DEFAULT NULL,
  `work_link` varchar(500) DEFAULT NULL,
  `work_description` text DEFAULT NULL,
  `worker_completed` tinyint(4) DEFAULT 0,
  `employer_completed` tinyint(4) DEFAULT 0,
  `payment_requested_at` datetime DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `funds_released_at` timestamp NULL DEFAULT NULL,
  `reservation_status` varchar(50) DEFAULT NULL,
  `reserved_at` datetime DEFAULT NULL,
  `reserved_by` int(11) DEFAULT NULL,
  `released_at` datetime DEFAULT NULL,
  `released_by` int(11) DEFAULT NULL,
  `release_notes` text DEFAULT NULL,
  `cover_letter` text DEFAULT NULL,
  `expected_salary` decimal(12,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`id`, `listing_id`, `buyer_id`, `seller_id`, `total_amount`, `deposit_amount`, `commission_amount`, `remaining_balance`, `amount_paid`, `payment_status`, `funds_status`, `status`, `payment_code_5digit`, `code_expires_at`, `escrow_held`, `created_at`, `updated_at`, `completed_at`, `buyer_confirmed`, `seller_confirmed`, `escrow_released`, `admin_processed`, `buyer_legal_confirmed`, `seller_legal_confirmed`, `legal_documents`, `legal_notes`, `buyer_delivery_confirmed`, `seller_delivery_confirmed`, `deposit_paid`, `remaining_paid`, `payment_stage`, `escrow_status`, `escrow_release_date`, `auto_release_days`, `buyer_confirmed_at`, `seller_confirmed_at`, `payment_released_at`, `admin_frozen`, `frozen_reason`, `release_method`, `escrow_release_method`, `delivery_status`, `delivered_at`, `confirmed_at`, `listing_type`, `employer_id`, `worker_id`, `hired_at`, `work_submitted_at`, `work_approved_at`, `work_rejected_at`, `rejection_reason`, `full_payment_paid`, `full_payment_paid_at`, `handover_confirmed`, `handover_confirmed_at`, `delivery_proof`, `work_link`, `work_description`, `worker_completed`, `employer_completed`, `payment_requested_at`, `approved_at`, `accepted_at`, `funds_released_at`, `reservation_status`, `reserved_at`, `reserved_by`, `released_at`, `released_by`, `release_notes`, `cover_letter`, `expected_salary`) VALUES
(1, 1, 1, 1, 1000.00, 300.00, 150.00, 550.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 500.00, '2026-05-04 10:25:59', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(2, 1, 1, 1, 1000.00, 300.00, 150.00, 550.00, 0.00, 'pending', 'pending', 'pending_deposit', NULL, '2026-05-04 11:30:44', 0.00, '2026-05-04 11:30:44', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(3, 1, 1, 1, 1000.00, 300.00, 150.00, 550.00, 0.00, 'pending', 'pending', 'disputed', NULL, '2026-05-08 12:32:16', 0.00, '2026-05-04 11:35:42', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(4, 1, 1, 1, 1000.00, 300.00, 150.00, 550.00, 0.00, 'pending', 'pending', 'pending_deposit', NULL, '2026-05-04 11:46:02', 0.00, '2026-05-04 11:46:02', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(5, 5, 5, 5, 100000.00, 37000.00, 10000.00, 63000.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 47000.00, '2026-05-04 12:39:36', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(6, 6, 5, 5, 50000.00, 15000.00, 5000.00, 35000.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 20000.00, '2026-05-04 13:00:40', NULL, NULL, 0, 0, 0, 0, 1, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(7, 4, 6, 5, 120000.00, 36000.00, 18000.00, 84000.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 54000.00, '2026-05-04 13:32:53', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(8, 1, 6, 2, 1000.00, 300.00, 150.00, 700.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 900.00, '2026-05-04 14:15:10', NULL, NULL, 0, 0, 0, 0, 1, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(9, 6, 6, 5, 50000.00, 15000.00, 5000.00, 35000.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 30000.00, '2026-05-04 14:45:46', NULL, NULL, 0, 0, 0, 0, 1, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(10, 7, 5, 5, 122999.00, 36899.98, 18449.99, 86099.94, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 55349.00, '2026-05-04 17:25:07', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(11, 4, 10, 5, 120000.00, 36000.00, 18000.00, 84000.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 54000.00, '2026-05-04 17:33:58', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(12, 9, 12, 12, 49999.00, 5000.00, 5000.00, 44999.97, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 29997.00, '2026-05-04 19:49:03', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(13, 9, 11, 12, 49999.00, 5000.00, 5000.00, 44999.97, 0.00, 'pending', 'pending', 'awaiting_buyer_deposit', NULL, '2026-05-04 20:13:42', 0.00, '2026-05-04 20:13:42', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(14, 9, 11, 12, 49999.00, 5000.00, 5000.00, 44999.97, 0.00, 'pending', 'pending', 'awaiting_buyer_deposit', NULL, '2026-05-04 20:14:49', 0.00, '2026-05-04 20:14:49', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(15, 9, 11, 12, 49999.00, 5000.00, 5000.00, 44999.97, 0.00, 'pending', 'pending', 'awaiting_buyer_deposit', NULL, '2026-05-04 20:17:18', 0.00, '2026-05-04 20:17:18', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(16, 9, 11, 12, 49999.00, 5000.00, 5000.00, 44999.97, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 9999.80, '2026-05-04 20:23:31', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(17, 9, 5, 12, 49999.00, 5000.00, 5000.00, 44999.97, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 9999.80, '2026-05-05 05:22:18', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(18, 1, 5, 2, 1000.00, 300.00, 150.00, 700.00, 0.00, 'pending', 'pending', 'awaiting_buyer_deposit', NULL, '2026-05-05 14:24:36', 0.00, '2026-05-05 14:24:36', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(19, 10, 5, 5, 1300.00, 312.00, 195.00, 988.02, 0.00, 'pending', 'pending', 'awaiting_seller_deposit', NULL, '2026-05-05 14:39:12', 0.00, '2026-05-05 14:39:12', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(20, 11, 5, 5, 69.00, 7.00, 3.50, 62.97, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 30.00, '2026-05-05 15:03:43', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(21, 12, 5, 5, 2299.00, 460.00, 207.00, 1839.99, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 1332.00, '2026-05-05 15:32:13', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(22, 8, 5, 5, 800.00, 240.00, 120.00, 560.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 720.00, '2026-05-05 16:08:26', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(23, 8, 17, 5, 800.00, 240.00, 120.00, 560.00, 0.00, 'pending', 'pending', 'awaiting_buyer_deposit', NULL, '2026-05-08 14:27:13', 0.00, '2026-05-08 14:27:13', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(24, 8, 17, 5, 800.00, 240.00, 120.00, 560.00, 0.00, 'pending', 'pending', 'awaiting_buyer_deposit', NULL, '2026-05-08 14:27:41', 0.00, '2026-05-08 14:27:41', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(25, 6, 17, 5, 50000.00, 15000.00, 5000.00, 35000.00, 0.00, 'pending', 'pending', 'awaiting_buyer_deposit', NULL, '2026-05-08 14:29:36', 0.00, '2026-05-08 14:29:36', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(26, 6, 17, 5, 50000.00, 15000.00, 5000.00, 35000.00, 0.00, 'pending', 'pending', 'awaiting_buyer_deposit', NULL, '2026-05-08 14:30:40', 0.00, '2026-05-08 14:30:40', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(27, 11, 17, 5, 69.00, 7.00, 3.50, 69.97, 0.00, 'pending', 'pending', 'awaiting_seller_deposit', NULL, '2026-05-08 14:54:26', 0.00, '2026-05-08 14:54:26', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(28, 15, 17, 17, 12000.00, 3600.00, 1800.00, 8400.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 10800.00, '2026-05-08 14:59:03', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(29, 10, 17, 5, 1300.00, 312.00, 195.00, 988.02, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 1014.00, '2026-05-08 15:14:35', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(30, 15, 5, 17, 12000.00, 3600.00, 1800.00, 8400.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 16200.00, '2026-05-08 15:20:22', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(31, 15, 5, 17, 48000.00, 14400.00, 7200.00, 33600.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 43200.00, '2026-05-08 16:59:39', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(32, 15, 5, 17, 36000.00, 10800.00, 5400.00, 25200.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 16200.00, '2026-05-08 17:02:12', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(33, 12, 17, 5, 2299.00, 460.00, 207.00, 1839.99, 0.00, 'pending', 'pending', 'awaiting_buyer_deposit', NULL, '2026-05-08 17:04:57', 0.00, '2026-05-08 17:04:57', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(34, 15, 6, 17, 48000.00, 14400.00, 7200.00, 33600.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 21600.00, '2026-05-08 17:17:50', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(35, 21, 5, 17, 1200000.00, 360000.00, 180000.00, 840000.00, 0.00, 'pending', 'pending', 'awaiting_buyer_deposit', NULL, '2026-05-08 20:26:28', 0.00, '2026-05-08 20:26:28', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(36, 25, 5, 5, 1200000.00, 360000.00, 180000.00, 1200000.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 1080000.00, '2026-05-09 05:52:50', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(37, 25, 17, 5, 1200000.00, 360000.00, 180000.00, 840000.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 540000.00, '2026-05-09 06:17:16', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(38, 26, 5, 5, 1000000.00, 200000.00, 100000.00, 1000000.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 600000.00, '2026-05-09 06:43:32', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(39, 27, 5, 5, 2000000.00, 200000.00, 200000.00, 2000000.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 800000.00, '2026-05-09 06:57:50', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(40, 24, 17, 17, 4000000.00, 200000.00, 200000.00, 4000000.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 800000.00, '2026-05-09 07:04:18', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(41, 22, 5, 5, 9109211.00, 2732763.30, 1366381.65, 9109211.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 4099144.00, '2026-05-09 07:15:29', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(42, 18, 17, 17, 1200000.00, 360000.00, 180000.00, 1200000.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 540000.00, '2026-05-09 07:24:43', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(43, 28, 5, 5, 3000000.00, 150000.00, 150000.00, 3000000.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 600000.00, '2026-05-09 07:28:11', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(44, 16, 17, 17, 1200000.00, 60000.00, 60000.00, 1200000.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 240000.00, '2026-05-09 07:35:27', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(45, 29, 5, 5, 4200000.00, 1260000.00, 630000.00, 4200000.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 3780000.00, '2026-05-09 07:42:16', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(46, 30, 5, 5, 1200000.00, 360000.00, 180000.00, 1200000.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 1080000.00, '2026-05-09 07:56:44', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(47, 31, 5, 5, 3600000.00, 180000.00, 180000.00, 3600000.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 720000.00, '2026-05-09 08:08:28', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(48, 32, 5, 5, 3899999.00, 1170000.00, 585000.00, 3899999.99, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 3509998.00, '2026-05-09 08:35:16', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(49, 33, 5, 5, 4200000.00, 1260000.00, 630000.00, 4200000.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 3780000.00, '2026-05-09 08:53:17', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(50, 34, 5, 5, 2300000.00, 115000.00, 115000.00, 2300000.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 460000.00, '2026-05-09 09:05:10', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(51, 23, 5, 5, 12300000.00, 615000.00, 615000.00, 12300000.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 2460000.00, '2026-05-09 09:27:23', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(52, 36, 5, 5, 1300000.00, 130000.00, 65000.00, 1300000.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 390000.00, '2026-05-09 10:17:45', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(53, 43, 17, 5, 1200000.00, 360000.00, 36000.00, 840000.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 396000.00, '2026-05-09 13:35:40', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(54, 36, 17, 5, 1300000.00, 130000.00, 65000.00, 1170000.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 195000.00, '2026-05-09 13:43:32', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(55, 24, 5, 17, 4000000.00, 200000.00, 200000.00, 3800000.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 400000.00, '2026-05-09 16:05:01', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(56, 32, 17, 5, 3899999.00, 1170000.00, 585000.00, 2729999.99, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 3509998.00, '2026-05-09 17:10:45', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(57, 46, 1, 17, 1200000.00, 360000.00, 48000.00, 840000.00, 0.00, 'pending', 'pending', 'awaiting_buyer_deposit', NULL, '2026-05-10 07:56:27', 0.00, '2026-05-10 07:56:27', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(58, 34, 17, 5, 2300000.00, 115000.00, 115000.00, 2185000.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 230000.00, '2026-05-10 10:20:47', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(59, 27, 17, 5, 2000000.00, 200000.00, 200000.00, 1800000.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 400000.00, '2026-05-10 10:49:35', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(60, 28, 17, 5, 3000000.00, 150000.00, 150000.00, 2850000.00, 0.00, 'pending', 'pending', '', NULL, '2026-05-10 11:18:50', 300000.00, '2026-05-10 10:57:49', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(61, 30, 17, 5, 1200000.00, 360000.00, 180000.00, 840000.00, 0.00, 'pending', 'pending', 'awaiting_buyer_deposit', NULL, '2026-05-10 11:15:24', 0.00, '2026-05-10 11:15:24', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(62, 29, 17, 5, 4200000.00, 1260000.00, 630000.00, 2940000.00, 0.00, 'pending', 'pending', 'awaiting_buyer_deposit', NULL, '2026-05-10 11:27:23', 1890000.00, '2026-05-10 11:26:50', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(63, 26, 17, 5, 1000000.00, 200000.00, 100000.00, 800000.00, 0.00, 'pending', 'pending', 'awaiting_buyer_deposit', NULL, '2026-05-10 11:43:28', 300000.00, '2026-05-10 11:43:03', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(64, 31, 17, 5, 3600000.00, 180000.00, 180000.00, 3420000.00, 0.00, 'pending', 'pending', 'awaiting_buyer_deposit', NULL, '2026-05-10 11:54:18', 360000.00, '2026-05-10 11:53:57', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(65, 33, 17, 5, 4200000.00, 1260000.00, 630000.00, 2940000.00, 0.00, 'pending', 'pending', 'awaiting_buyer_deposit', NULL, '2026-05-10 11:58:01', 0.00, '2026-05-10 11:58:01', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(66, 46, 5, 17, 1200000.00, 360000.00, 48000.00, 840000.00, 0.00, 'pending', 'pending', 'awaiting_buyer_deposit', NULL, '2026-06-01 07:58:15', 0.00, '2026-06-01 07:58:15', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(67, 33, 18, 5, 4200000.00, 1260000.00, 630000.00, 2940000.00, 0.00, 'pending', 'pending', 'deposits_complete', NULL, '2026-06-01 10:16:02', 5670000.00, '2026-06-01 10:06:49', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(68, 43, 19, 5, 1200000.00, 360000.00, 36000.00, 840000.00, 0.00, 'pending', 'pending', 'completed', NULL, '2026-06-01 11:21:05', 792000.00, '2026-06-01 10:43:44', NULL, '2026-06-01 11:21:05', 0, 0, 1, 0, 0, 0, NULL, NULL, 1, 0, 0, 0, 'deposit_only', 'released', NULL, 7, '2026-06-01 14:21:05', NULL, '2026-06-01 14:21:05', 0, NULL, 'auto', 'auto', 'delivered', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 1, '2026-06-01 14:16:13', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(69, 36, 19, 5, 1300000.00, 130000.00, 65000.00, 1170000.00, 0.00, 'pending', 'pending', 'completed', NULL, '2026-06-01 11:33:04', 390000.00, '2026-06-01 11:31:16', NULL, '2026-06-01 11:33:04', 0, 0, 1, 0, 0, 0, NULL, NULL, 1, 0, 0, 0, 'deposit_only', 'released', '2026-06-06 13:31:37', 5, '2026-06-01 14:33:04', NULL, '2026-06-01 14:33:04', 0, NULL, 'auto', 'auto', 'delivered', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 1, '2026-06-01 14:32:40', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(70, 30, 19, 5, 1200000.00, 360000.00, 180000.00, 840000.00, 0.00, 'pending', 'pending', 'completed', NULL, '2026-06-01 11:37:22', 1620000.00, '2026-06-01 11:35:09', NULL, '2026-06-01 11:37:22', 0, 0, 1, 0, 0, 0, NULL, NULL, 1, 0, 0, 0, 'deposit_only', 'released', '2026-06-06 13:35:30', 5, '2026-06-01 14:37:22', NULL, '2026-06-01 14:37:22', 0, NULL, 'auto', 'auto', 'delivered', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 1, '2026-06-01 14:36:03', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(71, 28, 19, 5, 3000000.00, 150000.00, 150000.00, 2850000.00, 0.00, 'pending', 'pending', 'completed', NULL, '2026-06-01 11:53:20', 600000.00, '2026-06-01 11:51:10', NULL, '2026-06-01 11:53:20', 0, 0, 1, 0, 0, 0, NULL, NULL, 1, 0, 0, 0, 'deposit_only', 'released', '2026-06-06 13:51:37', 5, '2026-06-01 14:53:20', NULL, '2026-06-01 14:53:20', 0, NULL, 'auto', 'auto', 'delivered', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 1, '2026-06-01 14:52:51', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(72, 34, 19, 5, 2300000.00, 115000.00, 115000.00, 2185000.00, 0.00, 'pending', 'pending', 'deposits_complete', NULL, '2026-06-01 12:18:34', 11385000.00, '2026-06-01 12:07:58', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 1, 0, 0, 0, 'deposit_only', 'active', '2026-06-06 14:08:16', 5, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'delivered', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 1, '2026-06-01 15:09:17', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(73, 33, 19, 5, 4200000.00, 1260000.00, 630000.00, 2940000.00, 0.00, 'pending', 'pending', 'awaiting_buyer_deposit', NULL, '2026-06-01 15:57:53', 0.00, '2026-06-01 15:57:53', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(74, 29, 19, 5, 4200000.00, 1260000.00, 630000.00, 2940000.00, 0.00, 'pending', 'pending', 'deposits_complete', NULL, '2026-06-01 16:39:14', 7770000.00, '2026-06-01 16:24:06', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 1, 1, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'delivered', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 1, '2026-06-01 19:39:14', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(75, 26, 19, 5, 1000000.00, 200000.00, 100000.00, 0.00, 0.00, 'pending', 'pending', 'completed', NULL, '2026-06-01 16:54:39', 1100000.00, '2026-06-01 16:40:57', NULL, '2026-06-01 16:54:39', 0, 0, 1, 0, 0, 0, NULL, NULL, 1, 1, 0, 0, 'deposit_only', 'released', '2026-06-06 18:43:42', 5, NULL, NULL, '2026-06-01 19:54:39', 0, NULL, 'auto', 'auto', 'delivered', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 1, '2026-06-01 19:53:36', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(76, 31, 19, 5, 3600000.00, 180000.00, 180000.00, 3420000.00, 0.00, 'pending', 'pending', 'completed', NULL, '2026-06-01 16:53:08', 10620000.00, '2026-06-01 16:44:56', NULL, '2026-06-01 16:53:08', 0, 0, 1, 0, 0, 0, NULL, NULL, 1, 1, 0, 0, 'deposit_only', 'released', '2026-06-06 18:44:56', 5, NULL, NULL, '2026-06-01 19:53:08', 0, NULL, 'auto', 'auto', 'delivered', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 1, '2026-06-01 19:45:51', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(77, 36, 20, 5, 1300000.00, 130000.00, 65000.00, 1170000.00, 0.00, 'pending', 'pending', 'in_progress', NULL, '2026-06-01 17:06:30', 195000.00, '2026-06-01 17:06:30', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', '2026-06-06 19:06:30', 5, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(78, 33, 20, 5, 4200000.00, 1260000.00, 630000.00, 2940000.00, 0.00, 'pending', 'pending', 'completed', NULL, '2026-06-01 17:13:18', 4830000.00, '2026-06-01 17:10:03', NULL, '2026-06-01 17:13:18', 0, 0, 1, 0, 0, 0, NULL, NULL, 0, 1, 0, 0, 'deposit_only', 'released', NULL, 7, NULL, NULL, '2026-06-01 20:13:18', 0, NULL, 'auto', 'auto', 'delivered', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 1, '2026-06-01 20:12:31', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(79, 31, 20, 5, 3600000.00, 180000.00, 180000.00, 3420000.00, 0.00, 'pending', 'pending', 'disputed', NULL, '2026-06-02 05:06:19', 360000.00, '2026-06-01 17:21:19', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', '2026-06-06 19:21:24', 5, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(80, 46, 19, 17, 1200000.00, 360000.00, 48000.00, 840000.00, 0.00, 'pending', 'pending', 'in_progress', NULL, '2026-06-01 17:30:11', 408000.00, '2026-06-01 17:29:57', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', '2026-06-06 19:30:11', 5, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(81, 28, 20, 5, 3000000.00, 150000.00, 150000.00, 2850000.00, 0.00, 'pending', 'pending', 'disputed', NULL, '2026-06-02 05:08:13', 300000.00, '2026-06-01 17:31:53', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', '2026-06-06 19:32:14', 5, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(82, 34, 20, 5, 2300000.00, 115000.00, 115000.00, 2185000.00, 0.00, 'pending', 'pending', 'in_progress', NULL, '2026-06-01 17:33:18', 230000.00, '2026-06-01 17:33:04', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', '2026-06-06 19:33:18', 5, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(83, 30, 20, 5, 1200000.00, 360000.00, 180000.00, 840000.00, 0.00, 'pending', 'pending', 'completed', NULL, '2026-06-01 17:44:10', 1380000.00, '2026-06-01 17:41:58', NULL, '2026-06-01 17:44:10', 0, 0, 1, 0, 0, 0, NULL, NULL, 0, 1, 0, 0, 'deposit_only', 'released', NULL, 7, NULL, NULL, '2026-06-01 20:44:10', 0, NULL, 'auto', 'auto', 'delivered', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 1, '2026-06-01 20:43:20', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(84, 43, 20, 5, 1200000.00, 360000.00, 36000.00, 840000.00, 0.00, 'pending', 'pending', 'completed', NULL, '2026-06-02 05:00:05', 1236000.00, '2026-06-02 04:56:12', NULL, '2026-06-02 05:00:05', 0, 0, 1, 0, 0, 0, NULL, NULL, 0, 1, 0, 0, 'deposit_only', 'released', NULL, 7, NULL, NULL, '2026-06-02 08:00:05', 0, NULL, 'auto', 'auto', 'delivered', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 1, '2026-06-02 07:59:04', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(85, 27, 20, 5, 2000000.00, 200000.00, 200000.00, 1800000.00, 0.00, 'pending', 'pending', 'completed', NULL, '2026-06-02 07:03:39', 2200000.00, '2026-06-02 05:19:14', NULL, '2026-06-02 07:03:39', 0, 0, 1, 0, 0, 0, NULL, NULL, 0, 1, 0, 0, 'deposit_only', 'released', NULL, 7, NULL, NULL, '2026-06-02 10:03:39', 0, NULL, 'auto', 'auto', 'delivered', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 1, '2026-06-02 10:03:04', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(86, 24, 20, 17, 4000000.00, 200000.00, 200000.00, 3800000.00, 0.00, 'pending', 'pending', 'in_progress', NULL, '2026-06-02 12:47:39', 400000.00, '2026-06-02 12:46:28', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(87, 21, 20, 17, 1200000.00, 360000.00, 180000.00, 840000.00, 0.00, 'pending', 'pending', 'awaiting_buyer_deposit', NULL, '2026-06-02 12:48:09', 0.00, '2026-06-02 12:48:09', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(88, 46, 20, 17, 1200000.00, 360000.00, 48000.00, 840000.00, 0.00, 'pending', 'pending', 'awaiting_buyer_deposit', NULL, '2026-06-02 16:55:08', 0.00, '2026-06-02 16:55:08', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(89, 31, 21, 5, 3600000.00, 180000.00, 180000.00, 3240000.00, 360000.00, 'deposit_paid', 'pending', '', NULL, '2026-06-02 17:26:47', 720000.00, '2026-06-02 16:59:52', '2026-06-02 20:14:28', NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 1, 0, 0, 'deposit_only', 'active', '2026-06-07 19:14:28', 5, NULL, '2026-06-02 20:26:47', NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(90, 46, 21, 17, 1200000.00, 360000.00, 48000.00, 792000.00, 408000.00, 'deposit_paid', 'pending', '', NULL, '2026-06-02 17:19:13', 816000.00, '2026-06-02 17:18:16', '2026-06-02 20:19:13', NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'fully_held', '2026-06-07 19:19:11', 5, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(91, 33, 21, 5, 4200000.00, 1260000.00, 630000.00, 0.00, 4200000.00, 'fully_paid', 'pending', '', NULL, '2026-06-02 18:32:46', 8400000.00, '2026-06-02 17:43:05', '2026-06-02 21:32:46', '2026-06-02 18:06:32', 0, 0, 0, 0, 0, 0, NULL, NULL, 1, 1, 0, 0, 'deposit_only', 'active', '2026-06-07 20:32:46', 5, '2026-06-02 21:06:32', '2026-06-02 21:04:55', '2026-06-02 21:06:32', 0, NULL, 'auto', 'dual_confirm', 'pending', NULL, '2026-06-02 21:06:32', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(92, 21, 21, 17, 1200000.00, 360000.00, 180000.00, 1200000.00, 0.00, 'pending', 'pending', 'awaiting_buyer_deposit', NULL, '2026-06-02 18:22:23', 0.00, '2026-06-02 18:22:23', '2026-06-02 21:22:23', NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(94, 14, 5, 16, 36001.00, 0.00, 5400.15, 36001.00, 0.00, 'pending', 'pending', 'awaiting_buyer_deposit', NULL, '2026-06-02 20:00:09', 0.00, '2026-06-02 20:00:09', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'iokxn;NXKK', NULL),
(95, 14, 21, 16, 36001.00, 0.00, 5400.15, 36001.00, 0.00, 'pending', 'pending', 'awaiting_buyer_deposit', NULL, '2026-06-02 20:03:15', 5400.00, '2026-06-02 20:02:31', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'PKQewgapkd&#039;oigaen;mka[j0csm EWIOAMAPOSMp&#039;moamPOWElkkkkkkkkk', NULL),
(96, 13, 5, 15, 32001.00, 0.00, 4800.15, 32001.00, 0.00, 'pending', 'pending', 'awaiting_buyer_deposit', NULL, '2026-06-02 20:06:10', 4800.00, '2026-06-02 20:05:46', NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'pending', NULL, 7, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'mmkodddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd', NULL),
(97, 14, 20, 16, 36001.00, 0.00, 5400.15, 30601.00, 5400.00, '', 'pending', '', NULL, '2026-06-02 20:14:23', 10800.00, '2026-06-02 20:13:51', '2026-06-02 23:14:23', NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 'deposit_only', 'active', '2026-06-12 22:14:23', 10, NULL, NULL, NULL, 0, NULL, 'auto', 'auto', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'konw kef kf k &#039;; FMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMM', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `transaction_timeline`
--

CREATE TABLE `transaction_timeline` (
  `id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `status` varchar(50) NOT NULL,
  `action` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `performed_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transaction_timeline`
--

INSERT INTO `transaction_timeline` (`id`, `transaction_id`, `status`, `action`, `description`, `performed_by`, `created_at`) VALUES
(1, 68, 'delivered', 'delivered', 'Seller marked as delivered: I have delivered it to the seller', 5, '2026-06-01 14:16:13'),
(2, 68, 'payment_released', 'payment released', 'Payment of 1,164,000.00 ETB released to seller', 19, '2026-06-01 14:21:05'),
(3, 69, 'payment_confirmed', 'payment confirmed', 'Payment of 195,000.00 ETB confirmed. Escrow activated.', 19, '2026-06-01 14:31:37'),
(4, 69, 'delivered', 'delivered', 'Seller marked as delivered', 5, '2026-06-01 14:32:40'),
(5, 69, 'payment_released', 'payment released', 'Payment of 1,235,000.00 ETB released to seller', 19, '2026-06-01 14:33:04'),
(6, 70, 'payment_confirmed', 'payment confirmed', 'Payment of 540,000.00 ETB confirmed. Escrow activated.', 19, '2026-06-01 14:35:30'),
(7, 70, 'delivered', 'delivered', 'Seller marked as delivered', 5, '2026-06-01 14:36:03'),
(8, 70, 'payment_released', 'payment released', 'Payment of 1,020,000.00 ETB released to seller', 19, '2026-06-01 14:37:22'),
(9, 71, 'payment_confirmed', 'payment confirmed', 'Payment of 300,000.00 ETB confirmed. Escrow activated.', 19, '2026-06-01 14:51:37'),
(10, 71, 'delivered', 'delivered', 'Seller marked as delivered: kKPom', 5, '2026-06-01 14:52:51'),
(11, 71, 'payment_released', 'payment released', 'Payment of 2,850,000.00 ETB released to seller', 19, '2026-06-01 14:53:20'),
(12, 72, 'payment_confirmed', 'payment confirmed', 'Payment of 230,000.00 ETB confirmed. Escrow activated.', 19, '2026-06-01 15:08:16'),
(13, 72, 'delivered', 'delivered', 'Seller marked as delivered', 5, '2026-06-01 15:09:17'),
(14, 74, 'delivered', 'delivered', 'Seller marked as delivered', 5, '2026-06-01 19:26:28'),
(15, 74, 'delivered', 'delivered', 'Seller marked as delivered', 5, '2026-06-01 19:35:00'),
(16, 74, 'delivered', 'delivered', 'Seller marked as delivered', 5, '2026-06-01 19:39:14'),
(17, 75, 'payment_confirmed', 'payment confirmed', 'Initial payment 300,000.00 ETB confirmed. Escrow activated.', 19, '2026-06-01 19:43:42'),
(18, 76, 'payment_confirmed', 'payment confirmed', 'Initial payment 360,000.00 ETB confirmed. Escrow activated.', 19, '2026-06-01 19:44:56'),
(19, 76, 'delivered', 'delivered', 'Seller marked as delivered', 5, '2026-06-01 19:45:51'),
(20, 76, 'buyer_confirmed_delivery', 'buyer confirmed delivery', 'Buyer confirmed receipt of delivery.', 19, '2026-06-01 19:46:20'),
(21, 76, 'payment_released', 'payment released', 'Payment of 3,420,000.00 ETB released to seller', 19, '2026-06-01 19:53:08'),
(22, 75, 'delivered', 'delivered', 'Seller marked as delivered', 5, '2026-06-01 19:53:36'),
(23, 75, 'buyer_confirmed_delivery', 'buyer confirmed delivery', 'Buyer confirmed receipt of delivery. I accepted', 19, '2026-06-01 19:54:17'),
(24, 75, 'remaining_balance_paid', 'remaining balance paid', 'Remaining balance 800,000.00 ETB paid via Telebirr', 19, '2026-06-01 19:54:17'),
(25, 75, 'payment_released', 'payment released', 'Payment of 900,000.00 ETB released to seller', 19, '2026-06-01 19:54:39'),
(26, 77, 'payment_confirmed', 'payment confirmed', 'Initial payment 195,000.00 ETB confirmed. Escrow activated.', 20, '2026-06-01 20:06:30'),
(27, 78, 'delivered', 'delivered', 'Seller marked as delivered: I delive to solomon', 5, '2026-06-01 20:12:31'),
(28, 78, 'payment_released', 'payment released', 'Payment of 3,570,000.00 ETB released to seller', 20, '2026-06-01 20:13:18'),
(29, 79, 'payment_confirmed', 'payment confirmed', 'Initial payment 360,000.00 ETB confirmed. Escrow activated.', 20, '2026-06-01 20:21:24'),
(30, 80, 'payment_confirmed', 'payment confirmed', 'Initial payment 408,000.00 ETB confirmed. Escrow activated.', 19, '2026-06-01 20:30:11'),
(31, 81, 'payment_confirmed', 'payment confirmed', 'Initial payment 300,000.00 ETB confirmed. Escrow activated.', 20, '2026-06-01 20:32:14'),
(32, 82, 'payment_confirmed', 'payment confirmed', 'Initial payment 230,000.00 ETB confirmed. Escrow activated.', 20, '2026-06-01 20:33:18'),
(33, 83, 'delivered', 'delivered', 'Seller marked as delivered', 5, '2026-06-01 20:43:20'),
(34, 83, 'payment_released', 'payment released', 'Payment of 1,020,000.00 ETB released to seller', 20, '2026-06-01 20:44:10'),
(35, 84, 'delivered', 'delivered', 'Seller marked as delivered: rjneapeaohsronj', 5, '2026-06-02 07:59:04'),
(36, 84, 'payment_released', 'payment released', 'Payment of 1,164,000.00 ETB released to seller', 20, '2026-06-02 08:00:05'),
(37, 85, 'delivered', 'delivered', 'Seller marked as delivered', 5, '2026-06-02 10:03:04'),
(38, 85, 'payment_released', 'payment released', 'Payment of 1,800,000.00 ETB released to seller', 20, '2026-06-02 10:03:39'),
(39, 89, 'payment_confirmed', 'payment confirmed', 'Payment of 360,000.00 ETB confirmed. Escrow activated.', 21, '2026-06-02 20:14:28'),
(40, 90, 'payment_confirmed', 'payment confirmed', 'Payment of 408,000.00 ETB confirmed. Escrow activated.', 21, '2026-06-02 20:19:11'),
(41, 89, 'seller_confirmed_delivery', 'seller confirmed delivery', 'Seller confirmed delivery. Notes: Sent', 5, '2026-06-02 20:26:47'),
(42, 91, 'payment_confirmed', 'payment confirmed', 'Payment of 1,890,000.00 ETB confirmed. Escrow activated.', 21, '2026-06-02 20:43:27'),
(43, 91, 'seller_confirmed_delivery', 'seller confirmed delivery', 'Seller confirmed delivery. Notes: I have given the car to Biniam', 5, '2026-06-02 21:04:55'),
(44, 91, 'buyer_confirmed_receipt', 'buyer confirmed receipt', 'Buyer confirmed receipt. Notes: I have received', 21, '2026-06-02 21:06:32'),
(45, 91, 'payment_released', 'payment released', 'Payment of 3,570,000.00 ETB released to seller', 21, '2026-06-02 21:06:32'),
(46, 91, 'payment_confirmed', 'payment confirmed', 'Payment of 2,310,000.00 ETB confirmed. Escrow activated.', 21, '2026-06-02 21:32:46'),
(47, 97, 'payment_confirmed', 'payment confirmed', 'Payment of 5,400.00 ETB confirmed. Escrow activated.', 20, '2026-06-02 23:14:23');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `role` enum('admin','user','company') DEFAULT 'user',
  `balance` decimal(12,2) DEFAULT 0.00,
  `escrow_held` decimal(12,2) DEFAULT 0.00,
  `is_verified` tinyint(1) DEFAULT 0,
  `is_suspended` tinyint(1) DEFAULT 0,
  `kyc_document` varchar(255) DEFAULT NULL,
  `email_verified` tinyint(1) DEFAULT 0,
  `verification_token` varchar(100) DEFAULT NULL,
  `reset_token` varchar(100) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'Ethiopia',
  `profile_image` varchar(255) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` enum('male','female','other') DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `ban_reason` text DEFAULT NULL,
  `banned_at` datetime DEFAULT NULL,
  `total_transactions` int(11) DEFAULT 0,
  `total_spent` decimal(12,2) DEFAULT 0.00,
  `pin` varchar(10) DEFAULT '1234',
  `admin_balance` decimal(12,2) DEFAULT 0.00,
  `normalized_phone` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `email_verified_at` datetime DEFAULT NULL,
  `phone_verified_at` datetime DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `age` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `email`, `password_hash`, `phone`, `role`, `balance`, `escrow_held`, `is_verified`, `is_suspended`, `kyc_document`, `email_verified`, `verification_token`, `reset_token`, `reset_expires`, `created_at`, `updated_at`, `address`, `city`, `country`, `profile_image`, `date_of_birth`, `gender`, `last_login`, `ban_reason`, `banned_at`, `total_transactions`, `total_spent`, `pin`, `admin_balance`, `normalized_phone`, `is_active`, `email_verified_at`, `phone_verified_at`, `first_name`, `last_name`, `age`) VALUES
(1, 'Administrator', 'admin@brokerplace.com', '$2y$10$RS.F/UnUc7fIdRi66bVNhuWhRAHMJANJjzadHSCnT0qRmqM.cpT1i', '0992116527', 'admin', 0.00, 0.00, 1, 0, NULL, 1, NULL, NULL, NULL, '2026-05-04 06:24:52', '2026-06-02 21:14:28', 'Ethiopian', 'D/B', 'Ethiopia', NULL, NULL, NULL, '2026-06-03 00:14:28', NULL, NULL, 0, 0.00, '1234', 0.00, '+251992116527', 1, NULL, NULL, NULL, NULL, NULL),
(2, 'Demo User', 'user@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '+251912345678', 'user', 4850.00, 0.00, 1, 0, NULL, 0, NULL, NULL, NULL, '2026-05-04 06:24:52', '2026-06-02 18:56:04', NULL, NULL, 'Ethiopia', NULL, NULL, NULL, NULL, NULL, NULL, 0, 0.00, '1234', 0.00, '+251912345678', 1, NULL, NULL, NULL, NULL, NULL),
(3, 'Demo Company', 'company@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '+251923456789', 'company', 0.00, 0.00, 1, 0, NULL, 0, NULL, NULL, NULL, '2026-05-04 06:24:52', '2026-06-02 18:56:04', NULL, NULL, 'Ethiopia', NULL, NULL, NULL, NULL, NULL, NULL, 0, 0.00, '1234', 0.00, '+251923456789', 1, NULL, NULL, NULL, NULL, NULL),
(5, 'Mesfin Haileslassie', 'mesfinhaileslassie17@gmail.com', '$2y$10$AGeS.ZjrZ/3YJFSABhkjKObnJtPlnLGZEJOhr5lqEaUVJxalzn4Na', '+251992116527', 'user', 21613000.00, 0.00, 1, 0, NULL, 0, NULL, NULL, NULL, '2026-05-04 08:04:37', '2026-06-02 21:09:05', 'Ethiopian', 'Gonder', 'Ethiopia', NULL, NULL, 'male', '2026-06-03 00:09:05', NULL, NULL, 0, 0.00, '1234', 0.00, '+251992116527', 1, NULL, NULL, 'Mesfin', 'Haileslassie', 22),
(6, 'Biniam Emishaw', 'bini@gmail.com', '$2y$10$kTYkjzX7LyWA1SbCGAxuKOxnY0.4vIgKExXGMqbXsM/c4956WjJhG', '+251912345678', 'user', 100.00, 0.00, 1, 0, NULL, 0, NULL, NULL, NULL, '2026-05-04 08:13:17', '2026-06-02 18:56:04', NULL, NULL, 'Ethiopia', NULL, NULL, NULL, '2026-05-08 20:17:28', NULL, NULL, 0, 0.00, '1234', 0.00, '+251912345678', 1, NULL, NULL, NULL, NULL, NULL),
(7, 'Mesfin', 'mesfin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '+251992116527', 'user', 50000.00, 0.00, 1, 0, NULL, 0, NULL, NULL, NULL, '2026-05-04 11:48:37', '2026-06-02 18:56:04', NULL, NULL, 'Ethiopia', NULL, NULL, NULL, NULL, NULL, NULL, 0, 0.00, '1234', 0.00, '+251992116527', 1, NULL, NULL, NULL, NULL, NULL),
(8, 'Mesfin', 'mesfin@telebirr.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '+251992116527', 'user', 50000.00, 0.00, 1, 0, NULL, 0, NULL, NULL, NULL, '2026-05-04 11:49:21', '2026-06-02 18:56:04', NULL, NULL, 'Ethiopia', NULL, NULL, NULL, NULL, NULL, NULL, 0, 0.00, '1234', 0.00, '+251992116527', 1, NULL, NULL, NULL, NULL, NULL),
(9, 'User_0501', 'user_0501@temp.com', '', '+251988400501', 'user', 0.00, 0.00, 1, 0, NULL, 0, NULL, NULL, NULL, '2026-05-04 14:07:00', '2026-06-02 18:56:04', NULL, NULL, 'Ethiopia', NULL, NULL, NULL, NULL, NULL, NULL, 0, 0.00, '1234', 0.00, '+251988400501', 1, NULL, NULL, NULL, NULL, NULL),
(10, 'Biniam Emishaw', 'bini12@gmail.com', '$2y$10$BO17SMjrt3o1ph2iKFeJGORqhL0bSyn.z.JE2P7KWf5YmBJgF8U.G', '0992116528', 'user', 100.00, 0.00, 1, 0, NULL, 0, NULL, NULL, NULL, '2026-05-04 17:32:44', '2026-06-02 18:56:04', NULL, NULL, 'Ethiopia', NULL, NULL, NULL, '2026-05-04 20:32:44', NULL, NULL, 0, 0.00, '1234', 0.00, '+251992116528', 1, NULL, NULL, NULL, NULL, NULL),
(11, 'Seller one', 'seller@gmail.com', '$2y$10$TwoALz.uA26REWWX3OYNcej8AI0prXiDwdQNldCSVg9r6gKH0c6Uq', '0992116529', 'user', 100.00, 0.00, 1, 0, NULL, 0, NULL, NULL, NULL, '2026-05-04 19:39:01', '2026-06-02 18:56:04', NULL, NULL, 'Ethiopia', NULL, NULL, NULL, '2026-05-04 23:13:25', NULL, NULL, 0, 0.00, '1234', 0.00, '+251992116529', 1, NULL, NULL, NULL, NULL, NULL),
(12, 'Buyer one', 'buyer@gmail.com', '$2y$10$LHuuQer12dggvCcOvsb4iuYckkKB0KACdttUqHFtgsoz1qzGvuyUe', '0992116522', 'user', 100.00, 0.00, 1, 0, NULL, 0, NULL, NULL, NULL, '2026-05-04 19:40:40', '2026-06-02 18:56:04', NULL, NULL, 'Ethiopia', NULL, NULL, NULL, '2026-05-04 23:26:29', NULL, NULL, 0, 0.00, '1234', 0.00, '+251992116522', 1, NULL, NULL, NULL, NULL, NULL),
(13, 'MHA', 'Comp@gmail.com', '$2y$10$5oDJdiqR/y4KfEUFBbf7Nevy54n22FM0tc5Nv.WHjQffCs5Fs0zRm', '0992116527', 'user', 100.00, 0.00, 1, 0, NULL, 0, NULL, NULL, NULL, '2026-05-06 18:22:33', '2026-06-02 18:56:04', NULL, NULL, 'Ethiopia', NULL, NULL, NULL, '2026-05-06 21:22:33', NULL, NULL, 0, 0.00, '1234', 0.00, '+251992116527', 1, NULL, NULL, NULL, NULL, NULL),
(15, 'Mesfin Haileslassie', 'mesfinhaileslassie7@gmail.com', '$2y$10$YquyMm3UUa5SfjpwVnR6LunwI4d.BSyN3Aj7MF6WqhCTpHNAFkGyS', '+251992116527', 'company', 100.00, 0.00, 0, 0, NULL, 0, NULL, NULL, NULL, '2026-05-06 18:51:04', '2026-06-02 18:56:04', NULL, NULL, 'Ethiopia', NULL, NULL, NULL, '2026-05-06 21:51:04', NULL, NULL, 0, 0.00, '1234', 0.00, '+251992116527', 1, NULL, NULL, NULL, NULL, NULL),
(16, 'Mesfin Haileslassie', 'techeth100@gmail.com', '$2y$10$xer0HG/ow3N0k8ub9/XeMe7gVWEGJGPhcEA2/gpfWHHpTzlipl/P2', '+251992116527', 'company', 100.00, 0.00, 1, 0, NULL, 0, NULL, NULL, NULL, '2026-05-06 19:23:20', '2026-06-02 18:56:04', NULL, NULL, 'Ethiopia', NULL, NULL, NULL, '2026-05-06 22:46:45', NULL, NULL, 0, 0.00, '1234', 0.00, '+251992116527', 1, NULL, NULL, NULL, NULL, NULL),
(17, 'Abebe Alemu', 'ab@gmail.com', '$2y$10$Da5yU98v.IRQe8GJMQxqbOZ2.VcK9tQ29N8zyG1Di3/YT5BdUoD7e', '+251992116524', 'user', 435200.00, 0.00, 1, 0, NULL, 0, NULL, NULL, NULL, '2026-05-08 14:27:02', '2026-06-02 18:56:04', NULL, NULL, 'Ethiopia', NULL, NULL, NULL, '2026-05-10 15:12:17', NULL, NULL, 0, 0.00, '1234', 0.00, '+251992116524', 1, NULL, NULL, NULL, NULL, NULL),
(18, 'M H', 'mesfinhaileslassie10@gmail.com', '$2y$10$4Ge/U7dzi1oz1FiKDcd/R.pAvYJ5qs/lCDNc1EoWj76XhKBSr3x/S', '+251992116527', 'user', 0.00, 0.00, 0, 0, NULL, 0, NULL, NULL, NULL, '2026-06-01 10:06:28', '2026-06-02 18:56:04', NULL, NULL, 'Ethiopia', NULL, NULL, NULL, '2026-06-01 13:06:28', NULL, NULL, 0, 0.00, '1234', 0.00, '+251992116527', 1, NULL, NULL, NULL, NULL, NULL),
(19, 'H M', 'mesfinhaileslassie19@gmail.com', '$2y$10$RJaBClULRFKdnBNSEBwQtOAc9zPkYuBRDOvigbtl3Ygy3eerW06ve', '+251992116527', 'user', 0.00, 0.00, 0, 0, NULL, 0, NULL, NULL, NULL, '2026-06-01 10:43:12', '2026-06-02 18:56:04', NULL, NULL, 'Ethiopia', NULL, NULL, NULL, '2026-06-01 20:29:51', NULL, NULL, 0, 0.00, '1234', 0.00, '+251992116527', 1, NULL, NULL, NULL, NULL, NULL),
(20, 'Solomon Alemayehu', 'sol@gmail.com', '$2y$10$nG/UdLcNa7Omy8jM8n6fLuQeWKys5/mN2xENwDZiuRPqMHgqLSfMC', '+251992114566', 'user', 0.00, 0.00, 0, 0, NULL, 0, NULL, NULL, NULL, '2026-06-01 17:06:20', '2026-06-02 20:13:51', NULL, NULL, 'Ethiopia', NULL, NULL, 'male', '2026-06-02 23:12:11', NULL, NULL, 0, 0.00, '1234', 0.00, NULL, 1, NULL, NULL, 'Solomon', 'Alemayehu', 23),
(21, 'Mesfin Haileslassie', 'bini10@gmail.com', '$2y$10$zycbSOztYbZhSVekf/UNPevzC9u2dG6TZjjL2nlRxqz8ASlB/G9Tm', '+251992116527', 'user', 0.00, 0.00, 0, 0, NULL, 0, NULL, NULL, NULL, '2026-06-02 16:59:38', '2026-06-02 20:02:31', NULL, NULL, 'Ethiopia', NULL, NULL, 'male', '2026-06-02 21:59:07', NULL, NULL, 0, 0.00, '1234', 0.00, '+251992116528', 1, NULL, NULL, 'Mesfin', 'Haileslassie', 23),
(22, 'broker', 'admin2@brokerplace.com', '$2y$10$RS.F/UnUc7fIdRi66bVNhuWhRAHMJANJjzadHSCnT0q...', '+251987654321', 'admin', 0.00, 0.00, 1, 0, NULL, 1, NULL, NULL, NULL, '2026-06-02 21:13:26', '2026-06-02 21:13:26', NULL, NULL, 'Ethiopia', NULL, NULL, NULL, NULL, NULL, NULL, 0, 0.00, '1234', 0.00, NULL, 1, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_activity_logs`
--

CREATE TABLE `user_activity_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `activity_type` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_notification_settings`
--

CREATE TABLE `user_notification_settings` (
  `user_id` int(11) NOT NULL,
  `chat_notifications` tinyint(1) DEFAULT 1,
  `email_notifications` tinyint(1) DEFAULT 1,
  `sound_enabled` tinyint(1) DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_notification_settings`
--

INSERT INTO `user_notification_settings` (`user_id`, `chat_notifications`, `email_notifications`, `sound_enabled`, `updated_at`) VALUES
(1, 1, 1, 1, '2026-05-05 12:11:37'),
(2, 1, 1, 1, '2026-05-05 12:11:37'),
(3, 1, 1, 1, '2026-05-05 12:11:37'),
(5, 1, 1, 1, '2026-05-05 12:11:37'),
(6, 1, 1, 1, '2026-05-05 12:11:37'),
(7, 1, 1, 1, '2026-05-05 12:11:37'),
(8, 1, 1, 1, '2026-05-05 12:11:37'),
(9, 1, 1, 1, '2026-05-05 12:11:37'),
(10, 1, 1, 1, '2026-05-05 12:11:37'),
(11, 1, 1, 1, '2026-05-05 12:11:37'),
(12, 1, 1, 1, '2026-05-05 12:11:37');

-- --------------------------------------------------------

--
-- Table structure for table `user_sessions`
--

CREATE TABLE `user_sessions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `session_token` varchar(255) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `last_activity` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_tokens`
--

CREATE TABLE `user_tokens` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_tokens`
--

INSERT INTO `user_tokens` (`id`, `user_id`, `token`, `expires_at`, `created_at`) VALUES
(1, 5, '07d3f2d2c705b7009e1484b45f425643450fc81de1f448f1b2e3182d0c399de1', '2026-06-05 22:18:36', '2026-05-06 20:18:36'),
(2, 1, '4cad56cce3936bf4d812bf459735a303667ca6e6c7711e843323463f6d775b50', '2026-06-09 12:59:11', '2026-05-10 10:59:11'),
(3, 17, '66210e19a7fa536bbc18d452438b0c82f68e26c5c6e9d2cb18e50a563d1e35a3', '2026-06-09 13:14:39', '2026-05-10 11:14:39');

-- --------------------------------------------------------

--
-- Table structure for table `user_trust_scores`
--

CREATE TABLE `user_trust_scores` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `score` int(11) DEFAULT 50,
  `total_listings` int(11) DEFAULT 0,
  `completed_transactions` int(11) DEFAULT 0,
  `successful_negotiations` int(11) DEFAULT 0,
  `cancelled_negotiations` int(11) DEFAULT 0,
  `avg_commission_agreed` decimal(5,2) DEFAULT NULL,
  `last_calculated` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wallet_transactions`
--

CREATE TABLE `wallet_transactions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `type` enum('deposit','withdrawal','withdrawal_pending','withdrawal_approved','withdrawal_rejected','withdrawal_failed','withdrawal_completed','withdrawal_refund','payment_sent','payment_received','refund','commission') NOT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('pending','completed','failed') DEFAULT 'completed',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `wallet_transactions`
--

INSERT INTO `wallet_transactions` (`id`, `user_id`, `amount`, `type`, `reference_id`, `reference_type`, `description`, `status`, `created_at`) VALUES
(1, 6, 100.00, 'deposit', NULL, NULL, 'Welcome bonus', 'completed', '2026-05-04 08:13:17'),
(2, 10, 100.00, 'deposit', NULL, NULL, 'Welcome bonus', 'completed', '2026-05-04 17:32:44'),
(3, 11, 100.00, 'deposit', NULL, NULL, 'Welcome bonus', 'completed', '2026-05-04 19:39:01'),
(4, 12, 100.00, 'deposit', NULL, NULL, 'Welcome bonus', 'completed', '2026-05-04 19:40:40'),
(5, 13, 100.00, 'deposit', NULL, NULL, 'Welcome bonus', 'completed', '2026-05-06 18:22:33'),
(6, 15, 100.00, 'deposit', NULL, NULL, 'Welcome bonus', 'completed', '2026-05-06 18:51:04'),
(7, 16, 100.00, 'deposit', NULL, NULL, 'Welcome bonus', 'completed', '2026-05-06 19:23:20'),
(8, 17, 100.00, 'deposit', NULL, NULL, 'Welcome bonus', 'completed', '2026-05-08 14:27:02'),
(9, 17, 435100.00, '', NULL, NULL, 'Admin added funds', 'completed', '2026-05-10 08:27:19'),
(10, 18, 100.00, 'deposit', NULL, NULL, 'Welcome bonus', 'completed', '2026-06-01 10:06:28'),
(11, 19, 100.00, 'deposit', NULL, NULL, 'Welcome bonus', 'completed', '2026-06-01 10:43:12'),
(12, 5, 1164000.00, 'deposit', NULL, NULL, 'Payment released for: yrflk', 'completed', '2026-06-01 11:21:05'),
(13, 5, 1235000.00, 'deposit', NULL, NULL, 'Payment released for: Toyota 2017', 'completed', '2026-06-01 11:33:04'),
(14, 5, 1020000.00, 'deposit', NULL, NULL, 'Payment released for: aaaaaaa', 'completed', '2026-06-01 11:37:22'),
(15, 5, 2850000.00, 'deposit', NULL, NULL, 'Payment released for: ABCD', 'completed', '2026-06-01 11:53:20'),
(16, 5, 3420000.00, 'deposit', NULL, NULL, 'Payment released for: Nissan 2026', 'completed', '2026-06-01 16:53:08'),
(17, 5, 900000.00, 'deposit', NULL, NULL, 'Payment released for: Newsssssssssssssssssssssssssssss', 'completed', '2026-06-01 16:54:39'),
(18, 20, 100.00, 'deposit', NULL, NULL, 'Welcome bonus', 'completed', '2026-06-01 17:06:20'),
(19, 5, 3570000.00, 'deposit', NULL, NULL, 'Payment released for: FNL car', 'completed', '2026-06-01 17:13:18'),
(20, 5, 1020000.00, 'deposit', NULL, NULL, 'Payment released for: aaaaaaa', 'completed', '2026-06-01 17:44:10'),
(21, 5, 1164000.00, 'deposit', NULL, NULL, 'Payment released for: yrflk', 'completed', '2026-06-02 05:00:05'),
(22, 2, 150.00, 'withdrawal_pending', 1, 'withdrawal', 'Telebirr withdrawal request — funds reserved', 'pending', '2026-06-02 05:44:44'),
(23, 5, 100000.00, 'withdrawal_pending', 2, 'withdrawal', 'Telebirr withdrawal request — funds reserved', 'pending', '2026-06-02 05:47:57'),
(24, 5, 42999.99, 'withdrawal_pending', NULL, NULL, 'Withdrawal request pending approval to Telebirr +251992116527', 'completed', '2026-06-02 06:53:48'),
(25, 5, 100000.00, 'withdrawal_pending', NULL, NULL, 'Withdrawal request pending approval to Telebirr +251992116527', 'completed', '2026-06-02 06:58:59'),
(26, 5, 100000.00, 'withdrawal_pending', NULL, NULL, 'Withdrawal request pending approval to Telebirr +251992116527', 'completed', '2026-06-02 06:59:10'),
(27, 5, 1800000.00, 'deposit', NULL, NULL, 'Payment released for: Modern Electric Car', 'completed', '2026-06-02 07:03:39'),
(28, 21, 100.00, 'deposit', NULL, NULL, 'Welcome bonus', 'completed', '2026-06-02 16:59:38'),
(29, 5, 3570000.00, 'deposit', NULL, NULL, 'Payment released for: FNL car', 'completed', '2026-06-02 18:06:32');

-- --------------------------------------------------------

--
-- Table structure for table `withdrawal_requests`
--

CREATE TABLE `withdrawal_requests` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `account_number` varchar(50) DEFAULT NULL,
  `account_name` varchar(100) DEFAULT NULL,
  `telebirr_phone` varchar(20) DEFAULT NULL,
  `transfer_reference` varchar(64) DEFAULT NULL,
  `telebirr_transfer_id` int(11) DEFAULT NULL,
  `telebirr_status` enum('pending','success','failed') DEFAULT 'pending',
  `failure_reason` text DEFAULT NULL,
  `status` enum('pending','processing','approved','rejected','completed','failed') NOT NULL DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `withdrawal_requests`
--

INSERT INTO `withdrawal_requests` (`id`, `user_id`, `amount`, `bank_name`, `account_number`, `account_name`, `telebirr_phone`, `transfer_reference`, `telebirr_transfer_id`, `telebirr_status`, `failure_reason`, `status`, `admin_notes`, `processed_by`, `processed_at`, `created_at`) VALUES
(1, 2, 150.00, 'Telebirr', '+251912345678', 'Demo User', '+251912345678', 'TBW2026060207444423D27B87', 1, 'success', NULL, 'processing', NULL, NULL, NULL, '2026-06-02 05:44:44'),
(2, 5, 100000.00, 'Telebirr', '+251992116527', 'Mesfin Haileslassie', '+251992116527', 'TBW202606020747570AE8021C', 2, 'success', NULL, 'processing', NULL, NULL, NULL, '2026-06-02 05:47:57');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_logs`
--
ALTER TABLE `admin_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_admin` (`admin_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `admin_notifications`
--
ALTER TABLE `admin_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `listing_id` (`listing_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_read` (`is_read`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `availability_calendar`
--
ALTER TABLE `availability_calendar`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_listing_date` (`listing_id`,`booking_date`),
  ADD KEY `idx_date` (`booking_date`),
  ADD KEY `idx_available` (`is_available`),
  ADD KEY `reservation_id` (`reservation_id`);

--
-- Indexes for table `balance_adjustments`
--
ALTER TABLE `balance_adjustments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_admin` (`admin_id`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `parent_id` (`parent_id`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_slug` (`slug`);

--
-- Indexes for table `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_subscription_expiry` (`subscription_expiry`),
  ADD KEY `subscription_plan_id` (`subscription_plan_id`);

--
-- Indexes for table `conversations`
--
ALTER TABLE `conversations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_conversation` (`user_id`,`broker_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_broker` (`broker_id`),
  ADD KEY `idx_updated` (`updated_at`);

--
-- Indexes for table `conversation_typing`
--
ALTER TABLE `conversation_typing`
  ADD PRIMARY KEY (`conversation_id`),
  ADD KEY `idx_typing` (`typing_until`);

--
-- Indexes for table `delivery_proofs`
--
ALTER TABLE `delivery_proofs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `transaction_id` (`transaction_id`);

--
-- Indexes for table `disputes`
--
ALTER TABLE `disputes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_transaction` (`transaction_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_raised_by` (`raised_by`);

--
-- Indexes for table `escrow_accounts`
--
ALTER TABLE `escrow_accounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_transaction_id` (`transaction_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_type` (`type`);

--
-- Indexes for table `escrow_release_history`
--
ALTER TABLE `escrow_release_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_transaction` (`transaction_id`);

--
-- Indexes for table `escrow_release_queue`
--
ALTER TABLE `escrow_release_queue`
  ADD PRIMARY KEY (`id`),
  ADD KEY `transaction_id` (`transaction_id`),
  ADD KEY `idx_scheduled_date` (`scheduled_release_date`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `favorites`
--
ALTER TABLE `favorites`
  ADD PRIMARY KEY (`user_id`,`listing_id`),
  ADD KEY `listing_id` (`listing_id`);

--
-- Indexes for table `job_applications`
--
ALTER TABLE `job_applications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `job_id` (`job_id`),
  ADD KEY `applicant_id` (`applicant_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `legal_documents`
--
ALTER TABLE `legal_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_transaction` (`transaction_id`);

--
-- Indexes for table `listings`
--
ALTER TABLE `listings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_seller` (`seller_id`),
  ADD KEY `idx_category` (`category_id`),
  ADD KEY `idx_featured` (`featured`),
  ADD KEY `idx_views` (`views`),
  ADD KEY `idx_is_rented` (`is_rented`),
  ADD KEY `idx_negotiation_id` (`negotiation_id`),
  ADD KEY `idx_availability` (`availability_status`,`status`,`approval_status`),
  ADD KEY `idx_availability_status` (`availability_status`),
  ADD KEY `idx_sold_to` (`sold_to_user_id`);

--
-- Indexes for table `listing_negotiations`
--
ALTER TABLE `listing_negotiations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_seller` (`seller_id`),
  ADD KEY `idx_listing` (`listing_id`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_conversation` (`conversation_id`),
  ADD KEY `idx_sender` (`sender_id`),
  ADD KEY `idx_receiver` (`receiver_id`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_unread` (`receiver_id`,`is_read`),
  ADD KEY `idx_deleted_sender` (`deleted_by_sender`),
  ADD KEY `idx_deleted_receiver` (`deleted_by_receiver`);

--
-- Indexes for table `message_reactions`
--
ALTER TABLE `message_reactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_reaction` (`message_id`,`user_id`),
  ADD KEY `idx_message` (`message_id`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `negotiation_history`
--
ALTER TABLE `negotiation_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_negotiation` (`negotiation_id`);

--
-- Indexes for table `negotiation_messages`
--
ALTER TABLE `negotiation_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_negotiation` (`negotiation_id`),
  ADD KEY `idx_read` (`is_read`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_read` (`is_read`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_transaction` (`transaction_id`),
  ADD KEY `idx_telebirr_code` (`telebirr_code_5digit`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `payment_codes`
--
ALTER TABLE `payment_codes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_code` (`code`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_code_status` (`code`,`status`),
  ADD KEY `idx_transaction_user` (`transaction_id`,`user_id`),
  ADD KEY `idx_code_expires` (`code`,`expires_at`);

--
-- Indexes for table `ratings`
--
ALTER TABLE `ratings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rated` (`rated_id`),
  ADD KEY `idx_rater` (`rater_id`),
  ADD KEY `idx_transaction` (`transaction_id`);

--
-- Indexes for table `rental_bookings`
--
ALTER TABLE `rental_bookings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_transaction_id` (`transaction_id`),
  ADD KEY `idx_property_id` (`property_id`),
  ADD KEY `idx_tenant_id` (`tenant_id`),
  ADD KEY `idx_owner_id` (`owner_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_dates` (`check_in_date`,`check_out_date`),
  ADD KEY `idx_reservation` (`reservation_id`);

--
-- Indexes for table `reservation_records`
--
ALTER TABLE `reservation_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_listing` (`listing_id`),
  ADD KEY `idx_dates` (`check_in_date`,`check_out_date`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_transaction` (`transaction_id`),
  ADD KEY `idx_buyer` (`buyer_id`),
  ADD KEY `seller_id` (`seller_id`);

--
-- Indexes for table `reservation_status_history`
--
ALTER TABLE `reservation_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_reservation` (`reservation_id`);

--
-- Indexes for table `saved_jobs`
--
ALTER TABLE `saved_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_saved` (`user_id`,`job_id`),
  ADD KEY `job_id` (`job_id`);

--
-- Indexes for table `subscription_plans`
--
ALTER TABLE `subscription_plans`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `support_tickets`
--
ALTER TABLE `support_tickets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ticket_number` (`ticket_number`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_ticket_number` (`ticket_number`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `telebirr_accounts`
--
ALTER TABLE `telebirr_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_telebirr_phone` (`phone`),
  ADD KEY `idx_telebirr_user` (`user_id`),
  ADD KEY `idx_telebirr_platform` (`is_platform`);

--
-- Indexes for table `telebirr_transfers`
--
ALTER TABLE `telebirr_transfers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_transfer_reference` (`transfer_reference`),
  ADD KEY `idx_tb_transfer_withdrawal` (`withdrawal_request_id`),
  ADD KEY `idx_tb_transfer_status` (`status`),
  ADD KEY `fk_tb_sender` (`sender_account_id`),
  ADD KEY `fk_tb_receiver` (`receiver_account_id`);

--
-- Indexes for table `ticket_replies`
--
ALTER TABLE `ticket_replies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_ticket` (`ticket_id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_payment_code` (`payment_code_5digit`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_payment_code` (`payment_code_5digit`),
  ADD KEY `idx_buyer` (`buyer_id`),
  ADD KEY `idx_seller` (`seller_id`),
  ADD KEY `idx_listing` (`listing_id`),
  ADD KEY `idx_escrow_status` (`escrow_status`),
  ADD KEY `idx_escrow_release_date` (`escrow_release_date`),
  ADD KEY `idx_admin_frozen` (`admin_frozen`);

--
-- Indexes for table `transaction_timeline`
--
ALTER TABLE `transaction_timeline`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_transaction` (`transaction_id`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_role` (`role`),
  ADD KEY `idx_verification_token` (`verification_token`),
  ADD KEY `idx_phone` (`phone`),
  ADD KEY `idx_normalized_phone` (`normalized_phone`);

--
-- Indexes for table `user_activity_logs`
--
ALTER TABLE `user_activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `user_notification_settings`
--
ALTER TABLE `user_notification_settings`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_token` (`session_token`),
  ADD KEY `idx_user_active` (`user_id`,`is_active`);

--
-- Indexes for table `user_tokens`
--
ALTER TABLE `user_tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `user_trust_scores`
--
ALTER TABLE `user_trust_scores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD KEY `idx_score` (`score`);

--
-- Indexes for table `wallet_transactions`
--
ALTER TABLE `wallet_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `withdrawal_requests`
--
ALTER TABLE `withdrawal_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_withdrawal_transfer_ref` (`transfer_reference`),
  ADD KEY `processed_by` (`processed_by`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_user` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_logs`
--
ALTER TABLE `admin_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admin_notifications`
--
ALTER TABLE `admin_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `availability_calendar`
--
ALTER TABLE `availability_calendar`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `balance_adjustments`
--
ALTER TABLE `balance_adjustments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `companies`
--
ALTER TABLE `companies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `conversations`
--
ALTER TABLE `conversations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `delivery_proofs`
--
ALTER TABLE `delivery_proofs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `disputes`
--
ALTER TABLE `disputes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `escrow_accounts`
--
ALTER TABLE `escrow_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=87;

--
-- AUTO_INCREMENT for table `escrow_release_history`
--
ALTER TABLE `escrow_release_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `escrow_release_queue`
--
ALTER TABLE `escrow_release_queue`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `job_applications`
--
ALTER TABLE `job_applications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `legal_documents`
--
ALTER TABLE `legal_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `listings`
--
ALTER TABLE `listings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=50;

--
-- AUTO_INCREMENT for table `listing_negotiations`
--
ALTER TABLE `listing_negotiations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `message_reactions`
--
ALTER TABLE `message_reactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `negotiation_history`
--
ALTER TABLE `negotiation_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `negotiation_messages`
--
ALTER TABLE `negotiation_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=87;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=127;

--
-- AUTO_INCREMENT for table `payment_codes`
--
ALTER TABLE `payment_codes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=287;

--
-- AUTO_INCREMENT for table `ratings`
--
ALTER TABLE `ratings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rental_bookings`
--
ALTER TABLE `rental_bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `reservation_records`
--
ALTER TABLE `reservation_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reservation_status_history`
--
ALTER TABLE `reservation_status_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `saved_jobs`
--
ALTER TABLE `saved_jobs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subscription_plans`
--
ALTER TABLE `subscription_plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `support_tickets`
--
ALTER TABLE `support_tickets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `telebirr_accounts`
--
ALTER TABLE `telebirr_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `telebirr_transfers`
--
ALTER TABLE `telebirr_transfers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `ticket_replies`
--
ALTER TABLE `ticket_replies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=98;

--
-- AUTO_INCREMENT for table `transaction_timeline`
--
ALTER TABLE `transaction_timeline`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `user_activity_logs`
--
ALTER TABLE `user_activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_sessions`
--
ALTER TABLE `user_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_tokens`
--
ALTER TABLE `user_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `user_trust_scores`
--
ALTER TABLE `user_trust_scores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wallet_transactions`
--
ALTER TABLE `wallet_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `withdrawal_requests`
--
ALTER TABLE `withdrawal_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admin_logs`
--
ALTER TABLE `admin_logs`
  ADD CONSTRAINT `admin_logs_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `admin_notifications`
--
ALTER TABLE `admin_notifications`
  ADD CONSTRAINT `admin_notifications_ibfk_1` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `admin_notifications_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `availability_calendar`
--
ALTER TABLE `availability_calendar`
  ADD CONSTRAINT `availability_calendar_ibfk_1` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `availability_calendar_ibfk_2` FOREIGN KEY (`reservation_id`) REFERENCES `reservation_records` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `balance_adjustments`
--
ALTER TABLE `balance_adjustments`
  ADD CONSTRAINT `balance_adjustments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `balance_adjustments_ibfk_2` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `categories`
--
ALTER TABLE `categories`
  ADD CONSTRAINT `categories_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`);

--
-- Constraints for table `companies`
--
ALTER TABLE `companies`
  ADD CONSTRAINT `companies_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `companies_ibfk_2` FOREIGN KEY (`subscription_plan_id`) REFERENCES `subscription_plans` (`id`);

--
-- Constraints for table `conversation_typing`
--
ALTER TABLE `conversation_typing`
  ADD CONSTRAINT `conversation_typing_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `delivery_proofs`
--
ALTER TABLE `delivery_proofs`
  ADD CONSTRAINT `delivery_proofs_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `disputes`
--
ALTER TABLE `disputes`
  ADD CONSTRAINT `disputes_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `disputes_ibfk_2` FOREIGN KEY (`raised_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `escrow_release_queue`
--
ALTER TABLE `escrow_release_queue`
  ADD CONSTRAINT `escrow_release_queue_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `favorites`
--
ALTER TABLE `favorites`
  ADD CONSTRAINT `favorites_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `favorites_ibfk_2` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `job_applications`
--
ALTER TABLE `job_applications`
  ADD CONSTRAINT `job_applications_ibfk_1` FOREIGN KEY (`job_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `job_applications_ibfk_2` FOREIGN KEY (`applicant_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `legal_documents`
--
ALTER TABLE `legal_documents`
  ADD CONSTRAINT `legal_documents_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`),
  ADD CONSTRAINT `legal_documents_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `listings`
--
ALTER TABLE `listings`
  ADD CONSTRAINT `listings_ibfk_1` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `listings_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`);

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_ibfk_3` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `message_reactions`
--
ALTER TABLE `message_reactions`
  ADD CONSTRAINT `message_reactions_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `message_reactions_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payment_codes`
--
ALTER TABLE `payment_codes`
  ADD CONSTRAINT `payment_codes_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`),
  ADD CONSTRAINT `payment_codes_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `ratings`
--
ALTER TABLE `ratings`
  ADD CONSTRAINT `ratings_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ratings_ibfk_2` FOREIGN KEY (`rater_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ratings_ibfk_3` FOREIGN KEY (`rated_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `reservation_records`
--
ALTER TABLE `reservation_records`
  ADD CONSTRAINT `reservation_records_ibfk_1` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reservation_records_ibfk_2` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reservation_records_ibfk_3` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `reservation_records_ibfk_4` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `reservation_status_history`
--
ALTER TABLE `reservation_status_history`
  ADD CONSTRAINT `reservation_status_history_ibfk_1` FOREIGN KEY (`reservation_id`) REFERENCES `reservation_records` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `saved_jobs`
--
ALTER TABLE `saved_jobs`
  ADD CONSTRAINT `saved_jobs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `saved_jobs_ibfk_2` FOREIGN KEY (`job_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `support_tickets`
--
ALTER TABLE `support_tickets`
  ADD CONSTRAINT `support_tickets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `support_tickets_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`);

--
-- Constraints for table `telebirr_transfers`
--
ALTER TABLE `telebirr_transfers`
  ADD CONSTRAINT `fk_tb_receiver` FOREIGN KEY (`receiver_account_id`) REFERENCES `telebirr_accounts` (`id`),
  ADD CONSTRAINT `fk_tb_sender` FOREIGN KEY (`sender_account_id`) REFERENCES `telebirr_accounts` (`id`);

--
-- Constraints for table `ticket_replies`
--
ALTER TABLE `ticket_replies`
  ADD CONSTRAINT `ticket_replies_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ticket_replies_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transactions_ibfk_3` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `user_activity_logs`
--
ALTER TABLE `user_activity_logs`
  ADD CONSTRAINT `user_activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `user_notification_settings`
--
ALTER TABLE `user_notification_settings`
  ADD CONSTRAINT `user_notification_settings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD CONSTRAINT `user_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `user_tokens`
--
ALTER TABLE `user_tokens`
  ADD CONSTRAINT `user_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `wallet_transactions`
--
ALTER TABLE `wallet_transactions`
  ADD CONSTRAINT `wallet_transactions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `withdrawal_requests`
--
ALTER TABLE `withdrawal_requests`
  ADD CONSTRAINT `withdrawal_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `withdrawal_requests_ibfk_2` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
