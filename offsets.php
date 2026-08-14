<?php
/**
 * קיזוזים בין אירועים
 * offsets.php
 *
 * מרכז שני דברים שאי אפשר לראות מתוך אירוע בודד: חובות הפוכים
 * בין אותם שני אנשים בשני אירועים, והאירועים שחולקים משתתפים.
 */

require_once 'config.php';
require_once 'includes/auth_check.php';
require_once 'includes/offsets.php';

$pdo     = getDBConnection();
$user_id = $_SESSION['user_id'];

$featuresReady = eventFeaturesReady($pdo);

// ============================================================
// פעולות AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    if ($_POST['action'] === 'applyOffset') {
        $result = applyOffset(
            $pdo, $user_id,
            intval($_POST['group_a'] ?? 0),
            intval($_POST['group_b'] ?? 0),
            (string)($_POST['identity_a'] ?? ''),
            (string)($_POST['identity_b'] ?? ''),
            $_POST['amount'] ?? 0
        );

        echo json_encode([
            'success' => $result['ok'],
            'message' => $result['message'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'פעולה לא מוכרת'], JSON_UNESCAPED_UNICODE);
    exit;
}

$candidates = $featuresReady ? findOffsets($pdo, $user_id) : [];
$shared     = $featuresReady ? findSharedGroups($pdo, $user_id) : [];
$symbol     = currencySymbol();

/** סכום מעוצב */
function offsetMoney($value) {
    return currencySymbol() . number_format((float)$value, 2);
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="he">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>קיזוזים - <?php echo SITE_NAME; ?></title>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    <link rel="stylesheet" href="<?php echo asset('/css/dashboard.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('/css/contacts.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('/css/offsets.css'); ?>">
    <meta name="theme-color" content="#667eea">
</head>
<body>
    <nav class="navbar">
        <div class="navbar-container">
            <a href="dashboard.php" class="btn-back">
                <i class="fas fa-arrow-right"></i> חזרה
            </a>
            <span class="navbar-title">קיזוזים</span>
            <span style="width: 40px;"></span>
        </div>
    </nav>

    <div class="container">

        <?php if (!$featuresReady): ?>
            <?php renderMigrationNotice(); ?>
        <?php endif; ?>

        <!-- ==================================== קיזוזים -->
        <section class="offset-section">
            <h2 class="offset-title">
                <i class="fas fa-scale-balanced"></i> קיזוזים אפשריים
            </h2>

            <p class="offset-intro">
                כששני אנשים חייבים זה לזה בשני אירועים שונים, אין סיבה
                שכסף יעבור פעמיים. קיזוז רושם את שתי ההתחשבנויות יחד —
                אחת בכל אירוע — ומשאיר רק את ההפרש.
            </p>

            <?php if (!$candidates): ?>
                <div class="offset-empty">
                    <i class="fas fa-check-circle"></i>
                    <p>לא נמצאו חובות הפוכים בין האירועים שלך.</p>
                    <small>
                        קיזוז אפשרי כשאותם שני אנשים חייבים זה לזה בשני אירועים
                        נפרדים, ובכיוונים הפוכים.
                    </small>
                </div>
            <?php else: ?>
                <?php foreach ($candidates as $index => $candidate): ?>
                <div class="offset-card">
                    <div class="offset-people">
                        <strong><?php echo htmlspecialchars($candidate['person_a']['name']); ?></strong>
                        <i class="fas fa-arrows-left-right"></i>
                        <strong><?php echo htmlspecialchars($candidate['person_b']['name']); ?></strong>
                    </div>

                    <div class="offset-legs">
                        <div class="offset-leg">
                            <span class="offset-leg-flow">
                                <?php echo htmlspecialchars($candidate['person_a']['name']); ?>
                                חייב
                                <?php echo htmlspecialchars($candidate['person_b']['name']); ?>
                            </span>
                            <span class="offset-leg-amount"><?php echo offsetMoney($candidate['debt_a']['amount']); ?></span>
                            <span class="offset-leg-group">
                                <i class="fas fa-calendar-check"></i>
                                <?php echo htmlspecialchars($candidate['debt_a']['group_name']); ?>
                            </span>
                        </div>

                        <div class="offset-leg">
                            <span class="offset-leg-flow">
                                <?php echo htmlspecialchars($candidate['person_b']['name']); ?>
                                חייב
                                <?php echo htmlspecialchars($candidate['person_a']['name']); ?>
                            </span>
                            <span class="offset-leg-amount"><?php echo offsetMoney($candidate['debt_b']['amount']); ?></span>
                            <span class="offset-leg-group">
                                <i class="fas fa-calendar-check"></i>
                                <?php echo htmlspecialchars($candidate['debt_b']['group_name']); ?>
                            </span>
                        </div>
                    </div>

                    <div class="offset-result">
                        <span>ניתן לקזז</span>
                        <strong><?php echo offsetMoney($candidate['offsetable']); ?></strong>
                        <?php if ($candidate['remainder'] > 0.01): ?>
                            <span class="offset-remainder">
                                ואז יישאר חוב אחד של <?php echo offsetMoney($candidate['remainder']); ?>
                            </span>
                        <?php else: ?>
                            <span class="offset-remainder">ושני החובות נסגרים</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($candidate['can_apply']): ?>
                    <button class="btn-offset"
                            onclick="openOffsetModal(<?php echo htmlspecialchars(json_encode([
                                'group_a'    => $candidate['debt_a']['group_id'],
                                'group_b'    => $candidate['debt_b']['group_id'],
                                'identity_a' => $candidate['person_a']['identity'],
                                'identity_b' => $candidate['person_b']['identity'],
                                'max'        => $candidate['offsetable'],
                                'label'      => $candidate['person_a']['name'] . ' ↔ ' . $candidate['person_b']['name'],
                            ], JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>)">
                        <i class="fas fa-scale-balanced"></i> בצע קיזוז
                    </button>
                    <?php else: ?>
                    <p class="offset-blocked">
                        <i class="fas fa-lock"></i>
                        רק מנהל האירוע או אחד משני הצדדים יכול לרשום את הקיזוז הזה
                    </p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <!-- ============================== קבוצות משותפות -->
        <section class="offset-section">
            <h2 class="offset-title">
                <i class="fas fa-people-arrows"></i> אירועים עם משתתפים משותפים
            </h2>

            <p class="offset-intro">
                איפה אותם אנשים חוזרים. זה המקום שבו קיזוזים נוטים להופיע,
                גם כשכרגע אין חוב פתוח.
            </p>

            <?php if (!$shared): ?>
                <div class="offset-empty">
                    <i class="fas fa-user-group"></i>
                    <p>אין שני אירועים שחולקים יותר ממשתתף אחד.</p>
                </div>
            <?php else: ?>
                <?php foreach ($shared as $pair): ?>
                <div class="shared-card">
                    <div class="shared-groups">
                        <a href="group.php?id=<?php echo (int)$pair['group_a']['id']; ?>">
                            <?php echo htmlspecialchars($pair['group_a']['name']); ?>
                        </a>
                        <i class="fas fa-link"></i>
                        <a href="group.php?id=<?php echo (int)$pair['group_b']['id']; ?>">
                            <?php echo htmlspecialchars($pair['group_b']['name']); ?>
                        </a>
                    </div>
                    <div class="shared-people">
                        <span class="shared-count"><?php echo (int)$pair['count']; ?> משותפים:</span>
                        <?php foreach ($pair['shared'] as $name): ?>
                            <span class="contact-badge"><?php echo htmlspecialchars($name); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>

    <!-- ביצוע קיזוז -->
    <div id="offsetModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>ביצוע קיזוז</h2>
                <span class="close" onclick="closeOffsetModal()">&times;</span>
            </div>
            <form id="offsetForm">
                <p class="modal-context" id="offsetLabel"></p>

                <div class="form-group">
                    <label for="offsetAmount">כמה לקזז?</label>
                    <div class="input-with-suffix">
                        <input type="number" id="offsetAmount" step="0.01" min="0.01" required>
                        <span><?php echo htmlspecialchars($symbol); ?></span>
                    </div>
                    <small class="form-hint">
                        עד <span id="offsetMax"></span>.
                        <button type="button" class="link-button" onclick="offsetFillFull()">הכל</button>
                    </small>
                </div>

                <p class="form-hint">
                    ייווצרו שתי התחשבנויות, אחת בכל אירוע. אפשר לבטל כל אחת מהן
                    ממסך האירוע שלה.
                </p>

                <div class="modal-actions">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-scale-balanced"></i> קזז
                    </button>
                    <button type="button" class="btn-secondary" onclick="closeOffsetModal()">
                        ביטול
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        window.APP_CONFIG = {
            csrfToken: <?php echo json_encode($_SESSION['csrf_token']); ?>,
            currency:  <?php echo json_encode($symbol); ?>
        };
    </script>
    <script src="<?php echo asset('/js/offsets.js'); ?>"></script>
</body>
</html>
