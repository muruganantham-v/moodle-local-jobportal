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

echo html_writer::start_tag('div', array('class' => 'local-jobportal-page'));

// HERO SECTION
echo html_writer::start_tag('div', array('class' => 'jp-page-hero mb-4'));
echo html_writer::start_div('container-fluid');

echo html_writer::start_div('row align-items-center mb-4');
echo html_writer::start_div('col-md-6');
echo html_writer::tag('h2', get_string('resumequeue', 'local_jobportal'), array('class' => 'jp-hero-title mb-2'));
echo html_writer::tag('p', 'Manage and review student resume submissions.', array('class' => 'jp-hero-subtitle mb-0'));
echo html_writer::end_div();
echo html_writer::start_div('col-md-6 text-md-right mt-3 mt-md-0');
echo html_writer::tag('div', 'Total Profiles: ' . $totalprofiles, array('class' => 'h4 text-white mb-0 font-weight-bold'));
echo html_writer::end_div();
echo html_writer::end_div(); // row

// Stats
echo html_writer::start_div('d-flex flex-wrap gap-3 mb-4');
$stat_items = [
    ['label' => get_string('resumestatus_submitted', 'local_jobportal'), 'count' => $statuscounts['submitted'], 'color' => 'text-white'],
    ['label' => get_string('resumestatus_underreview', 'local_jobportal'), 'count' => $statuscounts['underreview'], 'color' => 'text-warning'],
    ['label' => get_string('resumestatus_needsrework', 'local_jobportal'), 'count' => $statuscounts['needsrework'], 'color' => 'text-danger'],
    ['label' => get_string('resumestatus_approved', 'local_jobportal'), 'count' => $statuscounts['approved'], 'color' => 'text-success'],
];
foreach ($stat_items as $idx => $stat) {
    if ($idx > 0) {
        echo html_writer::div('', 'border-right border-white-50 mx-2');
    }
    echo html_writer::start_div('text-center px-3');
    echo html_writer::tag('div', (int)$stat['count'], array('class' => 'h3 mb-0 font-weight-bold ' . $stat['color']));
    echo html_writer::tag('div', $stat['label'], array('class' => 'small text-white-50 text-uppercase font-weight-bold'));
    echo html_writer::end_div();
}
echo html_writer::end_div(); // stats flex

// Filter Bar
$activeresumefilterscount = ($search !== '' ? 1 : 0) + ($statusfilter !== 'all' ? 1 : 0);
echo html_writer::start_div('jp-form-card p-3 jp-filter-card', array('id' => 'jp-resume-filter-card'));
echo html_writer::start_div('d-flex justify-content-between align-items-center mb-2');
echo html_writer::start_div('d-flex align-items-center gap-2');
echo html_writer::tag('h6', '🔍 ' . get_string('filter', 'moodle'), array('class' => 'font-weight-bold mb-0 text-dark'));
if ($activeresumefilterscount > 0) {
    echo html_writer::tag('span', get_string('filtersapplied', 'local_jobportal', $activeresumefilterscount), array('class' => 'badge badge-primary ml-2 jp-filter-active-count'));
}
echo html_writer::end_div();
echo html_writer::tag(
    'button',
    '👁️ ' . get_string('hidefilters', 'local_jobportal'),
    array(
        'type' => 'button',
        'class' => 'btn btn-sm btn-outline-secondary jp-toggle-filters-btn',
        'data-target' => '#jp-resume-filter-content-wrap',
        'data-storage-key' => 'jp_resume_filters_hidden',
        'data-show-text' => '👁️ ' . get_string('showfilters', 'local_jobportal'),
        'data-hide-text' => '👁️ ' . get_string('hidefilters', 'local_jobportal'),
        'aria-expanded' => 'true',
    )
);
echo html_writer::end_div();

echo html_writer::start_div('jp-filter-content-wrap', array('id' => 'jp-resume-filter-content-wrap'));
echo html_writer::start_tag('form', array('method' => 'get', 'action' => $baseurl, 'class' => 'mb-0'));
echo html_writer::start_div('row align-items-center');
echo html_writer::start_div('col-md-5 mb-2 mb-md-0');
echo html_writer::empty_tag('input', array(
    'type' => 'text',
    'name' => 'search',
    'value' => $search,
    'placeholder' => get_string('search'),
    'class' => 'form-control border-0 bg-light',
));
echo html_writer::end_div();
echo html_writer::start_div('col-md-4 mb-2 mb-md-0');
echo html_writer::select($statusoptions, 'status', $statusfilter, false, array('class' => 'custom-select border-0 bg-light'));
echo html_writer::end_div();
echo html_writer::start_div('col-md-3 d-flex gap-2');
echo html_writer::tag('button', get_string('filter'), array('type' => 'submit', 'class' => 'btn btn-primary flex-grow-1 jp-action-pill'));
if ($search !== '' || $statusfilter !== 'all') {
    echo html_writer::link($baseurl, '✖', array('class' => 'btn btn-outline-secondary jp-action-pill', 'title' => get_string('resetfilters', 'local_jobportal')));
}
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_tag('form');
echo html_writer::end_div(); // jp-filter-content-wrap
echo html_writer::end_div(); // jp-form-card

echo html_writer::end_div(); // container-fluid
echo html_writer::end_tag('div'); // jp-page-hero


echo html_writer::start_div('container-fluid pb-4');

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

    echo html_writer::start_div('row jp-review-grid');
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

        echo html_writer::start_div('col-md-6 col-xl-4 mb-4');
        echo html_writer::start_div('card h-100 jp-review-card shadow-sm border-0');
        
        echo html_writer::start_div('card-header bg-white border-bottom-0 pt-4 pb-0 d-flex justify-content-between align-items-center');
        echo html_writer::tag('span', $statuslabel, array('class' => $statusbadge));
        echo html_writer::tag('span', $profileupdatedlabel, array('class' => 'small text-muted font-weight-bold'));
        echo html_writer::end_div();

        echo html_writer::start_div('card-body');
        echo html_writer::tag('h5', fullname($profile), array('class' => 'card-title font-weight-bold mb-1'));
        echo html_writer::tag('div', s($profile->email), array('class' => 'text-muted small mb-3'));
        
        echo html_writer::start_div('d-flex flex-column gap-2 mb-3 small');
        echo html_writer::div(
            html_writer::tag('strong', get_string('assignedreviewers', 'local_jobportal') . ': ', array('class' => 'text-muted')) .
            s($reviewerslabel)
        );
        echo html_writer::div(
            html_writer::tag('strong', get_string('lastreviewed', 'local_jobportal') . ': ', array('class' => 'text-muted')) .
            $reviewedlabel
        );
        echo html_writer::end_div();
        
        echo html_writer::end_div();

        echo html_writer::start_div('card-footer bg-white border-top-0 pb-4 d-flex gap-2');
        echo html_writer::link(
            new moodle_url('/local/jobportal/resume_review.php', array('profileid' => (int)$profile->id)),
            '👁️ ' . get_string('openreview', 'local_jobportal'),
            array('class' => 'btn btn-primary btn-sm flex-grow-1 jp-action-pill')
        );
        echo html_writer::link(
            new moodle_url('/local/jobportal/student_profile.php', array('userid' => (int)$profile->userid)),
            '👤 Profile',
            array('class' => 'btn btn-outline-secondary btn-sm jp-action-pill')
        );
        echo html_writer::end_div();

        echo html_writer::end_div(); // card
        echo html_writer::end_div(); // col
    }
    echo html_writer::end_div(); // End grid

    if ($totalprofiles > $perpage) {
        $pagingurl = new moodle_url('/local/jobportal/resume_queue.php', array(
            'status' => $statusfilter,
            'search' => $search,
        ));
        echo $OUTPUT->paging_bar($totalprofiles, $page, $perpage, $pagingurl);
    }
}

echo html_writer::end_div(); // end container-fluid
echo html_writer::end_tag('div'); // end local-jobportal-page

echo $OUTPUT->footer();
