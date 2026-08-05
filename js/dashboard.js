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
    document.getElementById('createGroupModal').style.display = 'block';
    document.getElementById('groupName').focus();
}

function closeCreateGroupModal() {
    document.getElementById('createGroupModal').style.display = 'none';
    document.getElementById('createGroupForm').reset();
    toggleOwnerParticipationType();
}

function toggleOwnerParticipationType() {
    const type = document.querySelector('input[name="ownerParticipationType"]:checked').value;
    document.getElementById('ownerValueSuffix').textContent = type === 'percentage' ? '%' : '₪';
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
