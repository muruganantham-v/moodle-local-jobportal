<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');
require_once(__DIR__ . '/locallib.php');

require_login();

$profileid = required_param('profileid', PARAM_INT);
$context = context_system::instance();

$canassign = has_capability('local/jobportal:assignresumereviewers', $context);
$canreview = has_capability('local/jobportal:reviewresumes', $context) || has_capability('local/jobportal:viewapplications', $context);
if (!$canassign && !$canreview) {
    require_capability('local/jobportal:reviewresumes', $context);
}

$profile = $DB->get_record('local_jobportal_profiles', array('id' => (int)$profileid), '*', MUST_EXIST);
$student = $DB->get_record(
    'user',
    array('id' => (int)$profile->userid),
    'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename, email',
    MUST_EXIST
);
$baseurl = new moodle_url('/local/jobportal/resume_review.php', array('profileid' => (int)$profileid));
$resumestatusoptions = local_jobportal_get_resume_status_options();

$PAGE->set_context($context);
$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('resumereviewcenter', 'local_jobportal'));
$PAGE->set_heading(get_string('resumereviewcenter', 'local_jobportal'));
local_jobportal_require_styles();
$dateformat = '%d/%m/%Y';

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
if ($action !== '' && confirm_sesskey()) {
    $resumesignature = local_jobportal_get_profile_resume_signature((int)$profileid, $context);

    if ($action === 'assignreviewers' && $canassign) {
        if ($resumesignature === '') {
            redirect($baseurl, get_string('error:resumenotuploaded', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
        }

        $revieweroptions = local_jobportal_get_resume_reviewer_options($context);
        $allowedreviewerids = array_keys($revieweroptions);
        $reviewerids = optional_param_array('reviewerids', array(), PARAM_INT);
        $reviewerids = array_values(array_intersect(array_map('intval', $reviewerids), $allowedreviewerids));

        $assignedcount = local_jobportal_assign_resume_reviewers((int)$profileid, $reviewerids, (int)$USER->id, $context);
        if ($assignedcount > 0) {
            local_jobportal_log_resume_review_history(
                (int)$profileid,
                (int)$USER->id,
                'underreview',
                null,
                get_string('reviewersassignedfeedback', 'local_jobportal', $assignedcount),
                'assigned'
            );
        }

        $message = get_string('reviewsettingsupdated', 'local_jobportal');
        if ($assignedcount > 0) {
            $message = get_string('reviewersassigned', 'local_jobportal', $assignedcount);
        }
        redirect($baseurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
    }

    if ($action === 'submitreview' && $canreview) {
        if ($resumesignature === '') {
            redirect($baseurl, get_string('error:resumenotuploaded', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
        }

        if (!$canassign && !$DB->record_exists('local_jobportal_resume_assignments', array(
            'profileid' => (int)$profileid,
            'resumesignature' => $resumesignature,
            'reviewerid' => (int)$USER->id,
        ))) {
            redirect($baseurl, get_string('error:notassignedreviewer', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
        }

        $decision = optional_param('decision', '', PARAM_ALPHANUMEXT);
        $alloweddecisions = array('underreview', 'approved', 'needsrework');
        if (!in_array($decision, $alloweddecisions, true)) {
            redirect($baseurl, get_string('error:invalidresumestatus', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
        }

        $ratingraw = trim(optional_param('resumerating', '', PARAM_RAW_TRIMMED));
        $rating = null;
        if ($ratingraw !== '') {
            if (!preg_match('/^\d+$/', $ratingraw)) {
                redirect($baseurl, get_string('error:invalidresumerating', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
            }
            $rating = (int)$ratingraw;
            if ($rating < 1 || $rating > 5) {
                redirect($baseurl, get_string('error:invalidresumerating', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
            }
        }

        $feedback = trim(optional_param('resumefeedback', '', PARAM_TEXT));
        if ($decision === 'needsrework' && $feedback === '') {
            redirect($baseurl, get_string('error:feedbackrequiredforrework', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
        }

        local_jobportal_save_resume_reviewer_decision(
            (int)$profileid,
            (int)$USER->id,
            $decision,
            $rating,
            $feedback !== '' ? $feedback : null,
            'reviewerreview',
            $context
        );

        redirect($baseurl, get_string('resumereviewupdated', 'local_jobportal'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

$refresh = local_jobportal_refresh_profile_resume_review((int)$profileid, $context);
$profile = $refresh->profile;
$resumesignature = $refresh->resumesignature;
$summary = $refresh->summary;
$resumefile = local_jobportal_get_profile_resume_file((int)$profileid, $context);
$resumedownloadurl = null;
$resumepreviewurl = null;
$resumecanpreview = false;
if ($resumefile) {
    $resumedownloadurl = local_jobportal_get_profile_resume_url((int)$profileid, $context, true);
    if (local_jobportal_resume_file_is_previewable($resumefile)) {
        $resumecanpreview = true;
        $resumepreviewurl = local_jobportal_get_profile_resume_url((int)$profileid, $context, false);
    }
}

$assignments = local_jobportal_get_resume_assignments_for_version((int)$profileid, $resumesignature);
$assignmentstatusoptions = local_jobportal_get_resume_assignment_status_options();
$assignedreviewerids = array();
$currentuserassignment = null;
foreach ($assignments as $assignment) {
    $assignedreviewerids[(int)$assignment->reviewerid] = true;
    if ((int)$assignment->reviewerid === (int)$USER->id) {
        $currentuserassignment = $assignment;
    }
}

$historysql = "SELECT h.*, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
                 FROM {local_jobportal_resume_review_hist} h
                 JOIN {user} u ON u.id = h.userid
                WHERE h.profileid = :profileid
             ORDER BY h.timecreated DESC";
$history = $DB->get_records_sql($historysql, array('profileid' => (int)$profileid), 0, 30);

$navigationkey = $canassign ? 'resumequeue' : 'myresumereviews';

if ($resumecanpreview && $resumepreviewurl) {
    $PAGE->requires->js_init_code("
        (function() {
            var panel = document.getElementById('jp-resume-preview-panel');
            var frame = document.getElementById('jp-resume-preview-frame');
            var close = document.getElementById('jp-resume-preview-close');
            var triggers = document.querySelectorAll('.jp-resume-preview-trigger');

            triggers.forEach(function(trigger) {
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
        })();
    ");
}

echo $OUTPUT->header();
echo local_jobportal_render_navigation(
    $context,
    $navigationkey,
    array(
        array(
            'key' => 'resumereview',
            'label' => get_string('openreview', 'local_jobportal'),
            'url' => $baseurl,
        ),
    )
);

echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-body');
echo html_writer::tag('h4', fullname($student), array('class' => 'card-title mb-1'));
echo html_writer::tag('p', s($student->email), array('class' => 'text-muted mb-2'));
echo html_writer::tag('p', html_writer::tag('span', $summary->statuslabel, array('class' => $summary->statusbadge)), array('class' => 'mb-2'));
if ($resumedownloadurl) {
    if ($resumecanpreview && $resumepreviewurl) {
        echo html_writer::tag(
            'button',
            get_string('previewresume', 'local_jobportal'),
            array(
                'type' => 'button',
                'class' => 'btn btn-outline-secondary btn-sm mr-2 jp-resume-preview-trigger',
                'data-resume-url' => $resumepreviewurl->out(false),
            )
        );
    }
    echo html_writer::link($resumedownloadurl, get_string('downloadresume', 'local_jobportal'), array(
        'class' => 'btn btn-outline-primary btn-sm',
        'target' => '_blank',
        'rel' => 'noopener',
    ));
} else {
    echo html_writer::tag('p', get_string('resumenotuploaded', 'local_jobportal'), array('class' => 'alert alert-warning mb-0'));
}
echo html_writer::end_div();
echo html_writer::end_div();

if ($resumecanpreview && $resumepreviewurl) {
    echo html_writer::start_div('jp-resume-preview-panel card mb-3 d-none w-100', array('id' => 'jp-resume-preview-panel'));
    echo html_writer::start_div('card-body');
    echo html_writer::start_div('d-flex justify-content-between align-items-center mb-2');
    echo html_writer::tag('h5', get_string('previewresume', 'local_jobportal'), array('class' => 'mb-0'));
    echo html_writer::tag(
        'button',
        get_string('close', 'local_jobportal'),
        array('type' => 'button', 'id' => 'jp-resume-preview-close', 'class' => 'btn btn-sm btn-outline-secondary')
    );
    echo html_writer::end_div();
    echo html_writer::tag('iframe', '', array(
        'id' => 'jp-resume-preview-frame',
        'class' => 'jp-resume-preview-frame',
        'src' => 'about:blank',
        'title' => get_string('previewresume', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-body');
echo html_writer::tag('h5', get_string('reviewprogress', 'local_jobportal'), array('class' => 'card-title'));
echo html_writer::start_div('row');
echo html_writer::tag('div', html_writer::div(get_string('reviewersassigned', 'local_jobportal', $summary->total), 'mb-0'), array('class' => 'col-md-3 mb-2'));
echo html_writer::tag('div', html_writer::div(get_string('reviewapprovedcount', 'local_jobportal', $summary->approved), 'mb-0'), array('class' => 'col-md-3 mb-2'));
echo html_writer::tag('div', html_writer::div(get_string('reviewpendingcount', 'local_jobportal', $summary->pending), 'mb-0'), array('class' => 'col-md-3 mb-2'));
echo html_writer::tag('div', html_writer::div(get_string('reviewreworkcount', 'local_jobportal', $summary->needsrework), 'mb-0'), array('class' => 'col-md-3 mb-2'));
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

if ($canassign) {
    $revieweroptions = local_jobportal_get_resume_reviewer_options($context);

    echo html_writer::start_div('card mb-3');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h5', get_string('assignreviewers', 'local_jobportal'), array('class' => 'card-title mb-3'));

    if ($resumedownloadurl) {
        echo html_writer::start_tag('form', array('method' => 'post', 'action' => $baseurl));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'assignreviewers'));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));

        if (empty($revieweroptions)) {
            echo html_writer::tag('p', get_string('noreviewersavailable', 'local_jobportal'), array('class' => 'alert alert-warning'));
        } else {
            echo html_writer::start_div('list-group mb-3');
            foreach ($revieweroptions as $reviewerid => $reviewerlabel) {
                $checked = isset($assignedreviewerids[(int)$reviewerid]);
                $checkbox = html_writer::checkbox('reviewerids[]', (int)$reviewerid, $checked, '', array('class' => 'mr-2'));
                echo html_writer::tag(
                    'label',
                    $checkbox . s($reviewerlabel),
                    array('class' => 'list-group-item list-group-item-action mb-0')
                );
            }
            echo html_writer::end_div();
        }

        echo html_writer::tag('button', get_string('savechanges'), array('type' => 'submit', 'class' => 'btn btn-primary'));
        echo html_writer::end_tag('form');
    } else {
        echo html_writer::tag('p', get_string('resumenotuploaded', 'local_jobportal'), array('class' => 'alert alert-warning mb-0'));
    }

    echo html_writer::end_div();
    echo html_writer::end_div();
}

if ($canreview) {
    $mydecision = 'underreview';
    $myrating = '';
    $myfeedback = '';
    if ($currentuserassignment) {
        $assignmentstatus = local_jobportal_normalize_resume_assignment_status($currentuserassignment->status);
        if ($assignmentstatus === 'approved' || $assignmentstatus === 'needsrework') {
            $mydecision = $assignmentstatus;
        }
        if ($assignmentstatus === 'inreview') {
            $mydecision = 'underreview';
        }
        $myrating = $currentuserassignment->rating === null ? '' : (int)$currentuserassignment->rating;
        $myfeedback = !empty($currentuserassignment->feedback) ? $currentuserassignment->feedback : '';
    }

    $caneditdecision = $canassign || $currentuserassignment;

    echo html_writer::start_div('card mb-3');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h5', get_string('reviewerdecision', 'local_jobportal'), array('class' => 'card-title mb-3'));

    if (!$caneditdecision) {
        echo html_writer::tag('p', get_string('error:notassignedreviewer', 'local_jobportal'), array('class' => 'alert alert-warning mb-0'));
    } else if (!$resumedownloadurl) {
        echo html_writer::tag('p', get_string('resumenotuploaded', 'local_jobportal'), array('class' => 'alert alert-warning mb-0'));
    } else {
        $decisionoptions = array(
            'underreview' => $resumestatusoptions['underreview'],
            'approved' => $resumestatusoptions['approved'],
            'needsrework' => $resumestatusoptions['needsrework'],
        );

        echo html_writer::start_tag('form', array('method' => 'post', 'action' => $baseurl));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'submitreview'));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));

        echo html_writer::start_div('jp-form-card');
        echo html_writer::start_div('jp-review-inline-row mb-2');
        echo html_writer::start_div('jp-review-col-decision');
        echo html_writer::tag('label', get_string('reviewdecision', 'local_jobportal'), array('class' => 'd-block mb-1 font-weight-bold'));
        echo html_writer::select(
            $decisionoptions,
            'decision',
            $mydecision,
            false,
            array('class' => 'custom-select jp-select-control')
        );
        echo html_writer::end_div();

        echo html_writer::start_div('jp-review-col-rating');
        echo html_writer::tag('label', get_string('resumerating', 'local_jobportal'), array('class' => 'd-block mb-1 font-weight-bold'));
        echo html_writer::empty_tag('input', array(
            'type' => 'number',
            'name' => 'resumerating',
            'value' => $myrating,
            'class' => 'form-control',
            'min' => 1,
            'max' => 5,
            'step' => 1,
            'placeholder' => '1-5',
            'aria-label' => get_string('resumerating', 'local_jobportal'),
        ));
        echo html_writer::end_div();
        echo html_writer::end_div();

        echo html_writer::start_div('mb-3 w-100');
        echo html_writer::tag('label', get_string('resumefeedback', 'local_jobportal'), array('class' => 'd-block mb-1 font-weight-bold'));
        echo html_writer::tag('textarea', s($myfeedback), array(
            'name' => 'resumefeedback',
            'rows' => 2,
            'class' => 'form-control w-100',
            'placeholder' => get_string('resumefeedbackplaceholder', 'local_jobportal'),
        ));
        echo html_writer::end_div();

        echo html_writer::tag(
            'button',
            get_string('submitreview', 'local_jobportal'),
            array('type' => 'submit', 'class' => 'jp-btn-gradient')
        );
        echo html_writer::end_div();
        echo html_writer::end_tag('form');
    }

    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-body');
echo html_writer::tag('h5', get_string('assignedreviewers', 'local_jobportal'), array('class' => 'card-title mb-3'));

if (empty($assignments)) {
    echo html_writer::tag('p', get_string('noreviewersassigned', 'local_jobportal'), array('class' => 'text-muted mb-0'));
} else {
    $table = new html_table();
    $table->head = array(
        get_string('reviewer', 'local_jobportal'),
        get_string('reviewassignmentstatus', 'local_jobportal'),
        get_string('timeassigned', 'local_jobportal'),
        get_string('timereviewed', 'local_jobportal'),
        get_string('resumerating', 'local_jobportal'),
        get_string('resumefeedback', 'local_jobportal'),
    );
    $table->attributes['class'] = 'table table-sm table-striped table-bordered jp-table jp-data-table jp-assigned-reviewers-table';

    foreach ($assignments as $assignment) {
        $assignmentstatus = local_jobportal_normalize_resume_assignment_status($assignment->status);
        $assignmentlabel = isset($assignmentstatusoptions[$assignmentstatus]) ?
            $assignmentstatusoptions[$assignmentstatus] : $assignmentstatus;
        $assignmentbadge = local_jobportal_resume_assignment_badge_class($assignmentstatus);
        $timereviewed = !empty($assignment->timereviewed) ?
            userdate($assignment->timereviewed, $dateformat) : '-';

        $table->data[] = array(
            fullname($assignment) . html_writer::div(s($assignment->email), 'text-muted small'),
            html_writer::tag('span', $assignmentlabel, array('class' => $assignmentbadge)),
            userdate($assignment->timeassigned, $dateformat),
            $timereviewed,
            $assignment->rating === null ? '-' : ((int)$assignment->rating . '/5'),
            !empty($assignment->feedback) ? s($assignment->feedback) : '-',
        );
    }

    echo html_writer::table($table);
}

echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-body');
echo html_writer::tag('h5', get_string('resumereviewhistory', 'local_jobportal'), array('class' => 'card-title mb-3'));

if (empty($history)) {
    echo html_writer::tag('p', get_string('noreviewhistory', 'local_jobportal'), array('class' => 'text-muted mb-0'));
} else {
    echo html_writer::start_tag('ul', array('class' => 'list-group list-group-flush'));
    foreach ($history as $item) {
        $actionkey = 'resumeaction_' . $item->action;
        $actionlabel = get_string_manager()->string_exists($actionkey, 'local_jobportal') ?
            get_string($actionkey, 'local_jobportal') : format_string($item->action);
        $eventstatus = local_jobportal_normalize_resume_status($item->status);
        $eventstatuslabel = isset($resumestatusoptions[$eventstatus]) ? $resumestatusoptions[$eventstatus] : $eventstatus;

        $line = userdate($item->timecreated, $dateformat) .
            ' - ' . s($actionlabel) .
            ' - ' . s($eventstatuslabel) .
            ' [' . fullname($item) . ']';
        if (!empty($item->rating)) {
            $line .= ' (' . get_string('resumerating', 'local_jobportal') . ': ' . (int)$item->rating . '/5)';
        }
        if (!empty($item->feedback)) {
            $line .= ' - ' . s($item->feedback);
        }
        echo html_writer::tag('li', $line, array('class' => 'list-group-item py-1'));
    }
    echo html_writer::end_tag('ul');
}

echo html_writer::end_div();
echo html_writer::end_div();

echo $OUTPUT->footer();
