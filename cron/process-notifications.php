<?php
/**
 * מעבד תור ההתראות ושולח אותן בפועל
 * cron/process-notifications.php
 *
 * הרצה מה-cron:  php cron/process-notifications.php
 * הרצה ידנית:    /family/cron/process-notifications.php?token=XXX
 *                (כאשר XXX הוא CRON_TOKEN מקובץ ה-.env)
 *
 * הגרסה הקודמת של הקובץ הזה הייתה שלד בלבד: היא סימנה שורות
 * כ-'sent' - ערך שאינו קיים ב-enum של העמודה - ועדכנה עמודה
 * sent_at שאינה קיימת. גם אילו הייתה רצה, היא הייתה נכשלת.
 *
 * שורה שאין לנמען שלה מנוי Push לא מסומנת כטופלה, אלא רק מקבלת
 * processed_at. כך היא לא נבחרת שוב על ידי ה-cron, אבל כן ממשיכה
 * להופיע בתוך האפליקציה כשהמשתמש ייכנס.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/WebPush.php';

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');

    $expected = $_ENV['CRON_TOKEN'] ?? '';
    $given    = $_GET['token'] ?? '';

    if ($expected === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        echo "גישה נדחתה. יש להגדיר CRON_TOKEN ב-.env ולהעביר אותו ב-?token=\n";
        exit;
    }
}

const QUEUE_BATCH        = 50;   // כמה התראות לעבד בהרצה אחת
const QUEUE_MAX_ATTEMPTS = 3;    // כמה פעמים לנסות לפני ויתור
const QUEUE_MAX_AGE_DAYS = 7;    // התראה ישנה מזה כבר לא רלוונטית

/**
 * נעילה שמונעת משתי הרצות לחפוף ולשלוח פעמיים.
 * ה-cron רץ כל דקה, והרצה איטית עלולה לחפוף את הבאה אחריה.
 */
$lockPath = sys_get_temp_dir() . '/family-notifications.lock';
$lock     = fopen($lockPath, 'c');

if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "הרצה קודמת עדיין פועלת. יוצא.\n";
    exit;
}

$pdo = getDBConnection();

if (VAPID_PUBLIC_KEY === '' || VAPID_PRIVATE_KEY === '') {
    echo "מפתחות VAPID אינם מוגדרים ב-.env. אין למי לשלוח.\n";
    exit;
}

$push = new WebPush(VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY, VAPID_SUBJECT);

$sent = $skipped = $failed = $expiredSubs = 0;

// ------------------------------------------------------------
// התראות שפג תוקפן - מסומנות ככשלון כדי שלא יישארו בתור לנצח
// ------------------------------------------------------------
$stmt = $pdo->prepare("
    UPDATE notification_queue
    SET status = 'failed', error_message = 'פג תוקף - ישנה מדי', processed_at = NOW()
    WHERE status = 'pending'
      AND created_at <= DATE_SUB(NOW(), INTERVAL ? DAY)
");
$stmt->execute([QUEUE_MAX_AGE_DAYS]);
$aged = $stmt->rowCount();

// ------------------------------------------------------------
// ההתראות שממתינות למשלוח
// ------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT id, user_id, type, data, attempts
    FROM notification_queue
    WHERE status = 'pending'
      AND processed_at IS NULL
      AND user_id IS NOT NULL
      AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
    ORDER BY priority ASC, id ASC
    LIMIT " . QUEUE_BATCH . "
");
$stmt->execute([QUEUE_MAX_AGE_DAYS]);
$pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

$subsStmt = $pdo->prepare("
    SELECT id, endpoint, p256dh, auth
    FROM push_subscriptions
    WHERE user_id = ? AND is_active = 1 AND p256dh IS NOT NULL AND auth IS NOT NULL
");

$deactivate = $pdo->prepare("UPDATE push_subscriptions SET is_active = 0 WHERE id = ?");
$touch      = $pdo->prepare("UPDATE push_subscriptions SET last_used = NOW() WHERE id = ?");

$markDone = $pdo->prepare("
    UPDATE notification_queue SET status = 'completed', processed_at = NOW() WHERE id = ?
");
$markNoSubscription = $pdo->prepare("
    UPDATE notification_queue SET processed_at = NOW() WHERE id = ?
");
$markRetry = $pdo->prepare("
    UPDATE notification_queue
    SET attempts = attempts + 1, last_attempt = NOW(), error_message = ?
    WHERE id = ?
");
$markFailed = $pdo->prepare("
    UPDATE notification_queue
    SET status = 'failed', attempts = attempts + 1, last_attempt = NOW(),
        error_message = ?, processed_at = NOW()
    WHERE id = ?
");

foreach ($pending as $row) {
    $subsStmt->execute([$row['user_id']]);
    $subscriptions = $subsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$subscriptions) {
        // אין לאן לדחוף. ההתראה נשארת ממתינה עבור הערוץ שבתוך
        // האפליקציה, ולא תיבחר שוב כאן.
        $markNoSubscription->execute([$row['id']]);
        $skipped++;
        continue;
    }

    $payload = $row['data'] ?: '{}';
    $anyOk   = false;
    $errors  = [];

    foreach ($subscriptions as $subscription) {
        $result = $push->send($subscription, $payload);

        if ($result['ok']) {
            $anyOk = true;
            $touch->execute([$subscription['id']]);
            continue;
        }

        if ($result['expired']) {
            $deactivate->execute([$subscription['id']]);
            $expiredSubs++;
            continue;
        }

        $errors[] = $result['error'];
    }

    if ($anyOk) {
        $markDone->execute([$row['id']]);
        $sent++;
        continue;
    }

    // כל המנויים פגו: אין טעם לנסות שוב, אבל הערוץ בתוך
    // האפליקציה עדיין יציג את ההתראה
    if (!$errors) {
        $markNoSubscription->execute([$row['id']]);
        $skipped++;
        continue;
    }

    $message = mb_substr(implode(' | ', $errors), 0, 500);

    if ((int)$row['attempts'] + 1 >= QUEUE_MAX_ATTEMPTS) {
        $markFailed->execute([$message, $row['id']]);
        $failed++;
    } else {
        $markRetry->execute([$message, $row['id']]);
    }
}

echo sprintf(
    "נשלחו: %d | ללא מנוי: %d | נכשלו: %d | מנויים שפגו: %d | ישנות שנסגרו: %d\n",
    $sent, $skipped, $failed, $expiredSubs, $aged
);

flock($lock, LOCK_UN);
fclose($lock);
