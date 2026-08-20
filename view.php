<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');
require_once(__DIR__ . '/locallib.php');

require_login();

$id = required_param('id', PARAM_INT);

$context = context_system::instance();
require_capability('local/jobportal:viewjobs', $context);

$job = $DB->get_record('local_jobportal_jobs', array('id' => $id), '*', MUST_EXIST);
$companyprofile = !empty($job->companyid) ? local_jobportal_get_company((int)$job->companyid) : false;
$companyname = $companyprofile ? $companyprofile->name : $job->company;
$companylogo = null;
$companystats = null;
$canmanagejobs = has_capability('local/jobportal:managejobs', $context);
$canviewcompanystats = $canmanagejobs;
$drivestate = local_jobportal_get_job_drive_state($job);
$driveoutcome = !empty($job->driveoutcome) ? local_jobportal_normalize_drive_outcome($job->driveoutcome) : '';
$drivestatelabel = local_jobportal_get_job_drive_state_label($drivestate);
$drivebadgeclass = local_jobportal_get_job_drive_state_badge_class($drivestate);
$driveoutcomelabel = local_jobportal_get_job_drive_outcome_label($driveoutcome);

if ($canmanagejobs && data_submitted() && optional_param('updatedrivestate', 0, PARAM_BOOL) && confirm_sesskey()) {
    $newstate = local_jobportal_normalize_drive_state(optional_param('drivestate', $drivestate, PARAM_ALPHANUMEXT));
    $newoutcome = local_jobportal_normalize_drive_outcome(optional_param('driveoutcome', '', PARAM_ALPHANUMEXT));
    $newnote = trim(optional_param('drivenote', '', PARAM_TEXT));

    if (!local_jobportal_is_drive_transition_allowed($drivestate, $newstate)) {
        $transition = (object)array(
            'from' => $drivestatelabel,
            'to' => local_jobportal_get_job_drive_state_label($newstate),
        );
        redirect(
            new moodle_url('/local/jobportal/view.php', array('id' => $id)),
            get_string('error:drivestatetransition', 'local_jobportal', $transition),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    if ($newstate === 'completed' && $newoutcome === '') {
        redirect(
            new moodle_url('/local/jobportal/view.php', array('id' => $id)),
            get_string('error:driveoutcomerequired', 'local_jobportal'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    if ($newstate !== 'completed') {
        $newoutcome = '';
    }

    $newstatus = 1;
    if (in_array($newstate, array('completed', 'archived', 'cancelled'), true)) {
        $newstatus = 0;
    }

    $now = time();
    $update = new stdClass();
    $update->id = (int)$job->id;
    $update->status = $newstatus;
    $update->drivestate = $newstate;
    $update->driveoutcome = $newoutcome !== '' ? $newoutcome : null;
    $update->drivenote = $newnote !== '' ? $newnote : null;
    $update->drivestateupdatedby = (int)$USER->id;
    $update->drivestateupdatedat = $now;
    $update->timemodified = $now;
    $DB->update_record('local_jobportal_jobs', $update);

    redirect(
        new moodle_url('/local/jobportal/view.php', array('id' => $id)),
        get_string('drivestateupdated', 'local_jobportal'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($companyprofile) {
    $companylogo = local_jobportal_get_company_logo_url($companyprofile->id, $context);
    $companystats = local_jobportal_get_company_stats($companyprofile->id);
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/jobportal/view.php', array('id' => $id)));
$PAGE->set_title($job->title);
$PAGE->set_heading($job->title);
local_jobportal_require_styles();
$dateformat = '%d/%m/%Y';
$datetimeformat = '%d/%m/%Y %H:%M';

// Check if user has already applied
$hasapplied = false;
$applylockinfo = null;
if (has_capability('local/jobportal:apply', $context)) {
    $applylockinfo = local_jobportal_get_student_apply_lock_info((int)$USER->id);
    $hasapplied = $DB->record_exists('local_jobportal_applications', 
        array('jobid' => $job->id, 'userid' => $USER->id));
}

$applicationcount = 0;
if ($canmanagejobs) {
    $applicationcount = (int)$DB->count_records('local_jobportal_applications', array('jobid' => $job->id));
}

$navlinks = array(
    array(
        'key' => 'view',
        'label' => get_string('viewjob', 'local_jobportal'),
        'url' => new moodle_url('/local/jobportal/view.php', array('id' => $job->id)),
    ),
);
if (has_capability('local/jobportal:viewapplications', $context)) {
    $navlinks[] = array(
        'key' => 'applications',
        'label' => get_string('viewapplications', 'local_jobportal'),
        'url' => new moodle_url('/local/jobportal/applications.php', array('jobid' => $job->id)),
    );
}
if ($companyprofile) {
    $navlinks[] = array(
        'key' => 'company',
        'label' => get_string('viewcompanyprofile', 'local_jobportal'),
        'url' => new moodle_url('/local/jobportal/company.php', array('id' => $companyprofile->id)),
    );
}

echo $OUTPUT->header();
echo local_jobportal_render_navigation($context, 'view', $navlinks);

// Wrap the whole page
echo html_writer::start_tag('div', array('class' => 'local-jobportal-page'));

// HERO SECTION
echo html_writer::start_tag('div', array('class' => 'jp-page-hero mb-4'));
echo html_writer::start_div('container-fluid');
echo html_writer::start_div('row align-items-center');

echo html_writer::start_div('col-md-8');
echo html_writer::tag('h2', format_string($job->title), array('class' => 'jp-hero-title mb-2'));
echo html_writer::start_div('d-flex align-items-center gap-2 mt-2');
if ($companylogo) {
    echo html_writer::empty_tag('img', array(
        'src' => $companylogo->out(false),
        'alt' => format_string($companyname),
        'style' => 'width: 48px; height: 48px; object-fit: contain; border-radius: 8px; background: white; padding: 4px;',
    ));
}
echo html_writer::tag('h5', format_string($companyname), array('class' => 'mb-0 text-white'));
echo html_writer::end_div();
echo html_writer::end_div(); // col-md-8

echo html_writer::start_div('col-md-4 text-md-right mt-3 mt-md-0');
echo html_writer::tag('span', $drivestatelabel, array('class' => $drivebadgeclass . ' mb-2 d-inline-block'));
if ($driveoutcome !== '') {
    echo html_writer::tag('div', $driveoutcomelabel, array('class' => 'badge badge-light'));
}
echo html_writer::end_div();

echo html_writer::end_div(); // row
echo html_writer::end_div(); // container-fluid
echo html_writer::end_tag('div'); // jp-page-hero

// MAIN CONTENT 2-COLUMN LAYOUT
echo html_writer::start_div('container-fluid');
echo html_writer::start_div('row');

// LEFT COLUMN: Content
echo html_writer::start_div('col-lg-8');

// Job Description Section
echo html_writer::start_div('jp-form-section mb-4');
echo html_writer::tag('h4', get_string('description', 'local_jobportal'), array('class' => 'mb-3 font-weight-bold'));
echo html_writer::tag('div', format_text($job->description, FORMAT_HTML), array('class' => 'jp-job-desc-content'));
echo html_writer::end_div();

// Job Requirements Section
if (!empty($job->requirements)) {
    echo html_writer::start_div('jp-form-section mb-4');
    echo html_writer::tag('h4', get_string('requirements', 'local_jobportal'), array('class' => 'mb-3 font-weight-bold'));
    echo html_writer::tag('div', format_text($job->requirements, FORMAT_HTML), array('class' => 'jp-job-req-content'));
    echo html_writer::end_div();
}

// Drive Management Section (Managers)
if ($canmanagejobs) {
    echo html_writer::start_div('jp-form-section mb-4 border-warning');
    echo html_writer::tag('h5', '🛠️ ' . get_string('managedrivestate', 'local_jobportal'), array('class' => 'mb-3 font-weight-bold text-warning'));
    
    $driveoptions = local_jobportal_get_drive_state_options();
    $nextstateoptions = array();
    foreach ($driveoptions as $statekey => $statelabel) {
        if ($statekey === $drivestate || local_jobportal_is_drive_transition_allowed($drivestate, $statekey)) {
            $nextstateoptions[$statekey] = $statelabel;
        }
    }

    $driveoutcomeoptions = array('' => '-') + local_jobportal_get_drive_outcome_options();
    $showdriveoutcome = ($drivestate === 'completed');
    $updatedbytext = '-';
    if (!empty($job->drivestateupdatedby)) {
        $userfields = 'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename';
        $updatedbyuser = core_user::get_user((int)$job->drivestateupdatedby, $userfields, IGNORE_MISSING);
        if ($updatedbyuser) {
            $updatedbytext = fullname($updatedbyuser);
        }
    }
    $updatedattext = !empty($job->drivestateupdatedat) ? userdate((int)$job->drivestateupdatedat, $datetimeformat) : '-';

    echo html_writer::tag(
        'p',
        get_string('drivestateupdatedmeta', 'local_jobportal', (object)array('user' => $updatedbytext, 'time' => $updatedattext)),
        array('class' => 'text-muted small mb-3')
    );

    echo html_writer::start_tag('form', array('method' => 'post', 'action' => $PAGE->url));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'updatedrivestate', 'value' => 1));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));

    echo html_writer::start_div('row');
    echo html_writer::start_div('col-md-6 mb-3');
    echo html_writer::tag('label', get_string('jobdrivestate', 'local_jobportal'), array('for' => 'jp-drivestate', 'class' => 'small text-muted d-block font-weight-bold'));
    echo html_writer::select($nextstateoptions, 'drivestate', $drivestate, false, array('class' => 'custom-select', 'id' => 'jp-drivestate'));
    echo html_writer::end_div();

    $outcomestyle = $showdriveoutcome ? '' : 'display:none;';
    $outcomeattrs = array('class' => 'custom-select', 'id' => 'jp-driveoutcome');
    if (!$showdriveoutcome) {
        $outcomeattrs['disabled'] = 'disabled';
    }
    echo html_writer::start_div('col-md-6 mb-3', array('id' => 'jp-driveoutcome-wrap', 'style' => $outcomestyle));
    echo html_writer::tag('label', get_string('jobdriveoutcome', 'local_jobportal'), array('for' => 'jp-driveoutcome', 'class' => 'small text-muted d-block font-weight-bold'));
    echo html_writer::select($driveoutcomeoptions, 'driveoutcome', $driveoutcome, false, $outcomeattrs);
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('mb-3');
    echo html_writer::tag('label', get_string('jobdrivenote', 'local_jobportal'), array('for' => 'jp-drivenote', 'class' => 'small text-muted d-block font-weight-bold'));
    echo html_writer::tag('textarea', !empty($job->drivenote) ? s($job->drivenote) : '', array(
        'name' => 'drivenote',
        'id' => 'jp-drivenote',
        'class' => 'form-control',
        'rows' => 3,
        'placeholder' => get_string('jobdrivenoteplaceholder', 'local_jobportal'),
    ));
    echo html_writer::end_div();

    echo html_writer::tag('button', get_string('updatedrivestate', 'local_jobportal'), array('type' => 'submit', 'class' => 'btn btn-warning'));
    echo html_writer::end_tag('form');
    echo html_writer::end_div();

    $PAGE->requires->js_call_amd('local_jobportal/view_drive_state', 'init');
}

echo html_writer::end_div(); // End Left Column

// RIGHT COLUMN: Sticky Sidebar
echo html_writer::start_div('col-lg-4');
echo html_writer::start_div('jp-sticky-sidebar', array('style' => 'position: sticky; top: 20px;'));

// Action / Apply Card
echo html_writer::start_div('jp-form-section mb-4 text-center');
if (has_capability('local/jobportal:apply', $context)) {
    $hasprofileresume = local_jobportal_user_has_profile_resume($USER->id);
    $acceptsapplications = local_jobportal_job_accepts_applications($job);
    if ($hasapplied) {
        echo html_writer::tag('div', '✅ ' . get_string('alreadyapplied', 'local_jobportal'), 
            array('class' => 'jp-notification-banner jp-notification-info justify-content-center mb-0'));
    } else if (!empty($applylockinfo->locked)) {
        echo html_writer::tag(
            'div',
            '🔒 ' . local_jobportal_get_student_apply_lock_message($applylockinfo, false),
            array('class' => 'jp-notification-banner jp-notification-warning justify-content-center mb-0 text-left')
        );
    } else if (!$hasprofileresume) {
        $resumelink = html_writer::link(
            new moodle_url('/local/jobportal/profile.php'),
            get_string('myprofile', 'local_jobportal')
        );
        echo html_writer::tag(
            'div',
            '📄 ' . get_string('resumeuploadrequired', 'local_jobportal', $resumelink),
            array('class' => 'jp-notification-banner jp-notification-warning justify-content-center mb-0 text-left')
        );
    } else if (!$acceptsapplications) {
        if ($drivestate !== 'applicationsopen') {
            echo html_writer::tag(
                'div',
                '⛔ ' . get_string('jobdrivenotacceptingapplications', 'local_jobportal', $drivestatelabel),
                array('class' => 'jp-notification-banner jp-notification-warning justify-content-center mb-0 text-left')
            );
        } else if (!empty($job->deadline) && $job->deadline < time()) {
            echo html_writer::tag('div', '⏰ ' . get_string('error:deadlinepassed', 'local_jobportal'),
                array('class' => 'jp-notification-banner jp-notification-warning justify-content-center mb-0'));
        } else {
            echo html_writer::tag('div', '⛔ ' . get_string('jobnotacceptingapplications', 'local_jobportal'),
                array('class' => 'jp-notification-banner jp-notification-warning justify-content-center mb-0'));
        }
    } else {
        echo html_writer::link(
            new moodle_url('/local/jobportal/apply.php', array('jobid' => $job->id)),
            '🚀 ' . get_string('applynow', 'local_jobportal'),
            array('class' => 'btn btn-success btn-lg btn-block jp-action-pill mb-0')
        );
    }
}
echo html_writer::end_div(); // Action card

// Management Buttons
if (has_capability('local/jobportal:managejobs', $context)) {
    echo html_writer::start_div('jp-form-section mb-4');
    echo html_writer::tag('h6', 'Management Actions', array('class' => 'font-weight-bold mb-3 text-muted text-uppercase'));
    echo html_writer::start_div('d-flex flex-column gap-2');
    echo html_writer::link(
        new moodle_url('/local/jobportal/post.php', array('id' => $job->id)),
        '✏️ ' . get_string('editjob', 'local_jobportal'),
        array('class' => 'btn btn-outline-primary btn-block jp-action-pill')
    );
    echo html_writer::link(
        new moodle_url('/local/jobportal/applications.php', array('jobid' => $job->id)),
        '📋 ' . get_string('viewapplications', 'local_jobportal') . ' (' . $applicationcount . ')',
        array('class' => 'btn btn-outline-info btn-block jp-action-pill')
    );
    echo html_writer::link(
        new moodle_url('/local/jobportal/post.php', array('cloneid' => $job->id)),
        '🔄 ' . get_string('clonejob', 'local_jobportal'),
        array('class' => 'btn btn-outline-secondary btn-block jp-action-pill')
    );
    if ($applicationcount === 0) {
        echo html_writer::link(
            new moodle_url('/local/jobportal/delete.php', array('id' => $job->id)),
            '🗑️ ' . get_string('deletejob', 'local_jobportal'),
            array('class' => 'btn btn-outline-danger btn-block jp-action-pill')
        );
    } else {
        echo html_writer::tag(
            'div',
            get_string('cannotdeletejobhasapplications', 'local_jobportal'),
            array('class' => 'text-muted small mt-2 text-center')
        );
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
}

// Job Information Card
echo html_writer::start_div('jp-form-section mb-4');
echo html_writer::tag('h6', get_string('jobinformation', 'local_jobportal'), array('class' => 'font-weight-bold mb-3 text-muted text-uppercase'));
echo html_writer::start_tag('ul', array('class' => 'list-unstyled mb-0'));

echo html_writer::start_tag('li', array('class' => 'mb-3'));
echo html_writer::tag('div', get_string('jobtype', 'local_jobportal'), array('class' => 'small text-muted font-weight-bold'));
echo html_writer::tag('div', local_jobportal_format_jobtype($job->jobtype), array('class' => 'font-weight-600'));
echo html_writer::end_tag('li');

if (!empty($job->location)) {
    echo html_writer::start_tag('li', array('class' => 'mb-3'));
    echo html_writer::tag('div', get_string('location', 'local_jobportal'), array('class' => 'small text-muted font-weight-bold'));
    echo html_writer::tag('div', format_string($job->location), array('class' => 'font-weight-600'));
    echo html_writer::end_tag('li');
}

$salarydisplay = local_jobportal_get_job_salary_display($job);
if ($salarydisplay !== '') {
    echo html_writer::start_tag('li', array('class' => 'mb-3'));
    echo html_writer::tag('div', get_string('salary', 'local_jobportal'), array('class' => 'small text-muted font-weight-bold'));
    echo html_writer::tag('div', format_string($salarydisplay), array('class' => 'font-weight-600'));
    echo html_writer::end_tag('li');
}

if (!empty($job->deadline)) {
    echo html_writer::start_tag('li', array('class' => 'mb-3'));
    echo html_writer::tag('div', get_string('deadline', 'local_jobportal'), array('class' => 'small text-muted font-weight-bold'));
    echo html_writer::tag('div', userdate($job->deadline, $datetimeformat), array('class' => 'font-weight-600 text-danger'));
    echo html_writer::end_tag('li');
}

echo html_writer::start_tag('li');
echo html_writer::tag('div', get_string('joblistedon', 'local_jobportal'), array('class' => 'small text-muted font-weight-bold'));
echo html_writer::tag('div', userdate($job->timecreated, $dateformat), array('class' => 'font-weight-600'));
echo html_writer::end_tag('li');

echo html_writer::end_tag('ul');
echo html_writer::end_div();

echo html_writer::end_div(); // End Sticky Sidebar
echo html_writer::end_div(); // End Right Column

echo html_writer::end_div(); // End Row
echo html_writer::end_div(); // End Container
echo html_writer::end_tag('div'); // End local-jobportal-page

// Back link
echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/jobportal/index.php'),
        '← ' . get_string('alljobs', 'local_jobportal')
    ),
    'mt-3'
);

echo $OUTPUT->footer();
