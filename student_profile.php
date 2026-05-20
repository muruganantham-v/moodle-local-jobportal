<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');
require_once(__DIR__ . '/locallib.php');

require_login();

$userid = required_param('userid', PARAM_INT);
$context = context_system::instance();

$canviewapplications = has_capability('local/jobportal:viewapplications', $context);
$canreviewresumes = has_capability('local/jobportal:reviewresumes', $context);
$canassignreviewers = has_capability('local/jobportal:assignresumereviewers', $context);
$canviewprofile = $canviewapplications || $canreviewresumes || $canassignreviewers;
if (!$canviewprofile) {
    require_capability('local/jobportal:viewapplications', $context);
}

$student = $DB->get_record(
    'user',
    array('id' => (int)$userid, 'deleted' => 0),
    'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename, email, phone1, phone2, city, country, picture, imagealt, timecreated, lastaccess',
    MUST_EXIST
);

$profile = $DB->get_record('local_jobportal_profiles', array('userid' => (int)$userid), '*', IGNORE_MISSING);
if ($profile) {
    $refresh = local_jobportal_refresh_profile_resume_review((int)$profile->id, $context);
    $profile = $refresh->profile;
}

$baseurl = new moodle_url('/local/jobportal/student_profile.php', array('userid' => (int)$userid));
$PAGE->set_context($context);
$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('studentprofile', 'local_jobportal'));
$PAGE->set_heading(get_string('studentprofile', 'local_jobportal'));
local_jobportal_require_styles();
$dateformat = '%d/%m/%Y';
$datetimeformat = '%d/%m/%Y %I:%M %p';

$resumestatusoptions = local_jobportal_get_resume_status_options();
$status = !empty($profile) ? local_jobportal_normalize_resume_status($profile->resumestatus) : 'notsubmitted';
$statuslabel = isset($resumestatusoptions[$status]) ? $resumestatusoptions[$status] : $status;
$statusbadge = local_jobportal_resume_status_badge_class($status);

$reviewername = '';
$history = array();
$resumedownloadurl = null;
$resumepreviewurl = null;
$resumecanpreview = false;
if ($profile) {
    if (!empty($profile->resumereviewedby)) {
        $reviewer = $DB->get_record(
            'user',
            array('id' => (int)$profile->resumereviewedby),
            'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename',
            IGNORE_MISSING
        );
        if ($reviewer) {
            $reviewername = fullname($reviewer);
        }
    }

    $historysql = "SELECT h.*, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
                     FROM {local_jobportal_resume_review_hist} h
                     JOIN {user} u ON u.id = h.userid
                    WHERE h.profileid = :profileid
                 ORDER BY h.timecreated DESC";
    $history = $DB->get_records_sql($historysql, array('profileid' => (int)$profile->id), 0, 15);

    $resumefile = local_jobportal_get_profile_resume_file((int)$profile->id, $context);
    if ($resumefile) {
        $resumedownloadurl = local_jobportal_get_profile_resume_url((int)$profile->id, $context, true);
        if (local_jobportal_resume_file_is_previewable($resumefile)) {
            $resumecanpreview = true;
            $resumepreviewurl = local_jobportal_get_profile_resume_url((int)$profile->id, $context, false);
        }
    }
}

if ($resumecanpreview && $resumepreviewurl) {
    $PAGE->requires->js_call_amd('local_jobportal/resume_preview', 'init');
}

$totalapplications = (int)$DB->count_records('local_jobportal_applications', array('userid' => (int)$userid));
$activeapplications = local_jobportal_count_student_active_applications((int)$userid);
$totalshortlisted = (int)$DB->count_records('local_jobportal_applications', array(
    'userid' => (int)$userid,
    'shortliststatus' => 'shortlisted',
));
$lastshortlistedrecord = $DB->get_record_sql(
    "SELECT id, timemodified, timecreated
       FROM {local_jobportal_applications}
      WHERE userid = :userid
        AND shortliststatus = :shortliststatus
   ORDER BY timemodified DESC, timecreated DESC, id DESC",
    array(
        'userid' => (int)$userid,
        'shortliststatus' => 'shortlisted',
    ),
    IGNORE_MISSING
);
$offerhighlight = local_jobportal_get_student_offer_highlight((int)$userid);
$applylockinfo = local_jobportal_get_student_apply_lock_info((int)$userid);
$latestapplication = $DB->get_record_sql(
    "SELECT a.id, a.jobid, a.timecreated, j.title
       FROM {local_jobportal_applications} a
       JOIN {local_jobportal_jobs} j ON j.id = a.jobid
      WHERE a.userid = :userid
   ORDER BY a.timecreated DESC, a.id DESC",
    array('userid' => (int)$userid),
    IGNORE_MISSING
);

$recentapplications = $DB->get_records_sql(
    "SELECT a.id, a.jobid, a.shortliststatus, a.status, a.currentstageid, a.timecreated, a.timemodified, j.title
       FROM {local_jobportal_applications} a
       JOIN {local_jobportal_jobs} j ON j.id = a.jobid
      WHERE a.userid = :userid
   ORDER BY a.timecreated DESC, a.id DESC",
    array('userid' => (int)$userid),
    0,
    15
);
$stages = local_jobportal_get_recruitment_stages(false);
$shortlistoptions = local_jobportal_get_shortlist_status_options();
$roundevents = $DB->get_records_sql(
    "SELECT e.id, e.scheduledat, e.schedulestatus, e.roundoutcome, e.timecreated, s.shortname
       FROM {local_jobportal_appstage_events} e
       JOIN {local_jobportal_applications} a ON a.id = e.applicationid
       JOIN {local_jobportal_stages} s ON s.id = e.stageid
      WHERE a.userid = :userid
        AND s.shortname IN (:teststage, :interviewstage)
   ORDER BY e.timecreated DESC, e.id DESC",
    array(
        'userid' => (int)$userid,
        'teststage' => 'testscheduled',
        'interviewstage' => 'interviewscheduled',
    )
);

$phone = !empty($student->phone1) ? $student->phone1 : $student->phone2;
$studentname = fullname($student);
$firstinitial = core_text::substr(trim((string)$student->firstname), 0, 1);
$secondinitial = core_text::substr(trim((string)$student->lastname), 0, 1);
$studentinitials = core_text::strtoupper($firstinitial . $secondinitial);
if ($studentinitials === '') {
    $studentinitials = '?';
}
$hasprofilephoto = !empty($student->picture);

$profileupdatedlabel = (!empty($profile) && !empty($profile->timemodified)) ?
    userdate((int)$profile->timemodified, $datetimeformat) : '-';
$latestlabel = $latestapplication ? userdate((int)$latestapplication->timecreated, $datetimeformat) : '-';
$lastshortlistedlabel = '-';
if ($lastshortlistedrecord) {
    $lastshortlistedtime = !empty($lastshortlistedrecord->timemodified) ?
        (int)$lastshortlistedrecord->timemodified : (int)$lastshortlistedrecord->timecreated;
    $lastshortlistedlabel = userdate($lastshortlistedtime, $datetimeformat);
}
$roundsummary = array(
    'total' => 0,
    'completed' => 0,
    'noshow' => 0,
    'cleared' => 0,
    'notcleared' => 0,
);
$roundstatsbytype = array(
    'test' => array('total' => 0, 'completed' => 0, 'cleared' => 0, 'notcleared' => 0),
    'interview' => array('total' => 0, 'completed' => 0, 'cleared' => 0, 'notcleared' => 0),
);
$latestround = null;
$nextround = null;
$now = time();
foreach ($roundevents as $roundevent) {
    $type = ($roundevent->shortname === 'testscheduled') ? 'test' : 'interview';
    $eventtime = !empty($roundevent->scheduledat) ? (int)$roundevent->scheduledat : (int)$roundevent->timecreated;
    $status = local_jobportal_normalize_schedule_status($roundevent->schedulestatus);
    $outcome = local_jobportal_normalize_round_outcome($roundevent->roundoutcome);

    $roundsummary['total']++;
    $roundstatsbytype[$type]['total']++;

    if ($status === 'completed') {
        $roundsummary['completed']++;
        $roundstatsbytype[$type]['completed']++;
    }
    if ($status === 'noshow') {
        $roundsummary['noshow']++;
    }
    if ($outcome === 'cleared') {
        $roundsummary['cleared']++;
        $roundstatsbytype[$type]['cleared']++;
    } else if ($outcome === 'notcleared') {
        $roundsummary['notcleared']++;
        $roundstatsbytype[$type]['notcleared']++;
    }

    if ($latestround === null || $eventtime > $latestround['time']) {
        $latestround = array(
            'type' => $type,
            'time' => $eventtime,
            'status' => $status,
            'outcome' => $outcome,
        );
    }

    if (($status === 'scheduled' || $status === 'rescheduled') && !empty($roundevent->scheduledat) && (int)$roundevent->scheduledat > $now) {
        if ($nextround === null || (int)$roundevent->scheduledat < $nextround['time']) {
            $nextround = array(
                'type' => $type,
                'time' => (int)$roundevent->scheduledat,
                'status' => $status,
            );
        }
    }
}

$getschedulestatusbadgeclass = static function($status) {
    $normalized = local_jobportal_normalize_schedule_status($status);
    if (in_array($normalized, array('completed', 'excused'), true)) {
        return 'badge badge-success';
    }
    if ($normalized === 'cancelled' || $normalized === 'noshow') {
        return 'badge badge-danger';
    }
    if ($normalized === 'rescheduled') {
        return 'badge badge-info';
    }
    return 'badge badge-primary';
};

$buildroundsnapshothtml = static function($round, $datetimeformat, $includeoutcome = false) use ($getschedulestatusbadgeclass) {
    if ($round === null) {
        return html_writer::tag('span', '-', array('class' => 'jp-round-snapshot-empty'));
    }

    $typelabel = ($round['type'] === 'test') ? get_string('roundtype_test', 'local_jobportal') : get_string('roundtype_interview', 'local_jobportal');
    $timelabel = userdate((int)$round['time'], $datetimeformat);
    $statuslabel = local_jobportal_get_schedule_status_label($round['status']);
    $statusbadge = html_writer::tag(
        'span',
        s($statuslabel),
        array('class' => $getschedulestatusbadgeclass($round['status']) . ' jp-round-snapshot-badge')
    );

    $metaitems = array($statusbadge);
    if ($includeoutcome && isset($round['outcome']) && $round['outcome'] !== 'pending') {
        $outcomelabel = local_jobportal_get_round_outcome_label($round['outcome']);
        $outcomebadgeclass = ($round['outcome'] === 'cleared') ? 'badge badge-success' : 'badge badge-warning';
        $metaitems[] = html_writer::tag(
            'span',
            s($outcomelabel),
            array('class' => $outcomebadgeclass . ' jp-round-snapshot-badge')
        );
    }

    $html = html_writer::start_div('jp-round-snapshot');
    $html .= html_writer::div(s($typelabel), 'jp-round-snapshot-type');
    $html .= html_writer::div(s($timelabel), 'jp-round-snapshot-time');
    $html .= html_writer::div(implode('', $metaitems), 'jp-round-snapshot-meta');
    $html .= html_writer::end_div();
    return $html;
};

$latestroundlabel = $buildroundsnapshothtml($latestround, $datetimeformat, true);
$nextroundlabel = $buildroundsnapshothtml($nextround, $datetimeformat, false);

$buildroundprogresshtml = static function($stats) {
    $total = max(0, (int)$stats['total']);
    $completed = max(0, min($total, (int)$stats['completed']));
    $cleared = max(0, min($completed, (int)$stats['cleared']));
    $notcleared = max(0, (int)$stats['notcleared']);

    $completedpct = ($total > 0) ? (int)round(($completed / $total) * 100) : 0;
    $clearedpct = ($completed > 0) ? (int)round(($cleared / $completed) * 100) : 0;
    $completedpct = max(0, min(100, $completedpct));
    $clearedpct = max(0, min(100, $clearedpct));

    $html = html_writer::start_div('jp-round-progress-summary');
    $html .= html_writer::div(
        get_string('totalrounds', 'local_jobportal') . ': ' . $total,
        'jp-round-progress-total'
    );

    $completedlabel = get_string('completedrounds', 'local_jobportal');
    $completedvalue = $completed . '/' . $total . ' (' . $completedpct . '%)';
    $html .= html_writer::start_div('jp-round-progress-row');
    $html .= html_writer::div($completedlabel, 'jp-round-progress-label');
    $html .= html_writer::div($completedvalue, 'jp-round-progress-value');
    $html .= html_writer::end_div();
    $html .= html_writer::tag(
        'div',
        html_writer::tag(
            'span',
            '',
            array(
                'class' => 'jp-round-progress-fill jp-round-progress-fill-completed',
                'style' => 'width: ' . $completedpct . '%;',
            )
        ),
        array(
            'class' => 'jp-round-progress-track',
            'role' => 'progressbar',
            'aria-valuemin' => '0',
            'aria-valuemax' => '100',
            'aria-valuenow' => (string)$completedpct,
            'aria-label' => $completedlabel,
        )
    );

    $clearedlabel = get_string('clearedrounds', 'local_jobportal');
    $cleareddenominator = $completed;
    $clearedvalue = $cleared . '/' . $cleareddenominator . ' (' . $clearedpct . '%)';
    $html .= html_writer::start_div('jp-round-progress-row');
    $html .= html_writer::div($clearedlabel, 'jp-round-progress-label');
    $html .= html_writer::div($clearedvalue, 'jp-round-progress-value');
    $html .= html_writer::end_div();
    $html .= html_writer::tag(
        'div',
        html_writer::tag(
            'span',
            '',
            array(
                'class' => 'jp-round-progress-fill jp-round-progress-fill-cleared',
                'style' => 'width: ' . $clearedpct . '%;',
            )
        ),
        array(
            'class' => 'jp-round-progress-track',
            'role' => 'progressbar',
            'aria-valuemin' => '0',
            'aria-valuemax' => '100',
            'aria-valuenow' => (string)$clearedpct,
            'aria-label' => $clearedlabel,
        )
    );

    if ($notcleared > 0) {
        $html .= html_writer::tag(
            'span',
            get_string('notclearedrounds', 'local_jobportal') . ': ' . $notcleared,
            array('class' => 'jp-round-progress-chip jp-round-progress-chip-notcleared')
        );
    }

    $html .= html_writer::end_div();
    return $html;
};

$testroundsummaryhtml = $buildroundprogresshtml($roundstatsbytype['test']);
$interviewroundsummaryhtml = $buildroundprogresshtml($roundstatsbytype['interview']);

$reviewedlabel = '-';
if (!empty($profile) && !empty($profile->resumereviewedat)) {
    $reviewedlabel = userdate((int)$profile->resumereviewedat, $datetimeformat);
}
$resumeratinglabel = (!empty($profile) && $profile->resumerating !== null && $profile->resumerating !== '') ?
    ((int)$profile->resumerating . '/5') : '-';
$reviewernotelabel = ($reviewedlabel !== '-' && $reviewername !== '') ? s($reviewername) : '';
$resumefeedbacklabel = (!empty($profile) && trim((string)$profile->resumefeedback) !== '') ? s($profile->resumefeedback) : '-';
$portfoliohtml = '-';
if (!empty($profile) && trim((string)$profile->portfolio) !== '') {
    $portfolio = trim((string)$profile->portfolio);
    if (filter_var($portfolio, FILTER_VALIDATE_URL)) {
        $portfoliohtml = html_writer::link(
            new moodle_url($portfolio),
            s($portfolio),
            array('target' => '_blank', 'rel' => 'noopener')
        );
    } else {
        $portfoliohtml = s($portfolio);
    }
}

$navextralinks = array(
    array(
        'key' => 'studentprofile',
        'label' => get_string('viewstudentprofile', 'local_jobportal'),
        'url' => $baseurl,
    ),
);

echo $OUTPUT->header();
echo local_jobportal_render_navigation($context, 'studentprofile', $navextralinks);
echo html_writer::start_div('jp-student-profile-page');

$badges = html_writer::tag('span', $statuslabel, array('class' => $statusbadge . ' mr-2'));

echo html_writer::start_div('jp-header-card jp-student-header-card');
echo html_writer::start_div('jp-header-main');
echo html_writer::start_div('jp-header-main-left');
echo html_writer::start_div('jp-header-brand');
if ($hasprofilephoto) {
    echo html_writer::start_div('jp-student-avatar-photo');
    echo $OUTPUT->user_picture($student, array('size' => 100, 'link' => false));
    echo html_writer::end_div();
} else {
    echo html_writer::tag('div', s($studentinitials), array('class' => 'jp-header-company-fallback jp-student-avatar'));
}
echo html_writer::start_div('jp-header-brand-text');
echo html_writer::tag('h3', $studentname);
echo html_writer::tag('h5', s($student->email), array('class' => 'mt-2 mb-0 jp-student-email'));
echo html_writer::tag('div', $badges, array('class' => 'mt-2 jp-student-badges'));
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('jp-header-main-right');
echo html_writer::tag('div', get_string('applicantphone', 'local_jobportal') . ': ' . s($phone ?: '-'), array('class' => 'jp-header-meta-item'));
echo html_writer::tag('div', get_string('applicantcity', 'local_jobportal') . ': ' . s($student->city ?: '-'), array('class' => 'jp-header-meta-item'));
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

if (!empty($offerhighlight->hasoffer)) {
    $statusclass = preg_replace('/[^a-z0-9_-]/i', '', (string)$offerhighlight->status);
    $jobtitle = format_string($offerhighlight->jobtitle);
    $company = trim(format_string($offerhighlight->company));
    $offerdate = !empty($offerhighlight->offerdate) ? userdate((int)$offerhighlight->offerdate, $datetimeformat) : '-';
    $statusbadgehtml = html_writer::tag('span', $offerhighlight->statuslabel, array(
        'class' => 'jp-offer-status-inline jp-offer-status-inline--' . $statusclass,
    ));

    $companyhtml = ($company !== '') ? html_writer::span($company, 'jp-student-offer-company') : '-';
    $jobwithid = $jobtitle;
    if (!empty($offerhighlight->jobid)) {
        $jobwithid .= ' (#' . (int)$offerhighlight->jobid . ')';
    }

    echo html_writer::start_div('card mb-3 jp-student-section-card jp-manager-offer-summary');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h5', get_string('offersummary', 'local_jobportal'), array('class' => 'card-title mb-3 jp-student-section-title'));
    echo html_writer::start_div('jp-profile-metrics');
    echo html_writer::tag(
        'div',
        html_writer::div(get_string('offerstatus', 'local_jobportal'), 'jp-profile-metric-label') .
        html_writer::div($statusbadgehtml, 'jp-profile-metric-value') .
        html_writer::div('', 'jp-profile-metric-note'),
        array('class' => 'jp-profile-metric')
    );
    echo html_writer::tag(
        'div',
        html_writer::div(get_string('company', 'local_jobportal'), 'jp-profile-metric-label') .
        html_writer::div($companyhtml, 'jp-profile-metric-value jp-student-metric-datetime') .
        html_writer::div('', 'jp-profile-metric-note'),
        array('class' => 'jp-profile-metric')
    );
    echo html_writer::tag(
        'div',
        html_writer::div(get_string('jobtitle', 'local_jobportal'), 'jp-profile-metric-label') .
        html_writer::div(s($jobwithid), 'jp-profile-metric-value jp-student-metric-datetime') .
        html_writer::div('', 'jp-profile-metric-note'),
        array('class' => 'jp-profile-metric')
    );
    echo html_writer::tag(
        'div',
        html_writer::div(get_string('offerdate', 'local_jobportal'), 'jp-profile-metric-label') .
        html_writer::div($offerdate, 'jp-profile-metric-value jp-student-metric-datetime') .
        html_writer::div('', 'jp-profile-metric-note'),
        array('class' => 'jp-profile-metric')
    );
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
}

$eligibilitylabel = '';
$eligibilitybadgeclass = '';
$eligibilitynote = '';
if ($canviewapplications && $applylockinfo) {
    $eligibilitylabel = get_string('applylockstatus_open', 'local_jobportal');
    $eligibilitybadgeclass = 'badge badge-success';
    if (!empty($applylockinfo->manualblockactive)) {
        $eligibilitylabel = get_string('applylockstatus_manualblock', 'local_jobportal');
        $eligibilitybadgeclass = 'badge badge-danger';
    } else if (!empty($applylockinfo->locked)) {
        $eligibilitylabel = get_string('applylockstatus_locked', 'local_jobportal');
        $eligibilitybadgeclass = 'badge badge-danger';
    } else if (!empty($applylockinfo->overrideactive)) {
        $eligibilitylabel = get_string('applylockstatus_override', 'local_jobportal');
        $eligibilitybadgeclass = 'badge badge-warning';
    }

    $triggerlabel = '';
    if (!empty($applylockinfo->manualblockactive)) {
        $triggerlabel = get_string('applylocktrigger_manualblock', 'local_jobportal');
    } else if (!empty($applylockinfo->locked) && !empty($applylockinfo->lockreason) && $applylockinfo->lockreason === 'noshow') {
        $triggerlabel = get_string('applylocktrigger_noshow', 'local_jobportal', (object)array(
            'jobid' => !empty($applylockinfo->triggerjobid) ? (int)$applylockinfo->triggerjobid : '-',
        ));
    } else if (!empty($applylockinfo->triggerstatus)) {
        $triggerlabel = get_string('applylocktrigger', 'local_jobportal', (object)array(
            'stage' => $applylockinfo->triggerstatuslabel,
            'jobid' => !empty($applylockinfo->triggerjobid) ? (int)$applylockinfo->triggerjobid : '-',
        ));
    }

    $reasonlabel = '';
    if (!empty($applylockinfo->manualblockactive) && !empty($applylockinfo->manualblockreason)) {
        $reasonlabel = (string)$applylockinfo->manualblockreason;
    } else if (!empty($applylockinfo->overrideactive) && !empty($applylockinfo->overridereason)) {
        $reasonlabel = (string)$applylockinfo->overridereason;
    }

    $eligibilityparts = array();
    if ($triggerlabel !== '') {
        $eligibilityparts[] = $triggerlabel;
    }
    if ($reasonlabel !== '') {
        $eligibilityparts[] = get_string('eligibilityreason', 'local_jobportal') . ': ' . $reasonlabel;
    }
    if (!empty($eligibilityparts)) {
        $eligibilitynote = implode(' | ', $eligibilityparts);
    }
}

echo html_writer::start_div('card mb-3 jp-profile-overview jp-student-section-card');
echo html_writer::start_div('card-body');
echo html_writer::tag('h5', get_string('profileoverview', 'local_jobportal'), array('class' => 'card-title mb-3'));
echo html_writer::start_div('jp-profile-metrics');
echo html_writer::tag(
    'div',
    html_writer::div(get_string('totalapplications', 'local_jobportal'), 'jp-profile-metric-label') .
    html_writer::div((int)$totalapplications, 'jp-profile-metric-value'),
    array('class' => 'jp-profile-metric')
);
echo html_writer::tag(
    'div',
    html_writer::div(get_string('activeapplications', 'local_jobportal'), 'jp-profile-metric-label') .
    html_writer::div((int)$activeapplications, 'jp-profile-metric-value'),
    array('class' => 'jp-profile-metric')
);
echo html_writer::tag(
    'div',
    html_writer::div(get_string('totalshortlisted', 'local_jobportal'), 'jp-profile-metric-label') .
    html_writer::div((int)$totalshortlisted, 'jp-profile-metric-value'),
    array('class' => 'jp-profile-metric')
);
echo html_writer::tag(
    'div',
    html_writer::div(get_string('lastshortlisted', 'local_jobportal'), 'jp-profile-metric-label') .
    html_writer::div($lastshortlistedlabel, 'jp-profile-metric-value jp-student-metric-datetime'),
    array('class' => 'jp-profile-metric')
);
echo html_writer::tag(
    'div',
    html_writer::div(get_string('lastapplication', 'local_jobportal'), 'jp-profile-metric-label') .
    html_writer::div($latestlabel, 'jp-profile-metric-value jp-student-metric-datetime'),
    array('class' => 'jp-profile-metric')
);
if ($canviewapplications && $eligibilitylabel !== '') {
    echo html_writer::tag(
        'div',
        html_writer::div(get_string('applyeligibility', 'local_jobportal'), 'jp-profile-metric-label') .
        html_writer::div(
            html_writer::tag('span', s($eligibilitylabel), array('class' => $eligibilitybadgeclass)),
            'jp-profile-metric-value'
        ) .
        html_writer::div($eligibilitynote !== '' ? s($eligibilitynote) : '', 'jp-profile-metric-note'),
        array('class' => 'jp-profile-metric')
    );
}
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('card mb-3 jp-student-section-card');
echo html_writer::start_div('card-body');
echo html_writer::tag('h5', get_string('testinterviewperformance', 'local_jobportal'), array('class' => 'card-title mb-3 jp-student-section-title'));

if (empty($roundevents)) {
    echo html_writer::tag('p', get_string('noroundeventsforstudent', 'local_jobportal'), array('class' => 'alert alert-info mb-0'));
} else {
    echo html_writer::start_div('jp-profile-metrics');
    echo html_writer::tag(
        'div',
        html_writer::div(get_string('totalrounds', 'local_jobportal'), 'jp-profile-metric-label') .
        html_writer::div((int)$roundsummary['total'], 'jp-profile-metric-value'),
        array('class' => 'jp-profile-metric')
    );
    echo html_writer::tag(
        'div',
        html_writer::div(get_string('completedrounds', 'local_jobportal'), 'jp-profile-metric-label') .
        html_writer::div((int)$roundsummary['completed'], 'jp-profile-metric-value'),
        array('class' => 'jp-profile-metric')
    );
    if ((int)$roundsummary['noshow'] > 0) {
        echo html_writer::tag(
            'div',
            html_writer::div(get_string('noshowrounds', 'local_jobportal'), 'jp-profile-metric-label') .
            html_writer::div((int)$roundsummary['noshow'], 'jp-profile-metric-value'),
            array('class' => 'jp-profile-metric')
        );
    }
    echo html_writer::tag(
        'div',
        html_writer::div(get_string('clearedrounds', 'local_jobportal'), 'jp-profile-metric-label') .
        html_writer::div((int)$roundsummary['cleared'], 'jp-profile-metric-value'),
        array('class' => 'jp-profile-metric')
    );
    echo html_writer::tag(
        'div',
        html_writer::div(get_string('notclearedrounds', 'local_jobportal'), 'jp-profile-metric-label') .
        html_writer::div((int)$roundsummary['notcleared'], 'jp-profile-metric-value'),
        array('class' => 'jp-profile-metric')
    );
    echo html_writer::end_div();

    echo html_writer::start_div('jp-info-grid jp-student-info-grid mt-2 mb-0');
    echo html_writer::start_div('jp-info-card jp-student-info-card');
    echo html_writer::div(get_string('testrounds', 'local_jobportal'), 'jp-info-label');
    echo html_writer::div($testroundsummaryhtml, 'jp-info-value jp-student-info-value');
    echo html_writer::end_div();
    echo html_writer::start_div('jp-info-card jp-student-info-card');
    echo html_writer::div(get_string('interviewrounds', 'local_jobportal'), 'jp-info-label');
    echo html_writer::div($interviewroundsummaryhtml, 'jp-info-value jp-student-info-value');
    echo html_writer::end_div();
    echo html_writer::start_div('jp-info-card jp-student-info-card');
    echo html_writer::div(get_string('latestround', 'local_jobportal'), 'jp-info-label');
    echo html_writer::div($latestroundlabel, 'jp-info-value jp-student-info-value');
    echo html_writer::end_div();
    echo html_writer::start_div('jp-info-card jp-student-info-card');
    echo html_writer::div(get_string('nextround', 'local_jobportal'), 'jp-info-label');
    echo html_writer::div($nextroundlabel, 'jp-info-value jp-student-info-value');
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('card mb-3 jp-student-section-card');
echo html_writer::start_div('card-body');
echo html_writer::tag('h5', get_string('profileprofessionalsection', 'local_jobportal'), array('class' => 'card-title mb-3 jp-student-section-title'));

if (!$profile) {
    echo html_writer::tag('p', get_string('noprofileforstudent', 'local_jobportal'), array('class' => 'alert alert-info mb-0'));
} else {
    $skillshtml = trim((string)$profile->skills) !== '' ? format_text($profile->skills, FORMAT_PLAIN) : '-';
    $experiencehtml = trim((string)$profile->experience) !== '' ? format_text($profile->experience, FORMAT_PLAIN) : '-';
    $educationhtml = trim((string)$profile->education) !== '' ? format_text($profile->education, FORMAT_PLAIN) : '-';

    $profilecards = array(
        array('label' => get_string('skills', 'local_jobportal'), 'value' => $skillshtml),
        array('label' => get_string('experience', 'local_jobportal'), 'value' => $experiencehtml),
        array('label' => get_string('education', 'local_jobportal'), 'value' => $educationhtml),
        array('label' => get_string('portfolio', 'local_jobportal'), 'value' => $portfoliohtml),
    );

    echo html_writer::start_div('jp-info-grid jp-student-info-grid mb-0');
    foreach ($profilecards as $profilecard) {
        echo html_writer::start_div('jp-info-card jp-student-info-card');
        echo html_writer::div($profilecard['label'], 'jp-info-label');
        echo html_writer::div($profilecard['value'], 'jp-info-value jp-student-info-value');
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
}
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('card mb-3 jp-profile-overview jp-student-section-card');
echo html_writer::start_div('card-body');
echo html_writer::tag('h5', get_string('resumereview', 'local_jobportal'), array('class' => 'card-title mb-3 jp-student-section-title'));

if ($resumedownloadurl) {
    echo html_writer::start_div('jp-resume-actions jp-student-review-actions');
    if ($resumecanpreview && $resumepreviewurl) {
        echo html_writer::tag(
            'button',
            get_string('previewresume', 'local_jobportal'),
            array(
                'type' => 'button',
                'class' => 'btn btn-outline-secondary btn-sm jp-resume-preview-trigger',
                'data-resume-url' => $resumepreviewurl->out(false),
            )
        );
    }
    echo html_writer::link(
        $resumedownloadurl,
        get_string('downloadresume', 'local_jobportal'),
        array('class' => 'btn btn-outline-primary btn-sm', 'target' => '_blank', 'rel' => 'noopener')
    );
    echo html_writer::end_div();
} else {
    echo html_writer::tag('p', get_string('resumenotuploaded', 'local_jobportal'), array('class' => 'alert alert-warning mb-3'));
}

echo html_writer::start_div('jp-profile-metrics');
echo html_writer::tag(
    'div',
    html_writer::div(get_string('resumereviewstatus', 'local_jobportal'), 'jp-profile-metric-label') .
    html_writer::div(html_writer::tag('span', $statuslabel, array('class' => $statusbadge)), 'jp-profile-metric-value') .
    html_writer::div('', 'jp-profile-metric-note'),
    array('class' => 'jp-profile-metric')
);
echo html_writer::tag(
    'div',
    html_writer::div(get_string('resumerating', 'local_jobportal'), 'jp-profile-metric-label') .
    html_writer::div($resumeratinglabel, 'jp-profile-metric-value') .
    html_writer::div('', 'jp-profile-metric-note'),
    array('class' => 'jp-profile-metric')
);
echo html_writer::tag(
    'div',
    html_writer::div(get_string('lastreviewed', 'local_jobportal'), 'jp-profile-metric-label') .
    html_writer::div($reviewedlabel, 'jp-profile-metric-value jp-student-metric-datetime') .
    html_writer::div($reviewernotelabel !== '' ? (get_string('reviewer', 'local_jobportal') . ': ' . $reviewernotelabel) : '', 'jp-profile-metric-note'),
    array('class' => 'jp-profile-metric')
);
echo html_writer::tag(
    'div',
    html_writer::div(get_string('profileupdatedon', 'local_jobportal'), 'jp-profile-metric-label') .
    html_writer::div($profileupdatedlabel, 'jp-profile-metric-value jp-student-metric-datetime') .
    html_writer::div('', 'jp-profile-metric-note'),
    array('class' => 'jp-profile-metric')
);
echo html_writer::end_div();

echo html_writer::start_div('jp-info-grid jp-student-info-grid mt-2 mb-0');
echo html_writer::start_div('jp-info-card jp-student-info-card');
echo html_writer::div(get_string('resumefeedback', 'local_jobportal'), 'jp-info-label');
echo html_writer::div($resumefeedbacklabel, 'jp-info-value jp-student-info-value');
echo html_writer::end_div();
echo html_writer::end_div();
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

if ($canviewapplications) {
    echo html_writer::start_div('card mb-3 jp-student-section-card');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h5', get_string('applications', 'local_jobportal'), array('class' => 'card-title mb-3 jp-student-section-title'));

    if (empty($recentapplications)) {
        echo html_writer::tag('p', get_string('norecentapplications', 'local_jobportal'), array('class' => 'alert alert-info mb-0'));
    } else {
        $table = new html_table();
        $table->head = array(
            get_string('jobid', 'local_jobportal'),
            get_string('jobtitle', 'local_jobportal'),
            get_string('shortliststatus', 'local_jobportal'),
            get_string('postshortliststage', 'local_jobportal'),
            get_string('appliedon', 'local_jobportal'),
            get_string('lastactivity', 'local_jobportal'),
            get_string('actions'),
        );
        $table->attributes['class'] = 'table table-sm table-striped table-bordered jp-table jp-data-table jp-student-applications-table';
        $table->colclasses = array(
            'jp-col-app-jobid',
            'jp-col-app-title',
            'jp-col-app-shortlist',
            'jp-col-app-poststage',
            'jp-col-app-applied',
            'jp-col-app-lastactivity',
            'jp-col-app-actions',
        );

        foreach ($recentapplications as $application) {
            $shortliststatus = local_jobportal_get_application_shortlist_status($application);
            $shortlistlabel = isset($shortlistoptions[$shortliststatus]) ? $shortlistoptions[$shortliststatus] : $shortliststatus;
            $shortlistbadge = html_writer::tag('span', s($shortlistlabel), array('class' => local_jobportal_shortlist_badge_class($shortliststatus)));
            $stage = local_jobportal_get_application_stage($application, $stages);
            $poststagebadge = html_writer::tag('span', '-', array('class' => 'badge badge-secondary'));
            if ($shortliststatus === 'shortlisted') {
                $poststagename = get_string('poststagenotset', 'local_jobportal');
                if ($stage) {
                    $poststagename = format_string($stage->displayname);
                    if (!empty($stage->isinternal)) {
                        $poststagename .= ' (' . get_string('internalstage', 'local_jobportal') . ')';
                    }
                    $stagebadgeclass = local_jobportal_post_stage_badge_class(!empty($stage->shortname) ? (string)$stage->shortname : '');
                    $poststagebadge = html_writer::tag('span', s($poststagename), array('class' => $stagebadgeclass));
                } else {
                    $poststagebadge = html_writer::tag('span', s($poststagename), array('class' => 'badge badge-secondary'));
                }
            }

            $table->data[] = array(
                html_writer::tag('span', (int)$application->jobid, array('class' => 'font-weight-bold')),
                html_writer::link(
                    new moodle_url('/local/jobportal/view.php', array('id' => (int)$application->jobid)),
                    format_string($application->title)
                ),
                $shortlistbadge,
                $poststagebadge,
                userdate((int)$application->timecreated, $dateformat),
                userdate((int)$application->timemodified, $datetimeformat),
                html_writer::link(
                    new moodle_url('/local/jobportal/application.php', array('appid' => (int)$application->id)),
                    get_string('openapplication', 'local_jobportal'),
                    array('class' => 'btn btn-outline-primary btn-sm')
                ),
            );
        }

        echo html_writer::start_div('table-responsive');
        echo html_writer::table($table);
        echo html_writer::end_div();
    }

    echo html_writer::end_div();
    echo html_writer::end_div();
}

$historycount = count($history);
$historytitle = get_string('resumereviewhistory', 'local_jobportal') . ' (' . $historycount . ')';
echo html_writer::start_div('card mb-3 jp-student-section-card');
echo html_writer::start_div('card-body');
echo html_writer::start_tag('details', array('class' => 'jp-collapsible-history'));
echo html_writer::tag('summary', s($historytitle), array('class' => 'jp-collapsible-summary'));
echo html_writer::start_div('pt-2');

if (empty($history)) {
    echo html_writer::tag('p', get_string('noreviewhistory', 'local_jobportal'), array('class' => 'text-muted mb-0'));
} else {
    $historytable = new html_table();
    $historytable->attributes['class'] = 'table table-sm table-striped table-bordered mb-0 jp-table jp-data-table jp-profile-history-table';
    $historytable->head = array(
        get_string('reviewdate', 'local_jobportal'),
        get_string('actions', 'local_jobportal'),
        get_string('status', 'local_jobportal'),
        get_string('reviewer', 'local_jobportal'),
        get_string('resumerating', 'local_jobportal'),
        get_string('resumefeedback', 'local_jobportal'),
    );

    foreach ($history as $event) {
        $actionkey = 'resumeaction_' . $event->action;
        $actionlabel = get_string_manager()->string_exists($actionkey, 'local_jobportal') ?
            get_string($actionkey, 'local_jobportal') : format_string($event->action);
        $eventstatus = local_jobportal_normalize_resume_status($event->status);
        $eventstatuslabel = isset($resumestatusoptions[$eventstatus]) ? $resumestatusoptions[$eventstatus] : $eventstatus;

        $historytable->data[] = array(
            userdate((int)$event->timecreated, $datetimeformat),
            s($actionlabel),
            s($eventstatuslabel),
            fullname($event),
            $event->rating === null ? '-' : ((int)$event->rating . '/5'),
            !empty($event->feedback) ? s($event->feedback) : '-',
        );
    }

    echo html_writer::start_div('table-responsive');
    echo html_writer::table($historytable);
    echo html_writer::end_div();
    echo html_writer::tag('p', get_string('resumereviewhistorylatest', 'local_jobportal'), array('class' => 'text-muted small mb-0 mt-2'));
}

echo html_writer::end_div();
echo html_writer::end_tag('details');
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo $OUTPUT->footer();
