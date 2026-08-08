<?php
/**
 * שכבת הניהול
 * includes/admin.php
 *
 * הרשאת הניהול היא לכלל המערכת: היא חושפת את כל המשתמשים, את
 * כל הקבוצות ואת כל החברויות, ומאפשרת לאשר הצטרפות בשם משתמש
 * אחר. לכן:
 *
 *   1. ההרשאה נשמרת בעמודה users.is_admin ונבדקת מול מסד הנתונים
 *      בכל בקשה - לא מתוך ה-session, שאותו אפשר להזין בהתחברות
 *      ישנה שקדמה לשלילת ההרשאה.
 *   2. כל פעולה שמשנה מצב נרשמת ב-admin_actions, כדי שתמיד יהיה
 *      אפשר לענות על "מי אישר את זה ומתי".
 */

require_once __DIR__ . '/notifications.php';

/** האם עמודת ההרשאה קיימת. נבדק פעם אחת לכל בקשה. */
function adminSchemaReady(PDO $pdo) {
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_admin'
        ");
        $stmt->execute();
        $ready = ((int)$stmt->fetchColumn() === 1);
    } catch (Exception $e) {
        error_log('Admin schema check failed: ' . $e->getMessage());
        $ready = false;
    }

    return $ready;
}

/**
 * האם המשתמש הנוכחי הוא מנהל.
 *
 * נבדק מול מסד הנתונים ולא מול ה-session בכוונה: אם ההרשאה
 * נשללת, היא נשללת מיד ולא רק אחרי התחברות מחדש.
 */
function isSystemAdmin(PDO $pdo, $userId) {
    if (!adminSchemaReady($pdo)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([(int)$userId]);

        return (int)$stmt->fetchColumn() === 1;
    } catch (Exception $e) {
        error_log('Admin check failed: ' . $e->getMessage());

        return false;
    }
}

/** עוצר את הבקשה אם המשתמש אינו מנהל */
function requireSystemAdmin(PDO $pdo, $userId) {
    if (isSystemAdmin($pdo, $userId)) {
        return;
    }

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'אין הרשאת ניהול'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Location: ' . (defined('APP_BASE_PATH') ? APP_BASE_PATH : '') . '/dashboard.php');
    exit;
}

/** רושם פעולת ניהול ביומן */
function logAdminAction(PDO $pdo, $adminId, $action, $targetType = null, $targetId = null, $details = null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO admin_actions (admin_id, action, target_type, target_id, details)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([(int)$adminId, $action, $targetType, $targetId, $details]);
    } catch (Exception $e) {
        error_log('Failed to log admin action: ' . $e->getMessage());
    }
}

// ============================================================
// שאילתות התצוגה
// ============================================================

/**
 * כל המשתמשים, עם מספר הקבוצות שכל אחד מנהל ושהוא חבר בהן.
 */
function adminListUsers(PDO $pdo) {
    $stmt = $pdo->query("
        SELECT u.id, u.name, u.email, u.username, u.is_active, u.is_admin,
               u.created_at, u.last_login,
               (SELECT COUNT(*) FROM purchase_groups pg
                 WHERE pg.owner_id = u.id AND pg.is_active = 1) AS owned_groups,
               (SELECT COUNT(*) FROM group_members gm
                 JOIN purchase_groups pg2 ON pg2.id = gm.group_id
                 WHERE gm.user_id = u.id AND gm.is_active = 1 AND pg2.is_active = 1) AS member_groups,
               (SELECT COUNT(*) FROM group_invitations gi
                 WHERE gi.email = u.email AND gi.status = 'pending') AS pending_invitations
        FROM users u
        ORDER BY u.id
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * הקבוצות של משתמש מסוים, עם רשימת החברים בכל אחת.
 */
function adminUserGroups(PDO $pdo, $userId) {
    $stmt = $pdo->prepare("
        SELECT pg.id, pg.name, pg.status, pg.event_date, pg.is_active,
               pg.owner_id, owner.name AS owner_name,
               (pg.owner_id = ?) AS is_owner
        FROM purchase_groups pg
        LEFT JOIN users owner ON owner.id = pg.owner_id
        JOIN group_members gm ON gm.group_id = pg.id
        WHERE gm.user_id = ? AND gm.is_active = 1
        GROUP BY pg.id
        ORDER BY pg.id DESC
    ");
    $stmt->execute([(int)$userId, (int)$userId]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$groups) {
        return [];
    }

    $membersStmt = $pdo->prepare("
        SELECT gm.id, gm.nickname, gm.email, gm.participation_type, gm.participation_value,
               gm.user_id, u.name AS user_name
        FROM group_members gm
        LEFT JOIN users u ON u.id = gm.user_id
        WHERE gm.group_id = ? AND gm.is_active = 1
        ORDER BY gm.joined_at, gm.id
    ");

    $invitesStmt = $pdo->prepare("
        SELECT gi.id, gi.email, gi.nickname, gi.status, gi.created_at,
               gi.participation_type, gi.participation_value,
               (SELECT u.id FROM users u WHERE u.email = gi.email) AS invitee_user_id
        FROM group_invitations gi
        WHERE gi.group_id = ? AND gi.status = 'pending'
        ORDER BY gi.created_at DESC
    ");

    foreach ($groups as &$group) {
        $membersStmt->execute([$group['id']]);
        $group['members'] = $membersStmt->fetchAll(PDO::FETCH_ASSOC);

        $invitesStmt->execute([$group['id']]);
        $group['pending'] = $invitesStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($group);

    return $groups;
}

/**
 * מאשר הצטרפות בשם המוזמן.
 *
 * זו פעולה בשם אדם אחר, ולכן היא מוגבלת למקרה אחד בלבד: הזמנה
 * שממתינה, שנשלחה לכתובת שכבר רשומה במערכת. אי אפשר "לאשר"
 * הזמנה של מי שאין לו חשבון - אין למי לשייך את החברות.
 *
 * @return array{ok: bool, message: string}
 */
function adminForceAcceptInvitation(PDO $pdo, $adminId, $invitationId) {
    $stmt = $pdo->prepare("
        SELECT gi.*, pg.name AS group_name
        FROM group_invitations gi
        JOIN purchase_groups pg ON pg.id = gi.group_id
        WHERE gi.id = ?
    ");
    $stmt->execute([(int)$invitationId]);
    $invitation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invitation) {
        return ['ok' => false, 'message' => 'ההזמנה לא נמצאה'];
    }

    if ($invitation['status'] !== 'pending') {
        return ['ok' => false, 'message' => 'ההזמנה כבר טופלה'];
    }

    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ? AND is_active = 1");
    $stmt->execute([$invitation['email']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return [
            'ok'      => false,
            'message' => 'למוזמן אין עדיין חשבון במערכת, ולכן אי אפשר לאשר בשמו',
        ];
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT id FROM group_members WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$invitation['group_id'], $user['id']]);
        $existing = $stmt->fetch();

        if ($existing) {
            $stmt = $pdo->prepare("
                UPDATE group_members
                SET is_active = 1, nickname = ?, email = ?,
                    participation_type = ?, participation_value = ?, joined_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $invitation['nickname'], $invitation['email'],
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
                $invitation['group_id'], $user['id'],
                $invitation['nickname'], $invitation['email'],
                $invitation['participation_type'], $invitation['participation_value'],
            ]);
        }

        $stmt = $pdo->prepare("
            UPDATE group_invitations SET status = 'accepted', responded_at = NOW() WHERE id = ?
        ");
        $stmt->execute([$invitation['id']]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Force accept failed: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'האישור נכשל'];
    }

    logAdminAction(
        $pdo, $adminId, 'force_accept_invitation', 'invitation', (int)$invitation['id'],
        sprintf('צירוף %s לקבוצה "%s"', $invitation['email'], $invitation['group_name'])
    );

    // המשתמש חייב לדעת שצורף, גם אם לא הוא זה שאישר
    queueNotification(
        $pdo, $user['id'], 'invitation_accepted',
        'צורפת לאירוע "' . $invitation['group_name'] . '"',
        'מנהל המערכת אישר עבורך את ההצטרפות',
        ['group_id' => (int)$invitation['group_id'], 'url' => groupUrl($invitation['group_id'])]
    );

    // ומנהל האירוע צריך לדעת שהמשתתף נוסף
    notifyGroupOwner(
        $pdo, $invitation['group_id'], $adminId, 'invitation_accepted',
        'הצטרפות לאירוע אושרה',
        sprintf('%s צורף ל"%s" על ידי מנהל המערכת', $user['name'], $invitation['group_name'])
    );

    return [
        'ok'      => true,
        'message' => sprintf('%s צורף לקבוצה "%s"', $user['name'], $invitation['group_name']),
    ];
}

/** סטטיסטיקה כללית לראש הדף */
function adminOverview(PDO $pdo) {
    $one = function ($sql) use ($pdo) {
        try {
            return (int)$pdo->query($sql)->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    };

    return [
        'users'    => $one("SELECT COUNT(*) FROM users"),
        'groups'   => $one("SELECT COUNT(*) FROM purchase_groups WHERE is_active = 1"),
        'members'  => $one("SELECT COUNT(*) FROM group_members WHERE is_active = 1"),
        'pending'  => $one("SELECT COUNT(*) FROM group_invitations WHERE status = 'pending'"),
    ];
}
