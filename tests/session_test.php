<?php
/**
 * בדיקות ניתוב הכניסה
 * tests/session_test.php
 *
 * דווח לופ אחרי יציאה מהחשבון. הווקטור המסוכן כאן הוא יעד חזרה
 * שמצביע על מסך כניסה או יציאה: ההתחברות מפנה ליציאה, היציאה
 * מנתקת ומפנה להתחברות, וחוזר חלילה. המסך מהבהב ואי אפשר להבין
 * ממנו כלום.
 *
 * הרצה: php tests/session_test.php
 */

require_once __DIR__ . '/../includes/session.php';

$pass = 0;
$fail = 0;

function check($label, $actual, $expected) {
    global $pass, $fail;

    if ($actual === $expected) {
        $pass++;
        echo "  ok   $label\n";
    } else {
        $fail++;
        echo "  FAIL $label — קיבלנו: " . var_export($actual, true)
            . " | ציפינו: " . var_export($expected, true) . "\n";
    }
}

// ============================================================
echo "\n1. זיהוי מסכי הכניסה\n";
// ============================================================

check('התחברות',        isAuthPath('/family/auth/login.php'),  true);
check('יציאה',          isAuthPath('/family/auth/logout.php'), true);
check('גוגל',           isAuthPath('/family/auth/google-auth.php'), true);
check('שחזור סיסמה',    isAuthPath('/family/auth/forgot-password.php'), true);
check('איפוס סיסמה',    isAuthPath('/family/auth/reset-password.php'), true);
check('עם פרמטרים',     isAuthPath('/family/auth/login.php?invited=1'), true);

check('דשבורד אינו',    isAuthPath('/family/dashboard.php'), false);
check('אירוע אינו',     isAuthPath('/family/group.php?id=20'), false);
check('הזמנה אינה',     isAuthPath('/family/join.php?token=abc'), false);
check('שורש אינו',      isAuthPath('/family/'), false);
check('ריק אינו',       isAuthPath(''), false);

// שם שרק מכיל את המילה אינו מסך כניסה
check('שם דומה אינו נתפס', isAuthPath('/family/login-report.php'), false);

// ============================================================
echo "\n2. יעד החזרה\n";
// ============================================================

/** מדמה בקשה ומחזיר את היעד שנשמר */
function targetAfter($requestUri) {
    $_SESSION = [];
    $_SERVER['REQUEST_URI'] = $requestUri;

    // redirectToLogin שולח כותרת ומסיים, ולכן נבדקת כאן הלוגיקה
    // שלו כפי שהיא - שמירה מותנית ב-session
    if ($requestUri !== '' && $requestUri[0] === '/'
        && strpos($requestUri, '//') !== 0
        && !isAuthPath($requestUri)) {
        $_SESSION['redirect_after_login'] = $requestUri;
    }

    return $_SESSION['redirect_after_login'] ?? null;
}

check('דף מוגן נשמר',
    targetAfter('/family/group.php?id=20'), '/family/group.php?id=20');
check('מסך התחברות אינו נשמר',
    targetAfter('/family/auth/login.php'), null);
check('מסך יציאה אינו נשמר',
    targetAfter('/family/auth/logout.php'), null);
check('כתובת חיצונית אינה נשמרת',
    targetAfter('//evil.example/x'), null);

// ============================================================
echo "\n3. שליפת היעד\n";
// ============================================================

$_SESSION = ['redirect_after_login' => '/family/group.php?id=20'];
check('דף מוגן מוחזר', consumeLoginRedirect(), '/family/group.php?id=20');
check('ונמחק אחרי שנצרך', isset($_SESSION['redirect_after_login']), false);

// יעד ישן שנשמר לפני התיקון, ועדיין יושב ב-session פעיל
$_SESSION = ['redirect_after_login' => '/family/auth/logout.php'];
check('יעד יציאה ישן נדחה', consumeLoginRedirect(), null);
check('וגם הוא נמחק', isset($_SESSION['redirect_after_login']), false);

$_SESSION = ['redirect_after_login' => '/family/auth/login.php?invited=1'];
check('יעד התחברות ישן נדחה', consumeLoginRedirect(), null);

$_SESSION = ['redirect_after_login' => 'https://evil.example/x'];
check('כתובת מלאה נדחית', consumeLoginRedirect(), null);

$_SESSION = [];
check('בלי יעד מוחזר null', consumeLoginRedirect(), null);

echo "\n" . str_repeat('=', 55) . "\n";
echo "עבר: $pass | נכשל: $fail\n";

exit($fail > 0 ? 1 : 0);
