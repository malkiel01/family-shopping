<?php
/**
 * דף האירועים של המשתמש
 * dashboard.php
 */

require_once 'config.php';
require_once 'includes/auth_check.php';
require_once 'includes/schema.php';

$pdo     = getDBConnection();
$user_id = $_SESSION['user_id'];

$featuresReady = eventFeaturesReady($pdo);

// ============================================================
// פעולות AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    switch ($_POST['action']) {

        case 'createGroup':
            $name        = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $eventDate   = trim($_POST['event_date'] ?? '');
            $location    = trim($_POST['event_location'] ?? '');
            $type        = ($_POST['participation_type'] ?? '') === 'fixed' ? 'fixed' : 'percentage';
            $value       = round(floatval($_POST['participation_value'] ?? 0), 2);

            if ($name === '') {
                echo json_encode(['success' => false, 'message' => 'יש להזין שם לאירוע']);
                exit;
            }
            if ($value <= 0) {
                echo json_encode(['success' => false, 'message' => 'ערך ההשתתפות חייב להיות חיובי']);
                exit;
            }
            if ($type === 'percentage' && $value > 100) {
                echo json_encode(['success' => false, 'message' => 'לא ניתן להגדיר יותר מ-100% השתתפות']);
                exit;
            }
            if ($eventDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
                echo json_encode(['success' => false, 'message' => 'תאריך לא תקין']);
                exit;
            }

            try {
                $pdo->beginTransaction();

                if ($featuresReady) {
                    $stmt = $pdo->prepare("
                        INSERT INTO purchase_groups (name, description, owner_id, event_date, event_location)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $name,
                        $description !== '' ? $description : null,
                        $user_id,
                        $eventDate !== '' ? $eventDate : null,
                        $location !== '' ? $location : null,
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO purchase_groups (name, description, owner_id) VALUES (?, ?, ?)
                    ");
                    $stmt->execute([$name, $description !== '' ? $description : null, $user_id]);
                }

                $group_id = $pdo->lastInsertId();

                $stmt = $pdo->prepare("
                    INSERT INTO group_members
                        (group_id, user_id, nickname, email, participation_type, participation_value)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $group_id, $user_id, $_SESSION['name'], $_SESSION['email'], $type, $value,
                ]);

                $pdo->commit();
                echo json_encode(['success' => true, 'group_id' => (int)$group_id]);
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("createGroup failed: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'יצירת האירוע נכשלה']);
            }
            exit;

        case 'leaveGroup':
            $group_id = intval($_POST['group_id'] ?? 0);

            $stmt = $pdo->prepare("SELECT owner_id FROM purchase_groups WHERE id = ?");
            $stmt->execute([$group_id]);
            if ((int)$stmt->fetchColumn() === (int)$user_id) {
                echo json_encode([
                    'success' => false,
                    'message' => 'מנהל האירוע אינו יכול לעזוב אותו',
                ]);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM group_purchases gp
                JOIN group_members gm ON gp.member_id = gm.id
                WHERE gm.group_id = ? AND gm.user_id = ?
            ");
            $stmt->execute([$group_id, $user_id]);

            if ($stmt->fetchColumn() > 0) {
                echo json_encode([
                    'success' => false,
                    'message' => 'לא ניתן לעזוב אירוע שרשומות על שמך בו קניות',
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE group_members SET is_active = 0 WHERE group_id = ? AND user_id = ?
                ");
                echo json_encode(['success' => $stmt->execute([$group_id, $user_id])]);
            }
            exit;

        case 'respondInvitation':
            $invitation_id = intval($_POST['invitation_id'] ?? 0);
            $response      = $_POST['response'] ?? '';

            if (!in_array($response, ['accept', 'reject'], true)) {
                echo json_encode(['success' => false, 'message' => 'תגובה לא תקינה']);
                exit;
            }

            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    SELECT * FROM group_invitations
                    WHERE id = ? AND email = ? AND status = 'pending'
                ");
                $stmt->execute([$invitation_id, $_SESSION['email']]);
                $invitation = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$invitation) {
                    throw new Exception('הזמנה לא נמצאה או כבר טופלה');
                }

                if ($response === 'accept') {
                    $stmt = $pdo->prepare("
                        SELECT id FROM group_members WHERE group_id = ? AND user_id = ?
                    ");
                    $stmt->execute([$invitation['group_id'], $user_id]);
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
                            $invitation['group_id'], $user_id,
                            $invitation['nickname'], $_SESSION['email'],
                            $invitation['participation_type'], $invitation['participation_value'],
                        ]);
                    }
                }

                $stmt = $pdo->prepare("
                    UPDATE group_invitations SET status = ?, responded_at = NOW() WHERE id = ?
                ");
                $stmt->execute([
                    $response === 'accept' ? 'accepted' : 'rejected',
                    $invitation_id,
                ]);

                $pdo->commit();
                echo json_encode([
                    'success'  => true,
                    'group_id' => $response === 'accept' ? (int)$invitation['group_id'] : null,
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;

        default:
            echo json_encode(['success' => false, 'message' => 'פעולה לא מוכרת']);
            exit;
    }
}

// ============================================================
// שליפת נתונים
// ============================================================

// הסטטיסטיקות מחושבות ישירות במקום להישען על טבלת סיכום
// שעלולה להיות לא מסונכרנת.
$eventColumns = $featuresReady
    ? "pg.event_date, pg.event_location, pg.status,
       (SELECT COUNT(*) FROM shopping_items si
        WHERE si.group_id = pg.id AND si.status <> 'bought') AS open_items"
    : "NULL AS event_date, NULL AS event_location, 'active' AS status, 0 AS open_items";

$stmt = $pdo->prepare("
    SELECT
        pg.id, pg.name, pg.description, pg.owner_id, pg.created_at,
        $eventColumns,
        gm.nickname,
        gm.participation_type,
        gm.participation_value,
        u.name AS owner_name,
        (pg.owner_id = ?) AS is_owner,
        (SELECT COUNT(*) FROM group_members m2
         WHERE m2.group_id = pg.id AND m2.is_active = 1) AS member_count,
        (SELECT COUNT(*) FROM group_purchases p2
         WHERE p2.group_id = pg.id) AS purchase_count,
        (SELECT COALESCE(SUM(p3.amount), 0) FROM group_purchases p3
         WHERE p3.group_id = pg.id) AS total_amount
    FROM purchase_groups pg
    JOIN group_members gm ON pg.id = gm.group_id
    JOIN users u ON pg.owner_id = u.id
    WHERE gm.user_id = ? AND gm.is_active = 1 AND pg.is_active = 1
    ORDER BY pg.created_at DESC
");
$stmt->execute([$user_id, $user_id]);
$allGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);

// אירועים פעילים לפני סגורים; בתוך הפעילים - הקרוב ביותר ראשון
$activeGroups = [];
$closedGroups = [];
foreach ($allGroups as $group) {
    if (($group['status'] ?? 'active') === 'closed') {
        $closedGroups[] = $group;
    } else {
        $activeGroups[] = $group;
    }
}

usort($activeGroups, function ($a, $b) {
    $aDate = $a['event_date'] ?? null;
    $bDate = $b['event_date'] ?? null;

    // אירועים עם תאריך קודמים לאלה שבלי
    if ($aDate && $bDate) return strcmp($aDate, $bDate);
    if ($aDate) return -1;
    if ($bDate) return 1;

    return strcmp($b['created_at'], $a['created_at']);
});

// --- הזמנות ממתינות ---
$stmt = $pdo->prepare("
    SELECT gi.*, pg.name AS group_name
    FROM group_invitations gi
    JOIN purchase_groups pg ON gi.group_id = pg.id
    WHERE gi.email = ? AND gi.status = 'pending' AND pg.is_active = 1
    ORDER BY gi.created_at DESC
");
$stmt->execute([$_SESSION['email']]);
$invitations = $stmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * מציג את תאריך האירוע יחסית להיום.
 */
function eventDateLabel($date) {
    if (empty($date)) {
        return null;
    }

    $days = (int)floor((strtotime($date) - strtotime('today')) / 86400);
    $formatted = date('d/m/Y', strtotime($date));

    if ($days === 0)  return "$formatted · היום!";
    if ($days === 1)  return "$formatted · מחר";
    if ($days > 1)    return "$formatted · עוד $days ימים";

    return $formatted;
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="he">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>האירועים שלי - <?php echo SITE_NAME; ?></title>

    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <!-- Font Awesome נטען בלי לחסום רינדור: בלי זה הדף נשאר לבן
         עד שהבקשה החוצה ל-CDN חוזרת, וזה מה שנראה כהבזק ברענון. -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    <link rel="stylesheet" href="<?php echo asset('/css/dashboard.css'); ?>">

    <link rel="manifest" href="<?php echo APP_BASE_PATH; ?>/manifest.json">
    <meta name="theme-color" content="#667eea">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">

    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo APP_BASE_PATH; ?>/images/icons/ios/32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo APP_BASE_PATH; ?>/images/icons/ios/16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo APP_BASE_PATH; ?>/images/icons/ios/180.png">
</head>
<body>
    <nav class="navbar">
        <div class="navbar-container">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-calendar-check"></i>
                האירועים שלי
            </a>
            <div class="navbar-user">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php if (!empty($_SESSION['profile_picture'])): ?>
                            <img src="<?php echo htmlspecialchars($_SESSION['profile_picture']); ?>" alt="">
                        <?php else: ?>
                            <?php echo htmlspecialchars(mb_substr($_SESSION['name'], 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <span class="user-name"><?php echo htmlspecialchars($_SESSION['name']); ?></span>
                </div>
                <a href="auth/logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    התנתק
                </a>
            </div>
        </div>
    </nav>

    <div class="container">
        <?php if (!$featuresReady): ?>
            <?php renderMigrationNotice(); ?>
        <?php endif; ?>

        <?php if (count($invitations) > 0): ?>
        <div class="invitations-section">
            <h2><i class="fas fa-envelope"></i> הזמנות ממתינות</h2>
            <div class="invitations-grid">
                <?php foreach ($invitations as $invitation): ?>
                <div class="invitation-card">
                    <h3><?php echo htmlspecialchars($invitation['group_name']); ?></h3>
                    <p>כינוי: <?php echo htmlspecialchars($invitation['nickname']); ?></p>
                    <p>השתתפות:
                        <?php if ($invitation['participation_type'] === 'percentage'): ?>
                            <?php echo $invitation['participation_value']; ?>%
                        <?php else: ?>
                            ₪<?php echo number_format($invitation['participation_value'], 2); ?>
                        <?php endif; ?>
                    </p>
                    <div class="invitation-actions">
                        <button class="btn-accept" onclick="respondInvitation(<?php echo (int)$invitation['id']; ?>, 'accept')">
                            <i class="fas fa-check"></i> קבל
                        </button>
                        <button class="btn-reject" onclick="respondInvitation(<?php echo (int)$invitation['id']; ?>, 'reject')">
                            <i class="fas fa-times"></i> דחה
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="create-group-section">
            <button class="btn-create-group" onclick="showCreateGroupModal()">
                <i class="fas fa-plus-circle"></i>
                צור אירוע חדש
            </button>
        </div>

        <div class="groups-section">
            <h2><i class="fas fa-calendar-day"></i> אירועים פעילים</h2>

            <?php if (count($activeGroups) === 0): ?>
            <div class="no-groups">
                <i class="fas fa-calendar-plus"></i>
                <p>אין לך אירועים פעילים</p>
                <p>צור אירוע חדש או המתן להזמנה</p>
            </div>
            <?php else: ?>
            <div class="groups-grid">
                <?php foreach ($activeGroups as $group): ?>
                    <?php include 'includes/group_card.php'; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if (count($closedGroups) > 0): ?>
        <div class="groups-section closed-section">
            <h2><i class="fas fa-box-archive"></i> אירועים שנסגרו</h2>
            <div class="groups-grid">
                <?php foreach ($closedGroups as $group): ?>
                    <?php include 'includes/group_card.php'; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Modal ליצירת אירוע -->
    <div id="createGroupModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>יצירת אירוע חדש</h2>
                <span class="close" onclick="closeCreateGroupModal()">&times;</span>
            </div>
            <form id="createGroupForm">
                <div class="form-group">
                    <label for="groupName">שם האירוע:</label>
                    <input type="text" id="groupName" placeholder="לדוגמה: ראש השנה אצל סבתא" required>
                </div>
                <div class="form-group">
                    <label for="groupDescription">תיאור (אופציונלי):</label>
                    <textarea id="groupDescription" rows="2"></textarea>
                </div>
                <?php if ($featuresReady): ?>
                <div class="form-row">
                    <div class="form-group">
                        <label for="groupEventDate">תאריך:</label>
                        <input type="date" id="groupEventDate">
                    </div>
                    <div class="form-group">
                        <label for="groupEventLocation">מיקום:</label>
                        <input type="text" id="groupEventLocation" placeholder="אצל סבתא">
                    </div>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label>סוג ההשתתפות שלך:</label>
                    <div class="radio-group">
                        <label>
                            <input type="radio" name="ownerParticipationType" value="percentage" checked
                                   onchange="toggleOwnerParticipationType()">
                            אחוז
                        </label>
                        <label>
                            <input type="radio" name="ownerParticipationType" value="fixed"
                                   onchange="toggleOwnerParticipationType()">
                            סכום קבוע
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="ownerParticipationValue">ערך ההשתתפות שלך:</label>
                    <div class="input-with-suffix">
                        <input type="number" id="ownerParticipationValue" step="0.01" min="0.01" required>
                        <span id="ownerValueSuffix">%</span>
                    </div>
                    <small class="form-hint">
                        אפשר לשנות בהמשך, ויש כפתור "חלוקה שווה" בתוך האירוע
                    </small>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-plus"></i> צור אירוע
                    </button>
                    <button type="button" class="btn-secondary" onclick="closeCreateGroupModal()">
                        ביטול
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        window.APP_CONFIG = {
            csrfToken:     <?php echo json_encode($_SESSION['csrf_token']); ?>,
            userId:        <?php echo (int)$user_id; ?>,
            basePath:      <?php echo json_encode(APP_BASE_PATH); ?>,
            featuresReady: <?php echo $featuresReady ? 'true' : 'false'; ?>
        };
    </script>
    <script src="<?php echo asset('/js/notification-system.js'); ?>"></script>
    <script src="<?php echo asset('/js/dashboard.js'); ?>"></script>

    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker
                    .register('<?php echo APP_BASE_PATH; ?>/service-worker.js',
                              { scope: '<?php echo APP_BASE_PATH; ?>/' })
                    .catch(err => console.error('Service Worker registration failed:', err));
            });
        }
    </script>
</body>
</html>
