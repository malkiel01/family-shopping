<?php
/**
 * ייצוא נתונים
 * includes/admin_export.php
 *
 * שני שימושים, ושניהם אמיתיים: להוציא נתונים לגיליון כדי לבדוק
 * משהו שהממשק לא מציג, ולהחזיק עותק של המערכת מחוץ לשרת.
 *
 * שני כללים שחלים על כל ייצוא:
 *
 *   1. **שום סוד לא יוצא.** גיבוב הסיסמה, טוקני ההזמנה, טוקני
 *      איפוס הסיסמה ומפתחות ה-Push אינם נכללים באף מערך נתונים.
 *      קובץ שיורד מהדפדפן מגיע למקומות שהשרת לא שולט בהם - מייל,
 *      דיסק משותף, גיבוי ענן - ואסור שאפשר יהיה להתחזות בעזרתו.
 *      זה גם ההבדל מול המסך: שם קישור ההזמנה מוצג, כי הוא נשאר
 *      בדפדפן של המנהל ונועד להעתקה חד-פעמית.
 *
 *   2. **הכל קריא בעברית ובאקסל.** CSV נפתח באקסל עם BOM, אחרת
 *      העברית מוצגת כג'יבריש; ובלי CRLF השורות נדבקות.
 */

require_once __DIR__ . '/admin.php';

/**
 * מערכי הנתונים הזמינים לייצוא.
 *
 * לכל אחד: כותרת, שם קובץ, ותיאור קצר. השאילתה בוחרת עמודות
 * במפורש ולא *, כדי שעמודה רגישה שתתווסף בעתיד לא תזלוג החוצה
 * מעצמה.
 */
function exportDatasets() {
    return [
        'users' => [
            'title'  => 'משתמשים',
            'detail' => 'ללא סיסמאות',
            'sql'    => "
                SELECT u.id, u.name, u.email, u.username,
                       u.is_admin, u.is_active, u.created_at, u.last_login
                FROM users u ORDER BY u.id",
        ],

        'groups' => [
            'title'  => 'אירועים',
            'detail' => 'כולל מנהל, תאריך ומצב',
            'sql'    => "
                SELECT pg.id, pg.name, pg.description, pg.event_date, pg.event_location,
                       pg.status, pg.share_rate, pg.is_active, pg.closed_at, pg.created_at,
                       owner.name AS owner_name, owner.email AS owner_email,
                       (SELECT COUNT(*) FROM group_members gm
                         WHERE gm.group_id = pg.id AND gm.is_active = 1) AS member_count
                FROM purchase_groups pg
                LEFT JOIN users owner ON owner.id = pg.owner_id
                ORDER BY pg.id",
        ],

        'members' => [
            'title'  => 'חברויות',
            'detail' => 'מי משתתף בכל אירוע, ובאיזה מפתח',
            'sql'    => "
                SELECT gm.id, gm.group_id, pg.name AS group_name,
                       gm.nickname, gm.email, gm.user_id,
                       gm.participation_type, gm.participation_value,
                       gm.is_active, gm.joined_at
                FROM group_members gm
                LEFT JOIN purchase_groups pg ON pg.id = gm.group_id
                ORDER BY gm.group_id, gm.id",
        ],

        'purchases' => [
            'title'  => 'קניות',
            'detail' => 'סכומים, מי קנה, וכמה הוחרגו',
            'sql'    => "
                SELECT gp.id, gp.group_id, pg.name AS group_name,
                       gm.nickname AS buyer, gp.amount, gp.description,
                       gp.purchase_date, gp.created_at,
                       (gp.image_path IS NOT NULL AND gp.image_path <> '') AS has_receipt,
                       (SELECT COUNT(*) FROM purchase_exclusions pe
                         WHERE pe.purchase_id = gp.id) AS excluded_count
                FROM group_purchases gp
                LEFT JOIN purchase_groups pg ON pg.id = gp.group_id
                LEFT JOIN group_members gm ON gm.id = gp.member_id
                ORDER BY gp.group_id, gp.id",
        ],

        'settlements' => [
            'title'  => 'התחשבנויות',
            'detail' => 'העברות שסומנו כבוצעו',
            'sql'    => "
                SELECT s.id, s.group_id, pg.name AS group_name,
                       payer.nickname AS from_member, payee.nickname AS to_member,
                       s.amount, s.note, s.created_at
                FROM settlements s
                LEFT JOIN purchase_groups pg ON pg.id = s.group_id
                LEFT JOIN group_members payer ON payer.id = s.from_member_id
                LEFT JOIN group_members payee ON payee.id = s.to_member_id
                ORDER BY s.group_id, s.id",
        ],

        'items' => [
            'title'  => 'רשימות קניות',
            'detail' => 'מה צריך להביא, ומי לקח על עצמו',
            'sql'    => "
                SELECT si.id, si.group_id, pg.name AS group_name,
                       si.title, si.quantity, si.notes, si.status,
                       gm.nickname AS assigned_to, si.purchase_id, si.created_at
                FROM shopping_items si
                LEFT JOIN purchase_groups pg ON pg.id = si.group_id
                LEFT JOIN group_members gm ON gm.id = si.assigned_member_id
                ORDER BY si.group_id, si.sort_order, si.id",
        ],

        'invitations' => [
            'title'  => 'הזמנות',
            'detail' => 'ללא טוקנים',
            'sql'    => "
                SELECT gi.id, gi.group_id, pg.name AS group_name,
                       gi.email, gi.nickname, gi.status,
                       gi.participation_type, gi.participation_value,
                       inviter.name AS invited_by, gi.created_at, gi.responded_at
                FROM group_invitations gi
                LEFT JOIN purchase_groups pg ON pg.id = gi.group_id
                LEFT JOIN users inviter ON inviter.id = gi.invited_by
                ORDER BY gi.id",
        ],

        'contacts' => [
            'title'  => 'אנשי קשר',
            'detail' => 'הרשימה של כל בעלים',
            'sql'    => "
                SELECT c.id, owner.name AS owner_name, c.name, c.email,
                       c.default_participation_type, c.default_participation_value,
                       c.times_used, c.last_used_at, c.created_at
                FROM contacts c
                LEFT JOIN users owner ON owner.id = c.owner_id
                ORDER BY c.owner_id, c.id",
        ],

        'admin_log' => [
            'title'  => 'יומן פעולות ניהול',
            'detail' => 'מי עשה מה, ומתי',
            'sql'    => "
                SELECT a.id, u.name AS admin_name, a.action,
                       a.target_type, a.target_id, a.details, a.created_at
                FROM admin_actions a
                LEFT JOIN users u ON u.id = a.admin_id
                ORDER BY a.id",
        ],

        'email_log' => [
            'title'  => 'יומן מיילים',
            'detail' => 'מה יצא, ומה נכשל',
            'sql'    => "
                SELECT id, to_email, subject, status, sent_at
                FROM email_log ORDER BY id",
        ],
    ];
}

/**
 * מריץ מערך נתונים אחד.
 *
 * @return array|null שורות, או null אם השאילתה נכשלה
 */
function runExport(PDO $pdo, $key) {
    $datasets = exportDatasets();

    if (!isset($datasets[$key])) {
        return null;
    }

    try {
        return $pdo->query($datasets[$key]['sql'])->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Export $key failed: " . $e->getMessage());

        return null;
    }
}

/**
 * שם קובץ עם חותמת תאריך, בלי תווים שישברו כותרת HTTP.
 */
function exportFilename($key, $extension) {
    $safe = preg_replace('/[^a-z0-9_\-]/i', '', (string)$key);

    return 'family-' . $safe . '-' . date('Y-m-d') . '.' . $extension;
}

/** כותרות ההורדה, משותפות לכל הפורמטים */
function sendDownloadHeaders($filename, $contentType) {
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
}

/**
 * שדה בודד ב-CSV.
 *
 * שני דברים קורים כאן, ושניהם נדרשים בפועל:
 *
 *   1. הכל עטוף במרכאות. פשוט יותר מלהחליט מתי צריך, ותמיד תקין
 *      גם כשיש פסיק, מרכאות או ירידת שורה בתוך תיאור של קנייה.
 *   2. ערך שמתחיל ב-= + - @ מקבל גרש מוביל. בלעדיו אקסל מפרש
 *      אותו כנוסחה, ותיאור כמו "-50 החזר" הופך לשגיאה בתא -
 *      ובמקרה הגרוע לנוסחה שמושכת נתונים מבחוץ.
 */
function csvField($value) {
    if ($value === null || $value === false) {
        return '""';
    }

    $value = (string)$value;

    if ($value !== '' && strpos("=+-@", $value[0]) !== false) {
        $value = "'" . $value;
    }

    return '"' . str_replace('"', '""', $value) . '"';
}

/**
 * שולח CSV שנפתח נכון באקסל בעברית.
 *
 * BOM: בלעדיו אקסל מפרש את הקובץ ב-windows-1255 והעברית יוצאת
 * ג'יבריש. CRLF: בלעדיו חלק מגרסאות אקסל מדביקות את כל הקובץ
 * לשורה אחת.
 *
 * הכתיבה ידנית ולא דרך fputcsv, כי חתימת fputcsv השתנתה בין
 * גרסאות PHP - סיום שורה מותאם נוסף רק ב-8.1 - והקוד כאן אמור
 * לרוץ גם על אירוח משותף עם גרסה ישנה.
 */
function streamCsv(array $rows, $filename) {
    sendDownloadHeaders($filename, 'text/csv; charset=utf-8');

    echo "\xEF\xBB\xBF";

    if (!$rows) {
        echo "אין נתונים\r\n";
        return;
    }

    echo implode(',', array_map('csvField', array_keys($rows[0]))) . "\r\n";

    foreach ($rows as $row) {
        echo implode(',', array_map('csvField', $row)) . "\r\n";
    }
}

/** שולח JSON, בעברית קריאה ולא במנוסחי \u */
function streamJson($data, $filename) {
    sendDownloadHeaders($filename, 'application/json; charset=utf-8');

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/**
 * גיבוי מלא: כל מערכי הנתונים בקובץ JSON אחד.
 *
 * מערך שהשאילתה שלו נכשלה נכלל כ-null ולא מושמט, כדי שיהיה
 * ברור שהוא חסר - גיבוי שנראה שלם אבל אינו, גרוע מגיבוי חלקי
 * שמצהיר על עצמו.
 */
function buildFullExport(PDO $pdo) {
    $data = [
        'exported_at' => date('c'),
        'site'        => SITE_NAME,
        'database'    => DB_NAME,
        'datasets'    => [],
    ];

    foreach (exportDatasets() as $key => $dataset) {
        $rows = runExport($pdo, $key);

        $data['datasets'][$key] = [
            'title' => $dataset['title'],
            'count' => $rows === null ? null : count($rows),
            'rows'  => $rows,
        ];
    }

    return $data;
}

/**
 * מטפל בבקשת ייצוא ומסיים את הבקשה.
 *
 * נקרא לפני כל פלט אחר, כי הוא שולח כותרות הורדה.
 */
function handleExportRequest(PDO $pdo, $adminId, $key, $format) {
    $format = ($format === 'json') ? 'json' : 'csv';

    if ($key === 'full') {
        logAdminAction($pdo, $adminId, 'export', 'system', null, 'גיבוי מלא');
        streamJson(buildFullExport($pdo), exportFilename('backup', 'json'));
        exit;
    }

    $datasets = exportDatasets();

    if (!isset($datasets[$key])) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "מערך נתונים לא מוכר\n";
        exit;
    }

    $rows = runExport($pdo, $key);

    if ($rows === null) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "הייצוא נכשל. ייתכן שהטבלה עוד לא נוצרה.\n";
        exit;
    }

    logAdminAction($pdo, $adminId, 'export', 'system', null,
        $datasets[$key]['title'] . " ($format, " . count($rows) . " שורות)");

    if ($format === 'json') {
        streamJson($rows, exportFilename($key, 'json'));
    } else {
        streamCsv($rows, exportFilename($key, 'csv'));
    }

    exit;
}
