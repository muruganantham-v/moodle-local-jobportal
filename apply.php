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

// Check deadline.
if (!empty($job->deadline) && $job->deadline < time()) {
    redirect(
        new moodle_url('/local/jobportal/view.php', array('id' => $jobid)),
        get_string('error:deadlinepassed', 'local_jobportal'),
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
