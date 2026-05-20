define([], function() {
    var byId = function(id) {
        return document.getElementById(id);
    };

    var bind = function() {
        var stateSelect = byId('jp-drivestate');
        var outcomeWrap = byId('jp-driveoutcome-wrap');
        var outcomeSelect = byId('jp-driveoutcome');

        if (!stateSelect || !outcomeWrap || !outcomeSelect) {
            return;
        }

        var sync = function() {
            var show = stateSelect.value === 'completed';
            outcomeWrap.style.display = show ? '' : 'none';
            outcomeSelect.disabled = !show;
            if (!show) {
                outcomeSelect.value = '';
            }
        };

        stateSelect.addEventListener('change', sync);
        sync();
    };

    return {
        init: function() {
            bind();
        }
    };
});
