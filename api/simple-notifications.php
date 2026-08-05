<?php
// api/simple-notifications.php - מערכת התראות פשוטה
require_once '../config.php';
require_once '../includes/session.php';

bootstrapSession();

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// הנקודה הזו מסמנת התראות כנקראו, כלומר משנה מצב. בלי טוקן, אתר
// זר היה יכול לגרום לדפדפן של המשתמש לבלוע את ההתראות שלו.
if (!verifyCsrfToken()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

// בדיקה אם המשתמש מחובר
$isLoggedIn = isset($_SESSION['user_id']);

// קבלת הפעולה המבוקשת
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get-user-info':
        // החזר מידע על המשתמש המחובר
        if ($isLoggedIn) {
            echo json_encode([
                'success' => true,
                'email' => $_SESSION['email'] ?? null,
                'user_id' => $_SESSION['user_id'] ?? null,
                'name' => $_SESSION['name'] ?? null
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'User not logged in'
            ]);
        }
        break;
        
    case 'test':
        // בדיקת חיבור
        echo json_encode([
            'success' => true,
            'message' => 'Server is responding',
            'timestamp' => date('Y-m-d H:i:s'),
            'user_logged_in' => $isLoggedIn
        ]);
        break;
        
    case 'save-token':
        // שמירת טוקן התראות
        if (!$isLoggedIn) {
            echo json_encode(['success' => false, 'message' => 'Not logged in']);
            exit;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        $_SESSION['notification_token'] = $data['token'] ?? '';
        
        echo json_encode([
            'success' => true,
            'message' => 'Token saved'
        ]);
        break;
        
    case 'get-pending':
        // קבלת התראות ממתינות
        if (!$isLoggedIn) {
            echo json_encode([
                'success' => false,
                'message' => 'Not logged in',
                'notifications' => []
            ]);
            exit;
        }
        
        try {
            $pdo = getDBConnection();
            
            // בדוק אם יש טבלת התראות
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM information_schema.tables 
                WHERE table_schema = DATABASE() 
                AND table_name = 'notification_queue'
            ");
            $stmt->execute();
            
            if ($stmt->fetchColumn() > 0) {
                // אם הטבלה קיימת, משוך התראות
                $stmt = $pdo->prepare("
                    SELECT * FROM notification_queue 
                    WHERE user_id = ? 
                    AND status = 'pending'
                    AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    ORDER BY created_at DESC
                    LIMIT 10
                ");
                $stmt->execute([$_SESSION['user_id']]);
                $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // עבד את ההתראות
                $processed = [];
                foreach ($notifications as $notif) {
                    $data = json_decode($notif['data'], true);
                    if ($data) {
                        $processed[] = [
                            'id' => $notif['id'],
                            'title' => $data['title'] ?? 'התראה',
                            'body' => $data['body'] ?? '',
                            'type' => $data['type'] ?? 'general',
                            'created_at' => $notif['created_at']
                        ];
                    }
                }
                
                // סמן כנקראו
                if (!empty($processed)) {
                    $ids = array_column($notifications, 'id');
                    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
                    $stmt = $pdo->prepare("
                        UPDATE notification_queue 
                        SET status = 'read' 
                        WHERE id IN ($placeholders)
                    ");
                    $stmt->execute($ids);
                }
                
                echo json_encode([
                    'success' => true,
                    'notifications' => $processed
                ]);
            } else {
                // אין טבלת התראות - החזר ריק
                echo json_encode([
                    'success' => true,
                    'notifications' => []
                ]);
            }
            
        } catch (Exception $e) {
            error_log('Error getting notifications: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Database error',
                'notifications' => []
            ]);
        }
        break;
        
    case 'send-test':
        // שלח התראת בדיקה
        if (!$isLoggedIn) {
            echo json_encode(['success' => false, 'message' => 'Not logged in']);
            exit;
        }
        
        try {
            $pdo = getDBConnection();
            
            // בדוק אם הטבלה קיימת, אם לא - צור אותה
            $stmt = $pdo->prepare("
                CREATE TABLE IF NOT EXISTS notification_queue (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT,
                    type VARCHAR(50),
                    data TEXT,
                    status ENUM('pending', 'read', 'sent') DEFAULT 'pending',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    processed_at TIMESTAMP NULL,
                    INDEX idx_user_status (user_id, status),
                    INDEX idx_created (created_at)
                )
            ");
            $stmt->execute();
            
            // הוסף התראת בדיקה
            $testData = json_encode([
                'title' => 'התראת בדיקה 🔔',
                'body' => 'זו התראת בדיקה מהשרת - ' . date('H:i:s'),
                'type' => 'test',
                'icon' => '/family/images/icons/android/android-launchericon-192-192.png'
            ]);
            
            $stmt = $pdo->prepare("
                INSERT INTO notification_queue (user_id, type, data, status)
                VALUES (?, 'test', ?, 'pending')
            ");
            $stmt->execute([$_SESSION['user_id'], $testData]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Test notification created'
            ]);
            
        } catch (Exception $e) {
            error_log('Error sending test: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Failed to create test notification'
            ]);
        }
        break;
        
    default:
        // פעולה לא מוכרת
        echo json_encode([
            'success' => false,
            'message' => 'Unknown action: ' . $action
        ]);
        break;
}
?>