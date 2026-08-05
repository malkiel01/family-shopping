<?php
/**
 * דף אינדקס - הפניה אוטומטית לדשבורד
 * index.php
 */

require_once 'config.php';
require_once 'includes/session.php';

// דרך bootstrapSession, כדי שהעוגייה תקבל את אותן הגדרות אבטחה
// כמו בשאר הדפים
bootstrapSession();

// אם המשתמש מחובר - הפנה לדשבורד
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

// אם לא מחובר - הפנה לדף התחברות
header('Location: auth/login.php');
exit;
?>