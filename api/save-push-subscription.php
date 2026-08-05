<?php
// api/save-push-subscription.php - שמירת מנוי Push Notifications
require_once '../config.php';
require_once '../includes/session.php';

bootstrapSession();

header('Content-Type: application/json');

// רישום מנוי הוא פעולה שמשנה מצב, ולכן דורש טוקן
if (!verifyCsrfToken()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

// בדוק שהמשתמש מחובר
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// קבל את הנתונים
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['subscription'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing subscription data']);
    exit;
}

$subscription = $data['subscription'];
$userId = $_SESSION['user_id'];

try {
    $pdo = getDBConnection();
    
    // בדוק אם כבר קיים מנוי
    $stmt = $pdo->prepare("
        SELECT id FROM push_subscriptions 
        WHERE user_id = ? AND endpoint = ?
    ");
    $stmt->execute([$userId, $subscription['endpoint']]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // עדכן מנוי קיים
        $stmt = $pdo->prepare("
            UPDATE push_subscriptions 
            SET p256dh = ?, 
                auth = ?,
                is_active = 1,
                updated_at = NOW()
            WHERE user_id = ? AND endpoint = ?
        ");
        $stmt->execute([
            $subscription['keys']['p256dh'] ?? null,
            $subscription['keys']['auth'] ?? null,
            $userId,
            $subscription['endpoint']
        ]);
    } else {
        // צור מנוי חדש
        $stmt = $pdo->prepare("
            INSERT INTO push_subscriptions 
            (user_id, endpoint, p256dh, auth, user_agent, device_type, is_active)
            VALUES (?, ?, ?, ?, ?, ?, 1)
        ");
        
        // זהה סוג מכשיר
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $deviceType = 'Unknown';
        if (stripos($userAgent, 'android') !== false) $deviceType = 'Android';
        elseif (stripos($userAgent, 'iphone') !== false) $deviceType = 'iPhone';
        elseif (stripos($userAgent, 'ipad') !== false) $deviceType = 'iPad';
        elseif (stripos($userAgent, 'windows') !== false) $deviceType = 'Windows';
        elseif (stripos($userAgent, 'mac') !== false) $deviceType = 'Mac';
        elseif (stripos($userAgent, 'linux') !== false) $deviceType = 'Linux';
        
        $stmt->execute([
            $userId,
            $subscription['endpoint'],
            $subscription['keys']['p256dh'] ?? null,
            $subscription['keys']['auth'] ?? null,
            $userAgent,
            $deviceType
        ]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Subscription saved successfully'
    ]);
    
} catch (Exception $e) {
    error_log('Error saving push subscription: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to save subscription'
    ]);
}