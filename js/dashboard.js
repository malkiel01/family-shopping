// js/dashboard.js
// לוגיקת דף האירועים

const CONFIG = window.APP_CONFIG;

/**
 * שולח פעולה לשרת. מחזיר את גוף התשובה, או null אם נכשל.
 */
async function callAction(action, fields = {}) {
    const formData = new FormData();
    formData.append('action', action);
    formData.append('csrf_token', CONFIG.csrfToken);

    for (const [key, value] of Object.entries(fields)) {
        if (value !== null && value !== undefined) {
            formData.append(key, value);
        }
    }

    try {
        const response = await fetch('dashboard.php', {
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

// ============================================================
// Modal יצירת אירוע
// ============================================================

function showCreateGroupModal() {
    document.getElementById('createGroupModal').classList.add('is-open');
    document.getElementById('groupName').focus();
}

function closeCreateGroupModal() {
    document.getElementById('createGroupModal').classList.remove('is-open');
    document.getElementById('createGroupForm').reset();
    toggleOwnerParticipationType();
}

/** מה שמשתנה בטופס לפי סוג ההשתתפות שנבחר */
const OWNER_PARTICIPATION_UI = {
    shares:     { label: 'כמה נפשות?',  suffix: 'נפשות', step: '1',    min: '1' },
    percentage: { label: 'איזה אחוז?',  suffix: '%',     step: '0.01', min: '0.01' },
    fixed:      { label: 'איזה סכום?',  suffix: '₪',     step: '0.01', min: '0.01' }
};

function toggleOwnerParticipationType() {
    const type  = document.querySelector('input[name="ownerParticipationType"]:checked').value;
    const ui    = OWNER_PARTICIPATION_UI[type] || OWNER_PARTICIPATION_UI.percentage;
    const input = document.getElementById('ownerParticipationValue');

    document.getElementById('ownerValueLabel').textContent  = ui.label;
    document.getElementById('ownerValueSuffix').textContent = ui.suffix;
    input.step = ui.step;
    input.min  = ui.min;

    // נפשות הן מספר שלם
    if (type === 'shares' && input.value) {
        input.value = Math.max(1, Math.round(parseFloat(input.value) || 1));
    }
}

window.onclick = function (event) {
    const modal = document.getElementById('createGroupModal');
    if (event.target === modal) {
        closeCreateGroupModal();
    }
};

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        closeCreateGroupModal();
    }
});

// ============================================================
// פעולות
// ============================================================

async function leaveGroup(groupId) {
    if (!confirm('לעזוב את האירוע?')) return;

    const data = await callAction('leaveGroup', { group_id: groupId });
    if (data) location.reload();
}

async function respondInvitation(invitationId, response) {
    const data = await callAction('respondInvitation', {
        invitation_id: invitationId,
        response: response
    });

    if (!data) return;

    // אישור הזמנה מוביל ישר לתוך האירוע
    if (response === 'accept' && data.group_id) {
        window.location.href = 'group.php?id=' + data.group_id;
    } else {
        location.reload();
    }
}

// ============================================================
// התראות
// ============================================================

const NOTIFICATION_POLL_MS = 30000;

async function checkServerNotifications() {
    if (typeof showNotificationUniversal !== 'function') return;

    try {
        const response = await fetch(CONFIG.basePath + '/api/simple-notifications.php?action=get-pending', {
            headers: { 'X-CSRF-Token': CONFIG.csrfToken }
        });
        const data = await response.json();

        if (!data.success || !Array.isArray(data.notifications)) return;

        for (const notification of data.notifications) {
            await showNotificationUniversal(notification.title || 'התראה', {
                body: notification.body || '',
                icon: notification.icon || CONFIG.basePath + '/images/icons/android/android-launchericon-192-192.png',
                badge: CONFIG.basePath + '/images/icons/android/android-launchericon-96-96.png',
                vibrate: [200, 100, 200],
                tag: 'notif-' + (notification.id || Date.now())
            });
        }
    } catch (error) {
        console.error('Error checking notifications:', error);
    }
}

function initNotifications() {
    if ('Notification' in window && Notification.permission === 'default') {
        setTimeout(() => {
            Notification.requestPermission().then(permission => {
                if (permission === 'granted' && typeof showNotificationUniversal === 'function') {
                    showNotificationUniversal('ברוך הבא! 👋', {
                        body: 'התראות הופעלו בהצלחה',
                        icon: CONFIG.basePath + '/images/icons/android/android-launchericon-192-192.png'
                    });
                }
            });
        }, 3000);
    }

    setTimeout(checkServerNotifications, 2000);
    setInterval(checkServerNotifications, NOTIFICATION_POLL_MS);
}

window.addEventListener('load', initNotifications);

// ============================================================
// טופס יצירת אירוע
// ============================================================

document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('createGroupForm');
    if (!form) return;

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        const type = document.querySelector('input[name="ownerParticipationType"]:checked').value;
        const value = parseFloat(document.getElementById('ownerParticipationValue').value);

        if (!(value > 0)) {
            alert('ערך ההשתתפות חייב להיות חיובי');
            return;
        }
        if (type === 'percentage' && value > 100) {
            alert('לא ניתן להגדיר יותר מ-100% השתתפות');
            return;
        }

        const dateInput = document.getElementById('groupEventDate');
        const locationInput = document.getElementById('groupEventLocation');

        const data = await callAction('createGroup', {
            name: document.getElementById('groupName').value,
            description: document.getElementById('groupDescription').value,
            event_date: dateInput ? dateInput.value : '',
            event_location: locationInput ? locationInput.value : '',
            participation_type: type,
            participation_value: value
        });

        if (data) {
            window.location.href = 'group.php?id=' + data.group_id;
        }
    });
});

// ============================================================
// מחיקת אירוע
// ============================================================

async function deleteGroup(groupId, groupName) {
    const message = 'למחוק את האירוע "' + groupName + '"?\n\n'
        + 'האירוע ייעלם מכל המשתתפים, אבל הנתונים יישמרו ותוכל לשחזר אותו.';

    if (!confirm(message)) return;

    const data = await callAction('deleteGroup', { group_id: groupId });
    if (data) location.reload();
}

async function restoreGroup(groupId) {
    const data = await callAction('restoreGroup', { group_id: groupId });
    if (data) location.reload();
}

async function purgeGroup(groupId, groupName) {
    // מחיקה בלתי הפיכה, ולכן דורשת הקלדה מדויקת של השם ולא
    // רק לחיצה על "אישור" - כדי שלא תקרה בהיסח הדעת
    const typed = prompt(
        'מחיקה לצמיתות של "' + groupName + '".\n\n'
        + 'כל המשתתפים יוסרו, וכל הקניות, ההחרגות, הרשימה, ההתחשבנויות\n'
        + 'ותמונות הקבלות יימחקו. אין דרך לשחזר.\n\n'
        + 'להמשך, הקלד את שם האירוע במדויק:'
    );

    if (typed === null) return;

    if (typed.trim() !== groupName.trim()) {
        alert('השם שהוקלד אינו תואם. המחיקה בוטלה.');
        return;
    }

    const data = await callAction('purgeGroup', {
        group_id: groupId,
        confirm_name: typed
    });

    if (data) {
        alert(data.message);
        location.reload();
    }
}
