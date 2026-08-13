<?php
/**
 * ניהול הזמנות רוחבי
 * includes/admin_invitations.php
 *
 * הזמנה שנתקעה היא התקלה הכי שקטה במערכת: היא לא מייצרת שגיאה,
 * אף אחד לא מתלונן עליה, ומנהל האירוע בכלל לא יודע שהיא לא
 * הגיעה. עד היום היה אפשר לראות אותה רק בתוך האירוע שלה, כלומר
 * רק אם כבר חשדת. כאן כל ההזמנות של כל האירועים במקום אחד.
 *
 * שלוש הפעולות כאן קיימות בדיוק כדי לטפל בכשלי מסירה:
 *
 *   שליחה חוזרת   כשה-SMTP היה מושבת או נכשל בזמן ההזמנה
 *   העתקת קישור   כשאין מייל בכלל, ושולחים בוואטסאפ
 *   ביטול         כשהכתובת שגויה, או שההזמנה כבר לא רלוונטית
 *
 * "אישור בשם המשתמש" יושב ב-admin.php, כי הוא פעולה על חברות
 * ולא על ההזמנה.
 */

require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/group_actions.php';
require_once __DIR__ . '/EmailService.php';

/** כמה הזמנות להציג. מעבר לזה המסך מפסיק להיות שימושי */
const INVITATIONS_LIMIT = 200;

/**
 * כל ההזמנות במערכת, מהחדשה לישנה.
 *
 * הטוקן נכלל כדי שאפשר יהיה להעתיק קישור. זו אינה הרחבת הרשאה:
 * מי שרואה את המסך הזה יכול ממילא לאשר את ההזמנה בשם המוזמן.
 *
 * @return array
 */
function adminListInvitations(PDO $pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT gi.id, gi.group_id, gi.email, gi.nickname, gi.status, gi.token,
                   gi.participation_type, gi.participation_value,
                   gi.created_at, gi.responded_at,
                   pg.name AS group_name, pg.is_active AS group_active,
                   inviter.name AS inviter_name,
                   (SELECT u.id FROM users u WHERE u.email = gi.email) AS invitee_user_id,
                   DATEDIFF(NOW(), gi.created_at) AS age_days
            FROM group_invitations gi
            LEFT JOIN purchase_groups pg ON pg.id = gi.group_id
            LEFT JOIN users inviter ON inviter.id = gi.invited_by
            ORDER BY gi.id DESC
            LIMIT " . INVITATIONS_LIMIT
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Invitation list failed: ' . $e->getMessage());

        return [];
    }

    foreach ($rows as &$row) {
        $row['link'] = invitationLink($row['token']);

        // הטוקן עצמו כבר לא נחוץ אחרי שנבנה ממנו קישור, ואין
        // סיבה שיגיע ל-HTML פעמיים
        unset($row['token']);
    }

    return $rows;
}

/** ספירה לפי מצב, לתגיות הסינון */
function adminInvitationCounts(PDO $pdo) {
    $counts = ['pending' => 0, 'accepted' => 0, 'rejected' => 0, 'expired' => 0, 'stale' => 0];

    try {
        $rows = $pdo->query("
            SELECT status, COUNT(*) AS c FROM group_invitations GROUP BY status
        ")->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($rows as $status => $count) {
            $counts[$status] = (int)$count;
        }

        // הזמנה שממתינה מעל שבוע כבר לא "בדרך" - היא תקועה
        $counts['stale'] = (int)$pdo->query("
            SELECT COUNT(*) FROM group_invitations
            WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ")->fetchColumn();
    } catch (Exception $e) {
        error_log('Invitation counts failed: ' . $e->getMessage());
    }

    return $counts;
}

/**
 * שולח מחדש את מייל ההזמנה.
 *
 * @return array{ok: bool, message: string}
 */
function adminResendInvitation(PDO $pdo, $adminId, $invitationId) {
    $invitation = adminLoadInvitation($pdo, $invitationId);

    if (!$invitation) {
        return ['ok' => false, 'message' => 'ההזמנה לא נמצאה'];
    }

    if ($invitation['status'] !== 'pending') {
        return ['ok' => false, 'message' => 'אפשר לשלוח מחדש רק הזמנה שממתינה'];
    }

    if (trim($_ENV['SMTP_HOST'] ?? '') === '') {
        return [
            'ok'      => false,
            'message' => 'SMTP אינו מוגדר בשרת. העתק את קישור ההצטרפות ושלח אותו ידנית',
        ];
    }

    $service = new EmailService($pdo);
    $sent    = $service->sendGroupInvitation((int)$invitationId);

    logAdminAction(
        $pdo, $adminId, 'resend_invitation', 'invitation', (int)$invitationId,
        sprintf('%s לאירוע "%s" - %s',
            $invitation['email'], $invitation['group_name'], $sent ? 'נשלח' : 'נכשל')
    );

    return $sent
        ? ['ok' => true,  'message' => 'ההזמנה נשלחה מחדש אל ' . $invitation['email']]
        : ['ok' => false, 'message' => 'השליחה נכשלה. בדוק את יומן המיילים באזור המערכת'];
}

/**
 * מבטל הזמנה שממתינה.
 *
 * הביטול מסמן 'expired' ולא מוחק - בדיוק כמו ביטול על ידי מנהל
 * האירוע - כדי שהקישור יפסיק לעבוד אבל התיעוד יישאר.
 *
 * @return array{ok: bool, message: string}
 */
function adminCancelInvitation(PDO $pdo, $adminId, $invitationId) {
    $invitation = adminLoadInvitation($pdo, $invitationId);

    if (!$invitation) {
        return ['ok' => false, 'message' => 'ההזמנה לא נמצאה'];
    }

    if ($invitation['status'] !== 'pending') {
        return ['ok' => false, 'message' => 'ההזמנה כבר טופלה'];
    }

    $stmt = $pdo->prepare("
        UPDATE group_invitations SET status = 'expired' WHERE id = ? AND status = 'pending'
    ");
    $stmt->execute([(int)$invitationId]);

    if ($stmt->rowCount() === 0) {
        return ['ok' => false, 'message' => 'ההזמנה כבר טופלה'];
    }

    logAdminAction(
        $pdo, $adminId, 'cancel_invitation', 'invitation', (int)$invitationId,
        sprintf('%s לאירוע "%s"', $invitation['email'], $invitation['group_name'])
    );

    return ['ok' => true, 'message' => 'ההזמנה בוטלה, והקישור שלה כבר אינו תקף'];
}

/**
 * מבטל בבת אחת את כל ההזמנות שממתינות מעל מספר ימים.
 *
 * @return array{ok: bool, message: string}
 */
function adminCancelStaleInvitations(PDO $pdo, $adminId, $days = 30) {
    $days = max(7, (int)$days);

    try {
        $stmt = $pdo->prepare("
            UPDATE group_invitations SET status = 'expired'
            WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$days]);
        $rows = $stmt->rowCount();
    } catch (Exception $e) {
        error_log('Bulk invitation cancel failed: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'הפעולה נכשלה'];
    }

    if ($rows === 0) {
        return ['ok' => true, 'message' => "אין הזמנות שממתינות מעל $days יום"];
    }

    logAdminAction($pdo, $adminId, 'cancel_invitation', 'invitation', null,
        "ביטול קבוצתי: $rows הזמנות מעל $days יום");

    return ['ok' => true, 'message' => "בוטלו $rows הזמנות"];
}

/** שורת ההזמנה עם שם האירוע, לשימוש הפעולות */
function adminLoadInvitation(PDO $pdo, $invitationId) {
    $stmt = $pdo->prepare("
        SELECT gi.*, pg.name AS group_name
        FROM group_invitations gi
        LEFT JOIN purchase_groups pg ON pg.id = gi.group_id
        WHERE gi.id = ?
    ");
    $stmt->execute([(int)$invitationId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** תווית קריאה למצב ההזמנה */
function invitationStatusLabel($status) {
    $labels = [
        'pending'  => 'ממתינה',
        'accepted' => 'התקבלה',
        'rejected' => 'נדחתה',
        'expired'  => 'בוטלה',
    ];

    return $labels[$status] ?? $status;
}
