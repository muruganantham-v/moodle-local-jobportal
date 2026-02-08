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

echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-body');
echo html_writer::tag('h4', get_string('myresumereviews', 'local_jobportal'), array('class' => 'card-title mb-3'));

echo html_writer::start_tag('form', array('method' => 'get', 'action' => $baseurl, 'class' => 'mb-3'));
echo html_writer::select($viewoptions, 'view', $viewfilter, false, array('class' => 'custom-select d-inline-block w-auto'));
echo html_writer::tag('button', get_string('filter'), array('type' => 'submit', 'class' => 'btn btn-outline-primary ml-2'));
echo html_writer::end_tag('form');

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

if (empty($assignments)) {
    echo html_writer::tag('p', get_string('noreviewsassigned', 'local_jobportal'), array('class' => 'alert alert-info'));
} else {
    if ($totalcount > $perpage) {
        $pagingurl = new moodle_url('/local/jobportal/myreviews.php', array('view' => $viewfilter));
        echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $pagingurl);
    }

    $table = new html_table();
    $table->head = array(
        get_string('applicantname', 'local_jobportal'),
        get_string('reviewassignmentstatus', 'local_jobportal'),
        get_string('resumereviewstatus', 'local_jobportal'),
        get_string('timeassigned', 'local_jobportal'),
        get_string('timereviewed', 'local_jobportal'),
        get_string('actions'),
    );
    $table->attributes['class'] = 'table table-sm table-striped table-bordered jp-table jp-data-table jp-myreviews-table';

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

        $table->data[] = array(
            fullname($assignment) . html_writer::div(s($assignment->email), 'text-muted small'),
            html_writer::tag('span', $assignmentlabel, array('class' => $assignmentbadge)),
            html_writer::tag('span', $resumelabel, array('class' => $resumebadge)),
            userdate($assignment->timeassigned, $dateformat),
            $timereviewed,
            html_writer::link(
                new moodle_url('/local/jobportal/resume_review.php', array('profileid' => (int)$assignment->profileid)),
                get_string('openreview', 'local_jobportal')
            ),
        );
    }

    echo html_writer::table($table);

    if ($totalcount > $perpage) {
        $pagingurl = new moodle_url('/local/jobportal/myreviews.php', array('view' => $viewfilter));
        echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $pagingurl);
    }
}

echo $OUTPUT->footer();
