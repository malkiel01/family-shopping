<?php
// includes/group_actions.php
// טיפול בכל פעולות ה-AJAX של הקבוצה

require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/participation.php';
require_once __DIR__ . '/contacts.php';
require_once __DIR__ . '/debug_log.php';

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
    'splitEqually', 'setSplitMode', 'updateEvent', 'closeEvent', 'reopenEvent',
];

/**
 * פעולות שאסורות כשהאירוע סגור.
 */
const BLOCKED_WHEN_CLOSED = [
    'addMember', 'removeMember', 'editMember', 'cancelInvitation',
    'splitEqually', 'setSplitMode',
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
        'setSplitMode'     => 'actionSetSplitMode',
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
    $participationType  = participationTypeFromRequest();
    $participationValue = participationValueFromRequest($participationType);

    if ($email === '' || $nickname === '') {
        jsonFail('יש למלא אימייל וכינוי');
        return;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonFail('כתובת האימייל אינה תקינה');
        return;
    }

    if ($participationValue <= 0) {
        jsonFail($participationType === 'shares'
            ? 'מספר הנפשות חייב להיות לפחות 1'
            : 'ערך ההשתתפות חייב להיות חיובי');
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

    // כל הזמנה מוסיפה את הנמען לאנשי הקשר, כדי שבאירוע הבא
    // אפשר יהיה לבחור אותו במקום להקליד מחדש
    rememberContact(
        $context->pdo, $context->userId, $email, $nickname,
        $participationType, $participationValue
    );

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

/**
 * מכניס התראה לתור עבור משתמש אחד.
 *
 * שתי נקודות שחייבות להישמר כאן, כי בלעדיהן ההתראה נכנסת לתור
 * ולא מוצגת לעולם:
 *   1. user_id נשמר בעמודה עצמה ולא רק בתוך ה-JSON. הצרכן שולף
 *      לפי העמודה, ולכן התראה בלי העמודה הזו פשוט לא נמצאת.
 *   2. title ו-body נשמרים בתוך data. הצרכן קורא אותם משם, ובלעדיהם
 *      מוצגת התראה ריקה עם הכותרת הגנרית "התראה".
 *
 * @param array $extra שדות נוספים שנשמרים ב-data לשימוש הלקוח
 */
/** שם האירוע, נשלף פעם אחת לכל בקשה */
function groupDisplayName(GroupContext $context) {
    static $names = [];

    if (!isset($names[$context->groupId])) {
        $stmt = $context->pdo->prepare("SELECT name FROM purchase_groups WHERE id = ?");
        $stmt->execute([$context->groupId]);
        $names[$context->groupId] = $stmt->fetchColumn() ?: 'האירוע';
    }

    return $names[$context->groupId];
}

/**
 * מודיע לכל המשתתפים הפעילים באירוע, חוץ ממי שביצע את הפעולה.
 * מי שעדיין לא נרשם למערכת (אין לו user_id) פשוט מדולג.
 */
function notifyGroupMembers(GroupContext $context, $type, $title, $body, array $extra = []) {
    try {
        $stmt = $context->pdo->prepare("
            SELECT DISTINCT user_id
            FROM group_members
            WHERE group_id = ? AND is_active = 1
              AND user_id IS NOT NULL AND user_id != ?
        ");
        $stmt->execute([$context->groupId, $context->userId]);

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
            queueNotification($context->pdo, $userId, $type, $title, $body, array_merge([
                'group_id' => $context->groupId,
                'url'      => groupUrl($context->groupId),
            ], $extra));
        }
    } catch (Exception $e) {
        error_log("Failed to notify group members: " . $e->getMessage());
    }
}

/** מודיע למשתתף יחיד לפי מזהה השורה בטבלת המשתתפים */
function notifyMember(GroupContext $context, $memberId, $type, $title, $body, array $extra = []) {
    try {
        $stmt = $context->pdo->prepare("SELECT user_id FROM group_members WHERE id = ? AND group_id = ?");
        $stmt->execute([$memberId, $context->groupId]);
        $userId = $stmt->fetchColumn();

        if (!$userId || (int)$userId === (int)$context->userId) {
            return;
        }

        queueNotification($context->pdo, $userId, $type, $title, $body, array_merge([
            'group_id' => $context->groupId,
            'url'      => groupUrl($context->groupId),
        ], $extra));
    } catch (Exception $e) {
        error_log("Failed to notify member: " . $e->getMessage());
    }
}

function queueInvitationNotification(GroupContext $context, $invitationId, $invitedUserId) {
    $stmt = $context->pdo->prepare("SELECT name FROM purchase_groups WHERE id = ?");
    $stmt->execute([$context->groupId]);
    $groupName = $stmt->fetchColumn() ?: 'קבוצה';

    $inviter = $_SESSION['name'] ?? 'מנהל האירוע';

    queueNotification(
        $context->pdo,
        $invitedUserId,
        'invitation',
        'הזמנה לאירוע משפחתי',
        sprintf('%s הזמין אותך להצטרף ל"%s"', $inviter, $groupName),
        [
            'invitation_id' => (int)$invitationId,
            'group_id'      => $context->groupId,
            'group_name'    => $groupName,
            'url'           => (defined('APP_BASE_PATH') ? APP_BASE_PATH : '') . '/dashboard.php',
        ]
    );
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

/**
 * קובע את שיטת החלוקה של כל הקבוצה בבת אחת.
 *
 * percentage  - כולם באחוזים, מחולק שווה בשווה
 * shares      - כולם בנפשות. מי שכבר הוגדר בנפשות שומר על המספר
 *               שלו, וכל השאר מתחילים מנפש אחת
 * shares_rate - מצב מעורב: נקבע תעריף קבוע לנפש, וכך אפשר להחזיק
 *               חלק מהמשתתפים באחוזים וחלקם בנפשות באותה קבוצה
 */
function actionSetSplitMode(GroupContext $context) {
    $mode = $_POST['mode'] ?? '';
    $rate = round(floatval($_POST['share_rate'] ?? 0), 2);

    if (!in_array($mode, ['percentage', 'shares', 'shares_rate'], true)) {
        jsonFail('שיטת חלוקה לא מוכרת');
        return;
    }

    if ($mode === 'shares_rate' && $rate <= 0) {
        jsonFail('יש לקבוע תעריף לנפש');
        return;
    }

    $stmt = $context->pdo->prepare("
        SELECT id, participation_type, participation_value
        FROM group_members
        WHERE group_id = ? AND is_active = 1
        ORDER BY joined_at, id
    ");
    $stmt->execute([$context->groupId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$members) {
        jsonFail('אין משתתפים פעילים בקבוצה');
        return;
    }

    if ($mode === 'shares_rate') {
        $hasShares = false;
        foreach ($members as $member) {
            if ($member['participation_type'] === 'shares') {
                $hasShares = true;
                break;
            }
        }

        if (!$hasShares) {
            jsonFail('אין אף משתתף שמוגדר לפי נפשות. יש להגדיר לפחות אחד כזה');
            return;
        }
    }

    $context->pdo->beginTransaction();
    try {
        $update = $context->pdo->prepare("
            UPDATE group_members
            SET participation_type = ?, participation_value = ?
            WHERE id = ? AND group_id = ?
        ");

        if ($mode === 'percentage') {
            $shares = equalPercentageShares(count($members));

            foreach ($members as $index => $member) {
                $update->execute(['percentage', $shares[$index], $member['id'], $context->groupId]);
            }
        } elseif ($mode === 'shares') {
            foreach ($members as $member) {
                // מי שכבר בנפשות שומר על המספר שלו
                $value = $member['participation_type'] === 'shares'
                    ? max(1, (int)round((float)$member['participation_value']))
                    : 1;
                $update->execute(['shares', $value, $member['id'], $context->groupId]);
            }
        }

        // התעריף נשמר רק במצב המעורב, ומתאפס בשני האחרים
        $stmt = $context->pdo->prepare("UPDATE purchase_groups SET share_rate = ? WHERE id = ?");
        $stmt->execute([$mode === 'shares_rate' ? $rate : null, $context->groupId]);

        $context->pdo->commit();
    } catch (Exception $e) {
        $context->pdo->rollBack();
        throw $e;
    }

    $symbol      = currencySymbol();
    $description = [
        'percentage'  => 'אחוזים, שווה בשווה בין כולם',
        'shares'      => 'לפי נפשות',
        'shares_rate' => 'לפי נפשות בתעריף ' . $symbol . number_format($rate, 2) . ' לנפש',
    ][$mode];

    notifyGroupMembers(
        $context, 'split_mode',
        'שיטת החלוקה ב"' . groupDisplayName($context) . '" השתנתה',
        actorName() . ' עדכן את החלוקה: ' . $description
    );

    jsonOk(['message' => 'שיטת החלוקה עודכנה: ' . $description]);
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

    notifyMember(
        $context, $memberId, 'member_removed',
        'הוסרת מ"' . groupDisplayName($context) . '"',
        actorName() . ' הסיר אותך מהאירוע',
        ['url' => (defined('APP_BASE_PATH') ? APP_BASE_PATH : '') . '/dashboard.php']
    );

    jsonOk();
}

function actionEditMember(GroupContext $context) {
    $memberId           = intval($_POST['member_id'] ?? 0);
    $participationType  = participationTypeFromRequest();
    $participationValue = participationValueFromRequest($participationType);

    if ($participationValue <= 0) {
        jsonFail($participationType === 'shares'
            ? 'מספר הנפשות חייב להיות לפחות 1'
            : 'ערך ההשתתפות חייב להיות חיובי');
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

    $share = participationLabel($participationType, $participationValue);

    notifyMember(
        $context, $memberId, 'share_changed',
        'חלקך ב"' . groupDisplayName($context) . '" עודכן',
        actorName() . ' עדכן את חלקך ל-' . $share
    );

    jsonOk();
}

/**
 * מחלק 100% שווה בשווה בין כל החברים הפעילים.
 * זה המקרה הנפוץ ביותר, ובלעדיו המשתמש צריך לחשב ידנית.
 */
/**
 * מחלק 100% בין מספר נתון של משתתפים, בצורה שווה ככל שאפשר.
 *
 * 100 חלקי 7 אינו מספר עגול, ולכן מישהו תמיד יקבל קצת יותר.
 * השאלה היא כמה. הגרסה הקודמת עיגלה כלפי מטה וזרקה את כל
 * השארית על האחרון, כך שהוא קיבל 14.32% מול 14.28% של כולם -
 * פער של 0.04. כאן השארית מפוזרת באגורות בודדות, ולכן הפער
 * המרבי בין שני משתתפים הוא 0.01 בלבד.
 *
 * החישוב נעשה במאיות שלמות כדי להימנע משגיאות נקודה צפה.
 *
 * @return float[] מערך ערכים באורך $count, שסכומו בדיוק 100
 */
function equalPercentageShares($count) {
    if ($count <= 0) {
        return [];
    }

    $totalUnits = 10000;                      // 100.00% במאיות
    $base       = intdiv($totalUnits, $count);
    $leftover   = $totalUnits % $count;

    $shares = [];
    for ($i = 0; $i < $count; $i++) {
        // המאיות שנותרו מתחלקות אחת-אחת בין הראשונים
        $units    = $base + ($i < $leftover ? 1 : 0);
        $shares[] = $units / 100;
    }

    return $shares;
}

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

    $shares = equalPercentageShares($count);

    $context->pdo->beginTransaction();
    try {
        $stmt = $context->pdo->prepare("
            UPDATE group_members
            SET participation_type = 'percentage', participation_value = ?
            WHERE id = ? AND group_id = ?
        ");

        foreach ($memberIds as $index => $memberId) {
            $stmt->execute([$shares[$index], $memberId, $context->groupId]);
        }

        $context->pdo->commit();
    } catch (Exception $e) {
        $context->pdo->rollBack();
        throw $e;
    }

    // כשהחלוקה אינה עגולה, חלק מהמשתתפים מקבלים אגורה אחת יותר,
    // ולכן ההודעה מציגה את הטווח ולא מספר יחיד
    $low     = min($shares);
    $high    = max($shares);
    $summary = ($low === $high) ? "{$low}% לכל משתתף" : "{$low}%-{$high}% לכל משתתף";

    notifyGroupMembers(
        $context, 'share_changed',
        'החלוקה ב"' . groupDisplayName($context) . '" עודכנה',
        actorName() . ' חילק את ההוצאות שווה בשווה - ' . $summary
    );

    jsonOk(['message' => 'החלוקה עודכנה - ' . $summary]);
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

    notifyNewPurchaseSafely($context, $purchaseId, $amount, $description);

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
        SELECT user_id, member_id FROM group_purchases WHERE id = ? AND group_id = ?
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

    // העברת בעלות על הקנייה שמורה למנהל, בדיוק כמו הרשאתו
    // לרשום קנייה על שם משתתף אחר מלכתחילה
    $memberId = (int)$purchase['member_id'];
    if ($context->isOwner && isset($_POST['member_id'])) {
        $requested = intval($_POST['member_id']);

        if ($requested !== $memberId) {
            $stmt = $context->pdo->prepare("
                SELECT id FROM group_members WHERE id = ? AND group_id = ? AND is_active = 1
            ");
            $stmt->execute([$requested, $context->groupId]);

            if (!$stmt->fetch()) {
                jsonFail('יש לבחור משתתף תקין');
                return;
            }

            $memberId = $requested;
        }
    }

    $exclusions = readExclusions($context);
    if (!$exclusions['ok']) {
        jsonFail($exclusions['message']);
        return;
    }

    $context->pdo->beginTransaction();
    try {
        $stmt = $context->pdo->prepare("
            UPDATE group_purchases
            SET amount = ?, description = ?, member_id = ?
            WHERE id = ? AND group_id = ?
        ");
        $stmt->execute([$amount, $description, $memberId, $purchaseId, $context->groupId]);

        saveExclusions($context, $purchaseId, $exclusions['ids']);

        $context->pdo->commit();

        $symbol = currencySymbol();
        $body   = actorName() . ' עדכן קנייה ל-' . $symbol . number_format((float)$amount, 2)
            . ($description !== '' ? ' (' . $description . ')' : '');

        // שינוי בעלות הוא שינוי מהותי בחשבון, ולכן מצוין במפורש
        if ($memberId !== (int)$purchase['member_id']) {
            $stmt = $context->pdo->prepare("SELECT nickname FROM group_members WHERE id = ?");
            $stmt->execute([$memberId]);
            $body .= '. הקנייה נרשמה עכשיו על ' . ($stmt->fetchColumn() ?: 'משתתף אחר');
        }

        notifyGroupMembers(
            $context, 'purchase_updated',
            'קנייה עודכנה ב"' . groupDisplayName($context) . '"',
            $body,
            ['purchase_id' => (int)$purchaseId]
        );
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

    notifyGroupMembers(
        $context, 'purchase_deleted',
        'קנייה נמחקה מ"' . groupDisplayName($context) . '"',
        actorName() . ' מחק קנייה מהאירוע'
    );

    jsonOk();
}

/**
 * מודיע לשאר המשתתפים באירוע שנוספה קנייה.
 *
 * הגרסה הקודמת קראה ל-notifyNewPurchase() מתוך
 * api/send-push-notification.php - פונקציה שאינה מוגדרת שם כלל.
 * הקריאה הייתה עטופה ב-function_exists, ולכן לא קרסה, אבל גם
 * מעולם לא שלחה דבר.
 */
function notifyNewPurchaseSafely(GroupContext $context, $purchaseId, $amount, $description) {
    try {
        $stmt = $context->pdo->prepare("SELECT name FROM purchase_groups WHERE id = ?");
        $stmt->execute([$context->groupId]);
        $groupName = $stmt->fetchColumn() ?: 'האירוע';

        // כל המשתתפים הפעילים שיש להם חשבון, חוץ ממי שרשם את הקנייה
        $stmt = $context->pdo->prepare("
            SELECT DISTINCT user_id
            FROM group_members
            WHERE group_id = ? AND is_active = 1
              AND user_id IS NOT NULL AND user_id != ?
        ");
        $stmt->execute([$context->groupId, $context->userId]);
        $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!$recipients) {
            return;
        }

        $buyer  = $_SESSION['name'] ?? 'משתתף';
        $symbol = currencySymbol();
        $body   = $description !== ''
            ? sprintf('%s רשם קנייה: %s, %s%s', $buyer, $description, $symbol, number_format((float)$amount, 2))
            : sprintf('%s רשם קנייה על %s%s', $buyer, $symbol, number_format((float)$amount, 2));

        foreach ($recipients as $userId) {
            queueNotification(
                $context->pdo,
                $userId,
                'purchase',
                'קנייה חדשה ב"' . $groupName . '"',
                $body,
                [
                    'purchase_id' => (int)$purchaseId,
                    'group_id'    => $context->groupId,
                    'url'         => (defined('APP_BASE_PATH') ? APP_BASE_PATH : '')
                        . '/group.php?id=' . $context->groupId,
                ]
            );
        }
    } catch (Exception $e) {
        error_log('Error queueing purchase notifications: ' . $e->getMessage());
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

    // חייב להילקח לפני ההתראה: היא מבצעת INSERT משלה,
    // ואחריה lastInsertId כבר מצביע על שורת ההתראה
    $itemId = (int)$context->pdo->lastInsertId();

    notifyGroupMembers(
        $context, 'item_added',
        'פריט חדש ברשימה של "' . groupDisplayName($context) . '"',
        actorName() . ' הוסיף לרשימה: ' . $title . ($quantity !== '' ? ' (' . $quantity . ')' : '')
    );

    jsonOk(['item_id' => $itemId]);
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

    $titleStmt = $context->pdo->prepare("SELECT title FROM shopping_items WHERE id = ?");
    $titleStmt->execute([$itemId]);
    $itemTitle = $titleStmt->fetchColumn() ?: 'פריט';

    $wording = [
        'claimed' => ' לקח על עצמו להביא: ',
        'bought'  => ' קנה: ',
        'needed'  => ' החזיר לרשימה: ',
    ];

    notifyGroupMembers(
        $context, 'item_status',
        'עדכון ברשימה של "' . groupDisplayName($context) . '"',
        actorName() . $wording[$status] . $itemTitle
    );

    jsonOk();
}

function actionDeleteItem(GroupContext $context) {
    $itemId = intval($_POST['item_id'] ?? 0);

    $stmt = $context->pdo->prepare("
        SELECT created_by, title FROM shopping_items WHERE id = ? AND group_id = ?
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

    notifyGroupMembers(
        $context, 'item_deleted',
        'פריט הוסר מהרשימה של "' . groupDisplayName($context) . '"',
        actorName() . ' הסיר מהרשימה: ' . $item['title']
    );

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

    // תשלום והעברת חוב זהים במאזן ושונים במשמעות. ערך לא מוכר
    // נופל לתשלום, כדי ששום דבר שרירותי לא יגיע לעמודת ה-enum.
    $isTransfer = (($_POST['type'] ?? '') === 'transfer');

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

    // העמודה נוספה במיגרציה 011. עד שהיא תרוץ, העברת חוב נרשמת
    // כהתחשבנות רגילה - המאזן יוצא נכון, רק ההבחנה חסרה.
    $hasType = settlementTypesReady($context->pdo);

    // הכתיבה עטופה ביומן החישובים: כשהוא דלוק נשמר צילום של
    // המאזן לפני ואחרי, וזו הדרך היחידה לבדוק בדיעבד טענה כמו
    // "רשמתי תשלום והחישוב השתבש". כשהוא כבוי זה קורא לסגור ותו לא.
    $settlementId = debugAround(
        $context->pdo, $context->groupId, $context->userId,
        $isTransfer ? 'transfer' : 'payment',
        [
            'from_member_id' => $fromMemberId,
            'to_member_id'   => $toMemberId,
            'amount'         => $amount,
        ],
        function () use ($context, $hasType, $fromMemberId, $toMemberId, $amount, $note, $isTransfer) {
            if ($hasType) {
                $stmt = $context->pdo->prepare("
                    INSERT INTO settlements (group_id, from_member_id, to_member_id, amount, note, created_by, type)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $context->groupId, $fromMemberId, $toMemberId,
                    $amount, $note !== '' ? $note : null, $context->userId,
                    $isTransfer ? 'transfer' : 'payment',
                ]);
            } else {
                $stmt = $context->pdo->prepare("
                    INSERT INTO settlements (group_id, from_member_id, to_member_id, amount, note, created_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $context->groupId, $fromMemberId, $toMemberId,
                    $amount, $note !== '' ? $note : null, $context->userId,
                ]);
            }

            // חייב להילקח כאן, לפני הצילום השני וההתראה - שתיהן
            // מבצעות שאילתות משלהן ומאפסות את lastInsertId
            return (int)$context->pdo->lastInsertId();
        }
    );

    // שני הצדדים מקבלים הודעה, גם אם מישהו אחר רשם אותה
    $symbol = currencySymbol();
    $sum    = $symbol . number_format($amount, 2);
    $body   = $isTransfer
        ? actorName() . ' העביר חוב של ' . $sum . ' ב"' . groupDisplayName($context) . '"'
        : actorName() . ' רשם העברה של ' . $sum . ' ב"' . groupDisplayName($context) . '"';

    $title = $isTransfer ? 'חוב הועבר' : 'התחשבנות נרשמה';

    foreach ([$fromMemberId, $toMemberId] as $party) {
        notifyMember($context, $party, 'settlement', $title, $body);
    }

    jsonOk(['settlement_id' => $settlementId]);
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

    debugAround(
        $context->pdo, $context->groupId, $context->userId, 'delete',
        [
            'settlement_id'  => $settlementId,
            'from_member_id' => (int)$settlement['from_member_id'],
            'to_member_id'   => (int)$settlement['to_member_id'],
        ],
        function () use ($context, $settlementId) {
            $stmt = $context->pdo->prepare("DELETE FROM settlements WHERE id = ? AND group_id = ?");
            $stmt->execute([$settlementId, $context->groupId]);
        }
    );

    $body = actorName() . ' ביטל התחשבנות ב"' . groupDisplayName($context) . '"';
    foreach ([(int)$settlement['from_member_id'], (int)$settlement['to_member_id']] as $party) {
        notifyMember($context, $party, 'settlement_deleted', 'התחשבנות בוטלה', $body);
    }

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

    $details = [];
    if ($eventDate !== '') {
        $details[] = 'תאריך: ' . $eventDate;
    }
    if ($location !== '') {
        $details[] = 'מיקום: ' . $location;
    }

    notifyGroupMembers(
        $context, 'event_updated',
        'פרטי "' . $name . '" עודכנו',
        actorName() . ' עדכן את פרטי האירוע' . ($details ? ' — ' . implode(', ', $details) : '')
    );

    jsonOk();
}

function actionCloseEvent(GroupContext $context) {
    $stmt = $context->pdo->prepare("
        UPDATE purchase_groups SET status = 'closed', closed_at = NOW() WHERE id = ?
    ");
    $stmt->execute([$context->groupId]);

    notifyGroupMembers(
        $context, 'event_closed',
        '"' . groupDisplayName($context) . '" נסגר',
        actorName() . ' סגר את האירוע. אפשר עדיין לרשום התחשבנויות'
    );

    jsonOk(['message' => 'האירוע נסגר']);
}

function actionReopenEvent(GroupContext $context) {
    $stmt = $context->pdo->prepare("
        UPDATE purchase_groups SET status = 'active', closed_at = NULL WHERE id = ?
    ");
    $stmt->execute([$context->groupId]);

    notifyGroupMembers(
        $context, 'event_reopened',
        '"' . groupDisplayName($context) . '" נפתח מחדש',
        actorName() . ' פתח מחדש את האירוע'
    );

    jsonOk(['message' => 'האירוע נפתח מחדש']);
}
