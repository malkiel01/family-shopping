// js/admin.js
// פלטפורמת הניהול

const CONFIG = window.APP_CONFIG;

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
        const response = await fetch('admin.php', {
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

/** בורח מתווים כדי שנתוני משתמשים לא יורכבו כ-HTML */
function esc(value) {
    const div = document.createElement('div');
    div.textContent = value === null || value === undefined ? '' : String(value);

    return div.innerHTML;
}

function participationText(type, value) {
    if (type === 'shares')     return Math.round(value) + ' נפשות';
    if (type === 'percentage') return value + '%';

    return '₪' + Number(value).toFixed(2);
}

// ============================================================
// פתיחת משתמש
// ============================================================

const loadedUsers = new Set();

async function toggleUser(userId) {
    const body    = document.getElementById('user-' + userId);
    const chevron = document.getElementById('chevron-' + userId);

    if (body.classList.contains('open')) {
        body.classList.remove('open');
        chevron.classList.remove('up');
        return;
    }

    body.classList.add('open');
    chevron.classList.add('up');

    if (loadedUsers.has(userId)) {
        return;
    }

    body.innerHTML = '<p class="admin-loading">טוען...</p>';

    const data = await callAction('userGroups', { user_id: userId });
    if (!data) {
        body.innerHTML = '<p class="admin-loading">הטעינה נכשלה</p>';
        return;
    }

    loadedUsers.add(userId);
    body.innerHTML = renderGroups(data.groups);
}

function renderGroups(groups) {
    if (!groups.length) {
        return '<p class="admin-loading">המשתמש אינו חבר באף אירוע</p>';
    }

    return groups.map(group => {
        const members = group.members.map(member => `
            <li>
                <span class="admin-member-name">${esc(member.nickname)}</span>
                <span class="admin-member-share">${esc(participationText(member.participation_type, member.participation_value))}</span>
                ${member.user_id ? '' : '<span class="contact-badge">ללא חשבון</span>'}
            </li>
        `).join('');

        const pending = group.pending.map(invite => `
            <li class="admin-pending">
                <span class="admin-member-name">${esc(invite.nickname || invite.email)}</span>
                <span class="contact-email">${esc(invite.email)}</span>
                ${invite.invitee_user_id
                    ? `<button class="btn-force" onclick="forceAccept(${Number(invite.id)})">
                           <i class="fas fa-user-check"></i> אשר בשמו
                       </button>`
                    : '<span class="contact-badge">טרם נרשם למערכת</span>'}
            </li>
        `).join('');

        return `
            <div class="admin-group">
                <div class="admin-group-head">
                    <strong>${esc(group.name)}</strong>
                    ${Number(group.is_owner) ? '<span class="contact-badge registered">מנהל</span>' : ''}
                    ${group.status === 'closed' ? '<span class="contact-badge">סגור</span>' : ''}
                </div>
                <ul class="admin-members">${members}</ul>
                ${pending ? `<div class="admin-pending-title">הזמנות ממתינות</div><ul class="admin-members">${pending}</ul>` : ''}
            </div>
        `;
    }).join('');
}

async function forceAccept(invitationId) {
    if (!confirm('לאשר את ההצטרפות בשם המשתמש?\nהפעולה תירשם ביומן הניהול, והמשתמש יקבל על כך התראה.')) {
        return;
    }

    const data = await callAction('forceAccept', { invitation_id: invitationId });
    if (!data) return;

    alert(data.message);
    location.reload();
}

function filterUsers() {
    const term  = document.getElementById('userSearch').value.trim().toLowerCase();
    const cards = document.querySelectorAll('.admin-user');
    let visible = 0;

    cards.forEach(card => {
        const match = card.dataset.name.includes(term) || card.dataset.email.includes(term);
        card.style.display = match ? '' : 'none';
        if (match) visible++;
    });

    document.getElementById('noUsers').style.display = visible === 0 ? '' : 'none';
}
