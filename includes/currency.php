<?php
/**
 * סימן המטבע להצגה
 * includes/currency.php
 *
 * הרקע כאן שווה קריאה, כי הוא מסביר שם קבוע שנראה מיותר.
 *
 * במשך זמן רב הסימן הוגדר כך:
 *
 *     defineOnce('CURRENCY_SYMBOL', '₪');
 *
 * וזה מעולם לא עבד. CURRENCY_SYMBOL הוא **קבוע מובנה של PHP** -
 * אחד מקבועי nl_langinfo שבתוסף standard - והערך שלו הוא המספר
 * 262145. defineOnce ראתה שהשם תפוס, ויתרה בשקט, וכל קוד שקרא
 * את הקבוע קיבל מספר במקום סימן.
 *
 * התקלה שרדה חודשים כי כמעט בכל מסך ה-₪ כתוב ישירות ב-HTML.
 * רק מסך הניהול קרא מהקבוע, והציג "2621458,811" במקום "₪8,811".
 *
 * לכן שני שינויים: שם קבוע עם קידומת שלא מתנגשת בשום דבר,
 * ואימות של הערך לפני שמציגים אותו.
 */

/** ברירת המחדל, כשאין ערך תקין */
const DEFAULT_CURRENCY_SYMBOL = '₪';

/**
 * האם המחרוזת היא סימן מטבע יחיד ותקין.
 *
 * \p{Sc} היא קטגוריית Symbol/Currency ביוניקוד - ₪, $, €, £ וכו'.
 * מספר, מחרוזת ריקה או רצף תווים אינם עוברים.
 */
function isCurrencySymbol($value) {
    return is_string($value) && preg_match('/^\p{Sc}$/u', $value) === 1;
}

/**
 * סימן המטבע להצגה למשתמש.
 *
 * @return string
 */
function currencySymbol() {
    static $symbol = null;

    if ($symbol !== null) {
        return $symbol;
    }

    $candidate = defined('APP_CURRENCY_SYMBOL') ? APP_CURRENCY_SYMBOL : null;
    $symbol    = isCurrencySymbol($candidate) ? $candidate : DEFAULT_CURRENCY_SYMBOL;

    return $symbol;
}

/**
 * הערך של הקבוע המובנה של PHP, לצורך תצוגה בבדיקת התקינות.
 *
 * מוחזר כדי שאפשר יהיה להראות במסך *מה* התנגש ולא רק שמשהו
 * התנגש - ובעיקר כדי שאיש לא ינסה שוב להגדיר את השם הזה.
 *
 * @return string|null
 */
function reservedCurrencyConstant() {
    return defined('CURRENCY_SYMBOL') ? (string)CURRENCY_SYMBOL : null;
}
