<?php
/**
 * בדיקות לסימן המטבע
 * tests/currency_test.php
 *
 * הבדיקה הזו נכתבה אחרי תקלה אמיתית: באחסון המשותף משהו הגדיר
 * את הקבוע CURRENCY_SYMBOL לפני שהאפליקציה נטענה, defineOnce
 * כיבדה את הערך הזר, ומסך הניהול הציג "262145" לפני כל סכום.
 *
 * התקלה שרדה חודשים כי כמעט בכל מקום ה-₪ כתוב ישירות ב-HTML;
 * רק מסך אחד קרא מהקבוע, ושם היא צפה. המסקנה היא שאסור לסמוך
 * על ערך שהסביבה יכולה לקבוע - צריך לאמת אותו.
 *
 * הרצה: php tests/currency_test.php
 */

require_once __DIR__ . '/../includes/currency.php';

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
echo "\n1. זיהוי סימן מטבע תקין\n";
// ============================================================

foreach (['₪', '$', '€', '£', '¥'] as $symbol) {
    check("\"$symbol\" מזוהה כסימן מטבע", isCurrencySymbol($symbol));
}

// ============================================================
echo "\n2. דחיית ערכים פסולים\n";
// ============================================================

// זה הערך שהופיע בפועל בשרת, ובגללו נכתבה הבדיקה
check('"262145" נדחה', !isCurrencySymbol('262145'));

check('מחרוזת ריקה נדחית', !isCurrencySymbol(''));
check('אות נדחית', !isCurrencySymbol('a'));
check('ספרה נדחית', !isCurrencySymbol('7'));
check('עברית נדחית', !isCurrencySymbol('ש'));
check('שני סימנים נדחים', !isCurrencySymbol('₪₪'));
check('סימן עם רווח נדחה', !isCurrencySymbol('₪ '));
check('סימן בתוך טקסט נדחה', !isCurrencySymbol('מחיר ₪'));
check('null נדחה', !isCurrencySymbol(null));
check('מספר נדחה', !isCurrencySymbol(8362));
check('מערך נדחה', !isCurrencySymbol([]));

// ============================================================
echo "\n3. השם CURRENCY_SYMBOL תפוס על ידי PHP\n";
// ============================================================

// זה לב התקלה, ולכן זו הבדיקה החשובה בקובץ. הקבוע קיים בכל
// התקנת PHP - הבדיקה רצה בלי config.php ובלי .env, ובכל זאת
// הוא מוגדר - ולכן defineOnce על השם הזה לעולם לא תיכנס לתוקף.
check('CURRENCY_SYMBOL מוגדר גם בלי האפליקציה', defined('CURRENCY_SYMBOL'));
check('והוא אינו סימן מטבע', !isCurrencySymbol(reservedCurrencyConstant()),
    (string)reservedCurrencyConstant());

// ============================================================
echo "\n4. ברירת המחדל\n";
// ============================================================

// APP_CURRENCY_SYMBOL אינו מוגדר כאן, כי הבדיקה רצה בלי config.php
check('קבוע האפליקציה אינו מוגדר בהקשר הבדיקה', !defined('APP_CURRENCY_SYMBOL'));
check('currencySymbol נופלת ל-₪', currencySymbol() === '₪', currencySymbol());
check('התוצאה עצמה עוברת אימות', isCurrencySymbol(currencySymbol()));
check('ברירת המחדל תקינה', isCurrencySymbol(DEFAULT_CURRENCY_SYMBOL));

// ============================================================
echo "\n5. ערך פסול בקבוע האפליקציה\n";
// ============================================================

// גם השם החדש אינו נאמן בעיוורון: .env שגוי לא אמור לשבור מסך.
// currencySymbol כבר חושבה ונשמרה, ולכן נבדקת כאן ההחלטה עצמה.
define('APP_CURRENCY_SYMBOL', 'ILS');

check('קבוע האפליקציה אכן מכיל ערך פסול', !isCurrencySymbol(APP_CURRENCY_SYMBOL));
check('ההחלטה תדחה אותו', !isCurrencySymbol(
    defined('APP_CURRENCY_SYMBOL') ? APP_CURRENCY_SYMBOL : null));

echo "\n" . str_repeat('=', 55) . "\n";
echo "עבר: $pass | נכשל: $fail\n";

exit($fail > 0 ? 1 : 0);
