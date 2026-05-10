-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 09, 2026 at 04:35 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `juancafe`
--

-- --------------------------------------------------------

--
-- Table structure for table `auth_second_layer`
--

CREATE TABLE `auth_second_layer` (
  `id` int(11) NOT NULL,
  `action_type` enum('order_approve','payment_approve','inventory_adjust','rider_assign') NOT NULL,
  `reference_id` int(11) NOT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `otp_code` varchar(10) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `role_required` varchar(50) DEFAULT NULL,
  `user_id` bigint(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `delivery_logs`
--

CREATE TABLE `delivery_logs` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `rider_id` int(11) DEFAULT NULL,
  `status` enum('assigned','picked_up','in_transit','delivered','failed') DEFAULT 'assigned',
  `location_note` varchar(255) DEFAULT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `assigned_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `franchisees`
--

CREATE TABLE `franchisees` (
  `id` int(11) NOT NULL,
  `branch_name` varchar(150) DEFAULT NULL,
  `franchisee_name` varchar(150) DEFAULT NULL,
  `user_id` bigint(20) DEFAULT NULL,
  `branch_location` varchar(255) DEFAULT NULL,
  `contact_number` varchar(50) DEFAULT NULL,
  `gcash_number` varchar(50) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `franchisees`
--

INSERT INTO `franchisees` (`id`, `branch_name`, `franchisee_name`, `user_id`, `branch_location`, `contact_number`, `gcash_number`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Talon 2, Las Piñas', NULL, 9, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-19 16:10:25'),
(2, 'Brixtonville, Caloocan', 'Harvey R. Marquez', 29, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-05-06 16:17:40'),
(3, 'Lalaine Bennet, Las Pinas', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(4, 'Marcos Alvarez, Las Piñas', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(5, 'Star City, Pasay', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(6, 'Sun Valley, Parañaque City', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(7, 'Project 6, Quezon City', 'Juan Dela Cruz', 1, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-05-08 17:32:14'),
(8, 'Circle C Mall', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(9, 'A. Arbor Montessori, Parañaque', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(10, 'Phase 9, Caloocan City', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(11, 'Rodriguez St., Malibay', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(12, 'Legaspi St., Intramuros', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(13, 'Manggahan, Pasig', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(14, 'Samson Road, Caloocan', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(15, 'Dagohoy, Taguig', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(16, 'Palanan, Makati', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(17, 'Linao St., Paco', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(18, 'Sampaloc Site, Parañaque', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(19, 'North Fairview, Quezon City', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(20, 'Maligaya Park, Novaliches', 'Virginia Nidea-Tapia', 31, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-05-06 16:17:40'),
(21, 'Pasolo, Valenzuela City', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(22, 'Concepcion, Marikina', 'Julius Ryan B. Galicinao', 33, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-05-06 16:17:40'),
(23, 'Honradez, Sampaloc, Manila', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(24, 'Kaligayahan, QC', 'Allain Joseph Lolim', 34, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-05-06 16:17:40'),
(25, 'Salapan, San Juan City', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(26, 'Carina Homes, Caloocan City', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(27, 'Purok 3, Sucat, Muntinlupa', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(28, 'Kabihasnan, Parañaque', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(29, 'Admiral Rd., Las Piñas', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(30, 'SSS Village, Marikina', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(31, 'Kasayahan St., Batasan Hills', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(32, 'Castanos St., Sampaloc', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(33, 'Palatiw, Pasig', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(34, 'Don Fabian, Commonwealth', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(35, 'Balingasa Rd., Bonifacio', 'Robert John G. Pante', 26, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-05-06 16:17:40'),
(36, 'Pampano St., Longos', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(37, 'Lawin St., Makati', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(38, 'San Antonio Valley 1', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(39, 'Calzada Tipas, Taguig', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(40, 'Veterans Vill., Holy Spirit', 'Louise Alyanna M. Abad', 17, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-05-06 16:17:40'),
(41, 'Payatas A, Quezon City', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(42, 'Noli St., Malate', 'Cyril Aran G. Fallar', 19, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-05-06 16:17:40'),
(43, 'UPS5, Parañaque', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(44, 'BCDA, Taguig', 'Jireh D. Agustin', 22, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-05-06 16:17:40'),
(45, 'Banlat, Tandang Sora', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(46, 'Gatchalian, Parañaque', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(47, 'Pingkian, Sauyo', 'Denise Olive A. Casimero', 13, 'Pingkian, Sauyo', NULL, NULL, 'active', '2026-05-06 16:22:21', '2026-05-06 16:22:21'),
(48, 'Republic, Holy Spirit', 'Denise Olive A. Casimero', 14, 'Republic, Holy Spirit', NULL, NULL, 'active', '2026-05-06 16:22:21', '2026-05-06 16:22:21'),
(49, 'Sto. Niño', 'Annilor A. Marbella', 15, 'Sto. Niño', NULL, NULL, 'active', '2026-05-06 16:22:21', '2026-05-06 16:22:21'),
(50, 'Caybiga Food Hall', 'Norberto Pag-iwayan', 16, 'Caybiga Food Hall', NULL, NULL, 'active', '2026-05-06 16:22:21', '2026-05-06 16:22:21'),
(51, 'Antipolo Valley Mall', 'Niño E. Burac', 18, 'Antipolo Valley Mall', NULL, NULL, 'active', '2026-05-06 16:22:21', '2026-05-06 16:22:21'),
(52, '6th Ave., Mabalacat', 'Jomer L. Quillosa', 20, '6th Ave., Mabalacat', NULL, NULL, 'active', '2026-05-06 16:22:21', '2026-05-06 16:22:21'),
(53, 'Kapitangan, Paombong', 'Elmo F. Ramos', 21, 'Kapitangan, Paombong', NULL, NULL, 'active', '2026-05-06 16:22:21', '2026-05-06 16:22:21'),
(54, 'LMG Bldg., Tandang Sora', 'April Joy R. Ramos', 23, 'LMG Bldg., Tandang Sora', NULL, NULL, 'active', '2026-05-06 16:22:21', '2026-05-06 16:22:21'),
(55, 'Sta. Lucia, Novaliches', 'Edison J. Magno', 24, 'Sta. Lucia, Novaliches', NULL, NULL, 'active', '2026-05-06 16:22:21', '2026-05-06 16:22:21'),
(56, 'Sto. Domingo St.', 'Annilor A. Marbella', 25, 'Sto. Domingo St.', NULL, NULL, 'active', '2026-05-06 16:22:21', '2026-05-06 16:22:21'),
(57, 'Marikina Heights', 'Michelle L. Miguel', 27, 'Marikina Heights', NULL, NULL, 'active', '2026-05-06 16:22:21', '2026-05-06 16:22:21'),
(58, 'Bago Bantay', 'Erika Denise J. Tating', 28, 'Bago Bantay', NULL, NULL, 'active', '2026-05-06 16:22:21', '2026-05-06 16:22:21'),
(59, 'Del Pilar St., Pag-asa', 'Cecille D. Diaz', 30, 'Del Pilar St., Pag-asa', NULL, NULL, 'active', '2026-05-06 16:22:21', '2026-05-06 16:22:21'),
(60, 'Sto. Niño, Camarin', 'Evangeline R. Catinoy', 32, 'Sto. Niño, Camarin', NULL, NULL, 'active', '2026-05-06 16:22:21', '2026-05-06 16:22:21'),
(61, 'Sarmiento Homes, SJDM', 'Rafaelito L. Nicdao', 35, 'Sarmiento Homes, SJDM', NULL, NULL, 'active', '2026-05-06 16:22:21', '2026-05-06 16:22:21'),
(62, 'Samat Road, Luzon Avenue', 'Denise Olive A. Casimero', 36, 'Samat Road, Luzon Avenue', NULL, NULL, 'active', '2026-05-06 16:22:21', '2026-05-06 16:22:21'),
(63, 'Taurus St., Tandang Sora', 'Michhelle N. Carcueva', 37, 'Taurus St., Tandang Sora', NULL, NULL, 'active', '2026-05-06 16:22:21', '2026-05-06 16:22:21'),
(64, 'Everlasting, Quezon City', 'Julie Anne Sibal Ferrer', 38, 'Everlasting, Quezon City', NULL, NULL, 'active', '2026-05-06 16:22:21', '2026-05-06 16:22:21');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_batches`
--

CREATE TABLE `inventory_batches` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `batch_number` varchar(100) NOT NULL,
  `batch_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `remaining_qty` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory_batches`
--

INSERT INTO `inventory_batches` (`id`, `product_id`, `batch_number`, `batch_qty`, `remaining_qty`) VALUES
(1, 42, 'MFG-206-0908-19', 10.00, 10.00),
(2, 29, 'MFG-2026-0809', 10.00, 10.00);

-- --------------------------------------------------------

--
-- Table structure for table `inventory_logs`
--

CREATE TABLE `inventory_logs` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `franchisee_id` int(11) DEFAULT NULL,
  `action` enum('add','deduct','adjust') NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit` varchar(20) DEFAULT NULL,
  `previous_stock` int(11) DEFAULT NULL,
  `new_stock` int(11) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory_logs`
--

INSERT INTO `inventory_logs` (`id`, `product_id`, `order_id`, `franchisee_id`, `action`, `quantity`, `unit`, `previous_stock`, `new_stock`, `remarks`, `created_by`, `created_at`) VALUES
(1, 1, 1, 1, 'deduct', 2, '1kg', 100, 98, 'Order deduction for PO-PO-0001', 1, '2026-04-12 17:51:41'),
(2, 3, 1, 1, 'deduct', 1, '1kg', 100, 99, 'Order deduction for PO-PO-0001', 1, '2026-04-12 17:51:41'),
(3, 42, NULL, NULL, 'add', 10, '3kg', 100, 110, 'Stock received — batch MFG-206-0908-19', 4, '2026-05-08 18:23:03'),
(4, 29, NULL, NULL, 'deduct', 10, '1kg', 90, 80, 'Stock adjustment — Discrepancy', 4, '2026-05-09 01:29:05'),
(5, 29, NULL, NULL, 'add', 10, '1kg', 80, 90, 'Stock received — batch MFG-2026-0809', 4, '2026-05-09 02:34:12');

-- --------------------------------------------------------

--
-- Table structure for table `item_usage`
--

CREATE TABLE `item_usage` (
  `id` int(11) NOT NULL,
  `usage_ref` varchar(30) DEFAULT NULL,
  `franchisee_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity_used` int(11) NOT NULL,
  `unit` varchar(20) NOT NULL,
  `recording_date` date NOT NULL,
  `submitted_at` datetime DEFAULT current_timestamp(),
  `order_id` int(11) DEFAULT NULL,
  `is_default` tinyint(1) DEFAULT 1,
  `status` enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `item_usage`
--

INSERT INTO `item_usage` (`id`, `usage_ref`, `franchisee_id`, `product_id`, `quantity_used`, `unit`, `recording_date`, `submitted_at`, `order_id`, `is_default`, `status`) VALUES
(1, NULL, 1, 1, 5, 'g', '2026-03-13', '2026-04-02 03:19:37', NULL, 1, 'active'),
(2, NULL, 2, 17, 3, 'ml', '2026-03-13', '2026-04-02 03:19:37', NULL, 1, 'active'),
(3, NULL, 3, 22, 2, 'pcs', '2026-03-13', '2026-04-02 03:19:37', NULL, 1, 'active'),
(4, 'IU-2026-0001', 7, 29, 3, '1kg', '2026-05-03', '2026-05-04 01:27:09', NULL, 1, 'active'),
(5, 'IU-2026-0001', 7, 27, 1, '1kg', '2026-05-03', '2026-05-04 01:27:09', NULL, 1, 'active'),
(6, 'IU-2026-0002', 7, 27, 1, '1kg', '2026-05-03', '2026-05-04 01:37:58', NULL, 1, 'active'),
(7, 'IU-2026-0002', 7, 29, 3, '1kg', '2026-05-03', '2026-05-04 01:37:58', NULL, 1, 'active'),
(8, 'IU-2026-0002', 7, 47, 4, '1kg', '2026-05-03', '2026-05-04 01:37:58', NULL, 1, 'active'),
(9, 'IU-2026-0002', 7, 36, 1, '2L', '2026-05-03', '2026-05-04 01:37:58', NULL, 1, 'active'),
(10, 'IU-2026-0003', 2, 29, 2, '1kg', '2026-05-03', '2026-05-04 01:52:45', NULL, 0, 'active'),
(11, 'IU-2026-0003', 2, 28, 1, '2L', '2026-05-03', '2026-05-04 01:52:45', NULL, 0, 'active'),
(12, 'IU-2026-0003', 2, 43, 1, '3kg', '2026-05-03', '2026-05-04 01:52:45', NULL, 0, 'active'),
(13, 'IU-2026-0003', 2, 12, 6, '1kg', '2026-05-03', '2026-05-04 01:52:45', NULL, 0, 'active'),
(14, 'IU-2026-0003', 2, 38, 1, '1kg', '2026-05-03', '2026-05-04 01:52:45', NULL, 0, 'active');

-- --------------------------------------------------------

--
-- Table structure for table `item_usage_defaults`
--

CREATE TABLE `item_usage_defaults` (
  `id` int(11) NOT NULL,
  `franchisee_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit` varchar(50) NOT NULL DEFAULT '',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `item_usage_defaults`
--

INSERT INTO `item_usage_defaults` (`id`, `franchisee_id`, `product_id`, `quantity`, `unit`, `updated_at`) VALUES
(1, 7, 29, 3, '1kg', '2026-05-04 01:37:58'),
(2, 7, 27, 1, '1kg', '2026-05-04 01:37:58'),
(5, 7, 47, 4, '1kg', '2026-05-04 01:37:58'),
(6, 7, 36, 1, '2L', '2026-05-04 01:37:58');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `po_number` varchar(50) DEFAULT NULL,
  `franchisee_id` int(11) DEFAULT NULL,
  `status` enum('pending','for_approval','for_payment','paid','processing','out_for_delivery','completed','cancelled','Under Review','Approved','Rejected') DEFAULT 'pending',
  `status_step` tinyint(4) DEFAULT 0,
  `delivery_preference` varchar(20) DEFAULT NULL,
  `delivery_fee` decimal(10,2) DEFAULT NULL,
  `subtotal` decimal(10,2) DEFAULT NULL,
  `total_amount` decimal(10,2) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `estimated_pickup` date DEFAULT NULL,
  `payment_method` enum('cod','gcash','bank') DEFAULT NULL,
  `payment_status` enum('unpaid','for_payment','paid','rejected') DEFAULT 'unpaid',
  `payment_proof` text DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rider_id` int(11) DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `payment_due` decimal(10,2) DEFAULT NULL,
  `has_out_of_stock` tinyint(1) DEFAULT 0,
  `payment_ref` varchar(100) DEFAULT NULL,
  `payment_confirmed_by` int(11) DEFAULT NULL,
  `payment_confirmed_at` datetime DEFAULT NULL,
  `payment_attempts` int(11) DEFAULT 0,
  `delivery_status` enum('pending','assigned','picked_up','in_transit','delivered','failed') DEFAULT 'pending',
  `assigned_rider_id` int(11) DEFAULT NULL,
  `assigned_encoder_id` bigint(20) DEFAULT NULL,
  `assigned_clerk_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `po_number`, `franchisee_id`, `status`, `status_step`, `delivery_preference`, `delivery_fee`, `subtotal`, `total_amount`, `created_at`, `estimated_pickup`, `payment_method`, `payment_status`, `payment_proof`, `approved_by`, `approved_at`, `rider_id`, `delivered_at`, `payment_due`, `has_out_of_stock`, `payment_ref`, `payment_confirmed_by`, `payment_confirmed_at`, `payment_attempts`, `delivery_status`, `assigned_rider_id`, `assigned_encoder_id`, `assigned_clerk_id`) VALUES
(1, 'PO-0001', 1, 'pending', 0, 'delivery', 50.00, 500.00, 550.00, '2026-04-02 03:19:06', NULL, NULL, 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, 0, 'pending', NULL, NULL, NULL),
(2, 'PO-0002', 2, 'completed', 0, 'pickup', 0.00, 300.00, 300.00, '2026-04-02 03:19:06', NULL, NULL, 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, 0, 'pending', NULL, NULL, NULL),
(3, 'PO-0003', 3, 'pending', 0, 'delivery', 30.00, 400.00, 430.00, '2026-04-02 03:19:06', NULL, NULL, 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, 0, 'pending', NULL, NULL, NULL),
(4, 'PO-2026-0004', 7, 'Rejected', -1, 'Standard Delivery', 250.00, 580.00, 830.00, '2026-04-20 00:15:22', '2026-04-21', 'gcash', 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, 0, '1234567890', NULL, NULL, 0, 'pending', NULL, NULL, NULL),
(5, 'PO-2026-0005', 7, 'Rejected', -1, 'Priority Delivery', 250.00, 800.00, 1050.00, '2026-04-20 00:38:47', '2026-04-20', 'gcash', 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, 0, '1234567890', NULL, NULL, 0, 'pending', NULL, NULL, NULL),
(6, 'PO-2026-0006', 7, 'completed', 4, 'Priority Delivery', 250.00, 465.00, 715.00, '2026-04-20 00:58:06', '2026-04-20', 'gcash', 'paid', 'uploads/payment_screenshots/proof_1776617886_1933cdc2.png', 2, '2026-04-20 01:08:18', NULL, NULL, NULL, 0, '1234567890', NULL, NULL, 0, 'pending', NULL, 6, NULL),
(7, 'PO-2026-0007', 7, 'Rejected', -1, 'Priority Delivery', 250.00, 730.00, 980.00, '2026-04-20 01:15:45', '2026-04-20', 'bank', 'paid', 'uploads/payment_screenshots/proof_1776618945_79e90bbf.png', NULL, NULL, NULL, NULL, NULL, 0, '1234567890', NULL, NULL, 0, 'pending', NULL, NULL, NULL),
(8, 'PO-2026-0008', 7, 'completed', 4, 'Priority Delivery', 250.00, 90.00, 340.00, '2026-04-22 00:24:08', '2026-04-22', 'gcash', 'unpaid', 'uploads/payment_screenshots/proof_1776788648_1a4ec8d4.png', 2, '2026-04-22 02:21:33', NULL, NULL, NULL, 0, '1234567890', NULL, NULL, 0, 'pending', NULL, 6, NULL),
(9, 'PO-2026-0009', 7, 'Rejected', -1, 'Standard Delivery', 250.00, 275.00, 525.00, '2026-04-22 02:21:08', '2026-04-23', 'cod', 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, 0, '', NULL, NULL, 0, 'pending', NULL, 6, NULL),
(10, 'PO-2026-0010', 7, 'completed', 4, 'Priority Delivery', 250.00, 275.00, 525.00, '2026-04-23 00:29:42', '2026-04-23', 'gcash', 'unpaid', 'uploads/payment_screenshots/proof_1776875382_68ef75db.png', NULL, NULL, NULL, NULL, NULL, 0, '123', NULL, NULL, 0, 'pending', NULL, 6, 4),
(11, 'PO-2026-0011', 7, 'Rejected', -1, 'Standard Delivery', 250.00, 1015.00, 1265.00, '2026-05-04 01:08:40', '2026-05-05', 'gcash', 'paid', 'uploads/payment_screenshots/proof_1777828120_a775c886.png', NULL, NULL, NULL, NULL, NULL, 0, '1234567890', NULL, NULL, 0, 'pending', NULL, NULL, NULL),
(12, 'PO-2026-0012', 7, 'completed', 4, 'Self Pickup', 0.00, 2455.00, 2455.00, '2026-05-09 03:16:02', '2026-05-08', 'gcash', 'paid', 'uploads/payment_screenshots/proof_1778267762_6d218bf1.png', 2, '2026-05-09 03:18:11', NULL, NULL, NULL, 0, '1234567890', NULL, NULL, 0, 'pending', NULL, 6, 4),
(13, 'PO-2026-0013', 7, 'out_for_delivery', 3, 'Standard Delivery/mo', 250.00, 1383.00, 1633.00, '2026-05-09 03:16:42', '2026-05-10', 'cod', 'unpaid', NULL, 2, '2026-05-09 03:18:01', 7, NULL, NULL, 0, '', NULL, NULL, 0, 'in_transit', NULL, 6, 4),
(14, 'PO-2026-0014', 7, 'out_for_delivery', 3, 'Standard Delivery/mo', 250.00, 1550.00, 1800.00, '2026-05-09 03:17:13', '2026-05-10', 'bank', 'paid', 'uploads/payment_screenshots/proof_1778267833_672cb22f.jpg', 2, '2026-05-09 03:17:45', 7, NULL, NULL, 0, '1234567890', NULL, NULL, 0, '', NULL, 6, 4);

-- --------------------------------------------------------

--
-- Table structure for table `order_approvals`
--

CREATE TABLE `order_approvals` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `action` enum('approved','rejected','edited','returned','marked_out_of_stock') NOT NULL,
  `previous_status` varchar(30) DEFAULT NULL,
  `new_status` varchar(30) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `performed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_by` bigint(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) DEFAULT NULL,
  `unit` varchar(20) DEFAULT NULL,
  `stock_status` enum('available','out_of_stock') DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `quantity`, `unit_price`, `subtotal`, `unit`, `stock_status`, `created_at`) VALUES
(1, 1, 1, 2, 222.00, NULL, NULL, 'available', '2026-04-12 17:14:38'),
(2, 1, 3, 1, 273.00, NULL, NULL, 'available', '2026-04-12 17:14:38'),
(3, 2, 17, 2, 255.00, NULL, NULL, 'available', '2026-04-12 17:14:38'),
(4, 3, 22, 3, 112.00, NULL, NULL, 'available', '2026-04-12 17:14:38'),
(5, 4, 44, 1, 310.00, 310.00, NULL, 'available', '2026-04-19 16:15:22'),
(6, 4, 45, 3, 90.00, 270.00, NULL, 'available', '2026-04-19 16:15:22'),
(7, 5, 46, 1, 320.00, 320.00, NULL, 'available', '2026-04-19 16:38:47'),
(8, 5, 25, 3, 160.00, 480.00, NULL, 'available', '2026-04-19 16:38:47'),
(9, 6, 28, 1, 465.00, 465.00, NULL, 'available', '2026-04-19 16:58:06'),
(10, 7, 29, 1, 260.00, 260.00, NULL, 'available', '2026-04-19 17:15:45'),
(11, 7, 25, 1, 160.00, 160.00, NULL, 'available', '2026-04-19 17:15:45'),
(12, 7, 43, 1, 310.00, 310.00, NULL, 'available', '2026-04-19 17:15:45'),
(13, 8, 45, 1, 90.00, 90.00, NULL, 'available', '2026-04-21 16:24:08'),
(14, 9, 47, 1, 275.00, 275.00, NULL, 'available', '2026-04-21 18:21:08'),
(15, 10, 47, 1, 275.00, 275.00, NULL, 'available', '2026-04-22 16:29:42'),
(16, 11, 29, 3, 260.00, 780.00, NULL, 'available', '2026-05-03 17:08:40'),
(17, 11, 27, 1, 235.00, 235.00, NULL, 'available', '2026-05-03 17:08:40'),
(18, 12, 47, 4, 275.00, 1100.00, NULL, 'available', '2026-05-08 19:16:03'),
(19, 12, 27, 1, 235.00, 235.00, NULL, 'available', '2026-05-08 19:16:03'),
(20, 12, 29, 3, 260.00, 780.00, NULL, 'available', '2026-05-08 19:16:03'),
(21, 12, 36, 1, 340.00, 340.00, NULL, 'available', '2026-05-08 19:16:03'),
(22, 13, 28, 1, 465.00, 465.00, NULL, 'available', '2026-05-08 19:16:42'),
(23, 13, 12, 3, 306.00, 918.00, NULL, 'available', '2026-05-08 19:16:42'),
(24, 14, 43, 1, 310.00, 310.00, NULL, 'available', '2026-05-08 19:17:13'),
(25, 14, 42, 4, 310.00, 1240.00, NULL, 'available', '2026-05-08 19:17:13');

-- --------------------------------------------------------

--
-- Table structure for table `order_status_history`
--

CREATE TABLE `order_status_history` (
  `id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `status_step` tinyint(1) DEFAULT NULL,
  `status_label` varchar(50) DEFAULT NULL,
  `detail` varchar(255) DEFAULT NULL,
  `changed_at` datetime DEFAULT current_timestamp(),
  `changed_by` bigint(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_status_history`
--

INSERT INTO `order_status_history` (`id`, `order_id`, `status_step`, `status_label`, `detail`, `changed_at`, `changed_by`) VALUES
(1, 4, 0, 'Order Submitted', 'Purchase order successfully received by the system.', '2026-04-20 00:15:22', 1),
(2, 4, 1, 'Under Review', 'Our team is verifying item availability and branch details.', '2026-04-20 00:15:22', 1),
(3, 4, -1, 'Rejected', 'Order rejected by administrator. Reason: Reject', '2026-04-20 00:36:40', 2),
(4, 5, 0, 'Order Submitted', 'Purchase order successfully received by the system.', '2026-04-20 00:38:47', 1),
(5, 5, 1, 'Under Review', 'Our team is verifying item availability and branch details.', '2026-04-20 00:38:47', 1),
(6, 5, -1, 'Rejected', 'Order rejected by administrator. Reason: Reject', '2026-04-20 00:49:12', 2),
(7, 6, 0, 'Order Submitted', 'Purchase order successfully received by the system.', '2026-04-20 00:58:06', 1),
(8, 6, 1, 'Under Review', 'Our team is verifying item availability and branch details.', '2026-04-20 00:58:06', 1),
(9, 6, 2, 'Approved', 'Order approved by administrator and assigned to Data Encoder: Data Encoder.', '2026-04-20 01:08:18', 2),
(10, 7, 0, 'Order Submitted', 'Purchase order successfully received by the system.', '2026-04-20 01:15:45', 1),
(11, 7, 1, 'Under Review', 'Our team is verifying item availability and branch details.', '2026-04-20 01:15:45', 1),
(12, 6, 1, 'Under Review', 'Order flagged for discrepancy. Encoder requires additional information.', '2026-04-20 01:22:43', 6),
(13, 8, 0, 'Order Submitted', 'Purchase order successfully received by the system.', '2026-04-22 00:24:08', 1),
(14, 8, 1, 'Under Review', 'Our team is verifying item availability and branch details.', '2026-04-22 00:24:08', 1),
(15, 8, 2, 'Approved', 'Order approved by administrator and assigned to Data Encoder: Data Encoder.', '2026-04-22 00:36:43', 2),
(16, 8, 1, 'Under Review', 'Flagged by Encoder: Wrong reference number', '2026-04-22 01:20:22', 6),
(17, 8, 1, 'Under Review', 'Franchisee updated payment details and resubmitted.', '2026-04-22 01:21:09', 1),
(18, 6, 2, 'for_payment', 'Order confirmed by encoder. Awaiting payment verification before forwarding to warehouse.', '2026-04-22 01:49:14', 6),
(19, 6, 2, 'for_payment', 'Order confirmed by encoder. Awaiting payment verification before forwarding to warehouse.', '2026-04-22 01:57:12', 6),
(20, 6, 2, 'for_payment', 'Order confirmed by encoder. Awaiting payment verification before forwarding to warehouse.', '2026-04-22 01:57:20', 6),
(21, 6, 3, 'Processing', 'Payment verified. Order forwarded to Inventory Clerk for fulfillment.', '2026-04-22 02:20:41', 6),
(22, 9, 0, 'Order Submitted', 'Purchase order successfully received by the system.', '2026-04-22 02:21:08', 1),
(23, 9, 1, 'Under Review', 'Our team is verifying item availability and branch details.', '2026-04-22 02:21:08', 1),
(24, 9, 2, 'Approved', 'Order approved by administrator and assigned to Data Encoder: Data Encoder.', '2026-04-22 02:21:29', 2),
(25, 8, 2, 'Approved', 'Order approved by administrator and assigned to Data Encoder: Data Encoder.', '2026-04-22 02:21:33', 2),
(26, 9, 2, 'for_payment', 'Order confirmed by encoder. Awaiting payment verification before forwarding to warehouse.', '2026-04-22 02:24:51', 6),
(27, 9, 1, 'Under Review', 'Flagged by Encoder: Wrong number', '2026-04-23 00:28:41', 6),
(28, 10, 0, 'Order Submitted', 'Purchase order successfully received by the system.', '2026-04-23 00:29:42', 1),
(29, 10, 1, 'Under Review', 'Our team is verifying item availability and branch details.', '2026-04-23 00:29:42', 1),
(30, 10, 2, 'Approved', 'Order approved by administrator and assigned to Data Encoder: Data Encoder.', '2026-04-23 00:30:08', 2),
(31, 10, 2, 'for_payment', 'Order confirmed by encoder. Awaiting payment verification before forwarding to warehouse.', '2026-04-23 00:30:17', 6),
(32, 10, 1, 'Under Review', 'Flagged by Encoder: Wrong number', '2026-04-23 00:30:30', 6),
(33, 10, 1, 'Under Review', 'Franchisee updated payment details and resubmitted.', '2026-04-23 00:30:48', 1),
(34, 10, 2, 'for_payment', 'Order confirmed by encoder. Awaiting payment verification before forwarding to warehouse.', '2026-04-23 00:36:44', 6),
(35, 10, 3, 'Processing', 'Order marked as Ready for Fulfillment by encoder. Forwarded to Inventory Clerk. Assigned Clerk: Clerk.', '2026-04-23 00:36:49', 6),
(36, 8, 4, 'Completed', 'Order fulfilled by Inventory Clerk Clerk. Stock deducted from warehouse. Rider assigned: Delivery Rider.', '2026-04-23 00:59:51', 4),
(37, 8, 4, 'Completed', 'Order fulfilled by Inventory Clerk Clerk. Stock deducted from warehouse. Rider assigned: Delivery Rider.', '2026-04-23 01:00:08', 4),
(38, 8, 4, 'Completed', 'Order fulfilled by Inventory Clerk Clerk. Stock deducted from warehouse. Rider assigned: Delivery Rider.', '2026-04-23 01:05:06', 4),
(39, 8, 4, 'Completed', 'Order fulfilled by Inventory Clerk Clerk. Stock deducted from warehouse. Rider assigned: Delivery Rider.', '2026-04-23 01:12:40', 4),
(40, 8, 4, 'Completed', 'Order fulfilled by Inventory Clerk Clerk. Stock deducted from warehouse. Rider assigned: Delivery Rider.', '2026-04-23 01:25:53', 4),
(41, 6, 4, 'Completed', 'Order fulfilled by Inventory Clerk Clerk. Stock deducted from warehouse. Rider assigned: Delivery Rider.', '2026-04-23 01:26:03', 4),
(42, 6, 4, 'Completed', 'Order fulfilled by Inventory Clerk Clerk. Stock deducted from warehouse.', '2026-04-23 01:39:43', 4),
(43, 8, 4, 'Completed', 'Order fulfilled by Inventory Clerk Clerk. Stock deducted from warehouse.', '2026-04-23 01:39:50', 4),
(44, 10, 4, 'Completed', 'Order fulfilled by Inventory Clerk Clerk. Stock deducted from warehouse.', '2026-04-23 01:39:56', 4),
(45, 11, 0, 'Order Submitted', 'Purchase order successfully received by the system.', '2026-05-04 01:08:40', 1),
(46, 11, 1, 'Under Review', 'Our team is verifying item availability and branch details.', '2026-05-04 01:08:40', 1),
(47, 12, 0, 'Order Submitted', 'Purchase order successfully received by the system.', '2026-05-09 03:16:03', 1),
(48, 12, 1, 'Under Review', 'Our team is verifying item availability and branch details.', '2026-05-09 03:16:03', 1),
(49, 13, 0, 'Order Submitted', 'Purchase order successfully received by the system.', '2026-05-09 03:16:42', 1),
(50, 13, 1, 'Under Review', 'Our team is verifying item availability and branch details.', '2026-05-09 03:16:42', 1),
(51, 14, 0, 'Order Submitted', 'Purchase order successfully received by the system.', '2026-05-09 03:17:13', 1),
(52, 14, 1, 'Under Review', 'Our team is verifying item availability and branch details.', '2026-05-09 03:17:13', 1),
(53, 14, 2, 'Approved', 'Order approved by administrator and assigned to Data Encoder: Data Encoder.', '2026-05-09 03:17:45', 2),
(54, 13, 2, 'Approved', 'Order approved by administrator and assigned to Data Encoder: Data Encoder.', '2026-05-09 03:18:01', 2),
(55, 12, 2, 'Approved', 'Order approved by administrator and assigned to Data Encoder: Data Encoder.', '2026-05-09 03:18:11', 2),
(56, 11, -1, 'Rejected', 'Order rejected by administrator. Reason: sddss', '2026-05-09 03:18:21', 2),
(57, 9, -1, 'Rejected', 'Order rejected by administrator. Reason: aasdad', '2026-05-09 03:18:26', 2),
(58, 7, -1, 'Rejected', 'Order rejected by administrator. Reason: asdsdad', '2026-05-09 03:18:31', 2),
(59, 14, 2, 'For Payment', 'Order confirmed by encoder. Awaiting payment verification before forwarding to warehouse.', '2026-05-09 03:18:56', 6),
(60, 14, 3, 'Processing', 'Order marked as Ready for Fulfillment by encoder. Forwarded to Inventory Clerk. Assigned Clerk: Clerk.', '2026-05-09 03:19:08', 6),
(61, 13, 2, 'For Payment', 'Order confirmed by encoder. Awaiting payment verification before forwarding to warehouse.', '2026-05-09 03:19:12', 6),
(62, 13, 3, 'Processing', 'Order marked as Ready for Fulfillment by encoder. Forwarded to Inventory Clerk. Assigned Clerk: Clerk.', '2026-05-09 03:19:17', 6),
(63, 12, 2, 'For Payment', 'Order confirmed by encoder. Awaiting payment verification before forwarding to warehouse.', '2026-05-09 03:19:22', 6),
(64, 12, 3, 'Processing', 'Order marked as Ready for Fulfillment by encoder. Forwarded to Inventory Clerk. Assigned Clerk: Clerk.', '2026-05-09 03:19:28', 6),
(65, 12, 4, 'Completed', 'Order fulfilled by Inventory Clerk Clerk. Stock deducted. Ready for franchisee pickup.', '2026-05-09 03:19:56', 4),
(66, 13, 3, 'Out for Delivery', 'Order fulfilled by Inventory Clerk Clerk. Stock deducted. Ready for rider pickup.', '2026-05-09 03:20:03', 4),
(67, 14, 3, 'Out for Delivery', 'Order fulfilled by Inventory Clerk Clerk. Stock deducted. Ready for rider pickup.', '2026-05-09 03:20:10', 4),
(68, 13, 3, 'Picked Up', 'Order collected from warehouse by delivery rider.', '2026-05-09 03:21:17', 7),
(69, 13, 3, 'In Transit', 'Order is en route to the franchisee branch.', '2026-05-09 03:21:18', 7);

-- --------------------------------------------------------

--
-- Table structure for table `payment_logs`
--

CREATE TABLE `payment_logs` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `franchisee_id` int(11) DEFAULT NULL,
  `payment_method` enum('cod','gcash','bank') NOT NULL,
  `status` enum('submitted','for_review','approved','rejected') NOT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `proof` text DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `product_code` varchar(20) DEFAULT NULL,
  `category` varchar(50) NOT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `stock_qty` int(11) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'available',
  `low_stock_threshold` int(11) DEFAULT 10,
  `unit` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `name`, `product_code`, `category`, `price`, `stock_qty`, `status`, `low_stock_threshold`, `unit`) VALUES
(0, 'Caramel Syrup', '0CarSy', 'Syrup', 0.00, 100, 'available', 10, '2.5kg'),
(1, 'Wintermelon Powder', '1COWi', 'Powder Flavor', 222.00, 98, 'available', 10, '1kg'),
(2, 'Okinawa Powder', '2COOk', 'Powder Flavor', 222.00, 100, 'available', 10, '1kg'),
(3, 'Matcha Powder', '3COMa', 'Powder Flavor', 273.00, 99, 'available', 10, '1kg'),
(4, 'Salted Caramel Powder', '4COSa', 'Powder Flavor', 230.00, 100, 'available', 10, '1kg'),
(5, 'Taro Powder', '5THTa', 'Powder Flavor', 285.00, 100, 'available', 10, '1kg'),
(6, 'Strawberry Powder', '6THSl', 'Powder Flavor', 258.00, 100, 'available', 10, '1kg'),
(7, 'Dark Choco Powder', '7THDa', 'Powder Flavor', 321.00, 100, 'available', 10, '1kg'),
(8, 'Red Velvet Powder', '8THRe', 'Powder Flavor', 291.00, 100, 'available', 10, '1kg'),
(9, 'Mango Powder', '9THMa', 'Powder Flavor', 284.00, 100, 'available', 10, '1kg'),
(10, 'Rocky Road Powder', '10THRo', 'Powder Flavor', 305.00, 100, 'available', 10, '1kg'),
(11, 'Choco Mousse Powder', '11THCh', 'Powder Flavor', 302.00, 100, 'available', 10, '1kg'),
(12, 'Black Forest Powder', '12THBl', 'Powder Flavor', 306.00, 94, 'available', 10, '1kg'),
(13, 'Buko Pandan Powder', '13THBu', 'Powder Flavor', 265.00, 100, 'available', 10, '1kg'),
(14, 'Cookies & Cream Powder', '14THCo', 'Powder Flavor', 290.00, 100, 'available', 10, '1kg'),
(15, 'Double Dutch Powder', '15THDo', 'Powder Flavor', 346.00, 100, 'available', 10, '1kg'),
(16, 'Java Chip Powder', '16THJa', 'Powder Flavor', 314.00, 100, 'available', 10, '1kg'),
(17, 'Strawberry Syrup', '17THSl', 'Syrup', 255.00, 100, 'available', 10, '2L'),
(18, 'Blueberry Syrup', '18THBl', 'Syrup', 255.00, 100, 'available', 10, '2L'),
(19, 'Lychee Syrup', '19THLy', 'Syrup', 255.00, 100, 'available', 10, '2L'),
(20, 'Green Apple Syrup', '20THGr', 'Syrup', 255.00, 100, 'available', 10, '2L'),
(21, 'Passion Fruit Syrup', '21THPa', 'Syrup', 255.00, 100, 'available', 10, '2L'),
(22, 'Tapioca Pearl', '22THTa', 'Toppings', 112.00, 100, 'available', 10, '1kg'),
(23, 'Black Tea', '23THBl', 'Tea Base', 220.00, 100, 'available', 10, '500g'),
(24, 'Fructose', '24THFr', 'Sweetener', 160.00, 100, 'available', 10, '2kg'),
(25, 'Milk Tea Creamer', '25THMi', 'Creamer', 160.00, 96, 'available', 10, '1kg'),
(26, 'Crushed Oreo', '28THCr', 'Toppings', 205.00, 100, 'available', 10, '1kg'),
(27, 'Coffee Creamer', '31EBCo', 'Creamer', 235.00, 97, 'available', 10, '1kg'),
(28, 'Coffee Espresso', '32EBCo', 'Coffee Base', 465.00, 95, 'available', 10, '2L'),
(29, 'Frappe Base', '33EBFr', 'Base Mix', 260.00, 90, 'available', 10, '1kg'),
(30, 'Whipped Cream', '34EBWh', 'Toppings', 460.00, 100, 'available', 10, '1kg'),
(31, 'Cream Cheese Powder', '35EBCr', 'Powder Flavor', 280.00, 100, 'available', 10, '1kg'),
(32, 'Caramel Syrup', '36EBCa', 'Syrup', 340.00, 100, 'available', 10, '2L'),
(33, 'Salted Caramel Syrup', '37EBSa', 'Syrup', 340.00, 100, 'available', 10, '2L'),
(34, 'Mocha Syrup', '38EBMo', 'Syrup', 410.00, 100, 'available', 10, '2L'),
(35, 'Hazelnut Syrup', '39EBHa', 'Syrup', 340.00, 100, 'available', 10, '2L'),
(36, 'Vanilla Syrup', '40EBVa', 'Syrup', 340.00, 98, 'available', 10, '2L'),
(37, 'Brown Sugar Syrup', '41EBBr', 'Syrup', 340.00, 100, 'available', 10, '2L'),
(38, 'Cafe Mocha Powder', '42SNCa', 'Powder Flavor', 380.00, 100, 'available', 10, '1kg'),
(39, 'Nata', '39Nata', 'Toppings', 140.00, 100, 'available', 10, '1kg'),
(40, 'Coffee Jelly', '40CofJl', 'Toppings', 200.00, 100, 'available', 10, '1kg'),
(41, 'Nata Jar', '41NatJr', 'Toppings', 210.00, 100, 'available', 10, '1kg'),
(42, 'Strawberry Jam', '42StrJm', 'Fruit Flavor', 310.00, 102, 'available', 10, '3kg'),
(43, 'Blueberry Jam', '43SNDo', 'Fruit Flavor', 310.00, 97, 'available', 10, '3kg'),
(44, 'Mango Jam', '44SNFl', 'Fruit Flavor', 310.00, 99, 'available', 10, '3kg'),
(45, 'Jersey Full Cream Milk', '45SNY', 'Milk', 90.00, 90, 'available', 10, '1L'),
(46, 'Biscoff Spread', '46SNY', 'Flavor', 320.00, 99, 'available', 10, '400g'),
(47, 'Biscoff Powder', '47BisPw', 'Powder Flavor', 275.00, 89, 'available', 10, '1kg'),
(48, 'Crushed Graham', '48CruGr', 'Toppings', 225.00, 100, 'available', 10, '1kg');

-- --------------------------------------------------------

--
-- Table structure for table `returns`
--

CREATE TABLE `returns` (
  `id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `franchisee_id` int(11) DEFAULT NULL,
  `item_name` varchar(255) DEFAULT NULL,
  `reason` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `receipt_number` varchar(100) DEFAULT NULL,
  `receipt_photo` varchar(255) DEFAULT NULL,
  `resolution_photo` varchar(255) DEFAULT NULL,
  `status` enum('Pending','Approved','Resolved','Rejected') DEFAULT 'Pending',
  `submitted_at` datetime DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `returns`
--

INSERT INTO `returns` (`id`, `order_id`, `franchisee_id`, `item_name`, `reason`, `notes`, `receipt_number`, `receipt_photo`, `resolution_photo`, `status`, `submitted_at`, `resolved_at`, `product_id`) VALUES
(1, 11, 7, 'Coffee Creamer', 'Damaged', '', '1234567890', 'uploads/return_receipts/receipt_69f7938bd80142.43372469.png', NULL, 'Pending', '2026-05-04 02:27:23', NULL, NULL),
(2, 11, 7, 'Frappe Base', 'Expired', '', '1234567890', 'uploads/return_receipts/receipt_69f7938bd80142.43372469.png', NULL, 'Pending', '2026-05-04 02:27:23', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `riders`
--

CREATE TABLE `riders` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `contact_number` varchar(50) DEFAULT NULL,
  `vehicle_type` varchar(50) DEFAULT NULL,
  `status` enum('available','busy','inactive') DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `role_id` bigint(20) NOT NULL,
  `role_name` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`role_id`, `role_name`) VALUES
(1, 'Administrator'),
(2, 'Franchisee'),
(3, 'Inventory Clerk'),
(4, 'Data Encoder'),
(5, 'Delivery Rider');

-- --------------------------------------------------------

--
-- Table structure for table `shelf_life`
--

CREATE TABLE `shelf_life` (
  `id` int(11) NOT NULL,
  `category` varchar(50) NOT NULL,
  `min_months` tinyint(3) UNSIGNED NOT NULL,
  `max_months` tinyint(3) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `shelf_life`
--

INSERT INTO `shelf_life` (`id`, `category`, `min_months`, `max_months`) VALUES
(1, 'Powder Flavor', 12, 12),
(2, 'Creamer', 12, 12),
(3, 'Base Mix', 12, 12),
(4, 'Syrup', 6, 8),
(5, 'Coffee Base', 6, 8),
(6, 'Tea Base', 6, 8),
(7, 'Fruit Flavor', 6, 8),
(8, 'Sweetener', 6, 8),
(9, 'Flavor', 6, 8),
(10, 'Milk', 8, 12),
(11, 'Toppings', 8, 12);

-- --------------------------------------------------------

--
-- Table structure for table `stock_adjustments`
--

CREATE TABLE `stock_adjustments` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `adj_type` enum('add','subtract') NOT NULL DEFAULT 'subtract',
  `quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
  `reason_code` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `adjusted_by` bigint(20) DEFAULT NULL,
  `adjusted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `stock_adjustments`
--

INSERT INTO `stock_adjustments` (`id`, `product_id`, `batch_id`, `adj_type`, `quantity`, `reason_code`, `notes`, `adjusted_by`, `adjusted_at`) VALUES
(1, 29, NULL, 'subtract', 10.00, 'Discrepancy', 'Mali yung pagbilang', 4, '2026-05-09 01:29:05');

-- --------------------------------------------------------

--
-- Table structure for table `stock_receipts`
--

CREATE TABLE `stock_receipts` (
  `id` int(11) NOT NULL,
  `batch_number` varchar(100) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unit` varchar(50) DEFAULT NULL,
  `source_type` varchar(50) DEFAULT 'Internal',
  `arrival_date` date DEFAULT NULL,
  `mfg_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `qc_notes` text DEFAULT NULL,
  `recorded_by` bigint(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `stock_receipts`
--

INSERT INTO `stock_receipts` (`id`, `batch_number`, `product_id`, `quantity`, `unit`, `source_type`, `arrival_date`, `mfg_date`, `expiry_date`, `qc_notes`, `recorded_by`, `created_at`) VALUES
(1, 'MFG-206-0908-19', 42, 10.00, '3kg', 'Internal', '2026-05-08', '2026-04-09', '2026-07-31', NULL, 4, '2026-05-08 18:23:03'),
(5, 'MFG-2026-0809', 29, 10.00, '1kg', 'Internal', '2026-05-09', '2026-04-09', '2026-05-14', NULL, 4, '2026-05-09 02:34:12');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` bigint(20) NOT NULL,
  `role_id` bigint(20) DEFAULT NULL,
  `username` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `full_name` varchar(255) DEFAULT NULL,
  `contact_number` bigint(20) DEFAULT NULL,
  `status` enum('Active','Inactive') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `role_id`, `username`, `email`, `password`, `full_name`, `contact_number`, `status`) VALUES
(1, 2, 'testfranchisee', 'testfranchisee@topjuan.com', '$2y$10$PmD3ULVTisL9grAhBb4.DOqQjHjE5iAfNFIFvOkY0O/J1O3nTW.Mu', 'Juan Dela Cruz', 9171234567, 'Active'),
(2, 1, 'admin', 'testadmin@topjuan.com', '$2y$10$uNQVlu6MUhfjskFCVYR0QeFpEoiMfMOfW9nuP0WrRt1VEnezOh/xO', 'Admin', 9171234567, 'Active'),
(4, 3, 'clerk', 'testclerk@topjuan.com', '$2y$10$TM5gLyXCkMcVbbOqS/MWBO2srLxgm6KE9/4GSIT4SRwz7vVVkspHm', 'Clerk', 9171234567, 'Active'),
(6, 4, 'dataencoder', 'testencoder@topjuan.com', '$2y$10$zGY0jlm5Ta9IVOn8crKxOO7uZdh64CLzhn0Rh5VZdU6zRsl90VOsC', 'Data Encoder', 9171234567, 'Active'),
(7, 5, 'deliveryrider', 'testrider@topjuan.com', '$2y$10$nCn6UTqHQwaV.vHPQtLX2ewH6f2YFuOVq1AKSPRNLcTmfXY5EdCyG', 'Delivery Rider', 9171234567, 'Active'),
(9, 2, 'mariesim', 'simmarie@topjuan.com', '$2y$10$LdXI6bHPRlE8Wt.eMoaJo.sEl.hU218UUMekQ8BhvC7/n0wOYFIqW', 'Marie Sim', 9978080987, 'Active'),
(13, 2, 'denisecasimero.pingkian', 'denisecasimero.pingkian@topjuan.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC02pfyR7uMK8smo8kXm', 'Denise Olive A. Casimero', 0, 'Active'),
(14, 2, 'denisecasimero.republic', 'denisecasimero.republic@topjuan.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC02pfyR7uMK8smo8kXm', 'Denise Olive A. Casimero', 0, 'Active'),
(15, 2, 'annilormarbella.stonino', 'annilormarbella.stonino@topjuan.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC02pfyR7uMK8smo8kXm', 'Annilor A. Marbella', 0, 'Active'),
(16, 2, 'norbertopagiwayan', 'norbertopagiwayan@topjuan.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC02pfyR7uMK8smo8kXm', 'Norberto Pag-iwayan', 0, 'Active'),
(17, 2, 'louiseabad', 'louiseabad@topjuan.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC02pfyR7uMK8smo8kXm', 'Louise Alyanna M. Abad', 0, 'Active'),
(18, 2, 'nifioburac', 'nifioburac@topjuan.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC02pfyR7uMK8smo8kXm', 'Niño E. Burac', 0, 'Active'),
(19, 2, 'cyrilfallar', 'cyrilfallar@topjuan.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC02pfyR7uMK8smo8kXm', 'Cyril Aran G. Fallar', 0, 'Active'),
(20, 2, 'jomerquillosa', 'jomerquillosa@topjuan.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC02pfyR7uMK8smo8kXm', 'Jomer L. Quillosa', 0, 'Active'),
(21, 2, 'elmoramos', 'elmoramos@topjuan.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC02pfyR7uMK8smo8kXm', 'Elmo F. Ramos', 0, 'Active'),
(22, 2, 'jirehagustin', 'jirehagustin@topjuan.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC02pfyR7uMK8smo8kXm', 'Jireh D. Agustin', 0, 'Active'),
(23, 2, 'apriljoyramosl', 'apriljoyramosl@topjuan.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC02pfyR7uMK8smo8kXm', 'April Joy R. Ramos', 0, 'Active'),
(24, 2, 'edisonmagno', 'edisonmagno@topjuan.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC02pfyR7uMK8smo8kXm', 'Edison J. Magno', 0, 'Active'),
(25, 2, 'annilormarbella.stodomingo', 'annilormarbella.stodomingo@topjuan.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC02pfyR7uMK8smo8kXm', 'Annilor A. Marbella', 0, 'Active'),
(26, 2, 'robertpante', 'robertpante@topjuan.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC02pfyR7uMK8smo8kXm', 'Robert John G. Pante', 0, 'Active'),
(27, 2, 'michellemiguel', 'michellemiguel@topjuan.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC02pfyR7uMK8smo8kXm', 'Michelle L. Miguel', 0, 'Active'),
(28, 2, 'erikatating', 'erikatating@topjuan.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC02pfyR7uMK8smo8kXm', 'Erika Denise J. Tating', 0, 'Active'),
(29, 2, 'harveymarquez', 'harveymarquez@topjuan.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC02pfyR7uMK8smo8kXm', 'Harvey R. Marquez', 0, 'Active'),
(30, 2, 'cecillediaz', 'cecillediaz@topjuan.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC02pfyR7uMK8smo8kXm', 'Cecille D. Diaz', 0, 'Active'),
(31, 2, 'virginiatapia', 'virginiatapia@topjuan.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC02pfyR7uMK8smo8kXm', 'Virginia Nidea-Tapia', 0, 'Active'),
(32, 2, 'evangelinecatinoy', 'evangelinecatinoy@topjuan.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC02pfyR7uMK8smo8kXm', 'Evangeline R. Catinoy', 0, 'Active'),
(33, 2, 'juliusgalicinao', 'juliusgalicinao@topjuan.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC02pfyR7uMK8smo8kXm', 'Julius Ryan B. Galicinao', 0, 'Active'),
(34, 2, 'allainjlolim', 'allainjlolim@topjuan.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC02pfyR7uMK8smo8kXm', 'Allain Joseph Lolim', 0, 'Active'),
(35, 2, 'rafaelitonicdao', 'rafaelitonicdao@topjuan.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC02pfyR7uMK8smo8kXm', 'Rafaelito L. Nicdao', 0, 'Active'),
(36, 2, 'denisecasimero.samat', 'denisecasimero.samat@topjuan.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC02pfyR7uMK8smo8kXm', 'Denise Olive A. Casimero', 0, 'Active'),
(37, 2, 'michhellecarcueva', 'michhellecarcueva@topjuan.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC02pfyR7uMK8smo8kXm', 'Michhelle N. Carcueva', 0, 'Active'),
(38, 2, 'julieannesibferrer', 'julieannesibferrer@topjuan.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC02pfyR7uMK8smo8kXm', 'Julie Anne Sibal Ferrer', 0, 'Active');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `auth_second_layer`
--
ALTER TABLE `auth_second_layer`
  ADD PRIMARY KEY (`id`),
  ADD KEY `reference_id` (`reference_id`),
  ADD KEY `requested_by` (`requested_by`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `fk_auth_second_layer_users` (`user_id`);

--
-- Indexes for table `delivery_logs`
--
ALTER TABLE `delivery_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `rider_id` (`rider_id`);

--
-- Indexes for table `franchisees`
--
ALTER TABLE `franchisees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `inventory_batches`
--
ALTER TABLE `inventory_batches`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ib_product` (`product_id`),
  ADD KEY `idx_ib_remaining` (`remaining_qty`);

--
-- Indexes for table `inventory_logs`
--
ALTER TABLE `inventory_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `franchisee_id` (`franchisee_id`);

--
-- Indexes for table `item_usage`
--
ALTER TABLE `item_usage`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_item_usage_products` (`product_id`),
  ADD KEY `fk_item_usage_franchisees` (`franchisee_id`),
  ADD KEY `idx_iu_usage_ref` (`usage_ref`),
  ADD KEY `idx_iu_is_default` (`is_default`);

--
-- Indexes for table `item_usage_defaults`
--
ALTER TABLE `item_usage_defaults`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_iud_fran_prod` (`franchisee_id`,`product_id`),
  ADD KEY `idx_iud_franchisee` (`franchisee_id`),
  ADD KEY `idx_iud_product` (`product_id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_orders_franchisees` (`franchisee_id`),
  ADD KEY `fk_orders_riders` (`assigned_rider_id`),
  ADD KEY `fk_orders_encoder_2` (`assigned_encoder_id`);

--
-- Indexes for table `order_approvals`
--
ALTER TABLE `order_approvals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `performed_by` (`performed_by`),
  ADD KEY `fk_order_approvals_users` (`approved_by`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_order_items_orders` (`order_id`),
  ADD KEY `fk_order_items_products` (`product_id`);

--
-- Indexes for table `order_status_history`
--
ALTER TABLE `order_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_order_status_history_orders` (`order_id`);

--
-- Indexes for table `payment_logs`
--
ALTER TABLE `payment_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `franchisee_id` (`franchisee_id`),
  ADD KEY `fk_payment_logs_orders` (`order_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `returns`
--
ALTER TABLE `returns`
  ADD PRIMARY KEY (`id`),
  ADD KEY `franchisee_id` (`franchisee_id`),
  ADD KEY `fk_returns_orders` (`order_id`),
  ADD KEY `fk_returns_products` (`product_id`);

--
-- Indexes for table `riders`
--
ALTER TABLE `riders`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`role_id`);

--
-- Indexes for table `shelf_life`
--
ALTER TABLE `shelf_life`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_shelf_life_category` (`category`);

--
-- Indexes for table `stock_adjustments`
--
ALTER TABLE `stock_adjustments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sa_product` (`product_id`),
  ADD KEY `idx_sa_batch` (`batch_id`),
  ADD KEY `idx_sa_adjusted` (`adjusted_at`),
  ADD KEY `idx_sa_adjby` (`adjusted_by`);

--
-- Indexes for table `stock_receipts`
--
ALTER TABLE `stock_receipts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sr_product` (`product_id`),
  ADD KEY `idx_sr_arrival` (`arrival_date`),
  ADD KEY `idx_sr_expiry` (`expiry_date`),
  ADD KEY `idx_sr_recorded` (`recorded_by`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `fk_users_roles` (`role_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `auth_second_layer`
--
ALTER TABLE `auth_second_layer`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `delivery_logs`
--
ALTER TABLE `delivery_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `franchisees`
--
ALTER TABLE `franchisees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

--
-- AUTO_INCREMENT for table `inventory_batches`
--
ALTER TABLE `inventory_batches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `inventory_logs`
--
ALTER TABLE `inventory_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `item_usage`
--
ALTER TABLE `item_usage`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `item_usage_defaults`
--
ALTER TABLE `item_usage_defaults`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `order_approvals`
--
ALTER TABLE `order_approvals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `order_status_history`
--
ALTER TABLE `order_status_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=70;

--
-- AUTO_INCREMENT for table `payment_logs`
--
ALTER TABLE `payment_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `returns`
--
ALTER TABLE `returns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `riders`
--
ALTER TABLE `riders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shelf_life`
--
ALTER TABLE `shelf_life`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `stock_adjustments`
--
ALTER TABLE `stock_adjustments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `stock_receipts`
--
ALTER TABLE `stock_receipts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `auth_second_layer`
--
ALTER TABLE `auth_second_layer`
  ADD CONSTRAINT `fk_auth_second_layer_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `franchisees`
--
ALTER TABLE `franchisees`
  ADD CONSTRAINT `fk_franchisees_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_franchisees_user2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_franchisees_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `inventory_batches`
--
ALTER TABLE `inventory_batches`
  ADD CONSTRAINT `fk_ib_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `inventory_logs`
--
ALTER TABLE `inventory_logs`
  ADD CONSTRAINT `fk_inventory_franchisee` FOREIGN KEY (`franchisee_id`) REFERENCES `franchisees` (`id`),
  ADD CONSTRAINT `fk_inventory_logs_products` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_inventory_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  ADD CONSTRAINT `fk_inventory_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `item_usage`
--
ALTER TABLE `item_usage`
  ADD CONSTRAINT `fk_item_usage_franchisees` FOREIGN KEY (`franchisee_id`) REFERENCES `franchisees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_item_usage_products` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `item_usage_ibfk_1` FOREIGN KEY (`franchisee_id`) REFERENCES `franchisees` (`id`),
  ADD CONSTRAINT `item_usage_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `item_usage_defaults`
--
ALTER TABLE `item_usage_defaults`
  ADD CONSTRAINT `fk_iud_franchisee` FOREIGN KEY (`franchisee_id`) REFERENCES `franchisees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_iud_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_orders_encoder` FOREIGN KEY (`assigned_encoder_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_orders_encoder_2` FOREIGN KEY (`assigned_encoder_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_orders_franchisees` FOREIGN KEY (`franchisee_id`) REFERENCES `franchisees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_orders_riders` FOREIGN KEY (`assigned_rider_id`) REFERENCES `riders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`franchisee_id`) REFERENCES `franchisees` (`id`);

--
-- Constraints for table `order_approvals`
--
ALTER TABLE `order_approvals`
  ADD CONSTRAINT `fk_order_approvals_orders` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_order_approvals_users` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `fk_order_items_orders` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_order_items_products` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  ADD CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `order_status_history`
--
ALTER TABLE `order_status_history`
  ADD CONSTRAINT `fk_order_status_history_orders` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `payment_logs`
--
ALTER TABLE `payment_logs`
  ADD CONSTRAINT `fk_payment_logs_orders` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `returns`
--
ALTER TABLE `returns`
  ADD CONSTRAINT `fk_returns_orders` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_returns_products` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `returns_ibfk_1` FOREIGN KEY (`franchisee_id`) REFERENCES `franchisees` (`id`);

--
-- Constraints for table `stock_adjustments`
--
ALTER TABLE `stock_adjustments`
  ADD CONSTRAINT `fk_sa_adjby` FOREIGN KEY (`adjusted_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_sa_batch` FOREIGN KEY (`batch_id`) REFERENCES `inventory_batches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_sa_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `stock_receipts`
--
ALTER TABLE `stock_receipts`
  ADD CONSTRAINT `fk_sr_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_sr_recorder` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_roles` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
