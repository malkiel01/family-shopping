<?php
/**
 * מחיקת אירוע
 * includes/group_delete.php
 *
 * שתי רמות, ובכוונה:
 *
 *   מחיקה רגילה - האירוע מסומן כלא פעיל. הוא נעלם מכל המשתתפים,
 *                 אבל שום נתון לא נמחק, והמנהל יכול לשחזר אותו.
 *                 זו ברירת המחדל, כי רוב ה"מחיקות" הן בעצם רצון
 *                 להוריד מהעיניים ולא להשמיד.
 *
 *   מחיקה לצמיתות - כל המשתתפים מוסרים, וכל הנתונים נמחקים
 *                   מהמסד: קניות, החרגות, רשימה, התחשבנויות
 *                   והזמנות. גם תמונות הקבלות נמחקות מהדיסק.
 *                   אין דרך חזרה, ולכן היא דורשת הקלדת שם
 *                   האירוע כאישור.
 */

require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/notifications.php';

/**
 * מאמת שהמשתמש הוא בעל האירוע.
 *
 * @return array|null שורת האירוע, או null אם אינו הבעלים
 */
function groupOwnedBy(PDO $pdo, $groupId, $userId) {
    $stmt = $pdo->prepare("SELECT * FROM purchase_groups WHERE id = ? AND owner_id = ?");
    $stmt->execute([(int)$groupId, (int)$userId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    return $group ?: null;
}

/**
 * מחיקה רגילה: האירוע נעלם מכולם, הנתונים נשמרים.
 *
 * @return array{ok: bool, message: string}
 */
function softDeleteGroup(PDO $pdo, $groupId, $ownerId) {
    $group = groupOwnedBy($pdo, $groupId, $ownerId);

    if (!$group) {
        return ['ok' => false, 'message' => 'האירוע לא נמצא, או שאינך מנהל שלו'];
    }

    if (!$group['is_active']) {
        return ['ok' => false, 'message' => 'האירוע כבר מחוק'];
    }

    // ההתראה נשלחת לפני הסימון, כדי שהמשתתפים עוד יימצאו
    // בשאילתה של הנמענים
    notifyGroupMembersById(
        $pdo, $groupId, $ownerId, 'group_deleted',
        'האירוע "' . $group['name'] . '" נמחק',
        ($_SESSION['name'] ?? 'מנהל האירוע') . ' מחק את האירוע'
    );

    $stmt = $pdo->prepare("UPDATE purchase_groups SET is_active = 0 WHERE id = ?");
    $stmt->execute([(int)$groupId]);

    return ['ok' => true, 'message' => 'האירוע "' . $group['name'] . '" נמחק'];
}

/**
 * שחזור אירוע שנמחק מחיקה רגילה.
 */
function restoreGroup(PDO $pdo, $groupId, $ownerId) {
    $group = groupOwnedBy($pdo, $groupId, $ownerId);

    if (!$group) {
        return ['ok' => false, 'message' => 'האירוע לא נמצא, או שאינך מנהל שלו'];
    }

    if ($group['is_active']) {
        return ['ok' => false, 'message' => 'האירוע פעיל ממילא'];
    }

    $stmt = $pdo->prepare("UPDATE purchase_groups SET is_active = 1 WHERE id = ?");
    $stmt->execute([(int)$groupId]);

    notifyGroupMembersById(
        $pdo, $groupId, $ownerId, 'group_restored',
        'האירוע "' . $group['name'] . '" חזר',
        ($_SESSION['name'] ?? 'מנהל האירוע') . ' שחזר את האירוע'
    );

    return ['ok' => true, 'message' => 'האירוע "' . $group['name'] . '" שוחזר'];
}

/**
 * מחיקה לצמיתות: מסיר את כל המשתתפים ומוחק את כל נתוני האירוע.
 *
 * סדר המחיקה אינו שרירותי. group_purchases.member_id מוגדר
 * כ-RESTRICT מול group_members, ולכן חייבים למחוק קניות לפני
 * משתתפים - אחרת המסד ידחה את הפעולה.
 *
 * @param string $confirmName שם האירוע כפי שהוקלד לאישור
 *
 * @return array{ok: bool, message: string}
 */
function purgeGroup(PDO $pdo, $groupId, $ownerId, $confirmName) {
    $group = groupOwnedBy($pdo, $groupId, $ownerId);

    if (!$group) {
        return ['ok' => false, 'message' => 'האירוע לא נמצא, או שאינך מנהל שלו'];
    }

    // המחיקה בלתי הפיכה, ולכן דורשת הקלדה מדויקת של השם
    if (trim($confirmName) !== trim($group['name'])) {
        return [
            'ok'      => false,
            'message' => 'שם האירוע שהוקלד אינו תואם. המחיקה בוטלה',
        ];
    }

    $groupId = (int)$groupId;

    // התראה אחרונה למשתתפים, לפני שהם מוסרים
    notifyGroupMembersById(
        $pdo, $groupId, $ownerId, 'group_purged',
        'האירוע "' . $group['name'] . '" נמחק לצמיתות',
        ($_SESSION['name'] ?? 'מנהל האירוע') . ' מחק את האירוע וכל הנתונים שבו'
    );

    // תמונות הקבלות נמחקות מהדיסק לפני שהשורות נעלמות,
    // אחרת יישארו קבצים יתומים שאיש לא יידע לאתר
    $stmt = $pdo->prepare("
        SELECT image_path FROM group_purchases
        WHERE group_id = ? AND image_path IS NOT NULL AND image_path <> ''
    ");
    $stmt->execute([$groupId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $imagePath) {
        deleteReceiptImage($imagePath);
    }

    $pdo->beginTransaction();
    try {
        // 1. החרגות - תלויות בקניות ובמשתתפים
        // תת-שאילתה ולא DELETE רב-טבלאי, כדי שהתחביר יהיה תקני
        $stmt = $pdo->prepare("
            DELETE FROM purchase_exclusions
            WHERE purchase_id IN (SELECT id FROM group_purchases WHERE group_id = ?)
        ");
        $stmt->execute([$groupId]);

        // 2. רשימת הקניות - מצביעה על קניות
        $stmt = $pdo->prepare("DELETE FROM shopping_items WHERE group_id = ?");
        $stmt->execute([$groupId]);

        // 3. התחשבנויות
        $stmt = $pdo->prepare("DELETE FROM settlements WHERE group_id = ?");
        $stmt->execute([$groupId]);

        // 4. קניות - חייבות לרדת לפני המשתתפים בגלל RESTRICT
        $stmt = $pdo->prepare("DELETE FROM group_purchases WHERE group_id = ?");
        $stmt->execute([$groupId]);

        // 5. הזמנות
        $stmt = $pdo->prepare("DELETE FROM group_invitations WHERE group_id = ?");
        $stmt->execute([$groupId]);

        // 6. המשתתפים - "מעזיב את כולם"
        $stmt = $pdo->prepare("DELETE FROM group_members WHERE group_id = ?");
        $stmt->execute([$groupId]);

        // 7. והאירוע עצמו
        $stmt = $pdo->prepare("DELETE FROM purchase_groups WHERE id = ? AND owner_id = ?");
        $stmt->execute([$groupId, (int)$ownerId]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Purge group failed: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'המחיקה נכשלה. שום דבר לא נמחק'];
    }

    error_log(sprintf(
        'Group purged: id=%d name=%s by user=%d',
        $groupId, $group['name'], $ownerId
    ));

    return [
        'ok'      => true,
        'message' => 'האירוע "' . $group['name'] . '" נמחק לצמיתות',
    ];
}

/**
 * מודיע לכל המשתתפים הפעילים באירוע, חוץ מהמבצע.
 * גרסה שעובדת לפי מזהה קבוצה, בלי GroupContext.
 */
function notifyGroupMembersById(PDO $pdo, $groupId, $actingUserId, $type, $title, $body) {
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT user_id FROM group_members
            WHERE group_id = ? AND is_active = 1
              AND user_id IS NOT NULL AND user_id != ?
        ");
        $stmt->execute([(int)$groupId, (int)$actingUserId]);

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
            queueNotification($pdo, $userId, $type, $title, $body, [
                'group_id' => (int)$groupId,
                'url'      => dashboardUrl(),
            ]);
        }
    } catch (Exception $e) {
        error_log('Failed to notify group members: ' . $e->getMessage());
    }
}

/**
 * האירועים שהמנהל מחק מחיקה רגילה, לצורך שחזור או מחיקה סופית.
 */
function listDeletedGroups(PDO $pdo, $ownerId) {
    try {
        $stmt = $pdo->prepare("
            SELECT pg.id, pg.name, pg.event_date, pg.created_at,
                   (SELECT COUNT(*) FROM group_members gm
                     WHERE gm.group_id = pg.id AND gm.is_active = 1) AS member_count,
                   (SELECT COUNT(*) FROM group_purchases gp
                     WHERE gp.group_id = pg.id) AS purchase_count
            FROM purchase_groups pg
            WHERE pg.owner_id = ? AND pg.is_active = 0
            ORDER BY pg.id DESC
        ");
        $stmt->execute([(int)$ownerId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Failed to list deleted groups: ' . $e->getMessage());

        return [];
    }
}
