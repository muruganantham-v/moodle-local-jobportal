<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_jobportal', get_string('pluginname', 'local_jobportal'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_heading(
        'local_jobportal/presetsettingsheading',
        get_string('settingspresetheading', 'local_jobportal'),
        get_string('settingspresetdesc', 'local_jobportal')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_jobportal/preset_open_enabled',
        get_string('settingspresetopenenabled', 'local_jobportal'),
        get_string('settingspresetopenenabled_desc', 'local_jobportal'),
        1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_jobportal/preset_closingsoon_enabled',
        get_string('settingspresetclosingsoonenabled', 'local_jobportal'),
        get_string('settingspresetclosingsoonenabled_desc', 'local_jobportal'),
        1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_jobportal/preset_deadlinetoday_enabled',
        get_string('settingspresetdeadlinetodayenabled', 'local_jobportal'),
        get_string('settingspresetdeadlinetodayenabled_desc', 'local_jobportal'),
        1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_jobportal/preset_deadlinetomorrow_enabled',
        get_string('settingspresetdeadlinetomorrowenabled', 'local_jobportal'),
        get_string('settingspresetdeadlinetomorrowenabled_desc', 'local_jobportal'),
        1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_jobportal/preset_noapps_enabled',
        get_string('settingspresetnoappsenabled', 'local_jobportal'),
        get_string('settingspresetnoappsenabled_desc', 'local_jobportal'),
        1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_jobportal/preset_stale_enabled',
        get_string('settingspresetstaleenabled', 'local_jobportal'),
        get_string('settingspresetstaleenabled_desc', 'local_jobportal'),
        1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_jobportal/preset_noactivity_enabled',
        get_string('settingspresetnoactivityenabled', 'local_jobportal'),
        get_string('settingspresetnoactivityenabled_desc', 'local_jobportal'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_jobportal/preset_noapps_days',
        get_string('settingspresetnoappsdays', 'local_jobportal'),
        get_string('settingspresetnoappsdays_desc', 'local_jobportal'),
        14,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_jobportal/preset_stale_days',
        get_string('settingspresetstaledays', 'local_jobportal'),
        get_string('settingspresetstaledays_desc', 'local_jobportal'),
        14,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_jobportal/preset_noactivity_days',
        get_string('settingspresetnoactivitydays', 'local_jobportal'),
        get_string('settingspresetnoactivitydays_desc', 'local_jobportal'),
        14,
        PARAM_INT
    ));
}
