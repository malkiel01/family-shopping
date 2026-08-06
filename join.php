<?php
/**
 * הצטרפות לאירוע דרך קישור הזמנה
 * join.php
 *
 * הדף היחיד שנגיש גם למי שעדיין לא רשום: הוא נוחת כאן,
 * נשלח להרשמה, וחוזר לכאן אוטומטית כדי לאשר את ההזמנה.
 */

require_once 'config.php';
require_once 'includes/session.php';
require_once 'includes/notifications.php';

$pdo   = getDBConnection();
$token = trim($_GET['token'] ?? '');

$error      = null;
$invitation = null;
$done       = null;

if ($token === '') {
    $error = 'קישור ההזמנה חסר או שגוי';
} else {
    $stmt = $pdo->prepare("
        SELECT gi.*, pg.name AS group_name, pg.description AS group_description,
               pg.event_date, pg.event_location, u.name AS inviter_name
        FROM group_invitations gi
        JOIN purchase_groups pg ON pg.id = gi.group_id
        LEFT JOIN users u ON u.id = gi.invited_by
        WHERE gi.token = ?
    ");
    $stmt->execute([$token]);
    $invitation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invitation) {
        $error = 'ההזמנה לא נמצאה. ייתכן שהקישור שגוי';
    } elseif ($invitation['status'] === 'accepted') {
        $error = 'ההזמנה כבר אושרה';
    } elseif ($invitation['status'] !== 'pending') {
        $error = 'ההזמנה כבר אינה בתוקף';
    }
}

// לא מחובר - נשלח להתחברות ונחזור לכאן
if (!$error && !isset($_SESSION['user_id'])) {
    $_SESSION['redirect_after_login'] = APP_BASE_PATH . '/join.php?token=' . urlencode($token);
    header('Location: ' . APP_BASE_PATH . '/auth/login.php?invited=1');
    exit;
}

// ההזמנה נשלחה לכתובת אחרת מזו שאיתה התחברנו
$emailMismatch = false;
if (!$error && strcasecmp($invitation['email'], $_SESSION['email'] ?? '') !== 0) {
    $emailMismatch = true;
}

// --- טיפול בתגובה ---
if (!$error && !$emailMismatch && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken()) {
        rejectRequest();
    }

    $response = $_POST['response'] ?? '';
    $userId   = (int)$_SESSION['user_id'];

    if ($response === 'accept') {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                SELECT id FROM group_members WHERE group_id = ? AND user_id = ?
            ");
            $stmt->execute([$invitation['group_id'], $userId]);
            $existing = $stmt->fetch();

            if ($existing) {
                $stmt = $pdo->prepare("
                    UPDATE group_members
                    SET is_active = 1, nickname = ?, email = ?,
                        participation_type = ?, participation_value = ?, joined_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $invitation['nickname'], $_SESSION['email'],
                    $invitation['participation_type'], $invitation['participation_value'],
                    $existing['id'],
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO group_members
                        (group_id, user_id, nickname, email, participation_type, participation_value)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $invitation['group_id'], $userId,
                    $invitation['nickname'], $_SESSION['email'],
                    $invitation['participation_type'], $invitation['participation_value'],
                ]);
            }

            $stmt = $pdo->prepare("
                UPDATE group_invitations SET status = 'accepted', responded_at = NOW() WHERE id = ?
            ");
            $stmt->execute([$invitation['id']]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Join failed: " . $e->getMessage());
            $error = 'ההצטרפות נכשלה. נסו שוב';
        }

        if (!$error) {
            notifyGroupOwner(
                $pdo, $invitation['group_id'], $userId, 'invitation_accepted',
                'הצטרפות לאירוע אושרה',
                sprintf(
                    '%s הצטרף ל"%s"',
                    $_SESSION['name'] ?? $invitation['nickname'],
                    groupNameById($pdo, $invitation['group_id'])
                )
            );

            header('Location: ' . APP_BASE_PATH . '/group.php?id=' . (int)$invitation['group_id']);
            exit;
        }
    } elseif ($response === 'reject') {
        $stmt = $pdo->prepare("
            UPDATE group_invitations SET status = 'rejected', responded_at = NOW() WHERE id = ?
        ");
        $stmt->execute([$invitation['id']]);

        notifyGroupOwner(
            $pdo, $invitation['group_id'], $userId, 'invitation_rejected',
            'הזמנה נדחתה',
            sprintf(
                '%s דחה את ההזמנה ל"%s"',
                $_SESSION['name'] ?? $invitation['nickname'],
                groupNameById($pdo, $invitation['group_id'])
            )
        );

        $done = 'ההזמנה נדחתה';
    }
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="he">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>הזמנה לאירוע - <?php echo SITE_NAME; ?></title>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <!-- Font Awesome נטען בלי לחסום רינדור: בלי זה הדף נשאר לבן
         עד שהבקשה החוצה ל-CDN חוזרת, וזה מה שנראה כהבזק ברענון. -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    <link rel="stylesheet" href="<?php echo asset('/css/join.css'); ?>">
    <meta name="theme-color" content="#667eea">
</head>
<body>
    <div class="join-container">
        <div class="join-card">
            <?php if ($error): ?>
                <div class="join-icon error"><i class="fas fa-circle-exclamation"></i></div>
                <h1>אופס</h1>
                <p class="join-message"><?php echo htmlspecialchars($error); ?></p>
                <a href="<?php echo APP_BASE_PATH; ?>/dashboard.php" class="btn-primary">
                    לאירועים שלי
                </a>

            <?php elseif ($done): ?>
                <div class="join-icon"><i class="fas fa-circle-check"></i></div>
                <h1><?php echo htmlspecialchars($done); ?></h1>
                <a href="<?php echo APP_BASE_PATH; ?>/dashboard.php" class="btn-primary">
                    לאירועים שלי
                </a>

            <?php elseif ($emailMismatch): ?>
                <div class="join-icon error"><i class="fas fa-user-lock"></i></div>
                <h1>ההזמנה שייכת לחשבון אחר</h1>
                <p class="join-message">
                    ההזמנה נשלחה לכתובת
                    <strong class="email-ltr"><?php echo htmlspecialchars($invitation['email']); ?></strong>,
                    אבל התחברת עם
                    <strong class="email-ltr"><?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?></strong>.
                </p>
                <p class="join-message">
                    התחברו עם הכתובת שאליה נשלחה ההזמנה, או בקשו ממנהל האירוע
                    לשלוח הזמנה חדשה לכתובת הנוכחית.
                </p>
                <a href="<?php echo APP_BASE_PATH; ?>/auth/logout.php" class="btn-primary">
                    התחבר עם חשבון אחר
                </a>

            <?php else: ?>
                <div class="join-icon"><i class="fas fa-envelope-open-text"></i></div>
                <h1>הוזמנת לאירוע</h1>

                <div class="join-event">
                    <h2><?php echo htmlspecialchars($invitation['group_name']); ?></h2>
                    <?php if (!empty($invitation['group_description'])): ?>
                    <p class="event-description"><?php echo htmlspecialchars($invitation['group_description']); ?></p>
                    <?php endif; ?>
                    <div class="join-meta">
                        <?php if (!empty($invitation['event_date'])): ?>
                        <span><i class="fas fa-calendar-day"></i>
                            <?php echo date('d/m/Y', strtotime($invitation['event_date'])); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($invitation['event_location'])): ?>
                        <span><i class="fas fa-map-marker-alt"></i>
                            <?php echo htmlspecialchars($invitation['event_location']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($invitation['inviter_name'])): ?>
                        <span><i class="fas fa-user"></i>
                            הוזמנת על ידי <?php echo htmlspecialchars($invitation['inviter_name']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="join-terms">
                    <div class="term">
                        <span class="term-label">הכינוי שלך באירוע</span>
                        <span class="term-value"><?php echo htmlspecialchars($invitation['nickname']); ?></span>
                    </div>
                    <div class="term">
                        <span class="term-label">חלקך בהוצאות</span>
                        <span class="term-value">
                            <?php if ($invitation['participation_type'] === 'percentage'): ?>
                                <?php echo $invitation['participation_value']; ?>%
                            <?php else: ?>
                                ₪<?php echo number_format($invitation['participation_value'], 2); ?> (קבוע)
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

                <form method="post" class="join-actions">
                    <?php echo csrf_field(); ?>
                    <button type="submit" name="response" value="accept" class="btn-primary">
                        <i class="fas fa-check"></i> מצטרף
                    </button>
                    <button type="submit" name="response" value="reject" class="btn-secondary">
                        <i class="fas fa-times"></i> לא מצטרף
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
