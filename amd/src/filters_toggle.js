define([], function() {
    var restoreSavedFilterState = function() {
        var buttons = document.querySelectorAll('.jp-toggle-filters-btn');
        if (!buttons || !buttons.length) {
            return;
        }

        for (var i = 0; i < buttons.length; i++) {
            var button = buttons[i];
            var targetSelector = button.getAttribute('data-target');
            if (!targetSelector) {
                continue;
            }
            var target = document.querySelector(targetSelector);
            if (!target) {
                continue;
            }

            var storageKey = button.getAttribute('data-storage-key');
            var showText = button.getAttribute('data-show-text') || '👁️ Show Filters';

            if (storageKey) {
                try {
                    if (localStorage.getItem(storageKey) === '1') {
                        target.style.display = 'none';
                        target.classList.add('jp-collapsed');
                        button.innerHTML = showText;
                        button.setAttribute('aria-expanded', 'false');
                        button.classList.add('btn-primary');
                        button.classList.remove('btn-outline-secondary');
                    }
                } catch (e) {}
            }
        }
    };

    var bindGlobalToggle = function() {
        if (window.__jpFiltersToggleBound) {
            return;
        }
        window.__jpFiltersToggleBound = true;

        document.addEventListener('click', function(e) {
            var button = e.target.closest ? e.target.closest('.jp-toggle-filters-btn') : null;
            if (!button) {
                return;
            }

            var targetSelector = button.getAttribute('data-target');
            if (!targetSelector) {
                return;
            }
            var target = document.querySelector(targetSelector);
            if (!target) {
                return;
            }

            var storageKey = button.getAttribute('data-storage-key');
            var showText = button.getAttribute('data-show-text') || '👁️ Show Filters';
            var hideText = button.getAttribute('data-hide-text') || '👁️ Hide Filters';

            var isCollapsed = target.style.display === 'none' || target.classList.contains('jp-collapsed');
            if (isCollapsed) {
                target.style.display = '';
                target.classList.remove('jp-collapsed');
                button.innerHTML = hideText;
                button.setAttribute('aria-expanded', 'true');
                button.classList.remove('btn-primary');
                button.classList.add('btn-outline-secondary');
                if (storageKey) {
                    try { localStorage.setItem(storageKey, '0'); } catch (err) {}
                }
            } else {
                target.style.display = 'none';
                target.classList.add('jp-collapsed');
                button.innerHTML = showText;
                button.setAttribute('aria-expanded', 'false');
                button.classList.add('btn-primary');
                button.classList.remove('btn-outline-secondary');
                if (storageKey) {
                    try { localStorage.setItem(storageKey, '1'); } catch (err) {}
                }
            }
        });
    };

    return {
        init: function() {
            bindGlobalToggle();
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', restoreSavedFilterState);
            } else {
                restoreSavedFilterState();
            }
        }
    };
});
