<?php
/**
 * בחירת סיסמה חדשה לפי טוקן
 * auth/reset-password.php
 */

require_once '../config.php';
require_once '../includes/session.php';
require_once '../includes/password_reset.php';

bootstrapSession();

$pdo   = getDBConnection();
$token = $_GET['token'] ?? ($_POST['token'] ?? '');

$reset   = findValidResetToken($pdo, $token);
$message = null;
$isError = false;
$done    = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reset) {
    if (!verifyCsrfToken()) {
        rejectRequest();
    }

    $result = completePasswordReset(
        $pdo, $token,
        $_POST['password'] ?? '',
        $_POST['confirm'] ?? ''
    );

    $message = $result['message'];
    $isError = !$result['ok'];
    $done    = $result['ok'];

    if ($done) {
        // הסשן הנוכחי מוחלף, כדי שסשן שנפתח לפני החלפת הסיסמה
        // לא יישאר תקף אחריה
        regenerateSessionAfterLogin();
    }
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="he">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>בחירת סיסמה חדשה - <?php echo SITE_NAME; ?></title>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    <link rel="stylesheet" href="<?php echo asset('/css/styles.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('/css/join.css'); ?>">
    <meta name="theme-color" content="#667eea">
</head>
<body>
    <div class="join-container">
        <div class="join-card">

            <?php if ($done): ?>
                <div class="join-icon"><i class="fas fa-circle-check"></i></div>
                <h1>הסיסמה הוחלפה</h1>
                <p class="join-message"><?php echo htmlspecialchars($message); ?></p>
                <a href="login.php" class="btn-primary">להתחברות</a>

            <?php elseif (!$reset): ?>
                <div class="join-icon error"><i class="fas fa-link-slash"></i></div>
                <h1>הקישור אינו תקף</h1>
                <p class="join-message">
                    ייתכן שפג תוקפו, שכבר נעשה בו שימוש, או שכבר ביקשת קישור חדש.
                </p>
                <a href="forgot-password.php" class="btn-primary">בקשת קישור חדש</a>

            <?php else: ?>
                <div class="join-icon"><i class="fas fa-lock-open"></i></div>
                <h1>בחירת סיסמה חדשה</h1>
                <p class="join-message">
                    שלום <?php echo htmlspecialchars($reset['name']); ?>,
                    בחר סיסמה חדשה לחשבון שלך.
                </p>

                <?php if ($message && $isError): ?>
                <p class="join-message error-text"><?php echo htmlspecialchars($message); ?></p>
                <?php endif; ?>

                <form method="POST" class="reset-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    <div class="form-group">
                        <label for="password">סיסמה חדשה</label>
                        <input type="password" id="password" name="password" required minlength="6">
                    </div>
                    <div class="form-group">
                        <label for="confirm">אימות סיסמה</label>
                        <input type="password" id="confirm" name="confirm" required minlength="6">
                    </div>
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i> שמור סיסמה
                    </button>
                </form>
            <?php endif; ?>

        </div>
    </div>
</body>
</html>
