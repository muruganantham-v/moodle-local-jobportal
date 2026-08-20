define([], function() {
    var byId = function(id) {
        return document.getElementById(id);
    };

    var eachNode = function(nodes, callback) {
        for (var i = 0; i < nodes.length; i++) {
            callback(nodes[i], i);
        }
    };

    var bindSelectAll = function() {
        var master = byId('jp-select-all');
        if (!master) {
            return;
        }

        master.addEventListener('change', function() {
            eachNode(document.querySelectorAll('.jp-job-select'), function(checkbox) {
                checkbox.checked = master.checked;
            });
        });
    };

    var bindPresetReset = function() {
        var presetInput = byId('jp-preset');
        var manualFilterIds = [
            'jp-search', 'jp-companyid', 'jp-jobstatus', 'jp-jobtype',
            'jp-listedfrom', 'jp-listedto', 'jp-deadlinefrom', 'jp-deadlineto',
            'jp-hasapps', 'jp-staledays', 'jp-salarymode', 'jp-salarymin', 'jp-salarymax'
        ];

        eachNode(manualFilterIds, function(id) {
            var field = byId(id);
            if (!field) {
                return;
            }

            var clearPreset = function() {
                if (presetInput) {
                    presetInput.value = '';
                }
            };
            field.addEventListener('change', clearPreset);
            field.addEventListener('input', clearPreset);
        });
    };

    var bindConditionalFilters = function() {
        var presetInput = byId('jp-preset');
        var statusSelect = byId('jp-jobstatus');
        var staleDaysWrap = byId('jp-staledays-wrap');
        var salaryModeSelect = byId('jp-salarymode');
        var salaryMinWrap = byId('jp-salarymin-wrap');
        var salaryMaxWrap = byId('jp-salarymax-wrap');
        var salaryMinInput = byId('jp-salarymin');
        var salaryMaxInput = byId('jp-salarymax');

        var syncStaleDaysVisibility = function() {
            if (!statusSelect || !staleDaysWrap) {
                return;
            }
            var presetValue = presetInput ? presetInput.value : '';
            var show = statusSelect.value === 'stale' || presetValue === 'stale14' ||
                presetValue === 'noapps14' || presetValue === 'noactivity14';
            staleDaysWrap.style.display = show ? '' : 'none';
        };

        var syncSalaryVisibility = function() {
            if (!salaryModeSelect || !salaryMinWrap || !salaryMaxWrap) {
                return;
            }
            var mode = salaryModeSelect.value;
            var showMin = mode === 'gt' || mode === 'between';
            var showMax = mode === 'lt' || mode === 'between';
            salaryMinWrap.style.display = showMin ? '' : 'none';
            salaryMaxWrap.style.display = showMax ? '' : 'none';
            if (salaryMinInput) {
                salaryMinInput.disabled = !showMin;
            }
            if (salaryMaxInput) {
                salaryMaxInput.disabled = !showMax;
            }
        };

        if (statusSelect) {
            statusSelect.addEventListener('change', syncStaleDaysVisibility);
        }
        if (salaryModeSelect) {
            salaryModeSelect.addEventListener('change', syncSalaryVisibility);
        }

        syncStaleDaysVisibility();
        syncSalaryVisibility();
    };

    var bindColumnSearch = function() {
        var columnSearch = byId('jp-column-search');
        if (!columnSearch) {
            return;
        }

        columnSearch.addEventListener('input', function() {
            var term = columnSearch.value.toLowerCase().trim();
            eachNode(document.querySelectorAll('.jp-column-item'), function(item) {
                var label = item.getAttribute('data-col-label') || '';
                item.style.display = label.indexOf(term) !== -1 ? '' : 'none';
            });
        });
    };

    var bindFilterToggle = function() {
        var button = document.querySelector('#jp-jobs-filter-card .jp-toggle-filters-btn');
        var wrap = byId('jp-jobs-filter-content-wrap');
        if (!button || !wrap) {
            return;
        }

        try {
            if (localStorage.getItem('jp_jobs_filters_hidden') === '1') {
                wrap.style.display = 'none';
                wrap.classList.add('jp-collapsed');
                button.innerHTML = button.getAttribute('data-show-text') || '👁️ Show Filters';
                button.classList.add('btn-primary');
                button.classList.remove('btn-outline-secondary');
                button.setAttribute('aria-expanded', 'false');
            }
        } catch (e) {}

        button.addEventListener('click', function(e) {
            e.preventDefault();
            if (window.jpToggleFilters) {
                window.jpToggleFilters(
                    'jp-jobs-filter-content-wrap',
                    button,
                    'jp_jobs_filters_hidden',
                    button.getAttribute('data-show-text'),
                    button.getAttribute('data-hide-text')
                );
            }
        });
    };

    return {
        init: function() {
            bindSelectAll();
            bindPresetReset();
            bindConditionalFilters();
            bindColumnSearch();
            bindFilterToggle();
        }
    };
});
