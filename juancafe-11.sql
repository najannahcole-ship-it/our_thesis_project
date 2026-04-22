-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 15, 2026 at 03:43 PM
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
(1, 'Talon 2, Las Piñas', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(2, 'Brixtonville, Caloocan', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(3, 'Lalaine Bennet, Las Pinas', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(4, 'Marcos Alvarez, Las Piñas', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(5, 'Star City, Pasay', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(6, 'Sun Valley, Parañaque City', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(7, 'Project 6, Quezon City', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
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
(20, 'Maligaya Park, Novaliches', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(21, 'Pasolo, Valenzuela City', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(22, 'Concepcion, Marikina', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(23, 'Honradez, Sampaloc, Manila', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(24, 'Kaligayahan, QC', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
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
(35, 'Balingasa Rd., Bonifacio', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(36, 'Pampano St., Longos', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(37, 'Lawin St., Makati', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(38, 'San Antonio Valley 1', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(39, 'Calzada Tipas, Taguig', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(40, 'Veterans Vill., Holy Spirit', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(41, 'Payatas A, Quezon City', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(42, 'Noli St., Malate', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(43, 'UPS5, Parañaque', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(44, 'BCDA, Taguig', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(45, 'Banlat, Tandang Sora', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39'),
(46, 'Gatchalian, Parañaque', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-12 16:47:32', '2026-04-12 16:47:39');

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
(2, 3, 1, 1, 'deduct', 1, '1kg', 100, 99, 'Order deduction for PO-PO-0001', 1, '2026-04-12 17:51:41');

-- --------------------------------------------------------

--
-- Table structure for table `item_usage`
--

CREATE TABLE `item_usage` (
  `id` int(11) NOT NULL,
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

INSERT INTO `item_usage` (`id`, `franchisee_id`, `product_id`, `quantity_used`, `unit`, `recording_date`, `submitted_at`, `order_id`, `is_default`, `status`) VALUES
(1, 1, 1, 5, 'g', '2026-03-13', '2026-04-02 03:19:37', NULL, 1, 'active'),
(2, 2, 17, 3, 'ml', '2026-03-13', '2026-04-02 03:19:37', NULL, 1, 'active'),
(3, 3, 22, 2, 'pcs', '2026-03-13', '2026-04-02 03:19:37', NULL, 1, 'active');

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
  `assigned_encoder_id` bigint(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `po_number`, `franchisee_id`, `status`, `status_step`, `delivery_preference`, `delivery_fee`, `subtotal`, `total_amount`, `created_at`, `estimated_pickup`, `payment_method`, `payment_status`, `payment_proof`, `approved_by`, `approved_at`, `rider_id`, `delivered_at`, `payment_due`, `has_out_of_stock`, `payment_ref`, `payment_confirmed_by`, `payment_confirmed_at`, `payment_attempts`, `delivery_status`, `assigned_rider_id`, `assigned_encoder_id`) VALUES
(1, 'PO-0001', 1, 'pending', 0, 'delivery', 50.00, 500.00, 550.00, '2026-04-02 03:19:06', NULL, NULL, 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, 0, 'pending', NULL, NULL),
(2, 'PO-0002', 2, 'completed', 0, 'pickup', 0.00, 300.00, 300.00, '2026-04-02 03:19:06', NULL, NULL, 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, 0, 'pending', NULL, NULL),
(3, 'PO-0003', 3, 'pending', 0, 'delivery', 30.00, 400.00, 430.00, '2026-04-02 03:19:06', NULL, NULL, 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, 0, 'pending', NULL, NULL);

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
(4, 3, 22, 3, 112.00, NULL, NULL, 'available', '2026-04-12 17:14:38');

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

INSERT INTO `products` (`id`, `name`, `category`, `price`, `stock_qty`, `status`, `low_stock_threshold`, `unit`) VALUES
(0, 'Caramel Syrup', 'Syrup', 0.00, 100, 'available', 10, '2.5kg'),
(1, 'Wintermelon Powder', 'Powder Flavor', 222.00, 98, 'available', 10, '1kg'),
(2, 'Okinawa Powder', 'Powder Flavor', 222.00, 100, 'available', 10, '1kg'),
(3, 'Matcha Powder', 'Powder Flavor', 273.00, 99, 'available', 10, '1kg'),
(4, 'Salted Caramel Powder', 'Powder Flavor', 230.00, 100, 'available', 10, '1kg'),
(5, 'Taro Powder', 'Powder Flavor', 285.00, 100, 'available', 10, '1kg'),
(6, 'Strawberry Powder', 'Powder Flavor', 258.00, 100, 'available', 10, '1kg'),
(7, 'Dark Choco Powder', 'Powder Flavor', 321.00, 100, 'available', 10, '1kg'),
(8, 'Red Velvet Powder', 'Powder Flavor', 291.00, 100, 'available', 10, '1kg'),
(9, 'Mango Powder', 'Powder Flavor', 284.00, 100, 'available', 10, '1kg'),
(10, 'Rocky Road Powder', 'Powder Flavor', 305.00, 100, 'available', 10, '1kg'),
(11, 'Choco Mousse Powder', 'Powder Flavor', 302.00, 100, 'available', 10, '1kg'),
(12, 'Black Forest Powder', 'Powder Flavor', 306.00, 100, 'available', 10, '1kg'),
(13, 'Buko Pandan Powder', 'Powder Flavor', 265.00, 100, 'available', 10, '1kg'),
(14, 'Cookies & Cream Powder', 'Powder Flavor', 290.00, 100, 'available', 10, '1kg'),
(15, 'Double Dutch Powder', 'Powder Flavor', 346.00, 100, 'available', 10, '1kg'),
(16, 'Java Chip Powder', 'Powder Flavor', 314.00, 100, 'available', 10, '1kg'),
(17, 'Strawberry Syrup', 'Syrup', 255.00, 100, 'available', 10, '2L'),
(18, 'Blueberry Syrup', 'Syrup', 255.00, 100, 'available', 10, '2L'),
(19, 'Lychee Syrup', 'Syrup', 255.00, 100, 'available', 10, '2L'),
(20, 'Green Apple Syrup', 'Syrup', 255.00, 100, 'available', 10, '2L'),
(21, 'Passion Fruit Syrup', 'Syrup', 255.00, 100, 'available', 10, '2L'),
(22, 'Tapioca Pearl', 'Toppings', 112.00, 100, 'available', 10, '1kg'),
(23, 'Black Tea', 'Tea Base', 220.00, 100, 'available', 10, '500g'),
(24, 'Fructose', 'Sweetener', 160.00, 100, 'available', 10, '2kg'),
(25, 'Milk Tea Creamer', 'Creamer', 160.00, 100, 'available', 10, '1kg'),
(26, 'Crushed Oreo', 'Toppings', 205.00, 100, 'available', 10, '1kg'),
(27, 'Coffee Creamer', 'Creamer', 235.00, 100, 'available', 10, '1kg'),
(28, 'Coffee Espresso', 'Coffee Base', 465.00, 100, 'available', 10, '2L'),
(29, 'Frappe Base', 'Base Mix', 260.00, 100, 'available', 10, '1kg'),
(30, 'Whipped Cream', 'Toppings', 460.00, 100, 'available', 10, '1kg'),
(31, 'Cream Cheese Powder', 'Powder Flavor', 280.00, 100, 'available', 10, '1kg'),
(32, 'Caramel Syrup', 'Syrup', 340.00, 100, 'available', 10, '2L'),
(33, 'Salted Caramel Syrup', 'Syrup', 340.00, 100, 'available', 10, '2L'),
(34, 'Mocha Syrup', 'Syrup', 410.00, 100, 'available', 10, '2L'),
(35, 'Hazelnut Syrup', 'Syrup', 340.00, 100, 'available', 10, '2L'),
(36, 'Vanilla Syrup', 'Syrup', 340.00, 100, 'available', 10, '2L'),
(37, 'Brown Sugar Syrup', 'Syrup', 340.00, 100, 'available', 10, '2L'),
(38, 'Cafe Mocha Powder', 'Powder Flavor', 380.00, 100, 'available', 10, '1kg'),
(39, 'Nata', 'Toppings', 140.00, 100, 'available', 10, '1kg'),
(40, 'Coffee Jelly', 'Toppings', 200.00, 100, 'available', 10, '1kg'),
(41, 'Nata Jar', 'Toppings', 210.00, 100, 'available', 10, '1kg'),
(42, 'Strawberry Jam', 'Fruit Flavor', 310.00, 100, 'available', 10, '3kg'),
(43, 'Blueberry Jam', 'Fruit Flavor', 310.00, 100, 'available', 10, '3kg'),
(44, 'Mango Jam', 'Fruit Flavor', 310.00, 100, 'available', 10, '3kg'),
(45, 'Jersey Full Cream Milk', 'Milk', 90.00, 100, 'available', 10, '1L'),
(46, 'Biscoff Spread', 'Flavor', 320.00, 100, 'available', 10, '400g'),
(47, 'Biscoff Powder', 'Powder Flavor', 275.00, 100, 'available', 10, '1kg'),
(48, 'Crushed Graham', 'Toppings', 225.00, 100, 'available', 10, '1kg');

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
  `status` enum('Pending','Approved','Resolved','Rejected') DEFAULT 'Pending',
  `submitted_at` datetime DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  ADD KEY `fk_item_usage_franchisees` (`franchisee_id`);

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
-- AUTO_INCREMENT for table `inventory_logs`
--
ALTER TABLE `inventory_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `item_usage`
--
ALTER TABLE `item_usage`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `order_approvals`
--
ALTER TABLE `order_approvals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `order_status_history`
--
ALTER TABLE `order_status_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_logs`
--
ALTER TABLE `payment_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `returns`
--
ALTER TABLE `returns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `riders`
--
ALTER TABLE `riders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` bigint(20) NOT NULL AUTO_INCREMENT;

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
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_roles` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
