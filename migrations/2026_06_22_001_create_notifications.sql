-- WorkToGo — In-App Notifications (bell icon feed)
-- One row per (user, event) so the admin/vendor bell can show a
-- persistent history, independent of whether the push actually
-- delivered (FCM token missing/expired/permission denied, etc).
--
-- Safe: pure CREATE TABLE, no existing schema touched.
-- Run via phpMyAdmin (manual) or /api/system/migrate.

CREATE TABLE IF NOT EXISTS `notifications` (
  `id`         bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    bigint(20) UNSIGNED NOT NULL,
  `title`      varchar(255) NOT NULL,
  `body`       text NOT NULL,
  `type`       varchar(50) NOT NULL DEFAULT 'general',
  `is_read`    tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user_read` (`user_id`, `is_read`),
  CONSTRAINT `notifications_ibfk_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification queries (run after migration):
-- SHOW CREATE TABLE notifications;
-- SELECT COUNT(*) FROM notifications; -- expect 0
