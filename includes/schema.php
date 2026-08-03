<?php
// includes/schema.php
// בדיקה חד-פעמית האם מיגרציית "מנהל האירוע" הורצה.
//
// בלי זה, כל דף היה קורס בשרת שבו העדכון עוד לא הופעל.
// עם זה, הדף עובד כרגיל ומציג הודעה שמפנה להרצת המיגרציה.

function eventFeaturesReady(PDO $pdo) {
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME IN ('shopping_items', 'purchase_exclusions', 'settlements')
        ");
        $stmt->execute();
        $tables = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'purchase_groups'
              AND COLUMN_NAME IN ('event_date', 'event_location', 'status', 'closed_at')
        ");
        $stmt->execute();
        $columns = (int)$stmt->fetchColumn();

        $ready = ($tables === 3 && $columns === 4);
    } catch (Exception $e) {
        error_log("Schema check failed: " . $e->getMessage());
        $ready = false;
    }

    return $ready;
}

/**
 * באנר שמוצג כשהמיגרציה עוד לא הורצה.
 */
function renderMigrationNotice() {
    ?>
    <div class="migration-notice">
        <i class="fas fa-database"></i>
        <div>
            <strong>עדכון מסד הנתונים ממתין</strong>
            <p>
                תכונות האירוע (רשימת קניות, החרגות, סגירת חשבון) יופעלו
                אחרי הרצת <code>db/migrate.php</code>.
            </p>
        </div>
    </div>
    <?php
}
