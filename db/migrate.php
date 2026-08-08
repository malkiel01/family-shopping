<?php
/**
 * מריץ מיגרציות בצורה בטוחה ואידמפוטנטית
 * db/migrate.php
 *
 * הרצה מהטרמינל:   php db/migrate.php
 * הרצה מהדפדפן:    /family/db/migrate.php?token=XXX
 *                   (כאשר XXX הוא MIGRATION_TOKEN מקובץ ה-.env)
 *
 * הסקריפט בודק לפני כל פעולה אם היא כבר בוצעה, כך שאפשר
 * להריץ אותו שוב ושוב בלי לשבור כלום.
 */

require_once __DIR__ . '/../config.php';

$isCli = (php_sapi_name() === 'cli');

// --- הגנה על הרצה מהדפדפן ---
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $expected = $_ENV['MIGRATION_TOKEN'] ?? '';
    $given    = $_GET['token'] ?? '';

    if ($expected === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        echo "גישה נדחתה.\n";
        echo "יש להגדיר MIGRATION_TOKEN בקובץ .env ולהעביר אותו כפרמטר ?token=\n";
        exit;
    }
}

$pdo = getDBConnection();
$dbName = DB_NAME;

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

$applied = 0;
$skipped = 0;
$failed  = 0;

function step($label, callable $shouldRun, callable $run) {
    global $applied, $skipped, $failed;

    if (!$shouldRun()) {
        echo "  [דילוג]  $label\n";
        $skipped++;
        return;
    }

    try {
        $run();
        echo "  [בוצע]   $label\n";
        $applied++;
    } catch (Exception $e) {
        echo "  [נכשל]   $label\n";
        echo "           " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "מיגרציה 001 - מנהל אירוע משפחתי\n";
echo str_repeat('=', 60) . "\n";

// ------------------------------------------------------------
// 1. הרחבת הקבוצה למושג "אירוע"
// ------------------------------------------------------------
$eventColumns = [
    'event_date'     => "ADD COLUMN `event_date` DATE NULL DEFAULT NULL",
    'event_location' => "ADD COLUMN `event_location` VARCHAR(255) NULL DEFAULT NULL",
    'status'         => "ADD COLUMN `status` ENUM('planning','active','closed') NOT NULL DEFAULT 'active'",
    'closed_at'      => "ADD COLUMN `closed_at` DATETIME NULL DEFAULT NULL",
];

foreach ($eventColumns as $column => $sql) {
    step(
        "purchase_groups.$column",
        function () use ($pdo, $dbName, $column) {
            return !columnExists($pdo, $dbName, 'purchase_groups', $column);
        },
        function () use ($pdo, $sql) {
            $pdo->exec("ALTER TABLE `purchase_groups` $sql");
        }
    );
}

step(
    "אינדקס purchase_groups.event_date",
    function () use ($pdo, $dbName) {
        return columnExists($pdo, $dbName, 'purchase_groups', 'event_date')
            && !indexExists($pdo, $dbName, 'purchase_groups', 'idx_groups_event_date');
    },
    function () use ($pdo) {
        $pdo->exec("ALTER TABLE `purchase_groups` ADD INDEX `idx_groups_event_date` (`event_date`)");
    }
);

// ------------------------------------------------------------
// 2-4. טבלאות חדשות
// ------------------------------------------------------------
$tables = [
    'shopping_items' => "
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'purchase_exclusions' => "
        CREATE TABLE `purchase_exclusions` (
            `purchase_id` INT NOT NULL,
            `member_id`   INT NOT NULL,
            PRIMARY KEY (`purchase_id`, `member_id`),
            INDEX `idx_excl_member` (`member_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'settlements' => "
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

foreach ($tables as $table => $sql) {
    step(
        "טבלה $table",
        function () use ($pdo, $dbName, $table) {
            return !tableExists($pdo, $dbName, $table);
        },
        function () use ($pdo, $sql) {
            $pdo->exec($sql);
        }
    );
}

// ------------------------------------------------------------
// 5. מפתחות זרים - כישלון כאן אינו קריטי
// ------------------------------------------------------------
$foreignKeys = [
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
];

foreach ($foreignKeys as [$table, $name, $sql]) {
    step(
        "מפתח זר $name",
        function () use ($pdo, $dbName, $table, $name) {
            return tableExists($pdo, $dbName, $table)
                && !constraintExists($pdo, $dbName, $table, $name);
        },
        function () use ($pdo, $table, $sql) {
            $pdo->exec("ALTER TABLE `$table` $sql");
        }
    );
}

// ------------------------------------------------------------
// 6. אינדקס על טוקן ההזמנה
// ------------------------------------------------------------
step(
    "אינדקס group_invitations.token",
    function () use ($pdo, $dbName) {
        return columnExists($pdo, $dbName, 'group_invitations', 'token')
            && !indexExists($pdo, $dbName, 'group_invitations', 'idx_invitations_token');
    },
    function () use ($pdo) {
        $pdo->exec("ALTER TABLE `group_invitations` ADD INDEX `idx_invitations_token` (`token`)");
    }
);

// ============================================================
echo "\nמיגרציה 002 - הגבלת קצב על התחברות\n";
echo str_repeat('=', 60) . "\n";

step(
    'טבלה login_attempts',
    function () use ($pdo, $dbName) {
        return !tableExists($pdo, $dbName, 'login_attempts');
    },
    function () use ($pdo) {
        $pdo->exec("
            CREATE TABLE `login_attempts` (
                `id`           INT AUTO_INCREMENT PRIMARY KEY,
                `identifier`   VARCHAR(190) NOT NULL COMMENT 'שם המשתמש או האימייל שהוזן',
                `ip`           VARCHAR(45)  NOT NULL,
                `succeeded`    TINYINT(1)   NOT NULL DEFAULT 0,
                `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_attempts_ip`      (`ip`, `attempted_at`),
                INDEX `idx_attempts_account` (`identifier`, `attempted_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
);

// ============================================================
echo "\nמיגרציה 003 - יומן המיילים\n";
echo str_repeat('=', 60) . "\n";

step(
    'טבלה email_log',
    function () use ($pdo, $dbName) {
        return !tableExists($pdo, $dbName, 'email_log');
    },
    function () use ($pdo) {
        // EmailService כותב לטבלה הזו בכל שליחה. היא לא הייתה
        // קיימת, ולכן כל רישום נכשל בשקט ולא היה שום תיעוד
        // של מיילים שיצאו או נכשלו.
        $pdo->exec("
            CREATE TABLE `email_log` (
                `id`        INT AUTO_INCREMENT PRIMARY KEY,
                `to_email`  VARCHAR(255) NOT NULL,
                `subject`   VARCHAR(255) NOT NULL,
                `status`    ENUM('sent','failed') NOT NULL DEFAULT 'sent',
                `sent_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_email_log_sent` (`sent_at`),
                INDEX `idx_email_log_to`   (`to_email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
);

// ============================================================
echo "\nמיגרציה 004 - חלוקה לפי נפשות\n";
echo str_repeat('=', 60) . "\n";

step(
    "סוג השתתפות 'shares'",
    function () use ($pdo, $dbName) {
        $stmt = $pdo->prepare("
            SELECT COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'group_members'
              AND COLUMN_NAME = 'participation_type'
        ");
        $stmt->execute([$dbName]);
        $type = (string)$stmt->fetchColumn();

        return $type !== '' && strpos($type, "'shares'") === false;
    },
    function () use ($pdo) {
        // הערכים הקיימים נשמרים כפי שהם; רק נוסף ערך אפשרי חדש
        $pdo->exec("
            ALTER TABLE `group_members`
            MODIFY COLUMN `participation_type`
                ENUM('percentage','fixed','auto_percentage','shares')
                NOT NULL DEFAULT 'percentage'
        ");
    }
);

step(
    "סוג השתתפות 'shares' בהזמנות",
    function () use ($pdo, $dbName) {
        $stmt = $pdo->prepare("
            SELECT COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'group_invitations'
              AND COLUMN_NAME = 'participation_type'
        ");
        $stmt->execute([$dbName]);
        $type = (string)$stmt->fetchColumn();

        return $type !== '' && strpos($type, "'shares'") === false;
    },
    function () use ($pdo) {
        $pdo->exec("
            ALTER TABLE `group_invitations`
            MODIFY COLUMN `participation_type`
                ENUM('percentage','fixed','auto_percentage','shares')
                NOT NULL DEFAULT 'percentage'
        ");
    }
);

// ============================================================
echo "\nמיגרציה 005 - תעריף לנפש\n";
echo str_repeat('=', 60) . "\n";

step(
    'purchase_groups.share_rate',
    function () use ($pdo, $dbName) {
        return !columnExists($pdo, $dbName, 'purchase_groups', 'share_rate');
    },
    function () use ($pdo) {
        // כשחלק מהמשתתפים באחוזים וחלקם בנפשות, אין דרך לתרגם
        // "3 נפשות" למספר שאפשר להשוות ל-"20%" - אלא אם נקבע
        // תעריף קבוע לנפש. NULL פירושו חלוקה יחסית רגילה.
        $pdo->exec("
            ALTER TABLE `purchase_groups`
            ADD COLUMN `share_rate` DECIMAL(10,2) NULL DEFAULT NULL
                COMMENT 'תעריף לנפש; NULL = חלוקה יחסית'
        ");
    }
);

// ============================================================
echo "\nמיגרציה 006 - אנשי קשר\n";
echo str_repeat('=', 60) . "\n";

step(
    'טבלה contacts',
    function () use ($pdo, $dbName) {
        return !tableExists($pdo, $dbName, 'contacts');
    },
    function () use ($pdo) {
        // רשימת אנשי הקשר שייכת למשתמש ולא לאירוע, ולכן היא
        // נצברת מכל הקבוצות שלו ומשמשת בכל אירוע חדש
        $pdo->exec("
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
);

// ייבוא חד-פעמי מההיסטוריה, כדי שהרשימה לא תתחיל ריקה
step(
    'ייבוא אנשי קשר מההזמנות הקיימות',
    function () use ($pdo, $dbName) {
        if (!tableExists($pdo, $dbName, 'contacts')) {
            return false;
        }

        return (int)$pdo->query("SELECT COUNT(*) FROM contacts")->fetchColumn() === 0;
    },
    function () use ($pdo) {
        require_once __DIR__ . '/../includes/contacts.php';

        $owners = $pdo->query("
            SELECT DISTINCT owner_id FROM purchase_groups WHERE owner_id IS NOT NULL
        ")->fetchAll(PDO::FETCH_COLUMN);

        $total = 0;
        foreach ($owners as $ownerId) {
            $total += importContactsFromHistory($pdo, $ownerId);
        }

        echo "           (יובאו $total אנשי קשר)\n";
    }
);

// ============================================================
echo "\nמיגרציה 007 - פלטפורמת ניהול\n";
echo str_repeat('=', 60) . "\n";

step(
    'users.is_admin',
    function () use ($pdo, $dbName) {
        return !columnExists($pdo, $dbName, 'users', 'is_admin');
    },
    function () use ($pdo) {
        $pdo->exec("
            ALTER TABLE `users`
            ADD COLUMN `is_admin` TINYINT(1) NOT NULL DEFAULT 0
                COMMENT 'הרשאת ניהול לכלל המערכת'
        ");
    }
);

step(
    'טבלה admin_actions',
    function () use ($pdo, $dbName) {
        return !tableExists($pdo, $dbName, 'admin_actions');
    },
    function () use ($pdo) {
        // פעולה שמנהל מבצע בשם משתמש אחר חייבת להשאיר עקבות.
        // בלי יומן, אי אפשר לענות על השאלה "מי אישר את זה ומתי".
        $pdo->exec("
            CREATE TABLE `admin_actions` (
                `id`          INT AUTO_INCREMENT PRIMARY KEY,
                `admin_id`    INT          NOT NULL,
                `action`      VARCHAR(50)  NOT NULL,
                `target_type` VARCHAR(50)  NULL,
                `target_id`   INT          NULL,
                `details`     TEXT         NULL,
                `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_admin_actions_admin` (`admin_id`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
);

step(
    'הענקת הרשאת ניהול לפי ADMIN_EMAIL',
    function () use ($pdo, $dbName) {
        if (!columnExists($pdo, $dbName, 'users', 'is_admin')) {
            return false;
        }

        // רק אם אין עדיין אף מנהל, וההגדרה קיימת
        $email = trim($_ENV['ADMIN_EMAIL'] ?? '');

        return $email !== ''
            && (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 1")->fetchColumn() === 0;
    },
    function () use ($pdo) {
        $email = trim($_ENV['ADMIN_EMAIL'] ?? '');
        $stmt  = $pdo->prepare("UPDATE users SET is_admin = 1 WHERE email = ?");
        $stmt->execute([$email]);

        echo "           (הוענקה ל-" . $stmt->rowCount() . " משתמש)\n";
    }
);

echo str_repeat('=', 60) . "\n";
echo "בוצעו: $applied | דילוגים: $skipped | כשלונות: $failed\n";

if ($failed > 0) {
    echo "\nכשלונות במפתחות זרים בדרך כלל אינם קריטיים -\n";
    echo "הם קורים כשמנוע הטבלה אינו InnoDB או כשיש נתונים יתומים.\n";
    echo "המערכת תעבוד גם בלעדיהם.\n";
}

if (!$isCli) {
    echo "\nמומלץ למחוק את MIGRATION_TOKEN מה-.env בסיום.\n";
}
