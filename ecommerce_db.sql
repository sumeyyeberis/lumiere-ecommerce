-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Anamakine: 127.0.0.1
-- Üretim Zamanı: 21 May 2026, 10:47:49
-- Sunucu sürümü: 10.4.32-MariaDB
-- PHP Sürümü: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Veritabanı: `ecommerce_db`
--

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `orders`
--

CREATE TABLE `orders` (
  `order_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `status` varchar(50) DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Tablo döküm verisi `orders`
--

INSERT INTO `orders` (`order_id`, `user_id`, `total_amount`, `status`, `created_at`) VALUES
(1, 3, 4350.00, 'delivered', '2026-05-14 08:28:08');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `order_items`
--

CREATE TABLE `order_items` (
  `order_item_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `price_at_purchase` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Tablo döküm verisi `order_items`
--

INSERT INTO `order_items` (`order_item_id`, `order_id`, `product_id`, `quantity`, `price_at_purchase`) VALUES
(1, 1, 2, 1, 780.00),
(2, 1, 5, 1, 310.00),
(3, 1, 3, 1, 285.00),
(4, 1, 6, 1, 490.00),
(5, 1, 9, 1, 550.00),
(6, 1, 8, 1, 415.00),
(7, 1, 7, 1, 340.00),
(8, 1, 10, 2, 240.00),
(9, 1, 11, 1, 320.00),
(10, 1, 12, 1, 380.00);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `products`
--

CREATE TABLE `products` (
  `product_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `category` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `stock_quantity` int(11) NOT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Tablo döküm verisi `products`
--

INSERT INTO `products` (`product_id`, `name`, `category`, `description`, `price`, `stock_quantity`, `image_url`, `created_at`) VALUES
(1, 'Hyaluronic Acid Hydrating Serum', 'Skincare', 'A lightweight, fast-absorbing serum that draws moisture into the skin for a plump, dewy finish. Formulated with 2% pure hyaluronic acid and Vitamin B5.', 520.00, 0, 'images/hyaluronic_serum.png', '2026-05-19 18:53:40'),
(2, 'Vitamin C Brightening Drops', 'Skincare', 'Potent 15% L-Ascorbic Acid formula designed to fade dark spots and even out skin tone. Apply 3 drops every morning before sunscreen.', 780.00, 39, 'images/cdrops_serum.png', '2026-05-19 18:53:40'),
(3, 'Gentle Oat Barrier Cleanser', 'Skincare', 'A non-stripping, pH-balanced daily face wash perfect for sensitive or compromised skin barriers. Fragrance-free and hypoallergenic.', 285.00, 209, 'images/oat_cleanser.png', '2026-05-19 18:53:40'),
(4, 'Ceramide Repair Moisturizer', 'Skincare', 'A rich, restorative cream packed with essential ceramides and peptides to lock in hydration overnight. Ideal for dry or combination skin.', 650.00, 85, 'images/ceramide_moisturizer.png', '2026-05-19 18:53:40'),
(5, 'Rosewater & Aloe Toner', 'Skincare', 'Soothes and preps the skin after cleansing. Formulated with pure rose extract and aloe vera to calm redness and inflammation.', 310.00, 119, 'images/ceramide_toner.png', '2026-05-19 18:53:40'),
(6, 'Invisible Finish SPF 50+', 'Skincare', 'A broad-spectrum chemical sunscreen that leaves absolutely zero white cast. Doubles as a perfect makeup primer.', 490.00, 179, 'images/sunscreen.png', '2026-05-19 18:53:40'),
(7, 'Cold-Pressed Argan Oil', 'Haircare', '100% pure, unrefined Moroccan argan oil. Tames frizz, adds shine, and protects hair ends from heat damage.', 340.00, 94, 'images/argan_oil.png', '2026-05-19 18:53:40'),
(8, 'Clarifying Scalp Detox', 'Haircare', 'A weekly exfoliating treatment featuring salicylic acid to remove product buildup and balance scalp oil production.', 415.00, 59, 'images/scalp_detox.png', '2026-05-19 18:53:40'),
(9, 'Silk Protein Deep Mask', 'Haircare', 'Intensive 10-minute conditioning treatment for chemically treated or heat-damaged hair. Restores elasticity and prevents breakage.', 550.00, 74, 'images/hairmask.png', '2026-05-19 18:53:40'),
(10, 'Velvet Matte Lip Tint', 'Makeup', 'A highly pigmented, weightless lip tint that dries down to a comfortable matte finish. Smudge-proof for up to 12 hours.', 240.00, 298, 'images/liptint.png', '2026-05-19 18:53:40'),
(11, 'Dewy Finish Setting Spray', 'Makeup', 'Locks makeup in place all day while melting powders into the skin for a natural, glass-skin glow. Alcohol-free.', 320.00, 149, 'images/setting_spray.png', '2026-05-19 18:53:40'),
(12, 'Luminous Liquid Highlighter', 'Makeup', 'A blendable, pearlized liquid highlighter that melts seamlessly into the cheekbones. Shade: Champagne Gold.', 380.00, 109, 'images/highlight.png', '2026-05-19 18:53:40');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `reviews`
--

CREATE TABLE `reviews` (
  `review_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `rating` int(11) NOT NULL,
  `comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Tablo döküm verisi `reviews`
--

INSERT INTO `reviews` (`review_id`, `product_id`, `user_id`, `rating`, `comment`, `created_at`) VALUES
(4, 1, 3, 5, '10/10 i would recommend', '2026-05-14 08:42:46');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` varchar(20) DEFAULT 'customer',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Tablo döküm verisi `users`
--

INSERT INTO `users` (`user_id`, `first_name`, `last_name`, `email`, `password_hash`, `role`, `created_at`) VALUES
(1, 'Sümeyye', 'Beriş', 'admin@lumiere.com', '$2y$10$mrNyim5SyXVZonSxN7JFX.90ow5a4li5tm4Kev4xB2fOIyXCijviG', 'admin', '2026-05-19 19:21:52'),
(2, 'Test', 'Test', 'test@test.com', '$2y$10$UobBHjWUCRiEH02HZ5P6zOFCJnX8SQDwuRX9E4RooV2Zk4ISgxtC2', 'customer', '2026-05-19 19:40:13'),
(3, 'Jane', 'Doe', 'janedoe@lumiere.com', '$2y$10$XtI1nIbqy3f8WPc8Wj/wN.ovI3n3TC4k0972Nnv/eptsTCWaQX9Oa', 'customer', '2026-05-21 08:27:53'),
(4, 'John', 'Doe', 'johndoe@lumiere.com', '$2y$10$4n9u1jP2NX0T01544DB26unXUy7.t7iwixHJ0a1TGIEIShu9nwgqu', 'customer', '2026-05-21 08:37:29');

--
-- Dökümü yapılmış tablolar için indeksler
--

--
-- Tablo için indeksler `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`order_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Tablo için indeksler `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`order_item_id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Tablo için indeksler `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`product_id`);

--
-- Tablo için indeksler `reviews`
--
ALTER TABLE `reviews`
  ADD PRIMARY KEY (`review_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Tablo için indeksler `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Dökümü yapılmış tablolar için AUTO_INCREMENT değeri
--

--
-- Tablo için AUTO_INCREMENT değeri `orders`
--
ALTER TABLE `orders`
  MODIFY `order_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Tablo için AUTO_INCREMENT değeri `order_items`
--
ALTER TABLE `order_items`
  MODIFY `order_item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- Tablo için AUTO_INCREMENT değeri `products`
--
ALTER TABLE `products`
  MODIFY `product_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- Tablo için AUTO_INCREMENT değeri `reviews`
--
ALTER TABLE `reviews`
  MODIFY `review_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Tablo için AUTO_INCREMENT değeri `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Dökümü yapılmış tablolar için kısıtlamalar
--

--
-- Tablo kısıtlamaları `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Tablo kısıtlamaları `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`),
  ADD CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`);

--
-- Tablo kısıtlamaları `reviews`
--
ALTER TABLE `reviews`
  ADD CONSTRAINT `reviews_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  ADD CONSTRAINT `reviews_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
