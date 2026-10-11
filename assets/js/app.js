(function () {
    'use strict';

    function markReady() {
        requestAnimationFrame(function () {
            document.body.classList.add('is-ready');
        });
    }

    function setActiveNav() {
        var path = window.location.pathname.split('/').pop() || 'dashboard.php';
        document.querySelectorAll('#sidebarOffcanvas .nav-link, #sidebar .nav-link').forEach(function (link) {
            var href = link.getAttribute('href');
            if (!href) {
                return;
            }
            if (href.split('/').pop() === path) {
                link.classList.add('active');
            }
        });
    }

    function enhanceButtons() {
        document.querySelectorAll('form:not([data-no-loading])').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                var submitter = event.submitter;
                if (!submitter || submitter.tagName !== 'BUTTON') {
                    return;
                }
                if (submitter.dataset.noLoading === 'true') {
                    return;
                }
                if (submitter.classList.contains('btn-link')) {
                    return;
                }
                var original = submitter.innerHTML;
                // Defer disable so the browser finishes serializing the POST first.
                window.setTimeout(function () {
                    submitter.disabled = true;
                    submitter.innerHTML =
                        '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Please wait…';
                }, 0);
                window.setTimeout(function () {
                    submitter.disabled = false;
                    submitter.innerHTML = original;
                }, 15000);
            });
        });
    }

    function initConfirmModals() {
        document.querySelectorAll('form[data-confirm-message]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                var message = form.getAttribute('data-confirm-message');
                if (message && !window.confirm(message)) {
                    event.preventDefault();
                }
            });
        });

        document.querySelectorAll('button[data-confirm-message], a[data-confirm-message]').forEach(function (el) {
            el.addEventListener('click', function (event) {
                var message = el.getAttribute('data-confirm-message');
                if (message && !window.confirm(message)) {
                    event.preventDefault();
                    event.stopPropagation();
                }
            });
        });
    }

    // Copy each column header onto its cells so tables can stack into
    // labelled cards on phones (see .nemsu-table--stack in style.css).
    function labelStackTables() {
        document.querySelectorAll('table.nemsu-table--stack').forEach(function (table) {
            var headers = Array.prototype.map.call(table.querySelectorAll('thead th'), function (th) {
                return {
                    label: th.textContent.trim(),
                    full: th.textContent.trim() === '' || th.classList.contains('nemsu-stack-full')
                };
            });
            table.querySelectorAll('tbody tr').forEach(function (row) {
                Array.prototype.forEach.call(row.cells, function (cell, index) {
                    var header = headers[index];
                    if (cell.colSpan > 1 || !header || header.full) {
                        cell.classList.add('nemsu-td-full');
                    } else {
                        cell.setAttribute('data-label', header.label);
                    }
                });
            });
        });
    }

    // Pages can't use inline <script> (the Content-Security-Policy in
    // config.php only allows script files), so small page behaviours are
    // switched on with data- attributes and handled here.

    // <div data-auto-refresh="60">: reload every N seconds, but not while
    // the tab is hidden or the user is typing in a field.
    function initAutoRefresh() {
        var el = document.querySelector('[data-auto-refresh]');
        var seconds = el ? parseInt(el.getAttribute('data-auto-refresh'), 10) : 0;
        if (!seconds) {
            return;
        }
        setInterval(function () {
            if (document.visibilityState === 'visible' && !document.querySelector('input:focus, select:focus, textarea:focus')) {
                window.location.reload();
            }
        }, seconds * 1000);
    }

    // <button data-confirm-when="someCheckboxId" data-confirm-when-message="...">:
    // ask for confirmation only when that radio/checkbox is selected.
    function initConditionalConfirm() {
        document.querySelectorAll('[data-confirm-when]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                var option = document.getElementById(button.getAttribute('data-confirm-when'));
                if (option && option.checked && !window.confirm(button.getAttribute('data-confirm-when-message'))) {
                    event.preventDefault();
                }
            });
        });
    }

    /**
     * "View document" links (data-doc-viewer): on computers and tablets the
     * file opens in a pop-up on the same page; on phones the normal link is
     * kept, so it opens in a new tab (phones show files better full-screen,
     * and most phone browsers cannot show a PDF inside a page).
     *   data-doc-kind = image | pdf | other   (set by PHP from the extension)
     */
    function isPhone() {
        return window.matchMedia('(max-width: 767.98px)').matches
            || /Android.+Mobile|iPhone|iPod|Windows Phone|Mobi/i.test(navigator.userAgent);
    }

    function initDocViewer() {
        var modalEl = document.getElementById('docViewerModal');
        if (!modalEl || !window.bootstrap) {
            return;   // no viewer on this page: links keep opening in a new tab
        }
        var body = document.getElementById('docViewerBody');
        var title = document.getElementById('docViewerTitle');
        var openLink = document.getElementById('docViewerOpen');
        var downloadLink = document.getElementById('docViewerDownload');
        var modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);

        document.querySelectorAll('[data-doc-viewer]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                if (isPhone()) {
                    return;   // phone: let the link open the file in a new tab
                }
                event.preventDefault();

                var url = link.getAttribute('href');
                var name = link.getAttribute('data-doc-name') || 'Document';
                var kind = link.getAttribute('data-doc-kind');
                title.lastChild.textContent = name;
                openLink.href = url;
                downloadLink.href = url;
                body.textContent = '';

                if (kind === 'image') {
                    var img = document.createElement('img');
                    img.src = url;
                    img.alt = name;
                    img.className = 'nemsu-doc-viewer__image';
                    body.appendChild(img);
                } else if (kind === 'pdf') {
                    var frame = document.createElement('iframe');
                    frame.src = url;
                    frame.title = name;
                    frame.className = 'nemsu-doc-viewer__frame';
                    body.appendChild(frame);
                } else {
                    // Word files cannot be shown inside a browser page.
                    var note = document.createElement('div');
                    note.className = 'nemsu-doc-viewer__note';
                    note.innerHTML = '<i class="bi bi-file-earmark-word" aria-hidden="true"></i>'
                        + '<p class="mb-1 fw-semibold"></p>'
                        + '<p class="small text-muted mb-0">This file type can\'t be previewed here. Use Download to open it on your device.</p>';
                    note.querySelector('.fw-semibold').textContent = name;
                    body.appendChild(note);
                }
                modal.show();
            });
        });

        // Empty the viewer when it closes, so a PDF or image stops loading.
        modalEl.addEventListener('hidden.bs.modal', function () {
            body.textContent = '';
        });
    }

    /**
     * Program dropdowns (includes/program_select.php) follow the department
     * dropdown: only the chosen department's programs are shown, and a
     * program from another department is cleared.
     */
    function initProgramSelects() {
        document.querySelectorAll('select[data-program-select]').forEach(function (programSelect) {
            var deptSelect = document.getElementById(programSelect.getAttribute('data-department-field'));
            if (!deptSelect) {
                return;
            }
            function sync() {
                var dept = deptSelect.value;
                programSelect.querySelectorAll('optgroup').forEach(function (group) {
                    var show = dept === '' || group.getAttribute('data-department') === dept;
                    group.hidden = !show;
                    group.disabled = !show;
                });
                var chosen = programSelect.selectedOptions[0];
                if (chosen && chosen.parentElement.tagName === 'OPTGROUP' && chosen.parentElement.disabled) {
                    programSelect.value = '';
                }
            }
            deptSelect.addEventListener('change', sync);
            sync();
        });
    }

    /**
     * <div data-show-for-roles="program_coordinator student" data-role-field="role">:
     * shown only while the role dropdown has one of those roles. When hidden its
     * fields are disabled, so they are not sent (the server clears them too).
     */
    function initRoleFields() {
        document.querySelectorAll('[data-show-for-roles]').forEach(function (box) {
            var roleSelect = document.getElementById(box.getAttribute('data-role-field'));
            if (!roleSelect) {
                return;
            }
            var roles = box.getAttribute('data-show-for-roles').split(' ');
            function sync() {
                var show = roles.indexOf(roleSelect.value) !== -1;
                box.hidden = !show;
                box.querySelectorAll('input, select, textarea').forEach(function (field) {
                    field.disabled = !show;
                });
            }
            roleSelect.addEventListener('change', sync);
            sync();
        });
    }

    /**
     * Live updates (#liveUpdates in includes/navbar.php). Every 10 seconds,
     * while the tab is visible, ask live_status.php whether anything changed:
     *  - the bell badge is updated and a new notification pops up as a toast;
     *  - list pages (data-live-reload="1") reload themselves, keeping the
     *    scroll position, so e.g. a complaint escalated to a Department Chair
     *    appears without pressing refresh.
     * Never reloads while the user is typing, has edited a form, or has a
     * dialog/menu open: it waits and reloads when they are done.
     */
    var LIVE_SCROLL_KEY = 'nemsu-live-scroll';
    var LIVE_TOAST_KEY = 'nemsu-live-toast';

    function safeSession(action, key, value) {
        try {
            if (action === 'get') {
                return window.sessionStorage.getItem(key);
            }
            if (action === 'set') {
                window.sessionStorage.setItem(key, value);
            } else {
                window.sessionStorage.removeItem(key);
            }
        } catch (e) {
            /* storage blocked: live updates still work, just no scroll restore */
        }
        return null;
    }

    function showToast(message) {
        if (message && window.NemsuToast) {
            window.NemsuToast.show(message, 'info');
        }
    }

    function updateBell(unread) {
        var bell = document.querySelector('.nemsu-nav-bell');
        if (!bell) {
            return;
        }
        var badge = bell.querySelector('.nemsu-notification-badge');
        if (unread > 0) {
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'nemsu-notification-badge';
                bell.appendChild(badge);
            }
            badge.textContent = unread > 99 ? '99+' : String(unread);
            bell.setAttribute('aria-label', 'Notifications, ' + unread + ' unread');
        } else {
            if (badge) {
                badge.remove();
            }
            bell.setAttribute('aria-label', 'Notifications');
        }
    }

    function initLiveUpdates() {
        var box = document.getElementById('liveUpdates');

        // Restore the scroll position / show the message after a live reload.
        var saved = safeSession('get', LIVE_SCROLL_KEY);
        if (saved) {
            safeSession('remove', LIVE_SCROLL_KEY);
            try {
                var pos = JSON.parse(saved);
                if (pos && pos.url === window.location.href) {
                    window.scrollTo(0, pos.y || 0);
                }
            } catch (e) { /* ignore */ }
        }
        var pendingToast = safeSession('get', LIVE_TOAST_KEY);
        if (pendingToast) {
            safeSession('remove', LIVE_TOAST_KEY);
            showToast(pendingToast);
        }

        if (!box || !window.fetch) {
            return;
        }
        var url = box.getAttribute('data-live-url');
        var sig = box.getAttribute('data-live-sig');
        var latestSeen = parseInt(box.getAttribute('data-live-latest') || '0', 10);
        var reloadPage = box.getAttribute('data-live-reload') === '1';
        var formEdited = false;
        var reloadWaiting = false;
        var busy = false;

        document.addEventListener('input', function () {
            formEdited = true;
        });
        document.addEventListener('submit', function () {
            formEdited = false;
        });

        function userIsBusy() {
            return formEdited
                || document.querySelector('input:focus, select:focus, textarea:focus')
                || document.querySelector('.modal.show, .offcanvas.show, .dropdown-menu.show');
        }

        // Fetch the page again and replace only the [data-live-region] parts
        // (comments, timeline), so text being typed elsewhere is kept.
        function swapRegions() {
            var regions = document.querySelectorAll('[data-live-region][id]');
            if (!regions.length || !window.DOMParser) {
                return false;
            }
            fetch(window.location.href, { credentials: 'same-origin', cache: 'no-store' })
                .then(function (response) {
                    // Redirected away (e.g. no longer allowed to view it): leave the page as is.
                    return response.ok && !response.redirected ? response.text() : null;
                })
                .then(function (html) {
                    if (!html) {
                        return;
                    }
                    var fresh = new DOMParser().parseFromString(html, 'text/html');
                    regions.forEach(function (region) {
                        var replacement = fresh.getElementById(region.id);
                        if (replacement && replacement.innerHTML !== region.innerHTML) {
                            region.innerHTML = replacement.innerHTML;
                            if (region.classList.contains('nemsu-chat-thread')) {
                                region.scrollTop = region.scrollHeight;
                            }
                        }
                    });
                })
                .catch(function () { /* try again on the next change */ });
            return true;
        }

        function reloadWhenFree(message) {
            if (userIsBusy()) {
                // Show new comments right away; the rest of the page (status,
                // proof) refreshes once the user has finished.
                if (swapRegions()) {
                    reloadWaiting = true;
                    showToast(message);
                    return;
                }
                if (!reloadWaiting) {
                    reloadWaiting = true;
                    showToast('New updates are available. They will appear when you finish, or refresh the page.');
                }
                return;
            }
            safeSession('set', LIVE_SCROLL_KEY, JSON.stringify({ url: window.location.href, y: window.scrollY }));
            if (message) {
                safeSession('set', LIVE_TOAST_KEY, message);
            }
            window.location.reload();
        }

        function check() {
            if (busy || document.visibilityState !== 'visible') {
                return;
            }
            // A change arrived while the user was busy and they are free now.
            if (reloadWaiting && !userIsBusy()) {
                reloadWhenFree('');
                return;
            }
            busy = true;
            fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } })
                .then(function (response) {
                    if (response.status === 401) {
                        // Signed out (inactivity, or logged out in another tab):
                        // reload so the page goes to the sign-in screen and
                        // stops showing student information.
                        window.location.reload();
                        return null;
                    }
                    return response.ok ? response.json() : null;
                })
                .then(function (data) {
                    if (!data || !data.sig) {
                        return;
                    }
                    updateBell(data.unread || 0);
                    var message = '';
                    if (data.latest_id && data.latest_id > latestSeen) {
                        message = data.latest_title || 'You have a new notification.';
                        latestSeen = data.latest_id;
                    }
                    if (data.sig !== sig) {
                        sig = data.sig;
                        if (reloadPage) {
                            reloadWhenFree(message);
                            return;
                        }
                    }
                    showToast(message);
                })
                .catch(function () { /* offline for a moment: try again next time */ })
                .then(function () {
                    busy = false;
                });
        }

        window.setInterval(check, 10000);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                check();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        markReady();
        initRoleFields();
        initLiveUpdates();
        setActiveNav();
        enhanceButtons();
        initConfirmModals();
        labelStackTables();
        initAutoRefresh();
        initConditionalConfirm();
        initDocViewer();
        initProgramSelects();
    });
})();
