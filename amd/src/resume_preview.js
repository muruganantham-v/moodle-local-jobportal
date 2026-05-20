define([], function() {
    var byId = function(id) {
        return document.getElementById(id);
    };

    var eachNode = function(nodes, callback) {
        for (var i = 0; i < nodes.length; i++) {
            callback(nodes[i], i);
        }
    };

    var bind = function() {
        var panel = byId('jp-resume-preview-panel');
        var frame = byId('jp-resume-preview-frame');
        var close = byId('jp-resume-preview-close');
        var triggers = document.querySelectorAll('.jp-resume-preview-trigger');

        eachNode(triggers, function(trigger) {
            trigger.addEventListener('click', function(e) {
                e.preventDefault();
                if (!panel || !frame) {
                    return;
                }
                var url = trigger.getAttribute('data-resume-url');
                if (!url) {
                    return;
                }
                frame.setAttribute('src', url);
                panel.classList.remove('d-none');
                panel.scrollIntoView({behavior: 'smooth', block: 'start'});
            });
        });

        if (close) {
            close.addEventListener('click', function() {
                if (frame) {
                    frame.setAttribute('src', 'about:blank');
                }
                if (panel) {
                    panel.classList.add('d-none');
                }
            });
        }
    };

    return {
        init: function() {
            bind();
        }
    };
});
