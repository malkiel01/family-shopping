// service-worker.js - גרסה משולבת שעובדת!
const CACHE_NAME = 'panan-bakan-v1.2.0';

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

// === POLLING SETTINGS ===
let pollInterval = null;
let isPollingActive = false;
let lastCheckTime = 0;

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
      // התחל Polling מיד כשה-Service Worker מופעל
      startSmartPolling();
      return self.clients.claim();
    })
  );
});

// === SMART POLLING - בודק בתדירות משתנה ===
function startSmartPolling() {
  if (isPollingActive) return;
  
  console.log('Starting smart polling...');
  isPollingActive = true;
  
  // בדוק מיד
  checkForNotifications();
  
  // בדוק כל 5 שניות למשך הדקה הראשונה
  pollInterval = setInterval(() => {
    checkForNotifications();
  }, 5000);
  
  // אחרי דקה, האט ל-30 שניות
  setTimeout(() => {
    if (pollInterval) {
      clearInterval(pollInterval);
      pollInterval = setInterval(() => {
        checkForNotifications();
      }, 30000); // כל 30 שניות
    }
  }, 60000);
  
  // אחרי 5 דקות, האט לדקה
  setTimeout(() => {
    if (pollInterval) {
      clearInterval(pollInterval);
      pollInterval = setInterval(() => {
        checkForNotifications();
      }, 60000); // כל דקה
    }
  }, 300000);
}

// בדיקת התראות
async function checkForNotifications() {
  try {
    const now = Date.now();
    
    // מנע בדיקות מרובות (מינימום 3 שניות בין בדיקות)
    if (now - lastCheckTime < 3000) {
      return;
    }
    lastCheckTime = now;
    
    // בדוק אם יש לקוחות פעילים
    const clients = await self.clients.matchAll({
      type: 'window',
      includeUncontrolled: true
    });
    
    // אם אין אף חלון פתוח, בדוק רק כל 30 שניות
    if (clients.length === 0) {
      console.log('No active clients, reducing polling frequency');
      return;
    }
    
    // שלח בקשה לשרת
    const response = await fetch('/family/api/send-push-notification.php?action=get-pending', {
      method: 'GET',
      credentials: 'include',
      headers: {
        'Accept': 'application/json',
        'X-Service-Worker': 'true' // סמן שזו בקשה מ-Service Worker
      }
    });
    
    if (!response.ok) {
      console.error('Failed to fetch notifications:', response.status);
      return;
    }
    
    const data = await response.json();
    
    if (data.success && data.notifications && data.notifications.length > 0) {
      console.log(`Found ${data.notifications.length} pending notifications`);
      
      // הצג כל התראה
      for (const notification of data.notifications) {
        await showNotification(notification);
        
        // המתן קצת בין התראות
        await new Promise(resolve => setTimeout(resolve, 500));
      }
      
      // אם היו התראות, בדוק שוב בקרוב
      setTimeout(() => checkForNotifications(), 3000);
    }
    
  } catch (error) {
    console.error('Error checking notifications:', error);
  }
}

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
      requireInteraction: data.type === 'group_invitation', // השאר הזמנות פתוחות
      dir: 'rtl',
      lang: 'he',
      timestamp: Date.now(),
      silent: false
    };
    
    // הוסף כפתורי פעולה להזמנות
    if (data.type === 'group_invitation') {
      options.actions = [
        {
          action: 'accept',
          title: 'קבל',
          icon: '/family/images/icons/android/android-launchericon-96-96.png'
        },
        {
          action: 'view',
          title: 'צפה',
          icon: '/family/images/icons/android/android-launchericon-96-96.png'
        }
      ];
    }
    
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
  
  // טיפול בפעולות
  if (event.action === 'accept' && data.type === 'group_invitation') {
    url = `/family/dashboard.php?action=accept_invitation&id=${data.id}`;
  } else if (event.action === 'view') {
    url = data.url || '/family/dashboard.php#invitations';
  }
  
  // פתח או פוקס על החלון
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true })
      .then(windowClients => {
        // חפש חלון קיים
        for (let client of windowClients) {
          if (client.url.includes('/family/')) {
            return client.focus().then(() => {
              client.navigate(url);
              // הפעל מחדש את ה-polling
              startSmartPolling();
            });
          }
        }
        // פתח חלון חדש
        if (clients.openWindow) {
          return clients.openWindow(url).then(() => {
            // הפעל מחדש את ה-polling
            startSmartPolling();
          });
        }
      })
  );
});

// הקשבה להודעות מהאפליקציה
self.addEventListener('message', event => {
  console.log('Service Worker received message:', event.data);
  
  if (event.data.type === 'START_POLLING') {
    startSmartPolling();
  } else if (event.data.type === 'CHECK_NOW') {
    checkForNotifications();
  } else if (event.data.type === 'USER_ACTIVE') {
    // המשתמש פעיל - הגבר תדירות
    if (pollInterval) {
      clearInterval(pollInterval);
      pollInterval = setInterval(() => checkForNotifications(), 5000);
    }
  } else if (event.data.type === 'USER_IDLE') {
    // המשתמש לא פעיל - הפחת תדירות
    if (pollInterval) {
      clearInterval(pollInterval);
      pollInterval = setInterval(() => checkForNotifications(), 30000);
    }
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

// === Background Sync - אם הדפדפן תומך ===
self.addEventListener('sync', event => {
  console.log('Background sync triggered:', event.tag);
  if (event.tag === 'check-notifications') {
    event.waitUntil(checkForNotifications());
  }
});

// === Visibility Change - כשהמשתמש חוזר לאפליקציה ===
self.addEventListener('visibilitychange', () => {
  if (!document.hidden) {
    console.log('App became visible, checking notifications...');
    checkForNotifications();
    startSmartPolling();
  }
});

console.log('🚀 Service Worker loaded with Smart Polling!');