<?php
/**
 * בדיקות לקטלוג המיגרציות
 * tests/migrations_test.php
 *
 * המיגרציות עצמן מדברות MySQL, ולכן אי אפשר להריץ אותן כאן -
 * אין מסד נתונים בסביבת הבדיקות. מה שכן אפשר לבדוק, ומה שבאמת
 * נשבר בפועל, הוא שני דברים:
 *
 *   1. מבנה הקטלוג. הוא נצרך על ידי שני צרכנים - db/migrate.php
 *      ומסך התחזוקה - ושניהם מניחים שלכל צעד יש key, label ושתי
 *      פונקציות. מפתח כפול או צעד חסר שוברים את המסך בלי אזהרה.
 *
 *   2. עמידות בפני מסד שאינו עונה. מסך התחזוקה הוא בדיוק המקום
 *      שאליו מגיעים כשמשהו לא תקין, ולכן הוא לא רשאי לקרוס
 *      כשבדיקת הקיום נכשלת. כאן זה נבדק מול SQLite, שאין בו
 *      information_schema - כלומר כל בדיקת קיום זורקת חריגה.
 *
 * הרצה: php tests/migrations_test.php
 */

require_once __DIR__ . '/../db/migrations.php';

$pass = 0;
$fail = 0;

function check($label, $condition, $detail = '') {
    global $pass, $fail;

    if ($condition) {
        $pass++;
        echo "  ok   $label\n";
    } else {
        $fail++;
        echo "  FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

// מסד שלא יודע לענות על אף בדיקת קיום
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// ============================================================
echo "\n1. מבנה הקטלוג\n";
// ============================================================

$catalog = migrationCatalog($pdo, 'test_db');

check('הקטלוג אינו ריק', count($catalog) > 0, 'מיגרציות: ' . count($catalog));

$ids        = [];
$stepKeys   = [];
$stepCount  = 0;
$structureOk = true;

foreach ($catalog as $migration) {
    $ids[] = $migration['id'] ?? '(חסר)';

    if (!isset($migration['id'], $migration['title'], $migration['steps'])) {
        $structureOk = false;
        continue;
    }

    foreach ($migration['steps'] as $step) {
        $stepCount++;
        $stepKeys[] = $step['key'] ?? '(חסר)';

        if (!isset($step['key'], $step['label'])
            || !is_callable($step['pending'] ?? null)
            || !is_callable($step['run'] ?? null)) {
            $structureOk = false;
            echo "       צעד פגום: " . json_encode($step['key'] ?? '?', JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
}

check('לכל מיגרציה יש id, title ו-steps', $structureOk);
check('לכל צעד יש key, label, pending ו-run', $structureOk);
check('יש צעדים בקטלוג', $stepCount > 0, "צעדים: $stepCount");

check('מזהי המיגרציות ייחודיים', count($ids) === count(array_unique($ids)));
check('מזהי המיגרציות ממוינים', $ids === (function ($sorted) {
    sort($sorted);
    return $sorted;
})($ids), implode(', ', $ids));

// מפתח הצעד הוא המזהה שלו בממשק. שני צעדים עם אותו מפתח היו
// נראים כצעד אחד, והמצב של אחד מהם היה נעלם מהמסך.
$duplicates = array_keys(array_filter(array_count_values($stepKeys), function ($count) {
    return $count > 1;
}));

check('מפתחות הצעדים ייחודיים', !$duplicates, implode(', ', $duplicates));

// ============================================================
echo "\n2. הצגת מצב מול מסד שאינו עונה\n";
// ============================================================

$status = null;
$threw  = false;

try {
    $status = migrationStatus($pdo, 'test_db');
} catch (Throwable $e) {
    $threw = true;
    echo "       חריגה: " . $e->getMessage() . "\n";
}

check('migrationStatus אינה זורקת חריגה', !$threw);
check('התקבל מבנה תשובה מלא',
    is_array($status) && isset($status['migrations'], $status['pending'], $status['total']));

if (is_array($status)) {
    check('מספר המיגרציות תואם לקטלוג', count($status['migrations']) === count($catalog));
    check('סך הצעדים תואם לקטלוג', $status['total'] === $stepCount,
        "בסטטוס: {$status['total']}, בקטלוג: $stepCount");

    // בדיקה שנכשלה אינה יכולה להיחשב "ממתין": צעד שמוצג כממתין
    // בטעות מזמין הרצה מיותרת, ובמקרה הגרוע נראה כאילו המערכת
    // לא מעודכנת בזמן שהיא כן
    check('צעד שבדיקתו נכשלה אינו מסומן כממתין', $status['pending'] === 0,
        "ממתינים: {$status['pending']}");

    $withError = 0;
    foreach ($status['migrations'] as $migration) {
        foreach ($migration['steps'] as $step) {
            if ($step['error'] !== null) {
                $withError++;
            }
        }
    }

    check('שגיאת הבדיקה מדווחת בכל צעד', $withError === $stepCount,
        "עם שגיאה: $withError מתוך $stepCount");
}

// ============================================================
echo "\n3. הרצה מול מסד שאינו עונה\n";
// ============================================================

$result = null;
$threw  = false;

try {
    $result = runPendingMigrations($pdo, 'test_db');
} catch (Throwable $e) {
    $threw = true;
    echo "       חריגה: " . $e->getMessage() . "\n";
}

check('runPendingMigrations אינה זורקת חריגה', !$threw);
check('התקבל דוח מלא',
    is_array($result) && isset($result['applied'], $result['skipped'], $result['failed'], $result['log']));

if (is_array($result)) {
    check('שום צעד לא בוצע', $result['applied'] === 0, "בוצעו: {$result['applied']}");
    check('כל הצעדים נספרו ככשלון', $result['failed'] === $stepCount,
        "נכשלו: {$result['failed']}, צעדים: $stepCount");

    // היומן כולל גם שורת כותרת לכל מיגרציה, מעבר לצעדים עצמם
    $headers = count(array_filter($result['log'], function ($entry) {
        return $entry['state'] === 'migration';
    }));

    check('היומן פותח כל מיגרציה בשורת כותרת', $headers === count($catalog),
        "כותרות: $headers");
    check('היומן מכיל שורה לכל צעד',
        count($result['log']) === $stepCount + $headers, 'שורות: ' . count($result['log']));
}

// ============================================================
echo "\n4. הרצה מדווחת דרך callback\n";
// ============================================================

$reported = [];
runPendingMigrations($pdo, 'test_db', function ($state, $label, $note) use (&$reported) {
    $reported[] = $state;
});

check('הדיווח נקרא לכל שורה ביומן',
    count($reported) === $stepCount + count($catalog), 'דיווחים: ' . count($reported));
check('הדיווח כולל מצבים בלבד מהמילון המוכר',
    array_diff(array_unique($reported), ['migration', 'applied', 'skipped', 'failed']) === []);

echo "\n" . str_repeat('=', 55) . "\n";
echo "עבר: $pass | נכשל: $fail\n";

exit($fail > 0 ? 1 : 0);
