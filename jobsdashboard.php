<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');
require_once(__DIR__ . '/locallib.php');

/**
 * Format conversion percentage.
 *
 * @param int $numerator
 * @param int $denominator
 * @return string
 */
function local_jobportal_jobsdashboard_percent($numerator, $denominator) {
    if (empty($denominator)) {
        return '0%';
    }

    return format_float(($numerator / $denominator) * 100, 1) . '%';
}

require_login();

$context = context_system::instance();
require_capability('local/jobportal:managejobs', $context);

$companyid = optional_param('companyid', 0, PARAM_INT);
$jobstatus = optional_param('jobstatus', 'all', PARAM_ALPHANUMEXT);
$listeddays = optional_param('listeddays', 0, PARAM_INT);
$staledays = optional_param('staledays', 14, PARAM_INT);
$search = trim(optional_param('search', '', PARAM_TEXT));
$jobpage = optional_param('jobpage', 0, PARAM_INT);
if ($jobpage < 0) {
    $jobpage = 0;
}
$jobsperpage = 15;

$allowedstatuses = array('all', 'active', 'inactive', 'closed', 'expired', 'closingsoon', 'noapps', 'stale');
if (!in_array($jobstatus, $allowedstatuses, true)) {
    $jobstatus = 'all';
}

$allowedlisteddays = array(0, 7, 30, 90, 180);
if (!in_array($listeddays, $allowedlisteddays, true)) {
    $listeddays = 0;
}

if ($staledays < 1) {
    $staledays = 1;
} else if ($staledays > 90) {
    $staledays = 90;
}

$baseurl = new moodle_url('/local/jobportal/jobsdashboard.php');
$urlparams = array(
    'companyid' => $companyid,
    'jobstatus' => $jobstatus,
    'listeddays' => $listeddays,
    'staledays' => $staledays,
);
if ($search !== '') {
    $urlparams['search'] = $search;
}
if (!empty($jobpage)) {
    $urlparams['jobpage'] = $jobpage;
}

$PAGE->set_context($context);
$PAGE->set_url($baseurl, $urlparams);
$PAGE->set_title(get_string('jobpostsdashboard', 'local_jobportal'));
$PAGE->set_heading(get_string('jobpostsdashboard', 'local_jobportal'));
local_jobportal_require_styles();
$dateformat = '%d/%m/%Y';
$datetimeformat = '%d/%m/%Y %H:%M';

$companyrecords = $DB->get_records('local_jobportal_companies', null, 'name ASC', 'id, name');
$companyoptions = array(0 => get_string('allcompanies', 'local_jobportal'));
foreach ($companyrecords as $company) {
    $companyoptions[(int)$company->id] = format_string($company->name);
}
if (!empty($companyid) && !isset($companyoptions[$companyid])) {
    $companyid = 0;
}

$params = array(
    'shortlistedstatus1' => 'shortlisted',
    'shortlistedstatus2' => 'shortlisted',
    'shortlistedstatus3' => 'shortlisted',
    'offermadestatus' => 'offermade',
    'acceptedstatus' => 'accepted',
);
$sql = "SELECT j.id, j.title, j.company, j.companyid, j.status, j.deadline, j.timecreated, j.timemodified,
               c.name AS companyname,
               COUNT(a.id) AS applicationscount,
               SUM(CASE WHEN a.shortliststatus = :shortlistedstatus1 THEN 1 ELSE 0 END) AS shortlistedcount,
               SUM(CASE WHEN a.shortliststatus = :shortlistedstatus2 AND a.status = :offermadestatus THEN 1 ELSE 0 END) AS offermadecount,
               SUM(CASE WHEN a.shortliststatus = :shortlistedstatus3 AND a.status = :acceptedstatus THEN 1 ELSE 0 END) AS acceptedcount,
               MAX(a.timecreated) AS lastapplicationat
          FROM {local_jobportal_jobs} j
     LEFT JOIN {local_jobportal_companies} c ON c.id = j.companyid
     LEFT JOIN {local_jobportal_applications} a ON a.jobid = j.id
         WHERE 1=1";

if (!empty($companyid)) {
    $sql .= " AND j.companyid = :companyid";
    $params['companyid'] = (int)$companyid;
}

if ($search !== '') {
    $sql .= " AND (j.title LIKE :search1 OR j.company LIKE :search2 OR c.name LIKE :search3)";
    $params['search1'] = '%' . $search . '%';
    $params['search2'] = '%' . $search . '%';
    $params['search3'] = '%' . $search . '%';
}

if ($listeddays > 0) {
    $sql .= " AND j.timecreated >= :listedfrom";
    $params['listedfrom'] = time() - ($listeddays * DAYSECS);
}

$sql .= " GROUP BY j.id, j.title, j.company, j.companyid, j.status, j.deadline, j.timecreated, j.timemodified, c.name
          ORDER BY j.timecreated DESC";

$jobs = $DB->get_records_sql($sql, $params);

$now = time();
$expiringcutoff = $now + (7 * DAYSECS);
$overview = array(
    'totaljobs' => 0,
    'activejobs' => 0,
    'closedjobs' => 0,
    'expiringjobs' => 0,
    'noappjobs' => 0,
    'stalejobs' => 0,
);

$filteredjobs = array();
foreach ($jobs as $job) {
    $job->applicationscount = (int)$job->applicationscount;
    $job->shortlistedcount = (int)$job->shortlistedcount;
    $job->offermadecount = (int)$job->offermadecount;
    $job->acceptedcount = (int)$job->acceptedcount;

    $deadline = !empty($job->deadline) ? (int)$job->deadline : 0;
    $job->isexpired = !empty($deadline) && $deadline < $now;
    $job->isactiveopen = ((int)$job->status === 1) && !$job->isexpired;
    $job->isclosed = ((int)$job->status === 0) || $job->isexpired;
    $job->isexpiringsoon = ((int)$job->status === 1) && !empty($deadline) && $deadline >= $now && $deadline <= $expiringcutoff;
    $job->hasnoapplications = $job->applicationscount === 0;

    $job->daysopen = max(0, (int)floor(($now - (int)$job->timecreated) / DAYSECS));
    $job->dayssincelastapplication = null;
    if (!empty($job->lastapplicationat)) {
        $job->dayssincelastapplication = max(0, (int)floor(($now - (int)$job->lastapplicationat) / DAYSECS));
    }

    $job->isstale = $job->hasnoapplications ? ($job->daysopen >= $staledays) : ($job->dayssincelastapplication >= $staledays);

    if ((int)$job->status === 0) {
        $job->statuslabel = get_string('jobstatusinactive', 'local_jobportal');
        $job->statusbadgeclass = 'badge badge-secondary';
    } else if ($job->isexpired) {
        $job->statuslabel = get_string('jobstatusexpired', 'local_jobportal');
        $job->statusbadgeclass = 'badge badge-dark';
    } else if ($job->isexpiringsoon) {
        $job->statuslabel = get_string('jobstatusclosingsoon', 'local_jobportal');
        $job->statusbadgeclass = 'badge badge-warning';
    } else {
        $job->statuslabel = get_string('jobstatusactive', 'local_jobportal');
        $job->statusbadgeclass = 'badge badge-success';
    }

    $overview['totaljobs']++;
    if ($job->isactiveopen) {
        $overview['activejobs']++;
    }
    if ($job->isclosed) {
        $overview['closedjobs']++;
    }
    if ($job->isexpiringsoon) {
        $overview['expiringjobs']++;
    }
    if ($job->hasnoapplications) {
        $overview['noappjobs']++;
    }
    if ($job->isstale) {
        $overview['stalejobs']++;
    }

    $show = true;
    if ($jobstatus === 'active') {
        $show = $job->isactiveopen;
    } else if ($jobstatus === 'inactive') {
        $show = ((int)$job->status === 0);
    } else if ($jobstatus === 'closed') {
        $show = $job->isclosed;
    } else if ($jobstatus === 'expired') {
        $show = $job->isexpired;
    } else if ($jobstatus === 'closingsoon') {
        $show = $job->isexpiringsoon;
    } else if ($jobstatus === 'noapps') {
        $show = $job->hasnoapplications;
    } else if ($jobstatus === 'stale') {
        $show = $job->isstale;
    }

if ($show) {
        $filteredjobs[] = $job;
    }
}
$totalfilteredjobs = count($filteredjobs);
$filteredjobs = array_slice($filteredjobs, $jobpage * $jobsperpage, $jobsperpage);

$statusoptions = array(
    'all' => get_string('allstatuses', 'local_jobportal'),
    'active' => get_string('jobstatusactive', 'local_jobportal'),
    'inactive' => get_string('jobstatusinactive', 'local_jobportal'),
    'closed' => get_string('jobstatusclosed', 'local_jobportal'),
    'expired' => get_string('jobstatusexpired', 'local_jobportal'),
    'closingsoon' => get_string('jobstatusclosingsoon', 'local_jobportal'),
    'noapps' => get_string('jobstatusnoapps', 'local_jobportal'),
    'stale' => get_string('jobstatusstale', 'local_jobportal'),
);

$listeddaysoptions = array(
    0 => get_string('listedalltime', 'local_jobportal'),
    7 => get_string('listedlast7days', 'local_jobportal'),
    30 => get_string('listedlast30days', 'local_jobportal'),
    90 => get_string('listedlast90days', 'local_jobportal'),
    180 => get_string('listedlast180days', 'local_jobportal'),
);

$staledaysoptions = array(7 => 7, 14 => 14, 30 => 30, 60 => 60, 90 => 90);
if (!isset($staledaysoptions[$staledays])) {
    $staledaysoptions[$staledays] = $staledays;
    ksort($staledaysoptions);
}

echo $OUTPUT->header();
echo local_jobportal_render_navigation($context, 'jobsdashboard');

echo html_writer::start_tag('div', array('class' => 'card mb-3'));
echo html_writer::start_tag('div', array('class' => 'card-body'));
echo html_writer::tag('h4', get_string('jobpostsoverview', 'local_jobportal'), array('class' => 'card-title mb-3'));

echo html_writer::start_tag('form', array('method' => 'get', 'action' => $baseurl, 'class' => 'mb-3'));
echo html_writer::start_tag('div', array('class' => 'row'));

echo html_writer::start_tag('div', array('class' => 'col-md-3 mb-2'));
echo html_writer::empty_tag('input', array(
    'type' => 'text',
    'name' => 'search',
    'value' => $search,
    'placeholder' => get_string('search'),
    'class' => 'form-control',
));
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', array('class' => 'col-md-3 mb-2'));
echo html_writer::select($companyoptions, 'companyid', $companyid, false, array('class' => 'custom-select'));
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', array('class' => 'col-md-2 mb-2'));
echo html_writer::select($statusoptions, 'jobstatus', $jobstatus, false, array('class' => 'custom-select'));
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', array('class' => 'col-md-2 mb-2'));
echo html_writer::select($listeddaysoptions, 'listeddays', $listeddays, false, array('class' => 'custom-select'));
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', array('class' => 'col-md-2 mb-2'));
echo html_writer::select($staledaysoptions, 'staledays', $staledays, false, array('class' => 'custom-select'));
echo html_writer::end_tag('div');

echo html_writer::end_tag('div');
echo html_writer::tag('button', get_string('filter'), array('type' => 'submit', 'class' => 'btn btn-sm btn-outline-primary mr-2'));
echo html_writer::link($baseurl, get_string('resetfilters', 'local_jobportal'), array('class' => 'btn btn-sm btn-outline-secondary'));
echo html_writer::end_tag('form');

echo html_writer::start_tag('div', array('class' => 'row'));
echo html_writer::tag(
    'div',
    html_writer::tag('div', get_string('totaljobs', 'local_jobportal'), array('class' => 'text-muted')) .
    html_writer::tag('div', $overview['totaljobs'], array('class' => 'h4 mb-0')),
    array('class' => 'col-md-2 col-sm-4 mb-2')
);
echo html_writer::tag(
    'div',
    html_writer::tag('div', get_string('activejobs', 'local_jobportal'), array('class' => 'text-muted')) .
    html_writer::tag('div', $overview['activejobs'], array('class' => 'h4 mb-0')),
    array('class' => 'col-md-2 col-sm-4 mb-2')
);
echo html_writer::tag(
    'div',
    html_writer::tag('div', get_string('closedjobs', 'local_jobportal'), array('class' => 'text-muted')) .
    html_writer::tag('div', $overview['closedjobs'], array('class' => 'h4 mb-0')),
    array('class' => 'col-md-2 col-sm-4 mb-2')
);
echo html_writer::tag(
    'div',
    html_writer::tag('div', get_string('expiringjobs7days', 'local_jobportal'), array('class' => 'text-muted')) .
    html_writer::tag('div', $overview['expiringjobs'], array('class' => 'h4 mb-0')),
    array('class' => 'col-md-2 col-sm-4 mb-2')
);
echo html_writer::tag(
    'div',
    html_writer::tag('div', get_string('jobswithnoapplications', 'local_jobportal'), array('class' => 'text-muted')) .
    html_writer::tag('div', $overview['noappjobs'], array('class' => 'h4 mb-0')),
    array('class' => 'col-md-2 col-sm-4 mb-2')
);
echo html_writer::tag(
    'div',
    html_writer::tag('div', get_string('stalejobs', 'local_jobportal') . ' (' . (int)$staledays . 'd)', array('class' => 'text-muted')) .
    html_writer::tag('div', $overview['stalejobs'], array('class' => 'h4 mb-0')),
    array('class' => 'col-md-2 col-sm-4 mb-2')
);
echo html_writer::end_tag('div');

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', array('class' => 'card mb-3'));
echo html_writer::start_tag('div', array('class' => 'card-body'));
echo html_writer::tag('h4', get_string('jobperformance', 'local_jobportal'), array('class' => 'card-title mb-3'));

if (empty($filteredjobs)) {
    echo html_writer::tag('p', get_string('nodataavailable', 'local_jobportal'), array('class' => 'text-muted mb-0'));
} else {
    $pagingparams = array(
        'companyid' => $companyid,
        'jobstatus' => $jobstatus,
        'listeddays' => $listeddays,
        'staledays' => $staledays,
    );
    if ($search !== '') {
        $pagingparams['search'] = $search;
    }
    $pagingurl = new moodle_url('/local/jobportal/jobsdashboard.php', $pagingparams);
    if ($totalfilteredjobs > $jobsperpage) {
        echo $OUTPUT->paging_bar($totalfilteredjobs, $jobpage, $jobsperpage, $pagingurl, 'jobpage');
    }

    echo html_writer::start_tag('div', array('class' => 'table-responsive'));
    echo html_writer::start_tag('table', array('class' => 'table table-sm table-striped table-bordered mb-0 jp-table jp-data-table jp-job-performance-table'));
    echo html_writer::tag(
        'thead',
        html_writer::tag(
            'tr',
            html_writer::tag('th', get_string('jobtitle', 'local_jobportal')) .
            html_writer::tag('th', get_string('company', 'local_jobportal')) .
            html_writer::tag('th', get_string('joblistedon', 'local_jobportal')) .
            html_writer::tag('th', get_string('deadline', 'local_jobportal')) .
            html_writer::tag('th', get_string('status', 'local_jobportal')) .
            html_writer::tag('th', get_string('totalapplications', 'local_jobportal')) .
            html_writer::tag('th', get_string('shortlisted', 'local_jobportal')) .
            html_writer::tag('th', get_string('offermadecount', 'local_jobportal')) .
            html_writer::tag('th', get_string('offeracceptedcount', 'local_jobportal')) .
            html_writer::tag('th', get_string('offerconversion', 'local_jobportal')) .
            html_writer::tag('th', get_string('daysopen', 'local_jobportal')) .
            html_writer::tag('th', get_string('dayssincelastapplication', 'local_jobportal')) .
            html_writer::tag('th', get_string('actions'))
        )
    );
    echo html_writer::start_tag('tbody');

    foreach ($filteredjobs as $job) {
        $companyname = !empty($job->companyname) ? $job->companyname : $job->company;
        $offerconversion = local_jobportal_jobsdashboard_percent($job->acceptedcount, $job->applicationscount);
        $dayssincelastapplication = $job->dayssincelastapplication === null ? '-' : (string)$job->dayssincelastapplication;
        $deadline = !empty($job->deadline) ? userdate($job->deadline, $datetimeformat) : '-';

        $actions = array();
        $actions[] = html_writer::link(
            new moodle_url('/local/jobportal/view.php', array('id' => $job->id)),
            get_string('viewjob', 'local_jobportal')
        );
        $actions[] = html_writer::link(
            new moodle_url('/local/jobportal/applications.php', array('jobid' => $job->id)),
            get_string('viewapplications', 'local_jobportal')
        );
        $actions[] = html_writer::link(
            new moodle_url('/local/jobportal/post.php', array('id' => $job->id)),
            get_string('editjob', 'local_jobportal')
        );
        $actions[] = html_writer::link(
            new moodle_url('/local/jobportal/post.php', array('cloneid' => $job->id)),
            get_string('clonejob', 'local_jobportal')
        );

        echo html_writer::tag(
            'tr',
            html_writer::tag('td', format_string($job->title)) .
            html_writer::tag('td', s($companyname)) .
            html_writer::tag('td', userdate($job->timecreated, $dateformat)) .
            html_writer::tag('td', $deadline) .
            html_writer::tag('td', html_writer::tag('span', $job->statuslabel, array('class' => $job->statusbadgeclass))) .
            html_writer::tag('td', $job->applicationscount) .
            html_writer::tag('td', $job->shortlistedcount) .
            html_writer::tag('td', $job->offermadecount) .
            html_writer::tag('td', $job->acceptedcount) .
            html_writer::tag('td', $offerconversion) .
            html_writer::tag('td', $job->daysopen) .
            html_writer::tag('td', $dayssincelastapplication) .
            html_writer::tag('td', implode(' | ', $actions))
        );
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_tag('div');

    if ($totalfilteredjobs > $jobsperpage) {
        echo $OUTPUT->paging_bar($totalfilteredjobs, $jobpage, $jobsperpage, $pagingurl, 'jobpage');
    }
}

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

echo $OUTPUT->footer();
