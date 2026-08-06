<?php
// includes/group_modals.php
// כל ה-Modals של דף הקבוצה

/**
 * בורר המשתתפים בקנייה.
 * מסומן = משתתף בהוצאה. הטופס שולח דווקא את *הלא* מסומנים,
 * כי זה מה שנשמר בטבלת ההחרגות.
 */
function renderParticipantPicker($members, $idPrefix) {
    ?>
    <div class="form-group participants-picker">
        <label>מי משתתף בהוצאה הזו?</label>
        <div class="picker-actions">
            <button type="button" class="btn-link" onclick="toggleAllParticipants('<?php echo $idPrefix; ?>', true)">
                סמן הכל
            </button>
            <button type="button" class="btn-link" onclick="toggleAllParticipants('<?php echo $idPrefix; ?>', false)">
                נקה הכל
            </button>
        </div>
        <div class="participants-list" id="<?php echo $idPrefix; ?>Participants">
            <?php foreach ($members as $member): ?>
            <label class="participant-option">
                <input type="checkbox"
                       class="<?php echo $idPrefix; ?>-participant"
                       value="<?php echo (int)$member['id']; ?>"
                       checked>
                <span><?php echo htmlspecialchars($member['nickname']); ?></span>
            </label>
            <?php endforeach; ?>
        </div>
        <small class="form-hint">
            מי שלא מסומן לא ישלם על הקנייה הזו, וחלקו יתחלק בין השאר
        </small>
    </div>
    <?php
}

function renderAddMemberModal($available_percentage) {
    ?>
    <div id="addMemberModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>הוספת משתתף חדש</h2>
                <span class="close" onclick="closeAddMemberModal()">&times;</span>
            </div>
            <form id="addMemberForm">
                <div class="form-group">
                    <label for="memberEmail">אימייל:</label>
                    <input type="email" id="memberEmail" required>
                </div>
                <div class="form-group">
                    <label for="memberNickname">כינוי:</label>
                    <input type="text" id="memberNickname" required>
                </div>
                <div class="form-group">
                    <label>איך מתחלקים?</label>
                    <div class="type-picker">
                        <input type="radio" name="participationType" id="participationType_shares" value="shares" checked onchange="toggleParticipationType()">
                        <label for="participationType_shares"><i class="fas fa-users"></i><span>נפשות</span></label>
                        <input type="radio" name="participationType" id="participationType_percentage" value="percentage" onchange="toggleParticipationType()">
                        <label for="participationType_percentage"><i class="fas fa-percentage"></i><span>אחוז</span></label>
                        <input type="radio" name="participationType" id="participationType_fixed" value="fixed" onchange="toggleParticipationType()">
                        <label for="participationType_fixed"><i class="fas fa-shekel-sign"></i><span>סכום קבוע</span></label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="memberValue" id="memberValueLabel">ערך השתתפות:</label>
                    <div class="input-with-suffix">
                        <input type="number" id="memberValue" step="0.01" min="0.01" required>
                        <span id="valueSuffix">%</span>
                    </div>
                    <small id="percentageInfo" class="form-hint">
                        נותרו <?php echo round($available_percentage, 2); ?>% זמינים להקצאה
                    </small>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-plus"></i> הוסף
                    </button>
                    <button type="button" class="btn-secondary" onclick="closeAddMemberModal()">
                        ביטול
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- מוצג אחרי יצירת הזמנה, עם קישור להעתקה -->
    <div id="inviteLinkModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>ההזמנה נוצרה</h2>
                <span class="close" onclick="closeInviteLinkModal()">&times;</span>
            </div>
            <p id="inviteLinkMessage" class="modal-text"></p>
            <div class="form-group">
                <label for="inviteLinkInput">קישור הצטרפות:</label>
                <input type="text" id="inviteLinkInput" readonly onclick="this.select()">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-primary" onclick="copyInviteLink()">
                    <i class="fas fa-copy"></i> העתק קישור
                </button>
                <a id="inviteWhatsappLink" class="btn-whatsapp" target="_blank" rel="noopener">
                    <i class="fab fa-whatsapp"></i> שלח בוואטסאפ
                </a>
                <button type="button" class="btn-secondary" onclick="closeInviteLinkModal()">
                    סגור
                </button>
            </div>
        </div>
    </div>
    <?php
}

function renderEditMemberModal() {
    ?>
    <div id="editMemberModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>עריכת פרטי משתתף</h2>
                <span class="close" onclick="closeEditMemberModal()">&times;</span>
            </div>
            <form id="editMemberForm">
                <input type="hidden" id="editMemberId">
                <div class="form-group">
                    <label>איך מתחלקים?</label>
                    <div class="type-picker">
                        <input type="radio" name="editParticipationType" id="editParticipationType_shares" value="shares" onchange="toggleEditParticipationType()">
                        <label for="editParticipationType_shares"><i class="fas fa-users"></i><span>נפשות</span></label>
                        <input type="radio" name="editParticipationType" id="editParticipationType_percentage" value="percentage" onchange="toggleEditParticipationType()">
                        <label for="editParticipationType_percentage"><i class="fas fa-percentage"></i><span>אחוז</span></label>
                        <input type="radio" name="editParticipationType" id="editParticipationType_fixed" value="fixed" onchange="toggleEditParticipationType()">
                        <label for="editParticipationType_fixed"><i class="fas fa-shekel-sign"></i><span>סכום קבוע</span></label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="editMemberValue" id="editMemberValueLabel">ערך השתתפות:</label>
                    <div class="input-with-suffix">
                        <input type="number" id="editMemberValue" step="0.01" min="0.01" required>
                        <span id="editValueSuffix">%</span>
                    </div>
                    <small id="editPercentageInfo" class="form-hint"></small>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i> שמור
                    </button>
                    <button type="button" class="btn-secondary" onclick="closeEditMemberModal()">
                        ביטול
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php
}

function renderAddPurchaseModal($members, $is_owner, $member_id, $featuresReady = true) {
    ?>
    <div id="addPurchaseModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>הוספת קנייה חדשה</h2>
                <span class="close" onclick="closeAddPurchaseModal()">&times;</span>
            </div>
            <form id="addPurchaseForm" enctype="multipart/form-data">
                <input type="hidden" id="purchaseItemId" value="">

                <?php if ($is_owner): ?>
                <div class="form-group">
                    <label for="purchaseMember">מי שילם:</label>
                    <select id="purchaseMember" required>
                        <option value="">בחר משתתף...</option>
                        <?php foreach ($members as $member): ?>
                        <option value="<?php echo (int)$member['id']; ?>"
                                <?php echo ((int)$member['id'] === (int)$member_id) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($member['nickname']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                <input type="hidden" id="purchaseMember" value="<?php echo (int)$member_id; ?>">
                <div class="form-group">
                    <label>הקנייה תירשם על שמך</label>
                    <div class="info-message">
                        <i class="fas fa-info-circle"></i>
                        רק מנהל הקבוצה יכול להוסיף קניות בשם משתתפים אחרים
                    </div>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="purchaseAmount">סכום הקנייה (₪):</label>
                    <input type="number" id="purchaseAmount" step="0.01" min="0.01" required>
                </div>
                <div class="form-group">
                    <label for="purchaseDescription">תיאור המוצרים:</label>
                    <textarea id="purchaseDescription" rows="3"></textarea>
                </div>

                <?php if ($featuresReady) renderParticipantPicker($members, 'add'); ?>

                <div class="form-group">
                    <label for="purchaseImage">תמונת קבלה:</label>
                    <input type="file" id="purchaseImage" accept="image/*" onchange="previewImage(event)">
                    <img id="imagePreview" class="image-preview" style="display: none;" alt="">
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-plus"></i> הוסף קנייה
                    </button>
                    <button type="button" class="btn-secondary" onclick="closeAddPurchaseModal()">
                        ביטול
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php
}

function renderEditPurchaseModal($members) {
    ?>
    <div id="editPurchaseModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>עריכת קנייה</h2>
                <span class="close" onclick="closeEditPurchaseModal()">&times;</span>
            </div>
            <form id="editPurchaseForm">
                <input type="hidden" id="editPurchaseId">
                <div class="form-group">
                    <label for="editPurchaseAmount">סכום הקנייה (₪):</label>
                    <input type="number" id="editPurchaseAmount" step="0.01" min="0.01" required>
                </div>
                <div class="form-group">
                    <label for="editPurchaseDescription">תיאור המוצרים:</label>
                    <textarea id="editPurchaseDescription" rows="3"></textarea>
                </div>

                <?php renderParticipantPicker($members, 'edit'); ?>

                <div class="modal-actions">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i> שמור
                    </button>
                    <button type="button" class="btn-secondary" onclick="closeEditPurchaseModal()">
                        ביטול
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php
}

function renderItemModal() {
    ?>
    <div id="itemModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="itemModalTitle">הוספת פריט</h2>
                <span class="close" onclick="closeItemModal()">&times;</span>
            </div>
            <form id="itemForm">
                <input type="hidden" id="itemId" value="">
                <div class="form-group">
                    <label for="itemTitle">מה צריך?</label>
                    <input type="text" id="itemTitle" placeholder="לדוגמה: פיתות" required>
                </div>
                <div class="form-group">
                    <label for="itemQuantity">כמות (אופציונלי):</label>
                    <input type="text" id="itemQuantity" placeholder="לדוגמה: 3 חבילות">
                </div>
                <div class="form-group">
                    <label for="itemNotes">הערות (אופציונלי):</label>
                    <textarea id="itemNotes" rows="2"></textarea>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i> שמור
                    </button>
                    <button type="button" class="btn-secondary" onclick="closeItemModal()">
                        ביטול
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php
}

function renderEventModal($group) {
    ?>
    <div id="eventModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>פרטי האירוע</h2>
                <span class="close" onclick="closeEventModal()">&times;</span>
            </div>
            <form id="eventForm">
                <div class="form-group">
                    <label for="eventName">שם האירוע:</label>
                    <input type="text" id="eventName"
                           value="<?php echo htmlspecialchars($group['name']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="eventDescription">תיאור:</label>
                    <textarea id="eventDescription" rows="2"><?php echo htmlspecialchars($group['description'] ?? ''); ?></textarea>
                </div>
                <div class="form-group">
                    <label for="eventDate">תאריך האירוע:</label>
                    <input type="date" id="eventDate"
                           value="<?php echo htmlspecialchars($group['event_date'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="eventLocation">מיקום:</label>
                    <input type="text" id="eventLocation" placeholder="לדוגמה: אצל סבתא"
                           value="<?php echo htmlspecialchars($group['event_location'] ?? ''); ?>">
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i> שמור
                    </button>
                    <button type="button" class="btn-secondary" onclick="closeEventModal()">
                        ביטול
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php
}

function renderImageModal() {
    ?>
    <div id="imageModal" class="modal">
        <div class="modal-content image-modal">
            <span class="close" onclick="closeImageModal()">&times;</span>
            <img id="modalImage" src="" alt="קבלה">
        </div>
    </div>
    <?php
}

/**
 * הגדרות החלוקה של כל הקבוצה.
 *
 * שלוש שיטות. השלישית, "נפשות חלקי", קיימת בדיוק בשביל המצב
 * שבו חלק מהמשתתפים מוגדרים באחוזים וחלקם בנפשות: בלי תעריף
 * קבוע לנפש אין שום דרך לתרגם "3 נפשות" למספר שאפשר להשוות
 * ל-"20%".
 */
function renderSplitSettingsModal($currentMode, $shareRate) {
    $rateValue = $shareRate > 0 ? $shareRate : '';
    ?>
    <div id="splitSettingsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>הגדרות חלוקה</h2>
                <span class="close" onclick="closeSplitSettings()">&times;</span>
            </div>
            <form id="splitSettingsForm">
                <div class="form-group">
                    <label>איך מתחלקים בקבוצה?</label>
                    <div class="type-picker">
                        <input type="radio" name="splitMode" id="splitMode_percentage" value="percentage"
                               <?php echo $currentMode === 'percentage' ? 'checked' : ''; ?>
                               onchange="toggleSplitMode()">
                        <label for="splitMode_percentage">
                            <i class="fas fa-percentage"></i><span>אחוזים</span>
                        </label>

                        <input type="radio" name="splitMode" id="splitMode_shares" value="shares"
                               <?php echo $currentMode === 'shares' ? 'checked' : ''; ?>
                               onchange="toggleSplitMode()">
                        <label for="splitMode_shares">
                            <i class="fas fa-users"></i><span>נפשות</span>
                        </label>

                        <input type="radio" name="splitMode" id="splitMode_rate" value="shares_rate"
                               <?php echo $currentMode === 'shares_rate' ? 'checked' : ''; ?>
                               onchange="toggleSplitMode()">
                        <label for="splitMode_rate">
                            <i class="fas fa-coins"></i><span>נפשות חלקי</span>
                        </label>
                    </div>
                    <small id="splitModeHint" class="form-hint"></small>
                </div>

                <div class="form-group" id="shareRateGroup" style="display: none;">
                    <label for="shareRateValue">תעריף לנפש:</label>
                    <div class="input-with-suffix">
                        <input type="number" id="shareRateValue" step="0.01" min="0.01"
                               value="<?php echo htmlspecialchars((string)$rateValue); ?>">
                        <span>₪</span>
                    </div>
                    <small class="form-hint">
                        כל משתתף שמוגדר בנפשות ישלם את התעריף כפול מספר הנפשות שלו,
                        והיתרה תתחלק בין בעלי האחוזים
                    </small>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i> החל על כולם
                    </button>
                    <button type="button" class="btn-secondary" onclick="closeSplitSettings()">
                        ביטול
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php
}
