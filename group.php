<?php
/**
 * דף האירוע
 * group.php
 */

require_once 'config.php';
require_once 'includes/auth_check.php';
require_once 'includes/schema.php';

$pdo     = getDBConnection();
$user_id = $_SESSION['user_id'];

$group_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($group_id <= 0) {
    header('Location: dashboard.php');
    exit;
}

$featuresReady = eventFeaturesReady($pdo);

// --- הרשאות: חייב להיות חבר פעיל בקבוצה פעילה ---
$stmt = $pdo->prepare("
    SELECT pg.*,
           gm.id AS member_id,
           gm.participation_type,
           gm.participation_value,
           (pg.owner_id = ?) AS is_owner
    FROM purchase_groups pg
    JOIN group_members gm ON pg.id = gm.group_id
    WHERE pg.id = ? AND gm.user_id = ? AND gm.is_active = 1 AND pg.is_active = 1
");
$stmt->execute([$user_id, $group_id, $user_id]);
$group = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$group) {
    header('Location: dashboard.php');
    exit;
}

$is_owner     = (bool)$group['is_owner'];
$member_id    = (int)$group['member_id'];
$event_status = $featuresReady ? ($group['status'] ?? 'active') : 'active';
$is_closed    = ($event_status === 'closed');

// --- טיפול בבקשות AJAX ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    if (!verifyCsrfToken()) {
        rejectRequest();
    }

    require_once 'includes/group_actions.php';
    handleGroupActions($pdo, $group_id, $user_id, $is_owner, $member_id, $event_status, $featuresReady);
    exit;
}

require_once 'includes/group_calculations.php';
require_once 'includes/group_modals.php';

// --- משתתפים ---
$stmt = $pdo->prepare("
    SELECT gm.*,
           COALESCE(u.name, gm.nickname) AS user_name,
           u.profile_picture,
           COALESCE(u.email, gm.email) AS email
    FROM group_members gm
    LEFT JOIN users u ON gm.user_id = u.id
    WHERE gm.group_id = ? AND gm.is_active = 1
    ORDER BY gm.joined_at, gm.id
");
$stmt->execute([$group_id]);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- סך ההשתתפות ---
$totalPercentage = 0.0;
foreach ($members as $member) {
    if ($member['participation_type'] === 'percentage') {
        $totalPercentage += (float)$member['participation_value'];
    }
}
$available_percentage = round(100 - $totalPercentage, 2);

// --- הזמנות ממתינות (רק למנהל) ---
$pending_invitations = [];
if ($is_owner) {
    $stmt = $pdo->prepare("
        SELECT gi.* FROM group_invitations gi
        WHERE gi.group_id = ? AND gi.status = 'pending'
        ORDER BY gi.created_at DESC
    ");
    $stmt->execute([$group_id]);
    $pending_invitations = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- קניות ---
$stmt = $pdo->prepare("
    SELECT gp.*, gm.nickname, u.name AS purchaser_name
    FROM group_purchases gp
    JOIN group_members gm ON gp.member_id = gm.id
    LEFT JOIN users u ON gp.user_id = u.id
    WHERE gp.group_id = ?
    ORDER BY gp.created_at DESC
");
$stmt->execute([$group_id]);
$purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- החרגות לכל קנייה ---
$exclusionsByPurchase = [];
if ($featuresReady && count($purchases) > 0) {
    $stmt = $pdo->prepare("
        SELECT pe.purchase_id, pe.member_id, gm.nickname
        FROM purchase_exclusions pe
        JOIN group_members gm ON gm.id = pe.member_id
        JOIN group_purchases gp ON gp.id = pe.purchase_id
        WHERE gp.group_id = ?
    ");
    $stmt->execute([$group_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $exclusionsByPurchase[(int)$row['purchase_id']][] = $row;
    }
}

foreach ($purchases as &$purchase) {
    $purchase['excluded'] = $exclusionsByPurchase[(int)$purchase['id']] ?? [];
    $purchase['excluded_ids'] = array_map(function ($row) {
        return (int)$row['member_id'];
    }, $purchase['excluded']);
}
unset($purchase);

// --- רשימת הקניות ---
$shoppingItems = [];
if ($featuresReady) {
    $stmt = $pdo->prepare("
        SELECT si.*, gm.nickname AS assigned_nickname
        FROM shopping_items si
        LEFT JOIN group_members gm ON gm.id = si.assigned_member_id
        WHERE si.group_id = ?
        ORDER BY FIELD(si.status, 'needed', 'claimed', 'bought'), si.sort_order, si.id
    ");
    $stmt->execute([$group_id]);
    $shoppingItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- התחשבנויות ---
$settlements = [];
if ($featuresReady) {
    $stmt = $pdo->prepare("
        SELECT s.*,
               f.nickname AS from_nickname,
               t.nickname AS to_nickname
        FROM settlements s
        JOIN group_members f ON f.id = s.from_member_id
        JOIN group_members t ON t.id = s.to_member_id
        WHERE s.group_id = ?
        ORDER BY s.created_at DESC
    ");
    $stmt->execute([$group_id]);
    $settlements = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$balance      = calculateGroupBalance($members, $purchases, $settlements);
$summaryText  = buildSummaryText($group, $balance, $shoppingItems);

$openItemCount   = 0;
$boughtItemCount = 0;
foreach ($shoppingItems as $item) {
    if ($item['status'] === 'bought') {
        $boughtItemCount++;
    } else {
        $openItemCount++;
    }
}

$canEdit = !$is_closed;
?>
<!DOCTYPE html>
<html dir="rtl" lang="he">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($group['name']); ?> - <?php echo SITE_NAME; ?></title>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <!-- Font Awesome נטען בלי לחסום רינדור: בלי זה הדף נשאר לבן
         עד שהבקשה החוצה ל-CDN חוזרת, וזה מה שנראה כהבזק ברענון. -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    <link rel="stylesheet" href="<?php echo asset('/css/group.css'); ?>">
    <link rel="manifest" href="<?php echo APP_BASE_PATH; ?>/manifest.json">
    <meta name="theme-color" content="#667eea">
</head>
<body>
    <nav class="navbar">
        <div class="navbar-container">
            <div class="navbar-left">
                <a href="dashboard.php" class="btn-back">
                    <i class="fas fa-arrow-right"></i>
                    חזרה
                </a>
                <span class="navbar-title"><?php echo htmlspecialchars($group['name']); ?></span>
            </div>
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
                <a href="auth/logout.php" class="btn-logout" title="התנתק">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </nav>

    <div class="container">
        <?php if (!$featuresReady): ?>
            <?php renderMigrationNotice(); ?>
        <?php endif; ?>

        <!-- כותרת האירוע -->
        <div class="event-header <?php echo $is_closed ? 'closed' : ''; ?>">
            <div class="event-main">
                <h1><?php echo htmlspecialchars($group['name']); ?></h1>
                <?php if (!empty($group['description'])): ?>
                <p class="event-description"><?php echo htmlspecialchars($group['description']); ?></p>
                <?php endif; ?>
                <div class="event-meta">
                    <?php if (!empty($group['event_date'])): ?>
                    <span><i class="fas fa-calendar-day"></i>
                        <?php echo date('d/m/Y', strtotime($group['event_date'])); ?>
                        <?php
                        $daysLeft = (int)floor((strtotime($group['event_date']) - strtotime('today')) / 86400);
                        if (!$is_closed && $daysLeft > 0) {
                            echo '<strong>(עוד ' . $daysLeft . ' ימים)</strong>';
                        } elseif (!$is_closed && $daysLeft === 0) {
                            echo '<strong>(היום!)</strong>';
                        }
                        ?>
                    </span>
                    <?php endif; ?>
                    <?php if (!empty($group['event_location'])): ?>
                    <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($group['event_location']); ?></span>
                    <?php endif; ?>
                    <?php if ($is_closed): ?>
                    <span class="status-badge closed"><i class="fas fa-lock"></i> האירוע סגור</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="event-actions">
                <button class="btn-secondary" onclick="shareSummary()">
                    <i class="fab fa-whatsapp"></i> שתף סיכום
                </button>
                <?php if ($is_owner && $featuresReady): ?>
                    <button class="btn-secondary" onclick="showEventModal()">
                        <i class="fas fa-edit"></i> פרטי האירוע
                    </button>
                    <?php if ($is_closed): ?>
                    <button class="btn-secondary" onclick="reopenEvent()">
                        <i class="fas fa-lock-open"></i> פתח מחדש
                    </button>
                    <?php else: ?>
                    <button class="btn-secondary" onclick="closeEvent()">
                        <i class="fas fa-flag-checkered"></i> סגור אירוע
                    </button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- סרגל מצב -->
        <div class="event-stats">
            <div class="event-stat">
                <span class="stat-value"><?php echo count($members); ?></span>
                <span class="stat-label">משתתפים</span>
            </div>
            <?php if ($featuresReady): ?>
            <div class="event-stat">
                <span class="stat-value"><?php echo $openItemCount; ?></span>
                <span class="stat-label">פריטים פתוחים</span>
            </div>
            <?php endif; ?>
            <div class="event-stat">
                <span class="stat-value">₪<?php echo number_format($balance['totalAmount'], 0); ?></span>
                <span class="stat-label">סך הוצאות</span>
            </div>
            <div class="event-stat <?php echo $balance['isBalanced'] ? 'ok' : 'pending'; ?>">
                <span class="stat-value"><?php echo count($balance['transfers']); ?></span>
                <span class="stat-label">העברות פתוחות</span>
            </div>
        </div>

        <!-- כרטיסיות -->
        <div class="tabs">
            <?php if ($featuresReady): ?>
            <button class="tab active" onclick="showTab('items', this)">
                <i class="fas fa-list-check"></i>
                מה צריך
                <?php if ($openItemCount > 0): ?>
                <span class="tab-badge"><?php echo $openItemCount; ?></span>
                <?php endif; ?>
            </button>
            <?php endif; ?>
            <button class="tab <?php echo $featuresReady ? '' : 'active'; ?>" onclick="showTab('members', this)">
                <i class="fas fa-users"></i>
                משתתפים
            </button>
            <button class="tab" onclick="showTab('purchases', this)">
                <i class="fas fa-shopping-cart"></i>
                קניות
            </button>
            <button class="tab" onclick="showTab('calculations', this)">
                <i class="fas fa-calculator"></i>
                חישובים
            </button>
        </div>

        <?php if ($featuresReady): ?>
        <!-- מסך "מה צריך" -->
        <div id="items" class="content">
            <div class="section-header">
                <h2>מה צריך להביא</h2>
                <?php if ($canEdit): ?>
                <button class="btn-add" onclick="showItemModal()">
                    <i class="fas fa-plus"></i> הוסף פריט
                </button>
                <?php endif; ?>
            </div>

            <?php if (count($shoppingItems) === 0): ?>
            <div class="no-data">
                <i class="fas fa-clipboard-list"></i>
                <p>הרשימה ריקה</p>
                <p>הוסיפו את מה שצריך להביא לאירוע</p>
            </div>
            <?php else: ?>
            <div class="items-list">
                <?php foreach ($shoppingItems as $item): ?>
                <div class="item-row status-<?php echo htmlspecialchars($item['status']); ?>">
                    <div class="item-main">
                        <div class="item-title">
                            <?php echo htmlspecialchars($item['title']); ?>
                            <?php if (!empty($item['quantity'])): ?>
                            <span class="item-quantity"><?php echo htmlspecialchars($item['quantity']); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($item['notes'])): ?>
                        <div class="item-notes"><?php echo htmlspecialchars($item['notes']); ?></div>
                        <?php endif; ?>
                        <div class="item-status">
                            <?php if ($item['status'] === 'bought'): ?>
                                <i class="fas fa-check-circle"></i> נקנה
                                <?php if (!empty($item['assigned_nickname'])): ?>
                                    על ידי <?php echo htmlspecialchars($item['assigned_nickname']); ?>
                                <?php endif; ?>
                            <?php elseif ($item['status'] === 'claimed'): ?>
                                <i class="fas fa-hand-paper"></i>
                                <?php echo htmlspecialchars($item['assigned_nickname'] ?? 'מישהו'); ?> לוקח על עצמו
                            <?php else: ?>
                                <i class="fas fa-circle-notch"></i> עוד לא שובץ
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($canEdit): ?>
                    <div class="item-actions">
                        <?php if ($item['status'] === 'needed'): ?>
                        <button class="btn-small" onclick="setItemStatus(<?php echo (int)$item['id']; ?>, 'claimed')">
                            <i class="fas fa-hand-paper"></i> אני אביא
                        </button>
                        <?php elseif ($item['status'] === 'claimed'): ?>
                        <button class="btn-small primary"
                                onclick='buyItem(<?php echo (int)$item["id"]; ?>, <?php echo json_encode($item["title"], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                            <i class="fas fa-receipt"></i> קניתי
                        </button>
                        <button class="btn-small" onclick="setItemStatus(<?php echo (int)$item['id']; ?>, 'needed')">
                            <i class="fas fa-undo"></i>
                        </button>
                        <?php else: ?>
                        <button class="btn-small" onclick="setItemStatus(<?php echo (int)$item['id']; ?>, 'needed')">
                            <i class="fas fa-undo"></i>
                        </button>
                        <?php endif; ?>
                        <button class="btn-icon" title="ערוך"
                                onclick='editItem(<?php echo json_encode([
                                    "id"       => (int)$item["id"],
                                    "title"    => $item["title"],
                                    "quantity" => $item["quantity"],
                                    "notes"    => $item["notes"],
                                ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn-icon-danger" title="מחק"
                                onclick="deleteItem(<?php echo (int)$item['id']; ?>)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if ($boughtItemCount > 0): ?>
            <p class="items-summary"><?php echo $boughtItemCount; ?> פריטים כבר נקנו</p>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- מסך משתתפים -->
        <div id="members" class="content" style="<?php echo $featuresReady ? 'display: none;' : ''; ?>">
            <div class="section-header">
                <h2><?php echo $is_owner ? 'ניהול משתתפים' : 'משתתפי האירוע'; ?></h2>
                <?php if ($is_owner && $canEdit): ?>
                <div class="header-actions">
                    <button class="btn-secondary" onclick="splitEqually()">
                        <i class="fas fa-equals"></i> חלוקה שווה
                    </button>
                    <button class="btn-add" onclick="showAddMemberModal()">
                        <i class="fas fa-user-plus"></i> הוסף משתתף
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <div class="members-grid">
                <?php foreach ($members as $member): ?>
                <div class="member-card">
                    <div class="member-avatar">
                        <?php if (!empty($member['profile_picture'])): ?>
                            <img src="<?php echo htmlspecialchars($member['profile_picture']); ?>" alt="">
                        <?php else: ?>
                            <i class="fas fa-user"></i>
                        <?php endif; ?>
                    </div>
                    <div class="member-info">
                        <h3><?php echo htmlspecialchars($member['nickname']); ?></h3>
                        <p class="member-email"><?php echo htmlspecialchars($member['email']); ?></p>
                        <p class="member-participation">
                            <?php if ($member['participation_type'] === 'percentage'): ?>
                                <i class="fas fa-percentage"></i> <?php echo $member['participation_value']; ?>%
                            <?php else: ?>
                                <i class="fas fa-shekel-sign"></i> ₪<?php echo number_format($member['participation_value'], 2); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php if ($is_owner && $canEdit): ?>
                    <div class="member-actions">
                        <button class="btn-edit" title="ערוך"
                                onclick="editMember(<?php echo (int)$member['id']; ?>, '<?php echo htmlspecialchars($member['participation_type'], ENT_QUOTES); ?>', <?php echo (float)$member['participation_value']; ?>)">
                            <i class="fas fa-edit"></i>
                        </button>
                        <?php // את המנהל אי אפשר להסיר מהאירוע שלו, אבל כן אפשר לערוך את חלקו ?>
                        <?php if ($member['user_id'] != $group['owner_id']): ?>
                        <button class="btn-remove" title="הסר"
                                onclick="removeMember(<?php echo (int)$member['id']; ?>)">
                            <i class="fas fa-times"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <?php if ($available_percentage > 0.01 && $totalPercentage > 0): ?>
                <div class="member-card warning">
                    <div class="member-avatar"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="member-info">
                        <h3>חסרים אחוזים להשלמה</h3>
                        <p class="member-status warning">
                            <i class="fas fa-info-circle"></i> סה"כ מוגדר: <?php echo round($totalPercentage, 2); ?>%
                        </p>
                        <p class="member-participation">
                            <i class="fas fa-percentage"></i> חסרים: <?php echo $available_percentage; ?>%
                        </p>
                    </div>
                </div>
                <?php endif; ?>

                <?php foreach ($pending_invitations as $invitation): ?>
                <div class="member-card pending">
                    <div class="member-avatar"><i class="fas fa-user-clock"></i></div>
                    <div class="member-info">
                        <h3><?php echo htmlspecialchars($invitation['nickname']); ?></h3>
                        <p class="member-email"><?php echo htmlspecialchars($invitation['email']); ?></p>
                        <p class="member-status pending">
                            <i class="fas fa-clock"></i> ממתין לאישור
                        </p>
                        <p class="member-participation">
                            <?php if ($invitation['participation_type'] === 'percentage'): ?>
                                <i class="fas fa-percentage"></i> <?php echo $invitation['participation_value']; ?>%
                            <?php else: ?>
                                <i class="fas fa-shekel-sign"></i> ₪<?php echo number_format($invitation['participation_value'], 2); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="member-actions">
                        <button class="btn-edit" title="העתק קישור הצטרפות"
                                onclick="showInviteLink('<?php echo htmlspecialchars($invitation['token'], ENT_QUOTES); ?>')">
                            <i class="fas fa-link"></i>
                        </button>
                        <button class="btn-remove" title="בטל הזמנה"
                                onclick="cancelInvitation(<?php echo (int)$invitation['id']; ?>)">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- מסך קניות -->
        <div id="purchases" class="content" style="display: none;">
            <div class="section-header">
                <h2>קניות האירוע</h2>
                <?php if ($canEdit): ?>
                <button class="btn-add" onclick="showAddPurchaseModal()">
                    <i class="fas fa-plus"></i> הוסף קנייה
                </button>
                <?php endif; ?>
            </div>

            <?php if (count($purchases) === 0): ?>
            <div class="no-data">
                <i class="fas fa-receipt"></i>
                <p>עוד לא נרשמו קניות</p>
            </div>
            <?php else: ?>
            <div class="purchases-list">
                <?php foreach ($purchases as $purchase): ?>
                <?php $canEditThis = $canEdit && ($is_owner || $purchase['user_id'] == $user_id); ?>
                <div class="purchase-card">
                    <div class="purchase-header">
                        <div class="purchase-info">
                            <h3><?php echo htmlspecialchars($purchase['nickname']); ?></h3>
                            <span class="purchase-date">
                                <i class="fas fa-calendar"></i>
                                <?php echo date('d/m/Y', strtotime($purchase['created_at'])); ?>
                            </span>
                        </div>
                        <div class="purchase-amount">
                            ₪<?php echo number_format($purchase['amount'], 2); ?>
                        </div>
                    </div>

                    <?php if ($purchase['description']): ?>
                    <div class="purchase-description">
                        <?php echo nl2br(htmlspecialchars($purchase['description'])); ?>
                    </div>
                    <?php endif; ?>

                    <?php if (count($purchase['excluded']) > 0): ?>
                    <div class="purchase-exclusions">
                        <i class="fas fa-user-slash"></i>
                        לא משתתפים:
                        <?php
                        $names = array_map(function ($row) {
                            return htmlspecialchars($row['nickname']);
                        }, $purchase['excluded']);
                        echo implode(', ', $names);
                        ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($purchase['image_path']): ?>
                    <div class="purchase-image">
                        <img src="<?php echo htmlspecialchars($purchase['image_path']); ?>" alt="קבלה"
                             onclick="showImageModal(this.src)">
                    </div>
                    <?php endif; ?>

                    <?php if ($canEditThis): ?>
                    <div class="purchase-actions">
                        <?php if ($featuresReady): ?>
                        <button class="btn-small"
                                onclick='editPurchase(<?php echo json_encode([
                                    "id"          => (int)$purchase["id"],
                                    "amount"      => (float)$purchase["amount"],
                                    "description" => $purchase["description"],
                                    "excluded"    => $purchase["excluded_ids"],
                                ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                            <i class="fas fa-edit"></i> ערוך
                        </button>
                        <?php endif; ?>
                        <button class="btn-delete" onclick="deletePurchase(<?php echo (int)$purchase['id']; ?>)">
                            <i class="fas fa-trash"></i> מחק
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- מסך חישובים -->
        <div id="calculations" class="content" style="display: none;">
            <div class="section-header">
                <h2>חישוב וחלוקת עלויות</h2>
                <button class="btn-secondary" onclick="shareSummary()">
                    <i class="fab fa-whatsapp"></i> שתף
                </button>
            </div>
            <?php echo renderCalculationsView($members, $balance, $featuresReady, $settlements); ?>
        </div>
    </div>

    <!-- Modals -->
    <?php
    if ($is_owner) {
        renderAddMemberModal($available_percentage);
        renderEditMemberModal();
    }
    if ($featuresReady) {
        renderEventModal($group);
        renderItemModal();
        renderEditPurchaseModal($members);
    }
    renderAddPurchaseModal($members, $is_owner, $member_id, $featuresReady);
    renderImageModal();
    ?>

    <script>
        window.APP_CONFIG = {
            groupId:             <?php echo (int)$group_id; ?>,
            isOwner:             <?php echo $is_owner ? 'true' : 'false'; ?>,
            isClosed:            <?php echo $is_closed ? 'true' : 'false'; ?>,
            featuresReady:       <?php echo $featuresReady ? 'true' : 'false'; ?>,
            availablePercentage: <?php echo $available_percentage; ?>,
            csrfToken:           <?php echo json_encode($_SESSION['csrf_token']); ?>,
            basePath:            <?php echo json_encode(APP_BASE_PATH); ?>,
            summaryText:         <?php echo json_encode($summaryText, JSON_UNESCAPED_UNICODE); ?>
        };
    </script>
    <script src="<?php echo asset('/js/group.js'); ?>"></script>
    <script src="<?php echo asset('/js/push-subscribe.js'); ?>"></script>

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
