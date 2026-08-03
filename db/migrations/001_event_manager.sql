-- ============================================================
-- מיגרציה 001 - מעבר ל"מנהל אירוע משפחתי"
-- ============================================================
-- ניתן להריץ את הקובץ הזה ידנית ב-phpMyAdmin,
-- או להריץ את db/migrate.php שמבצע את אותן פעולות
-- בצורה בטוחה (מדלג על מה שכבר קיים).
--
-- שים לב: הרצה ידנית של הקובץ הזה פעמיים תיכשל על
-- העמודות שכבר נוספו. במקרה כזה עדיף db/migrate.php.
-- ============================================================

-- ------------------------------------------------------------
-- 1. הרחבת הקבוצה למושג "אירוע"
-- ------------------------------------------------------------
ALTER TABLE `purchase_groups`
    ADD COLUMN `event_date`     DATE         NULL DEFAULT NULL AFTER `description`,
    ADD COLUMN `event_location` VARCHAR(255) NULL DEFAULT NULL AFTER `event_date`,
    ADD COLUMN `status`         ENUM('planning','active','closed') NOT NULL DEFAULT 'active' AFTER `event_location`,
    ADD COLUMN `closed_at`      DATETIME     NULL DEFAULT NULL AFTER `status`;

ALTER TABLE `purchase_groups`
    ADD INDEX `idx_groups_event_date` (`event_date`);

-- ------------------------------------------------------------
-- 2. רשימת קניות - "מה צריך להביא"
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `shopping_items` (
    `id`                 INT AUTO_INCREMENT PRIMARY KEY,
    `group_id`           INT          NOT NULL,
    `title`              VARCHAR(255) NOT NULL,
    `quantity`           VARCHAR(50)  NULL DEFAULT NULL,
    `notes`              TEXT         NULL DEFAULT NULL,
    `status`             ENUM('needed','claimed','bought') NOT NULL DEFAULT 'needed',
    `assigned_member_id` INT          NULL DEFAULT NULL,
    `purchase_id`        INT          NULL DEFAULT NULL,
    `created_by`         INT          NOT NULL,
    `sort_order`         INT          NOT NULL DEFAULT 0,
    `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_items_group_status` (`group_id`, `status`),
    INDEX `idx_items_member`       (`assigned_member_id`),
    INDEX `idx_items_purchase`     (`purchase_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. החרגות ברמת הקנייה - מי *לא* משתתף בהוצאה הזו
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `purchase_exclusions` (
    `purchase_id` INT NOT NULL,
    `member_id`   INT NOT NULL,
    PRIMARY KEY (`purchase_id`, `member_id`),
    INDEX `idx_excl_member` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. סגירת חשבון - העברות שבוצעו בפועל
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settlements` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `group_id`       INT           NOT NULL,
    `from_member_id` INT           NOT NULL,
    `to_member_id`   INT           NOT NULL,
    `amount`         DECIMAL(10,2) NOT NULL,
    `note`           VARCHAR(255)  NULL DEFAULT NULL,
    `created_by`     INT           NOT NULL,
    `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_settle_group` (`group_id`),
    INDEX `idx_settle_from`  (`from_member_id`),
    INDEX `idx_settle_to`    (`to_member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. מפתחות זרים (לניקוי אוטומטי במחיקה)
-- ------------------------------------------------------------
ALTER TABLE `purchase_exclusions`
    ADD CONSTRAINT `fk_excl_purchase` FOREIGN KEY (`purchase_id`)
        REFERENCES `group_purchases` (`id`) ON DELETE CASCADE,
    ADD CONSTRAINT `fk_excl_member` FOREIGN KEY (`member_id`)
        REFERENCES `group_members` (`id`) ON DELETE CASCADE;

ALTER TABLE `shopping_items`
    ADD CONSTRAINT `fk_items_group` FOREIGN KEY (`group_id`)
        REFERENCES `purchase_groups` (`id`) ON DELETE CASCADE,
    ADD CONSTRAINT `fk_items_purchase` FOREIGN KEY (`purchase_id`)
        REFERENCES `group_purchases` (`id`) ON DELETE SET NULL;

ALTER TABLE `settlements`
    ADD CONSTRAINT `fk_settle_group` FOREIGN KEY (`group_id`)
        REFERENCES `purchase_groups` (`id`) ON DELETE CASCADE;

-- ------------------------------------------------------------
-- 6. אינדקס על טוקן ההזמנה (נדרש לזרימת join.php)
-- ------------------------------------------------------------
ALTER TABLE `group_invitations`
    ADD INDEX `idx_invitations_token` (`token`);
