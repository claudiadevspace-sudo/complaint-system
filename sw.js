/*
 * Service worker — OSWD Student Complaint System (Progressive Web App).
 *
 * - App shell (styles, scripts, fonts, icons, offline page): cached, so the
 *   app opens quickly and still looks right without a connection.
 * - Pages: always loaded from the network first. A copy of the pages a
 *   student reads (dashboard, my complaints, a complaint, notifications,
 *   profile) is kept so they can still be opened OFFLINE, read-only.
 *   With no connection and no saved copy, offline.html is shown.
 * - Nothing is ever sent while offline (forms need a connection), and saved
 *   pages are deleted when the user logs out or a sign-in page is opened, so
 *   the next person using the device cannot see them.
 */
const VERSION = 'oswd-v3';
const SHELL = VERSION + '-shell';
const PAGES = VERSION + '-pages';

const SHELL_FILES = [
    'offline.html',
    'manifest.webmanifest',
    'assets/images/logo-mark.png',
    'assets/images/logo-mark-dark.png',
    'assets/images/favicon.png',
    'assets/images/app-icon-192.png',
    'assets/images/app-icon-512.png',
];

// Pages kept for offline reading (path without the site folder).
const OFFLINE_PAGES = ['dashboard.php', 'my_complaints.php', 'view_complaint.php', 'notifications.php', 'profile.php'];
// Opening one of these means someone signed out or is about to sign in.
const CLEAR_PAGES = ['logout.php', 'login.php', 'index.php'];

self.addEventListener('install', event => {
    event.waitUntil(caches.open(SHELL).then(cache => cache.addAll(SHELL_FILES)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(keys.filter(k => !k.startsWith(VERSION)).map(k => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

function pageName(url) {
    return url.pathname.split('/').pop() || 'index.php';
}

self.addEventListener('fetch', event => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;   // forms and uploads always go to the server
    }
    const url = new URL(request.url);

    // Pages
    if (request.mode === 'navigate') {
        const name = pageName(url);
        if (CLEAR_PAGES.includes(name)) {
            event.waitUntil(caches.delete(PAGES));
        }
        event.respondWith(
            fetch(request)
                .then(response => {
                    // Keep a copy of student pages that loaded normally (not a redirect to sign-in).
                    if (response.ok && !response.redirected && OFFLINE_PAGES.includes(name)) {
                        const copy = response.clone();
                        caches.open(PAGES).then(cache => cache.put(request, copy));
                    }
                    return response;
                })
                .catch(() => caches.match(request, { cacheName: PAGES })
                    .then(saved => saved || caches.match('offline.html', { cacheName: SHELL })))
        );
        return;
    }

    // Live updates, evidence files and downloads: network only.
    if (url.origin === self.location.origin && /\/(live_status|file)\.php$/.test(url.pathname)) {
        return;
    }

    // Styles, scripts, fonts, icons (this site and the jsDelivr CDN): cache first.
    const isAsset = (url.origin === self.location.origin && url.pathname.includes('/assets/'))
        || url.hostname === 'cdn.jsdelivr.net';
    if (isAsset) {
        event.respondWith(
            caches.match(request).then(cached => cached || fetch(request).then(response => {
                if (response.ok || response.type === 'opaque') {
                    const copy = response.clone();
                    caches.open(SHELL).then(cache => cache.put(request, copy));
                }
                return response;
            }))
        );
    }
});
