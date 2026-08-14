<?php
/**
 * עדכון הקוד מגיטהאב
 * includes/admin_deploy.php
 *
 * למה זה קיים: השרת מריץ git, אבל הדרך היחידה להריץ בו פקודה
 * הייתה טרמינל של cPanel - שמתנתק כל דקה, ושהדבקה לתוכו מטלפון
 * מאבדת תווים באמצע. בפועל זה גרם לכך שקוד מוכן נשאר בגיטהאב
 * ימים, ואיש לא ידע שהוא לא בשרת.
 *
 * שלוש החלטות שמרניות, כי זה קוד שמריץ פקודות מערכת מבקשת ווב:
 *
 *   1. **אין שום קלט מהמשתמש בפקודה.** הארגומנטים קבועים בקוד,
 *      ובכל זאת עוברים escapeshellarg. אין פרמטר שאפשר להזריק
 *      דרכו, ולא ייתכן שיהיה אחד בעתיד בלי לגעת בקובץ הזה.
 *
 *   2. **--ff-only.** משיכה שמצריכה מיזוג נדחית ומדווחת, במקום
 *      להיפתר לבד. בשרת הזה כבר היו 28 קומיטים מקומיים; פתרון
 *      אוטומטי היה עלול לדרוס עבודה, ודף ווב אינו המקום להכריע
 *      בזה.
 *
 *   3. **המיגרציות רצות בתהליך ולא דרך shell.** הן ממילא קוד
 *      PHP, ולהריץ אותן דרך הקונסולה רק היה מוסיף עוד מקום
 *      להיכשל בו.
 */

require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/../db/migrations.php';

/** שורש הריפו - תיקיית האפליקציה */
function deployRepoRoot() {
    return dirname(__DIR__);
}

/**
 * האם הרצת פקודות מערכת אפשרית בכלל.
 *
 * אירוח משותף מרבה לחסום את exec ב-disable_functions, ואז
 * הכפתור חייב להסביר את זה ולא פשוט להיכשל.
 */
function shellFunctionAvailable() {
    if (!function_exists('exec')) {
        return false;
    }

    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));

    return !in_array('exec', $disabled, true);
}

/**
 * מריץ פקודת git בשורש הריפו.
 *
 * @param array $args ארגומנטים קבועים בלבד - ראו הערה בראש הקובץ
 * @return array{ok: bool, code: int, output: string}
 */
function runGit(array $args) {
    if (!shellFunctionAvailable()) {
        return ['ok' => false, 'code' => -1, 'output' => 'הרצת פקודות מערכת חסומה בשרת'];
    }

    $command = 'cd ' . escapeshellarg(deployRepoRoot())
        . ' && git ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';

    $output = [];
    $code   = 1;
    @exec($command, $output, $code);

    return [
        'ok'     => ($code === 0),
        'code'   => (int)$code,
        'output' => trim(implode("\n", $output)),
    ];
}

/**
 * מצב הקוד בשרת: איפה הוא עומד, ומה חסר לו.
 *
 * @return array
 */
function deployStatus() {
    if (!shellFunctionAvailable()) {
        return [
            'available' => false,
            'reason'    => 'הרצת פקודות מערכת חסומה בשרת (disable_functions). '
                . 'העדכון ימשיך לדרוש git pull מהטרמינל.',
        ];
    }

    $version = runGit(['--version']);
    if (!$version['ok']) {
        return [
            'available' => false,
            'reason'    => 'git אינו זמין למשתמש שמריץ את האתר: ' . $version['output'],
        ];
    }

    $head = runGit(['log', '-1', '--pretty=%h %s']);
    if (!$head['ok']) {
        return [
            'available' => false,
            'reason'    => 'תיקיית האפליקציה אינה מחוברת לגיטהאב: ' . $head['output'],
        ];
    }

    $branch = runGit(['rev-parse', '--abbrev-ref', 'HEAD']);
    $remote = runGit(['config', '--get', 'remote.origin.url']);

    return [
        'available' => true,
        'head'      => $head['output'],
        'branch'    => $branch['ok'] ? $branch['output'] : '—',
        'remote'    => $remote['ok'] ? $remote['output'] : '—',
    ];
}

/**
 * מושך את הקוד מגיטהאב ומריץ מיגרציות ממתינות.
 *
 * @return array{ok: bool, message: string, log: array}
 */
function runDeploy(PDO $pdo, $adminId) {
    $log = [];

    $step = function ($label, array $result) use (&$log) {
        $log[] = [
            'state'  => $result['ok'] ? 'applied' : 'failed',
            'label'  => $label,
            'note'   => $result['output'] !== '' ? $result['output'] : null,
        ];

        return $result['ok'];
    };

    $status = deployStatus();
    if (!$status['available']) {
        return ['ok' => false, 'message' => $status['reason'], 'log' => []];
    }

    $before = runGit(['rev-parse', 'HEAD']);

    // --- משיכה ---
    if (!$step('git fetch origin', runGit(['fetch', 'origin']))) {
        return [
            'ok'      => false,
            'message' => 'המשיכה מגיטהאב נכשלה. ייתכן שאין לשרת גישה לרשת',
            'log'     => $log,
        ];
    }

    // ff-only: אם נדרש מיזוג, נעצור ונדווח במקום להכריע לבד
    $pull = runGit(['merge', '--ff-only', '@{u}']);
    if (!$step('git merge --ff-only', $pull)) {
        return [
            'ok'      => false,
            'message' => 'לא ניתן לעדכן בלי מיזוג. יש בשרת קומיטים מקומיים - '
                . 'צריך לטפל בזה מהטרמינל, כדי לא לדרוס עבודה',
            'log'     => $log,
        ];
    }

    $after = runGit(['rev-parse', 'HEAD']);
    $moved = ($before['output'] !== $after['output']);

    $head = runGit(['log', '-1', '--pretty=%h %s']);
    $log[] = ['state' => 'applied', 'label' => 'הקוד בשרת', 'note' => $head['output']];

    // --- מיגרציות ---
    $migrations = runPendingMigrations($pdo, DB_NAME, function ($state, $label, $note) use (&$log) {
        if ($state === 'skipped') {
            return; // דילוגים הם הרוב המוחלט, והם רק מעלימים את החשוב
        }

        $log[] = ['state' => $state, 'label' => $label, 'note' => $note];
    });

    $log[] = [
        'state' => $migrations['failed'] > 0 ? 'failed' : 'applied',
        'label' => 'מיגרציות',
        'note'  => sprintf('בוצעו %d | דילוגים %d | כשלונות %d',
            $migrations['applied'], $migrations['skipped'], $migrations['failed']),
    ];

    logAdminAction(
        $pdo, $adminId, 'deploy', 'system', null,
        ($moved ? 'עודכן ל-' : 'כבר היה מעודכן על ') . $head['output']
    );

    $message = $moved
        ? 'הקוד עודכן. רענן את הדף כדי לראות את השינויים'
        : 'הקוד כבר היה מעודכן';

    if ($migrations['applied'] > 0) {
        $message .= sprintf(' (ובוצעו %d מיגרציות)', $migrations['applied']);
    }

    return ['ok' => true, 'message' => $message, 'log' => $log];
}
