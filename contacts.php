<?php
/**
 * ניהול אנשי קשר
 * contacts.php
 *
 * הרשימה שייכת למשתמש ולא לאירוע מסוים: כל מי שהוזמן אי פעם
 * לאירוע שהוא מנהל נמצא כאן, ומשם אפשר לצרף אותו לאירוע חדש
 * בלי להקליד שוב אימייל וכינוי.
 */

require_once 'config.php';
require_once 'includes/auth_check.php';
require_once 'includes/contacts.php';

$pdo     = getDBConnection();
$user_id = $_SESSION['user_id'];

// ============================================================
// פעולות AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    if (!contactsTableReady($pdo)) {
        echo json_encode([
            'success' => false,
            'message' => 'אנשי הקשר עוד לא זמינים - יש להריץ את עדכון מסד הנתונים',
        ]);
        exit;
    }

    switch ($_POST['action']) {

        case 'saveContact':
            $email = trim(mb_strtolower($_POST['email'] ?? ''));
            $name  = trim($_POST['name'] ?? '');
            $type  = $_POST['participation_type'] ?? '';
            $value = round(floatval($_POST['participation_value'] ?? 0), 2);

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'כתובת האימייל אינה תקינה']);
                exit;
            }
            if ($name === '') {
                echo json_encode(['success' => false, 'message' => 'יש להזין שם']);
                exit;
            }

            $ok = rememberContact(
                $pdo, $user_id, $email, $name,
                in_array($type, PARTICIPATION_TYPES, true) ? $type : null,
                $value > 0 ? $value : null
            );

            echo json_encode(['success' => $ok]);
            exit;

        case 'updateContact':
            $id    = intval($_POST['contact_id'] ?? 0);
            $name  = trim($_POST['name'] ?? '');
            $type  = $_POST['participation_type'] ?? '';
            $value = round(floatval($_POST['participation_value'] ?? 0), 2);

            if ($name === '') {
                echo json_encode(['success' => false, 'message' => 'יש להזין שם']);
                exit;
            }

            echo json_encode(['success' => updateContact($pdo, $user_id, $id, $name, $type, $value)]);
            exit;

        case 'deleteContact':
            $id = intval($_POST['contact_id'] ?? 0);
            echo json_encode(['success' => deleteContact($pdo, $user_id, $id)]);
            exit;

        case 'importContacts':
            $added = importContactsFromHistory($pdo, $user_id);
            echo json_encode([
                'success' => true,
                'added'   => $added,
                'message' => $added > 0
                    ? "נוספו $added אנשי קשר מהאירועים שלך"
                    : 'לא נמצאו אנשי קשר חדשים לייבוא',
            ]);
            exit;
    }

    echo json_encode(['success' => false, 'message' => 'פעולה לא מוכרת']);
    exit;
}

$ready    = contactsTableReady($pdo);
$contacts = $ready ? listContacts($pdo, $user_id) : [];
?>
<!DOCTYPE html>
<html dir="rtl" lang="he">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>אנשי קשר - <?php echo SITE_NAME; ?></title>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    <link rel="stylesheet" href="<?php echo asset('/css/dashboard.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('/css/contacts.css'); ?>">
    <link rel="manifest" href="<?php echo APP_BASE_PATH; ?>/manifest.json">
    <meta name="theme-color" content="#667eea">
</head>
<body>
    <nav class="navbar">
        <div class="navbar-container">
            <a href="dashboard.php" class="btn-back">
                <i class="fas fa-arrow-right"></i> חזרה
            </a>
            <span class="navbar-title">אנשי קשר</span>
            <a href="auth/logout.php" class="btn-logout" title="התנתק">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </nav>

    <div class="container">
        <?php if (!$ready): ?>
        <div class="migration-notice">
            <i class="fas fa-database"></i>
            <div>
                <strong>עדכון מסד הנתונים ממתין</strong>
                <p>אנשי הקשר יופעלו אחרי הרצת <code>db/migrate.php</code>.</p>
            </div>
        </div>
        <?php else: ?>

        <div class="contacts-head">
            <div class="contacts-count">
                <?php echo count($contacts); ?> אנשי קשר
            </div>
            <div class="contacts-head-actions">
                <button class="btn-gear" onclick="importFromHistory()"
                        title="ייבוא מהאירועים שלי" aria-label="ייבוא מהאירועים שלי">
                    <i class="fas fa-rotate"></i>
                </button>
                <button class="btn-add" onclick="openContactModal()">
                    <i class="fas fa-user-plus"></i> איש קשר חדש
                </button>
            </div>
        </div>

        <?php if (count($contacts) === 0): ?>
        <div class="contacts-empty">
            <i class="fas fa-address-book"></i>
            <h2>עוד אין אנשי קשר</h2>
            <p>
                כל מי שתזמין לאירוע ייכנס לכאן אוטומטית.
                אפשר גם לייבא את כל מי שהוזמן לאירועים הקודמים שלך.
            </p>
            <button class="btn-primary" onclick="importFromHistory()">
                <i class="fas fa-rotate"></i> ייבא מהאירועים שלי
            </button>
        </div>
        <?php else: ?>

        <div class="contacts-search">
            <i class="fas fa-search"></i>
            <input type="search" id="contactSearch" placeholder="חיפוש לפי שם או אימייל"
                   oninput="filterContacts()" autocomplete="off">
        </div>

        <div class="contacts-list" id="contactsList">
            <?php foreach ($contacts as $contact): ?>
            <div class="contact-card"
                 data-name="<?php echo htmlspecialchars(mb_strtolower($contact['name'])); ?>"
                 data-email="<?php echo htmlspecialchars($contact['email']); ?>">
                <div class="contact-avatar">
                    <?php echo htmlspecialchars(mb_substr($contact['name'], 0, 1)); ?>
                </div>
                <div class="contact-info">
                    <h3><?php echo htmlspecialchars($contact['name']); ?></h3>
                    <p class="contact-email"><?php echo htmlspecialchars($contact['email']); ?></p>
                    <p class="contact-meta">
                        <?php if ($contact['is_registered']): ?>
                            <span class="contact-badge registered">
                                <i class="fas fa-check"></i> רשום במערכת
                            </span>
                        <?php else: ?>
                            <span class="contact-badge">טרם נרשם</span>
                        <?php endif; ?>
                        <?php if ($contact['default_participation_type']): ?>
                            <span class="contact-badge">
                                <?php echo htmlspecialchars(participationLabel(
                                    $contact['default_participation_type'],
                                    $contact['default_participation_value']
                                )); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($contact['times_used'] > 0): ?>
                            <span class="contact-uses"><?php echo (int)$contact['times_used']; ?> אירועים</span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="member-actions">
                    <button class="btn-edit" title="ערוך"
                            onclick="editContact(<?php echo (int)$contact['id']; ?>,
                                <?php echo htmlspecialchars(json_encode($contact['name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>,
                                <?php echo htmlspecialchars(json_encode($contact['email']), ENT_QUOTES); ?>,
                                <?php echo htmlspecialchars(json_encode($contact['default_participation_type']), ENT_QUOTES); ?>,
                                <?php echo (float)$contact['default_participation_value']; ?>)">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn-remove" title="מחק"
                            onclick="removeContact(<?php echo (int)$contact['id']; ?>)">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <p class="contacts-no-results" id="noResults" style="display: none;">
            לא נמצא איש קשר תואם
        </p>

        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- הוספה ועריכה -->
    <div id="contactModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="contactModalTitle">איש קשר חדש</h2>
                <span class="close" onclick="closeContactModal()">&times;</span>
            </div>
            <form id="contactForm">
                <input type="hidden" id="contactId">
                <div class="form-group">
                    <label for="contactName">שם:</label>
                    <input type="text" id="contactName" required>
                </div>
                <div class="form-group">
                    <label for="contactEmail">אימייל:</label>
                    <input type="email" id="contactEmail" dir="ltr" required>
                </div>
                <div class="form-group">
                    <label>ברירת מחדל להשתתפות (לא חובה):</label>
                    <div class="type-picker">
                        <input type="radio" name="contactType" id="contactType_shares" value="shares"
                               onchange="toggleContactType()">
                        <label for="contactType_shares"><i class="fas fa-users"></i><span>נפשות</span></label>
                        <input type="radio" name="contactType" id="contactType_percentage" value="percentage"
                               onchange="toggleContactType()">
                        <label for="contactType_percentage"><i class="fas fa-percentage"></i><span>אחוז</span></label>
                        <input type="radio" name="contactType" id="contactType_fixed" value="fixed"
                               onchange="toggleContactType()">
                        <label for="contactType_fixed"><i class="fas fa-shekel-sign"></i><span>סכום קבוע</span></label>
                    </div>
                    <small class="form-hint">
                        אם רוב הפעמים אותו אדם משתתף באותו אופן, אפשר לשמור זאת כאן
                        וזה ימולא מראש בכל צירוף
                    </small>
                </div>
                <div class="form-group" id="contactValueGroup" style="display: none;">
                    <label for="contactValue" id="contactValueLabel">כמה נפשות?</label>
                    <div class="input-with-suffix">
                        <input type="number" id="contactValue" step="1" min="1">
                        <span id="contactValueSuffix">נפשות</span>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i> שמור
                    </button>
                    <button type="button" class="btn-secondary" onclick="closeContactModal()">
                        ביטול
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        window.APP_CONFIG = {
            csrfToken: <?php echo json_encode($_SESSION['csrf_token']); ?>,
            basePath:  <?php echo json_encode(APP_BASE_PATH); ?>
        };
    </script>
    <script src="<?php echo asset('/js/contacts.js'); ?>"></script>
</body>
</html>
