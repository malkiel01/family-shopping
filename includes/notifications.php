<?php
/**
 * תשתית ההתראות
 * includes/notifications.php
 *
 * כל התראה נכנסת לטבלת notification_queue, ומשם נשלחת בשני ערוצים:
 * בתוך האפליקציה כשהיא פתוחה, ובדחיפה לדפדפן כשהיא סגורה.
 *
 * שתי נקודות שחייבות להישמר בכל כתיבה לתור, כי בלעדיהן ההתראה
 * נכנסת ולא מוצגת לעולם:
 *   1. user_id נשמר בעמודה עצמה ולא רק בתוך ה-JSON. הצרכן שולף
 *      לפי העמודה, ולכן התראה בלי העמודה הזו פשוט לא נמצאת.
 *   2. title ו-body נשמרים בתוך data. הצרכן קורא אותם משם, ובלעדיהם
 *      מוצגת התראה ריקה עם הכותרת הגנרית "התראה".
 */

/**
 * מכניס התראה לתור עבור משתמש אחד.
 *
 * @param array $extra שדות נוספים שנשמרים ב-data לשימוש הלקוח
 *
 * @return int מזהה השורה שנוצרה, או 0 בכישלון
 */
function queueNotification(PDO $pdo, $userId, $type, $title, $body, array $extra = []) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notification_queue (user_id, type, data, status, created_at)
            VALUES (?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([
            (int)$userId,
            $type,
            json_encode(array_merge([
                'type'  => $type,
                'title' => $title,
                'body'  => $body,
            ], $extra), JSON_UNESCAPED_UNICODE),
        ]);

        return (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        // התראה שנכשלה לא אמורה להפיל את הפעולה עצמה
        error_log("Failed to queue $type notification: " . $e->getMessage());

        return 0;
    }
}

/** כתובת הדף של האירוע, לשימוש בלחיצה על ההתראה */
function groupUrl($groupId) {
    return (defined('APP_BASE_PATH') ? APP_BASE_PATH : '') . '/group.php?id=' . (int)$groupId;
}

/** כתובת הדשבורד */
function dashboardUrl() {
    return (defined('APP_BASE_PATH') ? APP_BASE_PATH : '') . '/dashboard.php';
}

/** שם המשתמש שמבצע את הפעולה */
function actorName() {
    return $_SESSION['name'] ?? 'משתתף';
}

/**
 * מודיע למנהל האירוע.
 *
 * משמש גם מסלולים שאינם חלק מ-GroupContext, כמו אישור הזמנה
 * מהדשבורד או מדף ההצטרפות. אם המנהל הוא זה שביצע את הפעולה,
 * לא נשלחת התראה.
 */
function notifyGroupOwner(PDO $pdo, $groupId, $actingUserId, $type, $title, $body, array $extra = []) {
    try {
        $stmt = $pdo->prepare("SELECT owner_id FROM purchase_groups WHERE id = ?");
        $stmt->execute([$groupId]);
        $ownerId = $stmt->fetchColumn();

        if (!$ownerId || (int)$ownerId === (int)$actingUserId) {
            return;
        }

        queueNotification($pdo, $ownerId, $type, $title, $body, array_merge([
            'group_id' => (int)$groupId,
            'url'      => groupUrl($groupId),
        ], $extra));
    } catch (Exception $e) {
        error_log('Failed to notify group owner: ' . $e->getMessage());
    }
}

/**
 * שם האירוע לפי מזהה.
 */
function groupNameById(PDO $pdo, $groupId) {
    try {
        $stmt = $pdo->prepare("SELECT name FROM purchase_groups WHERE id = ?");
        $stmt->execute([$groupId]);

        return $stmt->fetchColumn() ?: 'האירוע';
    } catch (Exception $e) {
        return 'האירוע';
    }
}
