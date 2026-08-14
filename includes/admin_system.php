<?php
/**
 * מערכת, תחזוקה ופיתוח
 * includes/admin_system.php
 *
 * החלק בממשק הניהול שאינו עוסק במשתמשים או באירועים אלא
 * בהתקנה עצמה: מה מוגדר, מה עובד, מה ממתין, ומה אפשר לנקות.
 *
 * שני כללים שחלים על כל מה שכאן:
 *
 *   1. אף בדיקה לא מפילה את המסך. שאילתה שנכשלת - למשל על טבלה
 *      שעוד לא נוצרה - מוחזרת כמצב "לא זמין", כי מסך התחזוקה
 *      הוא בדיוק המקום שאליו מגיעים כשמשהו לא תקין.
 *   2. כל פעולה שמוחקת נתונים מדווחת כמה שורות נגעו בה, ונרשמת
 *      ביומן הניהול.
 */

// logAdminAction מגיע מ-admin.php. הוא נדרש כאן כי גם הרצת
// מיגרציות וגם פעולות ניקוי נרשמות ביומן.
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/../db/migrations.php';

// ============================================================
// עזרים
// ============================================================

/**
 * מריץ שאילתת ספירה ומחזיר null אם היא נכשלה.
 * null פירושו "לא ידוע", ולא אפס - וההבדל חשוב בתצוגה.
 */
function systemCount(PDO $pdo, $sql, array $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return null;
    }
}

/** גודל קריא לבני אדם */
function formatBytes($bytes) {
    if ($bytes === null) {
        return '—';
    }

    $units = ['B', 'KB', 'MB', 'GB'];
    $i     = 0;

    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }

    return round($bytes, $i === 0 ? 0 : 1) . ' ' . $units[$i];
}

// ============================================================
// מידע על ההתקנה
// ============================================================

/**
 * נתוני הסביבה שבה המערכת רצה.
 *
 * @return array שורות של תווית => ערך
 */
function systemInfo(PDO $pdo) {
    try {
        $mysql = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    } catch (Exception $e) {
        $mysql = '—';
    }

    $dbSize = null;
    try {
        $stmt = $pdo->prepare("
            SELECT SUM(data_length + index_length)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = ?
        ");
        $stmt->execute([DB_NAME]);
        $dbSize = $stmt->fetchColumn();
    } catch (Exception $e) {
        $dbSize = null;
    }

    $uploads = uploadsUsage();

    return [
        'PHP'              => PHP_VERSION,
        'MySQL'            => $mysql,
        'שרת'              => $_SERVER['SERVER_SOFTWARE'] ?? '—',
        'מסד נתונים'       => DB_NAME,
        'גודל מסד הנתונים' => formatBytes($dbSize === null ? null : (float)$dbSize),
        'סביבה'            => ENVIRONMENT,
        'נתיב בסיס'        => APP_BASE_PATH,
        'אזור זמן'         => TIMEZONE,
        'קבצי קבלות'       => $uploads['count'] . ' קבצים, ' . formatBytes($uploads['bytes']),
        'סימן מטבע'        => currencySymbol()
            . ' (CURRENCY_SYMBOL של PHP: ' . reservedCurrencyConstant() . ')',
        'זמן שרת'          => date('Y-m-d H:i'),
    ];
}

/** כמה מקום תופסות הקבלות שהועלו */
function uploadsUsage() {
    $dir = dirname(__DIR__) . '/' . rtrim(UPLOAD_DIR, '/');

    if (!is_dir($dir)) {
        return ['count' => 0, 'bytes' => 0];
    }

    $count = 0;
    $bytes = 0;

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry[0] === '.') {
            continue;
        }

        $path = $dir . '/' . $entry;
        if (is_file($path)) {
            $count++;
            $bytes += filesize($path) ?: 0;
        }
    }

    return ['count' => $count, 'bytes' => $bytes];
}

/**
 * בדיקות תקינות של ההתקנה.
 *
 * @return array שורות: label, state (ok|warn|fail), detail
 */
function systemHealthChecks(PDO $pdo) {
    $checks = [];

    $add = function ($label, $state, $detail) use (&$checks) {
        $checks[] = ['label' => $label, 'state' => $state, 'detail' => $detail];
    };

    // --- סכמה ---
    $schemaReady = eventFeaturesReady($pdo);
    $add(
        'סכמת האירועים',
        $schemaReady ? 'ok' : 'fail',
        $schemaReady ? 'כל הטבלאות קיימות' : 'המיגרציה עוד לא הורצה - תכונות האירוע מוסתרות'
    );

    try {
        $status  = migrationStatus($pdo, DB_NAME);
        $pending = $status['pending'];
        $add(
            'מיגרציות',
            $pending === 0 ? 'ok' : 'warn',
            $pending === 0
                ? $status['total'] . ' צעדים, הכל מעודכן'
                : "$pending צעדים ממתינים להרצה"
        );
    } catch (Exception $e) {
        $add('מיגרציות', 'fail', 'בדיקת המצב נכשלה: ' . $e->getMessage());
    }

    // --- ערוצי יציאה ---
    $add(
        'התחברות עם Google',
        GOOGLE_CLIENT_ID !== '' ? 'ok' : 'warn',
        GOOGLE_CLIENT_ID !== '' ? 'CLIENT_ID מוגדר' : 'CLIENT_ID חסר - הכפתור לא יעבוד'
    );

    $smtp = trim($_ENV['SMTP_HOST'] ?? '');
    $add(
        'שליחת מיילים',
        $smtp !== '' ? 'ok' : 'warn',
        $smtp !== '' ? "SMTP דרך $smtp" : 'SMTP_HOST חסר - הזמנות ואיפוס סיסמה לא יישלחו במייל'
    );

    $vapid = (VAPID_PUBLIC_KEY !== '' && VAPID_PRIVATE_KEY !== '');
    $add(
        'התראות Push',
        $vapid ? 'ok' : 'warn',
        $vapid ? 'מפתחות VAPID מוגדרים' : 'מפתחות VAPID חסרים - התראות יוצגו רק בתוך האפליקציה'
    );

    // --- ה-cron ---
    $lastProcessed = null;
    try {
        $lastProcessed = $pdo->query("
            SELECT MAX(processed_at) FROM notification_queue WHERE processed_at IS NOT NULL
        ")->fetchColumn();
    } catch (Exception $e) {
        $lastProcessed = null;
    }

    if ($lastProcessed === null || $lastProcessed === false) {
        $add('עיבוד ההתראות', 'warn', 'אף התראה לא עובדה עדיין - ודא שה-cron רץ');
    } else {
        $ageHours = (time() - strtotime($lastProcessed)) / 3600;
        $add(
            'עיבוד ההתראות',
            $ageHours <= 24 ? 'ok' : 'warn',
            'עיבוד אחרון: ' . date('Y-m-d H:i', strtotime($lastProcessed))
        );
    }

    // --- הגדרות שעדיף לסגור אחרי השימוש ---
    if (trim($_ENV['MIGRATION_TOKEN'] ?? '') !== '') {
        $add('MIGRATION_TOKEN', 'warn', 'עדיין מוגדר ב-.env. אפשר להריץ מיגרציות מכאן, אז מומלץ למחוק אותו');
    }

    if (ENVIRONMENT === 'development') {
        $add('סביבה', 'warn', 'ENVIRONMENT=development - שגיאות מוצגות למשתמש');
    }

    $add(
        'HTTPS',
        isHttpsRequest() ? 'ok' : 'fail',
        isHttpsRequest() ? 'הבקשה מוצפנת' : 'הדף נטען ללא HTTPS - עוגיית הסשן אינה מסומנת secure'
    );

    // --- סימן המטבע ---
    $add(
        'סימן מטבע',
        isCurrencySymbol(currencySymbol()) ? 'ok' : 'fail',
        'מוצג ' . currencySymbol() . '. הקבוע CURRENCY_SYMBOL שמור ל-PHP ואינו בשימוש'
    );

    // --- שרידי קוד ---
    $legacy = dirname(__DIR__) . '/process_notifications.php';
    if (file_exists($legacy)) {
        $add(
            'process_notifications.php',
            'warn',
            'מעבד התור הישן עדיין בשורש. הוחלף ב-cron/process-notifications.php ומכיל טוקן קשיח'
        );
    }

    return $checks;
}

// ============================================================
// סטטיסטיקת ההתראות והמיילים
// ============================================================

/** מצב תור ההתראות, המנויים והמיילים */
function notificationStats(PDO $pdo) {
    $queue = [];
    try {
        $rows = $pdo->query("
            SELECT status, COUNT(*) AS c FROM notification_queue GROUP BY status
        ")->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach (['pending', 'completed', 'failed', 'read', 'sent'] as $status) {
            if (isset($rows[$status])) {
                $queue[$status] = (int)$rows[$status];
            }
        }
    } catch (Exception $e) {
        $queue = null;
    }

    return [
        'queue'         => $queue,
        'stuck'         => systemCount($pdo, "
            SELECT COUNT(*) FROM notification_queue
            WHERE status = 'pending' AND processed_at IS NULL
              AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
        "),
        'push_active'   => systemCount($pdo, "SELECT COUNT(*) FROM push_subscriptions WHERE is_active = 1"),
        'push_total'    => systemCount($pdo, "SELECT COUNT(*) FROM push_subscriptions"),
        'mail_sent'     => systemCount($pdo, "
            SELECT COUNT(*) FROM email_log
            WHERE status = 'sent' AND sent_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        "),
        'mail_failed'   => systemCount($pdo, "
            SELECT COUNT(*) FROM email_log
            WHERE status = 'failed' AND sent_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        "),
        'logins_failed' => systemCount($pdo, "
            SELECT COUNT(*) FROM login_attempts
            WHERE succeeded = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
        "),
    ];
}

/** המיילים האחרונים שיצאו, לאבחון */
function recentEmails(PDO $pdo, $limit = 15) {
    try {
        $stmt = $pdo->prepare("
            SELECT to_email, subject, status, sent_at
            FROM email_log ORDER BY id DESC LIMIT " . (int)$limit
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// ============================================================
// יומן הפעולות
// ============================================================

/** פעולות הניהול האחרונות, עם שם המנהל שביצע אותן */
function recentAdminActions(PDO $pdo, $limit = 60) {
    try {
        $stmt = $pdo->prepare("
            SELECT a.action, a.target_type, a.target_id, a.details, a.created_at,
                   u.name AS admin_name, u.email AS admin_email
            FROM admin_actions a
            LEFT JOIN users u ON u.id = a.admin_id
            ORDER BY a.id DESC LIMIT " . (int)$limit
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/** תיאור קריא לשם הפעולה ביומן */
function adminActionLabel($action) {
    $labels = [
        'force_accept'    => 'אישור הזמנה בשם המשתמש',
        'add_to_group'    => 'צירוף משתתף לאירוע',
        'delete_group'    => 'מחיקת אירוע',
        'soft'            => 'מחיקת אירוע',
        'restore'         => 'שחזור אירוע',
        'purge'           => 'מחיקת אירוע לצמיתות',
        'grant_admin'     => 'הענקת הרשאת ניהול',
        'revoke_admin'    => 'שלילת הרשאת ניהול',
        'disable_user'    => 'השבתת משתמש',
        'enable_user'     => 'הפעלת משתמש',
        'run_migrations'  => 'הרצת מיגרציות',
        'maintenance'     => 'פעולת תחזוקה',
    ];

    return $labels[$action] ?? $action;
}

// ============================================================
// מיגרציות
// ============================================================

/**
 * מריץ את המיגרציות הממתינות ורושם ביומן.
 *
 * @return array applied, skipped, failed, log
 */
function adminRunMigrations(PDO $pdo, $adminId) {
    $result = runPendingMigrations($pdo, DB_NAME);

    // רק הרצה שעשתה משהו מעניינת ביומן; הרצה שכולה דילוגים
    // היא בדיקה, ולא שינוי
    if ($result['applied'] > 0 || $result['failed'] > 0) {
        logAdminAction(
            $pdo, $adminId, 'run_migrations', 'system', null,
            sprintf('בוצעו %d, נכשלו %d', $result['applied'], $result['failed'])
        );
    }

    return $result;
}

// ============================================================
// תחזוקה
// ============================================================

/**
 * פעולות הניקוי הזמינות מהמסך.
 *
 * כל פעולה מחזירה ['ok' => bool, 'message' => string]. אף אחת
 * מהן לא נוגעת בנתוני אירועים - רק ביומנים, בתור ובקבצים
 * שאיש כבר לא מפנה אליהם.
 */
function maintenanceTasks() {
    return [
        'purge_queue' => [
            'title'   => 'ניקוי תור ההתראות',
            'detail'  => 'מוחק התראות שטופלו או נכשלו, מלפני יותר מ-30 יום',
            'confirm' => 'למחוק התראות מטופלות מלפני יותר מ-30 יום?',
        ],
        'purge_attempts' => [
            'title'   => 'ניקוי ניסיונות התחברות',
            'detail'  => 'מוחק רישומי ניסיונות מלפני יותר מ-30 יום',
            'confirm' => 'למחוק רישומי ניסיונות התחברות ישנים?',
        ],
        'purge_resets' => [
            'title'   => 'ניקוי טוקני איפוס',
            'detail'  => 'מוחק טוקנים שפג תוקפם או שכבר נוצלו',
            'confirm' => 'למחוק טוקני איפוס סיסמה שאינם תקפים?',
        ],
        'purge_push' => [
            'title'   => 'ניקוי מנויי Push',
            'detail'  => 'מוחק מנויים שהושבתו אחרי שהדפדפן דחה אותם',
            'confirm' => 'למחוק מנויי Push מושבתים?',
        ],
        'scan_uploads' => [
            'title'   => 'איתור קבצי קבלות יתומים',
            'detail'  => 'סורק בלבד - מדווח על קבצים שאין להם קנייה במסד',
            'confirm' => null,
        ],
        'purge_uploads' => [
            'title'   => 'מחיקת קבצי קבלות יתומים',
            'detail'  => 'מוחק את הקבצים שהסריקה מצאה. אין דרך לשחזר',
            'confirm' => 'למחוק לצמיתות את קבצי הקבלות שאין להם קנייה במסד?',
            'danger'  => true,
        ],
    ];
}

/** מריץ פעולת תחזוקה לפי מפתח */
function runMaintenanceTask(PDO $pdo, $adminId, $task) {
    if (!array_key_exists($task, maintenanceTasks())) {
        return ['ok' => false, 'message' => 'פעולה לא מוכרת'];
    }

    try {
        switch ($task) {
            case 'purge_queue':
                $result = maintenanceDelete($pdo, "
                    DELETE FROM notification_queue
                    WHERE status IN ('completed', 'failed', 'read')
                      AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
                ", 'התראות');
                break;

            case 'purge_attempts':
                $result = maintenanceDelete($pdo, "
                    DELETE FROM login_attempts
                    WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
                ", 'רישומים');
                break;

            case 'purge_resets':
                $result = maintenanceDelete($pdo, "
                    DELETE FROM password_resets
                    WHERE used_at IS NOT NULL OR expires_at < NOW()
                ", 'טוקנים');
                break;

            case 'purge_push':
                $result = maintenanceDelete($pdo, "
                    DELETE FROM push_subscriptions WHERE is_active = 0
                ", 'מנויים');
                break;

            case 'scan_uploads':
                $orphans = findOrphanUploads($pdo);

                if ($orphans === null) {
                    return ['ok' => false, 'message' => 'הסריקה נכשלה - לא ניתן לקרוא את רשימת הקניות'];
                }

                $result = [
                    'ok'      => true,
                    'message' => $orphans
                        ? count($orphans) . ' קבצים יתומים, ' . formatBytes(orphanBytes($orphans))
                        : 'לא נמצאו קבצים יתומים',
                ];
                break;

            case 'purge_uploads':
                $result = purgeOrphanUploads($pdo);
                break;

            default:
                return ['ok' => false, 'message' => 'פעולה לא מוכרת'];
        }
    } catch (Exception $e) {
        error_log("Maintenance task $task failed: " . $e->getMessage());

        return ['ok' => false, 'message' => 'הפעולה נכשלה. ייתכן שהטבלה עוד לא נוצרה'];
    }

    // סריקה אינה שינוי, ולכן אינה נרשמת
    if ($result['ok'] && $task !== 'scan_uploads') {
        logAdminAction($pdo, $adminId, 'maintenance', 'system', null, $task . ': ' . $result['message']);
    }

    return $result;
}

/** מריץ DELETE ומדווח כמה שורות ירדו */
function maintenanceDelete(PDO $pdo, $sql, $noun) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->rowCount();

    return [
        'ok'      => true,
        'message' => $rows > 0 ? "נמחקו $rows $noun" : "לא נמצא מה למחוק",
    ];
}

/**
 * קבצים בתיקיית ההעלאות שאין להם קנייה במסד.
 *
 * מחזיר null אם לא הצלחנו לקרוא את רשימת הקניות. ההבחנה
 * קריטית: רשימה ריקה בגלל שאילתה שנכשלה הייתה הופכת *כל*
 * קובץ ליתום, והמחיקה הייתה מוחקת את כל הקבלות.
 *
 * @return array|null נתיבים מלאים
 */
function findOrphanUploads(PDO $pdo) {
    try {
        $referenced = $pdo->query("
            SELECT image_path FROM group_purchases
            WHERE image_path IS NOT NULL AND image_path <> ''
        ")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        error_log('Orphan scan failed: ' . $e->getMessage());

        return null;
    }

    $known = [];
    foreach ($referenced as $path) {
        $known[basename((string)$path)] = true;
    }

    $dir = dirname(__DIR__) . '/' . rtrim(UPLOAD_DIR, '/');
    if (!is_dir($dir)) {
        return [];
    }

    $orphans = [];
    foreach (scandir($dir) ?: [] as $entry) {
        // קבצים שמתחילים בנקודה הם הגדרות התיקייה, לא קבלות
        if ($entry[0] === '.') {
            continue;
        }

        $path = $dir . '/' . $entry;

        if (is_file($path) && !isset($known[$entry])) {
            $orphans[] = $path;
        }
    }

    return $orphans;
}

/** סך הגודל של רשימת קבצים */
function orphanBytes(array $paths) {
    $bytes = 0;
    foreach ($paths as $path) {
        $bytes += @filesize($path) ?: 0;
    }

    return $bytes;
}

/** מוחק את הקבצים היתומים */
function purgeOrphanUploads(PDO $pdo) {
    $orphans = findOrphanUploads($pdo);

    if ($orphans === null) {
        return ['ok' => false, 'message' => 'הסריקה נכשלה - שום קובץ לא נמחק'];
    }

    if (!$orphans) {
        return ['ok' => true, 'message' => 'לא נמצאו קבצים יתומים'];
    }

    $freed   = orphanBytes($orphans);
    $deleted = 0;

    foreach ($orphans as $path) {
        if (@unlink($path)) {
            $deleted++;
        }
    }

    return [
        'ok'      => true,
        'message' => "נמחקו $deleted קבצים, שוחררו " . formatBytes($freed),
    ];
}
