<?php
/**
 * בדיקות אינטגרציה לשכבת הפעולות
 * tests/actions_test.php
 *
 * רץ מול SQLite בזיכרון עם סכמה מקבילה לזו של MySQL, כדי לבדוק
 * את הזרימות עצמן - הרשאות, החרגות, התחשבנות וסגירת אירוע -
 * בלי להזדקק לשרת מסד נתונים.
 *
 * הרצה: php tests/calculations_test.php && php tests/actions_test.php
 */

define('MAX_FILE_SIZE', 5242880);
define('UPLOAD_DIR', 'uploads/');

// דמה של שליחת ההתראה, כדי לא לגרור את שכבת ה-push לבדיקות
$GLOBALS['notified_purchases'] = [];
function notifyNewPurchase($purchaseId) {
    $GLOBALS['notified_purchases'][] = $purchaseId;
    return ['success' => true];
}

require_once __DIR__ . '/../includes/group_actions.php';
require_once __DIR__ . '/../includes/group_delete.php';

// ------------------------------------------------------------
// תשתית בדיקה
// ------------------------------------------------------------

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

/** מריץ פעולה ומחזיר את ה-JSON שהוחזר */
function run($pdo, $action, array $post, $userId, $isOwner, $memberId, $status = 'active') {
    $_POST = array_merge(['action' => $action], $post);

    ob_start();
    handleGroupActions($pdo, 1, $userId, $isOwner, $memberId, $status);
    $output = ob_get_clean();

    $decoded = json_decode($output, true);

    return is_array($decoded) ? $decoded : ['success' => false, 'message' => 'bad json: ' . $output];
}

/**
 * PDO של SQLite שמתרגם תחביר MySQL תקין שאין לו מקבילה ישירה.
 * CURRENT_DATE() חוקי לגמרי ב-MySQL, אבל ב-SQLite זו מילה שמורה
 * שאי אפשר לקרוא לה כפונקציה - אז מסירים את הסוגריים.
 */
class TestPdo extends PDO {
    private static function translate($sql) {
        return str_ireplace('CURRENT_DATE()', "CURRENT_DATE", $sql);
    }

    public function prepare($query, $options = []): PDOStatement|false {
        return parent::prepare(self::translate($query), $options);
    }

    public function exec($statement): int|false {
        return parent::exec(self::translate($statement));
    }
}

function makeDb() {
    $pdo = new TestPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // פונקציות MySQL שאין ב-SQLite
    $pdo->sqliteCreateFunction('NOW', function () {
        return date('Y-m-d H:i:s');
    }, 0);

    $pdo->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT, is_active INTEGER DEFAULT 1)");

    $pdo->exec("CREATE TABLE purchase_groups (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, description TEXT, owner_id INTEGER,
        event_date TEXT, event_location TEXT, status TEXT DEFAULT 'active', closed_at TEXT,
        share_rate REAL DEFAULT NULL,
        is_active INTEGER DEFAULT 1, created_at TEXT DEFAULT '2026-01-01')");

    $pdo->exec("CREATE TABLE group_members (
        id INTEGER PRIMARY KEY AUTOINCREMENT, group_id INTEGER, user_id INTEGER,
        nickname TEXT, email TEXT, participation_type TEXT, participation_value REAL,
        is_active INTEGER DEFAULT 1, joined_at TEXT DEFAULT '2026-01-01')");

    $pdo->exec("CREATE TABLE group_purchases (
        id INTEGER PRIMARY KEY AUTOINCREMENT, group_id INTEGER, member_id INTEGER, user_id INTEGER,
        amount REAL, description TEXT, image_path TEXT, purchase_date TEXT,
        created_at TEXT DEFAULT '2026-01-01')");

    $pdo->exec("CREATE TABLE purchase_exclusions (purchase_id INTEGER, member_id INTEGER,
        PRIMARY KEY (purchase_id, member_id))");

    $pdo->exec("CREATE TABLE shopping_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT, group_id INTEGER, title TEXT, quantity TEXT,
        notes TEXT, status TEXT DEFAULT 'needed', assigned_member_id INTEGER, purchase_id INTEGER,
        created_by INTEGER, sort_order INTEGER DEFAULT 0, created_at TEXT DEFAULT '2026-01-01')");

    $pdo->exec("CREATE TABLE settlements (
        id INTEGER PRIMARY KEY AUTOINCREMENT, group_id INTEGER, from_member_id INTEGER,
        to_member_id INTEGER, amount REAL, note TEXT, created_by INTEGER,
        created_at TEXT DEFAULT '2026-01-01')");

    $pdo->exec("CREATE TABLE group_invitations (
        id INTEGER PRIMARY KEY AUTOINCREMENT, group_id INTEGER, email TEXT, nickname TEXT,
        participation_type TEXT, participation_value REAL, token TEXT, invited_by INTEGER,
        status TEXT DEFAULT 'pending', created_at TEXT DEFAULT '2026-01-01', responded_at TEXT)");

    $pdo->exec("CREATE TABLE notification_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, type TEXT, data TEXT,
        status TEXT, created_at TEXT, processed_at TEXT)");

    // משתמשים: 1 = מנהל, 2 = חבר, 3 = חבר
    $pdo->exec("INSERT INTO users (id, name, email) VALUES
        (1, 'דנה', 'dana@example.com'),
        (2, 'יוסי', 'yossi@example.com'),
        (3, 'רותי', 'ruti@example.com')");

    $pdo->exec("INSERT INTO purchase_groups (id, name, owner_id) VALUES (1, 'פסח', 1)");

    // חברים: 1 = דנה (מנהלת), 2 = יוסי, 3 = רותי
    $pdo->exec("INSERT INTO group_members (id, group_id, user_id, nickname, email, participation_type, participation_value) VALUES
        (1, 1, 1, 'דנה', 'dana@example.com', 'percentage', 50),
        (2, 1, 2, 'יוסי', 'yossi@example.com', 'percentage', 25),
        (3, 1, 3, 'רותי', 'ruti@example.com', 'percentage', 25)");

    return $pdo;
}

$_SERVER['HTTP_HOST']   = 'example.com';
$_SERVER['SCRIPT_NAME'] = '/family/group.php';
$_SESSION['name']       = 'דנה';

// ------------------------------------------------------------
echo "1. הרשאות\n";
// ------------------------------------------------------------
$pdo = makeDb();

$r = run($pdo, 'addMember', ['email' => 'x@y.com', 'nickname' => 'X', 'participation_value' => 10], 2, false, 2);
check('חבר רגיל לא יכול להוסיף משתתף', $r['success'] === false);

$r = run($pdo, 'splitEqually', [], 2, false, 2);
check('חבר רגיל לא יכול לחלק שווה', $r['success'] === false);

$r = run($pdo, 'closeEvent', [], 2, false, 2);
check('חבר רגיל לא יכול לסגור אירוע', $r['success'] === false);

$r = run($pdo, 'nonsenseAction', [], 1, true, 1);
check('פעולה לא מוכרת נדחית', $r['success'] === false);

// ------------------------------------------------------------
echo "\n2. חלוקה שווה\n";
// ------------------------------------------------------------
$r = run($pdo, 'splitEqually', [], 1, true, 1);
check('החלוקה בוצעה', $r['success'] === true, $r['message'] ?? '');

$values = $pdo->query("SELECT participation_value FROM group_members WHERE group_id = 1 ORDER BY id")
    ->fetchAll(PDO::FETCH_COLUMN);
check('סך האחוזים בדיוק 100', abs(array_sum($values) - 100) < 0.001, 'סה"כ ' . array_sum($values));
check('כולם עברו לאחוזים', count(array_unique(
    $pdo->query("SELECT participation_type FROM group_members WHERE group_id = 1")->fetchAll(PDO::FETCH_COLUMN)
)) === 1);

// ------------------------------------------------------------
echo "\n3. הוספת קנייה עם החרגה\n";
// ------------------------------------------------------------
$r = run($pdo, 'addPurchase', [
    'member_id'    => 1,
    'amount'       => 300,
    'description'  => 'בשר',
    'excluded_ids' => [3],
], 1, true, 1);
check('הקנייה נוספה', $r['success'] === true, $r['message'] ?? '');
check('הוחזר מזהה קנייה', isset($r['purchase_id']) && $r['purchase_id'] > 0);

$excluded = $pdo->query("SELECT member_id FROM purchase_exclusions WHERE purchase_id = 1")
    ->fetchAll(PDO::FETCH_COLUMN);
check('ההחרגה נשמרה', $excluded == [3], json_encode($excluded));

$body = $pdo->query("SELECT * FROM group_purchases WHERE id = 1")->fetch();
check('הסכום נשמר', abs($body['amount'] - 300) < 0.001);
check('תאריך הקנייה נקבע', !empty($body['purchase_date']));

// ------------------------------------------------------------
echo "\n4. תקינות קלט בקניות\n";
// ------------------------------------------------------------
$r = run($pdo, 'addPurchase', ['member_id' => 1, 'amount' => 0], 1, true, 1);
check('סכום אפס נדחה', $r['success'] === false);

$r = run($pdo, 'addPurchase', ['member_id' => 1, 'amount' => -50], 1, true, 1);
check('סכום שלילי נדחה', $r['success'] === false);

$r = run($pdo, 'addPurchase', ['member_id' => 999, 'amount' => 50], 1, true, 1);
check('משתתף לא קיים נדחה', $r['success'] === false);

$r = run($pdo, 'addPurchase', [
    'member_id' => 1, 'amount' => 50, 'excluded_ids' => [1, 2, 3],
], 1, true, 1);
check('החרגת כולם נדחית', $r['success'] === false, $r['message'] ?? '');

$r = run($pdo, 'addPurchase', [
    'member_id' => 1, 'amount' => 50, 'excluded_ids' => [999],
], 1, true, 1);
check('החרגה של מזהה זר מסוננת', $r['success'] === true);
$count = $pdo->query("SELECT COUNT(*) FROM purchase_exclusions WHERE purchase_id = " . $r['purchase_id'])
    ->fetchColumn();
check('לא נשמרה החרגה מזויפת', (int)$count === 0);

// חבר רגיל רושם קנייה - תמיד על שמו
$r = run($pdo, 'addPurchase', ['member_id' => 1, 'amount' => 80], 2, false, 2);
check('קניית חבר רגיל נוספה', $r['success'] === true);
$owner = $pdo->query("SELECT member_id FROM group_purchases WHERE id = " . $r['purchase_id'])->fetchColumn();
check('נרשמה על שמו ולא על מי שביקש', (int)$owner === 2, "member_id=$owner");

// ------------------------------------------------------------
echo "\n5. עריכה ומחיקה של קנייה\n";
// ------------------------------------------------------------
$r = run($pdo, 'updatePurchase', [
    'purchase_id' => 1, 'amount' => 400, 'excluded_ids' => [2],
], 1, true, 1);
check('העריכה בוצעה', $r['success'] === true, $r['message'] ?? '');

$amount = $pdo->query("SELECT amount FROM group_purchases WHERE id = 1")->fetchColumn();
check('הסכום עודכן', abs($amount - 400) < 0.001);

$excluded = $pdo->query("SELECT member_id FROM purchase_exclusions WHERE purchase_id = 1")
    ->fetchAll(PDO::FETCH_COLUMN);
check('ההחרגות הוחלפו ולא נערמו', $excluded == [2], json_encode($excluded));

$r = run($pdo, 'updatePurchase', ['purchase_id' => 1, 'amount' => 10], 3, false, 3);
check('חבר זר לא יכול לערוך קנייה של אחר', $r['success'] === false);

$r = run($pdo, 'deletePurchase', ['purchase_id' => 1], 3, false, 3);
check('חבר זר לא יכול למחוק קנייה של אחר', $r['success'] === false);

$r = run($pdo, 'deletePurchase', ['purchase_id' => 1], 1, true, 1);
check('מנהל מוחק קנייה', $r['success'] === true);
$left = $pdo->query("SELECT COUNT(*) FROM purchase_exclusions WHERE purchase_id = 1")->fetchColumn();
check('ההחרגות נוקו יחד עם הקנייה', (int)$left === 0);

// ------------------------------------------------------------
echo "\n6. רשימת קניות\n";
// ------------------------------------------------------------
$r = run($pdo, 'addItem', ['title' => 'פיתות', 'quantity' => '3 חבילות'], 2, false, 2);
check('פריט נוסף', $r['success'] === true);
$itemId = $r['item_id'];

$r = run($pdo, 'addItem', ['title' => ''], 2, false, 2);
check('פריט בלי שם נדחה', $r['success'] === false);

$r = run($pdo, 'setItemStatus', ['item_id' => $itemId, 'status' => 'claimed'], 3, false, 3);
check('"אני אביא" עובד', $r['success'] === true);
$item = $pdo->query("SELECT * FROM shopping_items WHERE id = $itemId")->fetch();
check('הפריט שויך למי שלחץ', (int)$item['assigned_member_id'] === 3);
check('הסטטוס התעדכן', $item['status'] === 'claimed');

$r = run($pdo, 'setItemStatus', ['item_id' => $itemId, 'status' => 'invalid'], 3, false, 3);
check('סטטוס לא חוקי נדחה', $r['success'] === false);

// קנייה מתוך פריט מסמנת אותו כנקנה
$r = run($pdo, 'addPurchase', ['member_id' => 3, 'amount' => 25, 'item_id' => $itemId], 3, false, 3);
check('קנייה מתוך פריט נוספה', $r['success'] === true);
$item = $pdo->query("SELECT * FROM shopping_items WHERE id = $itemId")->fetch();
check('הפריט סומן כנקנה', $item['status'] === 'bought');
check('הפריט קושר לקנייה', (int)$item['purchase_id'] === (int)$r['purchase_id']);

// מחיקת הקנייה מחזירה את הפריט לרשימה
run($pdo, 'deletePurchase', ['purchase_id' => $r['purchase_id']], 1, true, 1);
$item = $pdo->query("SELECT * FROM shopping_items WHERE id = $itemId")->fetch();
check('הפריט חזר להיות "צריך"', $item['status'] === 'needed');
check('הקישור לקנייה נוקה', $item['purchase_id'] === null);

$r = run($pdo, 'deleteItem', ['item_id' => $itemId], 3, false, 3);
check('מי שלא יצר את הפריט לא מוחק אותו', $r['success'] === false);

$r = run($pdo, 'deleteItem', ['item_id' => $itemId], 2, false, 2);
check('היוצר מוחק את הפריט שלו', $r['success'] === true);

// ------------------------------------------------------------
echo "\n7. התחשבנות\n";
// ------------------------------------------------------------
$r = run($pdo, 'addSettlement', ['from_member_id' => 2, 'to_member_id' => 1, 'amount' => 100], 2, false, 2);
check('צד להעברה יכול לרשום אותה', $r['success'] === true, $r['message'] ?? '');
$settlementId = $r['settlement_id'];

$r = run($pdo, 'addSettlement', ['from_member_id' => 2, 'to_member_id' => 1, 'amount' => 50], 3, false, 3);
check('צד שלישי לא רושם העברה של אחרים', $r['success'] === false);

$r = run($pdo, 'addSettlement', ['from_member_id' => 2, 'to_member_id' => 2, 'amount' => 50], 1, true, 1);
check('העברה מאדם לעצמו נדחית', $r['success'] === false);

$r = run($pdo, 'addSettlement', ['from_member_id' => 2, 'to_member_id' => 1, 'amount' => 0], 1, true, 1);
check('סכום אפס נדחה', $r['success'] === false);

$r = run($pdo, 'addSettlement', ['from_member_id' => 2, 'to_member_id' => 99, 'amount' => 10], 1, true, 1);
check('משתתף לא קיים נדחה', $r['success'] === false);

$r = run($pdo, 'deleteSettlement', ['settlement_id' => $settlementId], 3, false, 3);
check('צד שלישי לא מבטל התחשבנות', $r['success'] === false);

$r = run($pdo, 'deleteSettlement', ['settlement_id' => $settlementId], 1, true, 1);
check('מנהל מבטל התחשבנות', $r['success'] === true);

// ------------------------------------------------------------
echo "\n8. הזמנות\n";
// ------------------------------------------------------------
$r = run($pdo, 'addMember', [
    'email' => 'chen@example.com', 'nickname' => 'חן',
    'participation_type' => 'fixed', 'participation_value' => 200,
], 1, true, 1);
check('הזמנה נוצרה', $r['success'] === true, $r['message'] ?? '');
check('הוחזר קישור הצטרפות', !empty($r['invitation_link']) && strpos($r['invitation_link'], 'join.php?token=') !== false,
    $r['invitation_link'] ?? '');
check('סומן שהמשתמש לא רשום', $r['is_registered'] === false);

$token = $pdo->query("SELECT token FROM group_invitations WHERE email = 'chen@example.com'")->fetchColumn();
check('נשמר טוקן באורך תקין', strlen($token) === 64, 'אורך ' . strlen($token));

$r = run($pdo, 'addMember', [
    'email' => 'chen@example.com', 'nickname' => 'חן', 'participation_value' => 10,
], 1, true, 1);
check('הזמנה כפולה נדחית', $r['success'] === false);

$r = run($pdo, 'addMember', ['email' => 'not-an-email', 'nickname' => 'X', 'participation_value' => 10], 1, true, 1);
check('אימייל לא תקין נדחה', $r['success'] === false);

$r = run($pdo, 'addMember', ['email' => 'a@b.com', 'nickname' => 'A', 'participation_value' => 90], 1, true, 1);
check('חריגה מ-100% נדחית', $r['success'] === false, $r['message'] ?? '');

$r = run($pdo, 'addMember', [
    'email' => 'yossi@example.com', 'nickname' => 'יוסי', 'participation_value' => 5,
], 1, true, 1);
check('הזמנה לחבר פעיל נדחית', $r['success'] === false);

// משתמש רשום -> נכנס לתור ההתראות.
// משתמשים בסכום קבוע כי מכסת האחוזים כבר מלאה אחרי החלוקה השווה.
$pdo->exec("INSERT INTO users (id, name, email) VALUES (9, 'נעם', 'noam@example.com')");
$r = run($pdo, 'addMember', [
    'email' => 'noam@example.com', 'nickname' => 'נעם',
    'participation_type' => 'fixed', 'participation_value' => 150,
], 1, true, 1);
check('הזמנה למשתמש רשום מסומנת ככזו', $r['is_registered'] === true);
$queued = $pdo->query("SELECT COUNT(*) FROM notification_queue WHERE type = 'invitation'")->fetchColumn();
check('התראה נכנסה לתור', (int)$queued === 1, "בתור: $queued");

// הצרכן שולף לפי העמודה user_id ומציג את title ו-body מתוך data.
// בלי שלושת אלה ההתראה יושבת בתור ולא מוצגת לעולם.
$row  = $pdo->query("SELECT * FROM notification_queue WHERE type = 'invitation'")->fetch(PDO::FETCH_ASSOC);
$data = json_decode($row['data'], true);
check('ההתראה משויכת לנמען בעמודה user_id', (int)$row['user_id'] === 9, "user_id: {$row['user_id']}");
check('להתראה יש כותרת', !empty($data['title']));
check('להתראה יש גוף שמזכיר את שם הקבוצה', !empty($data['body']) && mb_strpos($data['body'], 'פסח') !== false);

// ------------------------------------------------------------
echo "\n8ב. התראה על קנייה חדשה\n";
// ------------------------------------------------------------
$pdoP = makeDb();
$pdoP->exec("DELETE FROM notification_queue");
run($pdoP, 'addPurchase', ['member_id' => 1, 'amount' => 90, 'description' => 'ירקות'], 1, true, 1);
$rows = $pdoP->query("SELECT * FROM notification_queue WHERE type = 'purchase'")->fetchAll(PDO::FETCH_ASSOC);
// המשתתפים הם 1, 2 ו-3, והרוכש עצמו לא מקבל התראה
check('נשלחה התראה לכל שאר המשתתפים', count($rows) === 2, 'נשלחו: ' . count($rows));
check('הרוכש עצמו לא קיבל התראה', !in_array('1', array_column($rows, 'user_id'), false));
$pdata = json_decode($rows[0]['data'] ?? '{}', true);
check('גוף ההתראה כולל את הסכום', !empty($pdata['body']) && mb_strpos($pdata['body'], '90') !== false);

// ------------------------------------------------------------
echo "\n9. הסרת משתתף\n";
// ------------------------------------------------------------
$pdo2 = makeDb();
run($pdo2, 'addPurchase', ['member_id' => 2, 'amount' => 100], 1, true, 1);

$r = run($pdo2, 'removeMember', ['member_id' => 2], 1, true, 1);
check('לא ניתן להסיר משתתף עם קניות', $r['success'] === false);

$r = run($pdo2, 'removeMember', ['member_id' => 1], 1, true, 1);
check('לא ניתן להסיר את המנהל', $r['success'] === false, $r['message'] ?? '');

$r = run($pdo2, 'removeMember', ['member_id' => 3], 1, true, 1);
check('משתתף בלי קניות מוסר', $r['success'] === true, $r['message'] ?? '');
$active = $pdo2->query("SELECT is_active FROM group_members WHERE id = 3")->fetchColumn();
check('ההסרה היא רכה (is_active=0)', (int)$active === 0);

// ------------------------------------------------------------
echo "\n10. אירוע סגור\n";
// ------------------------------------------------------------
$pdo3 = makeDb();

$r = run($pdo3, 'updateEvent', [
    'name' => 'פסח 2026', 'event_date' => '2026-04-01', 'event_location' => 'אצל סבתא',
], 1, true, 1);
check('פרטי האירוע עודכנו', $r['success'] === true, $r['message'] ?? '');
$group = $pdo3->query("SELECT * FROM purchase_groups WHERE id = 1")->fetch();
check('התאריך נשמר', $group['event_date'] === '2026-04-01');
check('המיקום נשמר', $group['event_location'] === 'אצל סבתא');

$r = run($pdo3, 'updateEvent', ['name' => 'X', 'event_date' => '01/04/2026'], 1, true, 1);
check('תאריך בפורמט שגוי נדחה', $r['success'] === false);

$r = run($pdo3, 'updateEvent', ['name' => ''], 1, true, 1);
check('שם ריק נדחה', $r['success'] === false);

$r = run($pdo3, 'closeEvent', [], 1, true, 1);
check('האירוע נסגר', $r['success'] === true);

// מכאן והלאה מדמים status=closed
$r = run($pdo3, 'addPurchase', ['member_id' => 1, 'amount' => 50], 1, true, 1, 'closed');
check('אי אפשר להוסיף קנייה לאירוע סגור', $r['success'] === false);

$r = run($pdo3, 'addItem', ['title' => 'עוד משהו'], 1, true, 1, 'closed');
check('אי אפשר להוסיף פריט לאירוע סגור', $r['success'] === false);

$r = run($pdo3, 'splitEqually', [], 1, true, 1, 'closed');
check('אי אפשר לשנות חלוקה באירוע סגור', $r['success'] === false);

$r = run($pdo3, 'addSettlement', ['from_member_id' => 2, 'to_member_id' => 1, 'amount' => 50], 1, true, 1, 'closed');
check('התחשבנות דווקא כן מותרת באירוע סגור', $r['success'] === true, $r['message'] ?? '');

$r = run($pdo3, 'reopenEvent', [], 1, true, 1, 'closed');
check('האירוע נפתח מחדש', $r['success'] === true);
$status = $pdo3->query("SELECT status FROM purchase_groups WHERE id = 1")->fetchColumn();
check('הסטטוס חזר לפעיל', $status === 'active');

// ------------------------------------------------------------
echo "\n11. עבודה לפני שהמיגרציה הורצה\n";
// ------------------------------------------------------------
// מדמים מסד ישן: בלי הטבלאות החדשות
$old = new TestPdo('sqlite::memory:');
$old->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$old->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$old->sqliteCreateFunction('NOW', function () { return date('Y-m-d H:i:s'); }, 0);

$old->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)");
$old->exec("CREATE TABLE purchase_groups (id INTEGER PRIMARY KEY, name TEXT, description TEXT,
    owner_id INTEGER, is_active INTEGER DEFAULT 1)");
$old->exec("CREATE TABLE group_members (id INTEGER PRIMARY KEY AUTOINCREMENT, group_id INTEGER,
    user_id INTEGER, nickname TEXT, email TEXT, participation_type TEXT, participation_value REAL,
    is_active INTEGER DEFAULT 1, joined_at TEXT DEFAULT '2026-01-01')");
$old->exec("CREATE TABLE group_purchases (id INTEGER PRIMARY KEY AUTOINCREMENT, group_id INTEGER,
    member_id INTEGER, user_id INTEGER, amount REAL, description TEXT, image_path TEXT,
    purchase_date TEXT, created_at TEXT DEFAULT '2026-01-01')");
$old->exec("CREATE TABLE group_invitations (id INTEGER PRIMARY KEY AUTOINCREMENT, group_id INTEGER,
    email TEXT, nickname TEXT, participation_type TEXT, participation_value REAL, token TEXT,
    invited_by INTEGER, status TEXT DEFAULT 'pending', created_at TEXT, responded_at TEXT)");
$old->exec("INSERT INTO purchase_groups (id, name, owner_id) VALUES (1, 'פסח', 1)");
$old->exec("INSERT INTO group_members (id, group_id, user_id, nickname, email, participation_type, participation_value)
    VALUES (1, 1, 1, 'דנה', 'dana@example.com', 'percentage', 100)");

// featuresReady = false בכל הקריאות כאן
$_POST = ['action' => 'addPurchase', 'member_id' => 1, 'amount' => 120, 'excluded_ids' => [1]];
ob_start();
handleGroupActions($old, 1, 1, true, 1, 'active', false);
$r = json_decode(ob_get_clean(), true);
check('קנייה עובדת גם בלי המיגרציה', $r['success'] === true, $r['message'] ?? '');

$count = $old->query("SELECT COUNT(*) FROM group_purchases")->fetchColumn();
check('הקנייה באמת נשמרה', (int)$count > 0);

foreach (['addItem' => ['title' => 'פיתות'], 'closeEvent' => [], 'addSettlement' => [
    'from_member_id' => 1, 'to_member_id' => 1, 'amount' => 5]] as $action => $post) {
    $_POST = array_merge(['action' => $action], $post);
    ob_start();
    handleGroupActions($old, 1, 1, true, 1, 'active', false);
    $r = json_decode(ob_get_clean(), true);
    check("$action נחסם עם הודעה ברורה",
        $r['success'] === false && strpos($r['message'] ?? '', 'מסד הנתונים') !== false,
        $r['message'] ?? '');
}

// ------------------------------------------------------------
echo "\n12. חלוקה לפי נפשות\n";
// ------------------------------------------------------------
$pdoN = makeDb();

$r = run($pdoN, 'editMember', [
    'member_id' => 2, 'participation_type' => 'shares', 'participation_value' => 4,
], 1, true, 1);
check('נפשות מתקבלות כסוג השתתפות', $r['success'] === true, $r['message'] ?? '');

$row = $pdoN->query("SELECT participation_type, participation_value FROM group_members WHERE id = 2")->fetch();
check('הסוג נשמר', $row['participation_type'] === 'shares', $row['participation_type']);
check('הערך נשמר', (float)$row['participation_value'] === 4.0);

// חצי נפש היא לא דבר קיים
$r = run($pdoN, 'editMember', [
    'member_id' => 3, 'participation_type' => 'shares', 'participation_value' => 2.6,
], 1, true, 1);
$row = $pdoN->query("SELECT participation_value FROM group_members WHERE id = 3")->fetch();
check('ערך שבור מעוגל למספר שלם', (float)$row['participation_value'] === 3.0, $row['participation_value']);

$r = run($pdoN, 'editMember', [
    'member_id' => 2, 'participation_type' => 'shares', 'participation_value' => 0,
], 1, true, 1);
check('אפס נפשות נדחה', $r['success'] === false);
check('ההודעה מדברת על נפשות', mb_strpos($r['message'] ?? '', 'נפשות') !== false, $r['message'] ?? '');

// תקרת ה-100% לא חלה על נפשות: הן סופגות את מה שנשאר
$r = run($pdoN, 'editMember', [
    'member_id' => 2, 'participation_type' => 'shares', 'participation_value' => 99,
], 1, true, 1);
check('נפשות אינן כפופות לתקרת ה-100%', $r['success'] === true, $r['message'] ?? '');

// סוג לא מוכר נופל בחזרה לאחוזים ולא נכתב כפי שהוא
$r = run($pdoN, 'editMember', [
    'member_id' => 3, 'participation_type' => 'zzz', 'participation_value' => 10,
], 1, true, 1);
$row = $pdoN->query("SELECT participation_type FROM group_members WHERE id = 3")->fetch();
check('סוג לא מוכר לא נכנס למסד', $row['participation_type'] === 'percentage', $row['participation_type']);

// ------------------------------------------------------------
echo "\n13. שיטת חלוקה לכל הקבוצה\n";
// ------------------------------------------------------------
$pdoS = makeDb();

$r = run($pdoS, 'setSplitMode', ['mode' => 'shares'], 1, true, 1);
check('מעבר לנפשות מצליח', $r['success'] === true, $r['message'] ?? '');
$types = $pdoS->query("SELECT DISTINCT participation_type FROM group_members WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
check('כולם עברו לנפשות', $types === ['shares'], implode(',', $types));
$vals = $pdoS->query("SELECT participation_value FROM group_members WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
check('מי שלא היה בנפשות מתחיל מנפש אחת', (float)$vals[0] === 1.0, $vals[0]);

// מספר נפשות קיים נשמר במעבר חוזר
run($pdoS, 'editMember', ['member_id' => 2, 'participation_type' => 'shares', 'participation_value' => 5], 1, true, 1);
run($pdoS, 'setSplitMode', ['mode' => 'shares'], 1, true, 1);
$kept = $pdoS->query("SELECT participation_value FROM group_members WHERE id = 2")->fetchColumn();
check('מספר נפשות קיים נשמר', (float)$kept === 5.0, $kept);

$r = run($pdoS, 'setSplitMode', ['mode' => 'shares_rate', 'share_rate' => 75], 1, true, 1);
check('תעריף לנפש נקבע', $r['success'] === true, $r['message'] ?? '');
$rate = $pdoS->query("SELECT share_rate FROM purchase_groups WHERE id = 1")->fetchColumn();
check('התעריף נשמר בקבוצה', (float)$rate === 75.0, $rate);

$r = run($pdoS, 'setSplitMode', ['mode' => 'shares_rate', 'share_rate' => 0], 1, true, 1);
check('תעריף אפס נדחה', $r['success'] === false);

$r = run($pdoS, 'setSplitMode', ['mode' => 'percentage'], 1, true, 1);
check('חזרה לאחוזים מצליחה', $r['success'] === true, $r['message'] ?? '');
$sum = $pdoS->query("SELECT SUM(participation_value) FROM group_members WHERE is_active = 1")->fetchColumn();
check('האחוזים מסתכמים ל-100', abs((float)$sum - 100) < 0.01, $sum);
$rate = $pdoS->query("SELECT share_rate FROM purchase_groups WHERE id = 1")->fetchColumn();
check('התעריף התאפס', $rate === null, var_export($rate, true));

$r = run($pdoS, 'setSplitMode', ['mode' => 'zzz'], 1, true, 1);
check('שיטה לא מוכרת נדחית', $r['success'] === false);

// אין אף משתתף בנפשות, ולכן תעריף לנפש חסר משמעות
$r = run($pdoS, 'setSplitMode', ['mode' => 'shares_rate', 'share_rate' => 50], 1, true, 1);
check('תעריף בלי משתתפי נפשות נדחה', $r['success'] === false, $r['message'] ?? '');

$r = run($pdoS, 'setSplitMode', ['mode' => 'shares'], 2, false, 2);
check('משתתף רגיל לא משנה שיטת חלוקה', $r['success'] === false);

// ------------------------------------------------------------
echo "\n14. הוגנות החלוקה השווה\n";
// ------------------------------------------------------------
// 100 חלקי 7 אינו עגול, ולכן מישהו תמיד מקבל קצת יותר.
// השאלה היא כמה: הגרסה הקודמת זרקה את כל השארית על האחרון.
foreach ([2, 3, 6, 7, 9, 13, 40] as $count) {
    $shares = equalPercentageShares($count);
    $sum    = array_sum($shares);
    $spread = max($shares) - min($shares);

    check("$count משתתפים: הסכום בדיוק 100", abs($sum - 100) < 0.0001, "התקבל $sum");
    check("$count משתתפים: הפער לא עולה על 0.01", $spread <= 0.0101, "פער $spread");
}

// והמסלול המלא, מקצה לקצה: שבעה משתתפים בחלוקה שווה
$pdoF = makeDb();
$pdoF->exec("INSERT INTO group_members (group_id, user_id, nickname, email, participation_type, participation_value)
    VALUES (1,4,'ד','d@e.com','percentage',0), (1,5,'ה','e@e.com','percentage',0),
           (1,6,'ו','f@e.com','percentage',0), (1,7,'ז','g@e.com','percentage',0)");
run($pdoF, 'splitEqually', [], 1, true, 1);
$values = $pdoF->query("SELECT participation_value FROM group_members WHERE is_active = 1")
    ->fetchAll(PDO::FETCH_COLUMN);
$values = array_map('floatval', $values);
check('שבעה משתתפים מסתכמים ל-100', abs(array_sum($values) - 100) < 0.0001, array_sum($values));
check('אף אחד לא נושא את כל השארית', (max($values) - min($values)) <= 0.0101,
    'פער ' . (max($values) - min($values)));

// ------------------------------------------------------------
echo "\n15. העברת בעלות על קנייה\n";
// ------------------------------------------------------------
$pdoO = makeDb();
run($pdoO, 'addPurchase', ['member_id' => 1, 'amount' => 250, 'description' => 'דגים'], 1, true, 1);
$pid = (int)$pdoO->query("SELECT MAX(id) FROM group_purchases")->fetchColumn();

// המנהל מעביר את הקנייה למשתתף אחר
$r = run($pdoO, 'updatePurchase', [
    'purchase_id' => $pid, 'amount' => 250, 'description' => 'דגים', 'member_id' => 3,
], 1, true, 1);
check('המנהל מעביר בעלות', $r['success'] === true, $r['message'] ?? '');
$owner = (int)$pdoO->query("SELECT member_id FROM group_purchases WHERE id = $pid")->fetchColumn();
check('הבעלות עברה בפועל', $owner === 3, "member_id: $owner");

// משתתף שאינו מוחרג נשאר מוחרג - הבעלות לא נוגעת בהחרגות
$r = run($pdoO, 'updatePurchase', [
    'purchase_id' => $pid, 'amount' => 250, 'member_id' => 99,
], 1, true, 1);
check('משתתף שאינו בקבוצה נדחה', $r['success'] === false);
$owner = (int)$pdoO->query("SELECT member_id FROM group_purchases WHERE id = $pid")->fetchColumn();
check('הבעלות לא השתנתה בעקבות ניסיון פסול', $owner === 3, "member_id: $owner");

// חבר רגיל עורך קנייה שרשומה עליו, אך אינו יכול להעביר בעלות
$pdoO2 = makeDb();
run($pdoO2, 'addPurchase', ['member_id' => 2, 'amount' => 80], 2, false, 2);
$pid2 = (int)$pdoO2->query("SELECT MAX(id) FROM group_purchases")->fetchColumn();
$r = run($pdoO2, 'updatePurchase', [
    'purchase_id' => $pid2, 'amount' => 90, 'member_id' => 3,
], 2, false, 2);
check('חבר רגיל עורך את הקנייה שלו', $r['success'] === true, $r['message'] ?? '');
$owner2 = (int)$pdoO2->query("SELECT member_id FROM group_purchases WHERE id = $pid2")->fetchColumn();
check('אך הבעלות נשארה שלו', $owner2 === 2, "member_id: $owner2");
$amount2 = (float)$pdoO2->query("SELECT amount FROM group_purchases WHERE id = $pid2")->fetchColumn();
check('והסכום כן התעדכן', $amount2 === 90.0, $amount2);

// ------------------------------------------------------------
echo "\n16. מחיקת אירוע\n";
// ------------------------------------------------------------
$pdoD = makeDb();
run($pdoD, 'addPurchase', ['member_id' => 1, 'amount' => 120, 'excluded_ids' => [3]], 1, true, 1);
run($pdoD, 'addItem', ['title' => 'לחם'], 1, true, 1);
run($pdoD, 'addSettlement', ['from_member_id' => 2, 'to_member_id' => 1, 'amount' => 40], 1, true, 1);

// מחיקה רגילה
$r = softDeleteGroup($pdoD, 1, 1);
check('המנהל מוחק את האירוע', $r['ok'] === true, $r['message']);
$active = (int)$pdoD->query("SELECT is_active FROM purchase_groups WHERE id = 1")->fetchColumn();
check('האירוע סומן כלא פעיל', $active === 0);
$purchases = (int)$pdoD->query("SELECT COUNT(*) FROM group_purchases WHERE group_id = 1")->fetchColumn();
check('הנתונים נשמרו במחיקה רגילה', $purchases === 1, "קניות: $purchases");

$r = softDeleteGroup($pdoD, 1, 1);
check('מחיקה חוזרת נדחית', $r['ok'] === false);

// רק הבעלים
$r = softDeleteGroup($pdoD, 1, 2);
check('משתתף רגיל לא מוחק אירוע', $r['ok'] === false);

// שחזור
$r = restoreGroup($pdoD, 1, 1);
check('שחזור מצליח', $r['ok'] === true, $r['message']);
$active = (int)$pdoD->query("SELECT is_active FROM purchase_groups WHERE id = 1")->fetchColumn();
check('האירוע חזר להיות פעיל', $active === 1);

// מחיקה לצמיתות: שם שגוי נדחה
$r = purgeGroup($pdoD, 1, 1, 'שם אחר לגמרי');
check('שם שגוי מבטל את המחיקה', $r['ok'] === false, $r['message']);
$stillThere = (int)$pdoD->query("SELECT COUNT(*) FROM purchase_groups WHERE id = 1")->fetchColumn();
check('האירוע עדיין קיים', $stillThere === 1);

// לא הבעלים
$r = purgeGroup($pdoD, 1, 2, 'פסח');
check('משתתף רגיל לא מוחק לצמיתות', $r['ok'] === false);

// והמחיקה האמיתית
$r = purgeGroup($pdoD, 1, 1, 'פסח');
check('מחיקה לצמיתות מצליחה עם השם הנכון', $r['ok'] === true, $r['message']);

foreach ([
    'purchase_groups'     => 'האירוע',
    'group_members'       => 'המשתתפים',
    'group_purchases'     => 'הקניות',
    'shopping_items'      => 'רשימת הקניות',
    'settlements'         => 'ההתחשבנויות',
    'purchase_exclusions' => 'ההחרגות',
] as $table => $label) {
    $where = $table === 'purchase_groups' ? 'id = 1'
        : ($table === 'purchase_exclusions' ? '1=1' : 'group_id = 1');
    $left  = (int)$pdoD->query("SELECT COUNT(*) FROM $table WHERE $where")->fetchColumn();
    check("$label נמחקו", $left === 0, "נותרו: $left");
}

// ------------------------------------------------------------
echo "\n17. מחיקה בהרשאת ניהול\n";
// ------------------------------------------------------------
// משתמש 9 אינו בעל האירוע, אך פועל בהרשאת ניהול
$pdoA = makeDb();
run($pdoA, 'addPurchase', ['member_id' => 1, 'amount' => 200], 1, true, 1);

$r = softDeleteGroup($pdoA, 1, 9, false);
check('בלי הרשאת ניהול - נדחה', $r['ok'] === false);

$r = softDeleteGroup($pdoA, 1, 9, true);
check('עם הרשאת ניהול - מוחק אירוע של אחר', $r['ok'] === true, $r['message']);
$active = (int)$pdoA->query("SELECT is_active FROM purchase_groups WHERE id = 1")->fetchColumn();
check('האירוע סומן כלא פעיל', $active === 0);

$r = restoreGroup($pdoA, 1, 9, true);
check('מנהל המערכת משחזר', $r['ok'] === true, $r['message']);

$r = purgeGroup($pdoA, 1, 9, 'שם שגוי', true);
check('גם למנהל המערכת השם חייב להתאים', $r['ok'] === false);
$still = (int)$pdoA->query("SELECT COUNT(*) FROM purchase_groups WHERE id = 1")->fetchColumn();
check('האירוע שרד את הניסיון', $still === 1);

$r = purgeGroup($pdoA, 1, 9, 'פסח', true);
check('מחיקה סופית בהרשאת ניהול', $r['ok'] === true, $r['message']);
$gone = (int)$pdoA->query("SELECT COUNT(*) FROM purchase_groups WHERE id = 1")->fetchColumn();
check('האירוע נמחק אף שהמוחק אינו הבעלים', $gone === 0);
$members = (int)$pdoA->query("SELECT COUNT(*) FROM group_members WHERE group_id = 1")->fetchColumn();
check('וגם המשתתפים ירדו', $members === 0);

echo "\n" . str_repeat('=', 55) . "\n";
echo "עבר: $pass | נכשל: $fail\n";
exit($fail > 0 ? 1 : 0);
