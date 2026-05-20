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
        var toggle = byId('jp-select-all');
        if (!toggle) {
            return;
        }
        toggle.addEventListener('change', function() {
            eachNode(document.querySelectorAll('.jp-app-select'), function(box) {
                box.checked = toggle.checked;
            });
        });
    };

    var bindExpandCollapse = function() {
        eachNode(document.querySelectorAll('.jp-expand-all'), function(button) {
            button.addEventListener('click', function() {
                var appid = button.getAttribute('data-appid');
                if (!appid) {
                    return;
                }
                eachNode(document.querySelectorAll('.jp-section-' + appid), function(section) {
                    section.classList.add('show');
                });
            });
        });

        eachNode(document.querySelectorAll('.jp-collapse-all'), function(button) {
            button.addEventListener('click', function() {
                var appid = button.getAttribute('data-appid');
                if (!appid) {
                    return;
                }
                eachNode(document.querySelectorAll('.jp-section-' + appid), function(section) {
                    section.classList.remove('show');
                });
            });
        });
    };

    var bindResumePreview = function() {
        var previewPanel = byId('jp-resume-preview-panel');
        var previewFrame = byId('jp-resume-preview-frame');
        var previewClose = byId('jp-resume-preview-close');

        eachNode(document.querySelectorAll('.jp-resume-preview-trigger'), function(trigger) {
            trigger.addEventListener('click', function(e) {
                e.preventDefault();
                if (!previewPanel || !previewFrame) {
                    return;
                }
                var url = trigger.getAttribute('data-resume-url');
                if (!url) {
                    return;
                }
                previewFrame.setAttribute('src', url);
                previewPanel.classList.remove('d-none');
                previewPanel.scrollIntoView({behavior: 'smooth', block: 'start'});
            });
        });

        if (previewClose) {
            previewClose.addEventListener('click', function() {
                if (previewFrame) {
                    previewFrame.setAttribute('src', 'about:blank');
                }
                if (previewPanel) {
                    previewPanel.classList.add('d-none');
                }
            });
        }
    };

    var findControl = function(form, name) {
        if (!form || !name) {
            return null;
        }
        return form.querySelector('[name="' + name + '"]');
    };

    var setControlVisible = function(control, visible) {
        if (!control) {
            return;
        }
        var wrapper = control.closest('.jp-inline-col-select, .jp-inline-col-date, .jp-inline-col-note');
        if (wrapper) {
            wrapper.style.display = visible ? '' : 'none';
        } else {
            control.style.display = visible ? '' : 'none';
        }
        if (typeof control.disabled !== 'undefined') {
            control.disabled = !visible;
        }
    };

    var setControlValue = function(control, value) {
        if (!control) {
            return;
        }
        control.value = value;
    };

    var bindScheduleControls = function(form, config, stageScheduleMap) {
        if (!form) {
            return;
        }

        var stageControl = findControl(form, config.stageField || '');
        var selectionControl = findControl(form, config.selectionField || config.stageField || '');
        var dateControl = findControl(form, config.dateField);
        var statusControl = findControl(form, config.statusField);
        var outcomeControl = findControl(form, config.outcomeField);
        var modeControl = findControl(form, config.modeField);
        var durationControl = findControl(form, config.durationField);
        var linkControl = findControl(form, config.linkField);
        var venueControl = findControl(form, config.venueField);
        if (!dateControl && !statusControl && !outcomeControl && !modeControl &&
                !durationControl && !linkControl && !venueControl) {
            return;
        }

        var sync = function() {
            var selected = true;
            if (selectionControl) {
                selected = selectionControl.value !== '';
            }

            var schedulable = false;
            if (selected) {
                if (config.forceSchedulable) {
                    schedulable = true;
                } else if (stageControl && stageControl.value !== '' &&
                        Object.prototype.hasOwnProperty.call(stageScheduleMap, stageControl.value)) {
                    schedulable = !!stageScheduleMap[stageControl.value];
                }
            }

            if (!schedulable) {
                setControlValue(statusControl, 'scheduled');
                setControlValue(outcomeControl, 'pending');
                setControlValue(modeControl, '');
                setControlValue(dateControl, '');
                setControlValue(durationControl, '');
                setControlValue(linkControl, '');
                setControlValue(venueControl, '');
            }

            var status = statusControl ? statusControl.value : 'scheduled';
            var isplanning = status === 'scheduled' || status === 'rescheduled';
            var iscompleted = status === 'completed';
            var mode = modeControl ? modeControl.value : '';

            setControlVisible(statusControl, schedulable);
            setControlVisible(dateControl, schedulable && isplanning);
            setControlVisible(durationControl, schedulable && isplanning);
            setControlVisible(modeControl, schedulable && isplanning);
            setControlVisible(outcomeControl, schedulable && iscompleted);

            if (!(schedulable && iscompleted)) {
                setControlValue(outcomeControl, 'pending');
            }
            if (!(schedulable && isplanning)) {
                setControlValue(modeControl, '');
                setControlValue(dateControl, '');
                setControlValue(durationControl, '');
            }

            var showLink = schedulable && isplanning && (mode === 'online' || mode === 'hybrid');
            var showVenue = schedulable && isplanning && (mode === 'offline' || mode === 'hybrid');
            setControlVisible(linkControl, showLink);
            setControlVisible(venueControl, showVenue);
            if (!showLink) {
                setControlValue(linkControl, '');
            }
            if (!showVenue) {
                setControlValue(venueControl, '');
            }
        };

        if (selectionControl) {
            selectionControl.addEventListener('change', sync);
        }
        if (statusControl) {
            statusControl.addEventListener('change', sync);
        }
        if (modeControl) {
            modeControl.addEventListener('change', sync);
        }
        sync();
    };

    var bindStageForms = function(stageScheduleMap) {
        var bulkForm = document.querySelector('.jp-bulk-section form[method="post"]');
        if (bulkForm) {
            bindScheduleControls(bulkForm, {
                stageField: 'stageid',
                selectionField: 'stageid',
                dateField: 'scheduleddatetime',
                statusField: 'schedulestatus',
                outcomeField: 'roundoutcome',
                modeField: 'schedulemode',
                durationField: 'scheduleduration',
                linkField: 'schedulelink',
                venueField: 'schedulevenue'
            }, stageScheduleMap);
            bindScheduleControls(bulkForm, {
                stageField: 'round_stageid',
                selectionField: 'round_stageid',
                dateField: 'round_scheduleddatetime',
                statusField: 'round_schedulestatus',
                outcomeField: 'round_roundoutcome',
                modeField: 'round_schedulemode',
                durationField: 'round_scheduleduration',
                linkField: 'round_schedulelink',
                venueField: 'round_schedulevenue'
            }, stageScheduleMap);
        }

        eachNode(document.querySelectorAll('form'), function(form) {
            var actionInput = findControl(form, 'action');
            var action = actionInput ? actionInput.value : '';
            if (action === 'changepoststage' || action === 'reopenpoststage') {
                bindScheduleControls(form, {
                    stageField: 'stageid',
                    selectionField: 'stageid',
                    dateField: 'scheduleddatetime',
                    statusField: 'schedulestatus',
                    outcomeField: 'roundoutcome',
                    modeField: 'schedulemode',
                    durationField: 'scheduleduration',
                    linkField: 'schedulelink',
                    venueField: 'schedulevenue'
                }, stageScheduleMap);
            } else if (action === 'updateroundevent') {
                bindScheduleControls(form, {
                    selectionField: 'eventid',
                    forceSchedulable: true,
                    dateField: 'scheduleddatetime',
                    statusField: 'schedulestatus',
                    outcomeField: 'roundoutcome',
                    modeField: 'schedulemode',
                    durationField: 'scheduleduration',
                    linkField: 'schedulelink',
                    venueField: 'schedulevenue'
                }, stageScheduleMap);
            }
        });
    };

    var findSelectOption = function(select, value) {
        if (!select) {
            return null;
        }
        for (var i = 0; i < select.options.length; i++) {
            if (select.options[i].value === value) {
                return select.options[i];
            }
        }
        return null;
    };

    var bindClosedRoundsToggle = function() {
        eachNode(document.querySelectorAll('.jp-round-show-closed'), function(toggle) {
            var targetid = toggle.getAttribute('data-target');
            if (!targetid) {
                return;
            }
            var select = byId(targetid);
            if (!select) {
                return;
            }
            var closedraw = toggle.getAttribute('data-closed-options') || '{}';
            var closedoptions = {};
            try {
                closedoptions = JSON.parse(closedraw);
            } catch (err) {
                closedoptions = {};
            }
            var closedids = Object.keys(closedoptions);
            var syncClosedOptions = function() {
                var showclosed = !!toggle.checked;
                var removedselected = false;
                if (showclosed) {
                    eachNode(closedids, function(id) {
                        if (!findSelectOption(select, id)) {
                            var opt = document.createElement('option');
                            opt.value = id;
                            opt.text = closedoptions[id];
                            opt.setAttribute('data-round-closed', '1');
                            select.appendChild(opt);
                        }
                    });
                    return;
                }
                eachNode(closedids, function(id) {
                    var existing = findSelectOption(select, id);
                    if (!existing) {
                        return;
                    }
                    if (existing.selected) {
                        removedselected = true;
                    }
                    existing.remove();
                });
                if (removedselected) {
                    select.value = '';
                }
            };
            toggle.addEventListener('change', syncClosedOptions);
            syncClosedOptions();
        });
    };

    return {
        init: function(stageScheduleMap) {
            if (!stageScheduleMap || typeof stageScheduleMap !== 'object') {
                stageScheduleMap = {};
            }
            bindSelectAll();
            bindExpandCollapse();
            bindResumePreview();
            bindStageForms(stageScheduleMap);
            bindClosedRoundsToggle();
        }
    };
});
