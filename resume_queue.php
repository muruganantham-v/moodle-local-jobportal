<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');
require_once(__DIR__ . '/locallib.php');

require_login();

$context = context_system::instance();
require_capability('local/jobportal:assignresumereviewers', $context);

$statusfilter = optional_param('status', 'all', PARAM_ALPHANUMEXT);
$search = trim(optional_param('search', '', PARAM_TEXT));
$page = optional_param('page', 0, PARAM_INT);
if ($page < 0) {
    $page = 0;
}
$perpage = 20;

$resumestatusoptions = local_jobportal_get_resume_status_options();
$statusoptions = array('all' => get_string('allstatuses', 'local_jobportal'));
foreach ($resumestatusoptions as $statuskey => $statuslabel) {
    if ($statuskey === 'notsubmitted') {
        continue;
    }
    $statusoptions[$statuskey] = $statuslabel;
}
if (!isset($statusoptions[$statusfilter])) {
    $statusfilter = 'all';
}

$baseurl = new moodle_url('/local/jobportal/resume_queue.php');
$urlparams = array('status' => $statusfilter);
if ($search !== '') {
    $urlparams['search'] = $search;
}
if ($page > 0) {
    $urlparams['page'] = $page;
}

$PAGE->set_context($context);
$PAGE->set_url($baseurl, $urlparams);
$PAGE->set_title(get_string('resumequeue', 'local_jobportal'));
$PAGE->set_heading(get_string('resumequeue', 'local_jobportal'));
local_jobportal_require_styles();
$dateformat = '%d/%m/%Y';

$where = " WHERE p.resumestatus <> :notsubmitted";
$params = array('notsubmitted' => 'notsubmitted');
if ($statusfilter !== 'all') {
    $where .= " AND p.resumestatus = :statusfilter";
    $params['statusfilter'] = $statusfilter;
}
if ($search !== '') {
    $where .= " AND (" .
        $DB->sql_like('u.firstname', ':search1', false) .
        " OR " . $DB->sql_like('u.lastname', ':search2', false) .
        " OR " . $DB->sql_like('u.email', ':search3', false) .
        ")";
    $params['search1'] = '%' . $search . '%';
    $params['search2'] = '%' . $search . '%';
    $params['search3'] = '%' . $search . '%';
}

$fromsql = " FROM {local_jobportal_profiles} p
             JOIN {user} u ON u.id = p.userid" . $where;
$totalsql = "SELECT COUNT(1) " . $fromsql;
$totalprofiles = (int)$DB->count_records_sql($totalsql, $params);

$sql = "SELECT p.*, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.email
          " . $fromsql . "
      ORDER BY p.timemodified DESC, p.id DESC";
$profiles = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);

$countsql = "SELECT resumestatus, COUNT(1) AS totalcount
               FROM {local_jobportal_profiles}
              WHERE resumestatus <> :notsubmitted
           GROUP BY resumestatus";
$statuscounts = array(
    'submitted' => 0,
    'underreview' => 0,
    'needsrework' => 0,
    'approved' => 0,
);
foreach ($DB->get_records_sql($countsql, array('notsubmitted' => 'notsubmitted')) as $row) {
    $statuskey = local_jobportal_normalize_resume_status($row->resumestatus);
    if (isset($statuscounts[$statuskey])) {
        $statuscounts[$statuskey] = (int)$row->totalcount;
    }
}

echo $OUTPUT->header();
echo local_jobportal_render_navigation($context, 'resumequeue');

echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-body');
echo html_writer::tag('h4', get_string('resumequeue', 'local_jobportal'), array('class' => 'card-title mb-3'));

echo html_writer::start_tag('form', array('method' => 'get', 'action' => $baseurl, 'class' => 'mb-3'));
echo html_writer::start_div('row');
echo html_writer::start_div('col-md-4 mb-2');
echo html_writer::empty_tag('input', array(
    'type' => 'text',
    'name' => 'search',
    'value' => $search,
    'placeholder' => get_string('search'),
    'class' => 'form-control',
));
echo html_writer::end_div();
echo html_writer::start_div('col-md-3 mb-2');
echo html_writer::select($statusoptions, 'status', $statusfilter, false, array('class' => 'custom-select'));
echo html_writer::end_div();
echo html_writer::start_div('col-md-5 mb-2');
echo html_writer::tag('button', get_string('filter'), array('type' => 'submit', 'class' => 'btn btn-outline-primary mr-2'));
echo html_writer::link($baseurl, get_string('resetfilters', 'local_jobportal'), array('class' => 'btn btn-outline-secondary'));
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_tag('form');

echo html_writer::start_div('row');
echo html_writer::tag(
    'div',
    html_writer::div(get_string('resumestatus_submitted', 'local_jobportal'), 'text-muted') .
    html_writer::div((int)$statuscounts['submitted'], 'h4 mb-0'),
    array('class' => 'col-md-3 col-sm-6 mb-2')
);
echo html_writer::tag(
    'div',
    html_writer::div(get_string('resumestatus_underreview', 'local_jobportal'), 'text-muted') .
    html_writer::div((int)$statuscounts['underreview'], 'h4 mb-0'),
    array('class' => 'col-md-3 col-sm-6 mb-2')
);
echo html_writer::tag(
    'div',
    html_writer::div(get_string('resumestatus_needsrework', 'local_jobportal'), 'text-muted') .
    html_writer::div((int)$statuscounts['needsrework'], 'h4 mb-0'),
    array('class' => 'col-md-3 col-sm-6 mb-2')
);
echo html_writer::tag(
    'div',
    html_writer::div(get_string('resumestatus_approved', 'local_jobportal'), 'text-muted') .
    html_writer::div((int)$statuscounts['approved'], 'h4 mb-0'),
    array('class' => 'col-md-3 col-sm-6 mb-2')
);
echo html_writer::end_div();

echo html_writer::end_div();
echo html_writer::end_div();

if (empty($profiles)) {
    echo html_writer::tag('p', get_string('noreviewqueueitems', 'local_jobportal'), array('class' => 'alert alert-info'));
} else {
    if ($totalprofiles > $perpage) {
        $pagingurl = new moodle_url('/local/jobportal/resume_queue.php', array(
            'status' => $statusfilter,
            'search' => $search,
        ));
        echo $OUTPUT->paging_bar($totalprofiles, $page, $perpage, $pagingurl);
    }

    $table = new html_table();
    $table->head = array(
        get_string('applicantname', 'local_jobportal'),
        get_string('profileupdatedon', 'local_jobportal'),
        get_string('resumereviewstatus', 'local_jobportal'),
        get_string('assignedreviewers', 'local_jobportal'),
        get_string('lastreviewed', 'local_jobportal'),
        get_string('actions'),
    );
    $table->attributes['class'] = 'table table-sm table-striped table-bordered jp-table jp-data-table jp-resume-queue-table';

    foreach ($profiles as $profile) {
        $resumesignature = local_jobportal_get_profile_resume_signature((int)$profile->id, $context);
        $summary = local_jobportal_get_resume_assignment_summary($profile, $resumesignature);
        $statuslabel = isset($resumestatusoptions[$summary->status]) ? $resumestatusoptions[$summary->status] : $summary->status;
        $statusbadge = local_jobportal_resume_status_badge_class($summary->status);
        $assignments = local_jobportal_get_resume_assignments_for_version((int)$profile->id, $resumesignature);

        $reviewernames = array();
        foreach ($assignments as $assignment) {
            $reviewernames[fullname($assignment)] = true;
        }
        if (!empty($reviewernames)) {
            $reviewerslabel = implode(', ', array_keys($reviewernames));
        } else {
            $reviewerslabel = get_string('noreviewersassigned', 'local_jobportal');
        }

        $reviewedlabel = '-';
        if (!empty($profile->resumereviewedat)) {
            $reviewedlabel = userdate($profile->resumereviewedat, $dateformat);
        }
        $profileupdatedlabel = '-';
        if (!empty($profile->timemodified)) {
            $profileupdatedlabel = userdate($profile->timemodified, $dateformat);
        }

        $table->data[] = array(
            fullname($profile) . html_writer::div(s($profile->email), 'text-muted small'),
            $profileupdatedlabel,
            html_writer::tag('span', $statuslabel, array('class' => $statusbadge)),
            s($reviewerslabel),
            $reviewedlabel,
            html_writer::div(
                html_writer::link(
                    new moodle_url('/local/jobportal/resume_review.php', array('profileid' => (int)$profile->id)),
                    get_string('openreview', 'local_jobportal')
                ),
                'mb-1'
            ) .
            html_writer::link(
                new moodle_url('/local/jobportal/student_profile.php', array('userid' => (int)$profile->userid)),
                get_string('viewstudentprofile', 'local_jobportal')
            ),
        );
    }

    echo html_writer::table($table);

    if ($totalprofiles > $perpage) {
        $pagingurl = new moodle_url('/local/jobportal/resume_queue.php', array(
            'status' => $statusfilter,
            'search' => $search,
        ));
        echo $OUTPUT->paging_bar($totalprofiles, $page, $perpage, $pagingurl);
    }
}

echo $OUTPUT->footer();
