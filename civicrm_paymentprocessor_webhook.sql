-- phpMyAdmin SQL Dump
-- version 5.1.1deb5ubuntu1
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Sep 24, 2026 at 01:38 PM
-- Server version: 8.0.46-0ubuntu0.22.04.4
-- PHP Version: 8.3.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `skvare8_uscf_civicrm`
--

-- --------------------------------------------------------

--
-- Table structure for table `civicrm_paymentprocessor_webhook`
--

CREATE TABLE `civicrm_paymentprocessor_webhook` (
  `id` int UNSIGNED NOT NULL COMMENT 'Unique PaymentprocessorWebhook ID',
  `payment_processor_id` int UNSIGNED DEFAULT NULL COMMENT 'Payment Processor for this webhook',
  `event_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Webhook event ID',
  `trigger` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Webhook trigger event type',
  `created_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the webhook was first received by the IPN code',
  `processed_date` timestamp NULL DEFAULT NULL COMMENT 'Has this webhook been processed yet?',
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new' COMMENT 'Processing status',
  `identifier` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Optional key to group webhooks, as needed by some processors.',
  `message` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT 'Stores data sent that is needed for processing. JSON suggested.',
  `data` text COLLATE utf8mb4_unicode_ci COMMENT 'Stores data sent that is needed for processing. JSON suggested.'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

--
-- Dumping data for table `civicrm_paymentprocessor_webhook`
--

INSERT INTO `civicrm_paymentprocessor_webhook` (`id`, `payment_processor_id`, `event_id`, `trigger`, `created_date`, `processed_date`, `status`, `identifier`, `message`, `data`) VALUES
(345, 7, 'b87940d8-9c50-369a-83c1-a1af532cde1d', 'payment.updated', '2026-09-24 16:47:00', NULL, 'new', 'n1xYKpEvBD0QyjdHt4l7WW7sgBgZY::', '', '{\"merchant_id\":\"ML0SVZ2P7HY7C\",\"type\":\"payment.updated\",\"event_id\":\"b87940d8-9c50-369a-83c1-a1af532cde1d\",\"created_at\":\"2026-09-24T16:46:58.22Z\",\"data\":{\"type\":\"payment\",\"id\":\"n1xYKpEvBD0QyjdHt4l7WW7sgBgZY\",\"object\":{\"payment\":{\"amount_money\":{\"amount\":1900,\"currency\":\"USD\"},\"application_details\":{\"application_id\":\"sandbox-sq0idb-iKaL0GY_L13b75J0K2qgsw\",\"square_product\":\"OTHER\"},\"approved_money\":{\"amount\":1900,\"currency\":\"USD\"},\"buyer_email_address\":\"sunil@skvare.com\",\"card_details\":{\"avs_status\":\"AVS_ACCEPTED\",\"card\":{\"bin\":\"411111\",\"card_brand\":\"VISA\",\"card_type\":\"CREDIT\",\"exp_month\":11,\"exp_year\":2029,\"fingerprint\":\"sq-1-G1aFRs4kqyICoIiVJ4z9SzaSHv8Xb1i5cgI_bwCvbiSxFWTtBvC-hDBQLERLbKwQaA\",\"last_4\":\"1111\",\"prepaid_type\":\"NOT_PREPAID\"},\"card_payment_timeline\":{\"authorized_at\":\"2026-09-24T16:45:53.894Z\",\"captured_at\":\"2026-09-24T16:45:54.049Z\"},\"cvv_status\":\"CVV_NOT_CHECKED\",\"entry_method\":\"ON_FILE\",\"statement_description\":\"SQ *DEFAULT TEST ACCOUNT\",\"status\":\"CAPTURED\"},\"created_at\":\"2026-09-24T16:45:53.762Z\",\"customer_id\":\"PAGRJWQKJH1E11PCR7KG7SKTY8\",\"delay_action\":\"CANCEL\",\"delay_duration\":\"PT168H\",\"delayed_until\":\"2026-10-01T16:45:53.762Z\",\"id\":\"n1xYKpEvBD0QyjdHt4l7WW7sgBgZY\",\"location_id\":\"L6Z22RREK6NGN\",\"order_id\":\"X4awkGmhfaQe3C7Z4g4o0esoje4F\",\"processing_fee\":[{\"amount_money\":{\"amount\":93,\"currency\":\"USD\"},\"effective_at\":\"2026-09-24T18:45:55.000Z\",\"type\":\"INITIAL\"}],\"receipt_number\":\"n1xY\",\"receipt_url\":\"https://squareupsandbox.com/receipt/preview/n1xYKpEvBD0QyjdHt4l7WW7sgBgZY\",\"risk_evaluation\":{\"created_at\":\"2026-09-24T16:45:53.894Z\",\"risk_level\":\"NORMAL\"},\"source_type\":\"CARD\",\"status\":\"COMPLETED\",\"total_money\":{\"amount\":1900,\"currency\":\"USD\"},\"updated_at\":\"2026-09-24T16:45:55.235Z\",\"version\":3}}}}'),
(344, 7, 'fcc73174-d6ec-3c84-b9dd-b6c45a386587', 'payment.updated', '2026-09-24 16:45:57', NULL, 'new', 'n1xYKpEvBD0QyjdHt4l7WW7sgBgZY::', '', '{\"merchant_id\":\"ML0SVZ2P7HY7C\",\"type\":\"payment.updated\",\"event_id\":\"fcc73174-d6ec-3c84-b9dd-b6c45a386587\",\"created_at\":\"2026-09-24T16:45:55.244Z\",\"data\":{\"type\":\"payment\",\"id\":\"n1xYKpEvBD0QyjdHt4l7WW7sgBgZY\",\"object\":{\"payment\":{\"amount_money\":{\"amount\":1900,\"currency\":\"USD\"},\"application_details\":{\"application_id\":\"sandbox-sq0idb-iKaL0GY_L13b75J0K2qgsw\",\"square_product\":\"OTHER\"},\"approved_money\":{\"amount\":1900,\"currency\":\"USD\"},\"buyer_email_address\":\"sunil@skvare.com\",\"card_details\":{\"avs_status\":\"AVS_ACCEPTED\",\"card\":{\"bin\":\"411111\",\"card_brand\":\"VISA\",\"card_type\":\"CREDIT\",\"exp_month\":11,\"exp_year\":2029,\"fingerprint\":\"sq-1-G1aFRs4kqyICoIiVJ4z9SzaSHv8Xb1i5cgI_bwCvbiSxFWTtBvC-hDBQLERLbKwQaA\",\"last_4\":\"1111\",\"prepaid_type\":\"NOT_PREPAID\"},\"card_payment_timeline\":{\"authorized_at\":\"2026-09-24T16:45:53.894Z\",\"captured_at\":\"2026-09-24T16:45:54.049Z\"},\"cvv_status\":\"CVV_NOT_CHECKED\",\"entry_method\":\"ON_FILE\",\"statement_description\":\"SQ *DEFAULT TEST ACCOUNT\",\"status\":\"CAPTURED\"},\"created_at\":\"2026-09-24T16:45:53.762Z\",\"customer_id\":\"PAGRJWQKJH1E11PCR7KG7SKTY8\",\"delay_action\":\"CANCEL\",\"delay_duration\":\"PT168H\",\"delayed_until\":\"2026-10-01T16:45:53.762Z\",\"id\":\"n1xYKpEvBD0QyjdHt4l7WW7sgBgZY\",\"location_id\":\"L6Z22RREK6NGN\",\"order_id\":\"X4awkGmhfaQe3C7Z4g4o0esoje4F\",\"receipt_number\":\"n1xY\",\"receipt_url\":\"https://squareupsandbox.com/receipt/preview/n1xYKpEvBD0QyjdHt4l7WW7sgBgZY\",\"risk_evaluation\":{\"created_at\":\"2026-09-24T16:45:53.894Z\",\"risk_level\":\"NORMAL\"},\"source_type\":\"CARD\",\"status\":\"COMPLETED\",\"total_money\":{\"amount\":1900,\"currency\":\"USD\"},\"updated_at\":\"2026-09-24T16:45:55.235Z\",\"version\":2}}}}'),
(343, 7, '2422886a-dc5f-5d47-b655-6d5332af1a2c', 'invoice.created', '2026-09-24 16:45:57', NULL, 'new', ':inv:0-ChAIcc-Uqf-MREOs4fwUEwthEIgK:4e6e5d41-a071-4e47-be68-087138ea360a', '', '{\"merchant_id\":\"ML0SVZ2P7HY7C\",\"location_id\":\"L6Z22RREK6NGN\",\"type\":\"invoice.created\",\"event_id\":\"2422886a-dc5f-5d47-b655-6d5332af1a2c\",\"created_at\":\"2026-09-24T16:45:53Z\",\"data\":{\"type\":\"invoice\",\"id\":\"inv:0-ChAIcc-Uqf-MREOs4fwUEwthEIgK\",\"object\":{\"invoice\":{\"accepted_payment_methods\":{\"bank_account\":false,\"buy_now_pay_later\":false,\"card\":true,\"cash_app_pay\":true,\"square_gift_card\":false},\"created_at\":\"2026-09-24T16:45:53Z\",\"delivery_method\":\"EMAIL\",\"id\":\"inv:0-ChAIcc-Uqf-MREOs4fwUEwthEIgK\",\"invoice_number\":\"000066\",\"location_id\":\"L6Z22RREK6NGN\",\"order_id\":\"X4awkGmhfaQe3C7Z4g4o0esoje4F\",\"payment_requests\":[{\"automatic_payment_source\":\"CARD_ON_FILE\",\"card_id\":\"ccof:CA4SEOLiJDT03Hr7z4ss8kEY4GwoAg\",\"computed_amount_money\":{\"amount\":1900,\"currency\":\"USD\"},\"due_date\":\"2026-09-24\",\"request_type\":\"BALANCE\",\"tipping_enabled\":false,\"total_completed_amount_money\":{\"amount\":0,\"currency\":\"USD\"},\"uid\":\"d8fcc799-eabf-42b0-b60f-e37a33ca9013\"}],\"primary_recipient\":{\"customer_id\":\"PAGRJWQKJH1E11PCR7KG7SKTY8\",\"email_address\":\"sunil@skvare.com\",\"family_name\":\"Pawar-1\",\"given_name\":\"Sunil-1\"},\"status\":\"DRAFT\",\"store_payment_method_enabled\":true,\"subscription_id\":\"4e6e5d41-a071-4e47-be68-087138ea360a\",\"timezone\":\"UTC\",\"title\":\"DAILY 19.00 USD Subscription\",\"updated_at\":\"2026-09-24T16:45:53Z\",\"version\":0}}}}'),
(340, 7, '34c65ab4-8a74-3652-acc3-be35708d94ce', 'payment.updated', '2026-09-23 16:47:03', NULL, 'new', 'VM5iP66yfHWI3UEIJWmbEAEzrrBZY::', '', '{\"merchant_id\":\"ML0SVZ2P7HY7C\",\"type\":\"payment.updated\",\"event_id\":\"34c65ab4-8a74-3652-acc3-be35708d94ce\",\"created_at\":\"2026-09-23T16:47:00.942Z\",\"data\":{\"type\":\"payment\",\"id\":\"VM5iP66yfHWI3UEIJWmbEAEzrrBZY\",\"object\":{\"payment\":{\"amount_money\":{\"amount\":1900,\"currency\":\"USD\"},\"application_details\":{\"application_id\":\"sandbox-sq0idb-iKaL0GY_L13b75J0K2qgsw\",\"square_product\":\"OTHER\"},\"approved_money\":{\"amount\":1900,\"currency\":\"USD\"},\"buyer_email_address\":\"sunil@skvare.com\",\"card_details\":{\"avs_status\":\"AVS_ACCEPTED\",\"card\":{\"bin\":\"411111\",\"card_brand\":\"VISA\",\"card_type\":\"CREDIT\",\"exp_month\":11,\"exp_year\":2029,\"fingerprint\":\"sq-1-G1aFRs4kqyICoIiVJ4z9SzaSHv8Xb1i5cgI_bwCvbiSxFWTtBvC-hDBQLERLbKwQaA\",\"last_4\":\"1111\",\"prepaid_type\":\"NOT_PREPAID\"},\"card_payment_timeline\":{\"authorized_at\":\"2026-09-23T16:45:56.915Z\",\"captured_at\":\"2026-09-23T16:45:57.027Z\"},\"cvv_status\":\"CVV_NOT_CHECKED\",\"entry_method\":\"ON_FILE\",\"statement_description\":\"SQ *DEFAULT TEST ACCOUNT\",\"status\":\"CAPTURED\"},\"created_at\":\"2026-09-23T16:45:56.784Z\",\"customer_id\":\"PAGRJWQKJH1E11PCR7KG7SKTY8\",\"delay_action\":\"CANCEL\",\"delay_duration\":\"PT168H\",\"delayed_until\":\"2026-09-30T16:45:56.784Z\",\"id\":\"VM5iP66yfHWI3UEIJWmbEAEzrrBZY\",\"location_id\":\"L6Z22RREK6NGN\",\"order_id\":\"thVIfFwfL8yeD6fM94dvotSh2c4F\",\"processing_fee\":[{\"amount_money\":{\"amount\":93,\"currency\":\"USD\"},\"effective_at\":\"2026-09-23T18:45:58.000Z\",\"type\":\"INITIAL\"}],\"receipt_number\":\"VM5i\",\"receipt_url\":\"https://squareupsandbox.com/receipt/preview/VM5iP66yfHWI3UEIJWmbEAEzrrBZY\",\"risk_evaluation\":{\"created_at\":\"2026-09-23T16:45:56.915Z\",\"risk_level\":\"NORMAL\"},\"source_type\":\"CARD\",\"status\":\"COMPLETED\",\"total_money\":{\"amount\":1900,\"currency\":\"USD\"},\"updated_at\":\"2026-09-23T16:45:58.357Z\",\"version\":3}}}}'),
(339, 7, 'e1fc9a1e-ae5f-3f8a-a079-5eb8c48c096c', 'payment.updated', '2026-09-23 16:46:00', NULL, 'new', 'VM5iP66yfHWI3UEIJWmbEAEzrrBZY::', '', '{\"merchant_id\":\"ML0SVZ2P7HY7C\",\"type\":\"payment.updated\",\"event_id\":\"e1fc9a1e-ae5f-3f8a-a079-5eb8c48c096c\",\"created_at\":\"2026-09-23T16:45:58.364Z\",\"data\":{\"type\":\"payment\",\"id\":\"VM5iP66yfHWI3UEIJWmbEAEzrrBZY\",\"object\":{\"payment\":{\"amount_money\":{\"amount\":1900,\"currency\":\"USD\"},\"application_details\":{\"application_id\":\"sandbox-sq0idb-iKaL0GY_L13b75J0K2qgsw\",\"square_product\":\"OTHER\"},\"approved_money\":{\"amount\":1900,\"currency\":\"USD\"},\"buyer_email_address\":\"sunil@skvare.com\",\"card_details\":{\"avs_status\":\"AVS_ACCEPTED\",\"card\":{\"bin\":\"411111\",\"card_brand\":\"VISA\",\"card_type\":\"CREDIT\",\"exp_month\":11,\"exp_year\":2029,\"fingerprint\":\"sq-1-G1aFRs4kqyICoIiVJ4z9SzaSHv8Xb1i5cgI_bwCvbiSxFWTtBvC-hDBQLERLbKwQaA\",\"last_4\":\"1111\",\"prepaid_type\":\"NOT_PREPAID\"},\"card_payment_timeline\":{\"authorized_at\":\"2026-09-23T16:45:56.915Z\",\"captured_at\":\"2026-09-23T16:45:57.027Z\"},\"cvv_status\":\"CVV_NOT_CHECKED\",\"entry_method\":\"ON_FILE\",\"statement_description\":\"SQ *DEFAULT TEST ACCOUNT\",\"status\":\"CAPTURED\"},\"created_at\":\"2026-09-23T16:45:56.784Z\",\"customer_id\":\"PAGRJWQKJH1E11PCR7KG7SKTY8\",\"delay_action\":\"CANCEL\",\"delay_duration\":\"PT168H\",\"delayed_until\":\"2026-09-30T16:45:56.784Z\",\"id\":\"VM5iP66yfHWI3UEIJWmbEAEzrrBZY\",\"location_id\":\"L6Z22RREK6NGN\",\"order_id\":\"thVIfFwfL8yeD6fM94dvotSh2c4F\",\"receipt_number\":\"VM5i\",\"receipt_url\":\"https://squareupsandbox.com/receipt/preview/VM5iP66yfHWI3UEIJWmbEAEzrrBZY\",\"risk_evaluation\":{\"created_at\":\"2026-09-23T16:45:56.915Z\",\"risk_level\":\"NORMAL\"},\"source_type\":\"CARD\",\"status\":\"COMPLETED\",\"total_money\":{\"amount\":1900,\"currency\":\"USD\"},\"updated_at\":\"2026-09-23T16:45:58.357Z\",\"version\":2}}}}'),
(338, 7, '0314d55c-fc55-5767-b7f3-3c6d4ba74ebb', 'invoice.created', '2026-09-23 16:45:58', NULL, 'new', ':inv:0-ChAsOc2tUbZgeioE6TokE6ODEIgK:4e6e5d41-a071-4e47-be68-087138ea360a', '', '{\"merchant_id\":\"ML0SVZ2P7HY7C\",\"location_id\":\"L6Z22RREK6NGN\",\"type\":\"invoice.created\",\"event_id\":\"0314d55c-fc55-5767-b7f3-3c6d4ba74ebb\",\"created_at\":\"2026-09-23T16:45:56Z\",\"data\":{\"type\":\"invoice\",\"id\":\"inv:0-ChAsOc2tUbZgeioE6TokE6ODEIgK\",\"object\":{\"invoice\":{\"accepted_payment_methods\":{\"bank_account\":false,\"buy_now_pay_later\":false,\"card\":true,\"cash_app_pay\":true,\"square_gift_card\":false},\"created_at\":\"2026-09-23T16:45:56Z\",\"delivery_method\":\"EMAIL\",\"id\":\"inv:0-ChAsOc2tUbZgeioE6TokE6ODEIgK\",\"invoice_number\":\"000065\",\"location_id\":\"L6Z22RREK6NGN\",\"order_id\":\"thVIfFwfL8yeD6fM94dvotSh2c4F\",\"payment_requests\":[{\"automatic_payment_source\":\"CARD_ON_FILE\",\"card_id\":\"ccof:CA4SEOLiJDT03Hr7z4ss8kEY4GwoAg\",\"computed_amount_money\":{\"amount\":1900,\"currency\":\"USD\"},\"due_date\":\"2026-09-23\",\"request_type\":\"BALANCE\",\"tipping_enabled\":false,\"total_completed_amount_money\":{\"amount\":0,\"currency\":\"USD\"},\"uid\":\"2fe49e37-008f-45d1-a8ef-171f91331aeb\"}],\"primary_recipient\":{\"customer_id\":\"PAGRJWQKJH1E11PCR7KG7SKTY8\",\"email_address\":\"sunil@skvare.com\",\"family_name\":\"Pawar-1\",\"given_name\":\"Sunil-1\"},\"status\":\"DRAFT\",\"store_payment_method_enabled\":true,\"subscription_id\":\"4e6e5d41-a071-4e47-be68-087138ea360a\",\"timezone\":\"UTC\",\"title\":\"DAILY 19.00 USD Subscription\",\"updated_at\":\"2026-09-23T16:45:56Z\",\"version\":0}}}}');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `civicrm_paymentprocessor_webhook`
--
ALTER TABLE `civicrm_paymentprocessor_webhook`
  ADD PRIMARY KEY (`id`),
  ADD KEY `index_event_id` (`event_id`),
  ADD KEY `index_created_date` (`created_date`),
  ADD KEY `index_processed_date` (`processed_date`),
  ADD KEY `index_status_processed_date` (`status`,`processed_date`),
  ADD KEY `index_identifier` (`identifier`),
  ADD KEY `FK_civicrm_paymentprocessor_webhook_111edf57e5277dbe` (`payment_processor_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `civicrm_paymentprocessor_webhook`
--
ALTER TABLE `civicrm_paymentprocessor_webhook`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique PaymentprocessorWebhook ID', AUTO_INCREMENT=346;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `civicrm_paymentprocessor_webhook`
--
ALTER TABLE `civicrm_paymentprocessor_webhook`
  ADD CONSTRAINT `FK_civicrm_paymentprocessor_webhook_111edf57e5277dbe` FOREIGN KEY (`payment_processor_id`) REFERENCES `civicrm_payment_processor` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
