<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');
require_once($CFG->libdir.'/tablelib.php');
require_once(__DIR__ . '/locallib.php');

require_login();

$context = context_system::instance();
require_capability('local/jobportal:viewjobs', $context);

$ismanager = has_capability('local/jobportal:managejobs', $context);
$canapply = has_capability('local/jobportal:apply', $context);
$canpost = has_capability('local/jobportal:postjobs', $context);
$canmanagecompanies = has_capability('local/jobportal:managecompanyprofile', $context);
$canviewapplications = has_capability('local/jobportal:viewapplications', $context);

$search = trim(optional_param('search', '', PARAM_TEXT));
$page = optional_param('page', 0, PARAM_INT);
if ($page < 0) {
    $page = 0;
}

$dateformat = '%d/%m/%Y';
$datetimeformat = '%d/%m/%Y %H:%M';
$now = time();

$perpage = 12;
$pageurlparams = array();

if ($ismanager) {
    $perpageoptions = array(25, 50, 100);
    $perpage = optional_param('perpage', 25, PARAM_INT);
    if (!in_array($perpage, $perpageoptions, true)) {
        $perpage = 25;
    }

    $companyid = optional_param('companyid', 0, PARAM_INT);
    $jobstatus = optional_param('jobstatus', 'all', PARAM_ALPHANUMEXT);
    $jobtype = optional_param('jobtype', 'all', PARAM_ALPHANUMEXT);
    $salarymode = optional_param('salarymode', 'all', PARAM_ALPHANUMEXT);
    $salaryminraw = trim(optional_param('salarymin', '', PARAM_RAW_TRIMMED));
    $salarymaxraw = trim(optional_param('salarymax', '', PARAM_RAW_TRIMMED));
    $hasapps = optional_param('hasapps', 'all', PARAM_ALPHANUMEXT);
    $staledays = optional_param('staledays', 14, PARAM_INT);
    if ($staledays < 1) {
        $staledays = 1;
    } else if ($staledays > 365) {
        $staledays = 365;
    }

    $listedfrom = trim(optional_param('listedfrom', '', PARAM_TEXT));
    $listedto = trim(optional_param('listedto', '', PARAM_TEXT));
    $deadlinefrom = trim(optional_param('deadlinefrom', '', PARAM_TEXT));
    $deadlineto = trim(optional_param('deadlineto', '', PARAM_TEXT));

    $sortby = optional_param('sortby', 'listed', PARAM_ALPHANUMEXT);
    $sortdir = optional_param('sortdir', 'desc', PARAM_ALPHA);
    $sortdir = strtolower($sortdir) === 'asc' ? 'asc' : 'desc';

    $preset = optional_param('preset', '', PARAM_ALPHANUMEXT);
    $cols = array();
    if ((isset($_GET['cols']) && is_array($_GET['cols'])) || (isset($_POST['cols']) && is_array($_POST['cols']))) {
        $cols = optional_param_array('cols', array(), PARAM_ALPHANUMEXT);
    }
    $colstring = '';
    if (empty($cols)) {
        if (isset($_GET['cols']) && is_string($_GET['cols'])) {
            $colstring = trim($_GET['cols']);
        } else if (isset($_POST['cols']) && is_string($_POST['cols'])) {
            $colstring = trim($_POST['cols']);
        }
    }

    $allowedstatuses = array('all', 'active', 'inactive', 'closed', 'expired', 'closingsoon', 'stale');
    if (!in_array($jobstatus, $allowedstatuses, true)) {
        $jobstatus = 'all';
    }

    $jobtypes = array('fulltime', 'parttime', 'internship', 'contract', 'freelance');
    if ($jobtype !== 'all' && !in_array($jobtype, $jobtypes, true)) {
        $jobtype = 'all';
    }

    $allowedsalarymodes = array('all', 'lt', 'between', 'gt', 'undisclosed');
    if (!in_array($salarymode, $allowedsalarymodes, true)) {
        $salarymode = 'all';
    }
    $salarymin = null;
    $salarymax = null;
    if ($salaryminraw !== '' && is_numeric($salaryminraw)) {
        $salarymin = (float)$salaryminraw;
    } else {
        $salaryminraw = '';
    }
    if ($salarymaxraw !== '' && is_numeric($salarymaxraw)) {
        $salarymax = (float)$salarymaxraw;
    } else {
        $salarymaxraw = '';
    }
    if ($salarymode === 'between') {
        if ($salarymin === null || $salarymax === null) {
            $salarymode = 'all';
        } else if ($salarymin > $salarymax) {
            $tmp = $salarymin;
            $salarymin = $salarymax;
            $salarymax = $tmp;
            $salaryminraw = (string)$salarymin;
            $salarymaxraw = (string)$salarymax;
        }
    } else if ($salarymode === 'lt') {
        // Backward compatibility: accept old links that used salarymin for "less than".
        if ($salarymax === null && $salarymin !== null) {
            $salarymax = $salarymin;
            $salarymaxraw = $salaryminraw;
        }
        if ($salarymax === null) {
            $salarymode = 'all';
        }
        $salarymin = null;
        $salaryminraw = '';
    } else if ($salarymode === 'gt') {
        // Backward compatibility: accept old links that used salarymax for "greater than".
        if ($salarymin === null && $salarymax !== null) {
            $salarymin = $salarymax;
            $salaryminraw = $salarymaxraw;
        }
        if ($salarymin === null) {
            $salarymode = 'all';
        }
        $salarymax = null;
        $salarymaxraw = '';
    } else {
        $salarymin = null;
        $salarymax = null;
        $salaryminraw = '';
        $salarymaxraw = '';
    }

    $allowedapps = array('all', 'yes', 'no');
    if (!in_array($hasapps, $allowedapps, true)) {
        $hasapps = 'all';
    }

    $presetoptions = array(
        '' => get_string('presetcustom', 'local_jobportal'),
        'open' => get_string('presetopenjobs', 'local_jobportal'),
        'closingsoon' => get_string('presetclosingsoon', 'local_jobportal'),
        'noapps14' => get_string('presetnoapps14', 'local_jobportal'),
        'stale14' => get_string('presetstale14', 'local_jobportal'),
    );
    if (!isset($presetoptions[$preset])) {
        $preset = '';
    }

    $noappsaged = false;
    $jobstatusprovided = isset($_GET['jobstatus']) || isset($_POST['jobstatus']);
    $hasappsprovided = isset($_GET['hasapps']) || isset($_POST['hasapps']);
    $staledaysprovided = isset($_GET['staledays']) || isset($_POST['staledays']);
    $salarymodeprovided = isset($_GET['salarymode']) || isset($_POST['salarymode']);
    $salaryminprovided = isset($_GET['salarymin']) || isset($_POST['salarymin']);
    $salarymaxprovided = isset($_GET['salarymax']) || isset($_POST['salarymax']);
    if ($preset === 'open') {
        $hasconflict = ($jobstatusprovided && $jobstatus !== 'active') ||
            ($salarymodeprovided && $salarymode !== 'all') ||
            ($salaryminprovided && $salaryminraw !== '') ||
            ($salarymaxprovided && $salarymaxraw !== '');
        if ($hasconflict) {
            $preset = '';
        } else {
            $jobstatus = 'active';
        }
    } else if ($preset === 'closingsoon') {
        $hasconflict = ($jobstatusprovided && $jobstatus !== 'closingsoon') ||
            ($salarymodeprovided && $salarymode !== 'all') ||
            ($salaryminprovided && $salaryminraw !== '') ||
            ($salarymaxprovided && $salarymaxraw !== '');
        if ($hasconflict) {
            $preset = '';
        } else {
            $jobstatus = 'closingsoon';
        }
    } else if ($preset === 'noapps14') {
        $hasconflict = ($hasappsprovided && $hasapps !== 'no') ||
            ($staledaysprovided && $staledays !== 14) ||
            ($salarymodeprovided && $salarymode !== 'all') ||
            ($salaryminprovided && $salaryminraw !== '') ||
            ($salarymaxprovided && $salarymaxraw !== '');
        if ($hasconflict) {
            $preset = '';
        } else {
            $hasapps = 'no';
            $staledays = 14;
            $noappsaged = true;
        }
    } else if ($preset === 'stale14') {
        $hasconflict = ($jobstatusprovided && $jobstatus !== 'stale') ||
            ($staledaysprovided && $staledays !== 14) ||
            ($salarymodeprovided && $salarymode !== 'all') ||
            ($salaryminprovided && $salaryminraw !== '') ||
            ($salarymaxprovided && $salarymaxraw !== '');
        if ($hasconflict) {
            $preset = '';
        } else {
            $jobstatus = 'stale';
            $staledays = 14;
        }
    }

    if ($preset !== '') {
        $page = 0;
    }

    $showstaledays = ($jobstatus === 'stale' || $preset === 'noapps14' || $preset === 'stale14');
    $advancedopen = ($listedfrom !== '' || $listedto !== '' || $deadlinefrom !== '' || $deadlineto !== '' || $hasapps !== 'all' ||
        $staledays !== 14 || $salarymode !== 'all' || $salaryminraw !== '' || $salarymaxraw !== '');

    $listedfromts = 0;
    if ($listedfrom !== '') {
        $listedfromts = strtotime($listedfrom . ' 00:00:00');
        if (!$listedfromts) {
            $listedfromts = 0;
            $listedfrom = '';
        }
    }
    $listedtots = 0;
    if ($listedto !== '') {
        $listedtots = strtotime($listedto . ' 23:59:59');
        if (!$listedtots) {
            $listedtots = 0;
            $listedto = '';
        }
    }
    $deadlinefromts = 0;
    if ($deadlinefrom !== '') {
        $deadlinefromts = strtotime($deadlinefrom . ' 00:00:00');
        if (!$deadlinefromts) {
            $deadlinefromts = 0;
            $deadlinefrom = '';
        }
    }
    $deadlinetots = 0;
    if ($deadlineto !== '') {
        $deadlinetots = strtotime($deadlineto . ' 23:59:59');
        if (!$deadlinetots) {
            $deadlinetots = 0;
            $deadlineto = '';
        }
    }

    $columnoptions = array(
        'jobid' => get_string('jobid', 'local_jobportal'),
        'title' => get_string('jobtitle', 'local_jobportal'),
        'company' => get_string('company', 'local_jobportal'),
        'status' => get_string('status', 'local_jobportal'),
        'jobtype' => get_string('jobtype', 'local_jobportal'),
        'location' => get_string('location', 'local_jobportal'),
        'salary' => get_string('salary', 'local_jobportal'),
        'listed' => get_string('joblistedon', 'local_jobportal'),
        'deadline' => get_string('deadline', 'local_jobportal'),
        'applications' => get_string('totalapplications', 'local_jobportal'),
        'shortlisted' => get_string('shortlisted', 'local_jobportal'),
        'offerconversion' => get_string('offerconversion', 'local_jobportal'),
        'lastapplication' => get_string('lastapplication', 'local_jobportal'),
        'dayssincelastapplication' => get_string('dayssincelastapplication', 'local_jobportal'),
        'updated' => get_string('lastupdated', 'local_jobportal'),
    );
    if (empty($cols) && $colstring !== '') {
        $cols = array_filter(array_map('trim', explode(',', $colstring)));
    }
    $selectedcols = array_values(array_intersect($cols, array_keys($columnoptions)));
    if (empty($selectedcols)) {
        $selectedcols = array_keys($columnoptions);
    }

    $pageurlparams = array(
        'search' => $search,
        'page' => $page,
        'perpage' => $perpage,
        'companyid' => $companyid,
        'jobstatus' => $jobstatus,
        'jobtype' => $jobtype,
        'salarymode' => $salarymode,
        'salarymin' => $salaryminraw,
        'salarymax' => $salarymaxraw,
        'hasapps' => $hasapps,
        'staledays' => $staledays,
        'listedfrom' => $listedfrom,
        'listedto' => $listedto,
        'deadlinefrom' => $deadlinefrom,
        'deadlineto' => $deadlineto,
        'sortby' => $sortby,
        'sortdir' => $sortdir,
        'preset' => $preset,
        'cols' => implode(',', $selectedcols),
    );
} else {
    if ($search !== '') {
        $pageurlparams['search'] = $search;
    }
    if (!empty($page)) {
        $pageurlparams['page'] = $page;
    }
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/jobportal/index.php', $pageurlparams));
$PAGE->set_title(get_string('jobportal', 'local_jobportal'));
$PAGE->set_heading(get_string('alljobs', 'local_jobportal'));
local_jobportal_require_styles();

if ($ismanager) {
    $PAGE->requires->js_init_code("
        (function() {
            var master = document.getElementById('jp-select-all');
            if (master) {
                master.addEventListener('change', function() {
                    document.querySelectorAll('.jp-job-select').forEach(function(cb) {
                        cb.checked = master.checked;
                    });
                });
            }

            var presetInput = document.getElementById('jp-preset');
            var manualFilterIds = [
                'jp-search', 'jp-companyid', 'jp-jobstatus', 'jp-jobtype',
                'jp-listedfrom', 'jp-listedto', 'jp-deadlinefrom', 'jp-deadlineto',
                'jp-hasapps', 'jp-staledays', 'jp-salarymode', 'jp-salarymin', 'jp-salarymax'
            ];
            manualFilterIds.forEach(function(id) {
                var field = document.getElementById(id);
                if (!field) {
                    return;
                }
                var clearPreset = function() {
                    if (presetInput) {
                        presetInput.value = '';
                    }
                };
                field.addEventListener('change', clearPreset);
                field.addEventListener('input', clearPreset);
            });

            var statusSelect = document.getElementById('jp-jobstatus');
            var staleDaysWrap = document.getElementById('jp-staledays-wrap');
            var salaryModeSelect = document.getElementById('jp-salarymode');
            var salaryMinWrap = document.getElementById('jp-salarymin-wrap');
            var salaryMaxWrap = document.getElementById('jp-salarymax-wrap');
            var salaryMinInput = document.getElementById('jp-salarymin');
            var salaryMaxInput = document.getElementById('jp-salarymax');
            function syncStaleDaysVisibility() {
                if (!statusSelect || !staleDaysWrap) {
                    return;
                }
                var presetValue = presetInput ? presetInput.value : '';
                var show = statusSelect.value === 'stale' || presetValue === 'stale14' || presetValue === 'noapps14';
                staleDaysWrap.style.display = show ? '' : 'none';
            }
            function syncSalaryVisibility() {
                if (!salaryModeSelect || !salaryMinWrap || !salaryMaxWrap) {
                    return;
                }
                var mode = salaryModeSelect.value;
                var showMin = mode === 'gt' || mode === 'between';
                var showMax = mode === 'lt' || mode === 'between';
                salaryMinWrap.style.display = showMin ? '' : 'none';
                salaryMaxWrap.style.display = showMax ? '' : 'none';
                if (salaryMinInput) {
                    salaryMinInput.disabled = !showMin;
                }
                if (salaryMaxInput) {
                    salaryMaxInput.disabled = !showMax;
                }
            }
            if (statusSelect) {
                statusSelect.addEventListener('change', syncStaleDaysVisibility);
            }
            if (salaryModeSelect) {
                salaryModeSelect.addEventListener('change', syncSalaryVisibility);
            }
            syncStaleDaysVisibility();
            syncSalaryVisibility();

            var columnSearch = document.getElementById('jp-column-search');
            if (columnSearch) {
                columnSearch.addEventListener('input', function() {
                    var term = columnSearch.value.toLowerCase().trim();
                    document.querySelectorAll('.jp-column-item').forEach(function(item) {
                        var label = item.getAttribute('data-col-label') || '';
                        item.style.display = label.indexOf(term) !== -1 ? '' : 'none';
                    });
                });
            }
        })();
    ");
}

if ($ismanager && data_submitted() && optional_param('bulk', 0, PARAM_BOOL) && confirm_sesskey()) {
    $bulkaction = optional_param('bulkaction', '', PARAM_ALPHANUMEXT);
    $jobids = optional_param_array('jobids', array(), PARAM_INT);
    $jobids = array_filter(array_map('intval', $jobids));

    if (empty($jobids)) {
        redirect($PAGE->url, get_string('bulknojobsselected', 'local_jobportal'), null, \core\output\notification::NOTIFY_WARNING);
    }

    $validactions = array('open', 'close', 'extenddeadline', 'clone');
    if (!in_array($bulkaction, $validactions, true)) {
        redirect($PAGE->url, get_string('error:invalidaction', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
    }

    $jobs = $DB->get_records_list('local_jobportal_jobs', 'id', $jobids);
    if (empty($jobs)) {
        redirect($PAGE->url, get_string('bulknojobsselected', 'local_jobportal'), null, \core\output\notification::NOTIFY_WARNING);
    }

    $updated = 0;
    $now = time();

    if ($bulkaction === 'open' || $bulkaction === 'close') {
        $newstatus = $bulkaction === 'open' ? 1 : 0;
        foreach ($jobs as $job) {
            $update = new stdClass();
            $update->id = (int)$job->id;
            $update->status = $newstatus;
            $update->timemodified = $now;
            $DB->update_record('local_jobportal_jobs', $update);
            $updated++;
        }
        redirect($PAGE->url, get_string('bulkjobsupdated', 'local_jobportal', $updated), null, \core\output\notification::NOTIFY_SUCCESS);
    }

    if ($bulkaction === 'extenddeadline') {
        $extenddays = optional_param('extenddays', 0, PARAM_INT);
        if ($extenddays < 1) {
            redirect($PAGE->url, get_string('bulkextenddaysrequired', 'local_jobportal'), null, \core\output\notification::NOTIFY_WARNING);
        }
        $extendseconds = $extenddays * DAYSECS;
        foreach ($jobs as $job) {
            $deadline = !empty($job->deadline) ? (int)$job->deadline : ($now + $extendseconds);
            if (!empty($job->deadline)) {
                $deadline = (int)$job->deadline + $extendseconds;
            }
            $update = new stdClass();
            $update->id = (int)$job->id;
            $update->deadline = $deadline;
            $update->timemodified = $now;
            $DB->update_record('local_jobportal_jobs', $update);
            $updated++;
        }
        redirect($PAGE->url, get_string('bulkjobsupdated', 'local_jobportal', $updated), null, \core\output\notification::NOTIFY_SUCCESS);
    }

    if ($bulkaction === 'clone') {
        foreach ($jobs as $job) {
            $suffix = ' (Copy)';
            $title = (string)$job->title;
            if (core_text::strlen($title . $suffix) > 255) {
                $title = core_text::substr($title, 0, 255 - core_text::strlen($suffix));
            }

            $clone = new stdClass();
            $clone->title = $title . $suffix;
            $clone->companyid = !empty($job->companyid) ? (int)$job->companyid : null;
            $clone->company = $job->company;
            $clone->description = $job->description;
            $clone->location = $job->location;
            $clone->jobtype = $job->jobtype;
            $clone->salary = $job->salary;
            $clone->salarymodel = !empty($job->salarymodel) ? $job->salarymodel : 'custom';
            $clone->salarycurrency = !empty($job->salarycurrency) ? $job->salarycurrency : 'INR';
            $clone->salaryperiod = !empty($job->salaryperiod) ? $job->salaryperiod : 'annual';
            $clone->salarymin = isset($job->salarymin) ? $job->salarymin : null;
            $clone->salarymax = isset($job->salarymax) ? $job->salarymax : null;
            $clone->salaryminannual = isset($job->salaryminannual) ? $job->salaryminannual : null;
            $clone->salarymaxannual = isset($job->salarymaxannual) ? $job->salarymaxannual : null;
            $clone->requirements = $job->requirements;
            $clone->deadline = null;
            $clone->status = 1;
            $clone->postedby = $USER->id;
            $clone->timecreated = $now;
            $clone->timemodified = $now;

            $newjobid = (int)$DB->insert_record('local_jobportal_jobs', $clone);
            $salarystages = local_jobportal_get_job_salary_stages((int)$job->id);
            $newstages = array();
            foreach ($salarystages as $stage) {
                $newstages[] = array(
                    'stagelabel' => $stage->stagelabel,
                    'amount' => $stage->amount,
                    'period' => $stage->period,
                    'conditiontext' => !empty($stage->conditiontext) ? $stage->conditiontext : '',
                    'sortorder' => $stage->sortorder,
                );
            }
            local_jobportal_replace_job_salary_stages($newjobid, $newstages);
            $updated++;
        }
        redirect($PAGE->url, get_string('bulkjobscloned', 'local_jobportal', $updated), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

echo $OUTPUT->header();
echo local_jobportal_render_navigation($context, 'index');

if ($ismanager) {
    $companyoptions = local_jobportal_get_company_options();
    $companyoptions = array(0 => get_string('allcompanies', 'local_jobportal')) + $companyoptions;
    if (!isset($companyoptions[$companyid])) {
        $companyid = 0;
    }

    $jobstatusoptions = array(
        'all' => get_string('allstatuses', 'local_jobportal'),
        'active' => get_string('jobstatusactive', 'local_jobportal'),
        'inactive' => get_string('jobstatusinactive', 'local_jobportal'),
        'closed' => get_string('jobstatusclosed', 'local_jobportal'),
        'expired' => get_string('jobstatusexpired', 'local_jobportal'),
        'closingsoon' => get_string('jobstatusclosingsoon', 'local_jobportal'),
        'stale' => get_string('jobstatusstale', 'local_jobportal'),
    );

    $jobtypeoptions = array(
        'all' => get_string('alljobtypes', 'local_jobportal'),
        'fulltime' => get_string('fulltime', 'local_jobportal'),
        'parttime' => get_string('parttime', 'local_jobportal'),
        'internship' => get_string('internship', 'local_jobportal'),
        'contract' => get_string('contract', 'local_jobportal'),
        'freelance' => get_string('freelance', 'local_jobportal'),
    );
    $salarymodeoptions = array(
        'all' => get_string('salaryfilterall', 'local_jobportal'),
        'lt' => get_string('salaryfilterlt', 'local_jobportal'),
        'between' => get_string('salaryfilterbetween', 'local_jobportal'),
        'gt' => get_string('salaryfiltergt', 'local_jobportal'),
        'undisclosed' => get_string('salaryfilterundisclosed', 'local_jobportal'),
    );

    $hasappsoptions = array(
        'all' => get_string('alloptions', 'local_jobportal'),
        'yes' => get_string('hasapplications_yes', 'local_jobportal'),
        'no' => get_string('hasapplications_no', 'local_jobportal'),
    );

    $sortoptions = array(
        'listed' => get_string('joblistedon', 'local_jobportal'),
        'deadline' => get_string('deadline', 'local_jobportal'),
        'updated' => get_string('lastupdated', 'local_jobportal'),
        'jobid' => get_string('jobid', 'local_jobportal'),
        'location' => get_string('location', 'local_jobportal'),
        'salary' => get_string('salary', 'local_jobportal'),
        'applications' => get_string('totalapplications', 'local_jobportal'),
        'shortlisted' => get_string('shortlisted', 'local_jobportal'),
        'offerconversion' => get_string('offerconversion', 'local_jobportal'),
        'dayssincelastapplication' => get_string('dayssincelastapplication', 'local_jobportal'),
        'company' => get_string('company', 'local_jobportal'),
        'title' => get_string('jobtitle', 'local_jobportal'),
    );
    if (!isset($sortoptions[$sortby])) {
        $sortby = 'listed';
    }

    $sortdiroptions = array(
        'asc' => get_string('sortasc', 'local_jobportal'),
        'desc' => get_string('sortdesc', 'local_jobportal'),
    );

    $perpagechoices = array(
        25 => '25',
        50 => '50',
        100 => '100',
    );

    $columnorder = array_keys($columnoptions);

    $baseurl = new moodle_url('/local/jobportal/index.php');
    $presetchipurls = array();
    foreach ($presetoptions as $presetkey => $presetlabel) {
        if ($presetkey === '') {
            continue;
        }
        $presetparams = $pageurlparams;
        unset($presetparams['page']);
        $presetparams['preset'] = $presetkey;
        $presetparams['jobstatus'] = 'all';
        $presetparams['salarymode'] = 'all';
        $presetparams['salarymin'] = '';
        $presetparams['salarymax'] = '';
        $presetparams['hasapps'] = 'all';
        $presetparams['staledays'] = 14;
        $presetparams['listedfrom'] = '';
        $presetparams['listedto'] = '';
        $presetparams['deadlinefrom'] = '';
        $presetparams['deadlineto'] = '';
        if ($presetkey === 'open') {
            $presetparams['jobstatus'] = 'active';
        } else if ($presetkey === 'closingsoon') {
            $presetparams['jobstatus'] = 'closingsoon';
        } else if ($presetkey === 'noapps14') {
            $presetparams['hasapps'] = 'no';
        } else if ($presetkey === 'stale14') {
            $presetparams['jobstatus'] = 'stale';
        }
        $presetchipurls[$presetkey] = new moodle_url('/local/jobportal/index.php', $presetparams);
    }
    $clearpresetparams = $pageurlparams;
    unset($clearpresetparams['page']);
    $clearpresetparams['preset'] = '';
    $clearpreseturl = new moodle_url('/local/jobportal/index.php', $clearpresetparams);

    $activefilterchips = array();
    $chipbaseparams = $pageurlparams;
    unset($chipbaseparams['page']);
    $buildchip = function(string $label, array $overrides, bool $clearpreset = true) use ($chipbaseparams): array {
        $params = $chipbaseparams;
        $params['page'] = 0;
        foreach ($overrides as $key => $value) {
            $params[$key] = $value;
        }
        if ($clearpreset) {
            $params['preset'] = '';
        }
        return array(
            'label' => $label,
            'url' => new moodle_url('/local/jobportal/index.php', $params),
        );
    };
    if ($search !== '') {
        $activefilterchips[] = $buildchip(get_string('search') . ': ' . $search, array('search' => ''));
    }
    if (!empty($companyid) && isset($companyoptions[$companyid])) {
        $activefilterchips[] = $buildchip(get_string('company', 'local_jobportal') . ': ' . $companyoptions[$companyid], array('companyid' => 0));
    }
    if ($jobstatus !== 'all' && isset($jobstatusoptions[$jobstatus])) {
        $activefilterchips[] = $buildchip(get_string('status', 'local_jobportal') . ': ' . $jobstatusoptions[$jobstatus], array('jobstatus' => 'all'));
    }
    if ($jobtype !== 'all' && isset($jobtypeoptions[$jobtype])) {
        $activefilterchips[] = $buildchip(get_string('jobtype', 'local_jobportal') . ': ' . $jobtypeoptions[$jobtype], array('jobtype' => 'all'));
    }
    if ($salarymode !== 'all' && isset($salarymodeoptions[$salarymode])) {
        $salarylabel = get_string('salaryfilter', 'local_jobportal') . ': ' . $salarymodeoptions[$salarymode];
        if ($salarymode === 'between' && $salaryminraw !== '' && $salarymaxraw !== '') {
            $salarylabel .= ' (' . $salaryminraw . ' - ' . $salarymaxraw . ')';
        } else if ($salarymode === 'lt' && $salarymaxraw !== '') {
            $salarylabel .= ' (' . $salarymaxraw . ')';
        } else if ($salarymode === 'gt' && $salaryminraw !== '') {
            $salarylabel .= ' (' . $salaryminraw . ')';
        }
        $activefilterchips[] = $buildchip($salarylabel, array('salarymode' => 'all', 'salarymin' => '', 'salarymax' => ''));
    }
    if ($hasapps !== 'all' && isset($hasappsoptions[$hasapps])) {
        $activefilterchips[] = $buildchip(get_string('hasapplications', 'local_jobportal') . ': ' . $hasappsoptions[$hasapps], array('hasapps' => 'all'));
    }
    if ($listedfrom !== '') {
        $activefilterchips[] = $buildchip(get_string('listedfrom', 'local_jobportal') . ': ' . $listedfrom, array('listedfrom' => ''));
    }
    if ($listedto !== '') {
        $activefilterchips[] = $buildchip(get_string('listedto', 'local_jobportal') . ': ' . $listedto, array('listedto' => ''));
    }
    if ($deadlinefrom !== '') {
        $activefilterchips[] = $buildchip(get_string('deadlinefrom', 'local_jobportal') . ': ' . $deadlinefrom, array('deadlinefrom' => ''));
    }
    if ($deadlineto !== '') {
        $activefilterchips[] = $buildchip(get_string('deadlineto', 'local_jobportal') . ': ' . $deadlineto, array('deadlineto' => ''));
    }
    if ($showstaledays && $staledays !== 14) {
        $activefilterchips[] = $buildchip(get_string('staledays', 'local_jobportal') . ': ' . $staledays, array('staledays' => 14));
    }
    if ($preset !== '' && isset($presetoptions[$preset])) {
        $activefilterchips[] = $buildchip(
            get_string('presetfilters', 'local_jobportal') . ': ' . $presetoptions[$preset],
            array('preset' => ''),
            false
        );
    }

    $columngroups = array(
        get_string('jobinformation', 'local_jobportal') => array('jobid', 'title', 'company', 'status', 'jobtype', 'location', 'salary', 'listed', 'deadline', 'updated'),
        get_string('funnelanalytics', 'local_jobportal') => array('applications', 'shortlisted', 'offerconversion', 'lastapplication', 'dayssincelastapplication'),
    );

    echo html_writer::start_div('card mb-3 jp-filter-card jp-sticky-filters');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h5', get_string('jobfilters', 'local_jobportal'), array('class' => 'card-title mb-2'));

    echo html_writer::start_div('jp-preset-chipbar mb-3');
    echo html_writer::tag('span', get_string('presetfilters', 'local_jobportal'), array('class' => 'jp-chipbar-label'));
    foreach ($presetoptions as $presetkey => $presetlabel) {
        if ($presetkey === '') {
            continue;
        }
        $presetclass = 'jp-preset-chip';
        if ($preset === $presetkey) {
            $presetclass .= ' jp-active';
        }
        echo html_writer::link($presetchipurls[$presetkey], $presetlabel, array('class' => $presetclass));
    }
    if ($preset !== '') {
        echo html_writer::link($clearpreseturl, get_string('clearpreset', 'local_jobportal'), array('class' => 'jp-preset-clear'));
    }
    echo html_writer::end_div();

    echo html_writer::start_tag('form', array(
        'method' => 'get',
        'action' => $baseurl,
        'class' => 'jp-filter-form',
        'novalidate' => 'novalidate',
    ));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'preset', 'id' => 'jp-preset', 'value' => $preset));

    echo html_writer::start_div('row');
    echo html_writer::start_div('col-md-4 mb-2');
    echo html_writer::tag('label', get_string('search'), array('for' => 'jp-search', 'class' => 'small text-muted d-block'));
    echo html_writer::empty_tag('input', array(
        'type' => 'text',
        'id' => 'jp-search',
        'name' => 'search',
        'value' => $search,
        'placeholder' => get_string('search'),
        'class' => 'form-control',
    ));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-3 mb-2');
    echo html_writer::tag('label', get_string('company', 'local_jobportal'), array('for' => 'jp-companyid', 'class' => 'small text-muted d-block'));
    echo html_writer::select($companyoptions, 'companyid', $companyid, false, array('class' => 'custom-select', 'id' => 'jp-companyid'));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-2 mb-2');
    echo html_writer::tag('label', get_string('status', 'local_jobportal'), array('for' => 'jp-jobstatus', 'class' => 'small text-muted d-block'));
    echo html_writer::select($jobstatusoptions, 'jobstatus', $jobstatus, false, array('class' => 'custom-select', 'id' => 'jp-jobstatus'));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-3 mb-2');
    echo html_writer::tag('label', get_string('jobtype', 'local_jobportal'), array('for' => 'jp-jobtype', 'class' => 'small text-muted d-block'));
    echo html_writer::select($jobtypeoptions, 'jobtype', $jobtype, false, array('class' => 'custom-select', 'id' => 'jp-jobtype'));
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_tag('details', array('class' => 'jp-filter-details mt-2', 'open' => $advancedopen ? 'open' : null));
    echo html_writer::tag('summary', get_string('advancedfilters', 'local_jobportal'));
    echo html_writer::start_div('row mt-2');
    echo html_writer::start_div('col-md-3 mb-2');
    echo html_writer::tag('label', get_string('listedfrom', 'local_jobportal'), array('for' => 'jp-listedfrom', 'class' => 'small text-muted d-block'));
    echo html_writer::empty_tag('input', array(
        'type' => 'date',
        'id' => 'jp-listedfrom',
        'name' => 'listedfrom',
        'value' => $listedfrom,
        'class' => 'form-control',
        'placeholder' => get_string('listedfrom', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-3 mb-2');
    echo html_writer::tag('label', get_string('listedto', 'local_jobportal'), array('for' => 'jp-listedto', 'class' => 'small text-muted d-block'));
    echo html_writer::empty_tag('input', array(
        'type' => 'date',
        'id' => 'jp-listedto',
        'name' => 'listedto',
        'value' => $listedto,
        'class' => 'form-control',
        'placeholder' => get_string('listedto', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-3 mb-2');
    echo html_writer::tag('label', get_string('deadlinefrom', 'local_jobportal'), array('for' => 'jp-deadlinefrom', 'class' => 'small text-muted d-block'));
    echo html_writer::empty_tag('input', array(
        'type' => 'date',
        'id' => 'jp-deadlinefrom',
        'name' => 'deadlinefrom',
        'value' => $deadlinefrom,
        'class' => 'form-control',
        'placeholder' => get_string('deadlinefrom', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-3 mb-2');
    echo html_writer::tag('label', get_string('deadlineto', 'local_jobportal'), array('for' => 'jp-deadlineto', 'class' => 'small text-muted d-block'));
    echo html_writer::empty_tag('input', array(
        'type' => 'date',
        'id' => 'jp-deadlineto',
        'name' => 'deadlineto',
        'value' => $deadlineto,
        'class' => 'form-control',
        'placeholder' => get_string('deadlineto', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('row');
    echo html_writer::start_div('col-md-3 mb-2');
    echo html_writer::tag('label', get_string('hasapplications', 'local_jobportal'), array('for' => 'jp-hasapps', 'class' => 'small text-muted d-block'));
    echo html_writer::select($hasappsoptions, 'hasapps', $hasapps, false, array('class' => 'custom-select', 'id' => 'jp-hasapps'));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-3 mb-2');
    echo html_writer::tag('label', get_string('salaryfilter', 'local_jobportal'), array('for' => 'jp-salarymode', 'class' => 'small text-muted d-block'));
    echo html_writer::select($salarymodeoptions, 'salarymode', $salarymode, false, array('class' => 'custom-select', 'id' => 'jp-salarymode'));
    echo html_writer::end_div();
    $showsalarymin = ($salarymode === 'gt' || $salarymode === 'between');
    $showsalarymax = ($salarymode === 'lt' || $salarymode === 'between');
    $salaryminwrapstyle = $showsalarymin ? '' : 'display:none;';
    $salarymaxwrapstyle = $showsalarymax ? '' : 'display:none;';
    $salarymindisplay = $salaryminraw !== '' ? $salaryminraw : '300000';
    $salarymaxdisplay = $salarymaxraw !== '' ? $salarymaxraw : '300000';
    echo html_writer::start_div('col-md-3 mb-2', array('id' => 'jp-salarymin-wrap', 'style' => $salaryminwrapstyle));
    echo html_writer::tag('label', get_string('salarymin', 'local_jobportal'), array('for' => 'jp-salarymin', 'class' => 'small text-muted d-block'));
    echo html_writer::empty_tag('input', array(
        'type' => 'number',
        'step' => '50000',
        'id' => 'jp-salarymin',
        'name' => 'salarymin',
        'value' => $salarymindisplay,
        'class' => 'form-control',
        'min' => 0,
        'placeholder' => get_string('salarymin', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-3 mb-2', array('id' => 'jp-salarymax-wrap', 'style' => $salarymaxwrapstyle));
    echo html_writer::tag('label', get_string('salarymax', 'local_jobportal'), array('for' => 'jp-salarymax', 'class' => 'small text-muted d-block'));
    echo html_writer::empty_tag('input', array(
        'type' => 'number',
        'step' => '50000',
        'id' => 'jp-salarymax',
        'name' => 'salarymax',
        'value' => $salarymaxdisplay,
        'class' => 'form-control',
        'min' => 0,
        'placeholder' => get_string('salarymax', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('row');
    $stalewrapstyle = $showstaledays ? '' : 'display:none;';
    echo html_writer::start_div('col-md-3 mb-2', array('id' => 'jp-staledays-wrap', 'style' => $stalewrapstyle));
    echo html_writer::tag('label', get_string('staledays', 'local_jobportal'), array('for' => 'jp-staledays', 'class' => 'small text-muted d-block'));
    echo html_writer::empty_tag('input', array(
        'type' => 'number',
        'id' => 'jp-staledays',
        'name' => 'staledays',
        'value' => $staledays,
        'class' => 'form-control',
        'min' => 1,
        'max' => 365,
        'placeholder' => get_string('staledays', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_tag('details');

    echo html_writer::start_div('row');
    echo html_writer::start_div('col-md-3 mb-2');
    echo html_writer::tag('label', get_string('sortby', 'local_jobportal'), array('for' => 'jp-sortby', 'class' => 'small text-muted d-block'));
    echo html_writer::select($sortoptions, 'sortby', $sortby, false, array('class' => 'custom-select', 'id' => 'jp-sortby'));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-2 mb-2');
    echo html_writer::tag('label', get_string('sortdirection', 'local_jobportal'), array('for' => 'jp-sortdir', 'class' => 'small text-muted d-block'));
    echo html_writer::select($sortdiroptions, 'sortdir', $sortdir, false, array('class' => 'custom-select', 'id' => 'jp-sortdir'));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-2 mb-2');
    echo html_writer::tag('label', get_string('perpage', 'local_jobportal'), array('for' => 'jp-perpage', 'class' => 'small text-muted d-block'));
    echo html_writer::select($perpagechoices, 'perpage', $perpage, false, array('class' => 'custom-select', 'id' => 'jp-perpage'));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-5 mb-2 jp-filter-actions pt-md-4');
    echo html_writer::tag('button', get_string('filter'), array('type' => 'submit', 'class' => 'btn btn-primary mr-2'));
    echo html_writer::link($baseurl, get_string('resetfilters', 'local_jobportal'), array('class' => 'btn btn-outline-secondary'));
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_tag('details', array('class' => 'jp-column-picker mt-2'));
    echo html_writer::tag('summary', get_string('selectcolumns', 'local_jobportal'));
    echo html_writer::empty_tag('input', array(
        'type' => 'search',
        'id' => 'jp-column-search',
        'class' => 'form-control form-control-sm mb-2',
        'placeholder' => get_string('searchcolumns', 'local_jobportal'),
    ));
    foreach ($columngroups as $grouplabel => $keys) {
        echo html_writer::tag('div', $grouplabel, array('class' => 'jp-column-group-title'));
        foreach ($keys as $key) {
            if (!isset($columnoptions[$key])) {
                continue;
            }
            $checked = in_array($key, $selectedcols, true);
            $label = $columnoptions[$key];
            $itemattrs = array('class' => 'jp-column-item', 'data-col-label' => core_text::strtolower(strip_tags($label)));
            echo html_writer::start_tag('div', $itemattrs);
            echo html_writer::checkbox('cols[]', $key, $checked, $label, array('class' => 'mr-2 jp-column-checkbox'));
            echo html_writer::end_div();
        }
    }
    echo html_writer::end_tag('details');

    echo html_writer::end_tag('form');
    if (!empty($activefilterchips)) {
        echo html_writer::start_div('jp-active-filters mt-3');
        echo html_writer::tag('span', get_string('activefilters', 'local_jobportal'), array('class' => 'jp-chipbar-label'));
        foreach ($activefilterchips as $chip) {
            echo html_writer::link($chip['url'], $chip['label'] . ' x', array('class' => 'jp-active-filter-chip'));
        }
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
    echo html_writer::end_div();

    if ($canpost) {
        echo html_writer::link(
            new moodle_url('/local/jobportal/post.php'),
            get_string('postjob', 'local_jobportal'),
            array('class' => 'btn btn-success mb-3')
        );
    }

    if ($canmanagecompanies) {
        echo ' ';
        echo html_writer::link(
            new moodle_url('/local/jobportal/companyprofile.php'),
            get_string('managecompanies', 'local_jobportal'),
            array('class' => 'btn btn-outline-primary mb-3')
        );
    }

    if (has_capability('local/jobportal:managejobs', $context)) {
        echo ' ';
        echo html_writer::link(
            new moodle_url('/local/jobportal/dashboard.php'),
            get_string('managerdashboard', 'local_jobportal'),
            array('class' => 'btn btn-outline-dark mb-3')
        );
        echo ' ';
        echo html_writer::link(
            new moodle_url('/local/jobportal/jobsdashboard.php'),
            get_string('jobpostsdashboard', 'local_jobportal'),
            array('class' => 'btn btn-outline-secondary mb-3')
        );
    }

    $params = array(
        'shortlistedstatus1' => 'shortlisted',
        'shortlistedstatus2' => 'shortlisted',
        'shortlistedstatus3' => 'shortlisted',
        'offermadestatus' => 'offermade',
        'acceptedstatus' => 'accepted',
    );
    $where = array('1=1');
    $having = array();

    if ($search !== '') {
        $where[] = '(' .
            $DB->sql_like('j.title', ':searchtitle', false) .
            ' OR ' . $DB->sql_like('j.company', ':searchcompany', false) .
            ' OR ' . $DB->sql_like('j.location', ':searchlocation', false) .
            ' OR ' . $DB->sql_like('j.description', ':searchdesc', false) .
            ' OR ' . $DB->sql_like('c.name', ':searchcompanyprofile', false) .
            ')';
        $params['searchtitle'] = '%' . $search . '%';
        $params['searchcompany'] = '%' . $search . '%';
        $params['searchlocation'] = '%' . $search . '%';
        $params['searchdesc'] = '%' . $search . '%';
        $params['searchcompanyprofile'] = '%' . $search . '%';
    }

    if (!empty($companyid)) {
        $where[] = 'j.companyid = :companyid';
        $params['companyid'] = $companyid;
    }

    if ($jobtype !== 'all') {
        $where[] = 'j.jobtype = :jobtype';
        $params['jobtype'] = $jobtype;
    }

    if ($salarymode === 'lt' && $salarymax !== null) {
        $where[] = 'j.salaryminannual IS NOT NULL';
        $where[] = 'j.salaryminannual < :salarylt';
        $params['salarylt'] = $salarymax;
    } else if ($salarymode === 'gt' && $salarymin !== null) {
        $where[] = 'j.salarymaxannual IS NOT NULL';
        $where[] = 'j.salarymaxannual > :salarygt';
        $params['salarygt'] = $salarymin;
    } else if ($salarymode === 'between' && $salarymin !== null && $salarymax !== null) {
        $where[] = 'j.salarymaxannual IS NOT NULL';
        $where[] = 'j.salaryminannual IS NOT NULL';
        $where[] = 'j.salarymaxannual >= :salarymin';
        $where[] = 'j.salaryminannual <= :salarymax';
        $params['salarymin'] = $salarymin;
        $params['salarymax'] = $salarymax;
    } else if ($salarymode === 'undisclosed') {
        $where[] = 'LOWER(j.salarymodel) = :salarymodeundisclosed';
        $params['salarymodeundisclosed'] = 'undisclosed';
    }

    if (!empty($listedfromts)) {
        $where[] = 'j.timecreated >= :listedfrom';
        $params['listedfrom'] = $listedfromts;
    }

    if (!empty($listedtots)) {
        $where[] = 'j.timecreated <= :listedto';
        $params['listedto'] = $listedtots;
    }

    if (!empty($noappsaged) && $hasapps === 'no') {
        $where[] = 'j.timecreated <= :noappslistedbefore';
        $params['noappslistedbefore'] = $now - ($staledays * DAYSECS);
    }

    if (!empty($deadlinefromts)) {
        $where[] = 'j.deadline >= :deadlinefrom';
        $params['deadlinefrom'] = $deadlinefromts;
    }

    if (!empty($deadlinetots)) {
        $where[] = 'j.deadline <= :deadlineto';
        $params['deadlineto'] = $deadlinetots;
    }

    switch ($jobstatus) {
        case 'active':
            $where[] = 'j.status = 1';
            $where[] = '(j.deadline IS NULL OR j.deadline = 0 OR j.deadline >= :nowactive)';
            $params['nowactive'] = $now;
            break;
        case 'inactive':
            $where[] = 'j.status = 0';
            break;
        case 'expired':
            $where[] = 'j.deadline > 0';
            $where[] = 'j.deadline < :nowexpired';
            $params['nowexpired'] = $now;
            break;
        case 'closingsoon':
            $where[] = 'j.status = 1';
            $where[] = 'j.deadline >= :nowclosing';
            $where[] = 'j.deadline <= :closingsoon';
            $params['nowclosing'] = $now;
            $params['closingsoon'] = $now + (7 * DAYSECS);
            break;
        case 'closed':
            $where[] = '(j.status = 0 OR (j.deadline > 0 AND j.deadline < :nowclosed))';
            $params['nowclosed'] = $now;
            break;
        case 'stale':
            $staleconds = $staledays * DAYSECS;
            // Use aggregate-safe expression for grouped queries across MySQL SQL modes.
            $having[] = '(:stalenow - COALESCE(MAX(a.timecreated), MIN(j.timecreated))) >= :staleseconds';
            $params['stalenow'] = $now;
            $params['staleseconds'] = $staleconds;
            break;
        default:
            break;
    }

    if ($hasapps === 'yes') {
        $having[] = 'COUNT(a.id) > 0';
    } else if ($hasapps === 'no') {
        $having[] = 'COUNT(a.id) = 0';
    }

    $selectfields = "j.*, c.id AS companyprofileid, c.name AS companyprofilename,
            COUNT(a.id) AS applicationscount,
            SUM(CASE WHEN a.shortliststatus = :shortlistedstatus1 THEN 1 ELSE 0 END) AS shortlistedcount,
            SUM(CASE WHEN a.shortliststatus = :shortlistedstatus2 AND a.status = :offermadestatus THEN 1 ELSE 0 END) AS offermadecount,
            SUM(CASE WHEN a.shortliststatus = :shortlistedstatus3 AND a.status = :acceptedstatus THEN 1 ELSE 0 END) AS acceptedcount,
            MAX(a.timecreated) AS lastapplicationat";

    $fromsql = " FROM {local_jobportal_jobs} j
         LEFT JOIN {local_jobportal_companies} c ON c.id = j.companyid
         LEFT JOIN {local_jobportal_applications} a ON a.jobid = j.id";

    $wheresql = ' WHERE ' . implode(' AND ', $where);
    $groupsql = ' GROUP BY j.id, c.id';
    $havingsql = empty($having) ? '' : (' HAVING ' . implode(' AND ', $having));

    $sortdirection = $sortdir === 'asc' ? 'ASC' : 'DESC';
    $ordersql = '';
    if ($sortby === 'offerconversion') {
        $ordersql = " ORDER BY (CASE WHEN applicationscount = 0 THEN 0 ELSE acceptedcount / applicationscount END) $sortdirection";
    } else if ($sortby === 'dayssincelastapplication') {
        if ($sortdirection === 'ASC') {
            $ordersql = ' ORDER BY (lastapplicationat IS NULL) ASC, lastapplicationat DESC';
        } else {
            $ordersql = ' ORDER BY (lastapplicationat IS NULL) ASC, lastapplicationat ASC';
        }
    } else if ($sortby === 'deadline') {
        $ordersql = " ORDER BY (j.deadline IS NULL OR j.deadline = 0) ASC, j.deadline $sortdirection";
    } else {
        $sortmap = array(
            'listed' => 'j.timecreated',
            'updated' => 'j.timemodified',
            'jobid' => 'j.id',
            'location' => 'j.location',
            'salary' => 'COALESCE(j.salaryminannual, j.salarymaxannual, 0)',
            'applications' => 'applicationscount',
            'shortlisted' => 'shortlistedcount',
            'company' => 'companyprofilename',
            'title' => 'j.title',
        );
        $orderby = isset($sortmap[$sortby]) ? $sortmap[$sortby] : 'j.timecreated';
        $ordersql = " ORDER BY {$orderby} $sortdirection";
    }
    $ordersql .= ', j.timecreated DESC';

    $countsql = 'SELECT COUNT(1) FROM (SELECT j.id' . $fromsql . $wheresql . $groupsql . $havingsql . ') jobcount';
    $totaljobs = (int)$DB->count_records_sql($countsql, $params);

    $sql = 'SELECT ' . $selectfields . $fromsql . $wheresql . $groupsql . $havingsql . $ordersql;
    $jobs = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);

    if (empty($jobs)) {
        echo html_writer::tag('p', get_string('nojobs', 'local_jobportal'), array('class' => 'alert alert-info'));
    } else {
        $pagingparams = $pageurlparams;
        unset($pagingparams['page']);
        $pagingurl = new moodle_url('/local/jobportal/index.php', $pagingparams);
        if ($totaljobs > $perpage) {
            echo $OUTPUT->paging_bar($totaljobs, $page, $perpage, $pagingurl);
        }

        $start = ($page * $perpage) + 1;
        $end = min($totaljobs, ($page * $perpage) + $perpage);
        $showing = (object)array('start' => $start, 'end' => $end, 'total' => $totaljobs);
        echo html_writer::div(get_string('showingresults', 'local_jobportal', $showing), 'text-muted mb-2');

        echo html_writer::start_tag('form', array('method' => 'post', 'action' => $PAGE->url, 'class' => 'jp-bulk-form'));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'bulk', 'value' => 1));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));

        $bulkactionoptions = array(
            '' => get_string('bulkaction', 'local_jobportal'),
            'open' => get_string('bulkopen', 'local_jobportal'),
            'close' => get_string('bulkclose', 'local_jobportal'),
            'extenddeadline' => get_string('bulkextenddeadline', 'local_jobportal'),
            'clone' => get_string('bulkclone', 'local_jobportal'),
        );

        echo html_writer::start_div('jp-bulk-actions mb-2');
        echo html_writer::select($bulkactionoptions, 'bulkaction', '', false, array('class' => 'custom-select')); 
        echo html_writer::empty_tag('input', array(
            'type' => 'number',
            'name' => 'extenddays',
            'class' => 'form-control form-control-sm',
            'min' => 1,
            'placeholder' => get_string('extenddays', 'local_jobportal'),
        ));
        echo html_writer::tag('button', get_string('applybulkaction', 'local_jobportal'), array('type' => 'submit', 'class' => 'btn btn-sm btn-outline-primary'));
        echo html_writer::end_div();

        echo html_writer::start_tag('div', array('class' => 'table-responsive'));
        $table = new html_table();
        $selectall = html_writer::empty_tag('input', array('type' => 'checkbox', 'id' => 'jp-select-all'));
        $table->head = array($selectall);
        foreach ($columnorder as $key) {
            if (!in_array($key, $selectedcols, true)) {
                continue;
            }
            $table->head[] = $columnoptions[$key];
        }
        $table->head[] = get_string('actions');
        $table->attributes['class'] = 'table table-sm table-striped table-bordered jp-table jp-data-table jp-jobs-table';

        foreach ($jobs as $job) {
            $companyname = !empty($job->companyprofilename) ? $job->companyprofilename : $job->company;
            $companycell = s($companyname);
            if (!empty($job->companyprofileid)) {
                $companycell = html_writer::link(
                    new moodle_url('/local/jobportal/company.php', array('id' => $job->companyprofileid)),
                    s($companyname)
                );
            }

            $applicationscount = (int)$job->applicationscount;
            $shortlistedcount = (int)$job->shortlistedcount;
            $acceptedcount = (int)$job->acceptedcount;
            $offerconversion = $applicationscount > 0 ? format_float(($acceptedcount / $applicationscount) * 100, 1) . '%' : '0%';

            $deadline = !empty($job->deadline) ? (int)$job->deadline : 0;
            $isexpired = !empty($deadline) && $deadline < $now;
            $isclosingsoon = (int)$job->status === 1 && !empty($deadline) && $deadline >= $now && $deadline <= ($now + (7 * DAYSECS));

            if ((int)$job->status === 0) {
                $statuslabel = get_string('jobstatusinactive', 'local_jobportal');
                $statusbadge = 'badge badge-secondary';
            } else if ($isexpired) {
                $statuslabel = get_string('jobstatusexpired', 'local_jobportal');
                $statusbadge = 'badge badge-dark';
            } else if ($isclosingsoon) {
                $statuslabel = get_string('jobstatusclosingsoon', 'local_jobportal');
                $statusbadge = 'badge badge-warning';
            } else {
                $statuslabel = get_string('jobstatusactive', 'local_jobportal');
                $statusbadge = 'badge badge-success';
            }

            $lastapplicationlabel = '-';
            $dayssincelastapplication = '-';
            if (!empty($job->lastapplicationat)) {
                $lastapplicationlabel = userdate($job->lastapplicationat, $dateformat);
                $dayssincelastapplication = (string)max(0, (int)floor(($now - (int)$job->lastapplicationat) / DAYSECS));
            }

            $actions = array();
            $actions[] = html_writer::link(
                new moodle_url('/local/jobportal/view.php', array('id' => $job->id)),
                get_string('viewjob', 'local_jobportal')
            );
            if ($canpost) {
                $actions[] = html_writer::link(
                    new moodle_url('/local/jobportal/post.php', array('id' => $job->id)),
                    get_string('editjob', 'local_jobportal')
                );
                $actions[] = html_writer::link(
                    new moodle_url('/local/jobportal/post.php', array('cloneid' => $job->id)),
                    get_string('clonejob', 'local_jobportal')
                );
            }
            if ($canviewapplications) {
                $actions[] = html_writer::link(
                    new moodle_url('/local/jobportal/applications.php', array('jobid' => $job->id)),
                    get_string('viewapplications', 'local_jobportal')
                );
            }

            $row = array();
            $row[] = html_writer::empty_tag('input', array('type' => 'checkbox', 'name' => 'jobids[]', 'value' => (int)$job->id, 'class' => 'jp-job-select'));

            foreach ($columnorder as $key) {
                if (!in_array($key, $selectedcols, true)) {
                    continue;
                }
                switch ($key) {
                    case 'jobid':
                        $row[] = html_writer::link(
                            new moodle_url('/local/jobportal/view.php', array('id' => $job->id)),
                            (string)$job->id
                        );
                        break;
                    case 'title':
                        $row[] = html_writer::link(
                            new moodle_url('/local/jobportal/view.php', array('id' => $job->id)),
                            format_string($job->title)
                        );
                        break;
                    case 'company':
                        $row[] = $companycell;
                        break;
                    case 'status':
                        $row[] = html_writer::tag('span', $statuslabel, array('class' => $statusbadge));
                        break;
                    case 'jobtype':
                        $row[] = local_jobportal_format_jobtype($job->jobtype);
                        break;
                    case 'location':
                        $row[] = !empty($job->location) ? s($job->location) : '-';
                        break;
                    case 'salary':
                        $salarydisplay = local_jobportal_get_job_salary_display($job);
                        $row[] = $salarydisplay !== '' ? s($salarydisplay) : '-';
                        break;
                    case 'listed':
                        $row[] = userdate($job->timecreated, $dateformat);
                        break;
                    case 'deadline':
                        $row[] = !empty($job->deadline) ? userdate($job->deadline, $datetimeformat) : '-';
                        break;
                    case 'applications':
                        $row[] = (string)$applicationscount;
                        break;
                    case 'shortlisted':
                        $row[] = (string)$shortlistedcount;
                        break;
                    case 'offerconversion':
                        $row[] = $offerconversion;
                        break;
                    case 'lastapplication':
                        $row[] = $lastapplicationlabel;
                        break;
                    case 'dayssincelastapplication':
                        $row[] = $dayssincelastapplication;
                        break;
                    case 'updated':
                        $row[] = userdate($job->timemodified, $dateformat);
                        break;
                    default:
                        break;
                }
            }

            $row[] = implode(' | ', $actions);
            $table->data[] = $row;
        }

        echo html_writer::table($table);
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('form');

        if ($totaljobs > $perpage) {
            echo $OUTPUT->paging_bar($totaljobs, $page, $perpage, $pagingurl);
        }
    }
} else {
    // Search and filter form
    echo html_writer::start_tag('div', array('class' => 'job-portal-search mb-3'));
    echo html_writer::start_tag('form', array('method' => 'get', 'action' => '', 'class' => 'form-inline'));
    echo html_writer::label(get_string('search'), 'search', false, array('class' => 'mr-2'));
    echo html_writer::empty_tag('input', array(
        'type' => 'text',
        'name' => 'search',
        'id' => 'search',
        'value' => $search,
        'class' => 'form-control mr-2',
        'placeholder' => get_string('search')
    ));
    echo html_writer::tag('button', get_string('search'), array(
        'type' => 'submit',
        'class' => 'btn btn-primary'
    ));
    echo html_writer::end_tag('form');
    echo html_writer::end_tag('div');

    // Action buttons
    if ($canpost) {
        echo html_writer::link(
            new moodle_url('/local/jobportal/post.php'),
            get_string('postjob', 'local_jobportal'),
            array('class' => 'btn btn-success mb-3')
        );
    }

    if ($canapply) {
        echo ' ';
        echo html_writer::link(
            new moodle_url('/local/jobportal/myapplications.php'),
            get_string('myapplications', 'local_jobportal'),
            array('class' => 'btn btn-info mb-3')
        );
        echo ' ';
        echo html_writer::link(
            new moodle_url('/local/jobportal/profile.php'),
            get_string('myprofile', 'local_jobportal'),
            array('class' => 'btn btn-secondary mb-3')
        );
    }

    if ($canmanagecompanies) {
        echo ' ';
        echo html_writer::link(
            new moodle_url('/local/jobportal/companyprofile.php'),
            get_string('managecompanies', 'local_jobportal'),
            array('class' => 'btn btn-outline-primary mb-3')
        );
    }

    if (has_capability('local/jobportal:managejobs', $context)) {
        echo ' ';
        echo html_writer::link(
            new moodle_url('/local/jobportal/dashboard.php'),
            get_string('managerdashboard', 'local_jobportal'),
            array('class' => 'btn btn-outline-dark mb-3')
        );
        echo ' ';
        echo html_writer::link(
            new moodle_url('/local/jobportal/jobsdashboard.php'),
            get_string('jobpostsdashboard', 'local_jobportal'),
            array('class' => 'btn btn-outline-secondary mb-3')
        );
    }

    // Get jobs from database.
    $fromsql = " FROM {local_jobportal_jobs} j
            LEFT JOIN {local_jobportal_companies} c ON c.id = j.companyid
            WHERE j.status = 1";
    $params = array();

    if (!empty($search)) {
        $fromsql .= " AND (j.title LIKE :search1 OR j.company LIKE :search2 OR j.description LIKE :search3 OR c.name LIKE :search4)";
        $params['search1'] = '%' . $search . '%';
        $params['search2'] = '%' . $search . '%';
        $params['search3'] = '%' . $search . '%';
        $params['search4'] = '%' . $search . '%';
    }

    $totalsql = "SELECT COUNT(1)" . $fromsql;
    $totaljobs = (int)$DB->count_records_sql($totalsql, $params);

    $sql = "SELECT j.*, c.id AS companyprofileid, c.name AS companyprofilename" . $fromsql . " ORDER BY j.timecreated DESC";
    $jobs = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);
    $userapplicationsbyjob = array();
    $usereventsbyapp = array();
    $stages = array();

    if ($canapply && !empty($jobs)) {
        $jobids = array_keys($jobs);
        list($jobinsql, $jobinparams) = $DB->get_in_or_equal($jobids, SQL_PARAMS_NAMED);
        $appsql = "SELECT id, jobid, status, shortliststatus, currentstageid, timecreated
                     FROM {local_jobportal_applications}
                    WHERE userid = :userid
                      AND jobid $jobinsql";
        $appparams = array_merge(array('userid' => $USER->id), $jobinparams);
        $applications = $DB->get_records_sql($appsql, $appparams);

        foreach ($applications as $application) {
            $userapplicationsbyjob[(int)$application->jobid] = $application;
        }

        if (!empty($applications)) {
            local_jobportal_ensure_default_stages();
            $stages = local_jobportal_get_recruitment_stages(false);

            $appids = array_keys($applications);
            list($appinsql, $appinparams) = $DB->get_in_or_equal($appids, SQL_PARAMS_NAMED);
            $eventsql = "SELECT id, applicationid, stageid, scheduledat, timecreated
                           FROM {local_jobportal_appstage_events}
                          WHERE applicationid $appinsql
                       ORDER BY timecreated ASC";
            $events = $DB->get_records_sql($eventsql, $appinparams);

            foreach ($events as $event) {
                if (!isset($usereventsbyapp[$event->applicationid])) {
                    $usereventsbyapp[$event->applicationid] = array();
                }
                $usereventsbyapp[$event->applicationid][] = $event;
            }
        }
    }

    if (empty($jobs)) {
        echo html_writer::tag('p', get_string('nojobs', 'local_jobportal'), array('class' => 'alert alert-info'));
    } else {
        $pagingparams = array();
        if ($search !== '') {
            $pagingparams['search'] = $search;
        }
        $pagingurl = new moodle_url('/local/jobportal/index.php', $pagingparams);
        if ($totaljobs > $perpage) {
            echo $OUTPUT->paging_bar($totaljobs, $page, $perpage, $pagingurl);
        }

        // Display jobs
        echo html_writer::start_tag('div', array('class' => 'job-listings'));

        foreach ($jobs as $job) {
            $joburl = new moodle_url('/local/jobportal/view.php', array('id' => $job->id));
            $companyname = !empty($job->companyprofilename) ? $job->companyprofilename : $job->company;

            echo html_writer::start_tag('div', array('class' => 'card mb-3'));
            echo html_writer::start_tag('div', array('class' => 'card-body'));

            echo html_writer::tag('h5', format_string($job->title), array('class' => 'card-title'));
            echo html_writer::tag('h6', format_string($companyname), array('class' => 'card-subtitle mb-2 text-muted'));
            if (!empty($job->companyprofileid)) {
                echo html_writer::link(
                    new moodle_url('/local/jobportal/company.php', array('id' => $job->companyprofileid)),
                    get_string('viewcompanyprofile', 'local_jobportal'),
                    array('class' => 'small d-inline-block mb-2')
                );
            }

            echo html_writer::start_tag('p', array('class' => 'card-text'));
            echo html_writer::tag('strong', get_string('jobtype', 'local_jobportal') . ': ');
            echo local_jobportal_format_jobtype($job->jobtype) . '<br>';

            if (!empty($job->location)) {
                echo html_writer::tag('strong', get_string('location', 'local_jobportal') . ': ');
                echo format_string($job->location) . '<br>';
            }

            $salarydisplay = local_jobportal_get_job_salary_display($job);
            if ($salarydisplay !== '') {
                echo html_writer::tag('strong', get_string('salary', 'local_jobportal') . ': ');
                echo format_string($salarydisplay) . '<br>';
            }

            if (!empty($job->deadline)) {
                echo html_writer::tag('strong', get_string('deadline', 'local_jobportal') . ': ');
                echo userdate($job->deadline, $datetimeformat) . '<br>';
            }

            echo html_writer::tag('strong', get_string('joblistedon', 'local_jobportal') . ': ');
            echo userdate($job->timecreated, $dateformat) . '<br>';

            echo html_writer::end_tag('p');

            echo html_writer::tag('p', shorten_text(strip_tags($job->description), 200));

            if (!empty($userapplicationsbyjob[$job->id])) {
                $application = $userapplicationsbyjob[$job->id];
                $events = !empty($usereventsbyapp[$application->id]) ? $usereventsbyapp[$application->id] : array();
                $shortliststatus = local_jobportal_get_applicant_visible_shortlist_status($application);
                $shortlistoptions = local_jobportal_get_shortlist_status_options();
                $shortlistlabel = isset($shortlistoptions[$shortliststatus]) ?
                    $shortlistoptions[$shortliststatus] : get_string('pending', 'local_jobportal');
                $shortlistclass = 'badge badge-secondary';
                if ($shortliststatus === 'pending') {
                    $shortlistclass = 'badge badge-warning';
                } else if ($shortliststatus === 'shortlisted') {
                    $shortlistclass = 'badge badge-success';
                } else if ($shortliststatus === 'notshortlisted') {
                    $shortlistclass = 'badge badge-danger';
                }

                $visiblestage = local_jobportal_get_applicant_visible_stage($application, $events, $stages);
                $poststatuslabel = get_string('poststagenotset', 'local_jobportal');
                if ($shortliststatus !== 'shortlisted') {
                    $poststatuslabel = '-';
                } else if ($visiblestage) {
                    $poststatuslabel = format_string($visiblestage->displayname);
                }
                $poststatusclass = 'badge badge-secondary';
                if ($visiblestage) {
                    switch ($visiblestage->shortname) {
                        case 'accepted':
                            $poststatusclass = 'badge badge-success';
                            break;
                        case 'rejected':
                            $poststatusclass = 'badge badge-danger';
                            break;
                        case 'offermade':
                            $poststatusclass = 'badge badge-primary';
                            break;
                        default:
                            $poststatusclass = 'badge badge-info';
                            break;
                    }
                }

                echo html_writer::tag(
                    'p',
                    html_writer::tag('strong', get_string('yourapplicationstatus', 'local_jobportal') . ': ') .
                    html_writer::tag('span', $shortlistlabel, array('class' => $shortlistclass)) .
                    ' ' .
                    html_writer::tag('span', get_string('postshortliststage', 'local_jobportal') . ': ', array('class' => 'ml-2')) .
                    ($shortliststatus === 'shortlisted'
                        ? html_writer::tag('span', $poststatuslabel, array('class' => $poststatusclass))
                        : $poststatuslabel),
                    array('class' => 'mb-2')
                );
            }

            echo html_writer::link($joburl, get_string('viewjob', 'local_jobportal'),
                array('class' => 'btn btn-primary'));

            echo html_writer::end_tag('div');
            echo html_writer::end_tag('div');
        }

        echo html_writer::end_tag('div');

        if ($totaljobs > $perpage) {
            echo $OUTPUT->paging_bar($totaljobs, $page, $perpage, $pagingurl);
        }
    }
}

echo $OUTPUT->footer();
