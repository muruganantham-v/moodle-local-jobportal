<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');
require_once(__DIR__ . '/locallib.php');

require_login();

$context = context_system::instance();
require_capability('local/jobportal:reviewresumes', $context);

$viewfilter = optional_param('view', 'pending', PARAM_ALPHANUMEXT);
$page = optional_param('page', 0, PARAM_INT);
if ($page < 0) {
    $page = 0;
}
$perpage = 20;

$viewoptions = array(
    'pending' => get_string('reviewview_pending', 'local_jobportal'),
    'completed' => get_string('reviewview_completed', 'local_jobportal'),
    'all' => get_string('reviewview_all', 'local_jobportal'),
);
if (!isset($viewoptions[$viewfilter])) {
    $viewfilter = 'pending';
}

$baseurl = new moodle_url('/local/jobportal/myreviews.php');
$urlparams = array('view' => $viewfilter);
if ($page > 0) {
    $urlparams['page'] = $page;
}

$PAGE->set_context($context);
$PAGE->set_url($baseurl, $urlparams);
$PAGE->set_title(get_string('myresumereviews', 'local_jobportal'));
$PAGE->set_heading(get_string('myresumereviews', 'local_jobportal'));
local_jobportal_require_styles();
$dateformat = '%d/%m/%Y';

$sql = "SELECT a.*, p.userid AS studentid, p.resumeapprovalmode, p.resumestatus,
               u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.email
          FROM {local_jobportal_resume_assignments} a
          JOIN {local_jobportal_profiles} p ON p.id = a.profileid
          JOIN {user} u ON u.id = p.userid
         WHERE a.reviewerid = :reviewerid
      ORDER BY a.timemodified DESC, a.id DESC";
$rawassignments = $DB->get_records_sql($sql, array('reviewerid' => (int)$USER->id));

$currentresumesignatures = array();
$filteredassignments = array();
$pendingcount = 0;
$completedcount = 0;
foreach ($rawassignments as $assignment) {
    $profileid = (int)$assignment->profileid;
    if (!isset($currentresumesignatures[$profileid])) {
        $currentresumesignatures[$profileid] = local_jobportal_get_profile_resume_signature($profileid, $context);
    }

    if ($assignment->resumesignature !== $currentresumesignatures[$profileid]) {
        continue;
    }

    $assignmentstatus = local_jobportal_normalize_resume_assignment_status($assignment->status);
    $ispending = in_array($assignmentstatus, array('assigned', 'inreview'), true);
    $iscompleted = in_array($assignmentstatus, array('approved', 'needsrework'), true);

    if ($ispending) {
        $pendingcount++;
    }
    if ($iscompleted) {
        $completedcount++;
    }

    if ($viewfilter === 'pending' && !$ispending) {
        continue;
    }
    if ($viewfilter === 'completed' && !$iscompleted) {
        continue;
    }

    $filteredassignments[] = $assignment;
}

$totalcount = count($filteredassignments);
$assignments = array_slice($filteredassignments, $page * $perpage, $perpage);
$assignmentstatusoptions = local_jobportal_get_resume_assignment_status_options();
$resumestatusoptions = local_jobportal_get_resume_status_options();

echo $OUTPUT->header();
echo local_jobportal_render_navigation($context, 'myresumereviews');

echo html_writer::start_div('card mb-3 jp-filter-card', array('id' => 'jp-myreviews-filter-card'));
echo html_writer::start_div('card-body');
echo html_writer::start_div('d-flex justify-content-between align-items-center mb-3');
echo html_writer::tag('h4', get_string('myresumereviews', 'local_jobportal'), array('class' => 'card-title mb-0'));
echo local_jobportal_render_filter_toggle_button('jp-myreviews-filter-content-wrap', 'jp_myreviews_filters_hidden');
echo html_writer::end_div();

echo html_writer::start_div('jp-filter-content-wrap', array('id' => 'jp-myreviews-filter-content-wrap'));
echo html_writer::start_tag('form', array('method' => 'get', 'action' => $baseurl, 'class' => 'mb-3'));
echo html_writer::select($viewoptions, 'view', $viewfilter, false, array('class' => 'custom-select d-inline-block w-auto'));
echo html_writer::tag('button', get_string('filter'), array('type' => 'submit', 'class' => 'btn btn-outline-primary ml-2'));
echo html_writer::end_tag('form');
echo html_writer::end_div(); // jp-filter-content-wrap

echo html_writer::start_div('row');
echo html_writer::tag(
    'div',
    html_writer::div(get_string('reviewview_pending', 'local_jobportal'), 'text-muted') .
    html_writer::div((int)$pendingcount, 'h4 mb-0'),
    array('class' => 'col-md-4 mb-2')
);
echo html_writer::tag(
    'div',
    html_writer::div(get_string('reviewview_completed', 'local_jobportal'), 'text-muted') .
    html_writer::div((int)$completedcount, 'h4 mb-0'),
    array('class' => 'col-md-4 mb-2')
);
echo html_writer::tag(
    'div',
    html_writer::div(get_string('total', 'moodle'), 'text-muted') .
    html_writer::div((int)($pendingcount + $completedcount), 'h4 mb-0'),
    array('class' => 'col-md-4 mb-2')
);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_tag('div', array('class' => 'local-jobportal-page'));
echo html_writer::start_div('container-fluid py-4');

if (empty($assignments)) {
    echo html_writer::tag('p', get_string('noreviewsassigned', 'local_jobportal'), array('class' => 'alert alert-info'));
} else {
    if ($totalcount > $perpage) {
        $pagingurl = new moodle_url('/local/jobportal/myreviews.php', array('view' => $viewfilter));
        echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $pagingurl);
    }

    echo html_writer::start_div('row jp-review-grid');
    foreach ($assignments as $assignment) {
        $assignmentstatus = local_jobportal_normalize_resume_assignment_status($assignment->status);
        $assignmentlabel = isset($assignmentstatusoptions[$assignmentstatus]) ?
            $assignmentstatusoptions[$assignmentstatus] : $assignmentstatus;
        $assignmentbadge = local_jobportal_resume_assignment_badge_class($assignmentstatus);

        $resumestatus = local_jobportal_normalize_resume_status($assignment->resumestatus);
        $resumelabel = isset($resumestatusoptions[$resumestatus]) ? $resumestatusoptions[$resumestatus] : $resumestatus;
        $resumebadge = local_jobportal_resume_status_badge_class($resumestatus);

        $timereviewed = '-';
        if (!empty($assignment->timereviewed)) {
            $timereviewed = userdate($assignment->timereviewed, $dateformat);
        }

        echo html_writer::start_div('col-md-6 col-lg-4 mb-4');
        echo html_writer::start_div('card h-100 jp-review-card shadow-sm border-0');
        
        echo html_writer::start_div('card-header bg-white border-bottom-0 pt-4 pb-0 d-flex justify-content-between align-items-center');
        echo html_writer::tag('span', $assignmentlabel, array('class' => $assignmentbadge));
        echo html_writer::tag('span', $resumelabel, array('class' => $resumebadge));
        echo html_writer::end_div();

        echo html_writer::start_div('card-body');
        echo html_writer::tag('h5', fullname($assignment), array('class' => 'card-title font-weight-bold mb-1'));
        echo html_writer::tag('div', s($assignment->email), array('class' => 'text-muted small mb-3'));
        
        echo html_writer::start_div('d-flex flex-column gap-2 mb-3 small');
        echo html_writer::div(
            html_writer::tag('strong', get_string('timeassigned', 'local_jobportal') . ': ', array('class' => 'text-muted')) .
            userdate($assignment->timeassigned, $dateformat)
        );
        echo html_writer::div(
            html_writer::tag('strong', get_string('timereviewed', 'local_jobportal') . ': ', array('class' => 'text-muted')) .
            $timereviewed
        );
        echo html_writer::end_div();
        
        echo html_writer::end_div();

        echo html_writer::start_div('card-footer bg-white border-top-0 pb-4 d-flex gap-2');
        echo html_writer::link(
            new moodle_url('/local/jobportal/resume_review.php', array('profileid' => (int)$assignment->profileid)),
            '👁️ ' . get_string('openreview', 'local_jobportal'),
            array('class' => 'btn btn-primary btn-sm flex-grow-1 jp-action-pill')
        );
        echo html_writer::link(
            new moodle_url('/local/jobportal/student_profile.php', array('userid' => (int)$assignment->studentid)),
            '👤 Profile',
            array('class' => 'btn btn-outline-secondary btn-sm jp-action-pill')
        );
        echo html_writer::end_div();

        echo html_writer::end_div();
        echo html_writer::end_div();
    }
    echo html_writer::end_div(); // End grid

    if ($totalcount > $perpage) {
        $pagingurl = new moodle_url('/local/jobportal/myreviews.php', array('view' => $viewfilter));
        echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $pagingurl);
    }
}
echo html_writer::end_div();
echo html_writer::end_tag('div');

echo $OUTPUT->footer();
