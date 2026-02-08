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
if (has_capability('local/jobportal:apply', $context)) {
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

// Job details
echo html_writer::start_tag('div', array('class' => 'job-details'));

echo html_writer::tag('h4', format_string($companyname), array('class' => 'text-muted mb-3'));

if ($companyprofile) {
    echo html_writer::start_tag('div', array('class' => 'card mb-3'));
    echo html_writer::start_tag('div', array('class' => 'card-body'));

    if ($companylogo) {
        echo html_writer::empty_tag('img', array(
            'src' => $companylogo->out(false),
            'alt' => format_string($companyprofile->name),
            'class' => 'img-thumbnail mb-3',
            'style' => 'max-width: 120px; height: auto;',
        ));
    }

    echo html_writer::tag('h5', get_string('companyprofile', 'local_jobportal'), array('class' => 'card-title'));
    echo html_writer::tag('p', format_string($companyprofile->name), array('class' => 'mb-2'));

    if (!empty($companyprofile->description)) {
        echo html_writer::tag('p', shorten_text(s($companyprofile->description), 220), array('class' => 'mb-2'));
    }

    if ($canviewcompanystats) {
        echo html_writer::start_tag('p', array('class' => 'mb-2'));
        echo html_writer::tag('span', get_string('jobsposted', 'local_jobportal') . ': ' . $companystats->jobsposted, array('class' => 'badge badge-light mr-2'));
        echo html_writer::tag('span', get_string('applicationsreceived', 'local_jobportal') . ': ' . $companystats->applicationsreceived, array('class' => 'badge badge-light'));
        echo html_writer::end_tag('p');
    }

    echo html_writer::link(
        new moodle_url('/local/jobportal/company.php', array('id' => $companyprofile->id)),
        get_string('viewcompanyprofile', 'local_jobportal')
    );

    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
}

echo html_writer::start_tag('div', array('class' => 'card mb-3'));
echo html_writer::start_tag('div', array('class' => 'card-body'));

echo html_writer::tag('h5', get_string('description', 'local_jobportal'), array('class' => 'card-title'));
echo html_writer::tag('div', format_text($job->description, FORMAT_HTML), array('class' => 'card-text'));

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

// Job information
echo html_writer::start_tag('div', array('class' => 'card mb-3'));
echo html_writer::start_tag('div', array('class' => 'card-body'));

echo html_writer::tag('h5', get_string('jobinformation', 'local_jobportal'), array('class' => 'card-title'));

echo html_writer::start_tag('dl', array('class' => 'row'));

echo html_writer::tag('dt', get_string('jobdrivestate', 'local_jobportal'), array('class' => 'col-sm-3'));
echo html_writer::tag(
    'dd',
    html_writer::tag('span', $drivestatelabel, array('class' => $drivebadgeclass)),
    array('class' => 'col-sm-9')
);

if ($driveoutcome !== '') {
    echo html_writer::tag('dt', get_string('jobdriveoutcome', 'local_jobportal'), array('class' => 'col-sm-3'));
    echo html_writer::tag('dd', $driveoutcomelabel, array('class' => 'col-sm-9'));
}

echo html_writer::tag('dt', get_string('jobtype', 'local_jobportal'), array('class' => 'col-sm-3'));
echo html_writer::tag('dd', local_jobportal_format_jobtype($job->jobtype), array('class' => 'col-sm-9'));

if (!empty($job->location)) {
    echo html_writer::tag('dt', get_string('location', 'local_jobportal'), array('class' => 'col-sm-3'));
    echo html_writer::tag('dd', format_string($job->location), array('class' => 'col-sm-9'));
}

$salarydisplay = local_jobportal_get_job_salary_display($job);
if ($salarydisplay !== '') {
    echo html_writer::tag('dt', get_string('salary', 'local_jobportal'), array('class' => 'col-sm-3'));
    echo html_writer::tag('dd', format_string($salarydisplay), array('class' => 'col-sm-9'));
}

if (!empty($job->salarymodel) && core_text::strtolower((string)$job->salarymodel) === 'progressive') {
    $salarystages = local_jobportal_get_job_salary_stages((int)$job->id);
    if (!empty($salarystages)) {
        $stageitems = '';
        foreach ($salarystages as $stage) {
            $stageperiod = core_text::strtolower((string)$stage->period);
            if ($stageperiod !== 'monthly' && $stageperiod !== 'annual') {
                $stageperiod = 'annual';
            }
            $itemtext = format_string($stage->stagelabel) . ': ' .
                local_jobportal_format_salary_amount($stage->amount, $job->salarycurrency) .
                ' / ' . get_string('salaryperiod_' . $stageperiod, 'local_jobportal');
            if (!empty($stage->conditiontext)) {
                $itemtext .= ' (' . format_string($stage->conditiontext) . ')';
            }
            $stageitems .= html_writer::tag('li', $itemtext);
        }
        echo html_writer::tag('dt', get_string('salarystages', 'local_jobportal'), array('class' => 'col-sm-3'));
        echo html_writer::tag('dd', html_writer::tag('ul', $stageitems, array('class' => 'mb-0 pl-3')), array('class' => 'col-sm-9'));
    }
}

if (!empty($job->deadline)) {
    echo html_writer::tag('dt', get_string('deadline', 'local_jobportal'), array('class' => 'col-sm-3'));
    echo html_writer::tag('dd', userdate($job->deadline, $datetimeformat),
        array('class' => 'col-sm-9'));
}

echo html_writer::tag('dt', get_string('joblistedon', 'local_jobportal'), array('class' => 'col-sm-3'));
echo html_writer::tag('dd', userdate($job->timecreated, $dateformat), array('class' => 'col-sm-9'));

echo html_writer::end_tag('dl');

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

if ($canmanagejobs) {
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

    echo html_writer::start_tag('div', array('class' => 'card mb-3'));
    echo html_writer::start_tag('div', array('class' => 'card-body'));
    echo html_writer::tag('h5', get_string('managedrivestate', 'local_jobportal'), array('class' => 'card-title'));
    echo html_writer::tag(
        'p',
        get_string('drivestateupdatedmeta', 'local_jobportal', (object)array('user' => $updatedbytext, 'time' => $updatedattext)),
        array('class' => 'text-muted small mb-3')
    );

    echo html_writer::start_tag('form', array('method' => 'post', 'action' => $PAGE->url));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'updatedrivestate', 'value' => 1));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));

    echo html_writer::start_div('row');
    echo html_writer::start_div('col-md-4 mb-2');
    echo html_writer::tag('label', get_string('jobdrivestate', 'local_jobportal'), array('for' => 'jp-drivestate', 'class' => 'small text-muted d-block'));
    echo html_writer::select($nextstateoptions, 'drivestate', $drivestate, false, array('class' => 'custom-select', 'id' => 'jp-drivestate'));
    echo html_writer::end_div();

    $outcomestyle = $showdriveoutcome ? '' : 'display:none;';
    $outcomeattrs = array('class' => 'custom-select', 'id' => 'jp-driveoutcome');
    if (!$showdriveoutcome) {
        $outcomeattrs['disabled'] = 'disabled';
    }
    echo html_writer::start_div('col-md-4 mb-2', array('id' => 'jp-driveoutcome-wrap', 'style' => $outcomestyle));
    echo html_writer::tag('label', get_string('jobdriveoutcome', 'local_jobportal'), array('for' => 'jp-driveoutcome', 'class' => 'small text-muted d-block'));
    echo html_writer::select($driveoutcomeoptions, 'driveoutcome', $driveoutcome, false, $outcomeattrs);
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('mb-2');
    echo html_writer::tag('label', get_string('jobdrivenote', 'local_jobportal'), array('for' => 'jp-drivenote', 'class' => 'small text-muted d-block'));
    echo html_writer::tag('textarea', !empty($job->drivenote) ? s($job->drivenote) : '', array(
        'name' => 'drivenote',
        'id' => 'jp-drivenote',
        'class' => 'form-control',
        'rows' => 3,
        'placeholder' => get_string('jobdrivenoteplaceholder', 'local_jobportal'),
    ));
    echo html_writer::end_div();

    echo html_writer::tag('button', get_string('updatedrivestate', 'local_jobportal'), array('type' => 'submit', 'class' => 'btn btn-primary'));
    echo html_writer::end_tag('form');
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');

    $PAGE->requires->js_init_code("
        (function() {
            var stateSelect = document.getElementById('jp-drivestate');
            var outcomeWrap = document.getElementById('jp-driveoutcome-wrap');
            var outcomeSelect = document.getElementById('jp-driveoutcome');
            if (!stateSelect || !outcomeWrap || !outcomeSelect) {
                return;
            }
            var sync = function() {
                var show = stateSelect.value === 'completed';
                outcomeWrap.style.display = show ? '' : 'none';
                outcomeSelect.disabled = !show;
                if (!show) {
                    outcomeSelect.value = '';
                }
            };
            stateSelect.addEventListener('change', sync);
            sync();
        })();
    ");
}

// Requirements
if (!empty($job->requirements)) {
    echo html_writer::start_tag('div', array('class' => 'card mb-3'));
    echo html_writer::start_tag('div', array('class' => 'card-body'));
    
    echo html_writer::tag('h5', get_string('requirements', 'local_jobportal'), array('class' => 'card-title'));
    echo html_writer::tag('div', format_text($job->requirements, FORMAT_HTML), array('class' => 'card-text'));
    
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
}

// Application button
if (has_capability('local/jobportal:apply', $context)) {
    $hasprofileresume = local_jobportal_user_has_profile_resume($USER->id);
    $acceptsapplications = local_jobportal_job_accepts_applications($job);
    if ($hasapplied) {
        echo html_writer::tag('div', get_string('alreadyapplied', 'local_jobportal'), 
            array('class' => 'alert alert-info'));
    } else if (!$hasprofileresume) {
        $resumelink = html_writer::link(
            new moodle_url('/local/jobportal/profile.php'),
            get_string('myprofile', 'local_jobportal')
        );
        echo html_writer::tag(
            'div',
            get_string('resumeuploadrequired', 'local_jobportal', $resumelink),
            array('class' => 'alert alert-warning')
        );
    } else if (!$acceptsapplications) {
        if ($drivestate !== 'applicationsopen') {
            echo html_writer::tag(
                'div',
                get_string('jobdrivenotacceptingapplications', 'local_jobportal', $drivestatelabel),
                array('class' => 'alert alert-warning')
            );
        } else if (!empty($job->deadline) && $job->deadline < time()) {
            echo html_writer::tag('div', get_string('error:deadlinepassed', 'local_jobportal'),
                array('class' => 'alert alert-warning'));
        } else {
            echo html_writer::tag('div', get_string('jobnotacceptingapplications', 'local_jobportal'),
                array('class' => 'alert alert-warning'));
        }
    } else {
        echo html_writer::link(
            new moodle_url('/local/jobportal/apply.php', array('jobid' => $job->id)),
            get_string('applynow', 'local_jobportal'),
            array('class' => 'btn btn-success btn-lg')
        );
    }
}

// Management buttons
if (has_capability('local/jobportal:managejobs', $context)) {
    echo html_writer::start_tag('div', array('class' => 'mt-3'));
    echo html_writer::link(
        new moodle_url('/local/jobportal/post.php', array('id' => $job->id)),
        get_string('editjob', 'local_jobportal'),
        array('class' => 'btn btn-warning mr-2')
    );
    echo html_writer::link(
        new moodle_url('/local/jobportal/post.php', array('cloneid' => $job->id)),
        get_string('clonejob', 'local_jobportal'),
        array('class' => 'btn btn-outline-secondary mr-2')
    );
    echo html_writer::link(
        new moodle_url('/local/jobportal/applications.php', array('jobid' => $job->id)),
        get_string('viewapplications', 'local_jobportal'),
        array('class' => 'btn btn-info')
    );

    if ($applicationcount === 0) {
        echo ' ';
        echo html_writer::link(
            new moodle_url('/local/jobportal/delete.php', array('id' => $job->id)),
            get_string('deletejob', 'local_jobportal'),
            array('class' => 'btn btn-danger')
        );
    } else {
        echo html_writer::tag(
            'p',
            get_string('cannotdeletejobhasapplications', 'local_jobportal'),
            array('class' => 'text-muted small mt-2 mb-0')
        );
    }
    echo html_writer::end_tag('div');
}

echo html_writer::end_tag('div');

// Back link
echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/jobportal/index.php'),
        '← ' . get_string('alljobs', 'local_jobportal')
    ),
    'mt-3'
);

echo $OUTPUT->footer();
