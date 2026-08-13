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
require_once __DIR__ . '/participation.php';
require_once __DIR__ . '/group_delete.php';

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
    // גם קבוצות שהמשתמש מנהל אך אינו חבר פעיל בהן, וגם כאלה
    // שחברותו בהן הושבתה. במסך ניהול חשוב לראות את התמונה
    // המלאה ולא רק את מה שהמשתמש עצמו רואה.
    $stmt = $pdo->prepare("
        SELECT pg.id, pg.name, pg.status, pg.event_date, pg.is_active,
               pg.owner_id, owner.name AS owner_name,
               (pg.owner_id = ?) AS is_owner,
               MAX(CASE WHEN gm.user_id = ? THEN gm.is_active ELSE NULL END) AS my_membership
        FROM purchase_groups pg
        LEFT JOIN users owner ON owner.id = pg.owner_id
        LEFT JOIN group_members gm ON gm.group_id = pg.id
        WHERE pg.owner_id = ? OR gm.user_id = ?
        GROUP BY pg.id
        ORDER BY pg.id DESC
    ");
    $stmt->execute([(int)$userId, (int)$userId, (int)$userId, (int)$userId]);
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

// ============================================================
// ניהול המשתמש עצמו
// ============================================================

/**
 * מעניק או שולל הרשאת ניהול.
 *
 * שתי מגבלות, ושתיהן נועדו למנוע נעילה מוחלטת של המערכת:
 * מנהל אינו יכול לשלול את ההרשאה מעצמו, ואי אפשר להוריד את
 * המנהל האחרון - כי אז לא יישאר איש שיכול להחזיר אותה.
 *
 * @return array{ok: bool, message: string}
 */
function adminSetUserAdmin(PDO $pdo, $adminId, $targetId, $makeAdmin) {
    $targetId  = (int)$targetId;
    $makeAdmin = (bool)$makeAdmin;

    if ($targetId === (int)$adminId && !$makeAdmin) {
        return ['ok' => false, 'message' => 'אי אפשר לשלול את ההרשאה מעצמך'];
    }

    $stmt = $pdo->prepare("SELECT id, name, is_admin FROM users WHERE id = ?");
    $stmt->execute([$targetId]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        return ['ok' => false, 'message' => 'המשתמש לא נמצא'];
    }

    if ((bool)$target['is_admin'] === $makeAdmin) {
        return ['ok' => false, 'message' => 'אין מה לשנות'];
    }

    if (!$makeAdmin) {
        $admins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 1")->fetchColumn();

        if ($admins <= 1) {
            return ['ok' => false, 'message' => 'זהו המנהל היחיד. הענק הרשאה למישהו אחר קודם'];
        }
    }

    $stmt = $pdo->prepare("UPDATE users SET is_admin = ? WHERE id = ?");
    $stmt->execute([$makeAdmin ? 1 : 0, $targetId]);

    logAdminAction(
        $pdo, $adminId,
        $makeAdmin ? 'grant_admin' : 'revoke_admin',
        'user', $targetId,
        sprintf('%s (%d)', $target['name'], $targetId)
    );

    return [
        'ok'      => true,
        'message' => $makeAdmin
            ? $target['name'] . ' הוא כעת מנהל מערכת'
            : 'הרשאת הניהול נשללה מ' . $target['name'],
    ];
}

/**
 * משבית או מפעיל חשבון משתמש.
 *
 * השבתה חוסמת התחברות ומסירה את המשתמש מכל בדיקת הרשאה, אבל
 * אינה מוחקת דבר: החברויות, הקניות וההיסטוריה נשארות, וההפעלה
 * מחזירה את המצב כפי שהיה.
 *
 * @return array{ok: bool, message: string}
 */
function adminSetUserActive(PDO $pdo, $adminId, $targetId, $active) {
    $targetId = (int)$targetId;
    $active   = (bool)$active;

    if ($targetId === (int)$adminId && !$active) {
        return ['ok' => false, 'message' => 'אי אפשר להשבית את החשבון שלך'];
    }

    $stmt = $pdo->prepare("SELECT id, name, is_active, is_admin FROM users WHERE id = ?");
    $stmt->execute([$targetId]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        return ['ok' => false, 'message' => 'המשתמש לא נמצא'];
    }

    if ((bool)$target['is_active'] === $active) {
        return ['ok' => false, 'message' => 'אין מה לשנות'];
    }

    // השבתת מנהל היא בפועל שלילת הרשאה, כי isSystemAdmin
    // דורש is_active. אותה מגבלה חלה גם כאן.
    if (!$active && (int)$target['is_admin'] === 1) {
        $admins = (int)$pdo->query("
            SELECT COUNT(*) FROM users WHERE is_admin = 1 AND is_active = 1
        ")->fetchColumn();

        if ($admins <= 1) {
            return ['ok' => false, 'message' => 'זהו המנהל הפעיל היחיד ולכן אי אפשר להשבית אותו'];
        }
    }

    $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
    $stmt->execute([$active ? 1 : 0, $targetId]);

    logAdminAction(
        $pdo, $adminId,
        $active ? 'enable_user' : 'disable_user',
        'user', $targetId,
        sprintf('%s (%d)', $target['name'], $targetId)
    );

    return [
        'ok'      => true,
        'message' => $active
            ? 'החשבון של ' . $target['name'] . ' הופעל'
            : 'החשבון של ' . $target['name'] . ' הושבת',
    ];
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

/**
 * כל האירועים במערכת, עם המנהל ומספר המשתתפים.
 */
function adminListGroups(PDO $pdo) {
    $stmt = $pdo->query("
        SELECT pg.id, pg.name, pg.status, pg.event_date, pg.is_active,
               pg.owner_id, owner.name AS owner_name, owner.email AS owner_email,
               (SELECT COUNT(*) FROM group_members gm
                 WHERE gm.group_id = pg.id AND gm.is_active = 1) AS member_count,
               (SELECT COUNT(*) FROM group_invitations gi
                 WHERE gi.group_id = pg.id AND gi.status = 'pending') AS pending_count
        FROM purchase_groups pg
        LEFT JOIN users owner ON owner.id = pg.owner_id
        ORDER BY pg.id DESC
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * פירוט אירוע אחד: המשתתפים, ההזמנות הממתינות, ומי אפשר עוד
 * לצרף אליו.
 */
function adminGroupDetail(PDO $pdo, $groupId) {
    $groupId = (int)$groupId;

    // שם האירוע ומצבו נמסרים מהשרת ולא נגזרים מהטקסט שבמסך.
    // הכותרת במסך מכילה גם תגיות כמו "מושבת" או "סגור", וכל
    // ניסיון לחלץ ממנה שם מחזיר מחרוזת מלוכלכת.
    $stmt = $pdo->prepare("SELECT name, is_active, status FROM purchase_groups WHERE id = ?");
    $stmt->execute([$groupId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT gm.id, gm.nickname, gm.email, gm.participation_type, gm.participation_value,
               gm.user_id, u.name AS user_name
        FROM group_members gm
        LEFT JOIN users u ON u.id = gm.user_id
        WHERE gm.group_id = ? AND gm.is_active = 1
        ORDER BY gm.joined_at, gm.id
    ");
    $stmt->execute([$groupId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT gi.id, gi.email, gi.nickname, gi.status, gi.created_at,
               (SELECT u.id FROM users u WHERE u.email = gi.email) AS invitee_user_id
        FROM group_invitations gi
        WHERE gi.group_id = ? AND gi.status = 'pending'
        ORDER BY gi.created_at DESC
    ");
    $stmt->execute([$groupId]);
    $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // משתמשים שאפשר עוד לצרף: כל מי שאינו כבר חבר פעיל
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.email
        FROM users u
        WHERE u.is_active = 1
          AND u.id NOT IN (
              SELECT gm.user_id FROM group_members gm
              WHERE gm.group_id = ? AND gm.is_active = 1 AND gm.user_id IS NOT NULL
          )
        ORDER BY u.name
    ");
    $stmt->execute([$groupId]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'name'       => $group['name'] ?? '',
        'is_active'  => (int)($group['is_active'] ?? 1),
        'status'     => $group['status'] ?? 'active',
        'members'    => $members,
        'pending'    => $pending,
        'candidates' => $candidates,
    ];
}

/**
 * מצרף משתמש לאירוע ישירות, בלי לעבור דרך הזמנה.
 *
 * זו הרחבה של "אשר בשמו": שם מאשרים הזמנה שכבר נשלחה, וכאן
 * מצרפים גם בלי שנשלחה בכלל. שתי הפעולות נרשמות ביומן, ובשתיהן
 * המשתמש ומנהל האירוע מקבלים התראה.
 *
 * @return array{ok: bool, message: string}
 */
function adminAddUserToGroup(PDO $pdo, $adminId, $groupId, $targetUserId, $type, $value) {
    $groupId      = (int)$groupId;
    $targetUserId = (int)$targetUserId;

    $stmt = $pdo->prepare("SELECT id, name FROM purchase_groups WHERE id = ? AND is_active = 1");
    $stmt->execute([$groupId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$group) {
        return ['ok' => false, 'message' => 'האירוע לא נמצא'];
    }

    $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$targetUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return ['ok' => false, 'message' => 'המשתמש לא נמצא'];
    }

    if (!in_array($type, PARTICIPATION_TYPES, true)) {
        $type = 'shares';
    }

    $value = $type === 'shares' ? max(1, (int)round((float)$value)) : round((float)$value, 2);

    if ($value <= 0) {
        return ['ok' => false, 'message' => 'ערך ההשתתפות חייב להיות חיובי'];
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT id, is_active FROM group_members WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$groupId, $targetUserId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing && $existing['is_active']) {
            $pdo->rollBack();

            return ['ok' => false, 'message' => 'המשתמש כבר חבר פעיל באירוע'];
        }

        if ($existing) {
            $stmt = $pdo->prepare("
                UPDATE group_members
                SET is_active = 1, nickname = ?, email = ?,
                    participation_type = ?, participation_value = ?, joined_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$user['name'], $user['email'], $type, $value, $existing['id']]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO group_members
                    (group_id, user_id, nickname, email, participation_type, participation_value)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$groupId, $targetUserId, $user['name'], $user['email'], $type, $value]);
        }

        // הזמנה ממתינה לאותה כתובת מיותרת מרגע שהמשתמש צורף
        $stmt = $pdo->prepare("
            UPDATE group_invitations SET status = 'accepted', responded_at = NOW()
            WHERE group_id = ? AND email = ? AND status = 'pending'
        ");
        $stmt->execute([$groupId, $user['email']]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Admin add to group failed: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'הצירוף נכשל'];
    }

    logAdminAction(
        $pdo, $adminId, 'add_user_to_group', 'group', $groupId,
        sprintf('צירוף %s (%s) כ-%s', $user['name'], $user['email'], participationLabel($type, $value))
    );

    queueNotification(
        $pdo, $targetUserId, 'invitation_accepted',
        'צורפת לאירוע "' . $group['name'] . '"',
        'מנהל המערכת צירף אותך לאירוע, חלקך: ' . participationLabel($type, $value),
        ['group_id' => $groupId, 'url' => groupUrl($groupId)]
    );

    notifyGroupOwner(
        $pdo, $groupId, $adminId, 'invitation_accepted',
        'משתתף נוסף לאירוע',
        sprintf('%s צורף ל"%s" על ידי מנהל המערכת', $user['name'], $group['name'])
    );

    return [
        'ok'      => true,
        'message' => sprintf('%s צורף לאירוע "%s"', $user['name'], $group['name']),
    ];
}

/**
 * מחיקת אירוע על ידי מנהל המערכת.
 *
 * עוטף את אותן פונקציות שמשמשות את בעל האירוע, עם דגל הרשאה
 * שמדלג על בדיקת הבעלות - ומוסיף רישום ביומן, כי זו פעולה
 * על נכס של מישהו אחר.
 *
 * @param string $mode soft | restore | purge
 *
 * @return array{ok: bool, message: string}
 */
function adminDeleteGroup(PDO $pdo, $adminId, $groupId, $mode, $confirmName = '') {
    $stmt = $pdo->prepare("SELECT name, owner_id FROM purchase_groups WHERE id = ?");
    $stmt->execute([(int)$groupId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$group) {
        return ['ok' => false, 'message' => 'האירוע לא נמצא'];
    }

    if ($mode === 'soft') {
        $result = softDeleteGroup($pdo, $groupId, $adminId, true);
        $action = 'soft_delete_group';
    } elseif ($mode === 'restore') {
        $result = restoreGroup($pdo, $groupId, $adminId, true);
        $action = 'restore_group';
    } elseif ($mode === 'purge') {
        $result = purgeGroup($pdo, $groupId, $adminId, $confirmName, true);
        $action = 'purge_group';
    } else {
        return ['ok' => false, 'message' => 'פעולה לא מוכרת'];
    }

    if ($result['ok']) {
        logAdminAction(
            $pdo, $adminId, $action, 'group', (int)$groupId,
            sprintf('אירוע "%s" של משתמש %d', $group['name'], (int)$group['owner_id'])
        );
    }

    return $result;
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
        'users'     => $one("SELECT COUNT(*) FROM users"),
        'groups'    => $one("SELECT COUNT(*) FROM purchase_groups WHERE is_active = 1"),
        'members'   => $one("SELECT COUNT(*) FROM group_members WHERE is_active = 1"),
        'pending'   => $one("SELECT COUNT(*) FROM group_invitations WHERE status = 'pending'"),

        // מספרים שמעניינים בסקירה אבל לא בכותרת של כל מסך
        'admins'    => $one("SELECT COUNT(*) FROM users WHERE is_admin = 1"),
        'inactive'  => $one("SELECT COUNT(*) FROM users WHERE is_active = 0"),
        'closed'    => $one("SELECT COUNT(*) FROM purchase_groups WHERE status = 'closed' AND is_active = 1"),
        'deleted'   => $one("SELECT COUNT(*) FROM purchase_groups WHERE is_active = 0"),
        'purchases' => $one("SELECT COUNT(*) FROM group_purchases"),
        'spent'     => $one("SELECT COALESCE(SUM(amount), 0) FROM group_purchases"),
        'items'     => $one("SELECT COUNT(*) FROM shopping_items"),
        'contacts'  => $one("SELECT COUNT(*) FROM contacts"),
    ];
}
