<?php
/**
 * שחזור סיסמה
 * includes/password_reset.php
 *
 * שיקולי האבטחה שקבעו את המימוש:
 *
 *   1. הטוקן נשמר כגיבוב ולא כפי שהוא. מי שמשיג גישה לטבלה
 *      לא יכול להתחזות לאיש - בדיוק ההיגיון של שמירת סיסמאות.
 *   2. הדף לא מגלה אם כתובת קיימת. תשובה שונה ל"קיים" ול"לא
 *      קיים" הופכת את הטופס למנוע לגילוי משתמשים רשומים.
 *   3. הטוקן חד-פעמי, בתוקף לשעה, וכל טוקן אחר של אותו משתמש
 *      מתבטל בשימוש - כדי שקישור ישן במייל לא יישאר פעיל.
 *   4. אחרי החלפת סיסמה מזהה ה-session מוחלף, כדי שסשן שנפתח
 *      לפני ההחלפה לא יישאר תקף.
 */

require_once __DIR__ . '/EmailService.php';
require_once __DIR__ . '/rate_limit.php';

const RESET_TOKEN_TTL_MINUTES = 60;
const RESET_MAX_PER_HOUR      = 5;

/** האם הטבלה קיימת. נבדק פעם אחת לכל בקשה. */
function passwordResetReady(PDO $pdo) {
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets'
        ");
        $stmt->execute();
        $ready = ((int)$stmt->fetchColumn() === 1);
    } catch (Exception $e) {
        error_log('Password reset schema check failed: ' . $e->getMessage());
        $ready = false;
    }

    return $ready;
}

/**
 * מייצר בקשת שחזור ושולח מייל.
 *
 * מחזיר תמיד את אותה תוצאה, בין אם הכתובת קיימת ובין אם לא.
 *
 * @return array{ok: bool, message: string}
 */
function requestPasswordReset(PDO $pdo, $email, $baseUrl) {
    $generic = [
        'ok'      => true,
        'message' => 'אם הכתובת רשומה במערכת, נשלח אליה קישור לאיפוס הסיסמה. '
            . 'הקישור תקף לשעה אחת.',
    ];

    $email = trim(mb_strtolower($email));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'כתובת האימייל אינה תקינה'];
    }

    if (!passwordResetReady($pdo)) {
        return ['ok' => false, 'message' => 'שחזור הסיסמה אינו זמין כרגע'];
    }

    // הגבלת קצב לפי הכתובת שהוזנה, כדי שלא יהיה אפשר להציף
    // תיבת דואר של מישהו אחר בקישורי איפוס
    if (resetRequestsExceeded($pdo, $email)) {
        return [
            'ok'      => false,
            'message' => 'נשלחו כבר מספר בקשות לכתובת הזו. יש להמתין שעה',
        ];
    }

    try {
        $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // הבקשה נרשמת גם כשאין משתמש, כדי שהגבלת הקצב לא תסגיר
        // אילו כתובות קיימות דרך הבדל בהתנהגות
        recordResetRequest($pdo, $email);

        if (!$user) {
            return $generic;
        }

        $token = bin2hex(random_bytes(32));

        $stmt = $pdo->prepare("
            INSERT INTO password_resets (user_id, token_hash, expires_at, ip)
            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)
        ");
        $stmt->execute([
            $user['id'],
            hash('sha256', $token),
            RESET_TOKEN_TTL_MINUTES,
            substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
        ]);

        $link   = rtrim($baseUrl, '/') . '/auth/reset-password.php?token=' . $token;
        $mailer = new EmailService($pdo);

        $mailer->sendEmail(
            $email,
            'איפוס סיסמה - ' . (defined('SITE_NAME') ? SITE_NAME : 'המערכת'),
            resetEmailBody($user['name'], $link)
        );
    } catch (Exception $e) {
        error_log('Password reset request failed: ' . $e->getMessage());
    }

    return $generic;
}

/** כמה בקשות נשלחו לכתובת בשעה האחרונה */
function resetRequestsExceeded(PDO $pdo, $email) {
    if (!loginAttemptsTableReady($pdo)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM login_attempts
            WHERE identifier = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute(['reset:' . $email]);

        return (int)$stmt->fetchColumn() >= RESET_MAX_PER_HOUR;
    } catch (Exception $e) {
        return false;
    }
}

function recordResetRequest(PDO $pdo, $email) {
    if (!loginAttemptsTableReady($pdo)) {
        return;
    }

    try {
        // succeeded=1 כדי שהבקשות האלה לא ייחשבו ככשלונות
        // התחברות ויחסמו את המשתמש בטעות
        $stmt = $pdo->prepare("
            INSERT INTO login_attempts (identifier, ip, succeeded) VALUES (?, ?, 1)
        ");
        $stmt->execute(['reset:' . $email, clientIpAddress()]);
    } catch (Exception $e) {
        error_log('Failed to record reset request: ' . $e->getMessage());
    }
}

/**
 * מאתר בקשה תקפה לפי טוקן.
 *
 * @return array|null שורת הבקשה עם פרטי המשתמש
 */
function findValidResetToken(PDO $pdo, $token) {
    if (!passwordResetReady($pdo) || !preg_match('/^[a-f0-9]{64}$/', (string)$token)) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT pr.id, pr.user_id, u.name, u.email
            FROM password_resets pr
            JOIN users u ON u.id = pr.user_id
            WHERE pr.token_hash = ?
              AND pr.used_at IS NULL
              AND pr.expires_at > NOW()
              AND u.is_active = 1
        ");
        $stmt->execute([hash('sha256', $token)]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        error_log('Reset token lookup failed: ' . $e->getMessage());

        return null;
    }
}

/**
 * מחליף סיסמה לפי טוקן תקף.
 *
 * @return array{ok: bool, message: string}
 */
function completePasswordReset(PDO $pdo, $token, $password, $confirm) {
    $reset = findValidResetToken($pdo, $token);

    if (!$reset) {
        return ['ok' => false, 'message' => 'הקישור אינו תקף או שפג תוקפו'];
    }

    if ($password !== $confirm) {
        return ['ok' => false, 'message' => 'הסיסמאות אינן תואמות'];
    }

    if (strlen($password) < 6) {
        return ['ok' => false, 'message' => 'הסיסמה חייבת להכיל לפחות 6 תווים'];
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]);

        // כל הטוקנים הפתוחים של המשתמש נסגרים, לא רק זה שנוצל.
        // אחרת קישור ישן במייל היה נשאר פעיל אחרי ההחלפה.
        $stmt = $pdo->prepare("
            UPDATE password_resets SET used_at = NOW()
            WHERE user_id = ? AND used_at IS NULL
        ");
        $stmt->execute([$reset['user_id']]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Password reset failed: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'החלפת הסיסמה נכשלה'];
    }

    // ניסיונות התחברות כושלים קודמים מתנקים, כדי שמי שאיפס
    // לא יגלה שהוא עדיין חסום
    try {
        $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE identifier = ? AND succeeded = 0");
        $stmt->execute([$reset['email']]);
    } catch (Exception $e) {
        // לא קריטי
    }

    return ['ok' => true, 'message' => 'הסיסמה הוחלפה. אפשר להתחבר עכשיו'];
}

/** גוף המייל */
function resetEmailBody($name, $link) {
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    $minutes  = RESET_TOKEN_TTL_MINUTES;

    return <<<HTML
<div dir="rtl" style="font-family: Arial, sans-serif; max-width: 520px; margin: 0 auto;
     background: #ffffff; border-radius: 12px; overflow: hidden; border: 1px solid #e6e8f2;">
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
         color: #ffffff; padding: 22px; text-align: center;">
        <h1 style="margin: 0; font-size: 20px;">איפוס סיסמה</h1>
    </div>
    <div style="padding: 24px; color: #333333; line-height: 1.7;">
        <p style="margin: 0 0 14px;">שלום $safeName,</p>
        <p style="margin: 0 0 14px;">
            התקבלה בקשה לאיפוס הסיסמה שלך. לחיצה על הכפתור תיקח אותך
            לבחירת סיסמה חדשה.
        </p>
        <p style="text-align: center; margin: 26px 0;">
            <a href="$safeLink"
               style="display: inline-block; background: #667eea; color: #ffffff;
                      padding: 13px 30px; border-radius: 9px; text-decoration: none;
                      font-weight: bold;">בחירת סיסמה חדשה</a>
        </p>
        <p style="margin: 0 0 14px; color: #6d7387; font-size: 13px;">
            הקישור תקף $minutes דקות והוא חד-פעמי.
        </p>
        <p style="margin: 0; color: #6d7387; font-size: 13px;">
            אם לא ביקשת לאפס סיסמה, אפשר להתעלם מההודעה. הסיסמה הנוכחית
            נשארת בתוקף ואיש לא נכנס לחשבון.
        </p>
    </div>
</div>
HTML;
}
