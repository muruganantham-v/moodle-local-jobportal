<?php
// Standalone application detail rendering section extracted from applications.php.
// Variables are provided by the caller (applications.php).
    foreach ($applications as $app) {
        $shortliststatus = local_jobportal_get_application_shortlist_status($app);
        $shortlistlabel = isset($shortlistoptions[$shortliststatus]) ?
            $shortlistoptions[$shortliststatus] : get_string('pending', 'local_jobportal');

        $stage = local_jobportal_get_application_stage($app, $stages);
        $currentstageid = $stage ? (int)$stage->id : 0;
        $poststageshortname = $stage ? $stage->shortname : '';
        $currentstageisterminal = $stage ? local_jobportal_is_terminal_post_stage($poststageshortname) : false;
        $poststagename = '-';
        if ($shortliststatus === 'shortlisted') {
            $poststagename = get_string('poststagenotset', 'local_jobportal');
            if ($stage) {
                $poststagename = format_string($stage->displayname);
                if (!empty($stage->isinternal)) {
                    $poststagename .= ' (' . get_string('internalstage', 'local_jobportal') . ')';
                }
            }
        }

        $resumepreviewurl = null;
        $resumedownloadurl = null;
        $resumecanpreview = false;
        if (!empty($app->profileid)) {
            $resumefile = local_jobportal_get_profile_resume_file((int)$app->profileid, $context);
            if ($resumefile) {
                $resumedownloadurl = local_jobportal_get_profile_resume_url((int)$app->profileid, $context, true);
                if (local_jobportal_resume_file_is_previewable($resumefile)) {
                    $resumecanpreview = true;
                    $resumepreviewurl = local_jobportal_get_profile_resume_url((int)$app->profileid, $context, false);
                }
            }
        }
        $resumestatus = !empty($app->resumestatus) ? local_jobportal_normalize_resume_status($app->resumestatus) : 'notsubmitted';
        $resumestatuslabel = isset($resumestatusoptions[$resumestatus]) ? $resumestatusoptions[$resumestatus] : $resumestatus;
        $resumestatusbadge = local_jobportal_resume_status_badge_class($resumestatus);
        $resumeratinglabel = !empty($app->resumerating) ? ((int)$app->resumerating . '/5') : '-';
        $lastactivitylabel = !empty($app->lastactivityat) ? userdate((int)$app->lastactivityat, $datetimeformat) : '-';
        $resumehistory = !empty($app->profileid) && isset($resumehistorybyprofile[(int)$app->profileid]) ?
            $resumehistorybyprofile[(int)$app->profileid] : array();

        $stageeventsbyid = array();
        $stageeventcounts = array();
        $stageroundsbyeventid = array();
        if (!empty($eventsbyapp[$app->id])) {
            foreach ($eventsbyapp[$app->id] as $event) {
                $eventstageid = (int)$event->stageid;
                if (isset($poststages[$eventstageid])) {
                    if (!isset($stageeventsbyid[$eventstageid])) {
                        $stageeventsbyid[$eventstageid] = array();
                    }
                    $stageeventsbyid[$eventstageid][] = $event;
                    if (!isset($stageeventcounts[$eventstageid])) {
                        $stageeventcounts[$eventstageid] = 0;
                    }
                    $stageeventcounts[$eventstageid]++;
                    if (!empty($poststages[$eventstageid]->hasscheduledate)) {
                        $stageroundsbyeventid[(int)$event->id] = $stageeventcounts[$eventstageid];
                    }
                }
            }
        }

        $roundeventopenoptions = array();
        $roundeventclosedoptions = array();
        if (!empty($eventsbyapp[$app->id])) {
            foreach ($eventsbyapp[$app->id] as $event) {
                $eventstageid = (int)$event->stageid;
                if (empty($poststages[$eventstageid]) || empty($poststages[$eventstageid]->hasscheduledate)) {
                    continue;
                }
                $optionlabel = format_string($event->displayname);
                if (!empty($stageroundsbyeventid[(int)$event->id])) {
                    $optionlabel .= ' - ' . get_string('roundlabel', 'local_jobportal', (int)$stageroundsbyeventid[(int)$event->id]);
                }
                if (!empty($event->scheduledat)) {
                    $optionlabel .= ' - ' . userdate($event->scheduledat, $datetimeformat);
                }
                $eventstatus = !empty($event->schedulestatus) ?
                    local_jobportal_normalize_schedule_status($event->schedulestatus) : 'scheduled';
                $optionlabel .= ' - ' . local_jobportal_get_schedule_status_label($eventstatus);
                $eventoutcome = !empty($event->roundoutcome) ?
                    local_jobportal_normalize_round_outcome($event->roundoutcome) : 'pending';
                if ($eventstatus === 'completed' || $eventoutcome !== 'pending') {
                    $optionlabel .= ' - ' . local_jobportal_get_round_outcome_label($eventoutcome);
                }
                if (in_array($eventstatus, array('scheduled', 'rescheduled'), true)) {
                    $roundeventopenoptions[(int)$event->id] = $optionlabel;
                } else {
                    $roundeventclosedoptions[(int)$event->id] = $optionlabel;
                }
            }
        }

        $transitionoptions = array();
        $reopentransitionoptions = array();
        foreach ($poststageoptions as $stageidkey => $label) {
            $stageid = (int)$stageidkey;
            if (empty($poststages[$stageid])) {
                continue;
            }
            $targetstageshortname = $poststages[$stageid]->shortname;
            if (!local_jobportal_is_post_stage_transition_allowed($poststageshortname, $targetstageshortname, false)) {
                continue;
            }
            $allowreselectcurrent = ($stageid === $currentstageid) &&
                !empty($poststages[$stageid]) &&
                !empty($poststages[$stageid]->hasscheduledate);
            if ($stageid === $currentstageid && !$allowreselectcurrent) {
                continue;
            }
            if ($allowreselectcurrent) {
                $nextround = !empty($stageeventcounts[$stageid]) ? ((int)$stageeventcounts[$stageid] + 1) : 1;
                $label .= ' (' . get_string('schedulenextround', 'local_jobportal', $nextround) . ')';
            }
            $transitionoptions[$stageid] = $label;
        }
        if ($currentstageisterminal) {
            foreach ($poststageoptions as $stageidkey => $label) {
                $stageid = (int)$stageidkey;
                if (empty($poststages[$stageid])) {
                    continue;
                }
                $targetstageshortname = $poststages[$stageid]->shortname;
                if (!local_jobportal_is_post_stage_transition_allowed($poststageshortname, $targetstageshortname, true)) {
                    continue;
                }
                $reopentransitionoptions[$stageid] = $label;
            }
        }
        $shortlisttransitionoptions = array();
        foreach ($shortlistoptions as $targetshortliststatus => $targetlabel) {
            if ($targetshortliststatus === $shortliststatus) {
                continue;
            }
            if (!local_jobportal_is_shortlist_transition_allowed($shortliststatus, $targetshortliststatus)) {
                continue;
            }
            $shortlisttransitionoptions[$targetshortliststatus] = $targetlabel;
        }
        if (empty($shortlisttransitionoptions)) {
            $shortlisttransitionoptions = $shortlistoptions;
        }

        $phone = !empty($app->phone1) ? $app->phone1 : $app->phone2;
        $canmanagejobs = has_capability('local/jobportal:managejobs', $context);
        $applylockinfo = $canmanagejobs ? local_jobportal_get_student_apply_lock_info((int)$app->userid) : null;
        $applicantsectionid = 'jp-applicant-' . (int)$app->id;
        $resumesectionid = 'jp-resume-' . (int)$app->id;
        $recruitsectionid = 'jp-recruit-' . (int)$app->id;
        $notessectionid = 'jp-notes-' . (int)$app->id;

        echo html_writer::start_tag('div', array('class' => 'jp-app-card'));
        echo html_writer::start_tag('div', array('class' => 'jp-app-header'));

        echo html_writer::tag('h5', fullname($app));
        echo html_writer::tag('h6', s($app->email));
        echo html_writer::link(
            new moodle_url('/local/jobportal/student_profile.php', array('userid' => (int)$app->userid)),
            get_string('viewstudentprofile', 'local_jobportal'),
            array('class' => 'btn btn-sm btn-outline-secondary mb-2')
        );

        $statusline = html_writer::tag(
            'span',
            $shortlistlabel,
            array('class' => local_jobportal_shortlist_badge_class($shortliststatus) . ' mr-2')
        );
        if ($shortliststatus === 'shortlisted') {
            $statusline .= html_writer::tag(
                'span',
                $poststagename,
                array('class' => local_jobportal_post_stage_badge_class($poststageshortname) . ' mr-2')
            );
        }
        echo html_writer::tag(
            'p',
            $statusline .
            html_writer::tag('span', '📅 ' . get_string('appliedon', 'local_jobportal') . ': ' . userdate($app->timecreated, $dateformat), array('class' => 'text-muted')),
            array('class' => 'mb-0')
        );
        echo html_writer::end_div();

        echo html_writer::start_tag('div', array('class' => 'jp-section-btns'));
        echo html_writer::tag(
            'button',
            '👤 ' . get_string('sectionapplicantdetails', 'local_jobportal'),
            array(
                'type' => 'button',
                'class' => 'jp-section-btn',
                'data-toggle' => 'collapse',
                'data-target' => '#' . $applicantsectionid,
                'aria-controls' => $applicantsectionid,
                'aria-expanded' => 'true',
            )
        );
        echo html_writer::tag(
            'button',
            '📄 ' . get_string('sectionresumereview', 'local_jobportal'),
            array(
                'type' => 'button',
                'class' => 'jp-section-btn',
                'data-toggle' => 'collapse',
                'data-target' => '#' . $resumesectionid,
                'aria-controls' => $resumesectionid,
                'aria-expanded' => 'false',
            )
        );
        echo html_writer::tag(
            'button',
            '📋 ' . get_string('sectionrecruitment', 'local_jobportal'),
            array(
                'type' => 'button',
                'class' => 'jp-section-btn',
                'data-toggle' => 'collapse',
                'data-target' => '#' . $recruitsectionid,
                'aria-controls' => $recruitsectionid,
                'aria-expanded' => 'true',
            )
        );
        echo html_writer::tag(
            'button',
            '📝 ' . get_string('sectionnotes', 'local_jobportal'),
            array(
                'type' => 'button',
                'class' => 'jp-section-btn',
                'data-toggle' => 'collapse',
                'data-target' => '#' . $notessectionid,
                'aria-controls' => $notessectionid,
                'aria-expanded' => 'false',
            )
        );
        echo html_writer::tag(
            'button',
            '⬇ ' . get_string('expandallsections', 'local_jobportal'),
            array(
                'type' => 'button',
                'class' => 'jp-section-btn jp-expand-all',
                'data-appid' => (int)$app->id,
            )
        );
        echo html_writer::tag(
            'button',
            '⬆ ' . get_string('collapseallsections', 'local_jobportal'),
            array(
                'type' => 'button',
                'class' => 'jp-section-btn jp-collapse-all',
                'data-appid' => (int)$app->id,
            )
        );
        echo html_writer::end_tag('div');

        echo html_writer::start_tag('div', array('id' => $applicantsectionid, 'class' => 'collapse show jp-app-section jp-section-' . (int)$app->id));
        echo html_writer::start_tag('div', array('class' => 'jp-section-content'));
        echo html_writer::tag('h6', get_string('sectionapplicantdetails', 'local_jobportal'), array('class' => 'text-uppercase text-muted small mb-3'));
        
        echo html_writer::start_div('jp-info-grid');
        
        echo html_writer::start_div('jp-info-card');
        echo html_writer::div(get_string('applicantphone', 'local_jobportal'), 'jp-info-label');
        echo html_writer::div(s($phone ?: '-'), 'jp-info-value');
        echo html_writer::end_div();
        
        echo html_writer::start_div('jp-info-card');
        echo html_writer::div(get_string('applicantcity', 'local_jobportal'), 'jp-info-label');
        echo html_writer::div(s($app->city ?: '-'), 'jp-info-value');
        echo html_writer::end_div();
        
        echo html_writer::start_div('jp-info-card');
        echo html_writer::div(get_string('totalapplications', 'local_jobportal'), 'jp-info-label');
        echo html_writer::div((int)$app->totalapplications, 'jp-info-value');
        echo html_writer::end_div();

        echo html_writer::start_div('jp-info-card');
        echo html_writer::div(get_string('lastactivity', 'local_jobportal'), 'jp-info-label');
        echo html_writer::div(s($lastactivitylabel), 'jp-info-value');
        echo html_writer::end_div();
        
        echo html_writer::start_div('jp-info-card');
        echo html_writer::div(get_string('resumereviewstatus', 'local_jobportal'), 'jp-info-label');
        echo html_writer::div(html_writer::tag('span', $resumestatuslabel, array('class' => $resumestatusbadge)), 'jp-info-value');
        echo html_writer::end_div();

        echo html_writer::start_div('jp-info-card');
        echo html_writer::div(get_string('resumerating', 'local_jobportal'), 'jp-info-label');
        echo html_writer::div(s($resumeratinglabel), 'jp-info-value');
        echo html_writer::end_div();
        
        if ($resumedownloadurl) {
            echo html_writer::start_div('jp-info-card');
            echo html_writer::div(get_string('resume', 'local_jobportal'), 'jp-info-label');
            $resumebuttons = '';
            if ($resumecanpreview && $resumepreviewurl) {
                $resumebuttons .= html_writer::tag(
                    'button',
                    '👁️',
                    array(
                        'type' => 'button',
                        'class' => 'btn btn-outline-secondary jp-icon-action jp-resume-preview-trigger',
                        'data-resume-url' => $resumepreviewurl->out(false),
                        'title' => get_string('previewresume', 'local_jobportal'),
                        'aria-label' => get_string('previewresume', 'local_jobportal'),
                    )
                );
            }
            if ($resumedownloadurl) {
                $resumebuttons .= html_writer::link(
                    $resumedownloadurl,
                    '⬇️',
                    array(
                        'class' => 'btn btn-outline-primary jp-icon-action',
                        'target' => '_blank',
                        'rel' => 'noopener',
                        'title' => get_string('downloadresume', 'local_jobportal'),
                        'aria-label' => get_string('downloadresume', 'local_jobportal'),
                    )
                );
            }
            echo html_writer::div(
                $resumebuttons,
                'jp-info-value jp-resume-actions'
            );
            echo html_writer::end_div();
        }
        
        echo html_writer::end_div();
        if (!empty($app->portfolio)) {
            echo html_writer::tag('h6', get_string('portfolio', 'local_jobportal') . ':', array('class' => 'mt-3 mb-2'));
            $portfoliolink = s($app->portfolio);
            if (filter_var($app->portfolio, FILTER_VALIDATE_URL)) {
                $portfoliolink = html_writer::link(new moodle_url($app->portfolio), '🔗 ' . s($app->portfolio), array('target' => '_blank', 'rel' => 'noopener', 'class' => 'btn btn-outline-info btn-sm'));
            }
            echo html_writer::tag('p', $portfoliolink);
        }
        echo html_writer::end_div();

        if (!empty($app->skills)) {
            echo html_writer::tag('h6', get_string('skills', 'local_jobportal') . ':', array('class' => 'mt-2'));
            echo html_writer::tag('p', format_text($app->skills, FORMAT_PLAIN), array('class' => 'mb-2'));
        }
        if (!empty($app->experience)) {
            echo html_writer::tag('h6', get_string('experience', 'local_jobportal') . ':', array('class' => 'mt-2'));
            echo html_writer::tag('p', format_text($app->experience, FORMAT_PLAIN), array('class' => 'mb-2'));
        }
        if (!empty($app->education)) {
            echo html_writer::tag('h6', get_string('education', 'local_jobportal') . ':', array('class' => 'mt-2'));
            echo html_writer::tag('p', format_text($app->education, FORMAT_PLAIN), array('class' => 'mb-2'));
        }
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');

        echo html_writer::start_tag('div', array('id' => $resumesectionid, 'class' => 'collapse jp-app-section jp-section-' . (int)$app->id));
        echo html_writer::start_tag('div', array('class' => 'border rounded p-3 mb-3'));
        echo html_writer::tag('h6', get_string('resumereview', 'local_jobportal'), array('class' => 'mt-3'));
        if (!empty($app->resumerating)) {
            echo html_writer::tag('p', html_writer::tag('strong', get_string('resumerating', 'local_jobportal') . ': ') . (int)$app->resumerating . '/5', array('class' => 'mb-1'));
        }
        if (!empty($app->resumefeedback)) {
            echo html_writer::tag('p', html_writer::tag('strong', get_string('resumefeedback', 'local_jobportal') . ': ') . s($app->resumefeedback), array('class' => 'mb-1'));
        }
        if (!empty($app->resumereviewedat)) {
            echo html_writer::tag('p', html_writer::tag('strong', get_string('lastreviewed', 'local_jobportal') . ': ') . userdate($app->resumereviewedat, $dateformat), array('class' => 'mb-2'));
        }

        if (!empty($app->profileid) && $resumedownloadurl) {
            echo html_writer::start_tag('form', array('method' => 'post', 'action' => $pageactionurl, 'class' => 'mb-3'));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'jobid', 'value' => $jobid));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'page', 'value' => $page));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'showapp', 'value' => $showapp));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'standalone', 'value' => $standalonevalue));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'appid', 'value' => $app->id));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'updateresumereview'));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
            echo html_writer::start_tag('div', array('class' => 'row'));
            echo html_writer::start_tag('div', array('class' => 'col-md-4 mb-2'));
            echo html_writer::select($resumestatusoptions, 'resumestatus', $resumestatus, false, array('class' => 'custom-select custom-select-sm'));
            echo html_writer::end_tag('div');
            echo html_writer::start_tag('div', array('class' => 'col-md-2 mb-2'));
            echo html_writer::empty_tag('input', array(
                'type' => 'number',
                'name' => 'resumerating',
                'value' => !empty($app->resumerating) ? (int)$app->resumerating : '',
                'class' => 'form-control form-control-sm',
                'min' => 1,
                'max' => 5,
                'step' => 1,
                'placeholder' => '1-5',
            ));
            echo html_writer::end_tag('div');
            echo html_writer::start_tag('div', array('class' => 'col-md-6 mb-2'));
            echo html_writer::tag('textarea', !empty($app->resumefeedback) ? s($app->resumefeedback) : '', array(
                'name' => 'resumefeedback',
                'rows' => 2,
                'class' => 'form-control form-control-sm',
                'placeholder' => get_string('resumefeedbackplaceholder', 'local_jobportal'),
            ));
            echo html_writer::end_tag('div');
            echo html_writer::end_tag('div');
            echo html_writer::tag('button', get_string('updateresumereview', 'local_jobportal'), array('type' => 'submit', 'class' => 'btn btn-outline-primary btn-sm'));
            echo html_writer::end_tag('form');
        } else {
            echo html_writer::tag('p', get_string('resumenotuploaded', 'local_jobportal'), array('class' => 'text-muted mb-2'));
        }

        echo html_writer::tag('h6', get_string('resumereviewhistory', 'local_jobportal'), array('class' => 'mt-2'));
        if (empty($resumehistory)) {
            echo html_writer::tag('p', get_string('noreviewhistory', 'local_jobportal'), array('class' => 'text-muted'));
        } else {
            echo html_writer::start_tag('ul', array('class' => 'list-group list-group-flush mb-3'));
            $historyshown = 0;
            foreach ($resumehistory as $historyitem) {
                $actionkey = 'resumeaction_' . $historyitem->action;
                $actionlabel = get_string_manager()->string_exists($actionkey, 'local_jobportal') ?
                    get_string($actionkey, 'local_jobportal') : format_string($historyitem->action);
                $hstatus = local_jobportal_normalize_resume_status($historyitem->status);
                $hstatuslabel = isset($resumestatusoptions[$hstatus]) ? $resumestatusoptions[$hstatus] : $hstatus;
                $line = userdate($historyitem->timecreated, $dateformat) .
                    ' - ' . s($actionlabel) .
                    ' - ' . s($hstatuslabel) .
                    ' [' . fullname($historyitem) . ']';
                if (!empty($historyitem->rating)) {
                    $line .= ' (' . get_string('resumerating', 'local_jobportal') . ': ' . (int)$historyitem->rating . '/5)';
                }
                if (!empty($historyitem->feedback)) {
                    $line .= ' - ' . s($historyitem->feedback);
                }
                echo html_writer::tag('li', $line, array('class' => 'list-group-item py-1'));
                $historyshown++;
                if ($historyshown >= 5) {
                    break;
                }
            }
            echo html_writer::end_tag('ul');
        }
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');

        echo html_writer::start_tag('div', array('id' => $recruitsectionid, 'class' => 'collapse show jp-app-section jp-section-' . (int)$app->id));
        echo html_writer::start_tag('div', array('class' => 'jp-section-content'));
        echo html_writer::tag('h6', get_string('recruitmentchecklist', 'local_jobportal'), array('class' => 'jp-form-title'));
        if ($shortliststatus !== 'shortlisted') {
            echo html_writer::tag('p', 'ℹ️ ' . get_string('poststageonlyaftershortlist', 'local_jobportal'), array('class' => 'alert alert-info'));
        } else if (empty($activepoststages)) {
            echo html_writer::tag('p', 'ℹ️ ' . get_string('nostagesconfigured', 'local_jobportal'), array('class' => 'alert alert-info'));
        } else {
            $terminaldecision = in_array($poststageshortname, array('accepted', 'rejected'), true) ? $poststageshortname : '';
            echo html_writer::start_tag('ul', array('class' => 'jp-checklist'));
            foreach ($activepoststages as $stageitem) {
                if ($terminaldecision !== '' &&
                        in_array($stageitem->shortname, array('accepted', 'rejected'), true) &&
                        $stageitem->shortname !== $terminaldecision) {
                    continue;
                }
                $stageid = (int)$stageitem->id;
                $stageevents = !empty($stageeventsbyid[$stageid]) ? $stageeventsbyid[$stageid] : array();
                $hasevents = !empty($stageevents);
                $iscurrent = !empty($currentstageid) && $currentstageid === $stageid;
                $checkliststate = 'notstarted';
                $isterminaldecision = in_array($stageitem->shortname, array('accepted', 'rejected'), true);

                if ($isterminaldecision) {
                    if ($iscurrent) {
                        $checkliststate = 'completed';
                    }
                } else if (!empty($stageitem->hasscheduledate)) {
                    if ($hasevents) {
                        $lastevent = end($stageevents);
                        reset($stageevents);
                        $lasteventstatus = !empty($lastevent->schedulestatus) ?
                            local_jobportal_normalize_schedule_status($lastevent->schedulestatus) : 'scheduled';
                        if (in_array($lasteventstatus, array('cancelled', 'noshow'), true)) {
                            $checkliststate = 'cancelled';
                        } else if ($lasteventstatus === 'completed') {
                            $checkliststate = 'completed';
                        } else {
                            $checkliststate = 'inprogress';
                        }
                    } else if ($iscurrent) {
                        $checkliststate = 'inprogress';
                    }
                } else if ($hasevents || $iscurrent) {
                    if ($iscurrent && !local_jobportal_is_terminal_post_stage($stageitem->shortname)) {
                        $checkliststate = 'inprogress';
                    } else {
                        $checkliststate = 'completed';
                    }
                }

                $stateicon = '⬜';
                $statelabel = get_string('checkliststate_notstarted', 'local_jobportal');
                if ($checkliststate === 'inprogress') {
                    $stateicon = '⏳';
                    $statelabel = get_string('checkliststate_inprogress', 'local_jobportal');
                } else if ($checkliststate === 'completed') {
                    $stateicon = '✅';
                    $statelabel = get_string('checkliststate_completed', 'local_jobportal');
                } else if ($checkliststate === 'cancelled') {
                    $stateicon = '⛔';
                    $statelabel = get_string('checkliststate_cancelled', 'local_jobportal');
                }

                $itemclass = 'jp-checklist-item jp-checklist-' . $checkliststate;
                $stagelabel = format_string($stageitem->displayname);
                $stageeventcount = !empty($stageeventcounts[$stageid]) ? (int)$stageeventcounts[$stageid] : 0;
                if (!empty($stageitem->hasscheduledate) && $stageeventcount > 0) {
                    $stagelabel .= ' (' . get_string('roundscount', 'local_jobportal', $stageeventcount) . ')';
                }
                if (!empty($stageitem->isinternal)) {
                    $stagelabel .= ' (' . get_string('internalstage', 'local_jobportal') . ')';
                }
                echo html_writer::tag('li',
                    html_writer::div(
                        html_writer::span($stateicon, 'jp-checklist-icon') .
                        html_writer::span($stagelabel, 'jp-checklist-stage-label'),
                        'jp-checklist-main'
                    ) .
                    html_writer::span($statelabel, 'jp-checklist-state-pill'),
                    array('class' => $itemclass));
            }
            echo html_writer::end_tag('ul');
        }

        echo html_writer::tag('h6', get_string('stagetimeline', 'local_jobportal'), array('class' => 'jp-form-title mt-4'));
        if (empty($eventsbyapp[$app->id])) {
            echo html_writer::tag('p', 'ℹ️ ' . get_string('nostagehistory', 'local_jobportal'), array('class' => 'alert alert-info'));
        } else {
            echo html_writer::start_tag('div', array('class' => 'jp-timeline'));
            foreach ($eventsbyapp[$app->id] as $event) {
                echo html_writer::start_div('jp-timeline-item');
                echo html_writer::tag('strong', userdate($event->timecreated, $datetimeformat));
                $eventtext = format_string($event->displayname);
                if (!empty($stageroundsbyeventid[(int)$event->id])) {
                    $eventtext .= ' - ' . get_string('roundlabel', 'local_jobportal', (int)$stageroundsbyeventid[(int)$event->id]);
                }
                if (!empty($event->isinternal)) {
                    $eventtext .= ' (' . get_string('internalstage', 'local_jobportal') . ')';
                }
                echo html_writer::tag('div', $eventtext, array('class' => 'mt-1'));
                if (!empty($event->hasscheduledate)) {
                    if (!empty($event->scheduledat)) {
                        echo html_writer::div('📅 ' . get_string('scheduledfor', 'local_jobportal') . ': ' .
                            userdate($event->scheduledat, $datetimeformat), 'text-primary mt-1');
                    }
                    if (!empty($event->schedulestatus)) {
                        echo html_writer::div(
                            get_string(
                                'schedulestatusvalue',
                                'local_jobportal',
                                local_jobportal_get_schedule_status_label($event->schedulestatus)
                            ),
                            'text-primary mt-1'
                        );
                    }
                    $eventoutcome = !empty($event->roundoutcome) ? local_jobportal_normalize_round_outcome($event->roundoutcome) : 'pending';
                    $eventstatus = !empty($event->schedulestatus) ? local_jobportal_normalize_schedule_status($event->schedulestatus) : 'scheduled';
                    if ($eventstatus === 'completed' || $eventoutcome !== 'pending') {
                        echo html_writer::div(
                            get_string(
                                'roundoutcomevalue',
                                'local_jobportal',
                                local_jobportal_get_round_outcome_label($eventoutcome)
                            ),
                            'text-primary mt-1'
                        );
                    }
                    if (!empty($event->schedulemode)) {
                        echo html_writer::div(
                            get_string(
                                'schedulemodevalue',
                                'local_jobportal',
                                local_jobportal_get_schedule_mode_label($event->schedulemode)
                            ),
                            'text-primary mt-1'
                        );
                    }
                    if (!empty($event->scheduleduration)) {
                        echo html_writer::div(
                            get_string('scheduledurationvalue', 'local_jobportal', (int)$event->scheduleduration),
                            'text-primary mt-1'
                        );
                    }
                    if (!empty($event->schedulevenue)) {
                        echo html_writer::div(
                            get_string('schedulevenuevalue', 'local_jobportal', s($event->schedulevenue)),
                            'text-primary mt-1'
                        );
                    }
                    if (!empty($event->schedulelink)) {
                        $rawlink = trim((string)$event->schedulelink);
                        if (preg_match('#^https?://#i', $rawlink)) {
                            $linkhtml = html_writer::link(
                                $rawlink,
                                s($rawlink),
                                array('target' => '_blank', 'rel' => 'noopener')
                            );
                        } else {
                            $linkhtml = s($rawlink);
                        }
                        echo html_writer::div(
                            get_string('schedulelink', 'local_jobportal') . ': ' . $linkhtml,
                            'text-primary mt-1'
                        );
                    }
                }
                if (!empty($event->notes)) {
                    echo html_writer::div(s($event->notes), 'text-muted mt-1');
                }
                echo html_writer::div('[' . fullname($event) . ']', 'text-muted small mt-1');
                echo html_writer::end_div();
            }
            echo html_writer::end_tag('div');
        }

        $collapseshortlistform = ($shortliststatus === 'shortlisted' && !empty($poststageshortname));
        if ($collapseshortlistform) {
            $summary = get_string('shortliststatus', 'local_jobportal') . ': ' . s($shortlistlabel);
            if (!empty($poststagename) && $poststagename !== get_string('poststagenotset', 'local_jobportal')) {
                $summary .= ' | ' . get_string('postshortliststage', 'local_jobportal') . ': ' . s($poststagename);
            }
            echo html_writer::start_tag('details', array('class' => 'jp-collapsible-history mb-3'));
            echo html_writer::tag('summary', $summary, array('class' => 'jp-collapsible-summary'));
        }

        echo html_writer::start_div('jp-form-card');
        echo html_writer::start_tag('form', array('method' => 'post', 'action' => $pageactionurl));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'jobid', 'value' => $jobid));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'page', 'value' => $page));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'showapp', 'value' => $showapp));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'standalone', 'value' => $standalonevalue));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'appid', 'value' => $app->id));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'changeshortlist'));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
        echo html_writer::tag('label', get_string('shortliststatus', 'local_jobportal'), array('class' => 'jp-form-title'));
        echo html_writer::start_div('jp-inline-row mb-3');
        echo html_writer::start_div('jp-inline-col-select');
        echo html_writer::select($shortlisttransitionoptions, 'shortliststatus', '', false, array('class' => 'custom-select jp-select-control'));
        echo html_writer::end_div();
        echo html_writer::start_div('jp-inline-col-note');
        echo html_writer::tag('textarea', '', array(
            'name' => 'shortlistnote',
            'rows' => 2,
            'class' => 'form-control',
            'placeholder' => get_string('shortlistnoteplaceholder', 'local_jobportal'),
        ));
        echo html_writer::end_div();
        echo html_writer::end_div();
        echo html_writer::tag('button', get_string('updateshortliststatus', 'local_jobportal'), array('type' => 'submit', 'class' => 'jp-btn-gradient'));
        echo html_writer::end_tag('form');
        echo html_writer::end_div();
        if ($collapseshortlistform) {
            echo html_writer::end_tag('details');
        }

        $collapseterminalactions = ($shortliststatus === 'shortlisted' && $currentstageisterminal);
        if ($shortliststatus === 'shortlisted' && $currentstageisterminal) {
            echo html_writer::tag('p', 'ℹ️ ' . get_string('reopenstagehelp', 'local_jobportal'), array('class' => 'alert alert-warning'));
            if (!empty($reopentransitionoptions)) {
                if ($collapseterminalactions) {
                    echo html_writer::start_tag('details', array('class' => 'jp-collapsible-history mb-3'));
                    echo html_writer::tag('summary', get_string('reopenstage', 'local_jobportal'), array('class' => 'jp-collapsible-summary'));
                }
                echo html_writer::start_div('jp-form-card');
                echo html_writer::start_tag('form', array('method' => 'post', 'action' => $pageactionurl));
                echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'jobid', 'value' => $jobid));
                echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'page', 'value' => $page));
                echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'showapp', 'value' => $showapp));
                echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'standalone', 'value' => $standalonevalue));
                echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'appid', 'value' => $app->id));
                echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'reopenpoststage'));
                echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
                echo html_writer::tag('label', get_string('reopenstage', 'local_jobportal'), array('class' => 'jp-form-title'));
                echo html_writer::start_div('jp-inline-row mb-3');
                echo html_writer::start_div('jp-inline-col-select');
                echo html_writer::select(
                    $reopentransitionoptions,
                    'stageid',
                    '',
                    array('' => get_string('selectstage', 'local_jobportal')),
                    array('class' => 'custom-select jp-select-control')
                );
                echo html_writer::end_div();
                echo html_writer::start_div('jp-inline-col-date');
                echo html_writer::empty_tag('input', array('type' => 'datetime-local', 'name' => 'scheduleddatetime', 'class' => 'form-control'));
                echo html_writer::end_div();
                echo html_writer::start_div('jp-inline-col-select');
                echo local_jobportal_render_select_with_tooltip(
                    $schedulestatusoptions,
                    'schedulestatus',
                    'scheduled',
                    false,
                    array('class' => 'custom-select jp-select-control'),
                    get_string('schedulestatus', 'local_jobportal'),
                    get_string('schedulestatustooltip', 'local_jobportal')
                );
                echo html_writer::end_div();
                echo html_writer::start_div('jp-inline-col-select');
                echo local_jobportal_render_select_with_tooltip(
                    $roundoutcomeoptions,
                    'roundoutcome',
                    'pending',
                    false,
                    array('class' => 'custom-select jp-select-control'),
                    get_string('roundoutcome', 'local_jobportal'),
                    get_string('roundoutcometooltip', 'local_jobportal')
                );
                echo html_writer::end_div();
                echo html_writer::start_div('jp-inline-col-select');
                echo html_writer::select(
                    $schedulemodeoptions,
                    'schedulemode',
                    '',
                    array('' => get_string('schedulemode', 'local_jobportal')),
                    array('class' => 'custom-select jp-select-control')
                );
                echo html_writer::end_div();
                echo html_writer::start_div('jp-inline-col-date');
                echo html_writer::empty_tag('input', array(
                    'type' => 'number',
                    'name' => 'scheduleduration',
                    'class' => 'form-control',
                    'min' => 1,
                    'step' => 5,
                    'placeholder' => get_string('scheduledurationminutes', 'local_jobportal'),
                ));
                echo html_writer::end_div();
                echo html_writer::end_div();
                echo html_writer::start_div('jp-inline-row mb-3');
                echo html_writer::start_div('jp-inline-col-note');
                echo html_writer::empty_tag('input', array(
                    'type' => 'text',
                    'name' => 'schedulelink',
                    'class' => 'form-control',
                    'placeholder' => get_string('schedulelink', 'local_jobportal'),
                ));
                echo html_writer::end_div();
                echo html_writer::start_div('jp-inline-col-note');
                echo html_writer::empty_tag('input', array(
                    'type' => 'text',
                    'name' => 'schedulevenue',
                    'class' => 'form-control',
                    'placeholder' => get_string('schedulevenue', 'local_jobportal'),
                ));
                echo html_writer::end_div();
                echo html_writer::end_div();
                echo html_writer::start_div('jp-inline-row mb-3');
                echo html_writer::start_div('jp-inline-col-note');
                echo html_writer::tag('textarea', '', array(
                    'name' => 'reopennote',
                    'rows' => 2,
                    'class' => 'form-control',
                    'placeholder' => get_string('reopennoteplaceholder', 'local_jobportal'),
                ));
                echo html_writer::end_div();
                echo html_writer::end_div();
                echo html_writer::tag('button', get_string('reopenapplication', 'local_jobportal'), array('type' => 'submit', 'class' => 'jp-btn-gradient'));
                echo html_writer::end_tag('form');
                echo html_writer::end_div();
                if ($collapseterminalactions) {
                    echo html_writer::end_tag('details');
                }
            }
        } else if ($shortliststatus === 'shortlisted' && !empty($transitionoptions)) {
            echo html_writer::start_div('jp-form-card');
            echo html_writer::start_tag('form', array('method' => 'post', 'action' => $pageactionurl));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'jobid', 'value' => $jobid));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'page', 'value' => $page));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'showapp', 'value' => $showapp));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'standalone', 'value' => $standalonevalue));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'appid', 'value' => $app->id));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'changepoststage'));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
            echo html_writer::tag('label', get_string('postshortliststage', 'local_jobportal'), array('class' => 'jp-form-title'));
            echo html_writer::div(get_string('multipleroundshelp', 'local_jobportal'), 'text-muted small mb-2');
            echo html_writer::start_div('jp-inline-row mb-3');
            echo html_writer::start_div('jp-inline-col-select');
            echo html_writer::select(
                $transitionoptions,
                'stageid',
                '',
                array('' => get_string('selectstage', 'local_jobportal')),
                array('class' => 'custom-select jp-select-control')
            );
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-col-date');
            echo html_writer::empty_tag('input', array('type' => 'datetime-local', 'name' => 'scheduleddatetime', 'class' => 'form-control'));
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-col-select');
            echo local_jobportal_render_select_with_tooltip(
                $schedulestatusoptions,
                'schedulestatus',
                'scheduled',
                false,
                array('class' => 'custom-select jp-select-control'),
                get_string('schedulestatus', 'local_jobportal'),
                get_string('schedulestatustooltip', 'local_jobportal')
            );
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-col-select');
            echo local_jobportal_render_select_with_tooltip(
                $roundoutcomeoptions,
                'roundoutcome',
                'pending',
                false,
                array('class' => 'custom-select jp-select-control'),
                get_string('roundoutcome', 'local_jobportal'),
                get_string('roundoutcometooltip', 'local_jobportal')
            );
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-col-select');
            echo html_writer::select(
                $schedulemodeoptions,
                'schedulemode',
                '',
                array('' => get_string('schedulemode', 'local_jobportal')),
                array('class' => 'custom-select jp-select-control')
            );
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-col-date');
            echo html_writer::empty_tag('input', array(
                'type' => 'number',
                'name' => 'scheduleduration',
                'class' => 'form-control',
                'min' => 1,
                'step' => 5,
                'placeholder' => get_string('scheduledurationminutes', 'local_jobportal'),
            ));
            echo html_writer::end_div();
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-row mb-3');
            echo html_writer::start_div('jp-inline-col-note');
            echo html_writer::empty_tag('input', array(
                'type' => 'text',
                'name' => 'schedulelink',
                'class' => 'form-control',
                'placeholder' => get_string('schedulelink', 'local_jobportal'),
            ));
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-col-note');
            echo html_writer::empty_tag('input', array(
                'type' => 'text',
                'name' => 'schedulevenue',
                'class' => 'form-control',
                'placeholder' => get_string('schedulevenue', 'local_jobportal'),
            ));
            echo html_writer::end_div();
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-row mb-3');
            echo html_writer::start_div('jp-inline-col-note');
            echo html_writer::tag('textarea', '', array(
                'name' => 'stagenote',
                'rows' => 2,
                'class' => 'form-control',
                'placeholder' => get_string('stagenoteplaceholder', 'local_jobportal'),
            ));
            echo html_writer::end_div();
            echo html_writer::end_div();
            echo html_writer::tag('button', get_string('updatestage', 'local_jobportal'), array('type' => 'submit', 'class' => 'jp-btn-gradient'));
            echo html_writer::end_tag('form');
            echo html_writer::end_div();
        } else if ($shortliststatus === 'shortlisted') {
            echo html_writer::tag('p', 'ℹ️ ' . get_string('notransitionsavailable', 'local_jobportal'), array('class' => 'alert alert-info'));
        } else if ($shortliststatus !== 'shortlisted') {
            echo html_writer::tag('p', 'ℹ️ ' . get_string('poststageonlyaftershortlist', 'local_jobportal'), array('class' => 'alert alert-info'));
        }

        if ($shortliststatus === 'shortlisted' && (!empty($roundeventopenoptions) || !empty($roundeventclosedoptions))) {
            if ($collapseterminalactions) {
                echo html_writer::start_tag('details', array('class' => 'jp-collapsible-history mb-3'));
                echo html_writer::tag('summary', get_string('updateroundevent', 'local_jobportal'), array('class' => 'jp-collapsible-summary'));
            }
            echo html_writer::start_div('jp-form-card');
            echo html_writer::start_tag('form', array('method' => 'post', 'action' => $pageactionurl));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'jobid', 'value' => $jobid));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'page', 'value' => $page));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'showapp', 'value' => $showapp));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'standalone', 'value' => $standalonevalue));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'appid', 'value' => $app->id));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'updateroundevent'));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
            echo html_writer::tag('label', get_string('updateroundevent', 'local_jobportal'), array('class' => 'jp-form-title'));
            echo html_writer::div(get_string('roundselectorhelp', 'local_jobportal'), 'text-muted small mb-2');
            $roundselectid = 'jp-round-event-select-' . (int)$app->id;
            $roundtoggleid = 'jp-round-show-closed-' . (int)$app->id;
            echo html_writer::start_div('jp-inline-row mb-3');
            echo html_writer::start_div('jp-inline-col-note');
            echo html_writer::select(
                $roundeventopenoptions,
                'eventid',
                '',
                array('' => get_string('selectroundevent', 'local_jobportal')),
                array('class' => 'custom-select jp-select-control', 'id' => $roundselectid)
            );
            echo html_writer::end_div();
            echo html_writer::end_div();
            if (!empty($roundeventclosedoptions)) {
                $closedoptionsjson = json_encode($roundeventclosedoptions);
                if ($closedoptionsjson === false) {
                    $closedoptionsjson = '{}';
                }
                echo html_writer::start_div('mb-3');
                echo html_writer::empty_tag('input', array(
                    'type' => 'checkbox',
                    'id' => $roundtoggleid,
                    'class' => 'mr-2 jp-round-show-closed',
                    'data-target' => $roundselectid,
                    'data-closed-options' => $closedoptionsjson,
                ));
                echo html_writer::tag(
                    'label',
                    get_string('showclosedrounds', 'local_jobportal'),
                    array('for' => $roundtoggleid, 'class' => 'mb-0')
                );
                echo html_writer::end_div();
            }
            echo html_writer::start_div('jp-inline-row mb-3');
            echo html_writer::start_div('jp-inline-col-date');
            echo html_writer::empty_tag('input', array('type' => 'datetime-local', 'name' => 'scheduleddatetime', 'class' => 'form-control'));
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-col-select');
            echo local_jobportal_render_select_with_tooltip(
                $schedulestatusoptions,
                'schedulestatus',
                'scheduled',
                false,
                array('class' => 'custom-select jp-select-control'),
                get_string('schedulestatus', 'local_jobportal'),
                get_string('schedulestatustooltip', 'local_jobportal')
            );
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-col-select');
            echo local_jobportal_render_select_with_tooltip(
                $roundoutcomeoptions,
                'roundoutcome',
                'pending',
                false,
                array('class' => 'custom-select jp-select-control'),
                get_string('roundoutcome', 'local_jobportal'),
                get_string('roundoutcometooltip', 'local_jobportal')
            );
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-col-select');
            echo html_writer::select(
                $schedulemodeoptions,
                'schedulemode',
                '',
                array('' => get_string('schedulemode', 'local_jobportal')),
                array('class' => 'custom-select jp-select-control')
            );
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-col-date');
            echo html_writer::empty_tag('input', array(
                'type' => 'number',
                'name' => 'scheduleduration',
                'class' => 'form-control',
                'min' => 1,
                'step' => 5,
                'placeholder' => get_string('scheduledurationminutes', 'local_jobportal'),
            ));
            echo html_writer::end_div();
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-row mb-3');
            echo html_writer::start_div('jp-inline-col-note');
            echo html_writer::empty_tag('input', array(
                'type' => 'text',
                'name' => 'schedulelink',
                'class' => 'form-control',
                'placeholder' => get_string('schedulelink', 'local_jobportal'),
            ));
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-col-note');
            echo html_writer::empty_tag('input', array(
                'type' => 'text',
                'name' => 'schedulevenue',
                'class' => 'form-control',
                'placeholder' => get_string('schedulevenue', 'local_jobportal'),
            ));
            echo html_writer::end_div();
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-row mb-3');
            echo html_writer::start_div('jp-inline-col-note');
            echo html_writer::tag('textarea', '', array(
                'name' => 'roundnote',
                'rows' => 2,
                'class' => 'form-control',
                'placeholder' => get_string('roundnoteplaceholder', 'local_jobportal'),
            ));
            echo html_writer::end_div();
            echo html_writer::end_div();
            echo html_writer::tag('button', get_string('updateroundevent', 'local_jobportal'), array('type' => 'submit', 'class' => 'jp-btn-gradient'));
            echo html_writer::end_tag('form');
            echo html_writer::end_div();
            if ($collapseterminalactions) {
                echo html_writer::end_tag('details');
            }
        } else if ($shortliststatus === 'shortlisted') {
            echo html_writer::tag('p', 'ℹ️ ' . get_string('noroundeventsavailable', 'local_jobportal'), array('class' => 'alert alert-info'));
        }

        if ($canmanagejobs && $applylockinfo) {
            $statuslabel = get_string('applylockstatus_open', 'local_jobportal');
            $statusclass = 'badge badge-success';
            if (!empty($applylockinfo->manualblockactive)) {
                $statuslabel = get_string('applylockstatus_manualblock', 'local_jobportal');
                $statusclass = 'badge badge-danger';
            } else if (!empty($applylockinfo->locked)) {
                $statuslabel = get_string('applylockstatus_locked', 'local_jobportal');
                $statusclass = 'badge badge-danger';
            } else if (!empty($applylockinfo->overrideactive)) {
                $statuslabel = get_string('applylockstatus_override', 'local_jobportal');
                $statusclass = 'badge badge-warning';
            }

            $triggerinfo = '';
            if (!empty($applylockinfo->manualblockactive)) {
                $triggerinfo = get_string('applylocktrigger_manualblock', 'local_jobportal');
            } else if (!empty($applylockinfo->lockreason) && $applylockinfo->lockreason === 'noshow') {
                $triggerinfo = get_string(
                    'applylocktrigger_noshow',
                    'local_jobportal',
                    (object)array('jobid' => (int)$applylockinfo->triggerjobid)
                );
            } else if (!empty($applylockinfo->triggerstatus)) {
                $triggerinfo = get_string(
                    'applylocktrigger',
                    'local_jobportal',
                    (object)array(
                        'stage' => $applylockinfo->triggerstatuslabel,
                        'jobid' => (int)$applylockinfo->triggerjobid,
                    )
                );
            }

            $overrideenabled = !empty($applylockinfo->overrideactive);
            $overrideexpiresvalue = ($overrideenabled && !empty($applylockinfo->overrideexpiresat) && $applylockinfo->overrideexpiresat > 0) ?
                date('Y-m-d\TH:i', (int)$applylockinfo->overrideexpiresat) : '';
            $overridereasonvalue = !empty($applylockinfo->overridereason) ? $applylockinfo->overridereason : '';
            $manualblockenabled = !empty($applylockinfo->manualblockactive);
            $manualblockexpiresvalue = ($manualblockenabled && !empty($applylockinfo->manualblockexpiresat) && $applylockinfo->manualblockexpiresat > 0) ?
                date('Y-m-d\TH:i', (int)$applylockinfo->manualblockexpiresat) : '';
            $manualblockreasonvalue = !empty($applylockinfo->manualblockreason) ? $applylockinfo->manualblockreason : '';

            echo html_writer::start_div('jp-form-card mt-3');
            echo html_writer::tag('label', get_string('applicationeligibilityoverride', 'local_jobportal'), array('class' => 'jp-form-title'));
            echo html_writer::div(get_string('applicationeligibilityhelp', 'local_jobportal'), 'text-muted small mb-2');
            echo html_writer::div(html_writer::tag('span', $statuslabel, array('class' => $statusclass)), 'mb-2');
            if ($triggerinfo !== '') {
                echo html_writer::div($triggerinfo, 'text-muted small mb-2');
            }
            if (!empty($applylockinfo->manualblockactive) && !empty($applylockinfo->manualblockreason)) {
                echo html_writer::div(s($applylockinfo->manualblockreason), 'text-muted small mb-2');
            }

            echo html_writer::start_tag('form', array('method' => 'post', 'action' => $pageactionurl));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'jobid', 'value' => $jobid));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'page', 'value' => $page));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'showapp', 'value' => $showapp));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'standalone', 'value' => $standalonevalue));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'appid', 'value' => $app->id));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'updateapplyoverride'));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));

            echo html_writer::start_div('form-check mb-3');
            echo html_writer::checkbox(
                'enableoverride',
                1,
                $overrideenabled,
                get_string('enableapplyoverride', 'local_jobportal'),
                array('class' => 'form-check-input', 'id' => 'jp-enable-override-' . (int)$app->id)
            );
            echo html_writer::end_div();

            echo html_writer::start_div('jp-inline-row mb-3');
            echo html_writer::start_div('jp-inline-col-date');
            echo html_writer::tag(
                'label',
                get_string('applyoverrideexpires', 'local_jobportal'),
                array('class' => 'small text-muted d-block')
            );
            echo html_writer::empty_tag('input', array(
                'type' => 'datetime-local',
                'name' => 'overrideexpires',
                'value' => $overrideexpiresvalue,
                'class' => 'form-control',
            ));
            echo html_writer::end_div();
            echo html_writer::end_div();

            echo html_writer::start_div('jp-inline-row mb-3');
            echo html_writer::start_div('jp-inline-col-note');
            echo html_writer::tag(
                'label',
                get_string('applyoverridereason', 'local_jobportal'),
                array('class' => 'small text-muted d-block')
            );
            echo html_writer::tag('textarea', s($overridereasonvalue), array(
                'name' => 'overridereason',
                'rows' => 2,
                'class' => 'form-control',
                'placeholder' => get_string('applyoverridereasonplaceholder', 'local_jobportal'),
            ));
            echo html_writer::end_div();
            echo html_writer::end_div();

            echo html_writer::empty_tag('hr', array('class' => 'my-3'));

            echo html_writer::start_div('form-check mb-3');
            echo html_writer::checkbox(
                'enablemanualblock',
                1,
                $manualblockenabled,
                get_string('enableapplymanualblock', 'local_jobportal'),
                array('class' => 'form-check-input', 'id' => 'jp-enable-manual-block-' . (int)$app->id)
            );
            echo html_writer::end_div();

            echo html_writer::start_div('jp-inline-row mb-3');
            echo html_writer::start_div('jp-inline-col-date');
            echo html_writer::tag(
                'label',
                get_string('applymanualblockexpires', 'local_jobportal'),
                array('class' => 'small text-muted d-block')
            );
            echo html_writer::empty_tag('input', array(
                'type' => 'datetime-local',
                'name' => 'manualblockexpires',
                'value' => $manualblockexpiresvalue,
                'class' => 'form-control',
            ));
            echo html_writer::end_div();
            echo html_writer::end_div();

            echo html_writer::start_div('jp-inline-row mb-3');
            echo html_writer::start_div('jp-inline-col-note');
            echo html_writer::tag(
                'label',
                get_string('applymanualblockreason', 'local_jobportal'),
                array('class' => 'small text-muted d-block')
            );
            echo html_writer::tag('textarea', s($manualblockreasonvalue), array(
                'name' => 'manualblockreason',
                'rows' => 2,
                'class' => 'form-control',
                'placeholder' => get_string('applymanualblockreasonplaceholder', 'local_jobportal'),
            ));
            echo html_writer::end_div();
            echo html_writer::end_div();

            echo html_writer::tag(
                'button',
                get_string('saveapplyoverride', 'local_jobportal'),
                array('type' => 'submit', 'class' => 'jp-btn-gradient')
            );
            echo html_writer::end_tag('form');
            echo html_writer::end_div();
        }
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');

        echo html_writer::start_tag('div', array('id' => $notessectionid, 'class' => 'collapse jp-app-section jp-section-' . (int)$app->id));
        echo html_writer::start_tag('div', array('class' => 'jp-section-content'));
        echo html_writer::tag('h6', get_string('recruiternotes', 'local_jobportal'), array('class' => 'jp-form-title'));
        if (empty($notesbyapp[$app->id])) {
            echo html_writer::tag('p', 'ℹ️ ' . get_string('nonotesyet', 'local_jobportal'), array('class' => 'alert alert-info'));
        } else {
            foreach ($notesbyapp[$app->id] as $note) {
                echo html_writer::start_div('jp-note-item');
                echo html_writer::div(
                    userdate($note->timecreated, $dateformat) . ' - ' . fullname($note),
                    'jp-note-meta'
                );
                echo html_writer::div(s($note->note), 'jp-note-text');
                echo html_writer::end_div();
            }
        }

        echo html_writer::start_div('jp-form-card');
        echo html_writer::start_tag('form', array('method' => 'post', 'action' => $pageactionurl));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'jobid', 'value' => $jobid));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'page', 'value' => $page));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'showapp', 'value' => $showapp));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'standalone', 'value' => $standalonevalue));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'appid', 'value' => $app->id));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'addnote'));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
        echo html_writer::tag('textarea', '', array(
            'name' => 'note',
            'rows' => 2,
            'class' => 'form-control mb-3',
            'placeholder' => get_string('addnoteplaceholder', 'local_jobportal'),
        ));
        echo html_writer::tag('button', '➕ ' . get_string('addnote', 'local_jobportal'), array('type' => 'submit', 'class' => 'jp-btn-gradient'));
        echo html_writer::end_tag('form');
        echo html_writer::end_div();
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');

        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');
    }
