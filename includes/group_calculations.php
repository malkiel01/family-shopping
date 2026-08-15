<?php
// includes/group_calculations.php
// לוגיקת חישובים וחלוקת עלויות

require_once __DIR__ . '/phone.php';
//
// ---------------------------------------------------------------
// מודל החישוב
// ---------------------------------------------------------------
// לכל קנייה יש קבוצת משתתפים משלה: כל החברים הפעילים, פחות מי
// שרושם הקנייה סימן כלא-משתתף (purchase_exclusions).
//
// לכל קנייה בנפרד:
//   1. חברים עם "סכום קבוע" מכסים נתח יחסי מהקנייה -
//      V * (סכום_הקנייה / סך_הכל). מי שהוחרג לא מכסה כלום,
//      ולכן משלם בסוף פחות מהסכום שהתחייב לו - וזה הרצוי.
//   2. היתרה מתחלקת בין חברי האחוזים שכן משתתפים, לפי
//      המשקל היחסי ביניהם (נרמול). כך החרגה של אחד מגלגלת
//      את חלקו על השאר במקום ליצור חור.
//   3. אם סך האחוזים בקבוצה קטן מ-100%, הפער נשמר כ"סכום לא
//      מוקצה" - בדיוק כמו בהתנהגות המקורית.
// ---------------------------------------------------------------

/**
 * מחשב את מאזן הקבוצה.
 *
 * @param array $members     חברים פעילים: id, nickname, participation_type, participation_value
 * @param array $purchases   קניות: id, member_id, amount, excluded_ids (מערך member_id)
 * @param array $settlements העברות שכבר בוצעו: from_member_id, to_member_id, amount
 */
/**
 * ממיר משתתפים שמוגדרים לפי נפשות לאחוזים.
 *
 * "נפשות" הוא הביטוי הטבעי לאירוע משפחתי: משפחה של ארבעה משלמת
 * כפול ממשפחה של שניים, בלי שאיש יחשב אחוזים בראש.
 *
 * מבחינת החישוב זו בסך הכל דרך נוחה לכתוב אחוזים: חברי הנפשות
 * מתחלקים ביניהם ביתרה שנשארה אחרי האחוזים שהוגדרו במפורש, לפי
 * היחס בין מספרי הנפשות שלהם. אחרי ההמרה כל שאר החישוב ממשיך
 * לעבוד בדיוק כמו קודם, בלי לדעת שקיים סוג כזה.
 *
 * דוגמה: אחד על 40%, ושניים על 3 ו-1 נפשות. הפול הוא 60%,
 * ולכן הם מקבלים 45% ו-15%.
 */
/**
 * מסיר רשומות כפולות של אותו משתתף.
 *
 * שורה כפולה ב-group_members - אותו מזהה, או אותו אדם שנוסף
 * פעמיים - מכפילה את חלקו בחישוב ומייצרת שתי שורות העברה זהות
 * על המסך. זה נראה כמו תקלה בחישוב, ואי אפשר להבין ממנו כלום.
 *
 * הבדיקה כאן זולה ורצה על כל טעינה, כי המחיר של לפספס כפילות
 * גבוה בהרבה מהמחיר של לבדוק.
 */
function dedupeMembers(array $members) {
    $seen   = [];
    $unique = [];

    foreach ($members as $member) {
        $id = (int)($member['id'] ?? 0);

        // מזהה זהה הוא בוודאות אותה רשומה
        if ($id > 0 && isset($seen['id' . $id])) {
            continue;
        }

        // אותו משתמש רשום שנוסף פעמיים לאותו אירוע
        $userId = (int)($member['user_id'] ?? 0);
        if ($userId > 0) {
            if (isset($seen['u' . $userId])) {
                continue;
            }
            $seen['u' . $userId] = true;
        }

        $seen['id' . $id] = true;
        $unique[] = $member;
    }

    return $unique;
}

function normalizeShareMembers(array $members, $shareRate = null) {
    $explicitPercentage = 0.0;
    $totalShares        = 0.0;

    foreach ($members as $member) {
        if ($member['participation_type'] === 'shares') {
            $totalShares += (float)$member['participation_value'];
        } elseif ($member['participation_type'] === 'percentage') {
            $explicitPercentage += (float)$member['participation_value'];
        }
    }

    if ($totalShares <= 0) {
        return $members;
    }

    // מצב "נפשות חלקי": נקבע תעריף קבוע לנפש, ולכן משתתף הנפשות
    // משלם סכום ידוע מראש - תעריף כפול מספר הנפשות - בדיוק כמו
    // משתתף בסכום קבוע. היתרה מתחלקת בין בעלי האחוזים.
    //
    // זה נדרש כשחלק מהמשתתפים באחוזים וחלקם בנפשות: בלי תעריף
    // אין שום דרך לתרגם "3 נפשות" למספר שאפשר להשוות ל-"20%".
    if ($shareRate !== null && (float)$shareRate > 0) {
        foreach ($members as &$member) {
            if ($member['participation_type'] !== 'shares') {
                continue;
            }

            $member['shares']              = (float)$member['participation_value'];
            $member['shareRate']           = (float)$shareRate;
            $member['participation_type']  = 'fixed';
            $member['participation_value'] = (float)$shareRate * (float)$member['participation_value'];
        }
        unset($member);

        return $members;
    }

    $pool = max(0.0, 100.0 - $explicitPercentage);

    foreach ($members as &$member) {
        if ($member['participation_type'] !== 'shares') {
            continue;
        }

        $member['shares']              = (float)$member['participation_value'];
        $member['participation_type']  = 'percentage';
        $member['participation_value'] = $pool * ((float)$member['participation_value'] / $totalShares);
    }
    unset($member);

    return $members;
}

function calculateGroupBalance(array $members, array $purchases, array $settlements = [], $shareRate = null) {
    $members = dedupeMembers($members);
    $members = normalizeShareMembers($members, $shareRate);

    $result = [
        'totalAmount'        => 0.0,
        'calculations'       => [],
        'transfers'          => [],
        'unallocated'        => 0.0,
        'coveragePercentage' => 0.0,
        'missingPercentage'  => 0.0,
        'fixedTotal'         => 0.0,
        'hasExclusions'      => false,
        'fixedOverflow'      => false,
        'isBalanced'         => true,
    ];

    $totalAmount = 0.0;
    foreach ($purchases as $purchase) {
        $totalAmount += (float)$purchase['amount'];
    }
    $result['totalAmount'] = $totalAmount;

    if (count($members) === 0) {
        return $result;
    }

    // --- בסיס ההשתתפות ברמת הקבוצה ---
    $totalPercentage = 0.0;
    $fixedTotal      = 0.0;
    foreach ($members as $member) {
        if ($member['participation_type'] === 'percentage') {
            $totalPercentage += (float)$member['participation_value'];
        } else {
            $fixedTotal += (float)$member['participation_value'];
        }
    }

    // אם האחוזים לא מגיעים ל-100, הפער נשאר לא מוקצה
    $coverage = min($totalPercentage, 100.0) / 100.0;

    $result['fixedTotal']         = $fixedTotal;
    $result['coveragePercentage'] = min($totalPercentage, 100.0);
    $result['missingPercentage']  = ($totalPercentage > 0 && $totalPercentage < 100)
        ? 100.0 - $totalPercentage
        : 0.0;
    $result['fixedOverflow']      = ($totalAmount > 0 && $fixedTotal > $totalAmount);

    $shouldPay    = [];
    $actuallyPaid = [];
    foreach ($members as $member) {
        $shouldPay[(int)$member['id']]    = 0.0;
        $actuallyPaid[(int)$member['id']] = 0.0;
    }

    $unallocated = 0.0;

    // --- מעבר קנייה-קנייה ---
    foreach ($purchases as $purchase) {
        $amount = (float)$purchase['amount'];
        $payer  = (int)$purchase['member_id'];

        if (isset($actuallyPaid[$payer])) {
            $actuallyPaid[$payer] += $amount;
        }

        if ($amount <= 0) {
            continue;
        }

        $excluded = [];
        foreach ($purchase['excluded_ids'] ?? [] as $excludedId) {
            $excluded[(int)$excludedId] = true;
        }
        if (count($excluded) > 0) {
            $result['hasExclusions'] = true;
        }

        // 1. חברי סכום קבוע מכסים נתח יחסי
        $fixedCovered = 0.0;
        if ($totalAmount > 0) {
            foreach ($members as $member) {
                $memberId = (int)$member['id'];
                if (isset($excluded[$memberId]) || $member['participation_type'] === 'percentage') {
                    continue;
                }
                $share = (float)$member['participation_value'] * ($amount / $totalAmount);
                $shouldPay[$memberId] += $share;
                $fixedCovered += $share;
            }
        }

        // 2. היתרה מתחלקת בין חברי האחוזים שמשתתפים
        $remainder = max(0.0, $amount - $fixedCovered);
        if ($remainder <= 0) {
            continue;
        }

        $includedWeight = 0.0;
        foreach ($members as $member) {
            $memberId = (int)$member['id'];
            if (isset($excluded[$memberId]) || $member['participation_type'] !== 'percentage') {
                continue;
            }
            $includedWeight += (float)$member['participation_value'];
        }

        // אין אף חבר אחוזים בקנייה הזו - כל היתרה נשארת לא מוקצית
        if ($includedWeight <= 0) {
            $unallocated += $remainder;
            continue;
        }

        $distributable = $remainder * $coverage;
        $unallocated  += $remainder - $distributable;

        foreach ($members as $member) {
            $memberId = (int)$member['id'];
            if (isset($excluded[$memberId]) || $member['participation_type'] !== 'percentage') {
                continue;
            }
            $shouldPay[$memberId] += $distributable
                * ((float)$member['participation_value'] / $includedWeight);
        }
    }

    $result['unallocated'] = $unallocated;

    // --- העברות שכבר בוצעו ---
    $settledOut = [];
    $settledIn  = [];
    foreach ($settlements as $settlement) {
        $from   = (int)$settlement['from_member_id'];
        $to     = (int)$settlement['to_member_id'];
        $amount = (float)$settlement['amount'];

        $settledOut[$from] = ($settledOut[$from] ?? 0.0) + $amount;
        $settledIn[$to]    = ($settledIn[$to] ?? 0.0) + $amount;
    }

    // --- סיכום לכל חבר ---
    foreach ($members as $member) {
        $memberId = (int)$member['id'];

        $paidOut = $settledOut[$memberId] ?? 0.0;
        $gotBack = $settledIn[$memberId] ?? 0.0;

        // מאזן גולמי: מה שהוציא על קניות פחות מה שהיה צריך לשלם
        $rawBalance = $actuallyPaid[$memberId] - $shouldPay[$memberId];

        // מאזן פתוח: אחרי התחשבנויות שכבר בוצעו
        $openBalance = $rawBalance + $paidOut - $gotBack;

        $result['calculations'][] = [
            'member'       => $member,
            'shouldPay'    => $shouldPay[$memberId],
            'actuallyPaid' => $actuallyPaid[$memberId],
            'settledOut'   => $paidOut,
            'settledIn'    => $gotBack,
            'balance'      => $rawBalance,
            'openBalance'  => $openBalance,
        ];
    }

    $result['transfers']  = calculateTransfers($result['calculations']);
    $result['isBalanced'] = count($result['transfers']) === 0;

    return $result;
}

/**
 * מייצר את רשימת ההעברות המינימלית לאיזון החשבון.
 * חמדני: מי שהכי חייב משלם למי שהכי מגיע לו.
 *
 * ---------------------------------------------------------------
 * למה הסדר נקבע לפי המאזן הגולמי ולא לפי הפתוח
 * ---------------------------------------------------------------
 * החלוקה החמדנית תלויה בסדר: מי ראשון בתור מקבל את השורה הגדולה.
 * כשהסדר נגזר מהמאזן הפתוח, כל תשלום מזיז את מי ששילם אחורה בתור
 * וכל התוכנית נבנית מחדש - עם אותם סכומים, אבל בין אנשים אחרים.
 *
 * זה מה שקרה באירוע אמיתי: שמואל היה חייב 159.26 למלכיאל ו-132.16
 * לחלילי. הוא שילם לחלילי את ה-132.16, ירד למקום השלישי בתור,
 * ויוסף חיים תפס את מקומו מול מלכיאל. התוצאה על המסך: שמואל שילם
 * לחלילי, ומיד אחר כך חייב לחלילי 159.26 - יותר ממה ששילם. הסכום
 * הכולל היה נכון לחלוטין, וזה בדיוק מה שהפך את זה לבלתי מובן.
 *
 * המאזן הגולמי - מה שכל אחד הוציא מול מה שהיה צריך - אינו משתנה
 * כשמישהו משלם. הוא נקבע מהקניות בלבד. לכן כשהוא קובע את הסדר,
 * התור קפוא: תשלום רק מקטין שורה או מוחק אותה, ואף שורה אחרת לא
 * זזה. לפני ההתחשבנות הראשונה שני המאזנים שווים, ולכן באירוע חדש
 * התוצאה זהה לחלוטין למה שהייתה קודם.
 */
function calculateTransfers(array $calculations) {
    $creditors = [];
    $debtors   = [];

    foreach ($calculations as $calculation) {
        $balance = round($calculation['openBalance'], 2);

        // עוגן הסדר. בהיעדר מאזן גולמי - הפתוח, כדי שקוד שקורא
        // לפונקציה עם רשימה מצומצמת ימשיך לעבוד כמו קודם.
        $anchor = round(isset($calculation['balance']) ? $calculation['balance'] : $balance, 2);

        if ($balance > 0.01) {
            $creditors[] = [
                'member'  => $calculation['member'],
                'balance' => $balance,
                'anchor'  => $anchor,
            ];
        } elseif ($balance < -0.01) {
            $debtors[] = [
                'member'  => $calculation['member'],
                'balance' => abs($balance),
                'anchor'  => -$anchor,
            ];
        }
    }

    // שוויון נשבר לפי מזהה ולא לפי סדר הרשימה: שני משתתפים עם
    // אותו מאזן בדיוק היו מתחלפים ביניהם בכל טעינה
    $order = function ($a, $b) {
        if (abs($a['anchor'] - $b['anchor']) > 0.005) {
            return $b['anchor'] <=> $a['anchor'];
        }

        return (int)$a['member']['id'] <=> (int)$b['member']['id'];
    };

    usort($creditors, $order);
    usort($debtors,   $order);

    $transfers     = [];
    $creditorIndex = 0;
    $debtorIndex   = 0;

    while ($creditorIndex < count($creditors) && $debtorIndex < count($debtors)) {
        $amount = min($creditors[$creditorIndex]['balance'], $debtors[$debtorIndex]['balance']);

        if ($amount > 0.01) {
            $transfers[] = [
                'from'    => $debtors[$debtorIndex]['member']['nickname'],
                'to'      => $creditors[$creditorIndex]['member']['nickname'],
                'from_id' => (int)$debtors[$debtorIndex]['member']['id'],
                'to_id'   => (int)$creditors[$creditorIndex]['member']['id'],
                'amount'  => round($amount, 2),
            ];
        }

        $creditors[$creditorIndex]['balance'] -= $amount;
        $debtors[$debtorIndex]['balance']     -= $amount;

        if ($creditors[$creditorIndex]['balance'] <= 0.01) {
            $creditorIndex++;
        }
        if ($debtors[$debtorIndex]['balance'] <= 0.01) {
            $debtorIndex++;
        }
    }

    return mergeTransfers($transfers);
}

/**
 * מאחד העברות בין אותם שני אנשים לשורה אחת.
 *
 * האלגוריתם עצמו אינו מסוגל לייצר זוג פעמיים - זה נבדק על
 * 200,000 תרחישים אקראיים - ובכל זאת השורה הזו כאן: שתי שורות
 * זהות על המסך נראות כמו טעות בכסף, ומזמינות תשלום כפול. אם
 * אי פעם ייכנס נתון פגום שיגרום לזה, המסך לא יציג אותו כך.
 */
function mergeTransfers(array $transfers) {
    $merged = [];

    foreach ($transfers as $transfer) {
        $key = $transfer['from_id'] . '>' . $transfer['to_id'];

        if (isset($merged[$key])) {
            $merged[$key]['amount'] = round($merged[$key]['amount'] + $transfer['amount'], 2);
            continue;
        }

        $merged[$key] = $transfer;
    }

    return array_values($merged);
}

/**
 * בונה סיכום טקסטואלי לשיתוף בוואטסאפ / העתקה.
 */
function buildSummaryText(array $group, array $result, array $shoppingItems = []) {
    $lines   = [];
    $lines[] = '*' . $group['name'] . '*';

    if (!empty($group['event_date'])) {
        $lines[] = '🗓️ ' . date('d/m/Y', strtotime($group['event_date']));
    }
    if (!empty($group['event_location'])) {
        $lines[] = '📍 ' . $group['event_location'];
    }

    $lines[] = '';
    $lines[] = '💰 סך ההוצאות: ₪' . number_format($result['totalAmount'], 2);
    $lines[] = '';

    $payers = [];
    foreach ($result['calculations'] as $calculation) {
        if ($calculation['actuallyPaid'] > 0) {
            $payers[] = '• ' . $calculation['member']['nickname']
                . ' — ₪' . number_format($calculation['actuallyPaid'], 2);
        }
    }
    if (count($payers) > 0) {
        $lines[] = '*מי שילם מה:*';
        $lines   = array_merge($lines, $payers);
        $lines[] = '';
    }

    if (count($result['transfers']) > 0) {
        $lines[] = '*העברות לסגירת החשבון:*';
        foreach ($result['transfers'] as $transfer) {
            $lines[] = '• ' . $transfer['from'] . ' ← ' . $transfer['to']
                . ': ₪' . number_format($transfer['amount'], 2);
        }
    } elseif ($result['totalAmount'] > 0) {
        $lines[] = '✅ הכל מאוזן — אין העברות פתוחות';
    }

    $openItems = array_filter($shoppingItems, function ($item) {
        return $item['status'] !== 'bought';
    });

    if (count($openItems) > 0) {
        $lines[] = '';
        $lines[] = '*עוד חסר:*';
        foreach ($openItems as $item) {
            $line = '• ' . $item['title'];
            if (!empty($item['quantity'])) {
                $line .= ' (' . $item['quantity'] . ')';
            }
            if (!empty($item['assigned_nickname'])) {
                $line .= ' — ' . $item['assigned_nickname'];
            }
            $lines[] = $line;
        }
    }

    return implode("\n", $lines);
}

/**
 * מרנדר את מסך החישובים.
 *
 * @param array $options viewer_member_id, is_owner, group_name -
 *        נדרשים כדי להחליט אילו כפתורי תשלום להציג בכל שורה.
 *        ברירות המחדל משאירות את המסך כפי שהיה.
 */
function renderCalculationsView(array $members, array $result, $canSettle,
                                array $settlementHistory = [], array $options = []) {

    // המספרים נשלפים מרשימת המשתתפים ולא בשאילתה נוספת: הם כבר
    // כאן, ומי שקורא לפונקציה בלי מספרים פשוט לא יראה את הכפתורים
    $phones = [];
    $names  = [];
    foreach ($members as $member) {
        $phones[(int)$member['id']] = memberPhone($member);
        $names[(int)$member['id']]  = (string)$member['nickname'];
    }

    $viewerMemberId = (int)($options['viewer_member_id'] ?? 0);
    $isOwner        = !empty($options['is_owner']);
    $groupName      = (string)($options['group_name'] ?? '');

    if ($result['totalAmount'] == 0 || count($members) === 0) {
        return '<div class="no-data">
            <i class="fas fa-info-circle"></i>
            <p>אין מספיק נתונים לחישוב</p>
            <p>יש להוסיף משתתפים וקניות</p>
        </div>';
    }

    ob_start();
    ?>
    <div class="calculation-summary">
        <div class="total-box">
            <i class="fas fa-receipt"></i>
            <h3>סכום כולל</h3>
            <div class="total-amount">₪<?php echo number_format($result['totalAmount'], 2); ?></div>
        </div>

        <?php if ($result['fixedTotal'] > 0): ?>
        <div class="info-box">
            <i class="fas fa-info-circle"></i>
            <p>סכומים קבועים: ₪<?php echo number_format($result['fixedTotal'], 2); ?></p>
        </div>
        <?php endif; ?>

        <?php if ($result['hasExclusions']): ?>
        <div class="info-box">
            <i class="fas fa-user-slash"></i>
            <p>חלק מהקניות מחולקות רק בין חלק מהמשתתפים</p>
        </div>
        <?php endif; ?>

        <?php if ($result['fixedOverflow']): ?>
        <div class="warning-box">
            <i class="fas fa-exclamation-triangle"></i>
            <h3>הסכומים הקבועים גבוהים מסך ההוצאות</h3>
            <p>סך התחייבויות קבועות: ₪<?php echo number_format($result['fixedTotal'], 2); ?></p>
            <p>יש לעדכן את מפתחות ההשתתפות</p>
        </div>
        <?php endif; ?>

        <?php if ($result['unallocated'] > 0.01): ?>
        <div class="warning-box">
            <i class="fas fa-exclamation-triangle"></i>
            <h3>יש סכום שאינו מוקצה לאף אחד</h3>
            <?php if ($result['missingPercentage'] > 0): ?>
            <p>מוגדר כרגע: <?php echo round($result['coveragePercentage'], 2); ?>%</p>
            <p>חסרים: <?php echo round($result['missingPercentage'], 2); ?>%</p>
            <?php endif; ?>
            <p>סכום לא מוקצה: ₪<?php echo number_format($result['unallocated'], 2); ?></p>
        </div>
        <?php endif; ?>
    </div>

    <div class="members-calculations">
        <h3>סיכום לפי משתתף:</h3>
        <?php foreach ($result['calculations'] as $calculation): ?>
        <?php $open = round($calculation['openBalance'], 2); ?>
        <div class="calc-card <?php echo $open >= 0 ? 'positive' : 'negative'; ?>">
            <div class="calc-member">
                <h4><?php echo htmlspecialchars($calculation['member']['nickname']); ?></h4>
                <span class="participation-info">
                    <?php if (isset($calculation['member']['shares'])): ?>
                        <?php // המשתתף הוגדר בנפשות, וההמרה לאחוז מוצגת לצידן ?>
                        <?php echo (int)$calculation['member']['shares']; ?> נפשות
                        (<?php echo round($calculation['member']['participation_value'], 1); ?>%)
                    <?php elseif ($calculation['member']['participation_type'] === 'percentage'): ?>
                        <?php echo $calculation['member']['participation_value']; ?>%
                    <?php else: ?>
                        ₪<?php echo number_format($calculation['member']['participation_value'], 2); ?> קבוע
                    <?php endif; ?>
                </span>
            </div>
            <div class="calc-details">
                <div class="calc-row">
                    <span>צריך לשלם:</span>
                    <span>₪<?php echo number_format($calculation['shouldPay'], 2); ?></span>
                </div>
                <div class="calc-row">
                    <span>שילם בפועל:</span>
                    <span>₪<?php echo number_format($calculation['actuallyPaid'], 2); ?></span>
                </div>
                <?php if ($calculation['settledOut'] > 0.01): ?>
                <div class="calc-row settled">
                    <span>העביר בהתחשבנות:</span>
                    <span>₪<?php echo number_format($calculation['settledOut'], 2); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($calculation['settledIn'] > 0.01): ?>
                <div class="calc-row settled">
                    <span>קיבל בהתחשבנות:</span>
                    <span>₪<?php echo number_format($calculation['settledIn'], 2); ?></span>
                </div>
                <?php endif; ?>
                <div class="calc-row balance">
                    <span><?php echo $open >= 0 ? 'מגיע לו' : 'חייב'; ?>:</span>
                    <span>₪<?php echo number_format(abs($open), 2); ?></span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="transfers-section">
        <?php
        // הכותרת נושאת מספר וסכום בכוונה. בלעדיהם אי אפשר לדעת
        // אם מה שרואים על המסך הוא מה שהשרת שלח - צילום מסך
        // מתגלגל בטלפון עלול לשכפל רצועה, ואז שתי שורות זהות
        // נראות כמו תקלה בחישוב. המספור מכריע את השאלה מיד.
        $transferTotal = 0.0;
        foreach ($result['transfers'] as $transfer) {
            $transferTotal += (float)$transfer['amount'];
        }
        ?>
        <h3>
            העברות נדרשות:
            <span class="transfers-count"><?php echo count($result['transfers']); ?></span>
        </h3>
        <?php if (count($result['transfers']) > 0): ?>
            <p class="transfers-total">
                סך הכל להעברה: <strong>₪<?php echo number_format($transferTotal, 2); ?></strong>
            </p>
            <?php foreach ($result['transfers'] as $index => $transfer): ?>
            <?php
            // שתי שורות ולא ארבעה פריטים בשורה אחת: בטלפון הם
            // נמעכו עד שהסכום דרס את השמות והכפתור יצא מהכרטיס
            $fromId = (int)$transfer['from_id'];
            $toId   = (int)$transfer['to_id'];

            $debtorPhone   = $phones[$fromId] ?? '';
            $creditorPhone = $phones[$toId]   ?? '';

            // מי שחייב רוצה את המספר של המקבל, כדי לפתוח את ביט
            // ולהעביר. מי שמגיע לו רוצה לשלוח תזכורת. שני צרכים
            // הפוכים, ולכן כל אחד רואה את הכפתור שלו בלבד -
            // כפתור שאינו שייך לך הוא רעש, ובטלפון גם מקום.
            $viewerIsDebtor   = ($viewerMemberId > 0 && $viewerMemberId === $fromId);
            $viewerIsCreditor = ($viewerMemberId > 0 && $viewerMemberId === $toId);

            $showBit = ($viewerIsDebtor || (!$viewerIsCreditor && $isOwner))
                && $creditorPhone !== '';

            $requestLink = '';
            if (($viewerIsCreditor || $isOwner) && !$viewerIsDebtor && $debtorPhone !== '') {
                $requestLink = whatsappLink($debtorPhone, paymentRequestText(
                    $transfer['from'], $transfer['to'],
                    $transfer['amount'], $groupName, $creditorPhone
                ));
            }
            ?>
            <div class="transfer-card">
                <span class="transfer-index"><?php echo $index + 1; ?></span>
                <div class="transfer-parties">
                    <span class="transfer-person">
                        <i class="fas fa-user"></i>
                        <?php echo htmlspecialchars($transfer['from']); ?>
                    </span>
                    <i class="fas fa-arrow-left transfer-direction" aria-label="משלם ל"></i>
                    <span class="transfer-person">
                        <i class="fas fa-user"></i>
                        <?php echo htmlspecialchars($transfer['to']); ?>
                    </span>
                </div>
                <div class="transfer-bottom">
                    <span class="transfer-amount">₪<?php echo number_format($transfer['amount'], 2); ?></span>
                    <?php if ($canSettle): ?>
                    <span class="transfer-actions">
                        <button class="btn-settle"
                                onclick="openSettleModal(<?php
                                    echo $transfer['from_id'], ', ', $transfer['to_id'], ', ', $transfer['amount'];
                                ?>, <?php echo htmlspecialchars(json_encode(
                                    $transfer['from'] . ' → ' . $transfer['to'], JSON_UNESCAPED_UNICODE
                                ), ENT_QUOTES); ?>)">
                            <i class="fas fa-check"></i> שולם
                        </button>
                        <button class="btn-transfer-debt" title="להעביר את החוב למשתתף אחר"
                                onclick="openDebtTransferModal(<?php
                                    echo $transfer['from_id'], ', ', $transfer['amount'];
                                ?>, <?php echo htmlspecialchars(json_encode(
                                    $transfer['from'], JSON_UNESCAPED_UNICODE
                                ), ENT_QUOTES); ?>)">
                            <i class="fas fa-right-left"></i> העבר חוב
                        </button>
                    </span>
                    <?php endif; ?>
                </div>

                <?php if ($showBit || $requestLink !== ''): ?>
                <div class="transfer-pay">
                    <?php if ($showBit): ?>
                    <?php // האפליקציה אינה מזיזה את הכסף - אין לביט ממשק
                          // שמאפשר זאת - אבל היא יכולה להסיר את כל מה
                          // שסביבו: המספר מועתק, והסכום על המסך ?>
                    <button class="btn-bit"
                            onclick="copyForBit(<?php echo htmlspecialchars(json_encode([
                                'phone'  => formatPhone($creditorPhone),
                                'raw'    => preg_replace('/\D+/', '', $creditorPhone),
                                'name'   => $transfer['to'],
                                'amount' => number_format($transfer['amount'], 2, '.', ''),
                            ], JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>, this)">
                        <i class="fas fa-mobile-screen"></i>
                        העתק מספר לביט
                    </button>
                    <?php endif; ?>

                    <?php if ($requestLink !== ''): ?>
                    <a class="btn-request" target="_blank" rel="noopener"
                       href="<?php echo htmlspecialchars($requestLink); ?>">
                        <i class="fab fa-whatsapp"></i>
                        בקש תשלום
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="no-transfers">
                <i class="fas fa-check-circle"></i>
                <p>הכל מאוזן - אין העברות נדרשות!</p>
            </div>
        <?php endif; ?>
    </div>

    <?php if (count($settlementHistory) > 0): ?>
    <div class="settlements-section">
        <h3>התחשבנויות שבוצעו:</h3>
        <?php foreach ($settlementHistory as $settlement): ?>
        <?php
        $isTransfer = (($settlement['type'] ?? 'payment') === 'transfer');
        $note       = trim((string)($settlement['note'] ?? ''));
        ?>
        <div class="settlement-row<?php echo $isTransfer ? ' is-transfer' : ''; ?>">
            <span class="settlement-parties">
                <?php echo htmlspecialchars($settlement['from_nickname']); ?>
                <i class="fas <?php echo $isTransfer ? 'fa-right-left' : 'fa-arrow-left'; ?>"
                   title="<?php echo $isTransfer ? 'החוב הועבר' : 'שולם'; ?>"></i>
                <?php echo htmlspecialchars($settlement['to_nickname']); ?>
                <?php if ($isTransfer): ?>
                    <span class="settlement-tag">העברת חוב</span>
                <?php endif; ?>
            </span>
            <span class="settlement-amount">₪<?php echo number_format($settlement['amount'], 2); ?></span>
            <span class="settlement-date"><?php echo date('d/m/Y', strtotime($settlement['created_at'])); ?></span>
            <?php if ($canSettle): ?>
            <button class="btn-icon-danger" title="בטל התחשבנות"
                    onclick="deleteSettlement(<?php echo (int)$settlement['id']; ?>)">
                <i class="fas fa-undo"></i>
            </button>
            <?php endif; ?>
            <?php if ($note !== ''): ?>
            <?php // ההערה נשמרה מאז ומעולם לא הוצגה. "ביט", "מזומן",
                  // "חלק ראשון" - זה בדיוק מה שמאפשר לזהות תשלום
                  // חודש אחרי, ובלעדיו נשארים עם תאריך וסכום בלבד ?>
            <span class="settlement-note">
                <i class="fas fa-quote-right"></i>
                <?php echo htmlspecialchars($note); ?>
            </span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}
