/**
 * הרשמה להתראות Push
 * js/push-subscribe.js
 *
 * זה החלק שהיה חסר לגמרי: בלי pushManager.subscribe אין לשרת
 * לאן לשלוח, וכל מנגנון ההתראות נשאר תלוי באפליקציה פתוחה.
 *
 * הרשאת התראות נדרשת מתוך לחיצה של המשתמש - דפדפנים דוחים בקשה
 * שמגיעה מעצמה בטעינת הדף, ובספארי זו דרישה מפורשת.
 */

(function () {
    'use strict';

    const CFG  = window.APP_CONFIG || {};
    const BASE = CFG.basePath || '';

    const supported = 'serviceWorker' in navigator
        && 'PushManager' in window
        && 'Notification' in window;

    if (!supported) {
        return;
    }

    /** ממיר מפתח base64url למערך בתים, כפי ש-pushManager מצפה */
    function keyToBytes(base64) {
        const padded = (base64 + '='.repeat((4 - base64.length % 4) % 4))
            .replace(/-/g, '+')
            .replace(/_/g, '/');
        const raw = atob(padded);
        const out = new Uint8Array(raw.length);

        for (let i = 0; i < raw.length; i++) {
            out[i] = raw.charCodeAt(i);
        }

        return out;
    }

    /** האם המנוי הקיים נוצר עם אותו מפתח שרת */
    function sameServerKey(subscription, keyBytes) {
        const current = subscription.options && subscription.options.applicationServerKey;

        if (!current) {
            return false;
        }

        const existing = new Uint8Array(current);

        if (existing.length !== keyBytes.length) {
            return false;
        }

        return existing.every((byte, i) => byte === keyBytes[i]);
    }

    async function fetchServerKey() {
        const response = await fetch(BASE + '/api/push-key.php', {
            headers: { 'X-CSRF-Token': CFG.csrfToken || '' }
        });
        const data = await response.json();

        if (!data.success) {
            throw new Error(data.message || 'המפתח לא התקבל');
        }

        return data.key;
    }

    async function saveSubscription(subscription) {
        const response = await fetch(BASE + '/api/save-push-subscription.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CFG.csrfToken || ''
            },
            body: JSON.stringify({ subscription: subscription.toJSON() })
        });
        const data = await response.json();

        if (!data.success) {
            throw new Error(data.message || 'שמירת המנוי נכשלה');
        }
    }

    /**
     * מוודא שקיים מנוי תקף ושמור בשרת.
     * אם המנוי הקיים נוצר עם מפתח שרת ישן - למשל אחרי החלפת
     * מפתחות - הוא מבוטל ונוצר חדש במקומו.
     */
    async function ensureSubscription() {
        const registration = await navigator.serviceWorker.ready;
        const keyBase64    = await fetchServerKey();
        const keyBytes     = keyToBytes(keyBase64);

        let subscription = await registration.pushManager.getSubscription();

        if (subscription && !sameServerKey(subscription, keyBytes)) {
            await subscription.unsubscribe();
            subscription = null;
        }

        if (!subscription) {
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: keyBytes
            });
        }

        await saveSubscription(subscription);

        return subscription;
    }

    // ============================================================
    // הבקשה למשתמש
    // ============================================================

    function removeBanner() {
        const existing = document.querySelector('.push-optin');

        if (existing) {
            existing.remove();
        }
    }

    function showBanner() {
        removeBanner();

        const bar = document.createElement('div');
        bar.className = 'push-optin';

        const text = document.createElement('span');
        text.className = 'push-optin-text';
        text.textContent = 'רוצה לדעת מיד על הזמנות וקניות חדשות?';

        const enable = document.createElement('button');
        enable.type = 'button';
        enable.className = 'push-optin-enable';
        enable.textContent = 'הפעל התראות';

        const dismiss = document.createElement('button');
        dismiss.type = 'button';
        dismiss.className = 'push-optin-dismiss';
        dismiss.setAttribute('aria-label', 'לא עכשיו');
        dismiss.textContent = '×';

        enable.addEventListener('click', async function () {
            enable.disabled = true;
            enable.textContent = 'מפעיל...';

            try {
                const permission = await Notification.requestPermission();

                if (permission !== 'granted') {
                    text.textContent = 'ההתראות חסומות. אפשר להפעיל אותן מהגדרות הדפדפן';
                    enable.remove();

                    return;
                }

                await ensureSubscription();
                text.textContent = 'מעולה, ההתראות פועלות';
                enable.remove();
                setTimeout(removeBanner, 2500);
            } catch (error) {
                console.error('Push subscription failed:', error);
                text.textContent = 'ההפעלה נכשלה. אפשר לנסות שוב מאוחר יותר';
                enable.disabled = false;
                enable.textContent = 'נסה שוב';
            }
        });

        dismiss.addEventListener('click', function () {
            // הבקשה לא תחזור באותו ביקור, אבל כן בביקור הבא
            sessionStorage.setItem('pushOptinDismissed', '1');
            removeBanner();
        });

        bar.append(text, enable, dismiss);
        document.body.appendChild(bar);
    }

    async function init() {
        // הרשאה שכבר ניתנה: לוודא בשקט שהמנוי קיים ומעודכן בשרת.
        // חשוב במיוחד אחרי החלפת מפתחות, שמבטלת מנויים ישנים.
        if (Notification.permission === 'granted') {
            try {
                await ensureSubscription();
            } catch (error) {
                console.error('Push refresh failed:', error);
            }

            return;
        }

        if (Notification.permission === 'denied') {
            return;
        }

        if (sessionStorage.getItem('pushOptinDismissed')) {
            return;
        }

        showBanner();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
