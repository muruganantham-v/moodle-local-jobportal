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

$columnoptions = array(
    'title' => get_string('jobtitle', 'local_jobportal'),
    'company' => get_string('company', 'local_jobportal'),
    'listed' => get_string('joblistedon', 'local_jobportal'),
    'deadline' => get_string('deadline', 'local_jobportal'),
    'status' => get_string('status', 'local_jobportal'),
    'applications' => get_string('totalapplications', 'local_jobportal'),
    'shortlisted' => get_string('shortlisted', 'local_jobportal'),
    'offermade' => get_string('offermadecount', 'local_jobportal'),
    'accepted' => get_string('offeracceptedcount', 'local_jobportal'),
    'offerconversion' => get_string('offerconversion', 'local_jobportal'),
    'daysopen' => get_string('daysopen', 'local_jobportal'),
    'dayssincelastapplication' => get_string('dayssincelastapplication', 'local_jobportal'),
);
$columngroups = array(
    get_string('jobinformation', 'local_jobportal') => array('title', 'company', 'listed', 'deadline', 'status'),
    get_string('funnelanalytics', 'local_jobportal') => array(
        'applications',
        'shortlisted',
        'offermade',
        'accepted',
        'offerconversion',
        'daysopen',
        'dayssincelastapplication',
    ),
);
$columnprefkey = 'local_jobportal_jobsdashboard_cols';
$colsprovided = array_key_exists('cols', $_GET) || array_key_exists('cols', $_POST);
$cols = array();
if ((isset($_GET['cols']) && is_array($_GET['cols'])) || (isset($_POST['cols']) && is_array($_POST['cols']))) {
    $cols = optional_param_array('cols', array(), PARAM_ALPHANUMEXT);
}
$colstring = '';
if (empty($cols)) {
    if ($colsprovided && isset($_GET['cols']) && is_string($_GET['cols'])) {
        $colstring = trim((string)$_GET['cols']);
    } else if ($colsprovided && isset($_POST['cols']) && is_string($_POST['cols'])) {
        $colstring = trim((string)$_POST['cols']);
    } else {
        $colstring = trim((string)get_user_preferences($columnprefkey, '', $USER->id));
    }
}
if (empty($cols) && $colstring !== '') {
    $cols = array_filter(array_map('trim', explode(',', $colstring)));
}
$selectedcols = array_values(array_intersect($cols, array_keys($columnoptions)));
if (empty($selectedcols)) {
    $selectedcols = array_keys($columnoptions);
}

$sortby = optional_param('sortby', 'listed', PARAM_ALPHANUMEXT);
$sortdir = optional_param('sortdir', 'desc', PARAM_ALPHA);
$allowedsortkeys = array_keys($columnoptions);
if (!in_array($sortby, $allowedsortkeys, true)) {
    $sortby = 'listed';
}
$sortdir = (core_text::strtolower($sortdir) === 'asc') ? 'asc' : 'desc';

set_user_preference($columnprefkey, implode(',', $selectedcols), $USER->id);

$baseurl = new moodle_url('/local/jobportal/jobsdashboard.php');
$urlparams = array(
    'companyid' => $companyid,
    'jobstatus' => $jobstatus,
    'listeddays' => $listeddays,
    'staledays' => $staledays,
    'sortby' => $sortby,
    'sortdir' => $sortdir,
    'cols' => implode(',', $selectedcols),
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

$comparestring = static function($left, $right) {
    return strcmp(core_text::strtolower((string)$left), core_text::strtolower((string)$right));
};
$comparenullableint = static function($left, $right, $direction = 'asc') {
    $leftisnull = ($left === null);
    $rightisnull = ($right === null);
    if ($leftisnull && $rightisnull) {
        return 0;
    }
    if ($leftisnull) {
        return 1; // Keep NULL values at the bottom.
    }
    if ($rightisnull) {
        return -1;
    }
    $cmp = ((int)$left <=> (int)$right);
    if ($direction === 'desc') {
        $cmp *= -1;
    }
    return $cmp;
};

usort($filteredjobs, static function($left, $right) use ($sortby, $sortdir, $comparestring, $comparenullableint) {
    $cmp = 0;
    $applydirection = true;
    switch ($sortby) {
        case 'title':
            $cmp = $comparestring($left->title, $right->title);
            break;
        case 'company':
            $leftcompany = !empty($left->companyname) ? $left->companyname : $left->company;
            $rightcompany = !empty($right->companyname) ? $right->companyname : $right->company;
            $cmp = $comparestring($leftcompany, $rightcompany);
            break;
        case 'listed':
            $cmp = ((int)$left->timecreated <=> (int)$right->timecreated);
            break;
        case 'deadline':
            $leftdeadline = !empty($left->deadline) ? (int)$left->deadline : null;
            $rightdeadline = !empty($right->deadline) ? (int)$right->deadline : null;
            $cmp = $comparenullableint($leftdeadline, $rightdeadline, $sortdir);
            $applydirection = false;
            break;
        case 'status':
            $cmp = $comparestring($left->statuslabel, $right->statuslabel);
            break;
        case 'applications':
            $cmp = ((int)$left->applicationscount <=> (int)$right->applicationscount);
            break;
        case 'shortlisted':
            $cmp = ((int)$left->shortlistedcount <=> (int)$right->shortlistedcount);
            break;
        case 'offermade':
            $cmp = ((int)$left->offermadecount <=> (int)$right->offermadecount);
            break;
        case 'accepted':
            $cmp = ((int)$left->acceptedcount <=> (int)$right->acceptedcount);
            break;
        case 'offerconversion':
            $leftconversion = ((int)$left->applicationscount > 0) ? ((float)$left->acceptedcount / (float)$left->applicationscount) : 0.0;
            $rightconversion = ((int)$right->applicationscount > 0) ? ((float)$right->acceptedcount / (float)$right->applicationscount) : 0.0;
            $cmp = ($leftconversion <=> $rightconversion);
            break;
        case 'daysopen':
            $cmp = ((int)$left->daysopen <=> (int)$right->daysopen);
            break;
        case 'dayssincelastapplication':
            $cmp = $comparenullableint($left->dayssincelastapplication, $right->dayssincelastapplication, $sortdir);
            $applydirection = false;
            break;
        default:
            $cmp = ((int)$left->timecreated <=> (int)$right->timecreated);
            break;
    }

    if ($applydirection && $sortdir === 'desc') {
        $cmp *= -1;
    }

    if ($cmp === 0) {
        $cmp = ((int)$right->timecreated <=> (int)$left->timecreated);
    }
    if ($cmp === 0) {
        $cmp = ((int)$right->id <=> (int)$left->id);
    }
    return $cmp;
});

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

echo html_writer::start_tag('div', array('class' => 'local-jobportal-page'));
echo html_writer::start_div('jp-page-hero mb-4');
echo html_writer::start_div('container-fluid');
echo html_writer::start_div('row align-items-center mb-4');
echo html_writer::start_div('col-md-6');
echo html_writer::tag('h2', get_string('jobpostsdashboard', 'local_jobportal'), array('class' => 'jp-hero-title mb-2'));
echo html_writer::tag('p', 'Monitor and analyze job posting performance.', array('class' => 'jp-hero-subtitle mb-0'));
echo html_writer::end_div();
echo html_writer::start_div('col-md-6 text-md-right mt-3 mt-md-0');
echo html_writer::link(new moodle_url('/local/jobportal/dashboard.php'), '📈 View Main Dashboard', array('class' => 'btn btn-outline-light jp-action-pill'));
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('d-flex flex-wrap gap-3 mb-2');
$topstats = [
    ['label' => get_string('totaljobs', 'local_jobportal'), 'count' => $overview['totaljobs'], 'color' => 'text-white'],
    ['label' => get_string('activejobs', 'local_jobportal'), 'count' => $overview['activejobs'], 'color' => 'text-success'],
    ['label' => get_string('expiringjobs7days', 'local_jobportal'), 'count' => $overview['expiringjobs'], 'color' => 'text-warning'],
    ['label' => get_string('jobswithnoapplications', 'local_jobportal'), 'count' => $overview['noappjobs'], 'color' => 'text-danger'],
];
foreach ($topstats as $idx => $stat) {
    if ($idx > 0) {
        echo html_writer::div('', 'border-right border-white-50 mx-2');
    }
    echo html_writer::start_div('text-center px-3');
    echo html_writer::tag('div', (int)$stat['count'], array('class' => 'h3 mb-0 font-weight-bold ' . $stat['color']));
    echo html_writer::tag('div', $stat['label'], array('class' => 'small text-white-50 text-uppercase font-weight-bold'));
    echo html_writer::end_div();
}
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div(); // jp-page-hero

echo html_writer::start_div('container-fluid pb-4');

$activejobsdashfilterscount = ($search !== '' ? 1 : 0) + (!empty($companyid) ? 1 : 0) + ($jobstatus !== 'all' ? 1 : 0) + ($listeddays !== 'all' ? 1 : 0) + ($staledays !== 'all' ? 1 : 0);
echo html_writer::start_tag('div', array('class' => 'card jp-form-section border-0 shadow-sm mb-4 jp-filter-card', 'id' => 'jp-jobsdash-filter-card'));
echo html_writer::start_tag('div', array('class' => 'card-body p-4'));
echo html_writer::start_div('d-flex justify-content-between align-items-center mb-3');
echo html_writer::start_div('d-flex align-items-center gap-2');
echo html_writer::tag('h5', '🔍 ' . get_string('jobfilters', 'local_jobportal'), array('class' => 'card-title font-weight-bold mb-0'));
if ($activejobsdashfilterscount > 0) {
    echo html_writer::tag('span', get_string('filtersapplied', 'local_jobportal', $activejobsdashfilterscount), array('class' => 'badge badge-primary ml-2 jp-filter-active-count'));
}
echo html_writer::end_div();
echo html_writer::tag(
    'button',
    '👁️ ' . get_string('hidefilters', 'local_jobportal'),
    array(
        'type' => 'button',
        'class' => 'btn btn-sm btn-outline-secondary jp-toggle-filters-btn',
        'data-target' => '#jp-jobsdash-filter-content-wrap',
        'data-storage-key' => 'jp_jobsdash_filters_hidden',
        'data-show-text' => '👁️ ' . get_string('showfilters', 'local_jobportal'),
        'data-hide-text' => '👁️ ' . get_string('hidefilters', 'local_jobportal'),
        'aria-expanded' => 'true',
    )
);
echo html_writer::end_div();

echo html_writer::start_div('jp-filter-content-wrap', array('id' => 'jp-jobsdash-filter-content-wrap'));

echo html_writer::start_tag('form', array('method' => 'get', 'action' => $baseurl, 'class' => 'mb-0'));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sortby', 'value' => $sortby));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sortdir', 'value' => $sortdir));

echo html_writer::start_tag('div', array('class' => 'row align-items-center mb-3'));
echo html_writer::start_tag('div', array('class' => 'col-md-3 mb-2'));
echo html_writer::empty_tag('input', array(
    'type' => 'text',
    'name' => 'search',
    'value' => $search,
    'placeholder' => get_string('search'),
    'class' => 'form-control bg-light border-0',
));
echo html_writer::end_tag('div');
echo html_writer::start_tag('div', array('class' => 'col-md-3 mb-2'));
echo html_writer::select($companyoptions, 'companyid', $companyid, false, array('class' => 'custom-select bg-light border-0'));
echo html_writer::end_tag('div');
echo html_writer::start_tag('div', array('class' => 'col-md-2 mb-2'));
echo html_writer::select($statusoptions, 'jobstatus', $jobstatus, false, array('class' => 'custom-select bg-light border-0'));
echo html_writer::end_tag('div');
echo html_writer::start_tag('div', array('class' => 'col-md-2 mb-2'));
echo html_writer::select($listeddaysoptions, 'listeddays', $listeddays, false, array('class' => 'custom-select bg-light border-0'));
echo html_writer::end_tag('div');
echo html_writer::start_tag('div', array('class' => 'col-md-2 mb-2'));
echo html_writer::select($staledaysoptions, 'staledays', $staledays, false, array('class' => 'custom-select bg-light border-0'));
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

echo html_writer::start_div('d-flex gap-2');
echo html_writer::start_tag('details', array('class' => 'jp-column-picker flex-grow-1'));
echo html_writer::tag('summary', get_string('selectcolumns', 'local_jobportal'), array('class' => 'btn btn-outline-secondary btn-sm h-100'));
foreach ($columngroups as $grouplabel => $keys) {
    echo html_writer::tag('div', $grouplabel, array('class' => 'jp-column-group-title mt-2'));
    foreach ($keys as $key) {
        if (!isset($columnoptions[$key])) {
            continue;
        }
        $checked = in_array($key, $selectedcols, true);
        echo html_writer::start_tag('div', array('class' => 'jp-column-item'));
        echo html_writer::checkbox('cols[]', $key, $checked, $columnoptions[$key], array('class' => 'mr-2'));
        echo html_writer::end_tag('div');
    }
}
echo html_writer::end_tag('details');
echo html_writer::tag('button', get_string('filter'), array('type' => 'submit', 'class' => 'btn btn-primary px-4 jp-action-pill'));
echo html_writer::link($baseurl, '✖', array('class' => 'btn btn-outline-secondary jp-action-pill'));
echo html_writer::end_div();

echo html_writer::end_tag('form');
echo html_writer::end_div(); // jp-filter-content-wrap
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', array('class' => 'card jp-form-section border-0 shadow-sm mb-4'));
echo html_writer::start_tag('div', array('class' => 'card-body p-4'));
echo html_writer::tag('h5', '💼 ' . get_string('jobperformance', 'local_jobportal'), array('class' => 'card-title font-weight-bold mb-4'));

if (empty($filteredjobs)) {
    echo html_writer::tag('p', get_string('nodataavailable', 'local_jobportal'), array('class' => 'text-muted mb-0'));
} else {
    $pagingparams = array(
        'companyid' => $companyid,
        'jobstatus' => $jobstatus,
        'listeddays' => $listeddays,
        'staledays' => $staledays,
        'sortby' => $sortby,
        'sortdir' => $sortdir,
        'cols' => implode(',', $selectedcols),
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
    $sortdefaultdirs = array(
        'title' => 'asc',
        'company' => 'asc',
        'listed' => 'desc',
        'deadline' => 'asc',
        'status' => 'asc',
        'applications' => 'desc',
        'shortlisted' => 'desc',
        'offermade' => 'desc',
        'accepted' => 'desc',
        'offerconversion' => 'desc',
        'daysopen' => 'desc',
        'dayssincelastapplication' => 'desc',
    );
    $rendersortheader = static function($columnkey, $columnlabel) use (
        $companyid,
        $jobstatus,
        $listeddays,
        $staledays,
        $search,
        $selectedcols,
        $sortby,
        $sortdir,
        $sortdefaultdirs
    ) {
        $nextdir = isset($sortdefaultdirs[$columnkey]) ? $sortdefaultdirs[$columnkey] : 'asc';
        if ($sortby === $columnkey) {
            $nextdir = ($sortdir === 'asc') ? 'desc' : 'asc';
        }
        $params = array(
            'companyid' => $companyid,
            'jobstatus' => $jobstatus,
            'listeddays' => $listeddays,
            'staledays' => $staledays,
            'sortby' => $columnkey,
            'sortdir' => $nextdir,
            'cols' => implode(',', $selectedcols),
        );
        if ($search !== '') {
            $params['search'] = $search;
        }
        $labelhtml = html_writer::span($columnlabel, 'jp-sort-label');
        if ($sortby === $columnkey) {
            $arrow = ($sortdir === 'asc') ? '↑' : '↓';
            $labelhtml .= html_writer::span($arrow, 'jp-sort-indicator');
        }
        return html_writer::link(new moodle_url('/local/jobportal/jobsdashboard.php', $params), $labelhtml, array('class' => 'jp-sort-link'));
    };
    $headercells = array();
    foreach ($selectedcols as $colkey) {
        if (isset($columnoptions[$colkey])) {
            $headercells[] = html_writer::tag('th', $rendersortheader($colkey, $columnoptions[$colkey]));
        }
    }
    $headercells[] = html_writer::tag('th', get_string('actions'));
    echo html_writer::tag(
        'thead',
        html_writer::tag(
            'tr',
            implode('', $headercells)
        )
    );
    echo html_writer::start_tag('tbody');

    foreach ($filteredjobs as $job) {
        $companyname = !empty($job->companyname) ? $job->companyname : $job->company;
        $offerconversion = local_jobportal_jobsdashboard_percent($job->acceptedcount, $job->applicationscount);
        $dayssincelastapplication = $job->dayssincelastapplication === null ? '-' : (string)$job->dayssincelastapplication;
        $deadline = !empty($job->deadline) ? userdate($job->deadline, $datetimeformat) : '-';

        $line1 = array();
        $line2 = array();
        $line1[] = html_writer::link(
            new moodle_url('/local/jobportal/view.php', array('id' => $job->id)),
            get_string('viewjob', 'local_jobportal')
        );
        $line1[] = html_writer::link(
            new moodle_url('/local/jobportal/applications.php', array('jobid' => $job->id)),
            get_string('viewapplications', 'local_jobportal')
        );
        $line2[] = html_writer::link(
            new moodle_url('/local/jobportal/post.php', array('id' => $job->id)),
            get_string('editjob', 'local_jobportal')
        );
        $line2[] = html_writer::link(
            new moodle_url('/local/jobportal/post.php', array('cloneid' => $job->id)),
            get_string('clonejob', 'local_jobportal')
        );
        $actionlines = array();
        if (!empty($line1)) {
            $actionlines[] = html_writer::div(implode(' | ', $line1), 'jp-job-actions-line');
        }
        if (!empty($line2)) {
            $actionlines[] = html_writer::div(implode(' | ', $line2), 'jp-job-actions-line');
        }
        $actionshtml = html_writer::div(implode('', $actionlines), 'jp-job-actions');
        $rowcells = array();
        foreach ($selectedcols as $colkey) {
            $value = '-';
            switch ($colkey) {
                case 'title':
                    $value = format_string($job->title);
                    break;
                case 'company':
                    $value = s($companyname);
                    break;
                case 'listed':
                    $value = userdate($job->timecreated, $dateformat);
                    break;
                case 'deadline':
                    $value = $deadline;
                    break;
                case 'status':
                    $value = html_writer::tag('span', $job->statuslabel, array('class' => $job->statusbadgeclass));
                    break;
                case 'applications':
                    $value = (string)$job->applicationscount;
                    break;
                case 'shortlisted':
                    $value = (string)$job->shortlistedcount;
                    break;
                case 'offermade':
                    $value = (string)$job->offermadecount;
                    break;
                case 'accepted':
                    $value = (string)$job->acceptedcount;
                    break;
                case 'offerconversion':
                    $value = $offerconversion;
                    break;
                case 'daysopen':
                    $value = (string)$job->daysopen;
                    break;
                case 'dayssincelastapplication':
                    $value = $dayssincelastapplication;
                    break;
            }
            $rowcells[] = html_writer::tag('td', $value);
        }
        $rowcells[] = html_writer::tag('td', $actionshtml, array('class' => 'jp-col-actions'));

        echo html_writer::tag(
            'tr',
            implode('', $rowcells)
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
