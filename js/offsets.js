// js/offsets.js
// קיזוזים בין אירועים

const CONFIG = window.APP_CONFIG;

/** המועמד שנפתח כרגע במודל */
let activeOffset = null;

async function callAction(action, fields = {}) {
    const formData = new FormData();
    formData.append('action', action);
    formData.append('csrf_token', CONFIG.csrfToken);

    for (const [key, value] of Object.entries(fields)) {
        formData.append(key, value);
    }

    try {
        const response = await fetch('offsets.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        });

        return await response.json();
    } catch (error) {
        console.error('Action failed:', action, error);

        return { success: false, message: 'שגיאת תקשורת. נסה שוב' };
    }
}

function formatAmount(value) {
    return CONFIG.currency + Number(value).toFixed(2);
}

function openOffsetModal(candidate) {
    activeOffset = candidate;

    document.getElementById('offsetLabel').textContent = candidate.label;
    document.getElementById('offsetMax').textContent   = formatAmount(candidate.max);
    document.getElementById('offsetAmount').value      = Number(candidate.max).toFixed(2);

    document.getElementById('offsetModal').classList.add('is-open');
}

function closeOffsetModal() {
    document.getElementById('offsetModal').classList.remove('is-open');
    activeOffset = null;
}

function offsetFillFull() {
    if (!activeOffset) return;

    document.getElementById('offsetAmount').value = Number(activeOffset.max).toFixed(2);
}

document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('offsetForm');
    if (!form) return;

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (!activeOffset) return;

        const amount = parseFloat(document.getElementById('offsetAmount').value);

        if (!(amount > 0)) {
            alert('הסכום חייב להיות חיובי');
            return;
        }

        // השרת מאמת את זה שוב מול החישוב העדכני; כאן זה רק כדי
        // לחסוך הלוך-חזור על טעות מובנת מאליה
        if (amount > Number(activeOffset.max) + 0.01) {
            alert('אי אפשר לקזז יותר מ' + formatAmount(activeOffset.max));
            return;
        }

        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;

        const data = await callAction('applyOffset', {
            group_a:    activeOffset.group_a,
            group_b:    activeOffset.group_b,
            identity_a: activeOffset.identity_a,
            identity_b: activeOffset.identity_b,
            amount:     amount
        });

        button.disabled = false;

        alert(data.message);

        if (data.success) {
            location.reload();
        }
    });
});

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeOffsetModal();
});

window.onclick = function (event) {
    if (event.target === document.getElementById('offsetModal')) {
        closeOffsetModal();
    }
};
