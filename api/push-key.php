<?php
/**
 * מוסר לדפדפן את המפתח הציבורי להרשמה למנוי Push
 * api/push-key.php
 *
 * המפתח הציבורי אינו סוד - הוא נועד להיות בידי הדפדפן. המפתח
 * הפרטי לעולם אינו יוצא מהשרת.
 */

require_once '../config.php';
require_once '../includes/session.php';

bootstrapSession();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'לא מחובר']);
    exit;
}

if (VAPID_PUBLIC_KEY === '') {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'התראות אינן מוגדרות בשרת']);
    exit;
}

echo json_encode(['success' => true, 'key' => VAPID_PUBLIC_KEY]);
