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
                    ${Number(group.my_membership) === 0 ? '<span class="contact-badge">חברות מושבתת</span>' : ''}
                    <button class="btn-force" onclick="openAddToGroup(${Number(group.id)}, ${JSON.stringify(group.name)})">
                        <i class="fas fa-user-plus"></i> צרף
                    </button>
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
    // מוגבל לרשימת המשתמשים: מאז שנוספה לשונית האירועים,
    // גם כרטיסי האירועים נושאים את המחלקה .admin-user
    const cards = document.querySelectorAll('#usersList .admin-user');
    let visible = 0;

    cards.forEach(card => {
        const match = card.dataset.name.includes(term) || card.dataset.email.includes(term);
        card.style.display = match ? '' : 'none';
        if (match) visible++;
    });

    document.getElementById('noUsers').style.display = visible === 0 ? '' : 'none';
}

// ============================================================
// לשוניות
// ============================================================

function showAdminTab(name) {
    const isUsers = (name === 'users');

    document.getElementById('pane-users').style.display  = isUsers ? '' : 'none';
    document.getElementById('pane-groups').style.display = isUsers ? 'none' : '';
    document.getElementById('tab-users').classList.toggle('active', isUsers);
    document.getElementById('tab-groups').classList.toggle('active', !isUsers);
}

// ============================================================
// תצוגת האירועים
// ============================================================

const loadedGroups = new Set();

async function toggleGroup(groupId) {
    const body    = document.getElementById('group-' + groupId);
    const chevron = document.getElementById('gchevron-' + groupId);

    if (body.classList.contains('open')) {
        body.classList.remove('open');
        chevron.classList.remove('up');
        return;
    }

    body.classList.add('open');
    chevron.classList.add('up');

    if (loadedGroups.has(groupId)) {
        return;
    }

    body.innerHTML = '<p class="admin-loading">טוען...</p>';

    const data = await callAction('groupDetail', { group_id: groupId });
    if (!data) {
        body.innerHTML = '<p class="admin-loading">הטעינה נכשלה</p>';
        return;
    }

    loadedGroups.add(groupId);
    groupCandidates.set(groupId, data.candidates || []);
    body.innerHTML = renderGroupDetail(groupId, data);
}

/** מועמדים לצירוף לכל אירוע שנטען, לשימוש המודל */
const groupCandidates = new Map();

function renderGroupDetail(groupId, data) {
    const members = data.members.map(member => `
        <li>
            <span class="admin-member-name">${esc(member.nickname)}</span>
            <span class="admin-member-share">${esc(participationText(member.participation_type, member.participation_value))}</span>
            ${member.user_id ? '' : '<span class="contact-badge">ללא חשבון</span>'}
        </li>
    `).join('');

    const pending = data.pending.map(invite => `
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

    const name = document.querySelector('#group-' + groupId)
        .closest('.admin-user').querySelector('h3').textContent.trim();

    return `
        <div class="admin-group">
            <div class="admin-group-head">
                <strong>משתתפים</strong>
                <button class="btn-force" onclick="openAddToGroup(${Number(groupId)}, ${JSON.stringify(name)})">
                    <i class="fas fa-user-plus"></i> צרף משתתף
                </button>
            </div>
            <ul class="admin-members">${members || '<li>אין משתתפים</li>'}</ul>
            ${pending ? `<div class="admin-pending-title">הזמנות ממתינות</div><ul class="admin-members">${pending}</ul>` : ''}
        </div>
    `;
}

function filterGroups() {
    const term  = document.getElementById('groupSearch').value.trim().toLowerCase();
    const cards = document.querySelectorAll('#groupsList .admin-user');
    let visible = 0;

    cards.forEach(card => {
        const match = card.dataset.name.includes(term) || card.dataset.owner.includes(term);
        card.style.display = match ? '' : 'none';
        if (match) visible++;
    });

    document.getElementById('noGroups').style.display = visible === 0 ? '' : 'none';
}

// ============================================================
// צירוף משתמש לאירוע
// ============================================================

const ADD_TYPE_UI = {
    shares:     { label: 'כמה נפשות?', suffix: 'נפשות', step: '1',    min: '1',    value: '1' },
    percentage: { label: 'איזה אחוז?', suffix: '%',     step: '0.01', min: '0.01', value: '' },
    fixed:      { label: 'איזה סכום?', suffix: '₪',     step: '0.01', min: '0.01', value: '' }
};

async function openAddToGroup(groupId, groupName) {
    document.getElementById('addGroupId').value      = groupId;
    document.getElementById('addGroupName').textContent = groupName;

    let candidates = groupCandidates.get(groupId);

    // אם האירוע נפתח מתוך תצוגת המשתמש, הרשימה עוד לא נטענה
    if (!candidates) {
        const data = await callAction('groupDetail', { group_id: groupId });
        if (!data) return;

        candidates = data.candidates || [];
        groupCandidates.set(groupId, candidates);
    }

    const select = document.getElementById('addUserSelect');
    select.innerHTML = '';

    if (!candidates.length) {
        alert('כל המשתמשים הרשומים כבר חברים באירוע הזה');
        return;
    }

    candidates.forEach(user => {
        const option = document.createElement('option');
        option.value = user.id;
        option.textContent = user.name + ' — ' + user.email;
        select.appendChild(option);
    });

    document.querySelector('input[name="addType"][value="shares"]').checked = true;
    toggleAddType();

    document.getElementById('addToGroupModal').classList.add('is-open');
}

function closeAddToGroup() {
    document.getElementById('addToGroupModal').classList.remove('is-open');
}

function toggleAddType() {
    const type  = document.querySelector('input[name="addType"]:checked').value;
    const ui    = ADD_TYPE_UI[type];
    const input = document.getElementById('addValue');

    document.getElementById('addValueLabel').textContent  = ui.label;
    document.getElementById('addValueSuffix').textContent = ui.suffix;
    input.step  = ui.step;
    input.min   = ui.min;
    input.value = ui.value;
}

document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('addToGroupForm');
    if (!form) return;

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        const data = await callAction('addToGroup', {
            group_id:            document.getElementById('addGroupId').value,
            target_user_id:      document.getElementById('addUserSelect').value,
            participation_type:  document.querySelector('input[name="addType"]:checked').value,
            participation_value: document.getElementById('addValue').value
        });

        if (!data) return;

        alert(data.message);
        location.reload();
    });
});

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeAddToGroup();
});

window.onclick = function (event) {
    const modal = document.getElementById('addToGroupModal');
    if (event.target === modal) closeAddToGroup();
};
