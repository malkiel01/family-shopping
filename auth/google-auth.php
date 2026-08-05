<?php
// auth/google-auth.php - טיפול באימות Google
require_once '../config.php';
require_once '../includes/session.php';

// הגדר כותרות JSON
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// בדיקה שזו בקשת POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// קבלת הנתונים
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['credential'])) {
    echo json_encode(['success' => false, 'message' => 'Missing credential']);
    exit;
}

try {    
    // משתמש ב-CLIENT_ID מה-ENV (דרך config.php)
    $CLIENT_ID = GOOGLE_CLIENT_ID;
    
    // אימות הטוקן מול Google
    $id_token = $data['credential'];
    
    // URL לאימות - פשוט וקל!
    $verify_url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . $id_token;
    
    // שליחת בקשה לאימות
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $verify_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        throw new Exception('Failed to verify token');
    }
    
    $payload = json_decode($response, true);
    
    // וידוא שהטוקן תקין ושייך לאפליקציה שלנו
    if (!$payload || $payload['aud'] !== $CLIENT_ID) {
        throw new Exception('Invalid token');
    }
    
    // קבלת פרטי המשתמש
    $email = $payload['email'] ?? '';
    $name = $payload['name'] ?? '';
    $google_id = $payload['sub'] ?? '';
    $picture = $payload['picture'] ?? '';
    
    if (empty($email)) {
        throw new Exception('Email not provided');
    }
    
    // חיבור למסד הנתונים
    $pdo = getDBConnection();
    
    // בדיקה אם המשתמש קיים
    $stmt = $pdo->prepare("
        SELECT id, username, name, email, is_active
        FROM users 
        WHERE email = ? OR google_id = ?
    ");
    $stmt->execute([$email, $google_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        // משתמש קיים - עדכון פרטים
        $stmt = $pdo->prepare("
            UPDATE users 
            SET google_id = ?, 
                profile_picture = ?,
                last_login = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$google_id, $picture, $user['id']]);
        
        $user_id = $user['id'];
        $username = $user['username'];
        $user_name = $user['name'] ?: $name;
        
    } else {
        // משתמש חדש - יצירת חשבון
        $username = explode('@', $email)[0] . '_' . rand(100, 999);
        
        // בדוק אם השם משתמש תפוס
        $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $check->execute([$username]);
        if ($check->fetch()) {
            $username .= rand(10, 99);
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO users (
                username, email, google_id, name, profile_picture,
                auth_type, is_active, created_at, last_login
            ) VALUES (?, ?, ?, ?, ?, 'google', 1, NOW(), NOW())
        ");
        
        $stmt->execute([
            $username,
            $email,
            $google_id,
            $name,
            $picture
        ]);
        
        $user_id = $pdo->lastInsertId();
        $user_name = $name;
    }
    
    // מזהה session חדש, כדי שמזהה שהיה ידוע לפני ההתחברות
    // לא יישאר תקף אחריה
    regenerateSessionAfterLogin();

    // הגדרת סשן
    $_SESSION['user_id'] = $user_id;
    $_SESSION['username'] = $username;
    $_SESSION['name'] = $user_name;
    $_SESSION['email'] = $email;
    $_SESSION['profile_picture'] = $picture;
    $_SESSION['auth_type'] = 'google';
    $_SESSION['login_time'] = time();
    
    // החזרת תגובה
    // מפנים ליעד שנשמר לפני ההתחברות (למשל קישור הזמנה)
    $redirect = consumeLoginRedirect() ?: APP_BASE_PATH . '/dashboard.php';

    echo json_encode([
        'success' => true,
        'redirect' => $redirect,
        'message' => 'התחברת בהצלחה'
    ]);
    
} catch (Exception $e) {
    error_log('Google Auth Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'שגיאה בהתחברות: ' . $e->getMessage()
    ]);
}
?>