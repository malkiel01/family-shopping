<?php
/**
 * מריץ מיגרציות בצורה בטוחה ואידמפוטנטית
 * db/migrate.php
 *
 * הרצה מהטרמינל:   php db/migrate.php
 * הרצה מהדפדפן:    /family/db/migrate.php?token=XXX
 *                   (כאשר XXX הוא MIGRATION_TOKEN מקובץ ה-.env)
 *
 * אפשר גם להריץ אותן ממסך "תחזוקה ופיתוח" בממשק הניהול.
 *
 * המיגרציות עצמן מוגדרות ב-db/migrations.php. הקובץ הזה הוא
 * מעטפת הרצה בלבד: הרשאה, הדפסה, וסיכום.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/migrations.php';

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

$labels = [
    'applied' => '  [בוצע]   ',
    'skipped' => '  [דילוג]  ',
    'failed'  => '  [נכשל]   ',
];

$result = runPendingMigrations($pdo, DB_NAME, function ($state, $label, $note) use ($labels) {
    if ($state === 'migration') {
        echo "\nמיגרציה $label\n";
        echo str_repeat('=', 60) . "\n";
        return;
    }

    echo $labels[$state] . $label . "\n";

    if ($note !== null) {
        echo "           ($note)\n";
    }
});

echo "\n" . str_repeat('=', 60) . "\n";
echo "בוצעו: {$result['applied']} | דילוגים: {$result['skipped']} | כשלונות: {$result['failed']}\n";

if ($result['failed'] > 0) {
    echo "\nכשלונות במפתחות זרים בדרך כלל אינם קריטיים -\n";
    echo "הם קורים כשמנוע הטבלה אינו InnoDB או כשיש נתונים יתומים.\n";
    echo "המערכת תעבוד גם בלעדיהם.\n";
}

if (!$isCli) {
    echo "\nמומלץ למחוק את MIGRATION_TOKEN מה-.env בסיום.\n";
}
