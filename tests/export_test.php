<?php
/**
 * בדיקות לייצוא הנתונים
 * tests/export_test.php
 *
 * שתי שאלות נבדקות כאן, ושתיהן נשאלות על קובץ שכבר יצא מהשרת
 * ואי אפשר להחזיר:
 *
 *   1. **האם משהו רגיש דלף?** מערכי הנתונים בוחרים עמודות
 *      במפורש בדיוק כדי שעמודה חדשה לא תזלוג מעצמה. הבדיקה
 *      אוכפת את זה: אין SELECT *, ואין אזכור של סיסמה, טוקן
 *      או מפתח הצפנה בשום שאילתה.
 *
 *   2. **האם הקובץ בטוח להיפתח?** תא ב-CSV שמתחיל ב-= הוא
 *      נוסחה מבחינת אקסל, ותיאור קנייה שהמשתמש הקליד יכול
 *      להתחיל כך. הבדיקה מוודאת שהוא מנוטרל.
 *
 * הרצה: php tests/export_test.php
 */

// הקובץ נטען בלי config.php ובלי מסד נתונים: מה שנבדק כאן הוא
// הגדרת מערכי הנתונים ובניית ה-CSV, ושניהם לוגיקה טהורה.
require_once __DIR__ . '/../includes/admin_export.php';

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

// ============================================================
echo "\n1. אין דליפה של סודות\n";
// ============================================================

$datasets = exportDatasets();

check('יש מערכי נתונים', count($datasets) > 0, 'מערכים: ' . count($datasets));

$missingFields = [];
$selectStar    = [];
$leaks         = [];

// עמודות שאסור להן להופיע בשום ייצוא. גיבוב סיסמה וטוקן מאפשרים
// התחזות; p256dh ו-auth הם מפתחות ההצפנה של מנוי ה-Push.
$forbidden = ['password', 'token', 'token_hash', 'p256dh', 'secret', 'endpoint'];

foreach ($datasets as $key => $dataset) {
    if (!isset($dataset['title'], $dataset['detail'], $dataset['sql'])) {
        $missingFields[] = $key;
        continue;
    }

    $sql = strtolower($dataset['sql']);

    if (preg_match('/select\s+\*/', $sql) || preg_match('/\.\*/', $sql)) {
        $selectStar[] = $key;
    }

    foreach ($forbidden as $column) {
        if (preg_match('/\b' . preg_quote($column, '/') . '\b/', $sql)) {
            $leaks[] = "$key:$column";
        }
    }
}

check('לכל מערך יש title, detail ו-sql', !$missingFields, implode(', ', $missingFields));
check('אף שאילתה אינה בוחרת * ', !$selectStar, implode(', ', $selectStar));
check('אין עמודה רגישה בשום שאילתה', !$leaks, implode(', ', $leaks));

// ============================================================
echo "\n2. שמות קבצים\n";
// ============================================================

check('שם הקובץ נושא סיומת', substr(exportFilename('users', 'csv'), -4) === '.csv');
check('שם הקובץ נושא תאריך', strpos(exportFilename('users', 'csv'), date('Y-m-d')) !== false);

// שם קובץ נכנס לכותרת Content-Disposition. מרכאה או ירידת שורה
// בתוכו היו מאפשרות להזריק כותרת נוספת לתשובה.
$nasty = exportFilename('users"; rm -rf /' . "\r\n" . 'X-Injected: 1', 'csv');
check('תווים מסוכנים מסוננים משם הקובץ',
    strpos($nasty, '"') === false
    && strpos($nasty, "\r") === false
    && strpos($nasty, "\n") === false
    && strpos($nasty, ' ') === false,
    $nasty);

// ============================================================
echo "\n3. שדות CSV\n";
// ============================================================

check('ערך רגיל עטוף במרכאות', csvField('דנה') === '"דנה"');
check('null הופך לתא ריק', csvField(null) === '""');
check('מרכאות מוכפלות', csvField('הוא אמר "שלום"') === '"הוא אמר ""שלום"""');
check('פסיק אינו שובר את השורה', csvField('בשר, יין') === '"בשר, יין"');
check('ירידת שורה נשמרת בתוך התא', csvField("שורה\nשנייה") === "\"שורה\nשנייה\"");
check('מספר מיוצג כמחרוזת', csvField(42) === '"42"');
check('אפס אינו הופך לריק', csvField(0) === '"0"', csvField(0));
check('מחרוזת ריקה נשארת ריקה', csvField('') === '""');

// ניטרול נוסחאות
foreach (['=', '+', '-', '@'] as $prefix) {
    $result = csvField($prefix . 'SUM(A1:A9)');
    check("ערך שמתחיל ב-$prefix מנוטרל", strpos($result, '"\'') === 0, $result);
}

check('סכום שלילי עדיין קריא', csvField('-50') === '"\'-50"', csvField('-50'));
check('טקסט רגיל אינו מקבל גרש', strpos(csvField('בשר'), "'") === false);

echo "\n" . str_repeat('=', 55) . "\n";
echo "עבר: $pass | נכשל: $fail\n";

exit($fail > 0 ? 1 : 0);
