<?php
/**
 * כרטיס אירוע בדשבורד
 * includes/group_card.php
 *
 * מצפה למשתנה $group מהלולאה בדשבורד.
 */

$isClosed  = (($group['status'] ?? 'active') === 'closed');
$dateLabel = eventDateLabel($group['event_date'] ?? null);
?>
<div class="group-card <?php echo $group['is_owner'] ? 'owner' : ''; ?> <?php echo $isClosed ? 'closed' : ''; ?>">
    <?php if ($group['is_owner']): ?>
    <div class="owner-badge">
        <i class="fas fa-crown"></i> מנהל
    </div>
    <?php endif; ?>

    <div class="group-header">
        <h3><?php echo htmlspecialchars($group['name']); ?></h3>
        <?php if (!empty($group['description'])): ?>
        <p class="group-description"><?php echo htmlspecialchars($group['description']); ?></p>
        <?php endif; ?>

        <?php if ($dateLabel || !empty($group['event_location'])): ?>
        <div class="group-event-meta">
            <?php if ($dateLabel): ?>
            <span><i class="fas fa-calendar-day"></i> <?php echo htmlspecialchars($dateLabel); ?></span>
            <?php endif; ?>
            <?php if (!empty($group['event_location'])): ?>
            <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($group['event_location']); ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="group-stats">
        <div class="stat">
            <i class="fas fa-users"></i>
            <span><?php echo (int)$group['member_count']; ?> משתתפים</span>
        </div>
        <?php if (!$isClosed && (int)($group['open_items'] ?? 0) > 0): ?>
        <div class="stat highlight">
            <i class="fas fa-list-check"></i>
            <span><?php echo (int)$group['open_items']; ?> פריטים פתוחים</span>
        </div>
        <?php else: ?>
        <div class="stat">
            <i class="fas fa-shopping-bag"></i>
            <span><?php echo (int)$group['purchase_count']; ?> קניות</span>
        </div>
        <?php endif; ?>
        <div class="stat">
            <i class="fas fa-shekel-sign"></i>
            <span>₪<?php echo number_format($group['total_amount'] ?? 0, 2); ?></span>
        </div>
    </div>

    <div class="group-info">
        <p><i class="fas fa-user"></i> מנהל: <?php echo htmlspecialchars($group['owner_name']); ?></p>
        <?php if ($group['participation_type'] === 'shares'): ?>
        <p><i class="fas fa-users"></i> החלק שלך:
            <?php echo (int)$group['participation_value']; ?> נפשות
        </p>
        <?php elseif ($group['participation_type'] === 'percentage'): ?>
        <p><i class="fas fa-percentage"></i> החלק שלך:
            <?php echo $group['participation_value']; ?>%
        </p>
        <?php else: ?>
        <p><i class="fas fa-shekel-sign"></i> החלק שלך:
            ₪<?php echo number_format($group['participation_value'], 2); ?>
        </p>
        <?php endif; ?>
        <?php if ($isClosed): ?>
        <p class="closed-note"><i class="fas fa-lock"></i> האירוע נסגר</p>
        <?php endif; ?>
    </div>

    <div class="group-actions">
        <a href="group.php?id=<?php echo (int)$group['id']; ?>" class="btn-enter">
            <i class="fas fa-sign-in-alt"></i>
            <?php echo $isClosed ? 'צפה באירוע' : 'כניסה לאירוע'; ?>
        </a>
        <?php if ($group['is_owner']): ?>
        <?php // מנהל אינו יכול לעזוב את האירוע שלו, אבל כן למחוק אותו ?>
        <button class="btn-leave" title="מחיקת האירוע"
                onclick="deleteGroup(<?php echo (int)$group['id']; ?>,
                    <?php echo htmlspecialchars(json_encode($group['name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>)">
            <i class="fas fa-trash-can"></i> מחק
        </button>
        <?php else: ?>
        <button class="btn-leave" onclick="leaveGroup(<?php echo (int)$group['id']; ?>)">
            <i class="fas fa-sign-out-alt"></i> עזוב
        </button>
        <?php endif; ?>
    </div>
</div>
