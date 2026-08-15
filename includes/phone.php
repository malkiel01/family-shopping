<?php
/**
 * מספרי טלפון
 * includes/phone.php
 *
 * מספר טלפון מוקלד בעשר צורות שונות - 050-1234567, 0501234567,
 * +972 50 123 4567, 972501234567 - וכולן אותו מספר. שלוש שאלות
 * נפרדות, ולכן שלוש פונקציות:
 *
 *   normalizePhone   מה לשמור במסד
 *   formatPhone      איך להראות את זה לאדם
 *   phoneToWhatsapp  מה לשים בקישור
 *
 * ההנחה היחידה שנעשית כאן היא שמספר שמתחיל באפס הוא ישראלי.
 * זו הנחה נכונה כמעט תמיד באפליקציה הזו, ומי שצריך אחרת יכול
 * להקליד קידומת בינלאומית במפורש - היא נשמרת כפי שהיא.
 */

/** קידומת המדינה כשלא נאמר אחרת */
const DEFAULT_COUNTRY_CODE = '972';

/**
 * מנקה מספר לצורת אחסון: ספרות בלבד, עם + מוביל אם ניתנה
 * קידומת בינלאומית במפורש.
 *
 * @return string המספר הנקי, או '' אם אינו נראה כמו מספר
 */
function normalizePhone($raw) {
    $raw = trim((string)$raw);

    if ($raw === '') {
        return '';
    }

    $digits = preg_replace('/\D+/', '', $raw);

    if ($digits === '') {
        return '';
    }

    $international = (strpos($raw, '+') === 0);

    // 00 הוא הכתיב האירופי ל-+, ואינו חלק מהמספר עצמו
    if (!$international && strpos($raw, '00') === 0) {
        $digits        = substr($digits, 2);
        $international = true;
    }

    // קצר מדי או ארוך מדי אינו מספר טלפון. הגבולות רחבים
    // בכוונה: תוכנית המספרים משתנה בין מדינות, ואין סיבה
    // לדחות מספר תקין רק כי הוא לא ישראלי.
    $length = strlen($digits);
    if ($length < 7 || $length > 15) {
        return '';
    }

    return $international ? '+' . $digits : $digits;
}

/**
 * המספר בצורה בינלאומית, בלי + ובלי אפסים - כפי ש-wa.me דורש.
 *
 * @return string או '' כשאי אפשר לבנות צורה כזו
 */
function phoneToWhatsapp($raw) {
    $normalized = normalizePhone($raw);

    if ($normalized === '') {
        return '';
    }

    if (strpos($normalized, '+') === 0) {
        return substr($normalized, 1);
    }

    // מספר מקומי: האפס המוביל מוחלף בקידומת המדינה
    if (strpos($normalized, '0') === 0) {
        return DEFAULT_COUNTRY_CODE . substr($normalized, 1);
    }

    // בלי אפס ובלי פלוס - כבר עם קידומת, או מספר חלקי.
    // מספר שמתחיל בקידומת המדינה נשאר כפי שהוא.
    if (strpos($normalized, DEFAULT_COUNTRY_CODE) === 0) {
        return $normalized;
    }

    return DEFAULT_COUNTRY_CODE . $normalized;
}

/**
 * המספר לתצוגה: 050-1234567.
 *
 * מספר שאינו נראה ישראלי מוצג כפי שהוא - ניחוש חלוקה לא נכון
 * גרוע ממספר רציף.
 */
function formatPhone($raw) {
    $normalized = normalizePhone($raw);

    if ($normalized === '') {
        return '';
    }

    // מקומי בן 10 ספרות שמתחיל ב-0: החלוקה המוכרת
    if (strlen($normalized) === 10 && strpos($normalized, '0') === 0) {
        return substr($normalized, 0, 3) . '-' . substr($normalized, 3);
    }

    // מקומי בן 9 - קווי בקידומת בת שתי ספרות
    if (strlen($normalized) === 9 && strpos($normalized, '0') === 0) {
        return substr($normalized, 0, 2) . '-' . substr($normalized, 2);
    }

    return $normalized;
}

/**
 * המספר של משתתף, כשיש אחד.
 *
 * מספר שנרשם באירוע גובר על זה שבפרופיל: הוא נקבע במפורש עבור
 * ההתחשבנות הזו, ולרוב על ידי מי שמכיר את המשתתף.
 */
function memberPhone(array $member) {
    $own = normalizePhone($member['phone'] ?? '');
    if ($own !== '') {
        return $own;
    }

    return normalizePhone($member['user_phone'] ?? '');
}

/**
 * ההודעה שנשלחת בבקשת תשלום.
 *
 * מכילה את הסכום, את שם האירוע, ואת המספר שאליו משלמים - שלושת
 * הפרטים שדרושים כדי לפתוח את ביט ולסיים. בלי המספר, המקבל
 * צריך לחזור ולשאול, וזה בדיוק החיכוך שהכפתור נועד להסיר.
 */
function paymentRequestText($debtorName, $creditorName, $amount, $groupName, $creditorPhone = '') {
    $symbol = function_exists('currencySymbol') ? currencySymbol() : '₪';
    $sum    = $symbol . number_format((float)$amount, 2);

    $lines   = [];
    $lines[] = 'היי ' . $debtorName . ',';
    $lines[] = 'נשאר חוב של ' . $sum . ' על "' . $groupName . '".';

    $formatted = formatPhone($creditorPhone);
    if ($formatted !== '') {
        $lines[] = 'אפשר להעביר בביט ל-' . $formatted . ' (' . $creditorName . ').';
    }

    $lines[] = 'תודה!';

    return implode("\n", $lines);
}

/** קישור וואטסאפ מוכן, או '' כשאין מספר */
function whatsappLink($phone, $text) {
    $target = phoneToWhatsapp($phone);

    if ($target === '') {
        return '';
    }

    return 'https://wa.me/' . $target . '?text=' . rawurlencode($text);
}
