<?php
/**
 * סוגי ההשתתפות בהוצאות
 * includes/participation.php
 *
 * percentage - אחוז מפורש מסך ההוצאות
 * fixed      - סכום קבוע בשקלים, שלא מושפע מהחלוקה של השאר
 * shares     - נפשות. מתחלקים ביניהם ביתרה לפי היחס בין מספרי
 *              הנפשות, וזו הדרך הטבעית לאירוע משפחתי: משפחה של
 *              ארבעה משלמת כפול ממשפחה של שניים
 *
 * ההמרה של נפשות לאחוזים נעשית ב-normalizeShareMembers שבתוך
 * group_calculations.php, ולכן שאר החישוב לא צריך להכיר את הסוג.
 */

const PARTICIPATION_TYPES = ['percentage', 'fixed', 'shares'];

/**
 * קורא את סוג ההשתתפות מהבקשה. סוג לא מוכר נופל לאחוזים,
 * כדי שערך שרירותי לעולם לא יגיע לעמודת ה-enum במסד.
 */
function participationTypeFromRequest() {
    $type = $_POST['participation_type'] ?? '';

    return in_array($type, PARTICIPATION_TYPES, true) ? $type : 'percentage';
}

/**
 * מנרמל את ערך ההשתתפות לפי הסוג.
 * נפשות הן מספר שלם - אין חצי נפש.
 */
function participationValueFromRequest($type) {
    $value = floatval($_POST['participation_value'] ?? 0);

    return $type === 'shares' ? (float)round($value) : round($value, 2);
}

/**
 * תיאור קריא של חלקו של משתתף, לשימוש בהתראות ובהודעות.
 */
function participationLabel($type, $value) {
    if ($type === 'shares') {
        return (int)$value . ' נפשות';
    }

    if ($type === 'percentage') {
        return $value . '%';
    }

    $symbol = defined('CURRENCY_SYMBOL') ? CURRENCY_SYMBOL : '₪';

    return $symbol . number_format((float)$value, 2);
}
