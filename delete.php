<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');
require_once(__DIR__ . '/locallib.php');

require_login();

$id = required_param('id', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$context = context_system::instance();
require_capability('local/jobportal:managejobs', $context);

$job = $DB->get_record('local_jobportal_jobs', array('id' => $id), '*', MUST_EXIST);
$applicationcount = (int)$DB->count_records('local_jobportal_applications', array('jobid' => $job->id));

if ($applicationcount > 0) {
    redirect(
        new moodle_url('/local/jobportal/view.php', array('id' => $job->id)),
        get_string('error:jobhasapplications', 'local_jobportal'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

if ($confirm && confirm_sesskey()) {
    $DB->delete_records('local_jobportal_jobs', array('id' => $job->id));
    redirect(
        new moodle_url('/local/jobportal/index.php'),
        get_string('jobdeleted', 'local_jobportal'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/jobportal/delete.php', array('id' => $id)));
$PAGE->set_title(get_string('deletejob', 'local_jobportal'));
$PAGE->set_heading(get_string('deletejob', 'local_jobportal'));
local_jobportal_require_styles();

$message = get_string('confirmdeletejob', 'local_jobportal', format_string($job->title));
$continueurl = new moodle_url('/local/jobportal/delete.php', array(
    'id' => $job->id,
    'confirm' => 1,
    'sesskey' => sesskey(),
));
$cancelurl = new moodle_url('/local/jobportal/view.php', array('id' => $job->id));

echo $OUTPUT->header();
echo local_jobportal_render_navigation(
    $context,
    'delete',
    array(
        array(
            'key' => 'view',
            'label' => get_string('viewjob', 'local_jobportal'),
            'url' => new moodle_url('/local/jobportal/view.php', array('id' => $job->id)),
        ),
        array(
            'key' => 'delete',
            'label' => get_string('deletejob', 'local_jobportal'),
            'url' => new moodle_url('/local/jobportal/delete.php', array('id' => $job->id)),
        ),
    )
);
echo $OUTPUT->confirm($message, $continueurl, $cancelurl);
echo $OUTPUT->footer();
