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
    $PAGE->requires->js_call_amd('local_jobportal/resume_preview', 'init');
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

echo html_writer::start_tag('div', array('class' => 'local-jobportal-page'));

// HERO SECTION
echo html_writer::start_tag('div', array('class' => 'jp-page-hero mb-4'));
echo html_writer::start_div('container-fluid');
echo html_writer::start_div('row align-items-center');

echo html_writer::start_div('col-md-6');
echo html_writer::tag('h2', fullname($student), array('class' => 'jp-hero-title mb-1'));
echo html_writer::tag('p', s($student->email), array('class' => 'jp-hero-subtitle mb-2'));
echo html_writer::link(
    new moodle_url('/local/jobportal/student_profile.php', array('userid' => (int)$student->id)),
    '👤 ' . get_string('viewstudentprofile', 'local_jobportal'),
    array('class' => 'btn btn-outline-light btn-sm jp-action-pill mt-2')
);
echo html_writer::end_div();

echo html_writer::start_div('col-md-6 text-md-right mt-3 mt-md-0');
echo html_writer::tag('div', html_writer::tag('span', $summary->statuslabel, array('class' => $summary->statusbadge)), array('class' => 'mb-3'));

if ($resumedownloadurl) {
    echo html_writer::start_div('d-flex justify-content-md-end gap-2');
    if ($resumecanpreview && $resumepreviewurl) {
        echo html_writer::tag(
            'button',
            '👁️ ' . get_string('previewresume', 'local_jobportal'),
            array(
                'type' => 'button',
                'class' => 'btn btn-outline-light btn-sm jp-resume-preview-trigger',
                'data-resume-url' => $resumepreviewurl->out(false),
            )
        );
    }
    echo html_writer::link($resumedownloadurl, '⬇️ ' . get_string('downloadresume', 'local_jobportal'), array(
        'class' => 'btn btn-primary btn-sm',
        'target' => '_blank',
        'rel' => 'noopener',
    ));
    echo html_writer::end_div();
} else {
    echo html_writer::tag('div', get_string('resumenotuploaded', 'local_jobportal'), array('class' => 'badge badge-warning text-dark px-3 py-2'));
}
echo html_writer::end_div();

echo html_writer::end_div(); // row
echo html_writer::end_div(); // container-fluid
echo html_writer::end_tag('div'); // jp-page-hero

echo html_writer::start_div('container-fluid');

// Stats Row
echo html_writer::start_div('row mb-4');
echo html_writer::start_div('col-md-3');
echo html_writer::start_div('card bg-light border-0 text-center py-3 jp-stat-card');
echo html_writer::tag('div', $summary->total, array('class' => 'h2 mb-0 font-weight-bold'));
echo html_writer::tag('div', get_string('assignedreviewers', 'local_jobportal'), array('class' => 'small text-muted text-uppercase font-weight-bold'));
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('col-md-3');
echo html_writer::start_div('card bg-light border-0 text-center py-3 jp-stat-card');
echo html_writer::tag('div', $summary->approved, array('class' => 'h2 mb-0 font-weight-bold text-success'));
echo html_writer::tag('div', get_string('approved', 'local_jobportal'), array('class' => 'small text-muted text-uppercase font-weight-bold'));
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('col-md-3');
echo html_writer::start_div('card bg-light border-0 text-center py-3 jp-stat-card');
echo html_writer::tag('div', $summary->pending, array('class' => 'h2 mb-0 font-weight-bold text-warning'));
echo html_writer::tag('div', get_string('pending', 'local_jobportal'), array('class' => 'small text-muted text-uppercase font-weight-bold'));
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('col-md-3');
echo html_writer::start_div('card bg-light border-0 text-center py-3 jp-stat-card');
echo html_writer::tag('div', $summary->needsrework, array('class' => 'h2 mb-0 font-weight-bold text-danger'));
echo html_writer::tag('div', get_string('needsrework', 'local_jobportal'), array('class' => 'small text-muted text-uppercase font-weight-bold'));
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div(); // end stats row

echo html_writer::start_div('row');
echo html_writer::start_div('col-lg-7');

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

    echo html_writer::start_div('jp-form-section mb-4 border-primary');
    echo html_writer::tag('h5', '✍️ ' . get_string('reviewerdecision', 'local_jobportal'), array('class' => 'font-weight-bold mb-3 text-primary'));

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

        echo html_writer::start_div('row mb-3');
        echo html_writer::start_div('col-md-6');
        echo html_writer::tag('label', get_string('reviewdecision', 'local_jobportal'), array('class' => 'd-block mb-1 font-weight-bold'));
        echo html_writer::select(
            $decisionoptions,
            'decision',
            $mydecision,
            false,
            array('class' => 'custom-select jp-select-control')
        );
        echo html_writer::end_div();

        echo html_writer::start_div('col-md-6');
        echo html_writer::tag('label', get_string('resumerating', 'local_jobportal'), array('class' => 'd-block mb-1 font-weight-bold'));
        
        // Star Rating Widget
        echo html_writer::start_div('jp-star-rating-widget d-flex flex-row-reverse justify-content-end');
        for ($i = 5; $i >= 1; $i--) {
            $checked = ((int)$myrating === $i) ? true : false;
            echo html_writer::empty_tag('input', array(
                'type' => 'radio',
                'name' => 'resumerating',
                'id' => 'rating-star-' . $i,
                'value' => $i,
                'class' => 'd-none jp-star-input',
                'checked' => $checked ? 'checked' : null,
            ));
            echo html_writer::tag('label', '★', array(
                'for' => 'rating-star-' . $i,
                'class' => 'jp-star-label mb-0',
                'title' => $i . ' stars',
            ));
        }
        echo html_writer::end_div();
        echo html_writer::end_div(); // col-md-6
        echo html_writer::end_div(); // row

        echo html_writer::start_div('mb-3 w-100');
        echo html_writer::tag('label', get_string('resumefeedback', 'local_jobportal'), array('class' => 'd-block mb-1 font-weight-bold'));
        echo html_writer::tag('textarea', s($myfeedback), array(
            'name' => 'resumefeedback',
            'rows' => 4,
            'class' => 'form-control w-100',
            'placeholder' => get_string('resumefeedbackplaceholder', 'local_jobportal'),
        ));
        echo html_writer::end_div();

        echo html_writer::tag(
            'button',
            get_string('submitreview', 'local_jobportal'),
            array('type' => 'submit', 'class' => 'btn btn-primary btn-lg jp-action-pill')
        );
        echo html_writer::end_tag('form');
    }
    echo html_writer::end_div(); // jp-form-section
}

echo html_writer::end_div(); // End col-lg-7

// RIGHT COLUMN
echo html_writer::start_div('col-lg-5');
echo html_writer::start_div('jp-sticky-sidebar', array('style' => 'position: sticky; top: 20px;'));

if ($canassign) {
    $revieweroptions = local_jobportal_get_resume_reviewer_options($context);

    echo html_writer::start_div('jp-form-section mb-4 border-info');
    echo html_writer::tag('h6', '👥 ' . get_string('assignreviewers', 'local_jobportal'), array('class' => 'font-weight-bold mb-3 text-info text-uppercase'));

    if ($resumedownloadurl) {
        echo html_writer::start_tag('form', array('method' => 'post', 'action' => $baseurl));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'assignreviewers'));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));

        if (empty($revieweroptions)) {
            echo html_writer::tag('p', get_string('noreviewersavailable', 'local_jobportal'), array('class' => 'alert alert-warning small'));
        } else {
            echo html_writer::start_div('list-group mb-3 small');
            foreach ($revieweroptions as $reviewerid => $reviewerlabel) {
                $checked = isset($assignedreviewerids[(int)$reviewerid]);
                $checkbox = html_writer::checkbox('reviewerids[]', (int)$reviewerid, $checked, '', array('class' => 'mr-2'));
                echo html_writer::tag(
                    'label',
                    $checkbox . s($reviewerlabel),
                    array('class' => 'list-group-item list-group-item-action mb-0 px-2 py-1')
                );
            }
            echo html_writer::end_div();
        }

        echo html_writer::tag('button', get_string('savechanges'), array('type' => 'submit', 'class' => 'btn btn-info btn-sm jp-action-pill'));
        echo html_writer::end_tag('form');
    } else {
        echo html_writer::tag('p', get_string('resumenotuploaded', 'local_jobportal'), array('class' => 'alert alert-warning mb-0 small'));
    }
    echo html_writer::end_div();
}

echo html_writer::start_div('jp-form-section mb-4');
echo html_writer::tag('h6', get_string('assignedreviewers', 'local_jobportal'), array('class' => 'font-weight-bold mb-3 text-muted text-uppercase'));

if (empty($assignments)) {
    echo html_writer::tag('p', get_string('noreviewersassigned', 'local_jobportal'), array('class' => 'text-muted mb-0 small'));
} else {
    echo html_writer::start_tag('ul', array('class' => 'list-unstyled mb-0 small'));
    foreach ($assignments as $assignment) {
        $assignmentstatus = local_jobportal_normalize_resume_assignment_status($assignment->status);
        $assignmentbadge = local_jobportal_resume_assignment_badge_class($assignmentstatus);
        
        echo html_writer::start_tag('li', array('class' => 'mb-3 pb-3 border-bottom'));
        echo html_writer::start_div('d-flex justify-content-between align-items-center mb-1');
        echo html_writer::tag('strong', fullname($assignment), array('class' => 'd-block'));
        echo html_writer::tag('span', '', array('class' => $assignmentbadge));
        echo html_writer::end_div();
        
        if ($assignment->rating !== null) {
            echo html_writer::tag('div', 'Rating: ' . (int)$assignment->rating . '/5', array('class' => 'font-weight-bold text-warning mb-1'));
        }
        if (!empty($assignment->feedback)) {
            echo html_writer::tag('div', '"' . s($assignment->feedback) . '"', array('class' => 'text-muted font-italic'));
        }
        echo html_writer::end_tag('li');
    }
    echo html_writer::end_tag('ul');
}
echo html_writer::end_div(); // jp-form-section

echo html_writer::start_div('jp-form-section mb-4');
echo html_writer::tag('h6', get_string('resumereviewhistory', 'local_jobportal'), array('class' => 'font-weight-bold mb-3 text-muted text-uppercase'));

if (empty($history)) {
    echo html_writer::tag('p', get_string('noreviewhistory', 'local_jobportal'), array('class' => 'text-muted mb-0 small'));
} else {
    echo html_writer::start_tag('ul', array('class' => 'list-unstyled mb-0 small'));
    foreach (array_slice($history, 0, 5) as $item) { // show top 5
        $actionkey = 'resumeaction_' . $item->action;
        $actionlabel = get_string_manager()->string_exists($actionkey, 'local_jobportal') ?
            get_string($actionkey, 'local_jobportal') : format_string($item->action);
        
        echo html_writer::start_tag('li', array('class' => 'mb-2 pb-2 border-bottom'));
        echo html_writer::tag('strong', userdate($item->timecreated, $dateformat), array('class' => 'd-block text-muted'));
        echo html_writer::tag('div', s($actionlabel) . ' [' . fullname($item) . ']', array('class' => 'font-weight-bold'));
        echo html_writer::end_tag('li');
    }
    echo html_writer::end_tag('ul');
}

echo html_writer::end_div(); // jp-form-section

echo html_writer::end_div(); // sticky sidebar
echo html_writer::end_div(); // end col-lg-5
echo html_writer::end_div(); // end row

echo $OUTPUT->footer();
