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
