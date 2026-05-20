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

    $settings->add(new admin_setting_heading(
        'local_jobportal/offermessagesheading',
        get_string('settingsoffermessagesheading', 'local_jobportal'),
        get_string('settingsoffermessagesdesc', 'local_jobportal')
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_jobportal/offer_message_offermade',
        get_string('settingsoffermessageoffermade', 'local_jobportal'),
        get_string('settingsoffermessageoffermade_desc', 'local_jobportal'),
        get_string('offeremotion_offermade', 'local_jobportal'),
        PARAM_TEXT
    ));
    $settings->add(new admin_setting_configtextarea(
        'local_jobportal/offer_message_accepted',
        get_string('settingsoffermessageaccepted', 'local_jobportal'),
        get_string('settingsoffermessageaccepted_desc', 'local_jobportal'),
        get_string('offeremotion_accepted', 'local_jobportal'),
        PARAM_TEXT
    ));
    $settings->add(new admin_setting_configtextarea(
        'local_jobportal/offer_message_rejected',
        get_string('settingsoffermessagerejected', 'local_jobportal'),
        get_string('settingsoffermessagerejected_desc', 'local_jobportal'),
        get_string('offeremotion_rejected', 'local_jobportal'),
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_heading(
        'local_jobportal/studentaccesspolicyheading',
        get_string('settingsstudentaccesspolicyheading', 'local_jobportal'),
        get_string('settingsstudentaccesspolicydesc', 'local_jobportal')
    ));

    $settings->add(new admin_setting_configselect(
        'local_jobportal/studentpolicy_feedmode',
        get_string('settingsstudentfeedmode', 'local_jobportal'),
        get_string('settingsstudentfeedmode_desc', 'local_jobportal'),
        'openjobs',
        array(
            'openjobs' => get_string('settingsstudentfeedmode_openjobs', 'local_jobportal'),
            'alljobs' => get_string('settingsstudentfeedmode_alljobs', 'local_jobportal'),
        )
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_jobportal/studentpolicy_requireresumeapproved',
        get_string('settingsstudentrequireresumeapproved', 'local_jobportal'),
        get_string('settingsstudentrequireresumeapproved_desc', 'local_jobportal'),
        0
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_jobportal/studentpolicy_blocknoshow',
        get_string('settingsstudentblockinterviewnoshow', 'local_jobportal'),
        get_string('settingsstudentblockinterviewnoshow_desc', 'local_jobportal'),
        1
    ));
    $settings->add(new admin_setting_configtext(
        'local_jobportal/studentpolicy_maxactiveapplications',
        get_string('settingsstudentmaxactiveapplications', 'local_jobportal'),
        get_string('settingsstudentmaxactiveapplications_desc', 'local_jobportal'),
        0,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_jobportal/studentpolicy_weeklyapplicationlimit',
        get_string('settingsstudentweeklyapplicationlimit', 'local_jobportal'),
        get_string('settingsstudentweeklyapplicationlimit_desc', 'local_jobportal'),
        0,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_jobportal/studentpolicy_notshortlistedcooldownenabled',
        get_string('settingsstudentnotshortlistedcooldownenabled', 'local_jobportal'),
        get_string('settingsstudentnotshortlistedcooldownenabled_desc', 'local_jobportal'),
        0
    ));
    $settings->add(new admin_setting_configtext(
        'local_jobportal/studentpolicy_notshortlistedtriggercount',
        get_string('settingsstudentnotshortlistedtriggercount', 'local_jobportal'),
        get_string('settingsstudentnotshortlistedtriggercount_desc', 'local_jobportal'),
        3,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_jobportal/studentpolicy_notshortlistedcooldowndays',
        get_string('settingsstudentnotshortlistedcooldowndays', 'local_jobportal'),
        get_string('settingsstudentnotshortlistedcooldowndays_desc', 'local_jobportal'),
        14,
        PARAM_INT
    ));
}
