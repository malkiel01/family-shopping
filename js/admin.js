// js/admin.js
// פלטפורמת הניהול

const CONFIG = window.APP_CONFIG;

/**
 * @param options.keepFailure מחזיר את התשובה גם כשהיא כישלון, במקום
 *        להסתפק ב-alert. נדרש לפעולות שמחזירות יומן מפורט - שם דווקא
 *        הכישלון הוא מה שצריך לראות על המסך.
 */
async function callAction(action, fields = {}, options = {}) {
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

        if (!data.success && !options.keepFailure) {
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

/**
 * מכין מחרוזת שתעבור כארגומנט בתוך onclick של תכונת HTML.
 *
 * JSON.stringify עוטף בגרשיים כפולים, ואלה סוגרים את התכונה
 * עצמה באמצע - כך שהכפתור פשוט לא עשה כלום. הקידוד ל-&quot;
 * משאיר את הערך תקין אחרי שהדפדפן מפענח את התכונה.
 */
function jsArg(value) {
    return JSON.stringify(String(value === null || value === undefined ? '' : value))
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;');
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
    body.innerHTML = renderUserActions(userId, body.dataset) + renderGroups(data.groups);
}

/**
 * כפתורי ההרשאה וההשבתה של המשתמש.
 *
 * המנהל אינו רואה אותם על עצמו: השרת חוסם את שתי הפעולות ממילא,
 * וכפתור שתמיד מחזיר שגיאה גרוע מכפתור שאינו קיים.
 */
function renderUserActions(userId, state) {
    if (state.self === '1') {
        return `
            <div class="admin-user-actions">
                <span class="admin-self-note">
                    <i class="fas fa-user-shield"></i> זה החשבון שלך
                </span>
            </div>
        `;
    }

    const isAdmin  = (state.admin === '1');
    const isActive = (state.active === '1');

    return `
        <div class="admin-user-actions">
            <button class="btn-force ${isAdmin ? 'neutral' : ''}"
                    onclick="setUserFlag('setAdmin', ${Number(userId)}, ${isAdmin ? 0 : 1})">
                <i class="fas ${isAdmin ? 'fa-user-minus' : 'fa-user-shield'}"></i>
                ${isAdmin ? 'שלול הרשאת ניהול' : 'הפוך למנהל מערכת'}
            </button>
            <button class="btn-purge-admin ${isActive ? '' : 'soft'}"
                    onclick="setUserFlag('setActive', ${Number(userId)}, ${isActive ? 0 : 1})">
                <i class="fas ${isActive ? 'fa-ban' : 'fa-rotate-left'}"></i>
                ${isActive ? 'השבת חשבון' : 'הפעל חשבון'}
            </button>
        </div>
    `;
}

const FLAG_CONFIRM = {
    'setAdmin:1':  'להפוך את המשתמש למנהל מערכת?\n\nהוא יראה את כל המשתמשים, כל האירועים וכל החברויות.',
    'setAdmin:0':  'לשלול את הרשאת הניהול?',
    'setActive:0': 'להשבית את החשבון?\n\nהמשתמש לא יוכל להתחבר, אבל שום נתון לא יימחק וההפעלה מחזירה הכל.',
    'setActive:1': 'להפעיל מחדש את החשבון?'
};

async function setUserFlag(action, userId, value) {
    if (!confirm(FLAG_CONFIRM[action + ':' + value])) {
        return;
    }

    const data = await callAction(action, { user_id: userId, value: value });
    if (!data) return;

    alert(data.message);
    location.reload();
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
                    <button class="btn-force" onclick="openAddToGroup(${Number(group.id)}, ${jsArg(group.name)})">
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
// אזורים
// ============================================================

/**
 * מעבר בין אזורי הניהול.
 *
 * האזור הנבחר נשמר ב-sessionStorage, כי כמעט כל פעולה כאן
 * מסתיימת ב-location.reload() - ובלי השמירה כל מחיקה או צירוף
 * היו מחזירים את המנהל לתחילת המסך.
 */
function showAdminTab(name) {
    if (!CONFIG.sections.includes(name)) {
        name = CONFIG.sections[0];
    }

    CONFIG.sections.forEach(section => {
        document.getElementById('pane-' + section).hidden = (section !== name);
        document.getElementById('tab-' + section).classList.toggle('active', section === name);
    });

    try {
        sessionStorage.setItem('adminSection', name);
    } catch (error) {
        // גלישה פרטית חוסמת אחסון. אין מה לעשות, וזה לא שובר כלום
    }
}

document.addEventListener('DOMContentLoaded', function () {
    let saved = null;

    try {
        saved = sessionStorage.getItem('adminSection');
    } catch (error) {
        saved = null;
    }

    if (saved) {
        showAdminTab(saved);
    }
});

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

    // השם והמצב מגיעים מהשרת. הגרסה הקודמת חילצה אותם מטקסט
    // הכותרת שבמסך, אבל הכותרת מכילה גם תגיות כמו "מושבת" -
    // ולכן השם שנשלח לאישור המחיקה מעולם לא תאם למה שהוקלד.
    const name      = data.name;
    const isDeleted = (data.is_active === 0);

    // פעולות ההרס מופרדות משאר הפעולות, כדי שלא ייראו כמו
    // עוד כפתור באותה שורה
    const danger = isDeleted
        ? `<button class="btn-restore-admin" onclick="adminGroupAction(${Number(groupId)}, 'restore', ${jsArg(name)})">
               <i class="fas fa-rotate-left"></i> שחזר
           </button>
           <button class="btn-purge-admin" onclick="adminGroupAction(${Number(groupId)}, 'purge', ${jsArg(name)})">
               <i class="fas fa-trash"></i> מחק לצמיתות
           </button>`
        : `<button class="btn-purge-admin soft" onclick="adminGroupAction(${Number(groupId)}, 'soft', ${jsArg(name)})">
               <i class="fas fa-trash-can"></i> מחק אירוע
           </button>`;

    return `
        <div class="admin-group">
            <div class="admin-group-head">
                <strong>משתתפים</strong>
                <button class="btn-force" onclick="openAddToGroup(${Number(groupId)}, ${jsArg(name)})">
                    <i class="fas fa-user-plus"></i> צרף משתתף
                </button>
            </div>
            <ul class="admin-members">${members || '<li>אין משתתפים</li>'}</ul>
            ${pending ? `<div class="admin-pending-title">הזמנות ממתינות</div><ul class="admin-members">${pending}</ul>` : ''}
            <div class="admin-danger-zone">${danger}</div>
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

// ============================================================
// הזמנות
// ============================================================

let inviteFilter = 'pending';

function setInviteFilter(value) {
    inviteFilter = value;

    document.querySelectorAll('#inviteFilters .admin-chip').forEach(chip => {
        chip.classList.toggle('active', chip.dataset.filter === value);
    });

    filterInvitations();
}

function filterInvitations() {
    const term  = document.getElementById('inviteSearch').value.trim().toLowerCase();
    const cards = document.querySelectorAll('#invitationsList .admin-invite');
    let visible = 0;

    cards.forEach(card => {
        const matchesFilter =
            inviteFilter === 'all'   ? true :
            inviteFilter === 'stale' ? card.dataset.stale === '1'
                                     : card.dataset.status === inviteFilter;

        const matchesTerm = term === '' || card.dataset.search.includes(term);
        const show = matchesFilter && matchesTerm;

        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    document.getElementById('noInvitations').style.display = visible === 0 ? '' : 'none';
}

/**
 * מעתיק את קישור ההצטרפות.
 *
 * clipboard.writeText זמין רק בהקשר מאובטח, ובלעדיו לא קורה כלום
 * והמנהל לא מבין למה. במקרה כזה הקישור מוצג ב-prompt כדי שאפשר
 * יהיה לסמן ולהעתיק ידנית.
 */
async function copyInviteLink(button) {
    const link = button.dataset.link;

    try {
        await navigator.clipboard.writeText(link);
    } catch (error) {
        prompt('העתק את הקישור:', link);
        return;
    }

    const original = button.innerHTML;
    button.innerHTML = '<i class="fas fa-check"></i> הועתק';

    setTimeout(() => { button.innerHTML = original; }, 1500);
}

async function resendInvitation(invitationId) {
    if (!confirm('לשלוח מחדש את מייל ההזמנה?')) {
        return;
    }

    const data = await callAction('resendInvitation', { invitation_id: invitationId });
    if (!data) return;

    alert(data.message);
}

async function cancelInvitation(invitationId) {
    if (!confirm('לבטל את ההזמנה?\n\nקישור ההצטרפות יפסיק לעבוד. אפשר תמיד להזמין מחדש.')) {
        return;
    }

    const data = await callAction('cancelInvitation', { invitation_id: invitationId });
    if (!data) return;

    alert(data.message);
    location.reload();
}

async function cancelStaleInvitations() {
    if (!confirm('לבטל את כל ההזמנות שממתינות מעל 30 יום?\n\nהקישורים שלהן יפסיקו לעבוד.')) {
        return;
    }

    const data = await callAction('cancelStaleInvitations', { days: 30 });
    if (!data) return;

    alert(data.message);
    location.reload();
}

// ============================================================
// תחזוקה ופיתוח
// ============================================================

const MIGRATION_STATES = {
    migration: { css: 'head',    icon: '' },
    applied:   { css: 'applied', icon: 'fa-check' },
    skipped:   { css: 'skipped', icon: 'fa-forward' },
    failed:    { css: 'failed',  icon: 'fa-xmark' }
};

/** מושך את הקוד מגיטהאב ומריץ מיגרציות, בלי טרמינל */
async function runDeploy() {
    const button = document.getElementById('deployBtn');
    const status = document.getElementById('deployStatus');
    const output = document.getElementById('deployOutput');

    if (!confirm('למשוך את הקוד העדכני מגיטהאב?\n\nמשיכה שדורשת מיזוג תידחה ותדווח, ולא תדרוס כלום.')) {
        return;
    }

    button.disabled = true;
    status.textContent = 'מושך...';
    output.innerHTML = '';

    const data = await callAction('deploy', {}, { keepFailure: true });

    button.disabled = false;

    if (!data) {
        status.textContent = 'שגיאת תקשורת';
        return;
    }

    status.textContent = data.message;
    output.innerHTML = renderMigrationLog(data.log || []);
}

async function runMigrations() {
    const button = document.getElementById('runMigrationsBtn');
    const status = document.getElementById('migrationsStatus');
    const output = document.getElementById('migrationsOutput');

    if (!confirm('להריץ את המיגרציות הממתינות?\n\nכל צעד נבדק לפני שהוא מורץ, וצעד שכבר בוצע ידולג.')) {
        return;
    }

    button.disabled = true;
    status.textContent = 'רץ...';
    output.innerHTML = '';

    const data = await callAction('runMigrations');

    button.disabled = false;

    if (!data) {
        status.textContent = 'ההרצה נכשלה';
        return;
    }

    status.textContent = data.message;
    output.innerHTML = renderMigrationLog(data.log);
}

function renderMigrationLog(log) {
    const rows = log.map(entry => {
        const state = MIGRATION_STATES[entry.state] || MIGRATION_STATES.skipped;

        if (entry.state === 'migration') {
            return `<li class="head">${esc(entry.label)}</li>`;
        }

        return `
            <li class="${state.css}">
                <i class="fas ${state.icon}"></i>
                ${esc(entry.label)}
                ${entry.note ? `<span class="admin-log-note">${esc(entry.note)}</span>` : ''}
            </li>
        `;
    }).join('');

    return `
        <ul class="admin-run-log">${rows}</ul>
        <p class="admin-note">רענן את הדף כדי לראות את המצב המעודכן.</p>
    `;
}

/**
 * מריץ פעולת ניקוי.
 *
 * שם הפעולה ונוסח האישור מגיעים מתכונות הכפתור, ולא מרשימה
 * שכפולה כאן: הרשימה מוגדרת פעם אחת ב-maintenanceTasks() בשרת,
 * שהוא גם מי שאוכף אותה.
 */
async function runMaintenance(button) {
    const task    = button.dataset.task;
    const warning = button.dataset.confirm;

    if (warning && !confirm(warning)) {
        return;
    }

    const original = button.textContent;
    button.disabled = true;
    button.textContent = 'רץ...';

    const data = await callAction('maintenance', { task: task });

    button.disabled = false;
    button.textContent = original;

    if (!data) return;

    alert(data.message);
}

/**
 * מחיקה, שחזור ומחיקה סופית של אירוע על ידי מנהל המערכת.
 * המחיקה הסופית דורשת הקלדת שם, בדיוק כמו אצל בעל האירוע.
 */
async function adminGroupAction(groupId, mode, groupName) {
    let confirmName = '';

    if (mode === 'soft') {
        if (!confirm('למחוק את האירוע "' + groupName + '"?\n\n'
            + 'הוא ייעלם מכל המשתתפים, אבל הנתונים יישמרו וניתן יהיה לשחזר.')) {
            return;
        }
    } else if (mode === 'purge') {
        const typed = prompt(
            'מחיקה לצמיתות של "' + groupName + '".\n\n'
            + 'כל המשתתפים יוסרו, וכל הקניות, ההחרגות, הרשימה, ההתחשבנויות\n'
            + 'ותמונות הקבלות יימחקו. אין דרך לשחזר.\n\n'
            + 'זהו אירוע של משתמש אחר. להמשך, הקלד את שם האירוע במדויק:'
        );

        if (typed === null) return;

        if (typed.trim() !== groupName.trim()) {
            alert('השם שהוקלד אינו תואם. המחיקה בוטלה.');
            return;
        }

        confirmName = typed;
    }

    const data = await callAction('deleteGroup', {
        group_id:     groupId,
        mode:         mode,
        confirm_name: confirmName
    });

    if (!data) return;

    alert(data.message);
    location.reload();
}
