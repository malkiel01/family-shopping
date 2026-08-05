-- ============================================================
-- מיגרציה 002 - הגבלת קצב על ניסיונות התחברות
-- ============================================================
-- מומלץ להריץ דרך db/migrate.php, שמדלג על מה שכבר קיים.
-- ============================================================

CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `identifier`   VARCHAR(190) NOT NULL COMMENT 'שם המשתמש או האימייל שהוזן',
    `ip`           VARCHAR(45)  NOT NULL,
    `succeeded`    TINYINT(1)   NOT NULL DEFAULT 0,
    `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_attempts_ip`      (`ip`, `attempted_at`),
    INDEX `idx_attempts_account` (`identifier`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
