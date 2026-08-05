<?php
/**
 * תשתית session ו-CSRF
 * includes/session.php
 *
 * נטען גם על ידי דפים מוגנים (דרך auth_check.php) וגם על ידי
 * דפים ציבוריים כמו join.php, שצריכים session ו-CSRF
 * אבל לא דורשים התחברות מראש.
 */

/**
 * מפעיל session אם עוד לא הופעל, ומייצר CSRF token.
 */
function bootstrapSession() {
    if (session_status() === PHP_SESSION_NONE) {
        // מאפייני העוגייה חייבים להיקבע לפני session_start.
        // path ו-domain נשארים כברירת המחדל בכוונה, כדי שסשנים
        // קיימים לא יינתקו בעקבות השינוי.
        session_set_cookie_params([
            'httponly' => true,             // העוגייה לא נגישה ל-JavaScript
            'secure'   => isHttpsRequest(), // נשלחת רק על גבי HTTPS
            'samesite' => 'Lax',            // לא נשלחת בבקשות POST מאתר זר
        ]);
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

/**
 * האם הבקשה הנוכחית הגיעה ב-HTTPS.
 */
function isHttpsRequest() {
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    // מאחורי proxy או load balancer
    $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';

    return strtolower($forwarded) === 'https';
}

/**
 * מחליף את מזהה ה-session אחרי התחברות מוצלחת.
 *
 * בלי זה, מזהה שהיה ידוע לתוקף לפני ההתחברות ממשיך להיות תקף
 * אחריה - וזו התקפת session fixation. הטוקן של CSRF מוחלף גם הוא,
 * כדי שלא יישאר טוקן שנוצר לפני שידענו מי המשתמש.
 */
function regenerateSessionAfterLogin() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

/**
 * בודק את ה-CSRF token של הבקשה.
 * מקבל אותו מהטופס או מכותרת X-CSRF-Token.
 */
function verifyCsrfToken() {
    $sent = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    return !empty($_SESSION['csrf_token'])
        && is_string($sent)
        && hash_equals($_SESSION['csrf_token'], $sent);
}

/**
 * האם הבקשה הנוכחית היא AJAX.
 */
function isAjaxRequest() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * עוצר את הבקשה עם שגיאת אבטחה, בפורמט שמתאים לסוג הבקשה.
 */
function rejectRequest($message = 'Invalid security token') {
    http_response_code(403);

    if (isAjaxRequest()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    } else {
        echo htmlspecialchars($message);
    }
    exit;
}

/**
 * מפנה להתחברות ושומר את היעד המבוקש לחזרה אחריה.
 */
function redirectToLogin($query = '') {
    $target = $_SERVER['REQUEST_URI'] ?? '';

    // שומרים רק נתיבים פנימיים, לא URL מלא
    if ($target !== '' && $target[0] === '/' && strpos($target, '//') !== 0) {
        $_SESSION['redirect_after_login'] = $target;
    }

    $base = defined('APP_BASE_PATH') ? APP_BASE_PATH : '/family';
    header('Location: ' . $base . '/auth/login.php' . $query);
    exit;
}

/**
 * מחזיר את היעד לחזרה אחרי התחברות, ומנקה אותו מה-session.
 * מחזיר null אם אין יעד תקין.
 */
function consumeLoginRedirect() {
    $target = $_SESSION['redirect_after_login'] ?? null;
    unset($_SESSION['redirect_after_login']);

    if (!is_string($target) || $target === '') {
        return null;
    }

    // רק נתיבים יחסיים לאתר עצמו - הגנה מפני open redirect
    if ($target[0] !== '/' || strpos($target, '//') === 0) {
        return null;
    }

    return $target;
}

/**
 * פונקציה להדפסת ה-token בטפסים
 */
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES) . '">';
}

bootstrapSession();
