<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');
require_once(__DIR__ . '/locallib.php');

/**
 * Count applications that have reached a stage.
 *
 * @param string $shortname
 * @return int
 */
function local_jobportal_dashboard_count_stage_reached($shortname) {
    global $DB;

    if ($shortname === 'shortlisted' || $shortname === 'notshortlisted') {
        return (int)$DB->count_records('local_jobportal_applications', array('shortliststatus' => $shortname));
    }
    // Terminal offer outcomes must stay mutually exclusive; count current state only.
    if ($shortname === 'accepted' || $shortname === 'rejected') {
        return (int)$DB->count_records('local_jobportal_applications', array(
            'shortliststatus' => 'shortlisted',
            'status' => $shortname,
        ));
    }

    $poststages = local_jobportal_get_post_shortlist_stage_shortnames();
    if (in_array($shortname, $poststages, true)) {
        $sql = "SELECT COUNT(DISTINCT a.id)
                  FROM {local_jobportal_applications} a
             LEFT JOIN {local_jobportal_appstage_events} e
                    ON e.applicationid = a.id
             LEFT JOIN {local_jobportal_stages} s
                    ON s.id = e.stageid
                 WHERE a.shortliststatus = :shortlisted
                   AND (
                        s.shortname = :shortname
                        OR (
                            a.status = :statusfallback
                            AND NOT EXISTS (
                                SELECT 1
                                  FROM {local_jobportal_appstage_events} e2
                                 WHERE e2.applicationid = a.id
                            )
                        )
                   )";

        return (int)$DB->count_records_sql($sql, array(
            'shortlisted' => 'shortlisted',
            'shortname' => $shortname,
            'statusfallback' => $shortname,
        ));
    }

    $sql = "SELECT COUNT(DISTINCT a.id)
              FROM {local_jobportal_applications} a
         LEFT JOIN {local_jobportal_appstage_events} e
                ON e.applicationid = a.id
         LEFT JOIN {local_jobportal_stages} s
                ON s.id = e.stageid
             WHERE s.shortname = :shortname
                OR (
                    a.status = :statusfallback
                    AND NOT EXISTS (
                        SELECT 1
                          FROM {local_jobportal_appstage_events} e2
                         WHERE e2.applicationid = a.id
                    )
                )";

    return (int)$DB->count_records_sql($sql, array('shortname' => $shortname, 'statusfallback' => $shortname));
}

/**
 * Format conversion percentage.
 *
 * @param int $numerator
 * @param int $denominator
 * @return string
 */
function local_jobportal_dashboard_percent($numerator, $denominator) {
    if (empty($denominator)) {
        return '0%';
    }

    return format_float(($numerator / $denominator) * 100, 1) . '%';
}

require_login();

$context = context_system::instance();
require_capability('local/jobportal:managejobs', $context);

$staledays = optional_param('staledays', 7, PARAM_INT);
if ($staledays < 1) {
    $staledays = 1;
} else if ($staledays > 60) {
    $staledays = 60;
}
$stalesortby = optional_param('stalesortby', 'daysinactive', PARAM_ALPHANUMEXT);
$stalesortdir = optional_param('stalesortdir', 'desc', PARAM_ALPHA);
$staleallowedsort = array(
    'applicantname',
    'jobtitle',
    'company',
    'currentstage',
    'appliedon',
    'lastactivity',
    'daysinactive',
    'daysopen',
);
if (!in_array($stalesortby, $staleallowedsort, true)) {
    $stalesortby = 'daysinactive';
}
$stalesortdir = core_text::strtolower($stalesortdir) === 'asc' ? 'asc' : 'desc';

$companystatssortby = optional_param('companystatssortby', 'applicationsreceived', PARAM_ALPHANUMEXT);
$companystatssortdir = optional_param('companystatssortdir', 'desc', PARAM_ALPHA);
$companystatsallowedsort = array(
    'company',
    'jobsposted',
    'activejobs',
    'applicationsreceived',
    'shortlistedcount',
    'offermadecount',
    'acceptedcount',
    'notshortlistedcount',
);
if (!in_array($companystatssortby, $companystatsallowedsort, true)) {
    $companystatssortby = 'applicationsreceived';
}
$companystatssortdir = core_text::strtolower($companystatssortdir) === 'asc' ? 'asc' : 'desc';

$stalepage = optional_param('stalepage', 0, PARAM_INT);
if ($stalepage < 0) {
    $stalepage = 0;
}
$companystatspage = optional_param('companystatspage', 0, PARAM_INT);
if ($companystatspage < 0) {
    $companystatspage = 0;
}
$staleperpage = 10;
$companystatsperpage = 10;

$baseurl = new moodle_url('/local/jobportal/dashboard.php');
$urlparams = array(
    'staledays' => $staledays,
    'stalesortby' => $stalesortby,
    'stalesortdir' => $stalesortdir,
    'companystatssortby' => $companystatssortby,
    'companystatssortdir' => $companystatssortdir,
);
if (!empty($stalepage)) {
    $urlparams['stalepage'] = $stalepage;
}
if (!empty($companystatspage)) {
    $urlparams['companystatspage'] = $companystatspage;
}
$PAGE->set_context($context);
$PAGE->set_url($baseurl, $urlparams);
$PAGE->set_title(get_string('managerdashboard', 'local_jobportal'));
$PAGE->set_heading(get_string('managerdashboard', 'local_jobportal'));
local_jobportal_require_styles();
$dateformat = '%d/%m/%Y';

local_jobportal_ensure_default_stages();
$stages = local_jobportal_get_recruitment_stages(false);

$totalapplications = (int)$DB->count_records('local_jobportal_applications');
$shortlistedcount = local_jobportal_dashboard_count_stage_reached('shortlisted');
$offermadecount = local_jobportal_dashboard_count_stage_reached('offermade');

$activitysql = "SELECT a.id, a.jobid, a.userid, a.status, a.shortliststatus, a.currentstageid, a.timecreated, a.timemodified,
                       j.title AS jobtitle, c.name AS companyname,
                       u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
                       COALESCE(ls.laststageactivity, a.timemodified, a.timecreated) AS lastactivity
                  FROM {local_jobportal_applications} a
                  JOIN {local_jobportal_jobs} j ON j.id = a.jobid
             LEFT JOIN {local_jobportal_companies} c ON c.id = j.companyid
                  JOIN {user} u ON u.id = a.userid
             LEFT JOIN (
                    SELECT applicationid, MAX(timecreated) AS laststageactivity
                      FROM {local_jobportal_appstage_events}
                  GROUP BY applicationid
             ) ls ON ls.applicationid = a.id
              ORDER BY lastactivity ASC";
$applicationsforaging = $DB->get_records_sql($activitysql);

$activepipelinecount = 0;
$totaldaysopen = 0;
$staleapplications = array();
$now = time();
$shortlistoptions = local_jobportal_get_shortlist_status_options();

foreach ($applicationsforaging as $application) {
    $shortliststatus = local_jobportal_get_application_shortlist_status($application);
    $status = core_text::strtolower((string)$application->status);
    $ispostterminal = $shortliststatus === 'shortlisted' && in_array($status, array('accepted', 'rejected'), true);
    if ($shortliststatus === 'notshortlisted' || $ispostterminal) {
        continue;
    }

    $activepipelinecount++;
    $daysopen = max(0, (int)floor(($now - (int)$application->timecreated) / DAYSECS));
    $daysinactive = max(0, (int)floor(($now - (int)$application->lastactivity) / DAYSECS));
    $totaldaysopen += $daysopen;

    if ($daysinactive >= $staledays) {
        $application->daysopen = $daysopen;
        $application->daysinactive = $daysinactive;
        $shortlistlabel = isset($shortlistoptions[$shortliststatus]) ?
            $shortlistoptions[$shortliststatus] : get_string('pending', 'local_jobportal');
        $stage = local_jobportal_get_application_stage($application, $stages);
        $stagename = $shortlistlabel;
        if ($shortliststatus === 'shortlisted') {
            if ($stage) {
                $stagename .= ' / ' . format_string($stage->displayname);
            } else {
                $stagename .= ' / ' . get_string('poststagenotset', 'local_jobportal');
            }
        }
        $application->stagedisplay = $stagename;
        $application->applicantnamedisplay = fullname($application);
        $application->companydisplay = !empty($application->companyname) ? $application->companyname : '-';
        $staleapplications[] = $application;
    }
}

usort($staleapplications, function($a, $b) use ($stalesortby, $stalesortdir) {
    $cmp = 0;
    switch ($stalesortby) {
        case 'applicantname':
            $cmp = strcmp(core_text::strtolower($a->applicantnamedisplay), core_text::strtolower($b->applicantnamedisplay));
            break;
        case 'jobtitle':
            $cmp = strcmp(core_text::strtolower((string)$a->jobtitle), core_text::strtolower((string)$b->jobtitle));
            break;
        case 'company':
            $cmp = strcmp(core_text::strtolower((string)$a->companydisplay), core_text::strtolower((string)$b->companydisplay));
            break;
        case 'currentstage':
            $cmp = strcmp(core_text::strtolower((string)$a->stagedisplay), core_text::strtolower((string)$b->stagedisplay));
            break;
        case 'appliedon':
            $cmp = ((int)$a->timecreated <=> (int)$b->timecreated);
            break;
        case 'lastactivity':
            $cmp = ((int)$a->lastactivity <=> (int)$b->lastactivity);
            break;
        case 'daysopen':
            $cmp = ((int)$a->daysopen <=> (int)$b->daysopen);
            break;
        case 'daysinactive':
        default:
            $cmp = ((int)$a->daysinactive <=> (int)$b->daysinactive);
            break;
    }

    if ($stalesortdir === 'desc') {
        $cmp *= -1;
    }
    if ($cmp === 0) {
        $cmp = ((int)$b->daysinactive <=> (int)$a->daysinactive);
    }
    if ($cmp === 0) {
        $cmp = ((int)$b->id <=> (int)$a->id);
    }
    return $cmp;
});
$staletotalcount = count($staleapplications);
$staleapplications = array_slice($staleapplications, $stalepage * $staleperpage, $staleperpage);

$avgdaysopen = $activepipelinecount > 0 ? format_float($totaldaysopen / $activepipelinecount, 1) : '0.0';

$companysql = "SELECT c.id, c.name,
                      (SELECT COUNT(1) FROM {local_jobportal_jobs} j WHERE j.companyid = c.id) AS jobsposted,
                      (SELECT COUNT(1) FROM {local_jobportal_jobs} j WHERE j.companyid = c.id AND j.status = 1) AS activejobs,
                      (SELECT COUNT(1)
                         FROM {local_jobportal_applications} a
                         JOIN {local_jobportal_jobs} j ON j.id = a.jobid
                        WHERE j.companyid = c.id) AS applicationsreceived,
                      (SELECT COUNT(1)
                         FROM {local_jobportal_applications} a
                         JOIN {local_jobportal_jobs} j ON j.id = a.jobid
                        WHERE j.companyid = c.id AND a.shortliststatus = :shortlisted1) AS shortlistedcount,
                      (SELECT COUNT(1)
                         FROM {local_jobportal_applications} a
                         JOIN {local_jobportal_jobs} j ON j.id = a.jobid
                        WHERE j.companyid = c.id AND a.shortliststatus = :shortlisted2 AND a.status = :offermade) AS offermadecount,
                      (SELECT COUNT(1)
                         FROM {local_jobportal_applications} a
                         JOIN {local_jobportal_jobs} j ON j.id = a.jobid
                        WHERE j.companyid = c.id AND a.shortliststatus = :shortlisted3 AND a.status = :accepted) AS acceptedcount,
                      (SELECT COUNT(1)
                         FROM {local_jobportal_applications} a
                         JOIN {local_jobportal_jobs} j ON j.id = a.jobid
                        WHERE j.companyid = c.id AND a.shortliststatus = :notshortlisted) AS notshortlistedcount
                 FROM {local_jobportal_companies} c
             ORDER BY applicationsreceived DESC, c.name ASC";
$companystats = $DB->get_records_sql($companysql, array(
    'shortlisted1' => 'shortlisted',
    'shortlisted2' => 'shortlisted',
    'shortlisted3' => 'shortlisted',
    'offermade' => 'offermade',
    'accepted' => 'accepted',
    'notshortlisted' => 'notshortlisted',
));
$companystats = array_values($companystats);

usort($companystats, function($a, $b) use ($companystatssortby, $companystatssortdir) {
    $cmp = 0;
    switch ($companystatssortby) {
        case 'company':
            $cmp = strcmp(core_text::strtolower((string)$a->name), core_text::strtolower((string)$b->name));
            break;
        case 'jobsposted':
            $cmp = ((int)$a->jobsposted <=> (int)$b->jobsposted);
            break;
        case 'activejobs':
            $cmp = ((int)$a->activejobs <=> (int)$b->activejobs);
            break;
        case 'shortlistedcount':
            $cmp = ((int)$a->shortlistedcount <=> (int)$b->shortlistedcount);
            break;
        case 'offermadecount':
            $cmp = ((int)$a->offermadecount <=> (int)$b->offermadecount);
            break;
        case 'acceptedcount':
            $cmp = ((int)$a->acceptedcount <=> (int)$b->acceptedcount);
            break;
        case 'notshortlistedcount':
            $cmp = ((int)$a->notshortlistedcount <=> (int)$b->notshortlistedcount);
            break;
        case 'applicationsreceived':
        default:
            $cmp = ((int)$a->applicationsreceived <=> (int)$b->applicationsreceived);
            break;
    }

    if ($companystatssortdir === 'desc') {
        $cmp *= -1;
    }
    if ($cmp === 0) {
        $cmp = strcmp(core_text::strtolower((string)$a->name), core_text::strtolower((string)$b->name));
    }
    if ($cmp === 0) {
        $cmp = ((int)$a->id <=> (int)$b->id);
    }
    return $cmp;
});

$companystatstotalcount = count($companystats);
$companystatspaged = array_slice($companystats, $companystatspage * $companystatsperpage, $companystatsperpage);

echo $OUTPUT->header();
echo local_jobportal_render_navigation($context, 'dashboard');

echo html_writer::start_tag('div', array('class' => 'card mb-3'));
echo html_writer::start_tag('div', array('class' => 'card-body'));
echo html_writer::tag('h4', get_string('funnelanalytics', 'local_jobportal'), array('class' => 'card-title mb-3'));

echo html_writer::start_tag('div', array('class' => 'table-responsive'));
echo html_writer::start_tag('table', array('class' => 'table table-sm table-striped table-bordered mb-0 jp-table jp-data-table jp-funnel-table'));
echo html_writer::tag(
    'thead',
    html_writer::tag(
        'tr',
        html_writer::tag('th', get_string('stage', 'local_jobportal')) .
        html_writer::tag('th', get_string('reachedcount', 'local_jobportal')) .
        html_writer::tag('th', get_string('conversionfromapplied', 'local_jobportal')) .
        html_writer::tag('th', get_string('conversionfromprevious', 'local_jobportal'))
    )
);

echo html_writer::start_tag('tbody');
echo html_writer::tag(
    'tr',
    html_writer::tag('td', get_string('applied', 'local_jobportal')) .
    html_writer::tag('td', $totalapplications) .
    html_writer::tag('td', local_jobportal_dashboard_percent($totalapplications, $totalapplications)) .
    html_writer::tag('td', '-')
);
echo html_writer::tag(
    'tr',
    html_writer::tag('td', get_string('shortlisted', 'local_jobportal')) .
    html_writer::tag('td', $shortlistedcount) .
    html_writer::tag('td', local_jobportal_dashboard_percent($shortlistedcount, $totalapplications)) .
    html_writer::tag('td', local_jobportal_dashboard_percent($shortlistedcount, $totalapplications))
);
echo html_writer::tag(
    'tr',
    html_writer::tag('td', get_string('offermade', 'local_jobportal')) .
    html_writer::tag('td', $offermadecount) .
    html_writer::tag('td', local_jobportal_dashboard_percent($offermadecount, $totalapplications)) .
    html_writer::tag('td', local_jobportal_dashboard_percent($offermadecount, $shortlistedcount))
);
echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', array('class' => 'card mb-3'));
echo html_writer::start_tag('div', array('class' => 'card-body'));
echo html_writer::tag('h4', get_string('agingandstale', 'local_jobportal'), array('class' => 'card-title mb-3'));

echo html_writer::start_tag('div', array('class' => 'row mb-3'));
echo html_writer::tag(
    'div',
    html_writer::tag('div', get_string('activepipeline', 'local_jobportal'), array('class' => 'text-muted')) .
    html_writer::tag('div', $activepipelinecount, array('class' => 'h4 mb-0')),
    array('class' => 'col-md-3')
);
echo html_writer::tag(
    'div',
    html_writer::tag('div', get_string('staleapplications', 'local_jobportal'), array('class' => 'text-muted')) .
    html_writer::tag('div', $staletotalcount, array('class' => 'h4 mb-0')),
    array('class' => 'col-md-3')
);
echo html_writer::tag(
    'div',
    html_writer::tag('div', get_string('avgdaysopen', 'local_jobportal'), array('class' => 'text-muted')) .
    html_writer::tag('div', $avgdaysopen, array('class' => 'h4 mb-0')),
    array('class' => 'col-md-3')
);
echo html_writer::end_tag('div');

$staleoptions = array(3 => 3, 7 => 7, 14 => 14, 30 => 30, 60 => 60);
if (!isset($staleoptions[$staledays])) {
    $staleoptions[$staledays] = $staledays;
    ksort($staleoptions);
}

echo html_writer::start_tag('form', array('method' => 'get', 'action' => $baseurl, 'class' => 'form-inline mb-3'));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'stalesortby', 'value' => $stalesortby));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'stalesortdir', 'value' => $stalesortdir));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'companystatssortby', 'value' => $companystatssortby));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'companystatssortdir', 'value' => $companystatssortdir));
echo html_writer::tag('label', get_string('stalethreshold', 'local_jobportal') . ': ', array('class' => 'mr-2'));
echo html_writer::select($staleoptions, 'staledays', $staledays, false, array('class' => 'custom-select custom-select-sm mr-2'));
echo html_writer::tag('button', get_string('filter'), array('type' => 'submit', 'class' => 'btn btn-sm btn-outline-secondary'));
echo html_writer::end_tag('form');

if (empty($staletotalcount)) {
    echo html_writer::tag('p', get_string('nodataavailable', 'local_jobportal'), array('class' => 'text-muted mb-0'));
} else {
    $stalepagingurl = new moodle_url('/local/jobportal/dashboard.php', array(
        'staledays' => $staledays,
        'stalesortby' => $stalesortby,
        'stalesortdir' => $stalesortdir,
        'companystatssortby' => $companystatssortby,
        'companystatssortdir' => $companystatssortdir,
        'companystatspage' => $companystatspage,
    ));
    if ($staletotalcount > $staleperpage) {
        echo $OUTPUT->paging_bar($staletotalcount, $stalepage, $staleperpage, $stalepagingurl, 'stalepage');
    }

    echo html_writer::start_tag('div', array('class' => 'table-responsive'));
    echo html_writer::start_tag('table', array('class' => 'table table-sm table-striped table-bordered mb-0 jp-table jp-data-table jp-stale-table'));
    $staleheader = function($key, $label) use (
        $staledays,
        $stalesortby,
        $stalesortdir,
        $companystatssortby,
        $companystatssortdir,
        $companystatspage
    ) {
        $defaultdir = in_array($key, array('applicantname', 'jobtitle', 'company', 'currentstage'), true) ? 'asc' : 'desc';
        $nextdir = ($stalesortby === $key && $stalesortdir === 'asc') ? 'desc' : 'asc';
        if ($stalesortby !== $key) {
            $nextdir = $defaultdir;
        }
        $params = array(
            'staledays' => $staledays,
            'stalesortby' => $key,
            'stalesortdir' => $nextdir,
            'companystatssortby' => $companystatssortby,
            'companystatssortdir' => $companystatssortdir,
            'stalepage' => 0,
            'companystatspage' => $companystatspage,
        );
        $content = s($label);
        if ($stalesortby === $key) {
            $content .= ' <span class="jp-sort-indicator">' . ($stalesortdir === 'asc' ? '↑' : '↓') . '</span>';
        }
        return html_writer::link(new moodle_url('/local/jobportal/dashboard.php', $params), $content, array('class' => 'jp-sort-header'));
    };
    echo html_writer::tag(
        'thead',
        html_writer::tag(
            'tr',
            html_writer::tag('th', $staleheader('applicantname', get_string('applicantname', 'local_jobportal'))) .
            html_writer::tag('th', $staleheader('jobtitle', get_string('jobtitle', 'local_jobportal'))) .
            html_writer::tag('th', $staleheader('company', get_string('company', 'local_jobportal'))) .
            html_writer::tag('th', $staleheader('currentstage', get_string('currentstage', 'local_jobportal'))) .
            html_writer::tag('th', $staleheader('appliedon', get_string('appliedon', 'local_jobportal'))) .
            html_writer::tag('th', $staleheader('lastactivity', get_string('lastactivity', 'local_jobportal'))) .
            html_writer::tag('th', $staleheader('daysinactive', get_string('daysinactive', 'local_jobportal'))) .
            html_writer::tag('th', $staleheader('daysopen', get_string('daysopen', 'local_jobportal'))) .
            html_writer::tag('th', get_string('actions'))
        )
    );
    echo html_writer::start_tag('tbody');
    foreach ($staleapplications as $application) {
        echo html_writer::tag(
            'tr',
            html_writer::tag('td', s($application->applicantnamedisplay)) .
            html_writer::tag('td', format_string($application->jobtitle)) .
            html_writer::tag('td', s($application->companydisplay)) .
            html_writer::tag('td', s($application->stagedisplay)) .
            html_writer::tag('td', userdate($application->timecreated, $dateformat)) .
            html_writer::tag('td', userdate($application->lastactivity, $dateformat)) .
            html_writer::tag('td', (int)$application->daysinactive) .
            html_writer::tag('td', (int)$application->daysopen) .
            html_writer::tag(
                'td',
                html_writer::link(
                    new moodle_url('/local/jobportal/applications.php', array('jobid' => $application->jobid)),
                    get_string('viewapplications', 'local_jobportal')
                )
            )
        );
    }
    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_tag('div');

    if ($staletotalcount > $staleperpage) {
        echo $OUTPUT->paging_bar($staletotalcount, $stalepage, $staleperpage, $stalepagingurl, 'stalepage');
    }
}

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', array('class' => 'card mb-3'));
echo html_writer::start_tag('div', array('class' => 'card-body'));
echo html_writer::tag('h4', get_string('percompanystats', 'local_jobportal'), array('class' => 'card-title mb-3'));

if (empty($companystatstotalcount)) {
    echo html_writer::tag('p', get_string('nodataavailable', 'local_jobportal'), array('class' => 'text-muted mb-0'));
} else {
    $companystatspagingurl = new moodle_url('/local/jobportal/dashboard.php', array(
        'staledays' => $staledays,
        'stalesortby' => $stalesortby,
        'stalesortdir' => $stalesortdir,
        'companystatssortby' => $companystatssortby,
        'companystatssortdir' => $companystatssortdir,
        'stalepage' => $stalepage,
    ));
    if ($companystatstotalcount > $companystatsperpage) {
        echo $OUTPUT->paging_bar($companystatstotalcount, $companystatspage, $companystatsperpage, $companystatspagingurl, 'companystatspage');
    }

    echo html_writer::start_tag('div', array('class' => 'table-responsive'));
    echo html_writer::start_tag('table', array('class' => 'table table-sm table-striped table-bordered mb-0 jp-table jp-data-table jp-company-stats-table'));
    $companystatsheader = function($key, $label) use (
        $staledays,
        $stalepage,
        $stalesortby,
        $stalesortdir,
        $companystatssortby,
        $companystatssortdir
    ) {
        $defaultdir = $key === 'company' ? 'asc' : 'desc';
        $nextdir = ($companystatssortby === $key && $companystatssortdir === 'asc') ? 'desc' : 'asc';
        if ($companystatssortby !== $key) {
            $nextdir = $defaultdir;
        }
        $params = array(
            'staledays' => $staledays,
            'stalesortby' => $stalesortby,
            'stalesortdir' => $stalesortdir,
            'companystatssortby' => $key,
            'companystatssortdir' => $nextdir,
            'stalepage' => $stalepage,
            'companystatspage' => 0,
        );
        $content = s($label);
        if ($companystatssortby === $key) {
            $content .= ' <span class="jp-sort-indicator">' . ($companystatssortdir === 'asc' ? '↑' : '↓') . '</span>';
        }
        return html_writer::link(new moodle_url('/local/jobportal/dashboard.php', $params), $content, array('class' => 'jp-sort-header'));
    };
    echo html_writer::tag(
        'thead',
        html_writer::tag(
            'tr',
            html_writer::tag('th', $companystatsheader('company', get_string('company', 'local_jobportal'))) .
            html_writer::tag('th', $companystatsheader('jobsposted', get_string('jobsposted', 'local_jobportal'))) .
            html_writer::tag('th', $companystatsheader('activejobs', get_string('activejobs', 'local_jobportal'))) .
            html_writer::tag('th', $companystatsheader('applicationsreceived', get_string('applicationsreceived', 'local_jobportal'))) .
            html_writer::tag('th', $companystatsheader('shortlistedcount', get_string('shortlisted', 'local_jobportal'))) .
            html_writer::tag('th', $companystatsheader('offermadecount', get_string('offermadecount', 'local_jobportal'))) .
            html_writer::tag('th', $companystatsheader('acceptedcount', get_string('offeracceptedcount', 'local_jobportal'))) .
            html_writer::tag('th', $companystatsheader('notshortlistedcount', get_string('notshortlistedcount', 'local_jobportal'))) .
            html_writer::tag('th', get_string('actions'))
        )
    );
    echo html_writer::start_tag('tbody');

    foreach ($companystatspaged as $company) {
        echo html_writer::tag(
            'tr',
            html_writer::tag('td', format_string($company->name)) .
            html_writer::tag('td', (int)$company->jobsposted) .
            html_writer::tag('td', (int)$company->activejobs) .
            html_writer::tag('td', (int)$company->applicationsreceived) .
            html_writer::tag('td', (int)$company->shortlistedcount) .
            html_writer::tag('td', (int)$company->offermadecount) .
            html_writer::tag('td', (int)$company->acceptedcount) .
            html_writer::tag('td', (int)$company->notshortlistedcount) .
            html_writer::tag(
                'td',
                html_writer::link(
                    new moodle_url('/local/jobportal/company.php', array('id' => $company->id)),
                    get_string('viewcompanyprofile', 'local_jobportal')
                )
            )
        );
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_tag('div');

    if ($companystatstotalcount > $companystatsperpage) {
        echo $OUTPUT->paging_bar($companystatstotalcount, $companystatspage, $companystatsperpage, $companystatspagingurl, 'companystatspage');
    }
}

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

echo $OUTPUT->footer();
