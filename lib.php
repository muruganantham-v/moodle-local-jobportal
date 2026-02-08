<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

/**
 * File serving callback for local_jobportal.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool
 */
function local_jobportal_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = array()) {
    global $DB, $USER;

    require_login();

    if ($context->contextlevel !== CONTEXT_SYSTEM) {
        return false;
    }

    $systemcontext = context_system::instance();
    if (count($args) < 2) {
        return false;
    }

    $itemid = (int)array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $component = 'local_jobportal';
    $allowed = false;

    if ($filearea === 'company_logo') {
        $allowed = has_capability('local/jobportal:viewjobs', $systemcontext) ||
            has_capability('local/jobportal:managecompanyprofile', $systemcontext);
    } else if ($filearea === 'profile_resume') {
        if (has_capability('local/jobportal:viewapplications', $systemcontext)) {
            $allowed = true;
        } else if (has_capability('local/jobportal:reviewresumes', $systemcontext)) {
            $allowed = true;
        } else {
            $profile = $DB->get_record('local_jobportal_profiles', array('id' => $itemid), 'id, userid');
            $allowed = $profile && (int)$profile->userid === (int)$USER->id;
        }
    }

    if (!$allowed) {
        return false;
    }

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, $component, $filearea, $itemid, $filepath, $filename);

    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, 3600, 0, $forcedownload, $options);
}
