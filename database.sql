-- phpMyAdmin SQL Dump
-- version 4.9.0.1
-- https://www.phpmyadmin.net/
--
-- Host: sql113.infinityfree.com
-- Generation Time: 03 سبتمبر 2026 الساعة 02:53
-- إصدار الخادم: 11.4.13-MariaDB
-- PHP Version: 7.2.22

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `if0_42724589_autocare_db`
--

-- --------------------------------------------------------

--
-- بنية الجدول `account_statement_requests`
--

CREATE TABLE `account_statement_requests` (
  `id` int(10) UNSIGNED NOT NULL,
  `customer_id` int(10) UNSIGNED NOT NULL,
  `from_date` date DEFAULT NULL,
  `to_date` date DEFAULT NULL,
  `status` enum('generated','pending','viewed') NOT NULL DEFAULT 'generated',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `user_name` varchar(100) DEFAULT NULL,
  `user_role` varchar(50) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `table_name` varchar(50) NOT NULL,
  `record_id` int(10) UNSIGNED DEFAULT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `user_name`, `user_role`, `action`, `table_name`, `record_id`, `description`, `ip_address`, `created_at`) VALUES
(1, 1, 'م.مازن عبده حمود العزي', 'admin', 'add', 'users', 2, 'إضافة موظف جديد: علي البخيتي (Ali_emp)', '31.31.191.180', '2026-08-25 23:28:47'),
(2, 1, 'م.مازن عبده حمود العزي', 'admin', 'add', 'customers', 1, 'تسجيل عميل جديد: ابراهيم المخلافي (777239330)', '31.31.191.180', '2026-08-25 23:29:17'),
(3, 3, 'محمد', 'customer', 'add', 'vehicles', 1, 'إضافة سيارة جديدة: تويوتا كامري (1234)', '81.91.29.108', '2026-08-25 23:56:10'),
(4, 3, 'محمد', 'customer', 'edit', 'vehicles', 1, 'تعديل بيانات السيارة: تويوتا كامري (1234)', '81.91.29.108', '2026-08-25 23:56:29'),
(5, 1, 'م.مازن عبده حمود العزي', 'admin', 'edit', 'users', 2, 'تعديل بيانات الموظف: علي البخيتي', '198.50.119.86', '2026-08-30 00:01:41'),
(6, 1, 'م.مازن عبده حمود العزي', 'admin', 'add', 'customers', 4, 'تسجيل عميل جديد: محمد احمد (9678464066)', '198.50.119.86', '2026-08-30 00:02:23'),
(7, 1, 'م.مازن عبده حمود العزي', 'admin', 'delete', 'customers', 4, 'حذف العميل: محمد احمد', '198.50.119.86', '2026-08-30 00:02:36'),
(8, 1, 'م.مازن عبده حمود العزي', 'admin', 'delete', 'customers', 3, 'حذف العميل: هلا', '198.50.119.86', '2026-08-30 00:02:48'),
(9, 1, 'م.مازن عبده حمود العزي', 'admin', 'edit', 'customers', 1, 'تعديل بيانات العميل: ابراهيم المخلافي', '198.50.119.86', '2026-08-30 00:06:32'),
(10, 5, 'احمد محمد العزي', 'customer', 'add', 'vehicles', 2, 'إضافة سيارة جديدة: Toyota Avalon (1)', '198.50.119.86', '2026-08-30 00:09:19'),
(11, 5, 'احمد محمد العزي', 'customer', 'create', 'work_orders', 1, 'إنشاء أمر صيانة #WO-202608-5259 بتكلفة تقديرية 250 ر.س', '198.50.119.86', '2026-08-30 00:09:31'),
(12, 1, 'م.مازن عبده حمود العزي', 'admin', 'edit', 'user_permissions', 2, 'تعطيل صلاحية (manage_customers) للمستخدم: علي البخيتي', '198.50.119.86', '2026-08-30 00:11:15'),
(13, 1, 'م.مازن عبده حمود العزي', 'admin', 'edit', 'user_permissions', 2, 'تعطيل صلاحية (manage_customers) للمستخدم: علي البخيتي', '198.50.119.86', '2026-08-30 00:11:16'),
(14, 1, 'م.مازن عبده حمود العزي', 'admin', 'edit', 'user_permissions', 2, 'تعطيل صلاحية (manage_customers) للمستخدم: علي البخيتي', '198.50.119.86', '2026-08-30 00:11:17'),
(15, 1, 'م.مازن عبده حمود العزي', 'admin', 'edit', 'user_permissions', 2, 'تعطيل صلاحية (manage_vehicles) للمستخدم: علي البخيتي', '198.50.119.86', '2026-08-30 00:11:31'),
(16, 1, 'م.مازن عبده حمود العزي', 'admin', 'edit', 'user_permissions', 2, 'تعطيل صلاحية (manage_services) للمستخدم: علي البخيتي', '198.50.119.86', '2026-08-30 00:11:46'),
(17, 1, 'م.مازن عبده حمود العزي', 'admin', 'edit', 'user_permissions', 2, 'تعطيل صلاحية (create_work_orders) للمستخدم: علي البخيتي', '198.50.119.86', '2026-08-30 00:12:03');

-- --------------------------------------------------------

--
-- بنية الجدول `customers`
--

CREATE TABLE `customers` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `full_name` varchar(120) NOT NULL,
  `phone` varchar(25) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `customers`
--

INSERT INTO `customers` (`id`, `user_id`, `full_name`, `phone`, `email`, `address`, `created_at`, `updated_at`) VALUES
(1, NULL, 'ابراهيم المخلافي', '777239330', 'Ebrahim@gmail.com', 'تعز شرعب', '2026-08-25 23:29:17', '2026-08-25 23:29:17'),
(2, 3, 'محمد', '434353', 'm1@gmail.com', 'ذمريكا', '2026-08-25 23:54:54', '2026-08-25 23:54:54'),
(5, 5, 'احمد محمد العزي', '778823635', 'ahmes@gmail.com', 'صنعاء العاصمه', '2026-08-30 00:08:20', '2026-08-30 00:08:20');

-- --------------------------------------------------------

--
-- بنية الجدول `invoices`
--

CREATE TABLE `invoices` (
  `id` int(10) UNSIGNED NOT NULL,
  `invoice_number` varchar(30) NOT NULL,
  `work_order_id` int(10) UNSIGNED NOT NULL,
  `customer_id` int(10) UNSIGNED NOT NULL,
  `vehicle_id` int(10) UNSIGNED NOT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum('cash','card','bank_transfer') NOT NULL DEFAULT 'cash',
  `status` enum('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `notifications`
--

CREATE TABLE `notifications` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `target_role` enum('admin','employee','all_staff','customer') NOT NULL DEFAULT 'all_staff',
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `customer_name` varchar(120) DEFAULT NULL,
  `customer_phone` varchar(30) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `invoice_id` int(10) UNSIGNED DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `payments`
--

CREATE TABLE `payments` (
  `id` int(10) UNSIGNED NOT NULL,
  `invoice_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` enum('cash','card','bank_transfer') NOT NULL DEFAULT 'cash',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `services`
--

CREATE TABLE `services` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','inactive','pending','pending_delete','deleted') NOT NULL DEFAULT 'active',
  `is_primary_admin` tinyint(1) NOT NULL DEFAULT 0,
  `deletion_requested` tinyint(1) NOT NULL DEFAULT 0,
  `deletion_requested_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `services`
--

INSERT INTO `services` (`id`, `name`, `description`, `price`, `status`, `created_at`) VALUES
(1, 'فحص شامل للسيارة والكمبيوتر', 'فحص كامل لجميع الحساسات وكمبيوتر المحرك والقير والكهرباء', '150.00', 'active', '2026-08-25 23:23:20'),
(2, 'تغيير زيت المحرك مع الفلتر الأصلي', 'تبديل زيت المحرك التخليقي بالكامل مع فلتر وكالة أصلي', '220.00', 'active', '2026-08-25 23:23:20'),
(3, 'صيانة وتبديل فحمات الفرامل الأمامية', 'استبدال فحمات الفرامل الأمامية وفحص مستوى زيت الفرامل', '180.00', 'active', '2026-08-25 23:23:20'),
(4, 'صيانة وتبديل فحمات الفرامل الخلفية', 'استبدال فحمات الفرامل الخلفية وفحص جلب الكليبرات', '160.00', 'active', '2026-08-25 23:23:20'),
(5, 'خرط هوبات الفرامل وميزان دقيق', 'خرط الهوبات الأمامية والخلفية بمكينة ليزر دقيقة لمنع الرجة', '120.00', 'active', '2026-08-25 23:23:20'),
(6, 'فحص وتعبئة غاز مكيف السيارة مع تنظيف الفلتر', 'تفريغ وتعبئة فريون أصلي وفحص التسريب مع تنظيف فلتر المكيف', '200.00', 'active', '2026-08-25 23:23:20'),
(7, 'صيانة دورية 10,000 كم (بواجي، فلاتر، سوائل)', 'صيانة دورية شاملة تشمل البواجي، فلتر الهواء، وفحص كافة السوائل', '350.00', 'active', '2026-08-25 23:23:20'),
(8, 'وزن أذرعة وميزان ليزر للعجلات', 'ضبط زوايا العجلات والمقصات والأذرعة بجهاز الليزر ثلاثي الأبعاد', '100.00', 'active', '2026-08-25 23:23:20'),
(9, 'تغيير سائل ناقل الحركة (زيت القير) مع الفلتر', 'استبدال زيت القير الأوتوماتيكي مع فلتر القير وتنظيف الكارتير', '400.00', 'active', '2026-08-25 23:23:20'),
(10, 'تبديل بطارية السيارة مع فحص الدينامو', 'تركيب بطارية جديدة وفحص كفاءة شحن الدينامو والكهرباء', '320.00', 'active', '2026-08-25 23:23:20'),
(11, 'تنظيف بخاخات الوقود وغرفة الاحتراق', 'تنظيف البخاخات بجهاز الموجات الصوتية وتنظيف بوابة الهواء', '250.00', 'active', '2026-08-25 23:23:20'),
(12, 'فحص وتغيير سائل تبريد الرديتر', 'تفريغ دورة التبريد وتعبئة ماء رديتر أصلي مانع للصدأ والغليان', '140.00', 'active', '2026-08-25 23:23:20');

-- --------------------------------------------------------

--
-- بنية الجدول `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(25) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','employee','customer') NOT NULL DEFAULT 'customer',
  `status` enum('active','inactive','pending','pending_delete','deleted') NOT NULL DEFAULT 'active',
  `is_primary_admin` tinyint(1) NOT NULL DEFAULT 0,
  `deletion_requested` tinyint(1) NOT NULL DEFAULT 0,
  `deletion_requested_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `users`
--

INSERT INTO `users` (`id`, `name`, `username`, `email`, `phone`, `password`, `role`, `status`, `is_primary_admin`, `deletion_requested`, `deletion_requested_at`, `created_at`, `updated_at`) VALUES
(1, 'م.مازن عبده حمود العزي', 'admin', 'mazenalazey1@gmail.com', '00967777038636', '$2y$10$xWEH05kGrpLGOMU/57u.sui9pF.W/CalSvsgHzTTT6RaHMyF2eM2u', 'admin', 'active', 1, 0, NULL, '2026-08-25 23:27:32', '2026-08-29 23:56:50'),
(2, 'علي البخيتي', 'Ali_emp', 'krata2814@gmail.com', '781802671', '$2y$10$znqRkg6HjlyqCUwZ9YeHwewaKIW.PdV0x2UefZOCa9pDjzxjq2R9e', 'employee', 'active', 0, 0, NULL, '2026-08-25 23:28:47', '2026-08-30 00:01:41'),
(3, 'محمد', 'm1_164', 'm1@gmail.com', '434353', '$2y$10$LhVM3X6xkMXSJmuIIt9QBO65BDwe9.wrbj6ZZNj9eyveNA0PZaywa', 'customer', 'active', 0, 0, NULL, '2026-08-25 23:54:54', '2026-08-25 23:54:54'),
(4, 'هلا', 'amynjhggwbh9_514', 'amynjhggwbh9@gmail.com', '75588', '$2y$10$2qOCREPgbyf3qu46dtANS.pRoNrq8KamAGOjTgLQGuPtro1.Pxu2O', 'customer', 'active', 0, 0, NULL, '2026-08-26 10:56:05', '2026-08-26 10:56:05'),
(5, 'احمد محمد العزي', 'ahmes_758', 'ahmes@gmail.com', '778823635', '$2y$10$Ihgm26t4jUtw21/G6LLH4u3BCvfsABu8w8jXcdiC036PtDXC191tK', 'customer', 'active', 0, 0, NULL, '2026-08-30 00:08:20', '2026-08-30 00:08:20');

-- --------------------------------------------------------

--
-- بنية الجدول `user_permissions`
--

CREATE TABLE `user_permissions` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `permission_key` varchar(50) NOT NULL,
  `is_granted` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `user_permissions`
--

INSERT INTO `user_permissions` (`id`, `user_id`, `permission_key`, `is_granted`, `created_at`, `updated_at`) VALUES
(1, 2, 'manage_customers', 0, '2026-08-30 00:11:15', '2026-08-30 00:11:17'),
(4, 2, 'manage_vehicles', 0, '2026-08-30 00:11:31', '2026-08-30 00:11:31'),
(5, 2, 'manage_services', 0, '2026-08-30 00:11:46', '2026-08-30 00:11:46'),
(6, 2, 'create_work_orders', 0, '2026-08-30 00:12:03', '2026-08-30 00:12:03');

-- --------------------------------------------------------

--
-- بنية الجدول `vehicles`
--

CREATE TABLE `vehicles` (
  `id` int(10) UNSIGNED NOT NULL,
  `customer_id` int(10) UNSIGNED NOT NULL,
  `plate_number` varchar(20) NOT NULL,
  `plate_letters` varchar(20) DEFAULT NULL,
  `make` varchar(50) NOT NULL,
  `model` varchar(50) NOT NULL,
  `year` smallint(5) UNSIGNED NOT NULL,
  `color` varchar(30) DEFAULT NULL,
  `vin` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `vehicles`
--

INSERT INTO `vehicles` (`id`, `customer_id`, `plate_number`, `plate_letters`, `make`, `model`, `year`, `color`, `vin`, `notes`, `created_at`, `updated_at`) VALUES
(1, 2, '1234', '1', 'تويوتا', 'كامري', 2026, 'الحاصل', '14', 'الحاصل', '2026-08-25 23:56:10', '2026-08-25 23:56:29'),
(2, 5, '1', 'ا ب ب', 'Toyota', 'Avalon', 2026, 'سوداء', NULL, NULL, '2026-08-30 00:09:19', '2026-08-30 00:09:19');

-- --------------------------------------------------------

--
-- بنية الجدول `work_orders`
--

CREATE TABLE `work_orders` (
  `id` int(10) UNSIGNED NOT NULL,
  `order_number` varchar(30) NOT NULL,
  `customer_id` int(10) UNSIGNED NOT NULL,
  `vehicle_id` int(10) UNSIGNED NOT NULL,
  `assigned_employee_id` int(10) UNSIGNED DEFAULT NULL,
  `service_id` int(10) UNSIGNED DEFAULT NULL,
  `issue_description` text NOT NULL,
  `diagnosis_notes` text DEFAULT NULL,
  `estimated_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `actual_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('new','inspecting','in_progress','completed','delivered','cancelled') NOT NULL DEFAULT 'new',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `work_orders`
--

INSERT INTO `work_orders` (`id`, `order_number`, `customer_id`, `vehicle_id`, `assigned_employee_id`, `service_id`, `issue_description`, `diagnosis_notes`, `estimated_cost`, `actual_cost`, `status`, `created_at`, `updated_at`) VALUES
(1, 'WO-202608-5259', 5, 2, NULL, 11, 'شوف', NULL, '250.00', '0.00', 'new', '2026-08-30 00:09:31', '2026-08-30 00:09:31');


-- Extended AutoCare security and operations tables
CREATE TABLE IF NOT EXISTS `password_reset_requests` (`id` int unsigned NOT NULL AUTO_INCREMENT, `user_id` int unsigned NOT NULL, `token_hash` char(64) NOT NULL, `token_expires_at` timestamp NULL DEFAULT NULL, `status` enum('pending','approved','used','rejected','expired') NOT NULL DEFAULT 'pending', `requested_at` timestamp NULL DEFAULT current_timestamp(), `approved_at` timestamp NULL DEFAULT NULL, `approved_by` int unsigned DEFAULT NULL, `used_at` timestamp NULL DEFAULT NULL, PRIMARY KEY (`id`), KEY `idx_pr_status` (`status`), KEY `idx_pr_user` (`user_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `inventory_parts` (`id` int unsigned NOT NULL AUTO_INCREMENT, `name` varchar(150) NOT NULL, `part_number` varchar(80) DEFAULT NULL, `quantity` int NOT NULL DEFAULT 0, `min_quantity` int NOT NULL DEFAULT 0, `purchase_price` decimal(10,2) NOT NULL DEFAULT 0.00, `sale_price` decimal(10,2) NOT NULL DEFAULT 0.00, `status` enum('active','inactive') NOT NULL DEFAULT 'active', `created_at` timestamp NULL DEFAULT current_timestamp(), `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `appointments` (`id` int unsigned NOT NULL AUTO_INCREMENT, `customer_id` int unsigned NOT NULL, `vehicle_id` int unsigned NOT NULL, `appointment_date` date NOT NULL, `appointment_time` time NOT NULL, `service_id` int unsigned DEFAULT NULL, `notes` varchar(500) DEFAULT NULL, `status` enum('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending', `created_by` int unsigned DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(), `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), PRIMARY KEY (`id`), KEY `idx_appt_date` (`appointment_date`), KEY `idx_appt_customer` (`customer_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `account_statement_requests`
--
ALTER TABLE `account_statement_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_statement_customer` (`customer_id`),
  ADD KEY `idx_statement_created` (`created_at`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_audit_user` (`user_id`),
  ADD KEY `idx_audit_table` (`table_name`),
  ADD KEY `idx_audit_action` (`action`),
  ADD KEY `idx_audit_created` (`created_at`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD KEY `idx_customers_phone` (`phone`),
  ADD KEY `idx_customers_name` (`full_name`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD UNIQUE KEY `work_order_id` (`work_order_id`),
  ADD KEY `fk_invoices_customer` (`customer_id`),
  ADD KEY `fk_invoices_vehicle` (`vehicle_id`),
  ADD KEY `idx_invoices_number` (`invoice_number`),
  ADD KEY `idx_invoices_status` (`status`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notif_role` (`target_role`),
  ADD KEY `idx_notif_read` (`is_read`),
  ADD KEY `idx_notif_created` (`created_at`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payments_invoice` (`invoice_id`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_services_status` (`status`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_role` (`role`),
  ADD KEY `idx_users_status` (`status`);

--
-- Indexes for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_user_permission` (`user_id`,`permission_key`),
  ADD KEY `idx_perm_key` (`permission_key`);

--
-- Indexes for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_vehicles_plate` (`plate_number`),
  ADD KEY `idx_vehicles_customer` (`customer_id`);

--
-- Indexes for table `work_orders`
--
ALTER TABLE `work_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_number` (`order_number`),
  ADD KEY `fk_workorders_customer` (`customer_id`),
  ADD KEY `fk_workorders_vehicle` (`vehicle_id`),
  ADD KEY `fk_workorders_employee` (`assigned_employee_id`),
  ADD KEY `fk_workorders_service` (`service_id`),
  ADD KEY `idx_workorders_status` (`status`),
  ADD KEY `idx_workorders_number` (`order_number`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `account_statement_requests`
--
ALTER TABLE `account_statement_requests`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `user_permissions`
--
ALTER TABLE `user_permissions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `work_orders`
--
ALTER TABLE `work_orders`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- قيود الجداول المحفوظة
--

--
-- القيود للجدول `account_statement_requests`
--
ALTER TABLE `account_statement_requests`
  ADD CONSTRAINT `fk_statement_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- القيود للجدول `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- القيود للجدول `customers`
--
ALTER TABLE `customers`
  ADD CONSTRAINT `fk_customers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- القيود للجدول `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `fk_invoices_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_invoices_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_invoices_workorder` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`) ON UPDATE CASCADE;

--
-- القيود للجدول `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- القيود للجدول `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD CONSTRAINT `fk_perm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- القيود للجدول `vehicles`
--
ALTER TABLE `vehicles`
  ADD CONSTRAINT `fk_vehicles_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- القيود للجدول `work_orders`
--
ALTER TABLE `work_orders`
  ADD CONSTRAINT `fk_workorders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_workorders_employee` FOREIGN KEY (`assigned_employee_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_workorders_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_workorders_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON UPDATE CASCADE;

-- --------------------------------------------------------
-- بنية سلة المحذوفات
--

CREATE TABLE `recycle_bin` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type` varchar(50) NOT NULL,
  `record_id` int(10) UNSIGNED NOT NULL,
  `record_name` varchar(150) DEFAULT NULL,
  `record_role` varchar(30) DEFAULT NULL,
  `deleted_by` int(10) UNSIGNED DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT current_timestamp(),
  `restored_at` timestamp NULL DEFAULT NULL,
  `restored_by` int(10) UNSIGNED DEFAULT NULL,
  `status` enum('deleted','restored') NOT NULL DEFAULT 'deleted',
  PRIMARY KEY (`id`),
  KEY `idx_rb_status` (`status`),
  KEY `idx_rb_type` (`entity_type`),
  KEY `idx_rb_record` (`record_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
