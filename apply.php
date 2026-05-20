<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');
require_once(__DIR__ . '/locallib.php');

require_login();

$jobid = required_param('jobid', PARAM_INT);

$context = context_system::instance();
require_capability('local/jobportal:apply', $context);

$job = $DB->get_record('local_jobportal_jobs', array('id' => $jobid), '*', MUST_EXIST);

// Check if already applied.
if ($DB->record_exists('local_jobportal_applications', array('jobid' => $jobid, 'userid' => $USER->id))) {
    redirect(
        new moodle_url('/local/jobportal/view.php', array('id' => $jobid)),
        get_string('alreadyapplied', 'local_jobportal'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

$applylockinfo = local_jobportal_get_student_apply_lock_info((int)$USER->id);
if (!empty($applylockinfo->locked)) {
    redirect(
        new moodle_url('/local/jobportal/view.php', array('id' => $jobid)),
        local_jobportal_get_student_apply_lock_message($applylockinfo, true),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$drivestate = local_jobportal_get_job_drive_state($job);
$drivestatelabel = local_jobportal_get_job_drive_state_label($drivestate);

// Check drive state.
if ($drivestate !== 'applicationsopen') {
    redirect(
        new moodle_url('/local/jobportal/view.php', array('id' => $jobid)),
        get_string('jobdrivenotacceptingapplications', 'local_jobportal', $drivestatelabel),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// Check deadline.
if (!empty($job->deadline) && $job->deadline < time()) {
    redirect(
        new moodle_url('/local/jobportal/view.php', array('id' => $jobid)),
        get_string('error:deadlinepassed', 'local_jobportal'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$studentpolicy = local_jobportal_get_student_job_access_policy();
$policyblockers = local_jobportal_get_student_apply_policy_blockers((int)$USER->id, $studentpolicy);
if (!empty($policyblockers['resumeapproved'])) {
    redirect(
        new moodle_url('/local/jobportal/profile.php'),
        get_string('error:policyresumeapprovedrequired', 'local_jobportal', $policyblockers['resumeapproved']['statuslabel']),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}
if (!empty($policyblockers['maxactive'])) {
    redirect(
        new moodle_url('/local/jobportal/view.php', array('id' => $jobid)),
        get_string('error:policymaxactiveapplications', 'local_jobportal', (object)$policyblockers['maxactive']),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}
if (!empty($policyblockers['weeklylimit'])) {
    redirect(
        new moodle_url('/local/jobportal/view.php', array('id' => $jobid)),
        get_string('error:policyweeklyapplicationlimit', 'local_jobportal', (object)$policyblockers['weeklylimit']),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}
if (!empty($policyblockers['cooldown'])) {
    redirect(
        new moodle_url('/local/jobportal/view.php', array('id' => $jobid)),
        get_string('error:policynotshortlistedcooldown', 'local_jobportal', (object)$policyblockers['cooldown']),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// Resume must be uploaded in profile before applying.
if (!local_jobportal_user_has_profile_resume($USER->id)) {
    redirect(
        new moodle_url('/local/jobportal/profile.php'),
        get_string('error:resumerequired', 'local_jobportal'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

local_jobportal_ensure_default_stages();
$pendingstageid = 0;
try {
    $pendingstage = $DB->get_record('local_jobportal_stages', array('shortname' => 'pending'), 'id');
    if ($pendingstage) {
        $pendingstageid = (int)$pendingstage->id;
    }
} catch (dml_exception $e) {
    $pendingstageid = 0;
}

$application = new stdClass();
$application->jobid = $jobid;
$application->userid = $USER->id;
$application->coverletter = null;
$application->status = 'pending';
$application->shortliststatus = 'pending';
$application->currentstageid = null;
$application->timecreated = time();
$application->timemodified = time();
$applicationid = $DB->insert_record('local_jobportal_applications', $application);

if ($pendingstageid) {
    $event = new stdClass();
    $event->applicationid = $applicationid;
    $event->stageid = $pendingstageid;
    $event->changedby = $USER->id;
    $event->notes = null;
    $event->scheduledat = null;
    $event->timecreated = time();
    $DB->insert_record('local_jobportal_appstage_events', $event);
}

redirect(
    new moodle_url('/local/jobportal/myapplications.php'),
    get_string('applicationsubmitted', 'local_jobportal'),
    null,
    \core\output\notification::NOTIFY_SUCCESS
);
