<?php
/**
 * בקשת איפוס סיסמה
 * auth/forgot-password.php
 */

require_once '../config.php';
require_once '../includes/session.php';
require_once '../includes/password_reset.php';

bootstrapSession();

$pdo     = getDBConnection();
$message = null;
$isError = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken()) {
        rejectRequest();
    }

    $scheme  = isHttpsRequest() ? 'https' : 'http';
    $baseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . APP_BASE_PATH;

    $result  = requestPasswordReset($pdo, $_POST['email'] ?? '', $baseUrl);
    $message = $result['message'];
    $isError = !$result['ok'];
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="he">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>איפוס סיסמה - <?php echo SITE_NAME; ?></title>
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
            <div class="join-icon"><i class="fas fa-key"></i></div>
            <h1>איפוס סיסמה</h1>

            <?php if ($message): ?>
                <p class="join-message <?php echo $isError ? 'error-text' : ''; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </p>
                <?php if (!$isError): ?>
                <a href="login.php" class="btn-primary">חזרה להתחברות</a>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (!$message || $isError): ?>
            <p class="join-message">
                הזן את כתובת המייל שאיתה נרשמת, ונשלח אליה קישור לבחירת
                סיסמה חדשה.
            </p>
            <form method="POST" class="reset-form">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label for="email">כתובת מייל</label>
                    <input type="email" id="email" name="email" dir="ltr" required
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>
                <button type="submit" class="btn-primary">
                    <i class="fas fa-paper-plane"></i> שלח קישור
                </button>
            </form>
            <p class="join-message">
                <a href="login.php">חזרה להתחברות</a>
            </p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
