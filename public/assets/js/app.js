
(function () {
    'use strict';

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
                var message = submitter && submitter.getAttribute('data-confirm');
                if (message && !window.confirm(message)) {
                    event.preventDefault();
                    return;
                }

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
