define([], function() {
    var eachNode = function(nodes, callback) {
        for (var i = 0; i < nodes.length; i++) {
            callback(nodes[i], i);
        }
    };

    var initToggleButtons = function() {
        var buttons = document.querySelectorAll('.jp-toggle-filters-btn');
        if (!buttons || !buttons.length) {
            return;
        }

        eachNode(buttons, function(button) {
            if (button.dataset.jpToggleInitialized === 'true') {
                return;
            }
            button.dataset.jpToggleInitialized = 'true';

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

            // Check if there are active filters in this card
            var card = button.closest('.jp-filter-card');
            var hasActiveFilters = card && card.querySelector('.jp-filter-active-count');

            // Restore initial state from localStorage if present
            if (storageKey) {
                try {
                    var isHidden = localStorage.getItem(storageKey) === '1';
                    if (isHidden) {
                        target.classList.add('jp-collapsed');
                        button.innerHTML = showText;
                        button.setAttribute('aria-expanded', 'false');
                        button.classList.add('btn-primary');
                        button.classList.remove('btn-outline-secondary');
                    }
                } catch (e) {
                    // LocalStorage unavailable, skip
                }
            }

            button.addEventListener('click', function(e) {
                e.preventDefault();
                var isCollapsed = target.classList.contains('jp-collapsed');
                if (isCollapsed) {
                    target.classList.remove('jp-collapsed');
                    button.innerHTML = hideText;
                    button.setAttribute('aria-expanded', 'true');
                    button.classList.remove('btn-primary');
                    button.classList.add('btn-outline-secondary');
                    if (storageKey) {
                        try {
                            localStorage.setItem(storageKey, '0');
                        } catch (err) {}
                    }
                } else {
                    target.classList.add('jp-collapsed');
                    button.innerHTML = showText;
                    button.setAttribute('aria-expanded', 'false');
                    button.classList.add('btn-primary');
                    button.classList.remove('btn-outline-secondary');
                    if (storageKey) {
                        try {
                            localStorage.setItem(storageKey, '1');
                        } catch (err) {}
                    }
                }
            });
        });
    };

    return {
        init: function() {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initToggleButtons);
            } else {
                initToggleButtons();
            }
        }
    };
});
