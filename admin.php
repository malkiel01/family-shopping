<?php
/**
 * פלטפורמת ניהול
 * admin.php
 *
 * נגישה רק למי שמסומן כמנהל בעמודה users.is_admin. ההרשאה
 * נבדקת מול מסד הנתונים בכל בקשה, ולא מתוך ה-session.
 */

require_once 'config.php';
require_once 'includes/auth_check.php';
require_once 'includes/admin.php';

$pdo     = getDBConnection();
$user_id = $_SESSION['user_id'];

requireSystemAdmin($pdo, $user_id);

// ============================================================
// פעולות AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    switch ($_POST['action']) {

        case 'forceAccept':
            $result = adminForceAcceptInvitation($pdo, $user_id, intval($_POST['invitation_id'] ?? 0));
            echo json_encode([
                'success' => $result['ok'],
                'message' => $result['message'],
            ], JSON_UNESCAPED_UNICODE);
            exit;

        case 'userGroups':
            $target = intval($_POST['user_id'] ?? 0);
            echo json_encode([
                'success' => true,
                'groups'  => adminUserGroups($pdo, $target),
            ], JSON_UNESCAPED_UNICODE);
            exit;
    }

    echo json_encode(['success' => false, 'message' => 'פעולה לא מוכרת'], JSON_UNESCAPED_UNICODE);
    exit;
}

$overview = adminOverview($pdo);
$users    = adminListUsers($pdo);
?>
<!DOCTYPE html>
<html dir="rtl" lang="he">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ניהול המערכת - <?php echo SITE_NAME; ?></title>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    <link rel="stylesheet" href="<?php echo asset('/css/dashboard.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('/css/contacts.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('/css/admin.css'); ?>">
    <meta name="theme-color" content="#667eea">
</head>
<body>
    <nav class="navbar">
        <div class="navbar-container">
            <a href="dashboard.php" class="btn-back">
                <i class="fas fa-arrow-right"></i> חזרה
            </a>
            <span class="navbar-title">ניהול המערכת</span>
            <a href="auth/logout.php" class="btn-logout" title="התנתק">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </nav>

    <div class="container">
        <div class="admin-stats">
            <div class="admin-stat">
                <span class="admin-stat-value"><?php echo $overview['users']; ?></span>
                <span class="admin-stat-label">משתמשים</span>
            </div>
            <div class="admin-stat">
                <span class="admin-stat-value"><?php echo $overview['groups']; ?></span>
                <span class="admin-stat-label">אירועים</span>
            </div>
            <div class="admin-stat">
                <span class="admin-stat-value"><?php echo $overview['members']; ?></span>
                <span class="admin-stat-label">חברויות</span>
            </div>
            <div class="admin-stat <?php echo $overview['pending'] > 0 ? 'warn' : ''; ?>">
                <span class="admin-stat-value"><?php echo $overview['pending']; ?></span>
                <span class="admin-stat-label">הזמנות ממתינות</span>
            </div>
        </div>

        <div class="contacts-search">
            <i class="fas fa-search"></i>
            <input type="search" id="userSearch" placeholder="חיפוש משתמש לפי שם או אימייל"
                   oninput="filterUsers()" autocomplete="off">
        </div>

        <div class="admin-users" id="usersList">
            <?php foreach ($users as $user): ?>
            <div class="admin-user"
                 data-name="<?php echo htmlspecialchars(mb_strtolower((string)$user['name'])); ?>"
                 data-email="<?php echo htmlspecialchars(mb_strtolower((string)$user['email'])); ?>">
                <button class="admin-user-head" onclick="toggleUser(<?php echo (int)$user['id']; ?>)">
                    <div class="contact-avatar">
                        <?php echo htmlspecialchars(mb_substr((string)$user['name'], 0, 1)); ?>
                    </div>
                    <div class="admin-user-info">
                        <h3>
                            <?php echo htmlspecialchars((string)$user['name']); ?>
                            <?php if ($user['is_admin']): ?>
                                <span class="contact-badge registered">מנהל</span>
                            <?php endif; ?>
                            <?php if (!$user['is_active']): ?>
                                <span class="contact-badge">מושבת</span>
                            <?php endif; ?>
                        </h3>
                        <p class="contact-email"><?php echo htmlspecialchars((string)$user['email']); ?></p>
                        <p class="contact-meta">
                            <span class="contact-badge"><?php echo (int)$user['owned_groups']; ?> מנהל</span>
                            <span class="contact-badge"><?php echo (int)$user['member_groups']; ?> חבר</span>
                            <?php if ($user['pending_invitations'] > 0): ?>
                                <span class="contact-badge pending">
                                    <?php echo (int)$user['pending_invitations']; ?> ממתינות
                                </span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <i class="fas fa-chevron-down admin-chevron" id="chevron-<?php echo (int)$user['id']; ?>"></i>
                </button>
                <div class="admin-user-body" id="user-<?php echo (int)$user['id']; ?>"></div>
            </div>
            <?php endforeach; ?>
        </div>

        <p class="contacts-no-results" id="noUsers" style="display: none;">
            לא נמצא משתמש תואם
        </p>
    </div>

    <script>
        window.APP_CONFIG = {
            csrfToken: <?php echo json_encode($_SESSION['csrf_token']); ?>,
            basePath:  <?php echo json_encode(APP_BASE_PATH); ?>
        };
    </script>
    <script src="<?php echo asset('/js/admin.js'); ?>"></script>
</body>
</html>
