// js/contacts.js
// ניהול אנשי הקשר

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
        const response = await fetch('contacts.php', {
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
// Modal
// ============================================================

const CONTACT_TYPE_UI = {
    shares:     { label: 'כמה נפשות?', suffix: 'נפשות', step: '1',    min: '1' },
    percentage: { label: 'איזה אחוז?', suffix: '%',     step: '0.01', min: '0.01' },
    fixed:      { label: 'איזה סכום?', suffix: '₪',     step: '0.01', min: '0.01' }
};

function openContactModal() {
    document.getElementById('contactModalTitle').textContent = 'איש קשר חדש';
    document.getElementById('contactId').value = '';
    document.getElementById('contactName').value = '';
    document.getElementById('contactEmail').value = '';
    document.getElementById('contactEmail').disabled = false;
    document.getElementById('contactValue').value = '';

    document.querySelectorAll('input[name="contactType"]').forEach(input => {
        input.checked = false;
    });
    document.getElementById('contactValueGroup').style.display = 'none';

    document.getElementById('contactModal').classList.add('is-open');
    document.getElementById('contactName').focus();
}

function closeContactModal() {
    document.getElementById('contactModal').classList.remove('is-open');
}

function editContact(id, name, email, type, value) {
    document.getElementById('contactModalTitle').textContent = 'עריכת איש קשר';
    document.getElementById('contactId').value = id;
    document.getElementById('contactName').value = name;
    document.getElementById('contactEmail').value = email;

    // האימייל הוא המפתח של איש הקשר, ולכן אינו ניתן לשינוי
    document.getElementById('contactEmail').disabled = true;

    document.querySelectorAll('input[name="contactType"]').forEach(input => {
        input.checked = (input.value === type);
    });

    document.getElementById('contactValue').value = value > 0 ? value : '';
    toggleContactType();

    document.getElementById('contactModal').classList.add('is-open');
}

function toggleContactType() {
    const checked = document.querySelector('input[name="contactType"]:checked');
    const group   = document.getElementById('contactValueGroup');

    if (!checked) {
        group.style.display = 'none';
        return;
    }

    const ui    = CONTACT_TYPE_UI[checked.value];
    const input = document.getElementById('contactValue');

    group.style.display = '';
    document.getElementById('contactValueLabel').textContent  = ui.label;
    document.getElementById('contactValueSuffix').textContent = ui.suffix;
    input.step = ui.step;
    input.min  = ui.min;
}

// ============================================================
// פעולות
// ============================================================

async function removeContact(id) {
    if (!confirm('למחוק את איש הקשר?\nהפעולה לא משפיעה על אירועים קיימים.')) return;

    const data = await callAction('deleteContact', { contact_id: id });
    if (data) location.reload();
}

async function importFromHistory() {
    const data = await callAction('importContacts');
    if (!data) return;

    alert(data.message);
    if (data.added > 0) location.reload();
}

function filterContacts() {
    const term  = document.getElementById('contactSearch').value.trim().toLowerCase();
    const cards = document.querySelectorAll('.contact-card');
    let visible = 0;

    cards.forEach(card => {
        const match = card.dataset.name.includes(term)
            || card.dataset.email.toLowerCase().includes(term);

        card.style.display = match ? '' : 'none';
        if (match) visible++;
    });

    document.getElementById('noResults').style.display = visible === 0 ? '' : 'none';
}

document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('contactForm');
    if (!form) return;

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        const id      = document.getElementById('contactId').value;
        const checked = document.querySelector('input[name="contactType"]:checked');
        const fields  = {
            name:                document.getElementById('contactName').value,
            participation_type:  checked ? checked.value : '',
            participation_value: document.getElementById('contactValue').value || 0
        };

        let data;
        if (id) {
            data = await callAction('updateContact', Object.assign({ contact_id: id }, fields));
        } else {
            fields.email = document.getElementById('contactEmail').value;
            data = await callAction('saveContact', fields);
        }

        if (data) location.reload();
    });
});

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeContactModal();
});

window.onclick = function (event) {
    const modal = document.getElementById('contactModal');
    if (event.target === modal) closeContactModal();
};
