<?php
/**
 * קטלוג המיגרציות
 * db/migrations.php
 *
 * המיגרציות מוגדרות כאן כנתונים, ולא כסקריפט שרץ מלמעלה למטה.
 * הסיבה היא שיש להן שני צרכנים: db/migrate.php שמריץ אותן
 * מהטרמינל, ומסך "תחזוקה ופיתוח" בממשק הניהול שגם מציג את
 * מצבן וגם מריץ אותן. כשההגדרה הייתה סקריפט, הצגת המצב הייתה
 * מחייבת לשכפל את כל בדיקות הקיום - ושני העותקים היו נפרדים.
 *
 * כל צעד מגדיר שלושה דברים:
 *   key      מזהה יציב לצעד, לשימוש בממשק
 *   pending  האם הצעד עוד לא בוצע
 *   run      ביצוע הצעד. מחזיר טקסט הערה, או null
 *
 * צעד מסומן critical=false כשכישלון בו אינו מונע מהמערכת לעבוד
 * (בפועל: מפתחות זרים, שנכשלים על מנוע שאינו InnoDB).
 */

// ============================================================
// בדיקות קיום
// ============================================================

/** האם טבלה קיימת */
function tableExists(PDO $pdo, $db, $table) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
    ");
    $stmt->execute([$db, $table]);
    return (int)$stmt->fetchColumn() > 0;
}

/** האם עמודה קיימת */
function columnExists(PDO $pdo, $db, $table, $column) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$db, $table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

/** האם אינדקס קיים */
function indexExists(PDO $pdo, $db, $table, $index) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?
    ");
    $stmt->execute([$db, $table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

/** האם מפתח זר קיים */
function constraintExists(PDO $pdo, $db, $table, $name) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
    ");
    $stmt->execute([$db, $table, $name]);
    return (int)$stmt->fetchColumn() > 0;
}

/** ההגדרה המלאה של עמודה, לזיהוי enum שצריך הרחבה */
function columnType(PDO $pdo, $db, $table, $column) {
    $stmt = $pdo->prepare("
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$db, $table, $column]);

    return (string)$stmt->fetchColumn();
}

// ============================================================
// בוני צעדים - הדפוסים החוזרים
// ============================================================

/** צעד שמוסיף עמודה לטבלה קיימת */
function stepAddColumn(PDO $pdo, $db, $table, $column, $definition) {
    return [
        'key'     => "$table.$column",
        'label'   => "$table.$column",
        'pending' => function () use ($pdo, $db, $table, $column) {
            return tableExists($pdo, $db, $table)
                && !columnExists($pdo, $db, $table, $column);
        },
        'run' => function () use ($pdo, $table, $definition) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN $definition");
            return null;
        },
    ];
}

/** צעד שיוצר טבלה */
function stepCreateTable(PDO $pdo, $db, $table, $sql) {
    return [
        'key'     => "table.$table",
        'label'   => "טבלה $table",
        'pending' => function () use ($pdo, $db, $table) {
            return !tableExists($pdo, $db, $table);
        },
        'run' => function () use ($pdo, $sql) {
            $pdo->exec($sql);
            return null;
        },
    ];
}

/** צעד שמוסיף אינדקס */
function stepAddIndex(PDO $pdo, $db, $table, $index, $columns) {
    return [
        'key'     => "index.$table.$index",
        'label'   => "אינדקס $table.$index",
        'pending' => function () use ($pdo, $db, $table, $index) {
            return tableExists($pdo, $db, $table)
                && !indexExists($pdo, $db, $table, $index);
        },
        'run' => function () use ($pdo, $table, $index, $columns) {
            $pdo->exec("ALTER TABLE `$table` ADD INDEX `$index` ($columns)");
            return null;
        },
    ];
}

/** צעד שמוסיף מפתח זר. כישלון בו אינו קריטי */
function stepForeignKey(PDO $pdo, $db, $table, $name, $sql) {
    return [
        'key'      => "fk.$name",
        'label'    => "מפתח זר $name",
        'critical' => false,
        'pending'  => function () use ($pdo, $db, $table, $name) {
            return tableExists($pdo, $db, $table)
                && !constraintExists($pdo, $db, $table, $name);
        },
        'run' => function () use ($pdo, $table, $sql) {
            $pdo->exec("ALTER TABLE `$table` $sql");
            return null;
        },
    ];
}

/** צעד שמרחיב enum בערך שעוד אינו קיים בו */
function stepWidenEnum(PDO $pdo, $db, $table, $column, $probe, $definition, $label) {
    return [
        'key'     => "enum.$table.$column",
        'label'   => $label,
        'pending' => function () use ($pdo, $db, $table, $column, $probe) {
            $type = columnType($pdo, $db, $table, $column);

            return $type !== '' && strpos($type, "'$probe'") === false;
        },
        'run' => function () use ($pdo, $table, $column, $definition) {
            $pdo->exec("ALTER TABLE `$table` MODIFY COLUMN `$column` $definition");
            return null;
        },
    ];
}

// ============================================================
// הקטלוג
// ============================================================

/**
 * כל המיגרציות, לפי סדר הרצה.
 *
 * @return array רשימת מיגרציות: id, title, steps
 */
function migrationCatalog(PDO $pdo, $dbName) {
    $catalog = [];

    // --------------------------------------------------------
    $steps = [];

    foreach ([
        'event_date'     => "`event_date` DATE NULL DEFAULT NULL",
        'event_location' => "`event_location` VARCHAR(255) NULL DEFAULT NULL",
        'status'         => "`status` ENUM('planning','active','closed') NOT NULL DEFAULT 'active'",
        'closed_at'      => "`closed_at` DATETIME NULL DEFAULT NULL",
    ] as $column => $definition) {
        $steps[] = stepAddColumn($pdo, $dbName, 'purchase_groups', $column, $definition);
    }

    $steps[] = stepAddIndex($pdo, $dbName, 'purchase_groups', 'idx_groups_event_date', '`event_date`');

    $steps[] = stepCreateTable($pdo, $dbName, 'shopping_items', "
        CREATE TABLE `shopping_items` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $steps[] = stepCreateTable($pdo, $dbName, 'purchase_exclusions', "
        CREATE TABLE `purchase_exclusions` (
            `purchase_id` INT NOT NULL,
            `member_id`   INT NOT NULL,
            PRIMARY KEY (`purchase_id`, `member_id`),
            INDEX `idx_excl_member` (`member_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $steps[] = stepCreateTable($pdo, $dbName, 'settlements', "
        CREATE TABLE `settlements` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach ([
        ['purchase_exclusions', 'fk_excl_purchase',
            "ADD CONSTRAINT `fk_excl_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `group_purchases` (`id`) ON DELETE CASCADE"],
        ['purchase_exclusions', 'fk_excl_member',
            "ADD CONSTRAINT `fk_excl_member` FOREIGN KEY (`member_id`) REFERENCES `group_members` (`id`) ON DELETE CASCADE"],
        ['shopping_items', 'fk_items_group',
            "ADD CONSTRAINT `fk_items_group` FOREIGN KEY (`group_id`) REFERENCES `purchase_groups` (`id`) ON DELETE CASCADE"],
        ['shopping_items', 'fk_items_purchase',
            "ADD CONSTRAINT `fk_items_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `group_purchases` (`id`) ON DELETE SET NULL"],
        ['settlements', 'fk_settle_group',
            "ADD CONSTRAINT `fk_settle_group` FOREIGN KEY (`group_id`) REFERENCES `purchase_groups` (`id`) ON DELETE CASCADE"],
    ] as [$table, $name, $sql]) {
        $steps[] = stepForeignKey($pdo, $dbName, $table, $name, $sql);
    }

    $steps[] = stepAddIndex($pdo, $dbName, 'group_invitations', 'idx_invitations_token', '`token`');

    $catalog[] = ['id' => '001', 'title' => 'מנהל אירוע משפחתי', 'steps' => $steps];

    // --------------------------------------------------------
    $catalog[] = [
        'id'    => '002',
        'title' => 'הגבלת קצב על התחברות',
        'steps' => [
            stepCreateTable($pdo, $dbName, 'login_attempts', "
                CREATE TABLE `login_attempts` (
                    `id`           INT AUTO_INCREMENT PRIMARY KEY,
                    `identifier`   VARCHAR(190) NOT NULL COMMENT 'שם המשתמש או האימייל שהוזן',
                    `ip`           VARCHAR(45)  NOT NULL,
                    `succeeded`    TINYINT(1)   NOT NULL DEFAULT 0,
                    `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_attempts_ip`      (`ip`, `attempted_at`),
                    INDEX `idx_attempts_account` (`identifier`, `attempted_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"),
        ],
    ];

    // --------------------------------------------------------
    // EmailService כותב לטבלה הזו בכל שליחה. היא לא הייתה קיימת,
    // ולכן כל רישום נכשל בשקט ולא היה תיעוד של מיילים שיצאו.
    $catalog[] = [
        'id'    => '003',
        'title' => 'יומן המיילים',
        'steps' => [
            stepCreateTable($pdo, $dbName, 'email_log', "
                CREATE TABLE `email_log` (
                    `id`        INT AUTO_INCREMENT PRIMARY KEY,
                    `to_email`  VARCHAR(255) NOT NULL,
                    `subject`   VARCHAR(255) NOT NULL,
                    `status`    ENUM('sent','failed') NOT NULL DEFAULT 'sent',
                    `sent_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_email_log_sent` (`sent_at`),
                    INDEX `idx_email_log_to`   (`to_email`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"),
        ],
    ];

    // --------------------------------------------------------
    // הערכים הקיימים נשמרים כפי שהם; רק נוסף ערך אפשרי חדש
    $participationEnum = "ENUM('percentage','fixed','auto_percentage','shares') NOT NULL DEFAULT 'percentage'";

    $catalog[] = [
        'id'    => '004',
        'title' => 'חלוקה לפי נפשות',
        'steps' => [
            stepWidenEnum($pdo, $dbName, 'group_members', 'participation_type', 'shares',
                $participationEnum, "סוג השתתפות 'shares'"),
            stepWidenEnum($pdo, $dbName, 'group_invitations', 'participation_type', 'shares',
                $participationEnum, "סוג השתתפות 'shares' בהזמנות"),
        ],
    ];

    // --------------------------------------------------------
    // כשחלק מהמשתתפים באחוזים וחלקם בנפשות, אין דרך לתרגם
    // "3 נפשות" למספר שאפשר להשוות ל-"20%" - אלא אם נקבע
    // תעריף קבוע לנפש. NULL פירושו חלוקה יחסית רגילה.
    $catalog[] = [
        'id'    => '005',
        'title' => 'תעריף לנפש',
        'steps' => [
            stepAddColumn($pdo, $dbName, 'purchase_groups', 'share_rate',
                "`share_rate` DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'תעריף לנפש; NULL = חלוקה יחסית'"),
        ],
    ];

    // --------------------------------------------------------
    // רשימת אנשי הקשר שייכת למשתמש ולא לאירוע, ולכן היא
    // נצברת מכל הקבוצות שלו ומשמשת בכל אירוע חדש
    $catalog[] = [
        'id'    => '006',
        'title' => 'אנשי קשר',
        'steps' => [
            stepCreateTable($pdo, $dbName, 'contacts', "
                CREATE TABLE `contacts` (
                    `id`                          INT AUTO_INCREMENT PRIMARY KEY,
                    `owner_id`                    INT          NOT NULL,
                    `email`                       VARCHAR(190) NOT NULL,
                    `name`                        VARCHAR(190) NOT NULL,
                    `default_participation_type`  ENUM('percentage','fixed','shares') NULL DEFAULT NULL,
                    `default_participation_value` DECIMAL(10,2) NULL DEFAULT NULL,
                    `times_used`                  INT          NOT NULL DEFAULT 0,
                    `last_used_at`                DATETIME     NULL DEFAULT NULL,
                    `created_at`                  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `uniq_owner_email` (`owner_id`, `email`),
                    INDEX `idx_contacts_owner` (`owner_id`, `times_used`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"),

            // ייבוא חד-פעמי מההיסטוריה, כדי שהרשימה לא תתחיל ריקה
            [
                'key'     => 'contacts.import',
                'label'   => 'ייבוא אנשי קשר מההזמנות הקיימות',
                'pending' => function () use ($pdo, $dbName) {
                    if (!tableExists($pdo, $dbName, 'contacts')) {
                        return false;
                    }

                    return (int)$pdo->query("SELECT COUNT(*) FROM contacts")->fetchColumn() === 0;
                },
                'run' => function () use ($pdo) {
                    require_once __DIR__ . '/../includes/contacts.php';

                    $owners = $pdo->query("
                        SELECT DISTINCT owner_id FROM purchase_groups WHERE owner_id IS NOT NULL
                    ")->fetchAll(PDO::FETCH_COLUMN);

                    $total = 0;
                    foreach ($owners as $ownerId) {
                        $total += importContactsFromHistory($pdo, $ownerId);
                    }

                    return "יובאו $total אנשי קשר";
                },
            ],
        ],
    ];

    // --------------------------------------------------------
    // פעולה שמנהל מבצע בשם משתמש אחר חייבת להשאיר עקבות.
    // בלי יומן, אי אפשר לענות על "מי אישר את זה ומתי".
    $catalog[] = [
        'id'    => '007',
        'title' => 'פלטפורמת ניהול',
        'steps' => [
            stepAddColumn($pdo, $dbName, 'users', 'is_admin',
                "`is_admin` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'הרשאת ניהול לכלל המערכת'"),

            stepCreateTable($pdo, $dbName, 'admin_actions', "
                CREATE TABLE `admin_actions` (
                    `id`          INT AUTO_INCREMENT PRIMARY KEY,
                    `admin_id`    INT          NOT NULL,
                    `action`      VARCHAR(50)  NOT NULL,
                    `target_type` VARCHAR(50)  NULL,
                    `target_id`   INT          NULL,
                    `details`     TEXT         NULL,
                    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_admin_actions_admin` (`admin_id`, `created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"),

            [
                'key'     => 'admin.grant',
                'label'   => 'הענקת הרשאת ניהול לפי ADMIN_EMAIL',
                'pending' => function () use ($pdo, $dbName) {
                    if (!columnExists($pdo, $dbName, 'users', 'is_admin')) {
                        return false;
                    }

                    // רק אם אין עדיין אף מנהל, וההגדרה קיימת
                    $email = trim($_ENV['ADMIN_EMAIL'] ?? '');

                    return $email !== ''
                        && (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 1")->fetchColumn() === 0;
                },
                'run' => function () use ($pdo) {
                    $email = trim($_ENV['ADMIN_EMAIL'] ?? '');
                    $stmt  = $pdo->prepare("UPDATE users SET is_admin = 1 WHERE email = ?");
                    $stmt->execute([$email]);

                    return 'הוענקה ל-' . $stmt->rowCount() . ' משתמש';
                },
            ],
        ],
    ];

    // --------------------------------------------------------
    // נשמר גיבוב של הטוקן ולא הטוקן עצמו. מי שמשיג גישה
    // לטבלה לא יכול להתחזות לאיש, בדיוק כמו עם סיסמאות.
    $catalog[] = [
        'id'    => '008',
        'title' => 'שחזור סיסמה',
        'steps' => [
            stepCreateTable($pdo, $dbName, 'password_resets', "
                CREATE TABLE `password_resets` (
                    `id`         INT AUTO_INCREMENT PRIMARY KEY,
                    `user_id`    INT          NOT NULL,
                    `token_hash` CHAR(64)     NOT NULL,
                    `expires_at` DATETIME     NOT NULL,
                    `used_at`    DATETIME     NULL DEFAULT NULL,
                    `ip`         VARCHAR(45)  NULL,
                    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `uniq_token` (`token_hash`),
                    INDEX `idx_resets_user` (`user_id`, `expires_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"),
        ],
    ];

    // --------------------------------------------------------
    // הטבלה מעולם לא נוצרה כאן, אלא רק דרך CREATE TABLE מאולתר
    // בתוך api/simple-notifications.php - ורק במסלול "שלח בדיקה".
    // בהתקנה נקייה שלא לחצו בה על הכפתור הזה, כל queueNotification
    // נכשל בשקט (הוא בולע את החריגה), וההתראות פשוט לא הגיעו.
    $queueSteps = [
        stepCreateTable($pdo, $dbName, 'notification_queue', "
            CREATE TABLE `notification_queue` (
                `id`            INT AUTO_INCREMENT PRIMARY KEY,
                `user_id`       INT          NULL,
                `type`          VARCHAR(50)  NOT NULL,
                `data`          TEXT         NULL,
                `status`        ENUM('pending','read','sent','completed','failed')
                                NOT NULL DEFAULT 'pending',
                `priority`      TINYINT      NOT NULL DEFAULT 5,
                `attempts`      INT          NOT NULL DEFAULT 0,
                `last_attempt`  DATETIME     NULL DEFAULT NULL,
                `error_message` VARCHAR(500) NULL DEFAULT NULL,
                `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `processed_at`  DATETIME     NULL DEFAULT NULL,
                INDEX `idx_queue_user`    (`user_id`, `status`),
                INDEX `idx_queue_pending` (`status`, `processed_at`, `priority`, `id`),
                INDEX `idx_queue_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"),
    ];

    // טבלה שנוצרה על ידי הגרסה המאולתרת חסרה את העמודות שה-cron
    // מעדכן, ולכן כל הרצה שלו נכשלת על "unknown column".
    foreach ([
        'priority'      => "`priority` TINYINT NOT NULL DEFAULT 5",
        'attempts'      => "`attempts` INT NOT NULL DEFAULT 0",
        'last_attempt'  => "`last_attempt` DATETIME NULL DEFAULT NULL",
        'error_message' => "`error_message` VARCHAR(500) NULL DEFAULT NULL",
        'processed_at'  => "`processed_at` DATETIME NULL DEFAULT NULL",
    ] as $column => $definition) {
        $queueSteps[] = stepAddColumn($pdo, $dbName, 'notification_queue', $column, $definition);
    }

    // ה-cron מסמן שורה שנשלחה כ-'completed' וכישלון כ-'failed'.
    // ב-enum הישן שני הערכים לא היו קיימים, ולכן העדכון נדחה
    // וההתראה נשארה 'pending' לנצח - ונשלחה שוב ושוב.
    $queueSteps[] = stepWidenEnum($pdo, $dbName, 'notification_queue', 'status', 'completed',
        "ENUM('pending','read','sent','completed','failed') NOT NULL DEFAULT 'pending'",
        "מצבי 'completed' ו-'failed' בתור");

    $catalog[] = ['id' => '009', 'title' => 'תור ההתראות', 'steps' => $queueSteps];

    // --------------------------------------------------------
    // המנוי הוא מה שמאפשר לדחוף התראה לדפדפן סגור. בלי הטבלה
    // api/save-push-subscription.php מחזיר שגיאה, והמשתמש רואה
    // הרשמה שנכשלת בלי סיבה נראית לעין.
    //
    // endpoint מוגבל ל-500 תווים כדי שיוכל להיכנס למפתח ייחודי
    // יחד עם user_id, ובכל זאת יכיל כתובות ארוכות של FCM.
    $pushSteps = [
        stepCreateTable($pdo, $dbName, 'push_subscriptions', "
            CREATE TABLE `push_subscriptions` (
                `id`          INT AUTO_INCREMENT PRIMARY KEY,
                `user_id`     INT          NOT NULL,
                `endpoint`    VARCHAR(500) NOT NULL,
                `p256dh`      VARCHAR(255) NULL DEFAULT NULL,
                `auth`        VARCHAR(255) NULL DEFAULT NULL,
                `user_agent`  VARCHAR(255) NULL DEFAULT NULL,
                `device_type` VARCHAR(50)  NULL DEFAULT NULL,
                `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
                `last_used`   DATETIME     NULL DEFAULT NULL,
                `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_user_endpoint` (`user_id`, `endpoint`(255)),
                INDEX `idx_push_active` (`user_id`, `is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"),
    ];

    foreach ([
        'is_active'   => "`is_active` TINYINT(1) NOT NULL DEFAULT 1",
        'last_used'   => "`last_used` DATETIME NULL DEFAULT NULL",
        'device_type' => "`device_type` VARCHAR(50) NULL DEFAULT NULL",
        'user_agent'  => "`user_agent` VARCHAR(255) NULL DEFAULT NULL",
    ] as $column => $definition) {
        $pushSteps[] = stepAddColumn($pdo, $dbName, 'push_subscriptions', $column, $definition);
    }

    $catalog[] = ['id' => '010', 'title' => 'מנויי Push', 'steps' => $pushSteps];

    // --------------------------------------------------------
    // העברת חוב ותשלום הן אותה פעולה מבחינת המאזן: החייב מזוכה
    // והמקבל מחויב. ההבדל הוא במשמעות, ובלי לתעד אותו ההיסטוריה
    // הייתה מציגה "א' שילם לג'" גם כשלא עבר שקל.
    $catalog[] = [
        'id'    => '011',
        'title' => 'סוג התחשבנות',
        'steps' => [
            stepAddColumn($pdo, $dbName, 'settlements', 'type',
                "`type` ENUM('payment','transfer') NOT NULL DEFAULT 'payment' "
                . "COMMENT 'payment = הועבר כסף; transfer = החוב עבר למשתתף אחר'"),
        ],
    ];

    return $catalog;
}

// ============================================================
// מצב והרצה
// ============================================================

/**
 * מצב כל המיגרציות, בלי לשנות דבר.
 *
 * @return array migrations (כל אחת עם steps ובהם pending), pending, total
 */
function migrationStatus(PDO $pdo, $dbName) {
    $migrations = [];
    $pendingAll = 0;
    $totalAll   = 0;

    foreach (migrationCatalog($pdo, $dbName) as $migration) {
        $steps   = [];
        $pending = 0;

        foreach ($migration['steps'] as $step) {
            try {
                $isPending = (bool)$step['pending']();
                $error     = null;
            } catch (Exception $e) {
                // בדיקה שנכשלת אינה מצדיקה מסך שבור. הצעד מוצג
                // כלא ידוע, וההרצה תגלה את המצב האמיתי.
                $isPending = false;
                $error     = $e->getMessage();
            }

            $steps[] = [
                'key'      => $step['key'],
                'label'    => $step['label'],
                'critical' => $step['critical'] ?? true,
                'pending'  => $isPending,
                'error'    => $error,
            ];

            if ($isPending) {
                $pending++;
            }
        }

        $migrations[] = [
            'id'      => $migration['id'],
            'title'   => $migration['title'],
            'steps'   => $steps,
            'pending' => $pending,
        ];

        $pendingAll += $pending;
        $totalAll   += count($steps);
    }

    return [
        'migrations' => $migrations,
        'pending'    => $pendingAll,
        'total'      => $totalAll,
    ];
}

/**
 * מריץ את כל הצעדים שממתינים.
 *
 * @param callable|null $report נקרא לכל צעד: (state, label, note)
 *                              state הוא applied | skipped | failed
 *
 * @return array applied, skipped, failed, log
 */
function runPendingMigrations(PDO $pdo, $dbName, callable $report = null) {
    $applied = 0;
    $skipped = 0;
    $failed  = 0;
    $log     = [];

    $record = function ($state, $label, $note = null) use (&$log, $report) {
        $log[] = ['state' => $state, 'label' => $label, 'note' => $note];

        if ($report) {
            $report($state, $label, $note);
        }
    };

    foreach (migrationCatalog($pdo, $dbName) as $migration) {
        $record('migration', $migration['id'] . ' - ' . $migration['title']);

        foreach ($migration['steps'] as $step) {
            try {
                if (!$step['pending']()) {
                    $skipped++;
                    $record('skipped', $step['label']);
                    continue;
                }
            } catch (Exception $e) {
                $failed++;
                $record('failed', $step['label'], $e->getMessage());
                continue;
            }

            try {
                $note = $step['run']();
                $applied++;
                $record('applied', $step['label'], $note);
            } catch (Exception $e) {
                $failed++;
                $record('failed', $step['label'], $e->getMessage());
            }
        }
    }

    return [
        'applied' => $applied,
        'skipped' => $skipped,
        'failed'  => $failed,
        'log'     => $log,
    ];
}
