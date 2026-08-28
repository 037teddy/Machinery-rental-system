-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 19, 2026 at 04:49 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.1.25

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `machinery_rental_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `bookings`
--

CREATE TABLE `bookings` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `id_document_type` enum('identification_card','passport') NOT NULL,
  `id_document_number` varchar(100) NOT NULL,
  `driver_included` tinyint(1) NOT NULL DEFAULT 1,
  `machinery_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `total_price` decimal(10,2) NOT NULL,
  `status` enum('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending',
  `handed_over_at` datetime DEFAULT NULL,
  `returned_at` datetime DEFAULT NULL,
  `condition_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bookings`
--

INSERT INTO `bookings` (`id`, `user_id`, `id_document_type`, `id_document_number`, `driver_included`, `machinery_id`, `start_date`, `end_date`, `total_price`, `status`, `handed_over_at`, `returned_at`, `condition_notes`, `created_at`) VALUES
(1, 1, 'identification_card', '', 1, 3, '2026-08-20', '2026-08-20', 8500.00, '', '2026-08-19 15:33:26', NULL, NULL, '2026-08-19 09:39:04'),
(2, 1, 'identification_card', '', 1, 13, '2026-08-19', '2026-08-20', 4.00, 'completed', NULL, NULL, NULL, '2026-08-19 10:19:05'),
(3, 6, 'identification_card', '41395742', 1, 13, '2026-08-22', '2026-08-22', 2.00, 'confirmed', '2026-08-19 15:33:18', NULL, NULL, '2026-08-19 12:06:38'),
(4, 1, 'identification_card', '41395742', 1, 13, '2026-08-25', '2026-08-25', 2.00, 'pending', NULL, NULL, NULL, '2026-08-19 13:08:34'),
(5, 1, 'identification_card', '41395742', 1, 13, '2026-08-19', '2026-08-19', 2.00, 'completed', '2026-08-19 17:39:00', NULL, NULL, '2026-08-19 14:03:53');

-- --------------------------------------------------------

--
-- Table structure for table `machinery`
--

CREATE TABLE `machinery` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `type` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `price_per_day` decimal(10,2) NOT NULL,
  `status` enum('available','rented','maintenance') NOT NULL DEFAULT 'available',
  `image` varchar(255) DEFAULT 'no-image.jpg',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `machinery`
--

INSERT INTO `machinery` (`id`, `name`, `type`, `description`, `price_per_day`, `status`, `image`, `created_at`) VALUES
(1, 'John Deere Tractor', 'Tractor', 'Reliable farm tractor suitable for ploughing and towing.', 4500.00, 'available', 'tractor.jpg', '2026-08-19 09:11:20'),
(2, 'CAT Excavator', 'Excavator', 'Heavy-duty excavator for digging and earthmoving.', 9000.00, 'available', 'excavator.jpg', '2026-08-19 09:11:20'),
(3, 'Komatsu Bulldozer', 'Bulldozer', 'Powerful bulldozer for grading and pushing large amounts of material.', 8500.00, 'available', 'bulldozer.jpg', '2026-08-19 09:11:20'),
(4, 'Toyota Forklift', 'Forklift', 'Warehouse and yard forklift, 2-ton lifting capacity.', 3000.00, 'available', 'forklift.jpg', '2026-08-19 09:11:20'),
(5, 'Diesel Generator 50kVA', 'Generator', 'Backup power generator suitable for construction sites.', 2500.00, 'available', 'generator.jpg', '2026-08-19 09:11:20'),
(6, 'Concrete Mixer 500L', 'Concrete Mixer', 'Portable concrete mixer for small to medium construction jobs.', 1800.00, 'available', 'concrete-mixer.jpg', '2026-08-19 09:11:20'),
(7, 'John Deere Tractor', 'Tractor', 'Reliable farm tractor suitable for ploughing and towing.', 4500.00, 'available', 'tractor.jpg', '2026-08-19 09:33:05'),
(8, 'CAT Excavator', 'Excavator', 'Heavy-duty excavator for digging and earthmoving.', 9000.00, 'available', 'excavator.jpg', '2026-08-19 09:33:05'),
(9, 'Komatsu Bulldozer', 'Bulldozer', 'Powerful bulldozer for grading and pushing large amounts of material.', 8500.00, 'available', 'bulldozer.jpg', '2026-08-19 09:33:05'),
(10, 'Toyota Forklift', 'Forklift', 'Warehouse and yard forklift, 2-ton lifting capacity.', 3000.00, 'available', 'forklift.jpg', '2026-08-19 09:33:05'),
(11, 'Diesel Generator 50kVA', 'Generator', 'Backup power generator suitable for construction sites.', 2500.00, 'available', 'generator.jpg', '2026-08-19 09:33:05'),
(12, 'Concrete Mixer 500L', 'Concrete Mixer', 'Portable concrete mixer for small to medium construction jobs.', 1800.00, 'available', 'concrete-mixer.jpg', '2026-08-19 09:33:05'),
(13, 'Bull', 'Bulldozer', 'Nice for work', 2.00, 'available', 'a417324a51a7622eb2f62d4b2d7ee5dc.jpg', '2026-08-19 10:17:28'),
(14, 'Bulldozer-CAT', 'Excavator', 'Yellow', 5.00, 'available', '20f5a6977836f9f2749fd9c19984641a.jpg', '2026-08-19 14:21:52');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `method` varchar(50) NOT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `checkout_request_id` varchar(150) DEFAULT NULL,
  `external_reference` varchar(150) DEFAULT NULL,
  `transaction_reference` varchar(150) DEFAULT NULL,
  `failure_reason` varchar(255) DEFAULT NULL,
  `status` enum('paid','refunded') NOT NULL DEFAULT 'paid',
  `paid_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `booking_id`, `amount`, `method`, `phone_number`, `checkout_request_id`, `external_reference`, `transaction_reference`, `failure_reason`, `status`, `paid_at`) VALUES
(1, 1, 8500.00, 'mpesa', NULL, NULL, NULL, NULL, NULL, 'paid', '2026-08-19 09:39:29'),
(2, 2, 4.00, 'mpesa', NULL, NULL, NULL, NULL, NULL, 'paid', '2026-08-19 10:19:15'),
(3, 3, 2.00, 'mpesa', '254742396020', 'ws_CO_19082026152633863742396020', 'MACH-00000003-1787142391', NULL, NULL, '', '2026-08-19 12:26:31'),
(4, 3, 2.00, 'mpesa', '254742396020', 'ws_CO_19082026152903534742396020', 'MACH-00000003-1787142541', NULL, NULL, 'paid', '2026-08-19 12:29:01'),
(5, 4, 2.00, 'mpesa', '254795433480', 'ws_CO_19082026160900867795433480', 'MACH-00000004-1787144938', NULL, NULL, '', '2026-08-19 13:08:58'),
(6, 4, 2.00, 'mpesa', '254795433480', 'ws_CO_19082026160944647795433480', 'MACH-00000004-1787144983', NULL, NULL, '', '2026-08-19 13:09:43'),
(7, 5, 2.00, 'mpesa', '254742396020', 'ws_CO_19082026170415814742396020', 'MACH-00000005-1787148254', NULL, NULL, '', '2026-08-19 14:04:14'),
(8, 5, 2.00, 'mpesa', '254798555338', 'ws_CO_19082026170456521798555338', 'MACH-00000005-1787148294', NULL, NULL, 'paid', '2026-08-19 14:04:54');

-- --------------------------------------------------------

--
-- Table structure for table `reviews`
--

CREATE TABLE `reviews` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `machinery_id` int(11) NOT NULL,
  `rating` tinyint(3) UNSIGNED NOT NULL,
  `comment` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

--
-- Dumping data for table `reviews`
--

INSERT INTO `reviews` (`id`, `user_id`, `machinery_id`, `rating`, `comment`, `created_at`) VALUES
(1, 1, 13, 5, 'The best I have ever seen', '2026-08-19 10:59:31');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('customer','admin','operator') NOT NULL DEFAULT 'customer',
  `approval_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  `email_verified` tinyint(1) NOT NULL DEFAULT 1,
  `otp_hash` varchar(255) DEFAULT NULL,
  `otp_expires` datetime DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `email`, `password_hash`, `role`, `approval_status`, `email_verified`, `otp_hash`, `otp_expires`, `phone`, `created_at`) VALUES
(1, 'TEDDY MBAYAKI', 'teddymbayaki@gmail.com', '$2y$10$q2f3/wuzGhdBtTb8VkjbnO9egiPDXYRftKEvybbuRkcC3ty6LK42e', 'customer', 'approved', 1, NULL, NULL, '0742396020', '2026-08-19 09:13:25'),
(5, 'Ijaka', 'ijakateddy@gmail.com', '$2y$10$Z9Hr24.RoFGQgL.UEO.AI.DY0M4BQwx2sWoGfgQ543TrylHL80sXi', 'operator', 'approved', 1, NULL, NULL, '0712345678', '2026-08-19 10:04:38'),
(6, 'priteddy', 'priteddy45@gmail.com', '$2y$10$AD8U8Sm1rx9bwju3NqFhvu03Eff2woArl6Ifi.nQACA8254CVtB5a', 'customer', 'approved', 1, NULL, NULL, '0742396020', '2026-08-19 10:21:15'),
(7, 'Administrator', 'adminmachinery@gmail.com', '$2y$10$s8hrWERWkZtVXNt3ECJJveLAf70cYN8vccPMYkJpTCDi3y18ArQiC', 'admin', 'approved', 1, NULL, NULL, NULL, '2026-08-19 10:27:37'),
(8, 'sihanikha', 'sihanikha64@gmail.com', '$2y$10$oHYiIKVh2KpS9mLc/wNgwOipHYNb3vjn7RcvzdcAUfksdBaR0K.Gq', 'operator', 'approved', 1, NULL, NULL, '0712345543', '2026-08-19 13:13:42'),
(9, 'Gideon', 'samkemei95@gmail.com', '$2y$10$xwPzhxWfvGSpFJux4hOHq.nYWQ92JQ9LaH6hs7p7axrzOt.aX1vkC', 'operator', 'approved', 1, NULL, NULL, '0745889971', '2026-08-19 14:17:46');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `bookings`
--
ALTER TABLE `bookings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `machinery_id` (`machinery_id`);

--
-- Indexes for table `machinery`
--
ALTER TABLE `machinery`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `payments_checkout_request_unique` (`checkout_request_id`),
  ADD UNIQUE KEY `payments_external_reference_unique` (`external_reference`),
  ADD KEY `booking_id` (`booking_id`);

--
-- Indexes for table `reviews`
--
ALTER TABLE `reviews`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `one_review_per_rental` (`user_id`,`machinery_id`),
  ADD KEY `reviews_machinery_fk` (`machinery_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `machinery`
--
ALTER TABLE `machinery`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `reviews`
--
ALTER TABLE `reviews`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bookings`
--
ALTER TABLE `bookings`
  ADD CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`machinery_id`) REFERENCES `machinery` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `reviews`
--
ALTER TABLE `reviews`
  ADD CONSTRAINT `reviews_machinery_fk` FOREIGN KEY (`machinery_id`) REFERENCES `machinery` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reviews_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
