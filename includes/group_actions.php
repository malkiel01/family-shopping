<?php
// includes/group_actions.php
// טיפול בכל פעולות ה-AJAX של הקבוצה

require_once __DIR__ . '/upload.php';

/**
 * הקשר הבקשה - נשמר פעם אחת כדי שכל פונקציה תדע
 * מי המשתמש, מה התפקיד שלו ומה מצב האירוע.
 */
class GroupContext {
    public $pdo;
    public $groupId;
    public $userId;
    public $isOwner;
    public $memberId;
    public $status;
    public $featuresReady;

    public function __construct($pdo, $groupId, $userId, $isOwner, $memberId, $status, $featuresReady = true) {
        $this->pdo           = $pdo;
        $this->groupId       = (int)$groupId;
        $this->userId        = (int)$userId;
        $this->isOwner       = (bool)$isOwner;
        $this->memberId      = (int)$memberId;
        $this->status        = $status;
        $this->featuresReady = (bool)$featuresReady;
    }

    public function isClosed() {
        return $this->status === 'closed';
    }
}

function jsonReply(array $payload) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}

function jsonFail($message) {
    jsonReply(['success' => false, 'message' => $message]);
}

function jsonOk(array $extra = []) {
    jsonReply(array_merge(['success' => true], $extra));
}

/**
 * פעולות שמותרות רק למנהל הקבוצה.
 */
const OWNER_ONLY_ACTIONS = [
    'addMember', 'removeMember', 'editMember', 'cancelInvitation',
    'splitEqually', 'updateEvent', 'closeEvent', 'reopenEvent',
];

/**
 * פעולות שאסורות כשהאירוע סגור.
 */
const BLOCKED_WHEN_CLOSED = [
    'addMember', 'removeMember', 'editMember', 'cancelInvitation', 'splitEqually',
    'addPurchase', 'updatePurchase', 'deletePurchase',
    'addItem', 'updateItem', 'deleteItem', 'setItemStatus',
];

/**
 * פעולות שנשענות על טבלאות שנוספו במיגרציה של מנהל האירוע.
 */
const REQUIRES_MIGRATION = [
    'addItem', 'updateItem', 'setItemStatus', 'deleteItem',
    'addSettlement', 'deleteSettlement',
    'updateEvent', 'closeEvent', 'reopenEvent',
];

function handleGroupActions($pdo, $group_id, $user_id, $is_owner, $member_id, $status = 'active', $featuresReady = true) {
    $context = new GroupContext($pdo, $group_id, $user_id, $is_owner, $member_id, $status, $featuresReady);
    $action  = $_POST['action'] ?? '';

    $handlers = [
        'addMember'        => 'actionAddMember',
        'removeMember'     => 'actionRemoveMember',
        'editMember'       => 'actionEditMember',
        'splitEqually'     => 'actionSplitEqually',
        'cancelInvitation' => 'actionCancelInvitation',
        'addPurchase'      => 'actionAddPurchase',
        'updatePurchase'   => 'actionUpdatePurchase',
        'deletePurchase'   => 'actionDeletePurchase',
        'addItem'          => 'actionAddItem',
        'updateItem'       => 'actionUpdateItem',
        'setItemStatus'    => 'actionSetItemStatus',
        'deleteItem'       => 'actionDeleteItem',
        'addSettlement'    => 'actionAddSettlement',
        'deleteSettlement' => 'actionDeleteSettlement',
        'updateEvent'      => 'actionUpdateEvent',
        'closeEvent'       => 'actionCloseEvent',
        'reopenEvent'      => 'actionReopenEvent',
    ];

    if (!isset($handlers[$action])) {
        jsonFail('פעולה לא מוכרת');
        return;
    }

    if (in_array($action, OWNER_ONLY_ACTIONS, true) && !$context->isOwner) {
        jsonFail('אין הרשאה - הפעולה שמורה למנהל הקבוצה');
        return;
    }

    if ($context->isClosed() && in_array($action, BLOCKED_WHEN_CLOSED, true)) {
        jsonFail('האירוע סגור. יש לפתוח אותו מחדש כדי לבצע שינויים');
        return;
    }

    if (!$context->featuresReady && in_array($action, REQUIRES_MIGRATION, true)) {
        jsonFail('התכונה עוד לא זמינה - יש להריץ את עדכון מסד הנתונים');
        return;
    }

    try {
        $handlers[$action]($context);
    } catch (Exception $e) {
        error_log("Group action '$action' failed: " . $e->getMessage());
        jsonFail('שגיאת שרת. הפעולה לא בוצעה');
    }
}

// ============================================================
// משתתפים
// ============================================================

function actionAddMember(GroupContext $context) {
    $email              = trim($_POST['email'] ?? '');
    $nickname           = trim($_POST['nickname'] ?? '');
    $participationType  = ($_POST['participation_type'] ?? '') === 'fixed' ? 'fixed' : 'percentage';
    $participationValue = round(floatval($_POST['participation_value'] ?? 0), 2);

    if ($email === '' || $nickname === '') {
        jsonFail('יש למלא אימייל וכינוי');
        return;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonFail('כתובת האימייל אינה תקינה');
        return;
    }

    if ($participationValue <= 0) {
        jsonFail('ערך ההשתתפות חייב להיות חיובי');
        return;
    }

    if ($participationType === 'percentage') {
        $available = availablePercentage($context->pdo, $context->groupId);
        if ($participationValue > $available + 0.001) {
            jsonFail("סכום האחוזים חורג מ-100%. נותרו " . round($available, 2) . "% זמינים");
            return;
        }
    }

    $stmt = $context->pdo->prepare("
        SELECT id FROM group_invitations
        WHERE group_id = ? AND email = ? AND status = 'pending'
    ");
    $stmt->execute([$context->groupId, $email]);
    if ($stmt->fetch()) {
        jsonFail('כבר קיימת הזמנה ממתינה למשתמש זה');
        return;
    }

    $stmt = $context->pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        $stmt = $context->pdo->prepare("
            SELECT id, is_active FROM group_members WHERE group_id = ? AND user_id = ?
        ");
        $stmt->execute([$context->groupId, $user['id']]);
        $existing = $stmt->fetch();

        if ($existing && $existing['is_active']) {
            jsonFail('המשתמש כבר חבר פעיל בקבוצה');
            return;
        }
    }

    $token = bin2hex(random_bytes(32));
    $stmt  = $context->pdo->prepare("
        INSERT INTO group_invitations
            (group_id, email, nickname, participation_type, participation_value, token, invited_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $context->groupId, $email, $nickname,
        $participationType, $participationValue, $token, $context->userId,
    ]);

    $invitationId = $context->pdo->lastInsertId();

    // המשתמש כבר רשום - נכניס התראה לתור
    if ($user) {
        queueInvitationNotification($context, $invitationId, $user['id']);
    }

    jsonOk([
        'invitation_id'   => (int)$invitationId,
        'invitation_link' => invitationLink($token),
        'is_registered'   => (bool)$user,
        'message'         => $user
            ? 'ההזמנה נשלחה. אפשר גם להעתיק את הקישור ולשלוח בוואטסאפ'
            : 'המשתמש עדיין לא רשום — יש לשלוח לו את קישור ההצטרפות',
    ]);
}

/**
 * בונה קישור הצטרפות מלא מהטוקן.
 */
function invitationLink($token) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? '';
    $base   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

    return $scheme . '://' . $host . $base . '/join.php?token=' . urlencode($token);
}

function queueInvitationNotification(GroupContext $context, $invitationId, $invitedUserId) {
    try {
        $stmt = $context->pdo->prepare("SELECT name FROM purchase_groups WHERE id = ?");
        $stmt->execute([$context->groupId]);
        $groupName = $stmt->fetchColumn() ?: 'קבוצה';

        $stmt = $context->pdo->prepare("
            INSERT INTO notification_queue (type, data, status, created_at)
            VALUES ('invitation', ?, 'pending', NOW())
        ");
        $stmt->execute([json_encode([
            'invitation_id' => (int)$invitationId,
            'user_id'       => (int)$invitedUserId,
            'group_id'      => $context->groupId,
            'group_name'    => $groupName,
        ], JSON_UNESCAPED_UNICODE)]);
    } catch (Exception $e) {
        // התראה שנכשלה לא אמורה להפיל את ההזמנה עצמה
        error_log("Failed to queue invitation notification: " . $e->getMessage());
    }
}

function availablePercentage($pdo, $groupId, $excludeMemberId = null) {
    $sql = "
        SELECT COALESCE(SUM(participation_value), 0)
        FROM group_members
        WHERE group_id = ? AND participation_type = 'percentage' AND is_active = 1
    ";
    $params = [$groupId];

    if ($excludeMemberId !== null) {
        $sql .= " AND id != ?";
        $params[] = $excludeMemberId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return 100 - (float)$stmt->fetchColumn();
}

function actionRemoveMember(GroupContext $context) {
    $memberId = intval($_POST['member_id'] ?? 0);

    $stmt = $context->pdo->prepare("
        SELECT gm.user_id, pg.owner_id
        FROM group_members gm
        JOIN purchase_groups pg ON pg.id = gm.group_id
        WHERE gm.id = ? AND gm.group_id = ?
    ");
    $stmt->execute([$memberId, $context->groupId]);
    $member = $stmt->fetch();

    if (!$member) {
        jsonFail('המשתתף לא נמצא');
        return;
    }

    if ($member['user_id'] == $member['owner_id']) {
        jsonFail('לא ניתן להסיר את מנהל הקבוצה');
        return;
    }

    $stmt = $context->pdo->prepare("SELECT COUNT(*) FROM group_purchases WHERE member_id = ?");
    $stmt->execute([$memberId]);
    if ($stmt->fetchColumn() > 0) {
        jsonFail('לא ניתן להסיר משתתף שרשומות על שמו קניות');
        return;
    }

    $stmt = $context->pdo->prepare("
        UPDATE group_members SET is_active = 0 WHERE id = ? AND group_id = ?
    ");
    $stmt->execute([$memberId, $context->groupId]);

    // שחרור פריטים שהיו משויכים אליו
    if ($context->featuresReady) {
        $stmt = $context->pdo->prepare("
            UPDATE shopping_items
            SET assigned_member_id = NULL, status = 'needed'
            WHERE assigned_member_id = ? AND status != 'bought'
        ");
        $stmt->execute([$memberId]);
    }

    jsonOk();
}

function actionEditMember(GroupContext $context) {
    $memberId           = intval($_POST['member_id'] ?? 0);
    $participationType  = ($_POST['participation_type'] ?? '') === 'fixed' ? 'fixed' : 'percentage';
    $participationValue = round(floatval($_POST['participation_value'] ?? 0), 2);

    if ($participationValue <= 0) {
        jsonFail('ערך ההשתתפות חייב להיות חיובי');
        return;
    }

    if ($participationType === 'percentage') {
        $available = availablePercentage($context->pdo, $context->groupId, $memberId);
        if ($participationValue > $available + 0.001) {
            jsonFail("סכום האחוזים חורג מ-100%. נותרו " . round($available, 2) . "% זמינים");
            return;
        }
    }

    $stmt = $context->pdo->prepare("
        UPDATE group_members
        SET participation_type = ?, participation_value = ?
        WHERE id = ? AND group_id = ?
    ");
    $stmt->execute([$participationType, $participationValue, $memberId, $context->groupId]);

    jsonOk();
}

/**
 * מחלק 100% שווה בשווה בין כל החברים הפעילים.
 * זה המקרה הנפוץ ביותר, ובלעדיו המשתמש צריך לחשב ידנית.
 */
function actionSplitEqually(GroupContext $context) {
    $stmt = $context->pdo->prepare("
        SELECT id FROM group_members WHERE group_id = ? AND is_active = 1 ORDER BY joined_at, id
    ");
    $stmt->execute([$context->groupId]);
    $memberIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $count = count($memberIds);
    if ($count === 0) {
        jsonFail('אין משתתפים פעילים בקבוצה');
        return;
    }

    // מעגלים כלפי מטה ומוסיפים את שארית העיגול לאחרון,
    // כדי שהסכום יהיה בדיוק 100
    $share     = floor((100 / $count) * 100) / 100;
    $remainder = round(100 - ($share * $count), 2);

    $context->pdo->beginTransaction();
    try {
        $stmt = $context->pdo->prepare("
            UPDATE group_members
            SET participation_type = 'percentage', participation_value = ?
            WHERE id = ? AND group_id = ?
        ");

        foreach ($memberIds as $index => $memberId) {
            $value = ($index === $count - 1) ? $share + $remainder : $share;
            $stmt->execute([$value, $memberId, $context->groupId]);
        }

        $context->pdo->commit();
    } catch (Exception $e) {
        $context->pdo->rollBack();
        throw $e;
    }

    jsonOk(['message' => "החלוקה עודכנה - {$share}% לכל משתתף"]);
}

function actionCancelInvitation(GroupContext $context) {
    $invitationId = intval($_POST['invitation_id'] ?? 0);

    $stmt = $context->pdo->prepare("
        UPDATE group_invitations SET status = 'expired'
        WHERE id = ? AND group_id = ? AND status = 'pending'
    ");
    $stmt->execute([$invitationId, $context->groupId]);

    jsonOk();
}

// ============================================================
// קניות
// ============================================================

/**
 * קורא את רשימת המוחרגים מהבקשה ומוודא שכולם חברים פעילים בקבוצה.
 *
 * @return array{ok: bool, ids?: int[], message?: string}
 */
function readExclusions(GroupContext $context) {
    // בלי המיגרציה אין טבלת החרגות - כל קנייה מתחלקת בין כולם
    if (!$context->featuresReady) {
        return ['ok' => true, 'ids' => []];
    }

    $raw = $_POST['excluded_ids'] ?? [];
    if (!is_array($raw)) {
        $raw = array_filter(explode(',', (string)$raw), 'strlen');
    }

    $requested = array_values(array_unique(array_map('intval', $raw)));

    $stmt = $context->pdo->prepare("
        SELECT id FROM group_members WHERE group_id = ? AND is_active = 1
    ");
    $stmt->execute([$context->groupId]);
    $activeIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $excluded = array_values(array_intersect($requested, $activeIds));

    if (count($excluded) > 0 && count($excluded) >= count($activeIds)) {
        return ['ok' => false, 'message' => 'לא ניתן להחריג את כל המשתתפים מהקנייה'];
    }

    return ['ok' => true, 'ids' => $excluded];
}

function saveExclusions(GroupContext $context, $purchaseId, array $excludedIds) {
    if (!$context->featuresReady) {
        return;
    }

    $stmt = $context->pdo->prepare("DELETE FROM purchase_exclusions WHERE purchase_id = ?");
    $stmt->execute([$purchaseId]);

    if (count($excludedIds) === 0) {
        return;
    }

    $stmt = $context->pdo->prepare("
        INSERT INTO purchase_exclusions (purchase_id, member_id) VALUES (?, ?)
    ");
    foreach ($excludedIds as $memberId) {
        $stmt->execute([$purchaseId, $memberId]);
    }
}

function actionAddPurchase(GroupContext $context) {
    $amount      = round(floatval($_POST['amount'] ?? 0), 2);
    $description = trim($_POST['description'] ?? '');

    if ($amount <= 0) {
        jsonFail('סכום הקנייה חייב להיות חיובי');
        return;
    }

    // רק מנהל יכול לרשום קנייה על שם משתתף אחר
    if ($context->isOwner) {
        $memberId = intval($_POST['member_id'] ?? 0);

        $stmt = $context->pdo->prepare("
            SELECT id FROM group_members WHERE id = ? AND group_id = ? AND is_active = 1
        ");
        $stmt->execute([$memberId, $context->groupId]);
        if (!$stmt->fetch()) {
            jsonFail('יש לבחור משתתף תקין');
            return;
        }
    } else {
        $memberId = $context->memberId;
    }

    $exclusions = readExclusions($context);
    if (!$exclusions['ok']) {
        jsonFail($exclusions['message']);
        return;
    }

    $imagePath = null;
    if (isset($_FILES['image'])) {
        $upload = saveReceiptImage($_FILES['image']);
        if (!$upload['ok']) {
            jsonFail($upload['message']);
            return;
        }
        $imagePath = $upload['path'];
    }

    $itemId = intval($_POST['item_id'] ?? 0);

    $context->pdo->beginTransaction();
    try {
        $stmt = $context->pdo->prepare("
            INSERT INTO group_purchases
                (group_id, member_id, user_id, amount, description, image_path, purchase_date)
            VALUES (?, ?, ?, ?, ?, ?, CURRENT_DATE())
        ");
        $stmt->execute([
            $context->groupId, $memberId, $context->userId,
            $amount, $description, $imagePath,
        ]);

        $purchaseId = (int)$context->pdo->lastInsertId();
        saveExclusions($context, $purchaseId, $exclusions['ids']);

        // הקנייה נוצרה מתוך פריט ברשימה - סמן אותו כנקנה
        if ($itemId > 0 && $context->featuresReady) {
            $stmt = $context->pdo->prepare("
                UPDATE shopping_items
                SET status = 'bought', purchase_id = ?
                WHERE id = ? AND group_id = ?
            ");
            $stmt->execute([$purchaseId, $itemId, $context->groupId]);
        }

        $context->pdo->commit();
    } catch (Exception $e) {
        $context->pdo->rollBack();
        deleteReceiptImage($imagePath);
        throw $e;
    }

    notifyNewPurchaseSafely($purchaseId);

    jsonOk(['purchase_id' => $purchaseId]);
}

function actionUpdatePurchase(GroupContext $context) {
    $purchaseId  = intval($_POST['purchase_id'] ?? 0);
    $amount      = round(floatval($_POST['amount'] ?? 0), 2);
    $description = trim($_POST['description'] ?? '');

    if ($amount <= 0) {
        jsonFail('סכום הקנייה חייב להיות חיובי');
        return;
    }

    $stmt = $context->pdo->prepare("
        SELECT user_id FROM group_purchases WHERE id = ? AND group_id = ?
    ");
    $stmt->execute([$purchaseId, $context->groupId]);
    $purchase = $stmt->fetch();

    if (!$purchase) {
        jsonFail('הקנייה לא נמצאה');
        return;
    }

    if (!$context->isOwner && $purchase['user_id'] != $context->userId) {
        jsonFail('אין הרשאה לערוך קנייה זו');
        return;
    }

    $exclusions = readExclusions($context);
    if (!$exclusions['ok']) {
        jsonFail($exclusions['message']);
        return;
    }

    $context->pdo->beginTransaction();
    try {
        $stmt = $context->pdo->prepare("
            UPDATE group_purchases SET amount = ?, description = ? WHERE id = ? AND group_id = ?
        ");
        $stmt->execute([$amount, $description, $purchaseId, $context->groupId]);

        saveExclusions($context, $purchaseId, $exclusions['ids']);

        $context->pdo->commit();
    } catch (Exception $e) {
        $context->pdo->rollBack();
        throw $e;
    }

    jsonOk();
}

function actionDeletePurchase(GroupContext $context) {
    $purchaseId = intval($_POST['purchase_id'] ?? 0);

    $stmt = $context->pdo->prepare("
        SELECT user_id, image_path FROM group_purchases WHERE id = ? AND group_id = ?
    ");
    $stmt->execute([$purchaseId, $context->groupId]);
    $purchase = $stmt->fetch();

    if (!$purchase) {
        jsonFail('קנייה לא נמצאה');
        return;
    }

    if (!$context->isOwner && $purchase['user_id'] != $context->userId) {
        jsonFail('אין הרשאה למחוק קנייה זו');
        return;
    }

    $context->pdo->beginTransaction();
    try {
        // ניקוי ידני למקרה שהמפתחות הזרים לא הותקנו
        if ($context->featuresReady) {
            $stmt = $context->pdo->prepare("DELETE FROM purchase_exclusions WHERE purchase_id = ?");
            $stmt->execute([$purchaseId]);

            $stmt = $context->pdo->prepare("
                UPDATE shopping_items SET purchase_id = NULL, status = 'needed' WHERE purchase_id = ?
            ");
            $stmt->execute([$purchaseId]);
        }

        $stmt = $context->pdo->prepare("DELETE FROM group_purchases WHERE id = ? AND group_id = ?");
        $stmt->execute([$purchaseId, $context->groupId]);

        $context->pdo->commit();
    } catch (Exception $e) {
        $context->pdo->rollBack();
        throw $e;
    }

    deleteReceiptImage($purchase['image_path']);

    jsonOk();
}

function notifyNewPurchaseSafely($purchaseId) {
    try {
        // טוענים את קובץ ההתראות רק אם הפונקציה עוד לא מוגדרת
        if (!function_exists('notifyNewPurchase')) {
            $notificationFile = dirname(__DIR__) . '/api/send-push-notification.php';
            if (!file_exists($notificationFile)) {
                return;
            }
            require_once $notificationFile;
        }

        if (function_exists('notifyNewPurchase')) {
            notifyNewPurchase($purchaseId);
        }
    } catch (Exception $e) {
        error_log("Error sending purchase notifications: " . $e->getMessage());
    }
}

// ============================================================
// רשימת קניות - "מה צריך להביא"
// ============================================================

function actionAddItem(GroupContext $context) {
    $title    = trim($_POST['title'] ?? '');
    $quantity = trim($_POST['quantity'] ?? '');
    $notes    = trim($_POST['notes'] ?? '');

    if ($title === '') {
        jsonFail('יש להזין שם פריט');
        return;
    }

    $stmt = $context->pdo->prepare("
        SELECT COALESCE(MAX(sort_order), 0) + 1 FROM shopping_items WHERE group_id = ?
    ");
    $stmt->execute([$context->groupId]);
    $sortOrder = (int)$stmt->fetchColumn();

    $stmt = $context->pdo->prepare("
        INSERT INTO shopping_items (group_id, title, quantity, notes, created_by, sort_order)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $context->groupId, $title,
        $quantity !== '' ? $quantity : null,
        $notes !== '' ? $notes : null,
        $context->userId, $sortOrder,
    ]);

    jsonOk(['item_id' => (int)$context->pdo->lastInsertId()]);
}

function actionUpdateItem(GroupContext $context) {
    $itemId   = intval($_POST['item_id'] ?? 0);
    $title    = trim($_POST['title'] ?? '');
    $quantity = trim($_POST['quantity'] ?? '');
    $notes    = trim($_POST['notes'] ?? '');

    if ($title === '') {
        jsonFail('יש להזין שם פריט');
        return;
    }

    $stmt = $context->pdo->prepare("
        UPDATE shopping_items SET title = ?, quantity = ?, notes = ?
        WHERE id = ? AND group_id = ?
    ");
    $stmt->execute([
        $title,
        $quantity !== '' ? $quantity : null,
        $notes !== '' ? $notes : null,
        $itemId, $context->groupId,
    ]);

    jsonOk();
}

/**
 * משנה את מצב הפריט: צריך / מישהו לקח על עצמו / נקנה.
 */
function actionSetItemStatus(GroupContext $context) {
    $itemId = intval($_POST['item_id'] ?? 0);
    $status = $_POST['status'] ?? '';

    if (!in_array($status, ['needed', 'claimed', 'bought'], true)) {
        jsonFail('סטטוס לא תקין');
        return;
    }

    $stmt = $context->pdo->prepare("
        SELECT assigned_member_id FROM shopping_items WHERE id = ? AND group_id = ?
    ");
    $stmt->execute([$itemId, $context->groupId]);
    $item = $stmt->fetch();

    if (!$item) {
        jsonFail('הפריט לא נמצא');
        return;
    }

    // "לקחתי על עצמי" משייך את הפריט למי שלחץ
    $assignedMemberId = null;
    if ($status === 'claimed') {
        $assignedMemberId = $context->memberId;
    } elseif ($status === 'bought') {
        $assignedMemberId = $item['assigned_member_id'] ?: $context->memberId;
    }

    $stmt = $context->pdo->prepare("
        UPDATE shopping_items SET status = ?, assigned_member_id = ?
        WHERE id = ? AND group_id = ?
    ");
    $stmt->execute([$status, $assignedMemberId, $itemId, $context->groupId]);

    jsonOk();
}

function actionDeleteItem(GroupContext $context) {
    $itemId = intval($_POST['item_id'] ?? 0);

    $stmt = $context->pdo->prepare("
        SELECT created_by FROM shopping_items WHERE id = ? AND group_id = ?
    ");
    $stmt->execute([$itemId, $context->groupId]);
    $item = $stmt->fetch();

    if (!$item) {
        jsonFail('הפריט לא נמצא');
        return;
    }

    if (!$context->isOwner && $item['created_by'] != $context->userId) {
        jsonFail('אין הרשאה למחוק פריט זה');
        return;
    }

    $stmt = $context->pdo->prepare("DELETE FROM shopping_items WHERE id = ? AND group_id = ?");
    $stmt->execute([$itemId, $context->groupId]);

    jsonOk();
}

// ============================================================
// התחשבנות
// ============================================================

function actionAddSettlement(GroupContext $context) {
    $fromMemberId = intval($_POST['from_member_id'] ?? 0);
    $toMemberId   = intval($_POST['to_member_id'] ?? 0);
    $amount       = round(floatval($_POST['amount'] ?? 0), 2);
    $note         = trim($_POST['note'] ?? '');

    if ($amount <= 0) {
        jsonFail('סכום ההעברה חייב להיות חיובי');
        return;
    }

    if ($fromMemberId === $toMemberId) {
        jsonFail('לא ניתן לרשום העברה מאדם לעצמו');
        return;
    }

    $stmt = $context->pdo->prepare("
        SELECT id FROM group_members WHERE id IN (?, ?) AND group_id = ? AND is_active = 1
    ");
    $stmt->execute([$fromMemberId, $toMemberId, $context->groupId]);
    if (count($stmt->fetchAll()) !== 2) {
        jsonFail('אחד המשתתפים אינו פעיל בקבוצה');
        return;
    }

    // מנהל, או אחד משני הצדדים להעברה
    $isParty = ($context->memberId === $fromMemberId || $context->memberId === $toMemberId);
    if (!$context->isOwner && !$isParty) {
        jsonFail('רק מנהל הקבוצה או אחד הצדדים יכולים לרשום את ההעברה');
        return;
    }

    $stmt = $context->pdo->prepare("
        INSERT INTO settlements (group_id, from_member_id, to_member_id, amount, note, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $context->groupId, $fromMemberId, $toMemberId,
        $amount, $note !== '' ? $note : null, $context->userId,
    ]);

    jsonOk(['settlement_id' => (int)$context->pdo->lastInsertId()]);
}

function actionDeleteSettlement(GroupContext $context) {
    $settlementId = intval($_POST['settlement_id'] ?? 0);

    $stmt = $context->pdo->prepare("
        SELECT created_by, from_member_id, to_member_id
        FROM settlements WHERE id = ? AND group_id = ?
    ");
    $stmt->execute([$settlementId, $context->groupId]);
    $settlement = $stmt->fetch();

    if (!$settlement) {
        jsonFail('ההתחשבנות לא נמצאה');
        return;
    }

    $isParty = in_array($context->memberId, [
        (int)$settlement['from_member_id'],
        (int)$settlement['to_member_id'],
    ], true);

    if (!$context->isOwner && !$isParty && $settlement['created_by'] != $context->userId) {
        jsonFail('אין הרשאה לבטל התחשבנות זו');
        return;
    }

    $stmt = $context->pdo->prepare("DELETE FROM settlements WHERE id = ? AND group_id = ?");
    $stmt->execute([$settlementId, $context->groupId]);

    jsonOk();
}

// ============================================================
// האירוע עצמו
// ============================================================

function actionUpdateEvent(GroupContext $context) {
    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $eventDate   = trim($_POST['event_date'] ?? '');
    $location    = trim($_POST['event_location'] ?? '');

    if ($name === '') {
        jsonFail('יש להזין שם לאירוע');
        return;
    }

    if ($eventDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
        jsonFail('תאריך לא תקין');
        return;
    }

    $stmt = $context->pdo->prepare("
        UPDATE purchase_groups
        SET name = ?, description = ?, event_date = ?, event_location = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $name,
        $description !== '' ? $description : null,
        $eventDate !== '' ? $eventDate : null,
        $location !== '' ? $location : null,
        $context->groupId,
    ]);

    jsonOk();
}

function actionCloseEvent(GroupContext $context) {
    $stmt = $context->pdo->prepare("
        UPDATE purchase_groups SET status = 'closed', closed_at = NOW() WHERE id = ?
    ");
    $stmt->execute([$context->groupId]);

    jsonOk(['message' => 'האירוע נסגר']);
}

function actionReopenEvent(GroupContext $context) {
    $stmt = $context->pdo->prepare("
        UPDATE purchase_groups SET status = 'active', closed_at = NULL WHERE id = ?
    ");
    $stmt->execute([$context->groupId]);

    jsonOk(['message' => 'האירוע נפתח מחדש']);
}
