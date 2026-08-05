<?php
/**
 * הגבלת קצב על ניסיונות התחברות
 * includes/rate_limit.php
 *
 * בלי ההגבלה הזו אפשר לנחש סיסמאות ללא סוף. הספירה נעשית בשני
 * מימדים במקביל: לפי כתובת IP, ולפי שם המשתמש שמנסים להתחבר אליו.
 * המימד השני חשוב כי תוקף שמתחלף בין כתובות IP עדיין חוסם את עצמו
 * מול חשבון ספציפי.
 *
 * אם הטבלה עוד לא נוצרה (המיגרציה לא הורצה), המודול לא חוסם כלום -
 * עדיף אתר שעובד בלי הגבלת קצב מאשר אתר שאי אפשר להתחבר אליו.
 */

const LOGIN_WINDOW_MINUTES  = 15;  // חלון הזמן שבו נספרים כשלונות
const LOGIN_MAX_PER_IP      = 15;  // מקסימום כשלונות מאותה כתובת
const LOGIN_MAX_PER_ACCOUNT = 5;   // מקסימום כשלונות מול אותו חשבון

/**
 * כתובת ה-IP של הפונה, מקוצרת לאורך שנכנס לעמודה.
 */
function clientIpAddress() {
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

/**
 * האם טבלת הניסיונות קיימת. נבדק פעם אחת לכל בקשה.
 */
function loginAttemptsTableReady(PDO $pdo) {
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts'
        ");
        $stmt->execute();
        $ready = ((int)$stmt->fetchColumn() === 1);
    } catch (Exception $e) {
        error_log('Rate limit check failed: ' . $e->getMessage());
        $ready = false;
    }

    return $ready;
}

/**
 * כמה שניות נותרו עד שמותר לנסות שוב, או 0 אם מותר עכשיו.
 *
 * @param string $identifier שם המשתמש או האימייל שהוזן
 */
function loginBlockedFor(PDO $pdo, $identifier) {
    if (!loginAttemptsTableReady($pdo)) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                SUM(ip = ?)         AS by_ip,
                SUM(identifier = ?) AS by_account,
                MIN(attempted_at)   AS oldest
            FROM login_attempts
            WHERE succeeded = 0
              AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
              AND (ip = ? OR identifier = ?)
        ");
        $stmt->execute([
            clientIpAddress(), $identifier, LOGIN_WINDOW_MINUTES,
            clientIpAddress(), $identifier,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['oldest'] === null) {
            return 0;
        }

        $blocked = ((int)$row['by_ip'] >= LOGIN_MAX_PER_IP)
                || ((int)$row['by_account'] >= LOGIN_MAX_PER_ACCOUNT);

        if (!$blocked) {
            return 0;
        }

        // החסימה משתחררת כשהניסיון הישן ביותר יוצא מחלון הזמן
        $freeAt = strtotime($row['oldest']) + (LOGIN_WINDOW_MINUTES * 60);

        return max(1, $freeAt - time());
    } catch (Exception $e) {
        error_log('Rate limit query failed: ' . $e->getMessage());

        return 0;
    }
}

/**
 * רושם ניסיון התחברות. התחברות מוצלחת מנקה את הכשלונות הקודמים,
 * כדי שמשתמש שנזכר בסיסמה לא יישאר חסום.
 */
function recordLoginAttempt(PDO $pdo, $identifier, $succeeded) {
    if (!loginAttemptsTableReady($pdo)) {
        return;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO login_attempts (identifier, ip, succeeded)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([
            substr((string)$identifier, 0, 190),
            clientIpAddress(),
            $succeeded ? 1 : 0,
        ]);

        if ($succeeded) {
            $stmt = $pdo->prepare("
                DELETE FROM login_attempts
                WHERE succeeded = 0 AND (identifier = ? OR ip = ?)
            ");
            $stmt->execute([substr((string)$identifier, 0, 190), clientIpAddress()]);
        }

        // ניקוי אקראי של רשומות ישנות, כדי שהטבלה לא תתפח
        if (random_int(1, 50) === 1) {
            $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        }
    } catch (Exception $e) {
        error_log('Failed to record login attempt: ' . $e->getMessage());
    }
}

/**
 * הודעה למשתמש החסום, בדקות מעוגלות כלפי מעלה.
 */
function loginBlockedMessage($seconds) {
    $minutes = max(1, (int)ceil($seconds / 60));

    return "יותר מדי ניסיונות התחברות. נסה שוב בעוד $minutes דקות";
}
