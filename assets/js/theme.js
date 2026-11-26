(function () {
    'use strict';

    /*
     * Light / dark mode switch: in the top bar (dashboards) and in the corner
     * of the sign-in pages.
     *
     * The mode is saved in the 'nemsu_theme' cookie and the page reloads, so
     * PHP renders it in the chosen mode (see appTheme() in config.php). Doing
     * it on the server means pages never flash the wrong colours while
     * loading.
     */
    var COOKIE = 'nemsu_theme';

    function saveTheme(theme) {
        // One year, whole site, sent only to this site.
        document.cookie = COOKIE + '=' + theme + '; path=/; max-age=31536000; SameSite=Lax';
    }

    document.addEventListener('DOMContentLoaded', function () {
        var btn = document.getElementById('themeToggle');
        if (!btn) {
            return;
        }
        btn.addEventListener('click', function () {
            var next = btn.getAttribute('data-current-theme') === 'dark' ? 'light' : 'dark';
            saveTheme(next);
            btn.disabled = true;
            // Open the same address again (a GET), not reload(): reloading a page
            // that was the result of a form would ask to re-send the form.
            window.location.assign(window.location.href.split('#')[0]);
        });
    });
})();
