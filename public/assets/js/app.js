(function () {
    'use strict';

    /* Reads the dialog texts from the button, then opens the FindIT confirm dialog. */
    function askConfirm(button, message) {
        var title = button.getAttribute('data-confirm-title');
        var text = message;

        // No explicit title: use the first sentence ("Approve this claim?") as the title.
        if (!title) {
            var cut = message.indexOf('? ');
            if (cut > 0) {
                title = message.slice(0, cut + 1);
                text = message.slice(cut + 2);
            } else {
                title = 'Are you sure?';
            }
        }

        var tone = button.getAttribute('data-confirm-tone');
        if (!tone) {
            tone = button.value === 'reject' || /danger/.test(button.className) ? 'danger' : 'info';
        }

        if (!window.FindIT || !window.FindIT.confirm) {
            return Promise.resolve(window.confirm(title + ' ' + text));
        }

        return window.FindIT.confirm({
            title: title,
            message: text,
            tone: tone,
            confirmLabel: button.getAttribute('data-confirm-label') || 'Yes, continue',
            cancelLabel: button.getAttribute('data-cancel-label') || 'Go back'
        });
    }

    /* 1. Form validation, loading state, and confirmation. */
    function initForms() {
        document.querySelectorAll('form.needs-validation').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                var submitter = event.submitter || null;

                // Some inputs are only required for a specific submit button.
                form.querySelectorAll('[data-required-for]').forEach(function (field) {
                    field.required = !!submitter &&
                        submitter.value === field.getAttribute('data-required-for');
                });

                // Fields that must match another field (for example password confirmation).
                form.querySelectorAll('[data-match]').forEach(function (field) {
                    var other = form.querySelector(field.getAttribute('data-match'));
                    var mismatch = other && field.value !== other.value;
                    field.setCustomValidity(mismatch ? 'Values do not match.' : '');
                });

                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                    form.classList.add('was-validated');

                    var firstInvalid = form.querySelector(':invalid');
                    if (firstInvalid) {
                        firstInvalid.focus({ preventScroll: true });
                        firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                    return;
                }

                // Ask for confirmation on irreversible actions, after validation passes.
                // A styled FindIT dialog is shown (not the browser's "localhost says" box).
                // When the person confirms, the form is submitted again and passes through here.
                var message = submitter && submitter.getAttribute('data-confirm');
                if (message && form.getAttribute('data-confirmed') !== '1') {
                    event.preventDefault();
                    askConfirm(submitter, message).then(function (ok) {
                        if (!ok) {
                            return;
                        }
                        form.setAttribute('data-confirmed', '1');
                        if (form.requestSubmit) {
                            form.requestSubmit(submitter);
                        } else {
                            // Very old browsers: keep the clicked button's value, then submit.
                            var keep = document.createElement('input');
                            keep.type = 'hidden';
                            keep.name = submitter.name;
                            keep.value = submitter.value;
                            form.appendChild(keep);
                            form.submit();
                        }
                    });
                    return;
                }
                form.removeAttribute('data-confirmed');

                // Prevent double submits. The delay keeps the clicked button's value in the form data.
                window.setTimeout(function () {
                    form.querySelectorAll('[type="submit"]').forEach(function (button) {
                        if (button === submitter && button.hasAttribute('data-loading-text')) {
                            button.innerHTML = '<span class="fi-spinner" aria-hidden="true"></span>' +
                                button.getAttribute('data-loading-text');
                        }
                        button.disabled = true;
                        button.classList.add('fi-btn-loading');
                    });
                }, 0);
            });

            // Re-validate a field as soon as the user fixes it.
            form.querySelectorAll('input, select, textarea').forEach(function (field) {
                field.addEventListener('input', function () {
                    if (!form.classList.contains('was-validated')) {
                        return;
                    }
                    if (field.hasAttribute('data-match')) {
                        var other = form.querySelector(field.getAttribute('data-match'));
                        field.setCustomValidity(other && field.value !== other.value ? 'Values do not match.' : '');
                    }
                });
            });
        });

        // The browser back button can restore a page with disabled buttons.
        window.addEventListener('pageshow', function (event) {
            if (!event.persisted) {
                return;
            }
            document.querySelectorAll('form.needs-validation [type="submit"]').forEach(function (button) {
                button.disabled = false;
                button.classList.remove('fi-btn-loading');
            });
        });
    }

    /* 2. Character counters for long text fields. */
    function initCounters() {
        document.querySelectorAll('textarea[data-counter]').forEach(function (area) {
            var max = parseInt(area.getAttribute('maxlength'), 10);
            if (!max) {
                return;
            }

            var counter = document.createElement('div');
            counter.className = 'fi-char-count';
            counter.setAttribute('aria-live', 'polite');
            area.parentNode.insertBefore(counter, area.nextSibling);

            function update() {
                var length = area.value.length;
                counter.textContent = length + ' / ' + max;
                counter.classList.toggle('is-near-limit', length >= max * 0.9);
            }

            area.addEventListener('input', update);
            update();
        });
    }

    /* 3. Show or hide a password. */
    function initPasswordToggles() {
        document.querySelectorAll('[data-toggle-password]').forEach(function (button) {
            button.addEventListener('click', function () {
                var input = document.querySelector(button.getAttribute('data-toggle-password'));
                if (!input) {
                    return;
                }
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                button.textContent = show ? 'Hide' : 'Show';
                button.setAttribute('aria-pressed', show ? 'true' : 'false');
            });
        });
    }

    /* 4. Success messages fade out by themselves. */
    function initAutoHide() {
        document.querySelectorAll('.alert[data-autohide]').forEach(function (alertBox) {
            var delay = parseInt(alertBox.getAttribute('data-autohide'), 10) || 6000;
            window.setTimeout(function () {
                alertBox.classList.add('fade-out');
                window.setTimeout(function () {
                    alertBox.remove();
                }, 450);
            }, delay);
        });
    }

    /* 5. Copy text (for example the pickup code). */
    function initCopyButtons() {
        document.querySelectorAll('[data-copy]').forEach(function (button) {
            button.addEventListener('click', function () {
                var text = button.getAttribute('data-copy');
                var original = button.textContent;

                function done() {
                    button.textContent = 'Copied';
                    window.setTimeout(function () {
                        button.textContent = original;
                    }, 1600);
                }

                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(done);
                    return;
                }

                // Fallback for plain http:// on localhost setups.
                var helper = document.createElement('textarea');
                helper.value = text;
                helper.setAttribute('readonly', '');
                helper.style.position = 'fixed';
                helper.style.opacity = '0';
                document.body.appendChild(helper);
                helper.select();
                try {
                    document.execCommand('copy');
                    done();
                } catch (error) {
                    button.textContent = 'Press Ctrl+C';
                }
                document.body.removeChild(helper);
            });
        });
    }

    /* 6. Homepage search and category filter. */
    function initHomeFilter() {
        var search = document.getElementById('home-search');
        var category = document.getElementById('home-category');
        var reset = document.getElementById('home-reset');
        var count = document.getElementById('home-visible-count');
        var empty = document.getElementById('home-no-results');
        var cards = Array.prototype.slice.call(document.querySelectorAll('[data-item-card]'));

        if (!search || !category || !count || cards.length === 0) {
            return;
        }

        function apply() {
            var term = search.value.trim().toLowerCase();
            var selected = category.value.toLowerCase();
            var visible = 0;

            cards.forEach(function (card) {
                var matchesText = (card.getAttribute('data-search') || '').indexOf(term) !== -1;
                var matchesCategory = selected === '' ||
                    (card.getAttribute('data-category') || '').toLowerCase() === selected;
                var show = matchesText && matchesCategory;

                card.hidden = !show;
                if (show) {
                    visible++;
                }
            });

            count.textContent = visible;
            if (empty) {
                empty.hidden = visible !== 0;
            }
        }

        search.addEventListener('input', apply);
        category.addEventListener('change', apply);

        if (reset) {
            reset.addEventListener('click', function () {
                search.value = '';
                category.value = '';
                apply();
                search.focus();
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        initForms();
        initCounters();
        initPasswordToggles();
        initAutoHide();
        initCopyButtons();
        initHomeFilter();
    });
})();

/* ==========================================================================
   Notifications: bell dropdown, live count (polling) and toast messages.
   The site works without this file; it only adds live updates and no-reload actions.
   All text from the server is inserted with textContent. The only HTML inserted is
   markup that PHP already escaped (the notification list) and trusted SVG icons.
   ========================================================================== */
(function () {
    'use strict';

    var ICONS = {
        success: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>',
        danger: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>',
        warning: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
        info: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>',
        close: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>'
    };

    /* ---------------------------------------------------------------- toast */
    var MAX_TOASTS = 3;
    var stack = null;

    function ensureStack() {
        if (stack && document.body.contains(stack)) {
            return stack;
        }
        stack = document.createElement('div');
        stack.className = 'fi-toast-stack';
        stack.setAttribute('aria-live', 'polite');
        stack.setAttribute('aria-relevant', 'additions');
        document.body.appendChild(stack);
        return stack;
    }

    /**
     * Show a pop-up message in the corner.
     * options: type (success|danger|warning|info), title, message, url, actionLabel,
     *          iconHtml (trusted SVG), duration (ms)
     */
    function toast(options) {
        var type = ['success', 'danger', 'warning', 'info'].indexOf(options.type) !== -1 ? options.type : 'info';
        var duration = options.duration || (type === 'danger' ? 9000 : 6500);
        var host = ensureStack();

        while (host.children.length >= MAX_TOASTS) {
            host.removeChild(host.firstChild);
        }

        var el = document.createElement('div');
        el.className = 'fi-toast fi-toast--' + type;
        el.setAttribute('role', type === 'danger' ? 'alert' : 'status');

        var icon = document.createElement('span');
        icon.className = 'fi-toast-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = options.iconHtml || ICONS[type];

        var body = document.createElement('div');
        body.className = 'fi-toast-body';

        var title = document.createElement('strong');
        title.className = 'fi-toast-title';
        title.textContent = options.title || '';
        body.appendChild(title);

        if (options.message) {
            var msg = document.createElement('span');
            msg.className = 'fi-toast-msg';
            msg.textContent = options.message;
            body.appendChild(msg);
        }

        if (options.url) {
            var action = document.createElement('a');
            action.className = 'fi-toast-action';
            action.href = options.url;
            action.textContent = options.actionLabel || 'View';
            body.appendChild(action);
        }

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'fi-toast-close';
        close.setAttribute('aria-label', 'Dismiss message');
        close.innerHTML = ICONS.close;

        var timerBar = document.createElement('span');
        timerBar.className = 'fi-toast-timer';
        timerBar.setAttribute('aria-hidden', 'true');
        timerBar.style.animationDuration = duration + 'ms';

        el.appendChild(icon);
        el.appendChild(body);
        el.appendChild(close);
        el.appendChild(timerBar);
        host.appendChild(el);

        var remaining = duration;
        var startedAt = Date.now();
        var timer = null;

        function dismiss() {
            window.clearTimeout(timer);
            if (!el.parentNode) {
                return;
            }
            el.classList.add('is-leaving');
            window.setTimeout(function () {
                if (el.parentNode) {
                    el.parentNode.removeChild(el);
                }
            }, 230);
        }

        function run() {
            startedAt = Date.now();
            timer = window.setTimeout(dismiss, remaining);
        }

        // Hovering or focusing the message pauses it, so it can be read and clicked.
        function pause() {
            window.clearTimeout(timer);
            remaining = Math.max(1500, remaining - (Date.now() - startedAt));
        }

        el.addEventListener('mouseenter', pause);
        el.addEventListener('focusin', pause);
        el.addEventListener('mouseleave', run);
        el.addEventListener('focusout', run);
        close.addEventListener('click', dismiss);
        run();

        return el;
    }

    /* -------------------------------------------------------- confirm dialog */
    /**
     * A modern replacement for window.confirm(). Returns a Promise: true (confirmed) or false.
     * options: title, message, tone (success|danger|warning|info), confirmLabel, cancelLabel.
     * Esc, clicking outside and the cancel button all mean "no". Focus stays inside the dialog
     * and returns to the button that opened it. Text is inserted with textContent only.
     */
    function confirmDialog(options) {
        return new Promise(function (resolve) {
            var tone = ['success', 'danger', 'warning', 'info'].indexOf(options.tone) !== -1 ? options.tone : 'info';
            var opener = document.activeElement;
            var uid = 'fi-modal-' + Date.now();

            var overlay = document.createElement('div');
            overlay.className = 'fi-modal-overlay';

            var dialog = document.createElement('div');
            dialog.className = 'fi-modal fi-modal--' + tone;
            dialog.setAttribute('role', 'alertdialog');
            dialog.setAttribute('aria-modal', 'true');
            dialog.setAttribute('aria-labelledby', uid + '-title');
            dialog.setAttribute('aria-describedby', uid + '-text');
            dialog.tabIndex = -1;

            var icon = document.createElement('span');
            icon.className = 'fi-modal-icon';
            icon.setAttribute('aria-hidden', 'true');
            // Dangerous actions get the warning triangle; others use their normal tone icon.
            icon.innerHTML = tone === 'danger' ? ICONS.warning : ICONS[tone];

            var title = document.createElement('h2');
            title.className = 'fi-modal-title';
            title.id = uid + '-title';
            title.textContent = options.title || 'Are you sure?';

            var text = document.createElement('p');
            text.className = 'fi-modal-text';
            text.id = uid + '-text';
            text.textContent = options.message || '';

            var actions = document.createElement('div');
            actions.className = 'fi-modal-actions';

            var cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'btn btn-outline-secondary';
            cancel.textContent = options.cancelLabel || 'Go back';

            var ok = document.createElement('button');
            ok.type = 'button';
            ok.className = 'btn ' + (tone === 'danger' ? 'fi-btn-danger' : 'btn-primary');
            ok.textContent = options.confirmLabel || 'Yes, continue';

            actions.appendChild(cancel);
            actions.appendChild(ok);
            dialog.appendChild(icon);
            dialog.appendChild(title);
            if (options.message) {
                dialog.appendChild(text);
            }
            dialog.appendChild(actions);
            overlay.appendChild(dialog);
            document.body.appendChild(overlay);
            document.body.classList.add('fi-modal-open');

            var closed = false;

            function close(result) {
                if (closed) {
                    return;
                }
                closed = true;
                document.removeEventListener('keydown', onKey, true);
                document.body.classList.remove('fi-modal-open');
                overlay.classList.add('is-leaving');
                window.setTimeout(function () {
                    if (overlay.parentNode) {
                        overlay.parentNode.removeChild(overlay);
                    }
                }, 160);
                if (opener && opener.focus) {
                    opener.focus();
                }
                resolve(result);
            }

            function onKey(event) {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    close(false);
                    return;
                }
                // Keep Tab inside the two buttons.
                if (event.key === 'Tab') {
                    var first = cancel;
                    var last = ok;
                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();
                        last.focus();
                    } else if (!event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                    } else if (document.activeElement !== first && document.activeElement !== last) {
                        event.preventDefault();
                        first.focus();
                    }
                }
            }

            document.addEventListener('keydown', onKey, true);
            cancel.addEventListener('click', function () { close(false); });
            ok.addEventListener('click', function () { close(true); });
            overlay.addEventListener('mousedown', function (event) {
                if (event.target === overlay) {
                    close(false);
                }
            });

            // Dangerous action: start on the safe choice. Otherwise start on the main button.
            (tone === 'danger' ? cancel : ok).focus();
        });
    }

    window.FindIT = window.FindIT || {};
    window.FindIT.toast = toast;
    window.FindIT.confirm = confirmDialog;

    /* ----------------------------------------------------------------- bell */
    function initBell(root) {
        var mode = root.getAttribute('data-mode') || 'student';
        var endpoint = root.getAttribute('data-endpoint');
        var csrf = root.getAttribute('data-csrf') || '';
        var pollSeconds = parseInt(root.getAttribute('data-poll'), 10) || 45;
        var heading = root.getAttribute('data-title') || 'Notifications';

        var button = root.querySelector('[data-bell-button]');
        var badge = root.querySelector('[data-bell-badge]');
        var list = root.querySelector('[data-bell-list]');
        var empty = root.querySelector('[data-bell-empty]');
        var markAll = root.querySelector('[data-bell-markall]');
        var live = root.querySelector('[data-bell-live]');

        if (!endpoint || !button || !badge || !list || !empty) {
            return;
        }

        var baseTitle = document.title;
        var latestId = parseInt(root.getAttribute('data-latest-id'), 10) || 0;
        var counts = {};
        try {
            counts = JSON.parse(root.getAttribute('data-counts') || '{}') || {};
        } catch (error) {
            counts = {};
        }
        var unread = badge.hidden ? 0 : (parseInt(badge.textContent, 10) || 0);
        var noun = mode === 'admin' ? 'alerts' : 'unread notifications';

        function applyCount(n) {
            unread = Math.max(0, parseInt(n, 10) || 0);
            badge.hidden = unread === 0;
            badge.textContent = unread > 99 ? '99+' : String(unread);
            button.setAttribute('aria-label', heading + (unread > 0 ? ', ' + unread + ' ' + noun : ', nothing new'));
            if (markAll) {
                markAll.hidden = unread === 0;
            }
            if (mode === 'student') {
                document.title = unread > 0 ? '(' + unread + ') ' + baseTitle : baseTitle;
            }
        }

        function applyList(html) {
            list.innerHTML = html || '';
            var has = list.children.length > 0;
            list.hidden = !has;
            empty.hidden = has;
        }

        function ring() {
            root.classList.remove('is-ping');
            // Reading offsetWidth restarts the CSS animation.
            void root.offsetWidth;
            root.classList.add('is-ping');
        }

        function request(method, url, data) {
            var options = {
                method: method,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf }
            };
            if (data) {
                options.body = new URLSearchParams(data);
            }
            return window.fetch(url, options).then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (json) {
                    return { status: response.status, data: json };
                });
            });
        }

        function failed() {
            toast({
                type: 'danger',
                title: 'Could not update notifications',
                message: 'Check your connection and try again.'
            });
        }

        /* Mark one as read, without leaving the page. */
        list.addEventListener('click', function (event) {
            var target = event.target.closest ? event.target.closest('[data-notif-read]') : null;
            if (!target) {
                return;
            }
            event.preventDefault();
            var row = target.closest('.fi-notif');

            target.disabled = true;
            request('POST', endpoint, { action: 'mark_read', id: target.getAttribute('data-notif-read') })
                .then(function (res) {
                    if (res.status !== 200 || !res.data.ok) {
                        target.disabled = false;
                        failed();
                        return;
                    }
                    if (row) {
                        row.classList.remove('is-unread');
                    }
                    target.remove();
                    applyCount(res.data.unread);
                    if (live) {
                        live.textContent = 'Notification marked as read.';
                    }
                })
                .catch(function () {
                    target.disabled = false;
                    failed();
                });
        });

        /* Mark all as read. */
        if (markAll) {
            markAll.addEventListener('click', function () {
                markAll.disabled = true;
                request('POST', endpoint, { action: 'mark_all' })
                    .then(function (res) {
                        markAll.disabled = false;
                        if (res.status !== 200 || !res.data.ok) {
                            failed();
                            return;
                        }
                        list.querySelectorAll('.fi-notif.is-unread').forEach(function (row) {
                            row.classList.remove('is-unread');
                        });
                        list.querySelectorAll('[data-notif-read]').forEach(function (b) { b.remove(); });
                        applyCount(res.data.unread);
                        toast({ type: 'success', title: 'All caught up', message: 'Every notification is marked as read.', duration: 4000 });
                    })
                    .catch(function () {
                        markAll.disabled = false;
                        failed();
                    });
            });
        }

        /* New items found by polling: show up to two toasts, or one summary. */
        function announce(items) {
            if (!items.length) {
                return;
            }
            ring();
            if (items.length > 2) {
                toast({
                    type: 'info',
                    title: items.length + ' new ' + (mode === 'admin' ? 'alerts' : 'notifications'),
                    message: 'Open the bell to see them.'
                });
                return;
            }
            items.forEach(function (item) {
                toast({
                    type: item.severity,
                    title: item.title,
                    message: item.message,
                    url: item.url,
                    actionLabel: mode === 'admin' ? 'Open' : 'View',
                    iconHtml: item.icon
                });
            });
        }

        function handle(data) {
            applyCount(data.unread);
            applyList(data.html);

            var toasts = data.toasts || [];

            if (mode === 'admin') {
                badge.className = 'fi-bell-badge fi-bell-badge--' + (data.severity === 'danger' ? 'danger' : 'warning');
                var fresh = [];
                var next = {};
                toasts.forEach(function (item) {
                    next[item.key] = item.count;
                    // Only tell the admin about something new or something that grew.
                    if (counts[item.key] === undefined || item.count > counts[item.key]) {
                        fresh.push(item);
                    }
                });
                counts = next;
                announce(fresh);
                return;
            }

            if (typeof data.latest_id === 'number') {
                latestId = Math.max(latestId, data.latest_id);
            }
            announce(toasts);
        }

        /* Polling: light, pauses in a hidden tab, backs off on errors, stops when signed out. */
        var timer = null;
        var failures = 0;
        var stopped = false;
        var lastRun = Date.now();

        function schedule(ms) {
            window.clearTimeout(timer);
            if (!stopped) {
                timer = window.setTimeout(poll, ms);
            }
        }

        function poll() {
            if (document.hidden) {
                schedule(pollSeconds * 1000);
                return;
            }
            lastRun = Date.now();
            var url = endpoint + (mode === 'student' ? '?action=summary&since=' + latestId : '');
            request('GET', url)
                .then(function (res) {
                    if (res.status === 401 || res.status === 403) {
                        stopped = true;
                        return;
                    }
                    if (res.status !== 200 || !res.data.ok) {
                        throw new Error('bad response');
                    }
                    failures = 0;
                    handle(res.data);
                    schedule(pollSeconds * 1000);
                })
                .catch(function () {
                    failures += 1;
                    schedule(Math.min(300000, pollSeconds * 1000 * Math.pow(2, failures)));
                });
        }

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && !stopped && Date.now() - lastRun > 15000) {
                schedule(0);
            }
        });
        window.addEventListener('pagehide', function () {
            stopped = true;
            window.clearTimeout(timer);
        });
        // Coming back through the browser's back button: start polling again.
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                stopped = false;
                schedule(0);
            }
        });

        applyCount(unread);
        schedule(pollSeconds * 1000);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-fi-bell]').forEach(initBell);
    });
})();
