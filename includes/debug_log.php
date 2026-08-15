<?php
/**
 * יומן חישובים
 * includes/debug_log.php
 *
 * למה זה קיים
 * -----------
 * דיווח כמו "רשמתי תשלום וכל החישוב השתבש" הוא בלתי אפשרי לבדיקה
 * אחרי מעשה: הדף נטען מחדש, ומה שהיה על המסך רגע לפני הפעולה כבר
 * איננו. אפשר להריץ את המנוע שוב על אותם נתונים - וזה נעשה, על
 * 200,000 תרחישים - אבל זה בודק את המנוע, לא את מה שקרה בפועל.
 *
 * לכן היומן הזה מצלם את מצב החישוב פעמיים: רגע לפני שהפעולה
 * נכתבת למסד, ורגע אחריה. שני הצילומים נשמרים יחד עם הפעולה
 * עצמה, ואפשר להעמיד אותם זה מול זה במסך הניהול.
 *
 * שלוש שאלות שאפשר לענות עליהן רק ככה:
 *   1. כמה שורות העברה השרת באמת ייצר - לעומת כמה נראו על המסך.
 *   2. האם ההתחשבנות שנרשמה הופחתה מהמאזן של מי שהיה אמור.
 *   3. האם אותו אדם מופיע ביותר מרשומת משתתף אחת.
 *
 * היומן כבוי כברירת מחדל. הוא נדלק מאזור הניהול לזמן הבדיקה,
 * ונכבה אחריה.
 */

require_once __DIR__ . '/group_calculations.php';

/** כמה רשומות לשמור. מעבר לזה הישנות נמחקות. */
const DEBUG_LOG_LIMIT = 200;

// ============================================================
// המתג
// ============================================================

/**
 * האם טבלה קיימת. נבדק בשאילתה ולא ב-information_schema, כדי
 * שהקוד יעבוד גם ב-SQLite שבו רצות הבדיקות.
 */
function debugTableExists(PDO $pdo, $table) {
    // בלי זיכרון סטטי: הוא היה חוסך שאילתה זולה אחת ובתמורה
    // מחזיר תשובה על מסד אחר כשיש יותר מחיבור אחד בתהליך
    try {
        $pdo->query("SELECT 1 FROM `$table` WHERE 1 = 0");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function appSetting(PDO $pdo, $name, $default = null) {
    if (!debugTableExists($pdo, 'app_settings')) {
        return $default;
    }

    try {
        $stmt = $pdo->prepare("SELECT value FROM app_settings WHERE name = ?");
        $stmt->execute([$name]);
        $value = $stmt->fetchColumn();
    } catch (Exception $e) {
        return $default;
    }

    return $value === false ? $default : $value;
}

function setAppSetting(PDO $pdo, $name, $value) {
    if (!debugTableExists($pdo, 'app_settings')) {
        return false;
    }

    // בלי ON DUPLICATE KEY: התחביר שונה בין MySQL ל-SQLite, ושתי
    // פקודות פשוטות עושות בדיוק את אותו דבר בלי להתחייב למנוע.
    try {
        $stmt = $pdo->prepare("UPDATE app_settings SET value = ? WHERE name = ?");
        $stmt->execute([(string)$value, $name]);

        if ($stmt->rowCount() === 0) {
            $stmt = $pdo->prepare("INSERT INTO app_settings (name, value) VALUES (?, ?)");
            $stmt->execute([$name, (string)$value]);
        }
    } catch (Exception $e) {
        return false;
    }

    return true;
}

/** האם היומן פעיל כרגע */
function debugLogEnabled(PDO $pdo) {
    return debugTableExists($pdo, 'calculation_debug')
        && appSetting($pdo, 'debug_calculations', '0') === '1';
}

function setDebugLogEnabled(PDO $pdo, $on) {
    return setAppSetting($pdo, 'debug_calculations', $on ? '1' : '0');
}

// ============================================================
// הצילום
// ============================================================

/**
 * מצב החישוב של אירוע ברגע זה.
 *
 * הנתונים נטענים בדיוק באותן שאילתות שבהן group.php טוען אותם.
 * זו הנקודה: צילום שנטען אחרת יכול להיות נכון ועדיין לא לתאר
 * את מה שהמשתמש ראה.
 */
function debugSnapshot(PDO $pdo, $groupId) {
    $groupId = (int)$groupId;

    $stmt = $pdo->prepare("SELECT id, name, share_rate FROM purchase_groups WHERE id = ?");
    $stmt->execute([$groupId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$group) {
        return ['error' => 'האירוע לא נמצא'];
    }

    $shareRate = isset($group['share_rate']) && $group['share_rate'] !== null
        ? (float)$group['share_rate']
        : null;

    $stmt = $pdo->prepare("
        SELECT gm.*,
               COALESCE(u.name, gm.nickname) AS user_name,
               COALESCE(u.email, gm.email) AS email
        FROM group_members gm
        LEFT JOIN users u ON gm.user_id = u.id
        WHERE gm.group_id = ? AND gm.is_active = 1
        ORDER BY gm.joined_at, gm.id
    ");
    $stmt->execute([$groupId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT gp.id, gp.member_id, gp.amount, gp.description
        FROM group_purchases gp
        WHERE gp.group_id = ?
        ORDER BY gp.id
    ");
    $stmt->execute([$groupId]);
    $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $exclusions = [];
    if (debugTableExists($pdo, 'purchase_exclusions')) {
        $stmt = $pdo->prepare("
            SELECT pe.purchase_id, pe.member_id
            FROM purchase_exclusions pe
            JOIN group_purchases gp ON gp.id = pe.purchase_id
            WHERE gp.group_id = ?
        ");
        $stmt->execute([$groupId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $exclusions[(int)$row['purchase_id']][] = (int)$row['member_id'];
        }
    }

    foreach ($purchases as &$purchase) {
        $purchase['excluded_ids'] = $exclusions[(int)$purchase['id']] ?? [];
    }
    unset($purchase);

    $settlements = [];
    if (debugTableExists($pdo, 'settlements')) {
        $stmt = $pdo->prepare("
            SELECT s.id, s.from_member_id, s.to_member_id, s.amount, s.created_at,
                   f.nickname AS from_nickname, t.nickname AS to_nickname
            FROM settlements s
            JOIN group_members f ON f.id = s.from_member_id
            JOIN group_members t ON t.id = s.to_member_id
            WHERE s.group_id = ?
            ORDER BY s.id
        ");
        $stmt->execute([$groupId]);
        $settlements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // כמה התחשבנויות קיימות במסד לעומת כמה שרדו את ה-JOIN.
    // התחשבנות שמצביעה על משתתף שנמחק נעלמת מהחישוב בשקט, והחוב
    // ששולם חוזר להופיע. זה בדיוק התסמין של "שילמתי ולא ירד".
    $storedSettlements = 0;
    if (debugTableExists($pdo, 'settlements')) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM settlements WHERE group_id = ?");
        $stmt->execute([$groupId]);
        $storedSettlements = (int)$stmt->fetchColumn();
    }

    $balance = calculateGroupBalance($members, $purchases, $settlements, $shareRate);

    return debugCompact($group, $members, $purchases, $settlements, $storedSettlements, $balance);
}

/**
 * ממיר את המצב המלא לצילום קטן שאפשר לשמור ולהשוות.
 */
function debugCompact(array $group, array $members, array $purchases, array $settlements,
                      $storedSettlements, array $balance) {

    $memberRows = [];
    foreach ($members as $member) {
        $memberRows[] = [
            'id'      => (int)$member['id'],
            'user_id' => isset($member['user_id']) ? (int)$member['user_id'] : 0,
            'name'    => (string)$member['nickname'],
            'email'   => strtolower(trim((string)($member['email'] ?? ''))),
            'type'    => (string)$member['participation_type'],
            'value'   => (float)$member['participation_value'],
        ];
    }

    $balances = [];
    foreach ($balance['calculations'] as $calculation) {
        $balances[] = [
            'id'          => (int)$calculation['member']['id'],
            'name'        => (string)$calculation['member']['nickname'],
            'shouldPay'   => round($calculation['shouldPay'], 2),
            'paid'        => round($calculation['actuallyPaid'], 2),
            'settledOut'  => round($calculation['settledOut'], 2),
            'settledIn'   => round($calculation['settledIn'], 2),
            'open'        => round($calculation['openBalance'], 2),
        ];
    }

    $transfers = [];
    $transferTotal = 0.0;
    foreach ($balance['transfers'] as $transfer) {
        $transfers[] = [
            'from_id' => (int)$transfer['from_id'],
            'to_id'   => (int)$transfer['to_id'],
            'from'    => (string)$transfer['from'],
            'to'      => (string)$transfer['to'],
            'amount'  => round($transfer['amount'], 2),
        ];
        $transferTotal += (float)$transfer['amount'];
    }

    $settlementRows = [];
    foreach ($settlements as $settlement) {
        $settlementRows[] = [
            'id'      => (int)$settlement['id'],
            'from_id' => (int)$settlement['from_member_id'],
            'to_id'   => (int)$settlement['to_member_id'],
            'from'    => (string)($settlement['from_nickname'] ?? ''),
            'to'      => (string)($settlement['to_nickname'] ?? ''),
            'amount'  => round((float)$settlement['amount'], 2),
        ];
    }

    return [
        'group_id'       => (int)$group['id'],
        'group_name'     => (string)$group['name'],
        'member_rows'    => count($members),
        'unique_members' => count(dedupeMembers($members)),
        'purchases'      => count($purchases),
        'total'          => round($balance['totalAmount'], 2),
        'unallocated'    => round($balance['unallocated'], 2),
        'stored_settlements' => (int)$storedSettlements,
        'used_settlements'   => count($settlements),
        'settlement_sum' => round(array_sum(array_map(function ($row) {
            return $row['amount'];
        }, $settlementRows)), 2),
        'members'        => $memberRows,
        'balances'       => $balances,
        'transfers'      => $transfers,
        'transfer_count' => count($transfers),
        'transfer_total' => round($transferTotal, 2),
        'settlements'    => $settlementRows,
    ];
}

// ============================================================
// בדיקות שפויות על צילום
// ============================================================

/**
 * כל מה שאפשר להוכיח מתוך צילום אחד, בלי להשוות לכלום.
 *
 * כל בדיקה מחזירה ok/fail ומשפט אחד שמסביר מה נמצא. הרשימה
 * הזו היא התשובה לשאלה "האם המספרים על המסך יכולים להיות נכונים",
 * ואפשר לענות עליה בלי לדעת מה המשתמש ציפה לראות.
 */
function debugChecks(array $snapshot) {
    $checks = [];

    $add = function ($label, $ok, $detail) use (&$checks) {
        $checks[] = ['label' => $label, 'ok' => (bool)$ok, 'detail' => $detail];
    };

    // 1. רשומות כפולות של אותו משתתף
    $add(
        'אין רשומת משתתף כפולה',
        $snapshot['member_rows'] === $snapshot['unique_members'],
        $snapshot['member_rows'] . ' שורות, ' . $snapshot['unique_members'] . ' משתתפים שונים'
    );

    // 2. אותו שם ביותר משורה אחת. לא בהכרח תקלה - שני אנשים
    //    יכולים לחלוק שם - אבל זה ההסבר הראשון לשורה שמופיעה
    //    פעמיים על המסך, ולכן הוא נבדק בנפרד.
    $byName = [];
    foreach ($snapshot['members'] as $member) {
        $byName[$member['name']] = ($byName[$member['name']] ?? 0) + 1;
    }
    $repeatedNames = array_keys(array_filter($byName, function ($count) {
        return $count > 1;
    }));
    $add(
        'אין שם שמופיע בשתי רשומות',
        count($repeatedNames) === 0,
        count($repeatedNames) === 0 ? 'כל השמות ייחודיים' : implode(', ', $repeatedNames)
    );

    // 3. אותו אימייל בשתי רשומות - אותו אדם שנוסף פעמיים
    $byEmail = [];
    foreach ($snapshot['members'] as $member) {
        if ($member['email'] === '') {
            continue;
        }
        $byEmail[$member['email']] = ($byEmail[$member['email']] ?? 0) + 1;
    }
    $repeatedEmails = array_keys(array_filter($byEmail, function ($count) {
        return $count > 1;
    }));
    $add(
        'אין אימייל שמופיע בשתי רשומות',
        count($repeatedEmails) === 0,
        count($repeatedEmails) === 0 ? 'כל האימיילים ייחודיים' : implode(', ', $repeatedEmails)
    );

    // 4. התחשבנות שנעלמה ב-JOIN
    $add(
        'כל ההתחשבנויות נכנסו לחישוב',
        $snapshot['stored_settlements'] === $snapshot['used_settlements'],
        'במסד ' . $snapshot['stored_settlements'] . ', בחישוב ' . $snapshot['used_settlements']
    );

    // 5. שימור: מה שכל אחד צריך לשלם, ועוד מה שלא הוקצה, שווה
    //    לסך ההוצאות. אם לא - מישהו חויב פעמיים.
    $shouldPayTotal = 0.0;
    $openTotal      = 0.0;
    foreach ($snapshot['balances'] as $row) {
        $shouldPayTotal += $row['shouldPay'];
        $openTotal      += $row['open'];
    }
    $add(
        'סך החיובים שווה לסך ההוצאות',
        abs(($shouldPayTotal + $snapshot['unallocated']) - $snapshot['total']) < 0.05,
        'חיובים ' . number_format($shouldPayTotal, 2)
            . ' + לא מוקצה ' . number_format($snapshot['unallocated'], 2)
            . ' מול הוצאות ' . number_format($snapshot['total'], 2)
    );

    // 6. סכום כל המאזנים הפתוחים הוא אפס
    $add(
        'המאזנים מתאפסים',
        abs($openTotal) < 0.05,
        'סכום המאזנים: ' . number_format($openTotal, 2)
    );

    // 7. סך ההעברות שווה לסך החובות
    $creditTotal = 0.0;
    foreach ($snapshot['balances'] as $row) {
        if ($row['open'] > 0.01) {
            $creditTotal += $row['open'];
        }
    }
    $add(
        'סך ההעברות שווה לסך מה שמגיע',
        abs($creditTotal - $snapshot['transfer_total']) < 0.05,
        'העברות ' . number_format($snapshot['transfer_total'], 2)
            . ' מול זכאות ' . number_format($creditTotal, 2)
    );

    // 8. אין שתי שורות העברה בין אותו צמד מזהים
    $pairs = [];
    foreach ($snapshot['transfers'] as $transfer) {
        $key = $transfer['from_id'] . '>' . $transfer['to_id'];
        $pairs[$key] = ($pairs[$key] ?? 0) + 1;
    }
    $add(
        'אין שתי העברות בין אותו צמד',
        count(array_filter($pairs, function ($n) { return $n > 1; })) === 0,
        count($snapshot['transfers']) . ' שורות העברה'
    );

    // 9. אין שתי שורות העברה בין אותו צמד שמות. זה מה שהעין
    //    רואה כשורה כפולה, גם כשהמזהים שונים.
    $namePairs = [];
    foreach ($snapshot['transfers'] as $transfer) {
        $key = $transfer['from'] . '>' . $transfer['to'];
        $namePairs[$key] = ($namePairs[$key] ?? 0) + 1;
    }
    $duplicateNames = array_keys(array_filter($namePairs, function ($n) { return $n > 1; }));
    $add(
        'אין שתי העברות בין אותם שמות',
        count($duplicateNames) === 0,
        count($duplicateNames) === 0 ? 'אין כפילות' : implode(', ', $duplicateNames)
    );

    // 10. מספר ההעברות קטן ממספר המשתתפים. זה גבול מתמטי של
    //     האלגוריתם, ולכן חריגה ממנו פירושה נתון פגום.
    $add(
        'מספר ההעברות קטן ממספר המשתתפים',
        $snapshot['transfer_count'] < max(1, $snapshot['unique_members']),
        $snapshot['transfer_count'] . ' העברות מול ' . $snapshot['unique_members'] . ' משתתפים'
    );

    return $checks;
}

// ============================================================
// כתיבה לפני ואחרי
// ============================================================

/**
 * מצלם, מריץ את הפעולה, מצלם שוב ורושם.
 *
 * הפעולה עצמה מועברת כסגור, ולכן שני הצילומים תמיד עוטפים בדיוק
 * אותה כתיבה. הערך שהיא מחזירה עובר הלאה בלי שינוי.
 *
 * אם היומן כבוי או שהצילום נכשל, הפעולה עדיין רצה. יומן דיאגנוסטי
 * שמסוגל להפיל תשלום גרוע מהתקלה שהוא בא לחקור.
 */
function debugAround(PDO $pdo, $groupId, $userId, $action, array $payload, $callback) {
    if (!debugLogEnabled($pdo)) {
        return $callback();
    }

    $before = null;
    try {
        $before = debugSnapshot($pdo, $groupId);
    } catch (Exception $e) {
        $before = ['error' => $e->getMessage()];
    }

    $result = $callback();

    $after = null;
    try {
        $after = debugSnapshot($pdo, $groupId);
    } catch (Exception $e) {
        $after = ['error' => $e->getMessage()];
    }

    debugLogWrite($pdo, $groupId, $userId, $action, $payload, $before, $after);

    return $result;
}

function debugLogWrite(PDO $pdo, $groupId, $userId, $action, array $payload, $before, $after) {
    if (!debugTableExists($pdo, 'calculation_debug')) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO calculation_debug (group_id, user_id, action, payload, before_json, after_json)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            (int)$groupId,
            $userId ? (int)$userId : null,
            (string)$action,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            json_encode($before, JSON_UNESCAPED_UNICODE),
            json_encode($after,  JSON_UNESCAPED_UNICODE),
        ]);

        debugLogPrune($pdo);
    } catch (Exception $e) {
        error_log('debug log write failed: ' . $e->getMessage());
        return false;
    }

    return true;
}

/** משאיר רק את הרשומות האחרונות */
function debugLogPrune(PDO $pdo) {
    try {
        $keepFrom = $pdo->query("
            SELECT id FROM calculation_debug ORDER BY id DESC LIMIT 1 OFFSET " . (DEBUG_LOG_LIMIT - 1)
        )->fetchColumn();

        if ($keepFrom !== false) {
            $stmt = $pdo->prepare("DELETE FROM calculation_debug WHERE id < ?");
            $stmt->execute([(int)$keepFrom]);
        }
    } catch (Exception $e) {
        // גיזום שנכשל אינו סיבה להיכשל
    }
}

// ============================================================
// קריאה
// ============================================================

function debugLogEntries(PDO $pdo, $limit = 20, $groupId = 0) {
    if (!debugTableExists($pdo, 'calculation_debug')) {
        return [];
    }

    $limit = max(1, min(100, (int)$limit));

    try {
        if ((int)$groupId > 0) {
            $stmt = $pdo->prepare("
                SELECT d.*, u.name AS user_name, pg.name AS group_name
                FROM calculation_debug d
                LEFT JOIN users u ON u.id = d.user_id
                LEFT JOIN purchase_groups pg ON pg.id = d.group_id
                WHERE d.group_id = ?
                ORDER BY d.id DESC LIMIT $limit
            ");
            $stmt->execute([(int)$groupId]);
        } else {
            $stmt = $pdo->query("
                SELECT d.*, u.name AS user_name, pg.name AS group_name
                FROM calculation_debug d
                LEFT JOIN users u ON u.id = d.user_id
                LEFT JOIN purchase_groups pg ON pg.id = d.group_id
                ORDER BY d.id DESC LIMIT $limit
            ");
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }

    foreach ($rows as &$row) {
        $row['payload_data'] = json_decode($row['payload'] ?? '', true) ?: [];
        $row['before']       = json_decode($row['before_json'] ?? '', true) ?: [];
        $row['after']        = json_decode($row['after_json'] ?? '', true) ?: [];
        $row['diff']         = debugDiff($row['before'], $row['after']);
    }
    unset($row);

    return $rows;
}

function clearDebugLog(PDO $pdo) {
    if (!debugTableExists($pdo, 'calculation_debug')) {
        return 0;
    }

    try {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM calculation_debug")->fetchColumn();
        $pdo->exec("DELETE FROM calculation_debug");
        return $count;
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * דוח טקסט קצר שאפשר להעתיק ולהדביק.
 *
 * זו לא תצוגה מקוצרת של המסך אלא הפורמט העיקרי: המשתמש מבצע
 * תשלום בטלפון, לוחץ העתק, ומדביק. לכן הכל שורות קצרות בלי
 * טבלאות - מה שנשבר בהדבקה לא שווה כלום.
 */
function debugLogText(array $entries, array $snapshot = null) {
    $lines = [];

    if ($snapshot !== null && isset($snapshot['transfers'])) {
        $lines[] = '== מצב נוכחי: ' . $snapshot['group_name'] . ' (#' . $snapshot['group_id'] . ') ==';
        $lines[] = 'משתתפים: ' . $snapshot['member_rows']
            . ' (ייחודיים ' . $snapshot['unique_members'] . ')'
            . ' | קניות: ' . $snapshot['purchases']
            . ' | סה"כ: ' . number_format($snapshot['total'], 2);
        $lines[] = 'התחשבנויות: ' . $snapshot['stored_settlements']
            . ' במסד, ' . $snapshot['used_settlements'] . ' בחישוב'
            . ' | סכומן: ' . number_format($snapshot['settlement_sum'], 2);
        $lines[] = 'העברות: ' . $snapshot['transfer_count']
            . ' | סכומן: ' . number_format($snapshot['transfer_total'], 2);

        foreach ($snapshot['members'] as $member) {
            $lines[] = '  חבר #' . $member['id']
                . ' u' . $member['user_id']
                . ' ' . $member['name']
                . ' [' . $member['type'] . ' ' . rtrim(rtrim(number_format($member['value'], 2, '.', ''), '0'), '.') . ']';
        }

        foreach ($snapshot['balances'] as $row) {
            $lines[] = '  מאזן #' . $row['id'] . ' ' . $row['name']
                . ' חייב=' . number_format($row['shouldPay'], 2)
                . ' שילם=' . number_format($row['paid'], 2)
                . ' העביר=' . number_format($row['settledOut'], 2)
                . ' קיבל=' . number_format($row['settledIn'], 2)
                . ' פתוח=' . number_format($row['open'], 2);
        }

        foreach ($snapshot['transfers'] as $index => $transfer) {
            $lines[] = '  העברה ' . ($index + 1) . ': '
                . $transfer['from'] . ' (#' . $transfer['from_id'] . ')'
                . ' ← ' . $transfer['to'] . ' (#' . $transfer['to_id'] . ')'
                . ' = ' . number_format($transfer['amount'], 2);
        }

        foreach (debugChecks($snapshot) as $check) {
            $lines[] = '  [' . ($check['ok'] ? 'תקין' : 'תקלה') . '] '
                . $check['label'] . ' — ' . $check['detail'];
        }

        $lines[] = '';
    }

    if (count($entries) === 0) {
        $lines[] = '== אין רשומות ביומן ==';
        return implode("\n", $lines);
    }

    $lines[] = '== יומן פעולות (' . count($entries) . ') ==';

    // מהישן לחדש: כך רואים את הרצף כפי שקרה
    foreach (array_reverse($entries) as $entry) {
        $diff = $entry['diff'];

        $lines[] = '';
        $lines[] = '--- #' . $entry['id'] . ' ' . $entry['action']
            . ' | ' . $entry['created_at']
            . ' | ' . ($entry['group_name'] ?? ('אירוע ' . $entry['group_id']))
            . ' | ' . ($entry['user_name'] ?? '—');

        $payload = [];
        foreach ($entry['payload_data'] as $name => $value) {
            $payload[] = $name . '=' . (is_scalar($value) ? $value : json_encode($value));
        }
        if ($payload) {
            $lines[] = '    פעולה: ' . implode(' ', $payload);
        }

        if (empty($diff['ok'])) {
            $lines[] = '    ' . implode(' ', $diff['notes']);
            continue;
        }

        $lines[] = '    שורות העברה: ' . $diff['count_before'] . ' ← ' . $diff['count_after']
            . ' | סך להעברה: ' . number_format($diff['transfer_total_before'], 2)
            . ' ← ' . number_format($diff['transfer_total_after'], 2)
            . ' (שינוי ' . number_format($diff['transfer_total_delta'], 2) . ')';
        $lines[] = '    שורות משתתף: ' . $diff['members_before'] . ' ← ' . $diff['members_after'];

        foreach ($diff['notes'] as $note) {
            $lines[] = '    ' . $note;
        }

        foreach (['before' => 'לפני', 'after' => 'אחרי'] as $side => $label) {
            $state = $entry[$side];
            if (!isset($state['transfers'])) {
                continue;
            }
            foreach ($state['transfers'] as $index => $transfer) {
                $lines[] = '    ' . $label . ' ' . ($index + 1) . ': '
                    . $transfer['from'] . ' (#' . $transfer['from_id'] . ')'
                    . ' ← ' . $transfer['to'] . ' (#' . $transfer['to_id'] . ')'
                    . ' = ' . number_format($transfer['amount'], 2);
            }
        }

        // בדיקות שנכשלו אחרי הפעולה - זה מה שמעניין
        if (isset($entry['after']['transfers'])) {
            foreach (debugChecks($entry['after']) as $check) {
                if (!$check['ok']) {
                    $lines[] = '    [תקלה] ' . $check['label'] . ' — ' . $check['detail'];
                }
            }
        }
    }

    return implode("\n", $lines);
}

/**
 * מה השתנה בין שני צילומים.
 *
 * שורות ההעברה מושוות לפי שמות ולא לפי מזהים: מזהה חדש שמופיע
 * אחרי תשלום הוא בדיוק מה שמחפשים, וההשוואה לפי שם היא זו
 * שמראה אותו.
 */
function debugDiff($before, $after) {
    if (!is_array($before) || !is_array($after)
        || !isset($before['transfers']) || !isset($after['transfers'])) {
        return ['ok' => false, 'notes' => ['אין צילום מלא להשוואה']];
    }

    $notes = [];

    $key = function (array $transfer) {
        return $transfer['from'] . ' ← ' . $transfer['to'];
    };

    $beforeMap = [];
    foreach ($before['transfers'] as $transfer) {
        $k = $key($transfer);
        $beforeMap[$k] = ($beforeMap[$k] ?? 0) + $transfer['amount'];
    }

    $afterMap = [];
    foreach ($after['transfers'] as $transfer) {
        $k = $key($transfer);
        $afterMap[$k] = ($afterMap[$k] ?? 0) + $transfer['amount'];
    }

    foreach ($afterMap as $k => $amount) {
        if (!isset($beforeMap[$k])) {
            $notes[] = 'נוספה שורה: ' . $k . ' ' . number_format($amount, 2);
        } elseif (abs($beforeMap[$k] - $amount) > 0.01) {
            $notes[] = 'השתנתה: ' . $k . ' ' . number_format($beforeMap[$k], 2)
                . ' ← ' . number_format($amount, 2);
        }
    }

    foreach ($beforeMap as $k => $amount) {
        if (!isset($afterMap[$k])) {
            $notes[] = 'נסגרה: ' . $k . ' ' . number_format($amount, 2);
        }
    }

    // הבדיקה המכריעה: תשלום מוריד מסך החוב בדיוק בגובהו. אם
    // הסכום הכולל להעברה לא ירד - התשלום לא נספר, וזה הבאג.
    $delta = round($after['transfer_total'] - $before['transfer_total'], 2);

    return [
        'ok'    => true,
        'notes' => $notes,
        'transfer_total_before' => $before['transfer_total'],
        'transfer_total_after'  => $after['transfer_total'],
        'transfer_total_delta'  => $delta,
        'count_before' => $before['transfer_count'],
        'count_after'  => $after['transfer_count'],
        'members_before' => $before['member_rows'],
        'members_after'  => $after['member_rows'],
    ];
}
