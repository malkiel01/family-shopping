<?php
/**
 * בדיקות יומן החישובים
 * tests/debug_log_test.php
 *
 * ליומן דיאגנוסטי יש חובה אחת שקודמת לכל השאר: לא לשבור את מה
 * שהוא בא לחקור. תשלום שנכשל בגלל היומן גרוע לאין שיעור מהבאג
 * שבגללו הדליקו אותו. לכן שלוש הבדיקות הראשונות כאן הן על
 * חוסר-הזק, ורק אחריהן מגיעות הבדיקות על מה שהוא מוצא.
 *
 * הרצה: php tests/debug_log_test.php
 */

require_once __DIR__ . '/../includes/debug_log.php';

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

/**
 * מסד בזיכרון: שלושה משתתפים ב-50/25/25, ומלכיאל שילם 300.
 * שני האחרים חייבים לו 75 כל אחד. המספרים נבחרו כך שהאחוזים
 * מסתכמים ב-100 בדיוק - אחרת כל בדיקה כאן הייתה נגררת אחרי
 * אגורות עיגול שאינן קשורות לנושא.
 */
function buildDebugFixture($withDebugTables = true) {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)");
    $pdo->exec("CREATE TABLE purchase_groups (
        id INTEGER PRIMARY KEY, name TEXT, share_rate REAL DEFAULT NULL
    )");
    $pdo->exec("CREATE TABLE group_members (
        id INTEGER PRIMARY KEY, group_id INTEGER, user_id INTEGER, nickname TEXT,
        email TEXT, participation_type TEXT, participation_value REAL,
        is_active INTEGER DEFAULT 1, joined_at TEXT DEFAULT '2026-01-01'
    )");
    $pdo->exec("CREATE TABLE group_purchases (
        id INTEGER PRIMARY KEY, group_id INTEGER, member_id INTEGER,
        amount REAL, description TEXT
    )");
    $pdo->exec("CREATE TABLE purchase_exclusions (purchase_id INTEGER, member_id INTEGER)");
    $pdo->exec("CREATE TABLE settlements (
        id INTEGER PRIMARY KEY, group_id INTEGER, from_member_id INTEGER,
        to_member_id INTEGER, amount REAL, created_at TEXT DEFAULT '2026-01-01'
    )");

    if ($withDebugTables) {
        $pdo->exec("CREATE TABLE app_settings (
            name TEXT PRIMARY KEY, value TEXT, updated_at TEXT DEFAULT '2026-01-01'
        )");
        $pdo->exec("CREATE TABLE calculation_debug (
            id INTEGER PRIMARY KEY, group_id INTEGER, user_id INTEGER, action TEXT,
            payload TEXT, before_json TEXT, after_json TEXT,
            created_at TEXT DEFAULT '2026-01-01'
        )");
    }

    $pdo->exec("INSERT INTO users (id, name, email) VALUES
        (1,'מלכיאל','m@x.com'), (2,'שמואל','s@x.com'), (3,'חלילי','h@x.com')");
    $pdo->exec("INSERT INTO purchase_groups (id, name) VALUES (10,'אירוע')");
    $pdo->exec("INSERT INTO group_members
        (id, group_id, user_id, nickname, email, participation_type, participation_value) VALUES
        (101,10,1,'מלכיאל','m@x.com','percentage',50),
        (102,10,2,'שמואל','s@x.com','percentage',25),
        (103,10,3,'חלילי','h@x.com','percentage',25)");
    $pdo->exec("INSERT INTO group_purchases (id, group_id, member_id, amount) VALUES (1001,10,101,300)");

    return $pdo;
}

// ============================================================
echo "\n1. היומן אינו מפריע לפעולה\n";
// ============================================================

// כבוי: הסגור רץ, הערך חוזר, שום דבר לא נרשם
$pdo    = buildDebugFixture();
$called = 0;

$returned = debugAround($pdo, 10, 1, 'payment', [], function () use (&$called) {
    $called++;
    return 'ערך-מוחזר';
});

check('הפעולה רצה כשהיומן כבוי', $called === 1);
check('הערך המוחזר עובר כמו שהוא', $returned === 'ערך-מוחזר', var_export($returned, true));
check('שום דבר לא נרשם',
    (int)$pdo->query("SELECT COUNT(*) FROM calculation_debug")->fetchColumn() === 0);

// בלי הטבלאות בכלל - שרת שהמיגרציה עוד לא רצה בו
$bare   = buildDebugFixture(false);
$called = 0;

$returned = debugAround($bare, 10, 1, 'payment', [], function () use (&$called) {
    $called++;
    return 42;
});

check('הפעולה רצה גם בלי טבלאות היומן', $called === 1 && $returned === 42);

// דלוק: הפעולה עדיין רצה בדיוק פעם אחת
$pdo = buildDebugFixture();
setDebugLogEnabled($pdo, true);
check('אפשר להדליק', debugLogEnabled($pdo));

$called = 0;
$returned = debugAround($pdo, 10, 1, 'payment', ['amount' => 75], function () use ($pdo, &$called) {
    $called++;
    $pdo->exec("INSERT INTO settlements (group_id, from_member_id, to_member_id, amount)
                VALUES (10, 102, 101, 75)");
    return (int)$pdo->lastInsertId();
});

check('הפעולה רצה פעם אחת בלבד כשהיומן דלוק', $called === 1);
check('lastInsertId נשמר לפני הצילום השני', $returned > 0, $returned);
check('נרשמה רשומה אחת',
    (int)$pdo->query("SELECT COUNT(*) FROM calculation_debug")->fetchColumn() === 1);

// סגור שזורק חריגה - היומן לא בולע אותה
$threw = false;
try {
    debugAround($pdo, 10, 1, 'payment', [], function () {
        throw new RuntimeException('נפילה מכוונת');
    });
} catch (RuntimeException $e) {
    $threw = true;
}
check('חריגה מהפעולה אינה נבלעת', $threw);

// ============================================================
echo "\n2. הצילום מתאר את המצב\n";
// ============================================================

$pdo      = buildDebugFixture();
$snapshot = debugSnapshot($pdo, 10);

check('שלוש שורות משתתף', $snapshot['member_rows'] === 3, $snapshot['member_rows']);
check('שלושה משתתפים ייחודיים', $snapshot['unique_members'] === 3);
check('סך ההוצאות 300', abs($snapshot['total'] - 300) < 0.01, $snapshot['total']);
check('שתי שורות העברה', $snapshot['transfer_count'] === 2, $snapshot['transfer_count']);
check('סך ההעברות 150', abs($snapshot['transfer_total'] - 150) < 0.01, $snapshot['transfer_total']);
check('אין התחשבנויות', $snapshot['stored_settlements'] === 0);

$failed = array_filter(debugChecks($snapshot), function ($check) { return !$check['ok']; });
check('כל בדיקות התקינות עוברות על נתונים נקיים',
    count($failed) === 0,
    implode(', ', array_map(function ($check) { return $check['label']; }, $failed)));

// ============================================================
echo "\n3. תשלום מוריד מסך החוב\n";
// ============================================================
// זו הטענה שהמשתמש דיווח עליה כשבורה: "מבצעים תשלום ולא פוחת
// מסכום הכסף הכולל". הבדיקה כאן קובעת מה ההתנהגות הנכונה.

$before = debugSnapshot($pdo, 10);
$pdo->exec("INSERT INTO settlements (group_id, from_member_id, to_member_id, amount)
            VALUES (10, 102, 101, 75)");
$after = debugSnapshot($pdo, 10);

$diff = debugDiff($before, $after);

check('ההשוואה תקפה', $diff['ok']);
check('סך ההעברות פחת ב-75',
    abs($diff['transfer_total_delta'] + 75) < 0.01, $diff['transfer_total_delta']);
check('נשארה שורת העברה אחת', $diff['count_after'] === 1, $diff['count_after']);
check('השורה של המשלם נסגרה',
    count(array_filter($diff['notes'], function ($note) {
        return strpos($note, 'נסגרה') === 0 && strpos($note, 'שמואל') !== false;
    })) === 1,
    implode(' | ', $diff['notes']));
check('החוב השני לא זז',
    count(array_filter($diff['notes'], function ($note) {
        return strpos($note, 'חלילי') !== false;
    })) === 0,
    implode(' | ', $diff['notes']));

// ============================================================
echo "\n4. משתתף כפול מזוהה\n";
// ============================================================
// ההשערה המרכזית לשורה שמופיעה פעמיים: אותו אדם רשום בשתי
// שורות. אם זה מה שקורה, הבדיקות חייבות לומר זאת במפורש.

$dup = buildDebugFixture();
$dup->exec("INSERT INTO group_members
    (id, group_id, user_id, nickname, email, participation_type, participation_value)
    VALUES (104,10,3,'חלילי','h@x.com','percentage',33.33)");

$snapshot = debugSnapshot($dup, 10);
$byLabel  = [];
foreach (debugChecks($snapshot) as $check) {
    $byLabel[$check['label']] = $check;
}

check('נמצאו 4 שורות משתתף', $snapshot['member_rows'] === 4);
check('אבל 3 משתתפים ייחודיים', $snapshot['unique_members'] === 3, $snapshot['unique_members']);
check('בדיקת הכפילות נכשלת', !$byLabel['אין רשומת משתתף כפולה']['ok']);
check('בדיקת השם הכפול נכשלת', !$byLabel['אין שם שמופיע בשתי רשומות']['ok']);
check('בדיקת האימייל הכפול נכשלת', !$byLabel['אין אימייל שמופיע בשתי רשומות']['ok']);
check('הכפילות מצוינת בשם', strpos($byLabel['אין שם שמופיע בשתי רשומות']['detail'], 'חלילי') !== false,
    $byLabel['אין שם שמופיע בשתי רשומות']['detail']);

// ============================================================
echo "\n5. התחשבנות שנעלמת בחישוב\n";
// ============================================================
// התחשבנות שמצביעה על משתתף שאינו קיים נופלת ב-JOIN, והחוב
// ששולם חוזר להופיע. זה בדיוק "שילמתי והכסף לא ירד".

$lost = buildDebugFixture();
$lost->exec("INSERT INTO settlements (group_id, from_member_id, to_member_id, amount)
             VALUES (10, 102, 999, 75)");

$snapshot = debugSnapshot($lost, 10);
$byLabel  = [];
foreach (debugChecks($snapshot) as $check) {
    $byLabel[$check['label']] = $check;
}

check('ההתחשבנות קיימת במסד', $snapshot['stored_settlements'] === 1);
check('אך לא נכנסה לחישוב', $snapshot['used_settlements'] === 0);
check('הבדיקה מתריעה', !$byLabel['כל ההתחשבנויות נכנסו לחישוב']['ok']);
check('החוב עדיין מוצג במלואו',
    abs($snapshot['transfer_total'] - 150) < 0.01, $snapshot['transfer_total']);

// ============================================================
echo "\n6. הדוח להעתקה\n";
// ============================================================

$pdo = buildDebugFixture();
setDebugLogEnabled($pdo, true);

debugAround($pdo, 10, 1, 'payment', ['amount' => 75], function () use ($pdo) {
    $pdo->exec("INSERT INTO settlements (group_id, from_member_id, to_member_id, amount)
                VALUES (10, 102, 101, 75)");
});

$entries = debugLogEntries($pdo, 20, 10);
check('הרשומה נקראת חזרה', count($entries) === 1, count($entries));

$text = debugLogText($entries, debugSnapshot($pdo, 10));

check('הדוח אינו ריק', strlen($text) > 100, strlen($text));
check('הדוח מציין את שם האירוע', strpos($text, 'אירוע') !== false);
check('הדוח מציין את הפעולה', strpos($text, 'payment') !== false);
check('הדוח מכיל שורת "לפני"', strpos($text, 'לפני 1:') !== false);
check('הדוח מכיל שורת "אחרי"', strpos($text, 'אחרי 1:') !== false);
check('הדוח מציין את השינוי בסך ההעברות', strpos($text, 'שינוי -75.00') !== false);
check('הדוח הוא טקסט שאפשר להדביק', strpos($text, '<') === false);

// ============================================================
echo "\n7. גיזום\n";
// ============================================================

$pdo = buildDebugFixture();
for ($i = 0; $i < DEBUG_LOG_LIMIT + 15; $i++) {
    debugLogWrite($pdo, 10, 1, 'payment', [], ['transfers' => []], ['transfers' => []]);
}

$count = (int)$pdo->query("SELECT COUNT(*) FROM calculation_debug")->fetchColumn();
check('היומן אינו גדל בלי גבול', $count <= DEBUG_LOG_LIMIT, $count);

check('ניקוי מוחק הכל', clearDebugLog($pdo) === $count);
check('ואחריו ריק',
    (int)$pdo->query("SELECT COUNT(*) FROM calculation_debug")->fetchColumn() === 0);

echo "\n" . str_repeat('=', 55) . "\n";
echo "עבר: $pass | נכשל: $fail\n";

exit($fail > 0 ? 1 : 0);
