<?php
/**
 * קיזוז חובות בין קבוצות
 * includes/offsets.php
 *
 * שמואל חייב לדוד 100 באירוע אחד, ודוד חייב לשמואל 70 באירוע אחר.
 * שניהם יודעים את זה, ושניהם מעבירים כסף פעמיים - כי כל אירוע
 * מנוהל בנפרד ואף מסך לא מראה את שתי השורות יחד.
 *
 * הקיזוז אינו מנגנון חדש. הוא **שתי התחשבנויות שנרשמות יחד**:
 * אחת בכל אירוע, כל אחת בכיוון ההפוך. אחרי קיזוז של 70 נשאר
 * חוב אחד של 30, והאירוע השני סגור.
 *
 * שלוש הבחנות שקובעות את ההתנהגות כאן:
 *
 *   1. **זהות חוצה אירועים.** אותו אדם הוא רשומת group_members
 *      נפרדת בכל אירוע. הקישור הוא user_id, ובהיעדרו האימייל -
 *      ולכן מי שהוזמן ולא נרשם עדיין מזוהה גם הוא.
 *
 *   2. **קיזוז בין שני אירועים בכל פעם.** אפשר היה לפרוס סכום
 *      על פני כמה אירועים, אבל אז אי אפשר להסביר בשורה אחת מה
 *      בדיוק נרשם - וזה בדיוק מה שמרתיע אנשים מלהשתמש בזה.
 *
 *   3. **ההרשאה נבדקת בשני האירועים בנפרד**, לפי אותו כלל
 *      שחל על התחשבנות רגילה: מנהל האירוע, או צד להעברה.
 */

require_once __DIR__ . '/group_calculations.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/currency.php';

/**
 * מזהה יציב לאדם, חוצה אירועים.
 *
 * user_id הוא הקישור האמין. בהיעדרו - מי שהוזמן ועוד לא נרשם -
 * האימייל הוא מה שיש, והוא ממילא מה שההזמנה נשלחה אליו.
 */
function offsetIdentity(array $member) {
    if (!empty($member['user_id'])) {
        return 'u' . (int)$member['user_id'];
    }

    return 'e' . mb_strtolower(trim((string)($member['email'] ?? '')));
}

/**
 * האירועים הפעילים שהמשתמש חבר בהם.
 *
 * @return array שורות אירוע, עם is_owner ו-my_member_id
 */
function offsetUserGroups(PDO $pdo, $userId) {
    $stmt = $pdo->prepare("
        SELECT pg.id, pg.name, pg.status, pg.share_rate, pg.owner_id,
               gm.id AS my_member_id
        FROM purchase_groups pg
        JOIN group_members gm ON gm.group_id = pg.id
        WHERE gm.user_id = ? AND gm.is_active = 1 AND pg.is_active = 1
        ORDER BY pg.event_date DESC, pg.id DESC
    ");
    $stmt->execute([(int)$userId]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($groups as &$group) {
        $group['is_owner'] = ((int)$group['owner_id'] === (int)$userId);
    }

    return $groups;
}

/**
 * מצב ההעברות באירוע אחד.
 *
 * טוען בדיוק את מה שגם group.php טוען, ומריץ את אותו מנוע חישוב -
 * כדי שהמספר כאן יהיה זהה למספר שמופיע במסך האירוע. כל חישוב
 * מקוצר היה יוצר שני מקורות אמת.
 *
 * @return array transfers, ו-membersById
 */
function offsetGroupSnapshot(PDO $pdo, $groupId, $shareRate) {
    $stmt = $pdo->prepare("
        SELECT gm.*, COALESCE(u.email, gm.email) AS email
        FROM group_members gm
        LEFT JOIN users u ON gm.user_id = u.id
        WHERE gm.group_id = ? AND gm.is_active = 1
        ORDER BY gm.joined_at, gm.id
    ");
    $stmt->execute([(int)$groupId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT id, member_id, amount FROM group_purchases WHERE group_id = ?
    ");
    $stmt->execute([(int)$groupId]);
    $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $exclusions = [];
    try {
        $stmt = $pdo->prepare("
            SELECT pe.purchase_id, pe.member_id
            FROM purchase_exclusions pe
            JOIN group_purchases gp ON gp.id = pe.purchase_id
            WHERE gp.group_id = ?
        ");
        $stmt->execute([(int)$groupId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $exclusions[(int)$row['purchase_id']][] = (int)$row['member_id'];
        }
    } catch (Exception $e) {
        $exclusions = [];
    }

    foreach ($purchases as &$purchase) {
        $purchase['excluded_ids'] = $exclusions[(int)$purchase['id']] ?? [];
    }
    unset($purchase);

    $settlements = [];
    try {
        $stmt = $pdo->prepare("
            SELECT from_member_id, to_member_id, amount FROM settlements WHERE group_id = ?
        ");
        $stmt->execute([(int)$groupId]);
        $settlements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $settlements = [];
    }

    $balance = calculateGroupBalance($members, $purchases, $settlements, $shareRate);

    $membersById = [];
    foreach ($members as $member) {
        $membersById[(int)$member['id']] = $member;
    }

    return ['transfers' => $balance['transfers'], 'membersById' => $membersById];
}

/**
 * כל הקיזוזים האפשריים בין האירועים של המשתמש.
 *
 * מועמד לקיזוז הוא זוג העברות בשני אירועים שונים, בין אותם שני
 * אנשים, בכיוונים הפוכים.
 *
 * @return array רשימת מועמדים
 */
function findOffsets(PDO $pdo, $userId) {
    $groups = offsetUserGroups($pdo, $userId);

    if (count($groups) < 2) {
        return [];
    }

    // כל ההעברות מכל האירועים, ממופות לזהות חוצת-אירועים
    $edges = [];
    $names = [];

    foreach ($groups as $group) {
        $shareRate = ($group['share_rate'] !== null && $group['share_rate'] !== '')
            ? (float)$group['share_rate']
            : null;

        $snapshot = offsetGroupSnapshot($pdo, $group['id'], $shareRate);

        foreach ($snapshot['transfers'] as $transfer) {
            $fromMember = $snapshot['membersById'][$transfer['from_id']] ?? null;
            $toMember   = $snapshot['membersById'][$transfer['to_id']] ?? null;

            if (!$fromMember || !$toMember) {
                continue;
            }

            $fromIdentity = offsetIdentity($fromMember);
            $toIdentity   = offsetIdentity($toMember);

            // אימייל ריק ובלי חשבון: אין דרך לזהות אותו באירוע אחר
            if ($fromIdentity === 'e' || $toIdentity === 'e') {
                continue;
            }

            $names[$fromIdentity] = $fromMember['nickname'];
            $names[$toIdentity]   = $toMember['nickname'];

            $edges[] = [
                'group_id'       => (int)$group['id'],
                'group_name'     => $group['name'],
                'is_owner'       => (bool)$group['is_owner'],
                'my_member_id'   => (int)$group['my_member_id'],
                'from_identity'  => $fromIdentity,
                'to_identity'    => $toIdentity,
                'from_member_id' => (int)$transfer['from_id'],
                'to_member_id'   => (int)$transfer['to_id'],
                'amount'         => round((float)$transfer['amount'], 2),
            ];
        }
    }

    // זוגות בכיוונים הפוכים, באירועים שונים
    $candidates = [];

    foreach ($edges as $debt) {
        foreach ($edges as $counter) {
            if ($debt['group_id'] === $counter['group_id']) {
                continue;
            }

            if ($debt['from_identity'] !== $counter['to_identity']
                || $debt['to_identity'] !== $counter['from_identity']) {
                continue;
            }

            // כל זוג נבנה פעם אחת בלבד
            if (strcmp($debt['from_identity'], $debt['to_identity']) > 0) {
                continue;
            }

            $offsetable = min($debt['amount'], $counter['amount']);
            if ($offsetable < 0.01) {
                continue;
            }

            $allowed = offsetPermission($debt, $userId) && offsetPermission($counter, $userId);

            $candidates[] = [
                'person_a'   => ['identity' => $debt['from_identity'], 'name' => $names[$debt['from_identity']]],
                'person_b'   => ['identity' => $debt['to_identity'],   'name' => $names[$debt['to_identity']]],
                'debt_a'     => $debt,      // א חייב לב
                'debt_b'     => $counter,   // ב חייב לא
                'offsetable' => round($offsetable, 2),
                'remainder'  => round(abs($debt['amount'] - $counter['amount']), 2),
                'can_apply'  => $allowed,
            ];
        }
    }

    // הקיזוז הגדול קודם - הוא זה שחוסך הכי הרבה
    usort($candidates, function ($a, $b) {
        return $b['offsetable'] <=> $a['offsetable'];
    });

    return $candidates;
}

/**
 * האם המשתמש רשאי לרשום את ההעברה הזו באירוע שלה.
 *
 * אותו כלל שחל על התחשבנות רגילה: מנהל האירוע, או צד להעברה.
 */
function offsetPermission(array $edge, $userId) {
    if ($edge['is_owner']) {
        return true;
    }

    return $edge['my_member_id'] === $edge['from_member_id']
        || $edge['my_member_id'] === $edge['to_member_id'];
}

/**
 * מבצע קיזוז: שתי התחשבנויות, אחת בכל אירוע.
 *
 * הסכום מאומת מחדש מול החישוב הנוכחי ולא נלקח מהבקשה כפי שהוא,
 * כי בין טעינת המסך ללחיצה מישהו אחר עשוי היה לרשום תשלום.
 *
 * @return array{ok: bool, message: string}
 */
function applyOffset(PDO $pdo, $userId, $groupAId, $groupBId, $identityA, $identityB, $amount) {
    $amount = round((float)$amount, 2);

    if ($amount < 0.01) {
        return ['ok' => false, 'message' => 'סכום הקיזוז חייב להיות חיובי'];
    }

    $match = null;
    foreach (findOffsets($pdo, $userId) as $candidate) {
        $sameGroups = ((int)$candidate['debt_a']['group_id'] === (int)$groupAId
            && (int)$candidate['debt_b']['group_id'] === (int)$groupBId);

        $samePeople = (($candidate['person_a']['identity'] === $identityA
                && $candidate['person_b']['identity'] === $identityB)
            || ($candidate['person_a']['identity'] === $identityB
                && $candidate['person_b']['identity'] === $identityA));

        if ($sameGroups && $samePeople) {
            $match = $candidate;
            break;
        }
    }

    if (!$match) {
        return ['ok' => false, 'message' => 'הקיזוז כבר אינו רלוונטי. רענן את הדף'];
    }

    if (!$match['can_apply']) {
        return ['ok' => false, 'message' => 'אין לך הרשאה לרשום התחשבנות בשני האירועים'];
    }

    if ($amount > $match['offsetable'] + 0.01) {
        return [
            'ok'      => false,
            'message' => 'הסכום גדול מהניתן לקיזוז (' . currencySymbol()
                . number_format($match['offsetable'], 2) . ')',
        ];
    }

    $hasType = settlementTypesReady($pdo);
    $noteA   = 'קיזוז מול "' . $match['debt_b']['group_name'] . '"';
    $noteB   = 'קיזוז מול "' . $match['debt_a']['group_name'] . '"';

    $sql = $hasType
        ? "INSERT INTO settlements (group_id, from_member_id, to_member_id, amount, note, created_by, type)
           VALUES (?, ?, ?, ?, ?, ?, 'offset')"
        : "INSERT INTO settlements (group_id, from_member_id, to_member_id, amount, note, created_by)
           VALUES (?, ?, ?, ?, ?, ?)";

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            $match['debt_a']['group_id'],
            $match['debt_a']['from_member_id'], $match['debt_a']['to_member_id'],
            $amount, $noteA, (int)$userId,
        ]);

        $stmt->execute([
            $match['debt_b']['group_id'],
            $match['debt_b']['from_member_id'], $match['debt_b']['to_member_id'],
            $amount, $noteB, (int)$userId,
        ]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Offset failed: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'הקיזוז נכשל. שום דבר לא נרשם'];
    }

    $symbol    = currencySymbol();
    $remaining = round($match['offsetable'] - $amount, 2);

    $message = 'קוזזו ' . $symbol . number_format($amount, 2)
        . ' בין "' . $match['debt_a']['group_name'] . '" ל"' . $match['debt_b']['group_name'] . '"';

    if ($remaining > 0.01) {
        $message .= '. נותרו ' . $symbol . number_format($remaining, 2) . ' שאפשר לקזז';
    }

    return ['ok' => true, 'message' => $message];
}

/**
 * אירועים שחולקים משתתפים.
 *
 * מנהל אירוע שרוצה לדעת "עם מי עוד אני בקבוצה" - למשל כדי להבין
 * אם יש טעם לחפש קיזוזים - צריך את זה לפני שהוא בודק חוב אחד
 * אחרי השני.
 *
 * @return array זוגות אירועים, עם רשימת המשתתפים המשותפים
 */
function findSharedGroups(PDO $pdo, $userId) {
    $groups = offsetUserGroups($pdo, $userId);

    if (count($groups) < 2) {
        return [];
    }

    $byGroup = [];
    foreach ($groups as $group) {
        $stmt = $pdo->prepare("
            SELECT gm.nickname, gm.user_id, COALESCE(u.email, gm.email) AS email
            FROM group_members gm
            LEFT JOIN users u ON gm.user_id = u.id
            WHERE gm.group_id = ? AND gm.is_active = 1
        ");
        $stmt->execute([(int)$group['id']]);

        $people = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $member) {
            $identity = offsetIdentity($member);
            if ($identity === 'e') {
                continue;
            }
            $people[$identity] = $member['nickname'];
        }

        $byGroup[(int)$group['id']] = ['group' => $group, 'people' => $people];
    }

    $pairs = [];
    $ids   = array_keys($byGroup);

    for ($i = 0; $i < count($ids); $i++) {
        for ($j = $i + 1; $j < count($ids); $j++) {
            $a = $byGroup[$ids[$i]];
            $b = $byGroup[$ids[$j]];

            $shared = array_intersect_key($a['people'], $b['people']);

            // אירוע משותף מעניין רק כשיש בו יותר מאדם אחד; אחרת
            // זה פשוט המשתמש עצמו, שנמצא בכל האירועים שלו
            if (count($shared) < 2) {
                continue;
            }

            $pairs[] = [
                'group_a' => $a['group'],
                'group_b' => $b['group'],
                'shared'  => array_values($shared),
                'count'   => count($shared),
            ];
        }
    }

    usort($pairs, function ($x, $y) {
        return $y['count'] <=> $x['count'];
    });

    return $pairs;
}
