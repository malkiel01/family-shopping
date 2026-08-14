<?php
/**
 * בדיקות לקיזוז בין אירועים
 * tests/offsets_test.php
 *
 * זה הקוד היחיד במערכת שכותב לשני אירועים בפעולה אחת, ולכן
 * הוא גם היחיד שטעות בו מזיזה כסף במקום שאיש לא הסתכל בו.
 * שלוש השאלות שנבדקות כאן:
 *
 *   1. **זהות.** אותו אדם הוא רשומה נפרדת בכל אירוע. אם הקישור
 *      נשבר, לא יימצא שום קיזוז - או גרוע מזה, יימצא בין שני
 *      אנשים שונים שבמקרה חולקים שם.
 *
 *   2. **כיוון.** קיזוז קיים רק כששני החובות הפוכים. שני חובות
 *      באותו כיוון אינם מקזזים דבר, והצגתם ככאלה הייתה מזמינה
 *      תשלום כפול.
 *
 *   3. **אטומיות.** קיזוז הוא שתי רשומות. אחת בלי השנייה משאירה
 *      את אחד הצדדים מזוכה בלי שהשני חויב.
 *
 * הרצה: php tests/offsets_test.php
 */

require_once __DIR__ . '/../includes/offsets.php';

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
 * מסד בזיכרון עם שני אירועים.
 *
 * שמואל (משתמש 2) ודוד (משתמש 3) חברים בשניהם. מלכיאל (משתמש 1)
 * הוא הבעלים, וחבר גם הוא - אחרת אין למי להציג את המסך.
 */
function buildFixture() {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)");
    $pdo->exec("CREATE TABLE purchase_groups (
        id INTEGER PRIMARY KEY, name TEXT, status TEXT DEFAULT 'active',
        share_rate REAL DEFAULT NULL, owner_id INTEGER, is_active INTEGER DEFAULT 1,
        event_date TEXT DEFAULT NULL
    )");
    $pdo->exec("CREATE TABLE group_members (
        id INTEGER PRIMARY KEY, group_id INTEGER, user_id INTEGER, nickname TEXT,
        email TEXT, participation_type TEXT, participation_value REAL,
        is_active INTEGER DEFAULT 1, joined_at TEXT DEFAULT '2026-01-01'
    )");
    $pdo->exec("CREATE TABLE group_purchases (
        id INTEGER PRIMARY KEY, group_id INTEGER, member_id INTEGER, amount REAL
    )");
    $pdo->exec("CREATE TABLE purchase_exclusions (purchase_id INTEGER, member_id INTEGER)");
    $pdo->exec("CREATE TABLE settlements (
        id INTEGER PRIMARY KEY, group_id INTEGER, from_member_id INTEGER,
        to_member_id INTEGER, amount REAL, note TEXT, created_by INTEGER,
        type TEXT DEFAULT 'payment'
    )");

    $pdo->exec("INSERT INTO users (id, name, email) VALUES
        (1,'מלכיאל','m@x.com'), (2,'שמואל','s@x.com'), (3,'דוד','d@x.com')");

    $pdo->exec("INSERT INTO purchase_groups (id, name, owner_id) VALUES
        (10,'אירוע א',1), (20,'אירוע ב',1)");

    // אירוע א: דוד שילם 200, חלוקה שווה בין שמואל ודוד -> שמואל חייב 100
    $pdo->exec("INSERT INTO group_members (id, group_id, user_id, nickname, email, participation_type, participation_value) VALUES
        (101,10,2,'שמואל','s@x.com','percentage',50),
        (102,10,3,'דוד','d@x.com','percentage',50)");
    $pdo->exec("INSERT INTO group_purchases (id, group_id, member_id, amount) VALUES (1001,10,102,200)");

    // אירוע ב: שמואל שילם 140, חלוקה שווה -> דוד חייב 70
    $pdo->exec("INSERT INTO group_members (id, group_id, user_id, nickname, email, participation_type, participation_value) VALUES
        (201,20,2,'שמואל','s@x.com','percentage',50),
        (202,20,3,'דוד','d@x.com','percentage',50)");
    $pdo->exec("INSERT INTO group_purchases (id, group_id, member_id, amount) VALUES (2001,20,201,140)");

    return $pdo;
}

// ============================================================
echo "\n1. זהות חוצת אירועים\n";
// ============================================================

check('משתמש רשום מזוהה לפי user_id',
    offsetIdentity(['user_id' => 7, 'email' => 'a@x.com']) === 'u7');
check('מוזמן בלי חשבון מזוהה לפי אימייל',
    offsetIdentity(['user_id' => null, 'email' => 'A@X.com']) === 'ea@x.com');
check('אותו אדם בשני אירועים מקבל אותו מזהה',
    offsetIdentity(['user_id' => 2, 'email' => 's@x.com'])
    === offsetIdentity(['user_id' => 2, 'email' => 'other@x.com']));
check('שני אנשים שונים אינם מתלכדים',
    offsetIdentity(['user_id' => 2, 'email' => 's@x.com'])
    !== offsetIdentity(['user_id' => 3, 'email' => 's@x.com']));
check('בלי חשבון ובלי אימייל אין זהות',
    offsetIdentity(['user_id' => null, 'email' => '']) === 'e');

// ============================================================
echo "\n2. איתור הקיזוז\n";
// ============================================================

$pdo = buildFixture();

// שמואל רואה את שני האירועים
$found = findOffsets($pdo, 2);

check('נמצא מועמד אחד', count($found) === 1, 'נמצאו: ' . count($found));

if ($found) {
    $c = $found[0];

    check('הצדדים הם שמואל ודוד',
        in_array($c['person_a']['name'], ['שמואל', 'דוד'], true)
        && in_array($c['person_b']['name'], ['שמואל', 'דוד'], true)
        && $c['person_a']['name'] !== $c['person_b']['name']);

    check('החוב האחד הוא 100', abs($c['debt_a']['amount'] - 100) < 0.01, $c['debt_a']['amount']);
    check('החוב השני הוא 70',  abs($c['debt_b']['amount'] - 70)  < 0.01, $c['debt_b']['amount']);
    check('ניתן לקזז 70',      abs($c['offsetable'] - 70) < 0.01, $c['offsetable']);
    check('היתרה 30',          abs($c['remainder'] - 30)  < 0.01, $c['remainder']);
    check('שני החובות באירועים שונים',
        $c['debt_a']['group_id'] !== $c['debt_b']['group_id']);
    check('לשמואל יש הרשאה - הוא צד בשניהם', $c['can_apply']);
}

// ============================================================
echo "\n3. אין קיזוז כששני החובות באותו כיוון\n";
// ============================================================

$same = buildFixture();
// באירוע ב גם דוד משלם, כך ששמואל חייב בשני האירועים
$same->exec("UPDATE group_purchases SET member_id = 202 WHERE id = 2001");

check('לא נמצא מועמד', count(findOffsets($same, 2)) === 0);

// ============================================================
echo "\n4. ביצוע קיזוז מלא\n";
// ============================================================

$pdo    = buildFixture();
$before = findOffsets($pdo, 2)[0];

$result = applyOffset(
    $pdo, 2,
    $before['debt_a']['group_id'], $before['debt_b']['group_id'],
    $before['person_a']['identity'], $before['person_b']['identity'],
    70
);

check('הקיזוז הצליח', $result['ok'], $result['message']);

$rows = $pdo->query("SELECT * FROM settlements ORDER BY group_id")->fetchAll(PDO::FETCH_ASSOC);
check('נרשמו בדיוק שתי התחשבנויות', count($rows) === 2, 'נרשמו: ' . count($rows));

if (count($rows) === 2) {
    check('אחת בכל אירוע', (int)$rows[0]['group_id'] !== (int)$rows[1]['group_id']);
    check('שתיהן על 70',
        abs($rows[0]['amount'] - 70) < 0.01 && abs($rows[1]['amount'] - 70) < 0.01);
    check('שתיהן מסומנות כקיזוז',
        $rows[0]['type'] === 'offset' && $rows[1]['type'] === 'offset');
    check('הכיוונים הפוכים זה לזה',
        (int)$rows[0]['from_member_id'] !== (int)$rows[1]['from_member_id']);
}

// אחרי הקיזוז נשאר חוב אחד בלבד, ושוב אין מה לקזז
$after = findOffsets($pdo, 2);
check('אין עוד קיזוז אפשרי', count($after) === 0, 'נשארו: ' . count($after));

$snapshot = offsetGroupSnapshot($pdo, 10, null);
check('באירוע א נשאר חוב של 30',
    count($snapshot['transfers']) === 1
    && abs($snapshot['transfers'][0]['amount'] - 30) < 0.01,
    json_encode($snapshot['transfers']));

$snapshot = offsetGroupSnapshot($pdo, 20, null);
check('אירוע ב נסגר', count($snapshot['transfers']) === 0);

// ============================================================
echo "\n5. קיזוז חלקי\n";
// ============================================================

$pdo = buildFixture();
$c   = findOffsets($pdo, 2)[0];

$result = applyOffset($pdo, 2,
    $c['debt_a']['group_id'], $c['debt_b']['group_id'],
    $c['person_a']['identity'], $c['person_b']['identity'], 30);

check('קיזוז חלקי הצליח', $result['ok'], $result['message']);
check('ההודעה מציינת את היתרה', strpos($result['message'], '40') !== false, $result['message']);

$again = findOffsets($pdo, 2);
check('נשאר קיזוז אפשרי', count($again) === 1);
if ($again) {
    check('וניתן לקזז עוד 40', abs($again[0]['offsetable'] - 40) < 0.01, $again[0]['offsetable']);
}

// ============================================================
echo "\n6. סירוב לסכום גדול מדי\n";
// ============================================================

$pdo = buildFixture();
$c   = findOffsets($pdo, 2)[0];

$result = applyOffset($pdo, 2,
    $c['debt_a']['group_id'], $c['debt_b']['group_id'],
    $c['person_a']['identity'], $c['person_b']['identity'], 500);

check('נדחה', !$result['ok']);
check('שום דבר לא נרשם',
    (int)$pdo->query("SELECT COUNT(*) FROM settlements")->fetchColumn() === 0);

$result = applyOffset($pdo, 2,
    $c['debt_a']['group_id'], $c['debt_b']['group_id'],
    $c['person_a']['identity'], $c['person_b']['identity'], 0);

check('סכום אפס נדחה', !$result['ok']);

// ============================================================
echo "\n7. אירועים משותפים\n";
// ============================================================

$pdo    = buildFixture();
$shared = findSharedGroups($pdo, 2);

check('נמצא זוג אירועים אחד', count($shared) === 1, 'נמצאו: ' . count($shared));
if ($shared) {
    check('עם שני משתתפים משותפים', $shared[0]['count'] === 2, $shared[0]['count']);
}

// אירוע שלישי עם משתתף אחד משותף בלבד אינו נחשב: זה בדרך כלל
// המשתמש עצמו, שנמצא בכל האירועים שלו
$pdo->exec("INSERT INTO purchase_groups (id, name, owner_id) VALUES (30,'אירוע ג',1)");
$pdo->exec("INSERT INTO group_members (id, group_id, user_id, nickname, email, participation_type, participation_value) VALUES
    (301,30,2,'שמואל','s@x.com','percentage',100)");

check('אירוע עם חפיפה של אדם אחד אינו נספר',
    count(findSharedGroups($pdo, 2)) === 1, count(findSharedGroups($pdo, 2)));

echo "\n" . str_repeat('=', 55) . "\n";
echo "עבר: $pass | נכשל: $fail\n";

exit($fail > 0 ? 1 : 0);
