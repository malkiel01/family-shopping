<?php
/**
 * בדיקת הרשאות והגנה על דפים
 * includes/auth_check.php
 *
 * יש לכלול את הקובץ הזה בראש כל דף מוגן.
 * דפים ציבוריים שצריכים רק session ו-CSRF (כמו join.php)
 * יכללו במקומו את includes/session.php.
 */

// מנע גישה ישירה לקובץ
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/session.php';

// בדיקה אם המשתמש מחובר
if (!isset($_SESSION['user_id'])) {
    redirectToLogin();
}

// בדיקת timeout של סשן (30 דקות)
$timeout = 1800;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
    session_unset();
    session_destroy();
    bootstrapSession();
    redirectToLogin('?timeout=1');
}
$_SESSION['last_activity'] = time();

// הגנה מפני Session Hijacking
if (!isset($_SESSION['user_agent'])) {
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
} elseif ($_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
    session_unset();
    session_destroy();
    bootstrapSession();
    redirectToLogin('?error=security');
}

// הגנה מפני CSRF בכל פעולת POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrfToken()) {
    rejectRequest();
}

/**
 * פונקציה לבדיקת הרשאת מנהל קבוצה
 */
function checkGroupOwnership($pdo, $group_id, $user_id) {
    $stmt = $pdo->prepare("SELECT owner_id FROM purchase_groups WHERE id = ? AND is_active = 1");
    $stmt->execute([$group_id]);
    $group = $stmt->fetch();

    return $group && $group['owner_id'] == $user_id;
}

/**
 * פונקציה לבדיקת חברות בקבוצה
 */
function checkGroupMembership($pdo, $group_id, $user_id) {
    $stmt = $pdo->prepare("
        SELECT id FROM group_members
        WHERE group_id = ? AND user_id = ? AND is_active = 1
    ");
    $stmt->execute([$group_id, $user_id]);

    return $stmt->fetch() !== false;
}
