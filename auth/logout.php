<?php
/**
 * יציאה מהחשבון
 * auth/logout.php
 *
 * שני דברים שנראים טכניים והם למעשה ההבדל בין יציאה שעובדת לבין
 * לולאה:
 *
 * 1. ה-session נפתח דרך bootstrapSession, בדיוק כמו בכל שאר הדפים.
 *    session_start() חשוף פותח אותו עם מאפייני ברירת המחדל, ואז
 *    session_get_cookie_params מחזיר מאפיינים שאינם אלה שאיתם
 *    העוגייה נוצרה - כך שמחיקתה עלולה לא לתפוס. העוגייה שורדת,
 *    המשתמש נשאר מחובר, והמסך חוזר לעצמו.
 *
 * 2. ההפניה מוחלטת ולא יחסית. "login.php" נפתר מול הנתיב הנוכחי,
 *    וברגע שמישהו מגיע לכאן מכתובת אחרת - הפניה, שגיאה, או PWA
 *    שמריץ בנתיב משלו - היעד אינו קיים, וכלל ה-fallback שולח
 *    אותו חזרה ל-index.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session.php';

// ניקוי כל משתני ה-session
$_SESSION = [];

// מחיקת עוגיית ה-session, באותם מאפיינים שאיתם היא נוצרה
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $params['path'],
        'domain'   => $params['domain'],
        'secure'   => isHttpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

session_destroy();

header('Location: ' . APP_BASE_PATH . '/auth/login.php?loggedout=1');
exit;
