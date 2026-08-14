<?php
/**
 * פלטפורמת ניהול
 * admin.php
 *
 * נגישה רק למי שמסומן כמנהל בעמודה users.is_admin. ההרשאה
 * נבדקת מול מסד הנתונים בכל בקשה, ולא מתוך ה-session.
 *
 * המסך מחולק לאזורים, וכל אזור עונה על שאלה אחרת:
 *
 *   סקירה       מה מצב המערכת ברגע זה
 *   משתמשים     מי רשום, מה ההרשאות שלו, ובאילו אירועים
 *   אירועים     מה קורה בכל אירוע, וצירוף או מחיקה
 *   מערכת       איך ההתקנה מוגדרת, ומה עובר בערוצי היציאה
 *   יומן        מי עשה מה, ומתי
 *   תחזוקה      מיגרציות וניקוי - האזור היחיד שמשנה את ההתקנה
 */

require_once 'config.php';
require_once 'includes/auth_check.php';
require_once 'includes/admin.php';
require_once 'includes/admin_system.php';
require_once 'includes/admin_invitations.php';
require_once 'includes/admin_export.php';

$pdo     = getDBConnection();
$user_id = $_SESSION['user_id'];

requireSystemAdmin($pdo, $user_id);

// ============================================================
// ייצוא - חייב לרוץ לפני כל פלט, כי הוא שולח כותרות הורדה
// ============================================================
if (isset($_GET['export'])) {
    handleExportRequest($pdo, $user_id, $_GET['export'], $_GET['format'] ?? 'csv');
}

// ============================================================
// פעולות AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    /** תשובה אחידה לפעולות שמחזירות ok+message */
    $respond = function (array $result, array $extra = []) {
        echo json_encode(array_merge([
            'success' => $result['ok'],
            'message' => $result['message'],
        ], $extra), JSON_UNESCAPED_UNICODE);
        exit;
    };

    switch ($_POST['action']) {

        case 'forceAccept':
            $respond(adminForceAcceptInvitation($pdo, $user_id, intval($_POST['invitation_id'] ?? 0)));

        case 'userGroups':
            $target = intval($_POST['user_id'] ?? 0);
            echo json_encode([
                'success' => true,
                'groups'  => adminUserGroups($pdo, $target),
            ], JSON_UNESCAPED_UNICODE);
            exit;

        case 'groupDetail':
            echo json_encode(array_merge(
                ['success' => true],
                adminGroupDetail($pdo, intval($_POST['group_id'] ?? 0))
            ), JSON_UNESCAPED_UNICODE);
            exit;

        case 'deleteGroup':
            $respond(adminDeleteGroup(
                $pdo, $user_id,
                intval($_POST['group_id'] ?? 0),
                $_POST['mode'] ?? '',
                $_POST['confirm_name'] ?? ''
            ));

        case 'addToGroup':
            $respond(adminAddUserToGroup(
                $pdo, $user_id,
                intval($_POST['group_id'] ?? 0),
                intval($_POST['target_user_id'] ?? 0),
                $_POST['participation_type'] ?? 'shares',
                $_POST['participation_value'] ?? 1
            ));

        case 'setAdmin':
            $respond(adminSetUserAdmin(
                $pdo, $user_id,
                intval($_POST['user_id'] ?? 0),
                ($_POST['value'] ?? '') === '1'
            ));

        case 'setActive':
            $respond(adminSetUserActive(
                $pdo, $user_id,
                intval($_POST['user_id'] ?? 0),
                ($_POST['value'] ?? '') === '1'
            ));

        case 'runMigrations':
            $result = adminRunMigrations($pdo, $user_id);
            echo json_encode([
                'success' => true,
                'message' => sprintf(
                    'בוצעו %d | דילוגים %d | כשלונות %d',
                    $result['applied'], $result['skipped'], $result['failed']
                ),
                'log' => $result['log'],
            ], JSON_UNESCAPED_UNICODE);
            exit;

        case 'maintenance':
            $respond(runMaintenanceTask($pdo, $user_id, $_POST['task'] ?? ''));

        case 'resendInvitation':
            $respond(adminResendInvitation($pdo, $user_id, intval($_POST['invitation_id'] ?? 0)));

        case 'cancelInvitation':
            $respond(adminCancelInvitation($pdo, $user_id, intval($_POST['invitation_id'] ?? 0)));

        case 'cancelStaleInvitations':
            $respond(adminCancelStaleInvitations($pdo, $user_id, intval($_POST['days'] ?? 30)));
    }

    echo json_encode(['success' => false, 'message' => 'פעולה לא מוכרת'], JSON_UNESCAPED_UNICODE);
    exit;
}

$overview    = adminOverview($pdo);
$users       = adminListUsers($pdo);
$groups      = adminListGroups($pdo);
$health      = systemHealthChecks($pdo);
$info        = systemInfo($pdo);
$stats       = notificationStats($pdo);
$emails      = recentEmails($pdo);
$auditLog    = recentAdminActions($pdo);
$migrations  = migrationStatus($pdo, DB_NAME);
$tasks       = maintenanceTasks();
$invitations = adminListInvitations($pdo);
$inviteCount = adminInvitationCounts($pdo);
$datasets    = exportDatasets();

/** כמה בדיקות תקינות אינן במצב תקין */
$healthIssues = count(array_filter($health, function ($check) {
    return $check['state'] !== 'ok';
}));

$sections = [
    'overview'    => ['סקירה',         'fa-gauge-high'],
    'users'       => ['משתמשים',       'fa-users'],
    'groups'      => ['אירועים',       'fa-calendar-check'],
    'invitations' => ['הזמנות',        'fa-envelope-open-text'],
    'system'      => ['מערכת',         'fa-server'],
    'export'      => ['ייצוא נתונים',  'fa-file-arrow-down'],
    'log'         => ['יומן פעולות',   'fa-clipboard-list'],
    'maintenance' => ['תחזוקה ופיתוח', 'fa-screwdriver-wrench'],
];

/** מספר, או "—" כשהערך אינו זמין */
function adminNum($value) {
    return $value === null ? '—' : number_format((int)$value);
}

/**
 * סכום כסף למלבן סטטיסטיקה.
 *
 * מעוגל לשקלים שלמים - אגורות בסכום כלל-מערכתי הן רעש - ומקוצר
 * ל-K או M מעל אלף, כדי שהמספר לא יישפך מהמלבן ויישאר קריא
 * במסך של טלפון.
 */
function adminMoney($value) {
    if ($value === null) {
        return '—';
    }

    $value  = (float)$value;
    $symbol = CURRENCY_SYMBOL;

    if (abs($value) >= 1000000) {
        return $symbol . number_format($value / 1000000, 1) . 'M';
    }

    if (abs($value) >= 10000) {
        return $symbol . number_format($value / 1000, 1) . 'K';
    }

    return $symbol . number_format($value);
}
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
        <nav class="admin-nav">
            <?php foreach ($sections as $key => [$label, $icon]): ?>
            <button class="admin-tab<?php echo $key === 'overview' ? ' active' : ''; ?>"
                    id="tab-<?php echo $key; ?>" onclick="showAdminTab('<?php echo $key; ?>')">
                <i class="fas <?php echo $icon; ?>"></i>
                <span><?php echo $label; ?></span>
                <?php if ($key === 'maintenance' && $migrations['pending'] > 0): ?>
                    <span class="admin-nav-dot" title="יש מיגרציות ממתינות"></span>
                <?php elseif ($key === 'overview' && $healthIssues > 0): ?>
                    <span class="admin-nav-dot" title="<?php echo $healthIssues; ?> בדיקות דורשות תשומת לב"></span>
                <?php elseif ($key === 'invitations' && $inviteCount['stale'] > 0): ?>
                    <span class="admin-nav-dot" title="<?php echo $inviteCount['stale']; ?> הזמנות תקועות"></span>
                <?php endif; ?>
            </button>
            <?php endforeach; ?>
        </nav>

        <!-- ============================================ סקירה -->
        <div class="admin-pane" id="pane-overview">
            <div class="admin-stats">
                <div class="admin-stat">
                    <span class="admin-stat-value"><?php echo adminNum($overview['users']); ?></span>
                    <span class="admin-stat-label">משתמשים</span>
                </div>
                <div class="admin-stat">
                    <span class="admin-stat-value"><?php echo adminNum($overview['groups']); ?></span>
                    <span class="admin-stat-label">אירועים</span>
                </div>
                <div class="admin-stat">
                    <span class="admin-stat-value"><?php echo adminNum($overview['members']); ?></span>
                    <span class="admin-stat-label">חברויות</span>
                </div>
                <div class="admin-stat <?php echo $overview['pending'] > 0 ? 'warn' : ''; ?>">
                    <span class="admin-stat-value"><?php echo adminNum($overview['pending']); ?></span>
                    <span class="admin-stat-label">הזמנות ממתינות</span>
                </div>
                <div class="admin-stat">
                    <span class="admin-stat-value"><?php echo adminNum($overview['purchases']); ?></span>
                    <span class="admin-stat-label">קניות</span>
                </div>
                <div class="admin-stat" title="<?php
                    echo htmlspecialchars(CURRENCY_SYMBOL . number_format((float)$overview['spent'], 2));
                ?>">
                    <span class="admin-stat-value"><?php echo adminMoney($overview['spent']); ?></span>
                    <span class="admin-stat-label">סך ההוצאות</span>
                </div>
                <div class="admin-stat">
                    <span class="admin-stat-value"><?php echo adminNum($overview['items']); ?></span>
                    <span class="admin-stat-label">פריטי רשימה</span>
                </div>
                <div class="admin-stat">
                    <span class="admin-stat-value"><?php echo adminNum($overview['contacts']); ?></span>
                    <span class="admin-stat-label">אנשי קשר</span>
                </div>
            </div>

            <div class="admin-card">
                <h2 class="admin-card-title">
                    <i class="fas fa-heart-pulse"></i> תקינות ההתקנה
                    <?php if ($healthIssues === 0): ?>
                        <span class="admin-pill ok">הכל תקין</span>
                    <?php else: ?>
                        <span class="admin-pill warn"><?php echo $healthIssues; ?> לבדיקה</span>
                    <?php endif; ?>
                </h2>

                <ul class="admin-checks">
                    <?php foreach ($health as $check): ?>
                    <li class="admin-check <?php echo $check['state']; ?>">
                        <i class="fas <?php
                            echo $check['state'] === 'ok'   ? 'fa-circle-check'
                               : ($check['state'] === 'warn' ? 'fa-triangle-exclamation' : 'fa-circle-xmark');
                        ?>"></i>
                        <div>
                            <strong><?php echo htmlspecialchars($check['label']); ?></strong>
                            <span><?php echo htmlspecialchars($check['detail']); ?></span>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="admin-card">
                <h2 class="admin-card-title"><i class="fas fa-chart-simple"></i> תנועה אחרונה</h2>
                <div class="admin-mini-stats">
                    <div><span><?php echo adminNum($stats['queue'] === null ? null : ($stats['queue']['pending'] ?? 0)); ?></span>התראות ממתינות</div>
                    <div><span><?php echo adminNum($stats['push_active']); ?></span>מנויי Push פעילים</div>
                    <div><span><?php echo adminNum($stats['mail_sent']); ?></span>מיילים ב-7 ימים</div>
                    <div class="<?php echo (int)$stats['mail_failed'] > 0 ? 'bad' : ''; ?>">
                        <span><?php echo adminNum($stats['mail_failed']); ?></span>מיילים שנכשלו
                    </div>
                    <div class="<?php echo (int)$stats['logins_failed'] > 0 ? 'bad' : ''; ?>">
                        <span><?php echo adminNum($stats['logins_failed']); ?></span>התחברויות שנכשלו היום
                    </div>
                    <div><span><?php echo adminNum($overview['admins']); ?></span>מנהלי מערכת</div>
                </div>
            </div>
        </div><!-- pane-overview -->

        <!-- ========================================= משתמשים -->
        <div class="admin-pane" id="pane-users" hidden>
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
                                <?php if ($user['last_login']): ?>
                                    <span class="contact-uses">
                                        כניסה: <?php echo htmlspecialchars(substr((string)$user['last_login'], 0, 10)); ?>
                                    </span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <i class="fas fa-chevron-down admin-chevron" id="chevron-<?php echo (int)$user['id']; ?>"></i>
                    </button>

                    <div class="admin-user-body" id="user-<?php echo (int)$user['id']; ?>"
                         data-admin="<?php echo (int)$user['is_admin']; ?>"
                         data-active="<?php echo (int)$user['is_active']; ?>"
                         data-self="<?php echo (int)$user['id'] === (int)$user_id ? 1 : 0; ?>"></div>
                </div>
                <?php endforeach; ?>
            </div>

            <p class="contacts-no-results" id="noUsers" style="display: none;">
                לא נמצא משתמש תואם
            </p>
        </div><!-- pane-users -->

        <!-- ========================================== אירועים -->
        <div class="admin-pane" id="pane-groups" hidden>
            <div class="contacts-search">
                <i class="fas fa-search"></i>
                <input type="search" id="groupSearch" placeholder="חיפוש אירוע לפי שם או מנהל"
                       oninput="filterGroups()" autocomplete="off">
            </div>

            <div class="admin-users" id="groupsList">
                <?php foreach ($groups as $group): ?>
                <div class="admin-user"
                     data-name="<?php echo htmlspecialchars(mb_strtolower((string)$group['name'])); ?>"
                     data-owner="<?php echo htmlspecialchars(mb_strtolower((string)$group['owner_name'])); ?>">
                    <button class="admin-user-head" onclick="toggleGroup(<?php echo (int)$group['id']; ?>)">
                        <div class="contact-avatar">
                            <?php echo htmlspecialchars(mb_substr((string)$group['name'], 0, 1)); ?>
                        </div>
                        <div class="admin-user-info">
                            <h3>
                                <?php echo htmlspecialchars((string)$group['name']); ?>
                                <?php if ($group['status'] === 'closed'): ?>
                                    <span class="contact-badge">סגור</span>
                                <?php endif; ?>
                                <?php if (!$group['is_active']): ?>
                                    <span class="contact-badge">מושבת</span>
                                <?php endif; ?>
                            </h3>
                            <p class="admin-owner-line">
                                מנהל: <?php echo htmlspecialchars((string)$group['owner_name']); ?>
                            </p>
                            <p class="contact-meta">
                                <span class="contact-badge"><?php echo (int)$group['member_count']; ?> משתתפים</span>
                                <?php if ($group['pending_count'] > 0): ?>
                                    <span class="contact-badge pending">
                                        <?php echo (int)$group['pending_count']; ?> ממתינות
                                    </span>
                                <?php endif; ?>
                                <?php if ($group['event_date']): ?>
                                    <span class="contact-uses"><?php echo htmlspecialchars($group['event_date']); ?></span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <i class="fas fa-chevron-down admin-chevron" id="gchevron-<?php echo (int)$group['id']; ?>"></i>
                    </button>
                    <div class="admin-user-body" id="group-<?php echo (int)$group['id']; ?>"></div>
                </div>
                <?php endforeach; ?>
            </div>

            <p class="contacts-no-results" id="noGroups" style="display: none;">
                לא נמצא אירוע תואם
            </p>
        </div><!-- pane-groups -->

        <!-- ========================================== הזמנות -->
        <div class="admin-pane" id="pane-invitations" hidden>
            <div class="admin-card">
                <h2 class="admin-card-title">
                    <i class="fas fa-envelope-open-text"></i> הזמנות בכל האירועים
                    <?php if ($inviteCount['stale'] > 0): ?>
                        <span class="admin-pill warn"><?php echo $inviteCount['stale']; ?> תקועות</span>
                    <?php endif; ?>
                </h2>

                <p class="admin-note">
                    הזמנה שנתקעה לא מייצרת שגיאה ואף אחד לא מתלונן עליה — מנהל
                    האירוע בכלל לא יודע שהיא לא הגיעה. הזמנה שממתינה מעל שבוע
                    מסומנת כתקועה.
                </p>

                <div class="admin-mini-stats">
                    <div><span><?php echo adminNum($inviteCount['pending']); ?></span>ממתינות</div>
                    <div class="<?php echo $inviteCount['stale'] > 0 ? 'bad' : ''; ?>">
                        <span><?php echo adminNum($inviteCount['stale']); ?></span>מעל שבוע
                    </div>
                    <div><span><?php echo adminNum($inviteCount['accepted']); ?></span>התקבלו</div>
                    <div><span><?php echo adminNum($inviteCount['rejected']); ?></span>נדחו</div>
                    <div><span><?php echo adminNum($inviteCount['expired']); ?></span>בוטלו</div>
                </div>

                <?php if ($inviteCount['stale'] > 0): ?>
                <div class="admin-actions-row">
                    <button class="btn-secondary" onclick="cancelStaleInvitations()">
                        <i class="fas fa-broom"></i> בטל הזמנות שממתינות מעל 30 יום
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <div class="contacts-search">
                <i class="fas fa-search"></i>
                <input type="search" id="inviteSearch" placeholder="חיפוש לפי אימייל, שם או אירוע"
                       oninput="filterInvitations()" autocomplete="off">
            </div>

            <div class="admin-filter-row" id="inviteFilters">
                <?php
                $inviteFilters = [
                    'pending'  => 'ממתינות',
                    'stale'    => 'תקועות',
                    'accepted' => 'התקבלו',
                    'rejected' => 'נדחו',
                    'expired'  => 'בוטלו',
                    'all'      => 'הכל',
                ];
                foreach ($inviteFilters as $value => $label):
                ?>
                <button class="admin-chip<?php echo $value === 'pending' ? ' active' : ''; ?>"
                        data-filter="<?php echo $value; ?>" onclick="setInviteFilter('<?php echo $value; ?>')">
                    <?php echo $label; ?>
                </button>
                <?php endforeach; ?>
            </div>

            <div class="admin-users" id="invitationsList">
                <?php foreach ($invitations as $invite):
                    $isStale = ($invite['status'] === 'pending' && (int)$invite['age_days'] >= 7);
                ?>
                <div class="admin-invite<?php echo $isStale ? ' stale' : ''; ?>"
                     data-status="<?php echo htmlspecialchars((string)$invite['status']); ?>"
                     data-stale="<?php echo $isStale ? '1' : '0'; ?>"
                     data-search="<?php echo htmlspecialchars(mb_strtolower(
                         $invite['email'] . ' ' . $invite['nickname'] . ' ' . $invite['group_name']
                     )); ?>">
                    <div class="admin-invite-main">
                        <div class="admin-invite-head">
                            <strong><?php echo htmlspecialchars((string)($invite['nickname'] ?: $invite['email'])); ?></strong>
                            <span class="contact-badge status-<?php echo htmlspecialchars((string)$invite['status']); ?>">
                                <?php echo htmlspecialchars(invitationStatusLabel((string)$invite['status'])); ?>
                            </span>
                            <?php if ($isStale): ?>
                                <span class="contact-badge pending">
                                    <?php echo (int)$invite['age_days']; ?> ימים
                                </span>
                            <?php endif; ?>
                            <?php if (!$invite['group_active']): ?>
                                <span class="contact-badge">האירוע נמחק</span>
                            <?php endif; ?>
                        </div>
                        <p class="contact-email"><?php echo htmlspecialchars((string)$invite['email']); ?></p>
                        <p class="admin-invite-meta">
                            <?php echo htmlspecialchars((string)($invite['group_name'] ?: 'אירוע שנמחק')); ?>
                            <?php if ($invite['inviter_name']): ?>
                                · הזמין <?php echo htmlspecialchars((string)$invite['inviter_name']); ?>
                            <?php endif; ?>
                            · <?php echo htmlspecialchars(substr((string)$invite['created_at'], 0, 10)); ?>
                            <?php if (!$invite['invitee_user_id']): ?>
                                <span class="contact-badge">טרם נרשם למערכת</span>
                            <?php endif; ?>
                        </p>
                    </div>

                    <?php if ($invite['status'] === 'pending'): ?>
                    <div class="admin-invite-actions">
                        <button class="btn-force" title="העתק קישור הצטרפות"
                                data-link="<?php echo htmlspecialchars((string)$invite['link'], ENT_QUOTES); ?>"
                                onclick="copyInviteLink(this)">
                            <i class="fas fa-link"></i> העתק קישור
                        </button>
                        <button class="btn-force neutral" title="שלח את מייל ההזמנה מחדש"
                                onclick="resendInvitation(<?php echo (int)$invite['id']; ?>)">
                            <i class="fas fa-paper-plane"></i> שלח שוב
                        </button>
                        <?php if ($invite['invitee_user_id']): ?>
                        <button class="btn-force" onclick="forceAccept(<?php echo (int)$invite['id']; ?>)">
                            <i class="fas fa-user-check"></i> אשר בשמו
                        </button>
                        <?php endif; ?>
                        <button class="btn-purge-admin" onclick="cancelInvitation(<?php echo (int)$invite['id']; ?>)">
                            <i class="fas fa-xmark"></i> בטל
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (count($invitations) >= INVITATIONS_LIMIT): ?>
            <p class="admin-note">
                מוצגות <?php echo INVITATIONS_LIMIT; ?> ההזמנות האחרונות בלבד.
                לרשימה המלאה השתמש בייצוא הנתונים.
            </p>
            <?php endif; ?>

            <p class="contacts-no-results" id="noInvitations" style="display: none;">
                אין הזמנות שתואמות לסינון
            </p>
        </div><!-- pane-invitations -->

        <!-- =========================================== מערכת -->
        <div class="admin-pane" id="pane-system" hidden>
            <div class="admin-card">
                <h2 class="admin-card-title"><i class="fas fa-circle-info"></i> פרטי ההתקנה</h2>
                <dl class="admin-info">
                    <?php foreach ($info as $label => $value): ?>
                        <dt><?php echo htmlspecialchars($label); ?></dt>
                        <dd><?php echo htmlspecialchars((string)$value); ?></dd>
                    <?php endforeach; ?>
                </dl>
            </div>

            <div class="admin-card">
                <h2 class="admin-card-title"><i class="fas fa-bell"></i> תור ההתראות</h2>
                <?php if ($stats['queue'] === null): ?>
                    <p class="admin-loading">הטבלה עוד לא נוצרה. הרץ את המיגרציות מאזור התחזוקה.</p>
                <?php else: ?>
                    <div class="admin-mini-stats">
                        <?php
                        $queueLabels = [
                            'pending'   => 'ממתינות',
                            'completed' => 'נשלחו',
                            'failed'    => 'נכשלו',
                            'read'      => 'נקראו',
                            'sent'      => 'ישן: sent',
                        ];
                        foreach ($stats['queue'] as $status => $count):
                        ?>
                        <div class="<?php echo $status === 'failed' && $count > 0 ? 'bad' : ''; ?>">
                            <span><?php echo adminNum($count); ?></span>
                            <?php echo $queueLabels[$status] ?? htmlspecialchars($status); ?>
                        </div>
                        <?php endforeach; ?>
                        <div class="<?php echo (int)$stats['stuck'] > 0 ? 'bad' : ''; ?>">
                            <span><?php echo adminNum($stats['stuck']); ?></span>תקועות מעל יממה
                        </div>
                    </div>
                    <?php if ((int)$stats['stuck'] > 0): ?>
                        <p class="admin-note">
                            התראות שממתינות מעל יממה מעידות בדרך כלל על כך שה-cron
                            אינו רץ. ההתראות עדיין יוצגו בתוך האפליקציה.
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="admin-card">
                <h2 class="admin-card-title">
                    <i class="fas fa-mobile-screen"></i> מנויי Push
                </h2>
                <div class="admin-mini-stats">
                    <div><span><?php echo adminNum($stats['push_active']); ?></span>פעילים</div>
                    <div><span><?php echo adminNum($stats['push_total']); ?></span>סך הכל</div>
                </div>
            </div>

            <div class="admin-card">
                <h2 class="admin-card-title"><i class="fas fa-envelope"></i> המיילים האחרונים</h2>
                <?php if (!$emails): ?>
                    <p class="admin-loading">אין רישומים. או שלא נשלחו מיילים, או שהטבלה עוד לא נוצרה.</p>
                <?php else: ?>
                    <ul class="admin-rows">
                        <?php foreach ($emails as $mail): ?>
                        <li class="<?php echo $mail['status'] === 'failed' ? 'bad' : ''; ?>">
                            <span class="admin-row-main"><?php echo htmlspecialchars((string)$mail['subject']); ?></span>
                            <span class="admin-row-sub"><?php echo htmlspecialchars((string)$mail['to_email']); ?></span>
                            <span class="admin-row-time"><?php echo htmlspecialchars((string)$mail['sent_at']); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div><!-- pane-system -->

        <!-- ============================================ ייצוא -->
        <div class="admin-pane" id="pane-export" hidden>
            <div class="admin-card">
                <h2 class="admin-card-title"><i class="fas fa-file-arrow-down"></i> ייצוא נתונים</h2>

                <p class="admin-note">
                    CSV נפתח ישירות באקסל, כולל עברית. JSON שומר את המבנה המלא
                    ומתאים להעברה למערכת אחרת. כל ייצוא נרשם ביומן הפעולות.
                </p>

                <p class="admin-note">
                    <strong>שום סוד אינו נכלל בייצוא:</strong> לא סיסמאות, לא
                    טוקני הזמנה, לא טוקני איפוס ולא מפתחות Push. קובץ שיורד
                    מהדפדפן ממשיך למקומות שהשרת לא שולט בהם, ואסור שאפשר יהיה
                    להתחזות בעזרתו.
                </p>

                <div class="admin-tasks">
                    <?php foreach ($datasets as $key => $dataset): ?>
                    <div class="admin-task">
                        <div class="admin-task-text">
                            <strong><?php echo htmlspecialchars($dataset['title']); ?></strong>
                            <span><?php echo htmlspecialchars($dataset['detail']); ?></span>
                        </div>
                        <div class="admin-export-buttons">
                            <a class="btn-secondary"
                               href="?export=<?php echo urlencode($key); ?>&amp;format=csv">CSV</a>
                            <a class="btn-secondary"
                               href="?export=<?php echo urlencode($key); ?>&amp;format=json">JSON</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="admin-card">
                <h2 class="admin-card-title"><i class="fas fa-box-archive"></i> גיבוי מלא</h2>
                <p class="admin-note">
                    כל מערכי הנתונים בקובץ JSON אחד. מערך שהשאילתה שלו נכשלה
                    נכלל כ-<code>null</code> ולא מושמט — גיבוי שנראה שלם אבל
                    אינו, גרוע מגיבוי חלקי שמצהיר על עצמו.
                </p>
                <p class="admin-note">
                    זה אינו תחליף לגיבוי מסד הנתונים: הוא אינו כולל את תמונות
                    הקבלות שב-<code>uploads/</code>, ואי אפשר לשחזר ממנו את
                    המערכת בלחיצה.
                </p>
                <div class="admin-actions-row">
                    <a class="btn-primary" href="?export=full&amp;format=json">
                        <i class="fas fa-download"></i> הורד גיבוי מלא
                    </a>
                </div>
            </div>
        </div><!-- pane-export -->

        <!-- ============================================= יומן -->
        <div class="admin-pane" id="pane-log" hidden>
            <div class="admin-card">
                <h2 class="admin-card-title">
                    <i class="fas fa-clipboard-list"></i> פעולות ניהול אחרונות
                </h2>
                <p class="admin-note">
                    כל פעולה שמנהל מבצע בשם משתמש אחר נרשמת כאן. היומן אינו ניתן
                    למחיקה מהממשק, בכוונה.
                </p>

                <?php if (!$auditLog): ?>
                    <p class="admin-loading">עוד לא נרשמה אף פעולה</p>
                <?php else: ?>
                    <ul class="admin-rows">
                        <?php foreach ($auditLog as $entry): ?>
                        <li>
                            <span class="admin-row-main">
                                <?php echo htmlspecialchars(adminActionLabel((string)$entry['action'])); ?>
                                <?php if ($entry['target_type']): ?>
                                    <span class="contact-badge">
                                        <?php echo htmlspecialchars((string)$entry['target_type']); ?>
                                        <?php echo $entry['target_id'] ? '#' . (int)$entry['target_id'] : ''; ?>
                                    </span>
                                <?php endif; ?>
                            </span>
                            <span class="admin-row-sub">
                                <?php echo htmlspecialchars((string)($entry['admin_name'] ?? 'משתמש שנמחק')); ?>
                                <?php if ($entry['details']): ?>
                                    — <?php echo htmlspecialchars((string)$entry['details']); ?>
                                <?php endif; ?>
                            </span>
                            <span class="admin-row-time"><?php echo htmlspecialchars((string)$entry['created_at']); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div><!-- pane-log -->

        <!-- ================================== תחזוקה ופיתוח -->
        <div class="admin-pane" id="pane-maintenance" hidden>
            <div class="admin-card">
                <h2 class="admin-card-title">
                    <i class="fas fa-database"></i> מיגרציות
                    <?php if ($migrations['pending'] === 0): ?>
                        <span class="admin-pill ok">מעודכן</span>
                    <?php else: ?>
                        <span class="admin-pill warn"><?php echo $migrations['pending']; ?> ממתינים</span>
                    <?php endif; ?>
                </h2>

                <p class="admin-note">
                    כל צעד נבדק לפני שהוא מורץ, ולכן הרצה חוזרת אינה מזיקה.
                    צעד שכבר בוצע פשוט ידולג. אותן מיגרציות רצות גם דרך
                    <code>php db/migrate.php</code> בטרמינל.
                </p>

                <div class="admin-actions-row">
                    <button class="btn-primary" id="runMigrationsBtn" onclick="runMigrations()">
                        <i class="fas fa-play"></i> הרץ מיגרציות ממתינות
                    </button>
                    <span class="admin-run-status" id="migrationsStatus"></span>
                </div>

                <div id="migrationsOutput"></div>

                <ul class="admin-migrations">
                    <?php foreach ($migrations['migrations'] as $migration): ?>
                    <li class="<?php echo $migration['pending'] > 0 ? 'has-pending' : ''; ?>">
                        <div class="admin-migration-head">
                            <strong><?php echo htmlspecialchars($migration['id']); ?></strong>
                            <span><?php echo htmlspecialchars($migration['title']); ?></span>
                            <?php if ($migration['pending'] > 0): ?>
                                <span class="admin-pill warn"><?php echo $migration['pending']; ?> ממתינים</span>
                            <?php else: ?>
                                <span class="admin-pill ok">בוצע</span>
                            <?php endif; ?>
                        </div>
                        <ul class="admin-migration-steps">
                            <?php foreach ($migration['steps'] as $step): ?>
                            <li class="<?php echo $step['pending'] ? 'pending' : 'done'; ?>">
                                <i class="fas <?php echo $step['pending'] ? 'fa-circle-dot' : 'fa-check'; ?>"></i>
                                <?php echo htmlspecialchars($step['label']); ?>
                                <?php if (!($step['critical'])): ?>
                                    <span class="contact-badge">לא קריטי</span>
                                <?php endif; ?>
                                <?php if ($step['error']): ?>
                                    <span class="admin-step-error"><?php echo htmlspecialchars($step['error']); ?></span>
                                <?php endif; ?>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="admin-card">
                <h2 class="admin-card-title"><i class="fas fa-broom"></i> ניקוי</h2>
                <p class="admin-note">
                    הפעולות כאן נוגעות ביומנים, בתור ובקבצים בלבד. אף אחת מהן
                    אינה משנה אירועים, קניות או חברויות.
                </p>

                <div class="admin-tasks">
                    <?php foreach ($tasks as $key => $task): ?>
                    <div class="admin-task<?php echo !empty($task['danger']) ? ' danger' : ''; ?>">
                        <div class="admin-task-text">
                            <strong><?php echo htmlspecialchars($task['title']); ?></strong>
                            <span><?php echo htmlspecialchars($task['detail']); ?></span>
                        </div>
                        <button class="btn-secondary"
                                data-task="<?php echo htmlspecialchars($key, ENT_QUOTES); ?>"
                                <?php if ($task['confirm']): ?>
                                data-confirm="<?php echo htmlspecialchars($task['confirm'], ENT_QUOTES); ?>"
                                <?php endif; ?>
                                onclick="runMaintenance(this)">
                            הרץ
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="admin-card">
                <h2 class="admin-card-title"><i class="fas fa-terminal"></i> הרצה מהטרמינל</h2>
                <p class="admin-note">
                    מה שאפשר לעשות מכאן, אפשר גם משורת הפקודה - ושם גם רואים
                    את הפלט המלא.
                </p>
                <ul class="admin-commands">
                    <li><code>php db/migrate.php</code><span>מיגרציות</span></li>
                    <li><code>php cron/process-notifications.php</code><span>שליחת ההתראות שבתור</span></li>
                    <li><code>php tests/calculations_test.php</code><span>בדיקות מנוע החישוב</span></li>
                    <li><code>php tests/actions_test.php</code><span>בדיקות שכבת הפעולות</span></li>
                    <li><code>php tests/webpush_test.php</code><span>בדיקות ההצפנה והחתימה</span></li>
                </ul>
            </div>
        </div><!-- pane-maintenance -->
    </div>

    <!-- צירוף משתמש לאירוע -->
    <div id="addToGroupModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>צירוף משתתף לאירוע</h2>
                <span class="close" onclick="closeAddToGroup()">&times;</span>
            </div>
            <form id="addToGroupForm">
                <input type="hidden" id="addGroupId">
                <p class="admin-modal-context" id="addGroupName"></p>
                <div class="form-group">
                    <label for="addUserSelect">משתמש:</label>
                    <select id="addUserSelect" required></select>
                </div>
                <div class="form-group">
                    <label>איך הוא משתתף?</label>
                    <div class="type-picker">
                        <input type="radio" name="addType" id="addType_shares" value="shares" checked
                               onchange="toggleAddType()">
                        <label for="addType_shares"><i class="fas fa-users"></i><span>נפשות</span></label>
                        <input type="radio" name="addType" id="addType_percentage" value="percentage"
                               onchange="toggleAddType()">
                        <label for="addType_percentage"><i class="fas fa-percentage"></i><span>אחוז</span></label>
                        <input type="radio" name="addType" id="addType_fixed" value="fixed"
                               onchange="toggleAddType()">
                        <label for="addType_fixed"><i class="fas fa-shekel-sign"></i><span>סכום קבוע</span></label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="addValue" id="addValueLabel">כמה נפשות?</label>
                    <div class="input-with-suffix">
                        <input type="number" id="addValue" step="1" min="1" value="1" required>
                        <span id="addValueSuffix">נפשות</span>
                    </div>
                    <small class="form-hint">
                        המשתמש ומנהל האירוע יקבלו התראה, והפעולה תירשם ביומן הניהול
                    </small>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-user-check"></i> צרף
                    </button>
                    <button type="button" class="btn-secondary" onclick="closeAddToGroup()">ביטול</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        window.APP_CONFIG = {
            csrfToken: <?php echo json_encode($_SESSION['csrf_token']); ?>,
            basePath:  <?php echo json_encode(APP_BASE_PATH); ?>,
            sections:  <?php echo json_encode(array_keys($sections)); ?>
        };
    </script>
    <script src="<?php echo asset('/js/admin.js'); ?>"></script>
</body>
</html>
