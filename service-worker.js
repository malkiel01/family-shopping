// service-worker.js - גרסה משולבת שעובדת!
const CACHE_NAME = 'panan-bakan-v1.3.0';

// רק קבצים סטטיים שמוחזרים ישירות עם 200.
//
// חשוב: אסור לרשום כאן דפי PHP. הם מחזירים 302 למי שלא מחובר,
// ו-cache.addAll נכשל על תגובת הפניה - מה שמפיל את כל ההתקנה
// ומשאיר את הגרסה הקודמת של ה-Service Worker בשליטה לנצח.
//
// CSS ו-JS גם הם לא כאן: הם נטענים עם חותמת גרסה (?v=...), ולכן
// נשמרים ב-cache בפעם הראשונה שמבקשים אותם ומתחלפים מאליהם.
const urlsToCache = [
  '/family/offline.html',
  '/family/images/icons/android/android-launchericon-192-192.png',
  '/family/images/icons/android/android-launchericon-512-512.png'
];

// התקנה
self.addEventListener('install', event => {
  console.log('Service Worker: Installing...');
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        console.log('Service Worker: Caching files');
        // כל קובץ בנפרד: קובץ אחד שנכשל לא מפיל את ההתקנה כולה
        return Promise.allSettled(
          urlsToCache.map(url => cache.add(url).catch(err => {
            console.warn('Service Worker: skipping', url, err);
          }))
        );
      })
      .then(() => self.skipWaiting())
  );
});

// הפעלה
self.addEventListener('activate', event => {
  console.log('Service Worker: Activated');
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          if (cacheName !== CACHE_NAME) {
            console.log('Service Worker: Clearing old cache');
            return caches.delete(cacheName);
          }
        })
      );
    }).then(() => {
      return self.clients.claim();
    })
  );
});

// === התראה שנדחפה מהשרת ===
//
// זה המסלול היחיד שמגיע למשתמש כשהאפליקציה סגורה. עד עכשיו לא היה
// כאן מאזין 'push' בכלל, ולכן גם התראה תקינה שהייתה מגיעה מהשרת
// פשוט לא הייתה מוצגת.
self.addEventListener('push', event => {
  let payload = {};

  if (event.data) {
    try {
      payload = event.data.json();
    } catch (error) {
      payload = { body: event.data.text() };
    }
  }

  // הדפדפן מחייב להציג התראה על כל push, אחרת הוא שולל את ההרשאה
  event.waitUntil(showNotification(payload));
});

// הצגת התראה
async function showNotification(data) {
  try {
    const title = data.title || 'התראה חדשה';
    const options = {
      body: data.body || '',
      icon: data.icon || '/family/images/icons/android/android-launchericon-192-192.png',
      badge: data.badge || '/family/images/icons/android/android-launchericon-96-96.png',
      tag: data.tag || `notification-${Date.now()}`,
      data: {
        url: data.url || '/family/dashboard.php',
        type: data.type,
        id: data.id,
        ...data.data
      },
      vibrate: [200, 100, 200],
      requireInteraction: data.type === 'invitation', // השאר הזמנות פתוחות
      dir: 'rtl',
      lang: 'he',
      timestamp: Date.now(),
      silent: false
    };
    
    await self.registration.showNotification(title, options);
    console.log('✅ Notification shown:', title);
    
  } catch (error) {
    console.error('❌ Error showing notification:', error);
  }
}

// לחיצה על התראה
self.addEventListener('notificationclick', event => {
  console.log('Notification clicked:', event.notification.tag);
  
  event.notification.close();
  
  const data = event.notification.data;
  let url = data.url || '/family/dashboard.php';
  
  // פתח או פוקס על החלון
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true })
      .then(windowClients => {
        // חפש חלון קיים
        for (let client of windowClients) {
          if (client.url.includes('/family/')) {
            return client.focus().then(() => client.navigate(url));
          }
        }
        // פתח חלון חדש
        if (clients.openWindow) {
          return clients.openWindow(url);
        }
      })
  );
});

// הקשבה להודעות מהאפליקציה
self.addEventListener('message', event => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

// Cache strategy
self.addEventListener('fetch', event => {
  // אל תעשה cache לבקשות API
  if (event.request.url.includes('/api/') || 
      event.request.url.includes('action=') ||
      event.request.url.includes('.php')) {
    return; // תן לבקשה לעבור ישירות
  }
  
  // Cache רק לקבצים סטטיים.
  //
  // האסטרטגיה היא stale-while-revalidate: מגישים מיד מה-cache כדי
  // שהאתר יעלה מהר, ובמקביל מרעננים מהרשת לפעם הבאה. cache-first
  // בלי רענון היה מקפיא קבצים ישנים אצל המשתמש גם אחרי עדכון בשרת.
  if (event.request.method === 'GET') {
    event.respondWith(
      caches.match(event.request).then(cached => {
        const network = fetch(event.request).then(response => {
          // אין טעם לשמור תשובות חלקיות, הפניות או תשובות אטומות
          if (response && response.status === 200 && response.type === 'basic' && !response.redirected) {
            const copy = response.clone();
            caches.open(CACHE_NAME).then(cache => cache.put(event.request, copy));
          }
          return response;
        });

        // יש עותק שמור - מחזירים אותו מיד, והרענון ממשיך ברקע
        if (cached) {
          network.catch(() => {});
          return cached;
        }

        return network.catch(() => {
          if ((event.request.headers.get('accept') || '').includes('text/html')) {
            return caches.match('/family/offline.html');
          }
          return Response.error();
        });
      })
    );
  }
});

console.log('Service Worker loaded');
