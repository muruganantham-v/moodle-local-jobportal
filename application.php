<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');
require_once(__DIR__ . '/locallib.php');

require_login();

$context = context_system::instance();
require_capability('local/jobportal:viewapplications', $context);

$appid = required_param('appid', PARAM_INT);
$application = $DB->get_record('local_jobportal_applications', array('id' => $appid), 'id, jobid', MUST_EXIST);

$forcedparams = array(
    'jobid' => (int)$application->jobid,
    'appid' => (int)$appid,
    'showapp' => (int)$appid,
    'standalone' => 1,
);

foreach ($forcedparams as $name => $value) {
    $_REQUEST[$name] = $value;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $_POST[$name] = $value;
    } else {
        $_GET[$name] = $value;
    }
}

require(__DIR__ . '/applications.php');
