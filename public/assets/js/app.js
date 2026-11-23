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

    /* 3b. The NIM field must be 12 digits and equal the NIM in the campus email. */
    function initNimMatch() {
        document.querySelectorAll('[data-nim-email]').forEach(function (nimField) {
            var emailField = document.querySelector(nimField.getAttribute('data-nim-email'));
            if (!emailField) {
                return;
            }
            var feedback = nimField.parentNode.querySelector('[data-nim-feedback]');

            function check() {
                var nim = nimField.value;
                var emailNim = emailField.value.trim().toLowerCase().split('@')[0];
                var message = '';

                if (!/^[0-9]{12}$/.test(nim)) {
                    message = 'Your NIM must be exactly 12 digits.';
                } else if (/^[0-9]{12}$/.test(emailNim) && nim !== emailNim) {
                    message = 'Your NIM must be the same as the NIM in your campus email.';
                }

                nimField.setCustomValidity(message);
                if (feedback && message) {
                    feedback.textContent = message;
                }
            }

            // Only digits are allowed in the NIM field.
            nimField.addEventListener('input', function () {
                var digits = nimField.value.replace(/[^0-9]/g, '');
                if (digits !== nimField.value) {
                    nimField.value = digits;
                }
                check();
            });
            emailField.addEventListener('input', check);
            check();
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
        initNimMatch();
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

    // check if the email is a campus email, and if so, extract the NIM (student ID) from it.
    document.addEventListener('DOMContentLoaded', function () {
        // This file is loaded on every page, so only run when the register form exists.
        var form = document.querySelector('form[action="register.php"]');
        if (!form) {
            return;
        }

        var email = form.querySelector('#email');
        var studentId = form.querySelector('#student_id');
        if (!email || !studentId) {
            return;
        }
        var feedback = studentId.parentElement.querySelector('.invalid-feedback');

        function nimFromEmail() {
            return email.value.trim().toLowerCase().split('@')[0];
        }

        var edited = studentId.value !== '' && studentId.value !== nimFromEmail();

        function check() {
            var value = studentId.value;
            var message = '';

            if (value !== '' && !/^[0-9]{12}$/.test(value)) {
                message = 'Student ID must be exactly 12 digits.';
            } else if (value !== '' && email.value.indexOf('@') !== -1 && value !== nimFromEmail()) {
                message = 'Student ID must match the NIM in your campus email.';
            }

            studentId.setCustomValidity(message);

            if (feedback) {
                feedback.textContent = message !== '' ? message : 'Please enter your student ID.';
            }
        }

        function onEmailChange() {
            var nim = nimFromEmail();

            if (!edited && /^[0-9]*$/.test(nim)) {
                studentId.value = nim.slice(0, 12);
            }
            check();
        }

        email.addEventListener('input', onEmailChange);
        email.addEventListener('change', onEmailChange);

        studentId.addEventListener('input', function () {
            studentId.value = studentId.value.replace(/[^0-9]/g, '');

            edited = studentId.value !== '' && studentId.value !== nimFromEmail();
            check();
        });

        check();
    });

})();

/* Profile page: choose a photo, then move and zoom it inside a round frame before uploading.
 The cropped square (512 x 512 JPEG) replaces the chosen file, so the server still receives a
   normal upload and checks it as before. Browsers without canvas or DataTransfer support keep  
   the old behaviour: the whole photo is uploaded and the server crops the centre. */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var input = document.querySelector('[data-photo-input]');
        var box = document.querySelector('[data-photo-preview]');
        var dialog = document.getElementById('fiCropper');
        var adjustBtn = document.querySelector('[data-photo-adjust]');
        if (!input || !box || !window.URL || !URL.createObjectURL) {
            return;
        }

        var maxPickBytes = 10 * 1024 * 1024;   // photos straight from a phone camera are big
        var maxUploadBytes = 2 * 1024 * 1024;  // what the server accepts
        var allowed = ['image/jpeg', 'image/png', 'image/webp'];
        var OUTPUT = 512;
        var previewUrl = null;
        var originalFile = null;               // the photo the person picked (uncropped)
        var committed = null;                  // the last cropped file placed in the input
        var committedSource = null;            // the original photo that `committed` came from
        var canCrop = !!(dialog && window.DataTransfer && HTMLCanvasElement.prototype.toBlob);

        function showPreview(file) {
            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
            }
            previewUrl = URL.createObjectURL(file);

            var face = box.querySelector('[data-fi-avatar]');
            if (face) {
                face.innerHTML = '';
                var img = document.createElement('img');
                img.src = previewUrl;
                img.alt = '';
                face.appendChild(img);
            }
        }

        function reject(message) {
            input.setCustomValidity(message);
            input.reportValidity();
            input.value = '';
            originalFile = null;
            committed = null;
            committedSource = null;
            if (adjustBtn) {
                adjustBtn.classList.add('d-none');
            }
        }

        /* ---------- crop dialog ---------- */
        var viewport, image, zoom, state, dragging, objectUrl;

        if (canCrop) {
            viewport = dialog.querySelector('[data-crop-viewport]');
            image = dialog.querySelector('[data-crop-image]');
            zoom = dialog.querySelector('[data-crop-zoom]');
            state = { nw: 0, nh: 0, size: 0, base: 1, z: 1, ox: 0, oy: 0 };
            dragging = null;
            objectUrl = null;
        }

        function clampAndRender() {
            var scale = state.base * state.z;
            var maxX = Math.max(0, (state.nw * scale - state.size) / 2);
            var maxY = Math.max(0, (state.nh * scale - state.size) / 2);
            state.ox = Math.min(maxX, Math.max(-maxX, state.ox));
            state.oy = Math.min(maxY, Math.max(-maxY, state.oy));

            var w = state.nw * scale;
            var h = state.nh * scale;
            image.style.width = w + 'px';
            image.style.height = h + 'px';
            image.style.left = (state.size / 2 + state.ox - w / 2) + 'px';
            image.style.top = (state.size / 2 + state.oy - h / 2) + 'px';
        }

        function setZoom(value) {
            state.z = Math.min(4, Math.max(1, value));
            zoom.value = String(state.z);
            clampAndRender();
        }

        function openCropper(file) {
            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
            }
            objectUrl = URL.createObjectURL(file);

            image.onload = function () {
                dialog.hidden = false;
                document.body.style.overflow = 'hidden';

                state.size = viewport.clientWidth;
                state.nw = image.naturalWidth;
                state.nh = image.naturalHeight;
                // "Cover" the square: the shorter side exactly fills it at zoom 1.
                state.base = Math.max(state.size / state.nw, state.size / state.nh);
                state.ox = 0;
                state.oy = 0;
                setZoom(1);
                viewport.focus();
            };
            image.onerror = function () {
                reject('This file could not be read as an image.');
            };
            image.src = objectUrl;
        }

        function closeCropper() {
            dialog.hidden = true;
            document.body.style.overflow = '';
            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
                objectUrl = null;
            }
            input.focus();
        }

        function cancelCrop() {
            if (committed) {
                // Go back to the last cropped photo, so a big uncropped file is never uploaded.
                var back = new DataTransfer();
                back.items.add(committed);
                input.files = back.files;
                originalFile = committedSource;
            } else {
                // Nothing was ever applied: clear the choice completely.
                input.value = '';
                originalFile = null;
                if (adjustBtn) {
                    adjustBtn.classList.add('d-none');
                }
            }
            input.setCustomValidity('');
            closeCropper();
        }

        function applyCrop() {
            var scale = state.base * state.z;
            var left = state.size / 2 + state.ox - (state.nw * scale) / 2;
            var top = state.size / 2 + state.oy - (state.nh * scale) / 2;
            var sx = -left / scale;
            var sy = -top / scale;
            var side = state.size / scale;

            var canvas = document.createElement('canvas');
            canvas.width = OUTPUT;
            canvas.height = OUTPUT;
            var ctx = canvas.getContext('2d');
            ctx.fillStyle = '#ffffff';            // PNG transparency becomes white
            ctx.fillRect(0, 0, OUTPUT, OUTPUT);
            ctx.drawImage(image, sx, sy, side, side, 0, 0, OUTPUT, OUTPUT);

            canvas.toBlob(function (blob) {
                if (!blob || blob.size > maxUploadBytes) {
                    closeCropper();
                    reject('The photo could not be prepared. Please try another photo.');
                    return;
                }
                var cropped = new File([blob], 'profile.jpg', { type: 'image/jpeg' });
                var transfer = new DataTransfer();
                transfer.items.add(cropped);
                input.files = transfer.files;
                input.setCustomValidity('');

                committed = cropped;
                committedSource = originalFile;

                showPreview(cropped);
                if (adjustBtn) {
                    adjustBtn.classList.remove('d-none');
                }
                closeCropper();
            }, 'image/jpeg', 0.9);
        }

        if (canCrop) {
            viewport.addEventListener('pointerdown', function (event) {
                dragging = { x: event.clientX, y: event.clientY };
                viewport.setPointerCapture(event.pointerId);
                event.preventDefault();
            });
            viewport.addEventListener('pointermove', function (event) {
                if (!dragging) {
                    return;
                }
                state.ox += event.clientX - dragging.x;
                state.oy += event.clientY - dragging.y;
                dragging = { x: event.clientX, y: event.clientY };
                clampAndRender();
            });
            ['pointerup', 'pointercancel'].forEach(function (name) {
                viewport.addEventListener(name, function () {
                    dragging = null;
                });
            });
            viewport.addEventListener('wheel', function (event) {
                event.preventDefault();
                setZoom(state.z + (event.deltaY < 0 ? 0.1 : -0.1));
            }, { passive: false });
            viewport.addEventListener('keydown', function (event) {
                var step = 10;
                var handled = true;
                if (event.key === 'ArrowLeft') { state.ox += step; }
                else if (event.key === 'ArrowRight') { state.ox -= step; }
                else if (event.key === 'ArrowUp') { state.oy += step; }
                else if (event.key === 'ArrowDown') { state.oy -= step; }
                else if (event.key === '+' || event.key === '=') { setZoom(state.z + 0.1); }
                else if (event.key === '-') { setZoom(state.z - 0.1); }
                else { handled = false; }
                if (handled) {
                    event.preventDefault();
                    clampAndRender();
                }
            });
            zoom.addEventListener('input', function () {
                setZoom(parseFloat(zoom.value));
            });

            dialog.querySelectorAll('[data-crop-cancel]').forEach(function (el) {
                el.addEventListener('click', cancelCrop);
            });
            dialog.querySelector('[data-crop-apply]').addEventListener('click', applyCrop);
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !dialog.hidden) {
                    cancelCrop();
                }
            });
        }

        if (adjustBtn) {
            adjustBtn.addEventListener('click', function () {
                if (canCrop && originalFile) {
                    openCropper(originalFile);
                }
            });
        }

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            input.setCustomValidity('');

            if (!file) {
                return;
            }
            if (allowed.indexOf(file.type) === -1) {
                reject('Only JPG, PNG or WebP photos are allowed.');
                return;
            }

            if (!canCrop) {
                if (file.size > maxUploadBytes) {
                    reject('The photo is too large. The maximum size is 2 MB.');
                    return;
                }
                showPreview(file);
                return;
            }

            if (file.size > maxPickBytes) {
                reject('The photo is too large. Please choose one under 10 MB.');
                return;
            }
            originalFile = file;
            openCropper(file);
        });
    });
})();

/* Password strength meter: <div data-strength="#password-input"> with 4 bars and a label.
   The server only requires 8 characters; the meter just helps people choose a better one. */
(function () {
    'use strict';

    var LEVELS = [
        { label: 'Not entered', hint: 'Use 12 or more characters with upper and lower case letters, numbers and symbols.' },
        { label: 'Weak', hint: 'Add more characters, and mix letters, numbers and symbols.' },
        { label: 'Fair', hint: 'Getting better. Try 12 or more characters or add a symbol.' },
        { label: 'Good', hint: 'Good. A few more characters or a symbol would make it strong.' },
        { label: 'Strong', hint: 'Strong password.' }
    ];

    function rate(value) {
        if (!value) {
            return 0;
        }
        if (value.length < 8) {
            return 1;
        }
        var points = 0;
        if (value.length >= 12) { points++; }
        if (/[a-z]/.test(value) && /[A-Z]/.test(value)) { points++; }
        if (/\d/.test(value)) { points++; }
        if (/[^A-Za-z0-9]/.test(value)) { points++; }
        // 8+ characters starts at Weak; each extra point raises the level (3 points = Strong).
        return Math.min(4, 1 + points);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-strength]').forEach(function (box) {
            var input = document.querySelector(box.getAttribute('data-strength'));
            var label = box.querySelector('[data-strength-label]');
            var hint = box.querySelector('[data-strength-hint]');
            if (!input) {
                return;
            }

            function update() {
                var level = rate(input.value);
                box.setAttribute('data-level', String(level));
                if (label) { label.textContent = LEVELS[level].label; }
                if (hint) { hint.textContent = LEVELS[level].hint; }
            }

            input.addEventListener('input', update);
            update();
        });
    });
})();

/* Profile photo: click the picture to see it bigger. */
(function () {
    var trigger = document.querySelector('[data-avatar-zoom]');
    var modal = document.getElementById('fiAvatarLightbox');

    // Run only on pages that have both the clickable photo and the viewer.
    if (!trigger || !modal) {
        return;
    }

    var stage = modal.querySelector('[data-lightbox-stage]');
    var closeButtons = modal.querySelectorAll('[data-lightbox-close]');
    var closeButton = modal.querySelector('.fi-lightbox-close');

    // Show a bigger copy of the profile picture.
    function openLightbox() {
        var source = trigger.querySelector('[data-fi-avatar] img, [data-fi-avatar] svg');
        if (!source || !stage) {
            return;
        }

        stage.innerHTML = '';

        // cloneNode(true) copies the picture, so the original on the page stays untouched.
        var big = source.cloneNode(true);
        big.removeAttribute('width');     // let the CSS decide the size
        big.removeAttribute('height');
        big.removeAttribute('loading');   // load immediately, not "lazy"
        big.classList.add('fi-lightbox-media');
        big.addEventListener('click', closeLightbox); // clicking the picture closes it too
        stage.appendChild(big);

        modal.hidden = false;
        document.body.style.overflow = 'hidden'; // stop the page from scrolling behind
        closeButton.focus();                     // keyboard users start on the close button
    }

    function closeLightbox() {
        modal.hidden = true;
        document.body.style.overflow = '';
        trigger.focus();                         // go back to the photo that was clicked
    }

    trigger.addEventListener('click', openLightbox);

    closeButtons.forEach(function (button) {
        button.addEventListener('click', closeLightbox);
    });

    // Pressing Esc closes the picture.
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) {
            closeLightbox();
        }
    });
})();

/* Scroll to the first server-side error alert after a form reload. */
(function () {
    var alertEl = document.querySelector('.fi-alert.alert-danger, .fi-alert.alert-warning, .alert-danger');
    if (alertEl) {
        alertEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
})();

/* Found item photo preview */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var input = document.getElementById('item_photo');
        var container = document.getElementById('photo-preview-container');
        var preview = document.getElementById('photo-preview');

        if (!input || !container || !preview) {
            return;
        }

        var maxBytes = 5 * 1024 * 1024;
        var allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        var objectUrl = null;

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];

            input.setCustomValidity('');

            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
                objectUrl = null;
            }

            preview.src = '';
            container.classList.add('d-none');

            if (!file) {
                return;
            }

            if (allowedTypes.indexOf(file.type) === -1) {
                input.setCustomValidity('Only JPG, PNG, and WEBP images are allowed.');
                input.reportValidity();
                input.value = '';
                return;
            }

            if (file.size > maxBytes) {
                input.setCustomValidity('The maximum photo size is 5 MB.');
                input.reportValidity();
                input.value = '';
                return;
            }

            objectUrl = URL.createObjectURL(file);
            preview.src = objectUrl;
            container.classList.remove('d-none');
        });

        window.addEventListener('beforeunload', function () {
            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
            }
        });
    });
})();



// Admin edit item: preview a replacement photo before uploading.
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const photoInput = document.getElementById('photo');
        const previewContainer = document.getElementById('photo-preview-container');
        const previewImage = document.getElementById('photo-preview');

        if (!photoInput || !previewContainer || !previewImage) {
            return;
        }

        let previewUrl = null;

        photoInput.addEventListener('change', function () {
            const file = photoInput.files[0];

            if (!file) {
                previewContainer.hidden = true;
                previewImage.removeAttribute('src');
                return;
            }

            const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
            const maxSize = 5 * 1024 * 1024;

            if (!allowedTypes.includes(file.type)) {
                alert('Only JPG, PNG, and WEBP photos are allowed.');
                photoInput.value = '';
                previewContainer.hidden = true;
                return;
            }

            if (file.size > maxSize) {
                alert('The photo must not exceed 5 MB.');
                photoInput.value = '';
                previewContainer.hidden = true;
                return;
            }

            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
            }

            previewUrl = URL.createObjectURL(file);
            previewImage.src = previewUrl;
            previewContainer.hidden = false;
        });
    });
})();


