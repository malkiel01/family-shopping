<?php
/**
 * אנשי הקשר של המשתמש
 * includes/contacts.php
 *
 * כל מי שהמשתמש הזמין אי פעם לאירוע נשמר כאן, כדי שלא יצטרך
 * להקליד שוב אימייל וכינוי בכל אירוע חדש. הרשימה שייכת למשתמש
 * ולא לאירוע מסוים, ולכן היא נצברת מכל הקבוצות שלו.
 *
 * הכינוי וברירת המחדל של ההשתתפות נשמרים גם הם, כי בדרך כלל
 * אותו אדם משתתף באותו אופן בכל פעם - אותה משפחה, אותו מספר
 * נפשות.
 */

require_once __DIR__ . '/participation.php';

/** האם טבלת אנשי הקשר קיימת. נבדק פעם אחת לכל בקשה. */
function contactsTableReady(PDO $pdo) {
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts'
        ");
        $stmt->execute();
        $ready = ((int)$stmt->fetchColumn() === 1);
    } catch (Exception $e) {
        error_log('Contacts table check failed: ' . $e->getMessage());
        $ready = false;
    }

    return $ready;
}

/**
 * כל אנשי הקשר של המשתמש, הנפוצים ביותר קודם.
 */
function listContacts(PDO $pdo, $ownerId) {
    if (!contactsTableReady($pdo)) {
        return [];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT c.*,
                   (SELECT COUNT(*) FROM users u WHERE u.email = c.email) AS is_registered
            FROM contacts c
            WHERE c.owner_id = ?
            ORDER BY c.times_used DESC, c.name ASC
        ");
        $stmt->execute([(int)$ownerId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Failed to list contacts: ' . $e->getMessage());

        return [];
    }
}

/**
 * שומר או מעדכן איש קשר. נקרא אוטומטית בכל הזמנה, כך שהרשימה
 * נבנית מאליה בלי שהמשתמש יצטרך לתחזק אותה.
 *
 * @return bool האם הפעולה הצליחה
 */
function rememberContact(PDO $pdo, $ownerId, $email, $name, $type = null, $value = null) {
    if (!contactsTableReady($pdo)) {
        return false;
    }

    $email = trim(mb_strtolower($email));
    $name  = trim($name);

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    try {
        // ON DUPLICATE KEY מעדכן את מי שכבר קיים, ולכן אין צורך
        // לבדוק קודם אם הוא ברשימה
        $stmt = $pdo->prepare("
            INSERT INTO contacts
                (owner_id, email, name, default_participation_type, default_participation_value,
                 times_used, last_used_at)
            VALUES (?, ?, ?, ?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                default_participation_type  = COALESCE(VALUES(default_participation_type), default_participation_type),
                default_participation_value = COALESCE(VALUES(default_participation_value), default_participation_value),
                times_used   = times_used + 1,
                last_used_at = NOW()
        ");
        $stmt->execute([
            (int)$ownerId,
            $email,
            $name !== '' ? $name : $email,
            $type,
            $value,
        ]);

        return true;
    } catch (Exception $e) {
        error_log('Failed to remember contact: ' . $e->getMessage());

        return false;
    }
}

/**
 * עדכון ידני של איש קשר.
 */
function updateContact(PDO $pdo, $ownerId, $contactId, $name, $type, $value) {
    $stmt = $pdo->prepare("
        UPDATE contacts
        SET name = ?, default_participation_type = ?, default_participation_value = ?
        WHERE id = ? AND owner_id = ?
    ");

    return $stmt->execute([
        $name,
        in_array($type, PARTICIPATION_TYPES, true) ? $type : null,
        $value > 0 ? $value : null,
        (int)$contactId,
        (int)$ownerId,
    ]);
}

/**
 * מחיקת איש קשר. אינה נוגעת בשום קבוצה או הזמנה קיימת -
 * רק ברשימה הפרטית של המשתמש.
 */
function deleteContact(PDO $pdo, $ownerId, $contactId) {
    $stmt = $pdo->prepare("DELETE FROM contacts WHERE id = ? AND owner_id = ?");

    return $stmt->execute([(int)$contactId, (int)$ownerId]);
}

/**
 * מייבא אנשי קשר מכל ההזמנות והחברויות הקיימות של המשתמש.
 *
 * בזכות זה הרשימה לא מתחילה ריקה: כל מי שהוזמן אי פעם לאירוע
 * שהמשתמש מנהל נכנס אליה מיד.
 *
 * @return int כמה אנשי קשר נוספו
 */
function importContactsFromHistory(PDO $pdo, $ownerId) {
    if (!contactsTableReady($pdo)) {
        return 0;
    }

    $ownerId = (int)$ownerId;

    try {
        // כל מי שהמשתמש הזמין, וכל מי שחבר בקבוצה שהוא מנהל.
        // COALESCE על הכינוי כי בהזמנות ישנות הוא עשוי להיות ריק.
        $stmt = $pdo->prepare("
            SELECT email, name, uses FROM (
                SELECT LOWER(gi.email) AS email,
                       COALESCE(NULLIF(TRIM(gi.nickname), ''), gi.email) AS name,
                       COUNT(*) AS uses,
                       MAX(gi.created_at) AS latest
                FROM group_invitations gi
                WHERE gi.invited_by = ? AND gi.email IS NOT NULL AND gi.email <> ''
                GROUP BY LOWER(gi.email), name

                UNION ALL

                SELECT LOWER(gm.email) AS email,
                       COALESCE(NULLIF(TRIM(gm.nickname), ''), gm.email) AS name,
                       COUNT(*) AS uses,
                       MAX(gm.joined_at) AS latest
                FROM group_members gm
                JOIN purchase_groups pg ON pg.id = gm.group_id
                WHERE pg.owner_id = ? AND gm.email IS NOT NULL AND gm.email <> ''
                  AND gm.user_id <> ?
                GROUP BY LOWER(gm.email), name
            ) AS history
            ORDER BY latest DESC
        ");
        $stmt->execute([$ownerId, $ownerId, $ownerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $added = 0;
        foreach ($rows as $row) {
            if (rememberContact($pdo, $ownerId, $row['email'], $row['name'])) {
                $added++;
            }
        }

        return $added;
    } catch (Exception $e) {
        error_log('Failed to import contacts: ' . $e->getMessage());

        return 0;
    }
}
