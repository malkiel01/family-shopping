// js/group.js
// כל הלוגיקה של דף האירוע

const CONFIG = window.APP_CONFIG;

// ============================================================
// תשתית - קריאות לשרת
// ============================================================

/**
 * שולח פעולה לשרת. מחזיר את גוף התשובה, או null אם נכשל
 * (במקרה כזה כבר הוצגה הודעה למשתמש).
 */
async function callAction(action, fields = {}, options = {}) {
    const formData = options.formData || new FormData();
    formData.append('action', action);
    formData.append('csrf_token', CONFIG.csrfToken);

    for (const [key, value] of Object.entries(fields)) {
        if (Array.isArray(value)) {
            value.forEach(item => formData.append(key + '[]', item));
        } else if (value !== null && value !== undefined) {
            formData.append(key, value);
        }
    }

    try {
        const response = await fetch('group.php?id=' + CONFIG.groupId, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        });

        const data = await response.json();

        if (!data.success) {
            alert(data.message || 'הפעולה נכשלה');
            return null;
        }

        return data;
    } catch (error) {
        console.error('Action failed:', action, error);
        alert('שגיאת תקשורת. נסה שוב');
        return null;
    }
}

/** מבצע פעולה ומרענן את הדף בהצלחה */
async function callAndReload(action, fields = {}, options = {}) {
    const data = await callAction(action, fields, options);
    if (data) {
        location.reload();
    }
    return data;
}

// ============================================================
// כרטיסיות ו-Modals
// ============================================================

function showTab(tabName, element) {
    document.querySelectorAll('.content').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.tab').forEach(el => el.classList.remove('active'));

    document.getElementById(tabName).style.display = 'block';
    element.classList.add('active');
}

function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.add('is-open');
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.remove('is-open');
}

window.onclick = function (event) {
    if (!event.target.classList || !event.target.classList.contains('modal')) return;

    if (event.target.id === 'inviteLinkModal') {
        closeInviteLinkModal();
    } else {
        event.target.classList.remove('is-open');
    }
};

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal').forEach(modal => modal.classList.remove('is-open'));
    }
});

// ============================================================
// משתתפים
// ============================================================

function showAddMemberModal() {
    openModal('addMemberModal');
    updatePercentageInfo();
}
function closeAddMemberModal() {
    closeModal('addMemberModal');
    document.getElementById('addMemberForm').reset();
}

/**
 * כל מה שמשתנה במסך לפי סוג ההשתתפות שנבחר.
 * "נפשות" הוא הסוג הטבעי לאירוע משפחתי, ולכן הוא ברירת המחדל.
 */
const PARTICIPATION_UI = {
    shares: {
        label:  'כמה נפשות?',
        suffix: 'נפשות',
        step:   '1',
        min:    '1',
        hint:   'משפחה של ארבעה משלמת כפול ממשפחה של שניים. החלוקה מחושבת לפי היחס בין כולם'
    },
    percentage: {
        label:  'איזה אחוז?',
        suffix: '%',
        step:   '0.01',
        min:    '0.01',
        hint:   null   // נכתב בנפרד, כי הוא תלוי במה שנותר להקצאה
    },
    fixed: {
        label:  'איזה סכום?',
        suffix: '₪',
        step:   '0.01',
        min:    '0.01',
        hint:   'סכום קבוע בשקלים, שלא מושפע מהחלוקה של השאר'
    }
};

/** מחיל את הגדרות הסוג על שדה הערך, התווית והרמז */
function applyParticipationUI(type, ids) {
    const ui    = PARTICIPATION_UI[type] || PARTICIPATION_UI.percentage;
    const input = document.getElementById(ids.input);

    document.getElementById(ids.label).textContent  = ui.label;
    document.getElementById(ids.suffix).textContent = ui.suffix;
    input.step = ui.step;
    input.min  = ui.min;

    // נפשות הן מספר שלם - אין חצי נפש
    if (type === 'shares' && input.value) {
        input.value = Math.max(1, Math.round(parseFloat(input.value) || 1));
    }

    document.getElementById(ids.hint).textContent = ui.hint !== null
        ? ui.hint
        : `נותרו ${CONFIG.availablePercentage}% זמינים להקצאה`;
}

function toggleParticipationType() {
    const type = document.querySelector('input[name="participationType"]:checked').value;

    applyParticipationUI(type, {
        input:  'memberValue',
        label:  'memberValueLabel',
        suffix: 'valueSuffix',
        hint:   'percentageInfo'
    });
}

function updatePercentageInfo() {
    toggleParticipationType();
}

function editMember(memberId, type, value) {
    document.getElementById('editMemberId').value = memberId;
    document.getElementById('editMemberValue').value = type === 'shares' ? Math.round(value) : value;
    document.querySelector(`input[name="editParticipationType"][value="${type}"]`).checked = true;
    toggleEditParticipationType();
    openModal('editMemberModal');
}

function closeEditMemberModal() {
    closeModal('editMemberModal');
}

function toggleEditParticipationType() {
    const type = document.querySelector('input[name="editParticipationType"]:checked').value;

    applyParticipationUI(type, {
        input:  'editMemberValue',
        label:  'editMemberValueLabel',
        suffix: 'editValueSuffix',
        hint:   'editPercentageInfo'
    });
}

function removeMember(memberId) {
    if (!confirm('להסיר את המשתתף מהאירוע?')) return;
    callAndReload('removeMember', { member_id: memberId });
}

function splitEqually() {
    if (!confirm('לחלק 100% שווה בשווה בין כל המשתתפים?\nההגדרות הנוכחיות יידרסו.')) return;
    callAndReload('splitEqually');
}

function cancelInvitation(invitationId) {
    if (!confirm('לבטל את ההזמנה?')) return;
    callAndReload('cancelInvitation', { invitation_id: invitationId });
}

// ============================================================
// קישור הזמנה
// ============================================================

// נדלק כשההזמנה נוצרה זה עתה - אז סגירת החלון צריכה לרענן
let reloadOnInviteClose = false;

function buildJoinUrl(token) {
    return `${location.origin}${CONFIG.basePath}/join.php?token=${encodeURIComponent(token)}`;
}

function showInviteLink(token, message) {
    const url = buildJoinUrl(token);

    document.getElementById('inviteLinkInput').value = url;
    document.getElementById('inviteLinkMessage').textContent =
        message || 'שלחו את הקישור למי שהזמנתם כדי שיוכל להצטרף';
    document.getElementById('inviteWhatsappLink').href =
        'https://wa.me/?text=' + encodeURIComponent('הוזמנת לאירוע! להצטרפות: ' + url);

    openModal('inviteLinkModal');
}

function closeInviteLinkModal() {
    closeModal('inviteLinkModal');

    if (reloadOnInviteClose) {
        reloadOnInviteClose = false;
        location.reload();
    }
}

async function copyToClipboard(text) {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch (error) {
        // דפדפנים ישנים / הקשר לא מאובטח
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();

        let ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (e) {
            ok = false;
        }

        document.body.removeChild(textarea);
        return ok;
    }
}

async function copyInviteLink() {
    const ok = await copyToClipboard(document.getElementById('inviteLinkInput').value);
    alert(ok ? 'הקישור הועתק' : 'לא הצלחנו להעתיק. סמנו את הקישור והעתיקו ידנית');
}

// ============================================================
// בורר משתתפים בקנייה
// ============================================================

function toggleAllParticipants(prefix, checked) {
    document.querySelectorAll('.' + prefix + '-participant')
        .forEach(checkbox => { checkbox.checked = checked; });
}

/** מחזיר את מזהי מי ש*לא* מסומן - אלו ההחרגות */
function getExcludedIds(prefix) {
    return Array.from(document.querySelectorAll('.' + prefix + '-participant'))
        .filter(checkbox => !checkbox.checked)
        .map(checkbox => checkbox.value);
}

function setParticipants(prefix, excludedIds) {
    const excluded = (excludedIds || []).map(String);
    document.querySelectorAll('.' + prefix + '-participant')
        .forEach(checkbox => { checkbox.checked = !excluded.includes(checkbox.value); });
}

// ============================================================
// קניות
// ============================================================

function showAddPurchaseModal() {
    openModal('addPurchaseModal');
}

function closeAddPurchaseModal() {
    closeModal('addPurchaseModal');
    document.getElementById('addPurchaseForm').reset();
    document.getElementById('purchaseItemId').value = '';
    document.getElementById('imagePreview').style.display = 'none';
    toggleAllParticipants('add', true);
}

function previewImage(event) {
    const file = event.target.files[0];
    const preview = document.getElementById('imagePreview');

    if (!file) {
        preview.style.display = 'none';
        return;
    }

    const reader = new FileReader();
    reader.onload = e => {
        preview.src = e.target.result;
        preview.style.display = 'block';
    };
    reader.readAsDataURL(file);
}

/** "קניתי" מתוך רשימת הפריטים - פותח את טופס הקנייה עם הפריט מקושר */
function buyItem(itemId, title) {
    showAddPurchaseModal();
    document.getElementById('purchaseItemId').value = itemId;
    document.getElementById('purchaseDescription').value = title;
    document.getElementById('purchaseAmount').focus();
}

function editPurchase(purchase) {
    document.getElementById('editPurchaseId').value = purchase.id;
    document.getElementById('editPurchaseAmount').value = purchase.amount;
    document.getElementById('editPurchaseDescription').value = purchase.description || '';
    setParticipants('edit', purchase.excluded);
    openModal('editPurchaseModal');
}

function closeEditPurchaseModal() {
    closeModal('editPurchaseModal');
}

function deletePurchase(purchaseId) {
    if (!confirm('למחוק את הקנייה?')) return;
    callAndReload('deletePurchase', { purchase_id: purchaseId });
}

function showImageModal(src) {
    document.getElementById('modalImage').src = src;
    openModal('imageModal');
}

function closeImageModal() {
    closeModal('imageModal');
}

// ============================================================
// רשימת הקניות
// ============================================================

function showItemModal() {
    document.getElementById('itemModalTitle').textContent = 'הוספת פריט';
    document.getElementById('itemForm').reset();
    document.getElementById('itemId').value = '';
    openModal('itemModal');
    document.getElementById('itemTitle').focus();
}

function editItem(item) {
    document.getElementById('itemModalTitle').textContent = 'עריכת פריט';
    document.getElementById('itemId').value = item.id;
    document.getElementById('itemTitle').value = item.title || '';
    document.getElementById('itemQuantity').value = item.quantity || '';
    document.getElementById('itemNotes').value = item.notes || '';
    openModal('itemModal');
}

function closeItemModal() {
    closeModal('itemModal');
}

function setItemStatus(itemId, status) {
    callAndReload('setItemStatus', { item_id: itemId, status: status });
}

function deleteItem(itemId) {
    if (!confirm('למחוק את הפריט מהרשימה?')) return;
    callAndReload('deleteItem', { item_id: itemId });
}

// ============================================================
// התחשבנות
// ============================================================

function markSettled(fromMemberId, toMemberId, amount) {
    if (!confirm(`לרשום שההעברה של ₪${amount} בוצעה?`)) return;

    callAndReload('addSettlement', {
        from_member_id: fromMemberId,
        to_member_id: toMemberId,
        amount: amount
    });
}

function deleteSettlement(settlementId) {
    if (!confirm('לבטל את רישום ההתחשבנות?')) return;
    callAndReload('deleteSettlement', { settlement_id: settlementId });
}

// ============================================================
// האירוע
// ============================================================

function showEventModal() {
    openModal('eventModal');
}

function closeEventModal() {
    closeModal('eventModal');
}

function closeEvent() {
    if (!confirm('לסגור את האירוע?\nלא יהיה ניתן להוסיף קניות או לשנות פרטים עד שייפתח מחדש.')) return;
    callAndReload('closeEvent');
}

function reopenEvent() {
    callAndReload('reopenEvent');
}

async function shareSummary() {
    const text = CONFIG.summaryText;

    if (navigator.share) {
        try {
            await navigator.share({ text: text });
            return;
        } catch (error) {
            if (error.name === 'AbortError') return;
        }
    }

    window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank', 'noopener');
}

// ============================================================
// טפסים
// ============================================================

document.addEventListener('DOMContentLoaded', function () {
    const addMemberForm = document.getElementById('addMemberForm');
    if (addMemberForm) {
        addMemberForm.addEventListener('submit', async function (event) {
            event.preventDefault();

            const type = document.querySelector('input[name="participationType"]:checked').value;
            const value = parseFloat(document.getElementById('memberValue').value);

            if (!(value > 0)) {
                alert('ערך ההשתתפות חייב להיות חיובי');
                return;
            }
            if (type === 'percentage' && value > CONFIG.availablePercentage) {
                alert(`נותרו רק ${CONFIG.availablePercentage}% להקצאה`);
                return;
            }

            const data = await callAction('addMember', {
                email: document.getElementById('memberEmail').value,
                nickname: document.getElementById('memberNickname').value,
                participation_type: type,
                participation_value: value
            });

            if (!data) return;

            closeAddMemberModal();

            // מציגים את קישור ההצטרפות - זה מה שמאפשר להזמין
            // גם מי שעוד לא רשום במערכת
            reloadOnInviteClose = true;
            const url = new URL(data.invitation_link);
            showInviteLink(url.searchParams.get('token'), data.message);
        });
    }

    const editMemberForm = document.getElementById('editMemberForm');
    if (editMemberForm) {
        editMemberForm.addEventListener('submit', function (event) {
            event.preventDefault();

            const value = parseFloat(document.getElementById('editMemberValue').value);
            if (!(value > 0)) {
                alert('ערך ההשתתפות חייב להיות חיובי');
                return;
            }

            callAndReload('editMember', {
                member_id: document.getElementById('editMemberId').value,
                participation_type: document.querySelector('input[name="editParticipationType"]:checked').value,
                participation_value: value
            });
        });
    }

    const addPurchaseForm = document.getElementById('addPurchaseForm');
    if (addPurchaseForm) {
        addPurchaseForm.addEventListener('submit', function (event) {
            event.preventDefault();

            const amount = parseFloat(document.getElementById('purchaseAmount').value);
            if (!(amount > 0)) {
                alert('סכום הקנייה חייב להיות חיובי');
                return;
            }

            const memberId = document.getElementById('purchaseMember').value;
            if (!memberId) {
                alert('יש לבחור מי שילם');
                return;
            }

            const formData = new FormData();
            const imageInput = document.getElementById('purchaseImage');
            if (imageInput && imageInput.files[0]) {
                formData.append('image', imageInput.files[0]);
            }

            callAndReload('addPurchase', {
                member_id: memberId,
                amount: amount,
                description: document.getElementById('purchaseDescription').value,
                item_id: document.getElementById('purchaseItemId').value || 0,
                excluded_ids: getExcludedIds('add')
            }, { formData: formData });
        });
    }

    const editPurchaseForm = document.getElementById('editPurchaseForm');
    if (editPurchaseForm) {
        editPurchaseForm.addEventListener('submit', function (event) {
            event.preventDefault();

            const amount = parseFloat(document.getElementById('editPurchaseAmount').value);
            if (!(amount > 0)) {
                alert('סכום הקנייה חייב להיות חיובי');
                return;
            }

            callAndReload('updatePurchase', {
                purchase_id: document.getElementById('editPurchaseId').value,
                amount: amount,
                description: document.getElementById('editPurchaseDescription').value,
                excluded_ids: getExcludedIds('edit')
            });
        });
    }

    const itemForm = document.getElementById('itemForm');
    if (itemForm) {
        itemForm.addEventListener('submit', function (event) {
            event.preventDefault();

            const itemId = document.getElementById('itemId').value;
            const fields = {
                title: document.getElementById('itemTitle').value,
                quantity: document.getElementById('itemQuantity').value,
                notes: document.getElementById('itemNotes').value
            };

            if (itemId) {
                fields.item_id = itemId;
                callAndReload('updateItem', fields);
            } else {
                callAndReload('addItem', fields);
            }
        });
    }

    const eventForm = document.getElementById('eventForm');
    if (eventForm) {
        eventForm.addEventListener('submit', function (event) {
            event.preventDefault();

            callAndReload('updateEvent', {
                name: document.getElementById('eventName').value,
                description: document.getElementById('eventDescription').value,
                event_date: document.getElementById('eventDate').value,
                event_location: document.getElementById('eventLocation').value
            });
        });
    }
});

// ============================================================
// הגדרות חלוקה לכל הקבוצה
// ============================================================

const SPLIT_MODE_HINTS = {
    percentage:  'כל המשתתפים יעברו לאחוזים, מחולק שווה בשווה בין כולם',
    shares:      'כל המשתתפים יעברו לנפשות. מי שכבר מוגדר בנפשות ישמור על המספר שלו, והשאר יתחילו מנפש אחת',
    shares_rate: 'מצב מעורב: אפשר להחזיק חלק מהמשתתפים באחוזים וחלקם בנפשות, ולכן צריך תעריף קבוע לנפש'
};

function showSplitSettings() {
    toggleSplitMode();
    openModal('splitSettingsModal');
}

function closeSplitSettings() {
    closeModal('splitSettingsModal');
}

function toggleSplitMode() {
    const checked = document.querySelector('input[name="splitMode"]:checked');
    const mode    = checked ? checked.value : 'percentage';

    document.getElementById('splitModeHint').textContent = SPLIT_MODE_HINTS[mode] || '';

    // שדה התעריף נדרש רק במצב המעורב
    document.getElementById('shareRateGroup').style.display =
        mode === 'shares_rate' ? '' : 'none';
}

document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('splitSettingsForm');
    if (!form) return;

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        const mode = document.querySelector('input[name="splitMode"]:checked').value;
        const rate = parseFloat(document.getElementById('shareRateValue').value);

        if (mode === 'shares_rate' && !(rate > 0)) {
            alert('יש לקבוע תעריף לנפש');
            return;
        }

        const warning = mode === 'percentage'
            ? 'כל המשתתפים יעברו לאחוזים שווים. ההגדרות הנוכחיות יידרסו.'
            : (mode === 'shares'
                ? 'כל המשתתפים יעברו לחלוקה לפי נפשות. ההגדרות הנוכחיות יידרסו.'
                : 'התעריף לנפש יחול על כל מי שמוגדר בנפשות.');

        if (!confirm(warning)) return;

        callAndReload('setSplitMode', {
            mode: mode,
            share_rate: mode === 'shares_rate' ? rate : 0
        });
    });
});

/** ממלא את טופס צירוף המשתתף מתוך איש קשר שנבחר */
function fillFromContact() {
    const picker = document.getElementById('contactPicker');
    const option = picker.options[picker.selectedIndex];

    if (!picker.value) {
        return;
    }

    document.getElementById('memberEmail').value    = picker.value;
    document.getElementById('memberNickname').value = option.dataset.name || '';

    // ברירת המחדל של ההשתתפות, אם נשמרה לאיש הקשר
    const type = option.dataset.type;
    if (type) {
        const radio = document.querySelector(`input[name="participationType"][value="${type}"]`);
        if (radio) {
            radio.checked = true;
            toggleParticipationType();
        }
    }

    const value = parseFloat(option.dataset.value);
    if (value > 0) {
        document.getElementById('memberValue').value = value;
    }
}
