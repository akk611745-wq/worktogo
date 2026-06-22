-- WorkToGo — FCM Browser Push Tokens
-- Stores one row per (user_id, token) so a user can be signed in on
-- multiple devices/browsers and receive the same push on all of them.
--
-- Safe: pure CREATE TABLE, no existing schema touched.
-- Run via phpMyAdmin (manual) or migrate.php / /api/system/migrate.

CREATE TABLE IF NOT EXISTS `fcm_tokens` (
  `id`          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     bigint(20) UNSIGNED NOT NULL,
  `token`       varchar(512) NOT NULL,
  `device_type` enum('web','android','ios') NOT NULL DEFAULT 'web',
  `created_at`  datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`  datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fcm_token` (`token`),
  KEY `idx_fcm_user` (`user_id`),
  CONSTRAINT `fcm_tokens_ibfk_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification queries (run after migration):
-- SHOW CREATE TABLE fcm_tokens;
-- SELECT COUNT(*) FROM fcm_tokens; -- expect 0
