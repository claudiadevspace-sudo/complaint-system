(function () {
    'use strict';

    /*
     * Progressive Web App: registers the service worker (sw.js), shows an
     * "Install app" button when the browser allows installing, and a notice
     * while the device is offline (the page shown is then a saved copy).
     */
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('sw.js').catch(function () { /* not critical */ });
        });
    }

    // ---- Install button ----
    var deferredPrompt = null;
    function showInstallButton() {
        if (document.getElementById('pwaInstall')) {
            return;
        }
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.id = 'pwaInstall';
        btn.className = 'nemsu-pwa-install';
        btn.innerHTML = '<i class="bi bi-download" aria-hidden="true"></i><span>Install app</span>';
        btn.addEventListener('click', function () {
            if (!deferredPrompt) {
                return;
            }
            deferredPrompt.prompt();
            deferredPrompt.userChoice.finally(function () {
                deferredPrompt = null;
                btn.remove();
            });
        });
        document.body.appendChild(btn);
    }
    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        deferredPrompt = event;
        showInstallButton();
    });
    window.addEventListener('appinstalled', function () {
        var btn = document.getElementById('pwaInstall');
        if (btn) {
            btn.remove();
        }
    });

    // ---- Offline notice ----
    function updateOnlineState() {
        var bar = document.getElementById('pwaOffline');
        if (navigator.onLine) {
            if (bar) {
                bar.remove();
            }
            document.documentElement.classList.remove('is-offline');
            return;
        }
        document.documentElement.classList.add('is-offline');
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'pwaOffline';
            bar.className = 'nemsu-offline-bar';
            bar.setAttribute('role', 'status');
            bar.innerHTML = '<i class="bi bi-wifi-off" aria-hidden="true"></i> You\'re offline. This is a saved copy: '
                + 'you can read it, but sending or saving needs a connection.';
            document.body.appendChild(bar);
        }
    }
    window.addEventListener('online', updateOnlineState);
    window.addEventListener('offline', updateOnlineState);
    document.addEventListener('DOMContentLoaded', updateOnlineState);
})();
