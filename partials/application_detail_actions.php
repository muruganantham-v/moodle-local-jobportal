<?php
// Application detail POST action handlers extracted from applications.php.
// Variables are provided by the caller (applications.php).
if ($action && $appid && confirm_sesskey()) {
    $application = $DB->get_record('local_jobportal_applications', array('id' => $appid, 'jobid' => $jobid), '*', MUST_EXIST);

    if ($action === 'addnote') {
        $note = trim(optional_param('note', '', PARAM_TEXT));
        if ($note === '') {
            redirect($baseurl, get_string('error:notenotempty', 'local_jobportal'), null, \core\output\notification::NOTIFY_WARNING);
        }

        $record = new stdClass();
        $record->applicationid = $application->id;
        $record->userid = $USER->id;
        $record->note = $note;
        $record->timecreated = time();
        $DB->insert_record('local_jobportal_appnotes', $record);

        redirect($baseurl, get_string('noteadded', 'local_jobportal'), null, \core\output\notification::NOTIFY_SUCCESS);
    }

    if ($action === 'updateapplyoverride') {
        require_capability('local/jobportal:managejobs', $context);

        $enableoverride = optional_param('enableoverride', 0, PARAM_BOOL) ? 1 : 0;
        $overridereason = trim(optional_param('overridereason', '', PARAM_TEXT));
        $overrideexpiresraw = trim(optional_param('overrideexpires', '', PARAM_RAW_TRIMMED));
        $overrideexpiresat = null;
        $enablemanualblock = optional_param('enablemanualblock', 0, PARAM_BOOL) ? 1 : 0;
        $manualblockreason = trim(optional_param('manualblockreason', '', PARAM_TEXT));
        $manualblockexpiresraw = trim(optional_param('manualblockexpires', '', PARAM_RAW_TRIMMED));
        $manualblockexpiresat = null;

        if ($enableoverride && $overrideexpiresraw !== '') {
            $overrideexpiresat = strtotime($overrideexpiresraw);
            if (empty($overrideexpiresat)) {
                redirect($baseurl, get_string('error:overrideexpiresinvalid', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
            }
            if ((int)$overrideexpiresat <= time()) {
                redirect($baseurl, get_string('error:overrideexpirespast', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
            }
        }

        if ($enablemanualblock && $manualblockexpiresraw !== '') {
            $manualblockexpiresat = strtotime($manualblockexpiresraw);
            if (empty($manualblockexpiresat)) {
                redirect($baseurl, get_string('error:blockexpiresinvalid', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
            }
            if ((int)$manualblockexpiresat <= time()) {
                redirect($baseurl, get_string('error:blockexpirespast', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
            }
        }

        local_jobportal_save_student_apply_override(
            (int)$application->userid,
            $enableoverride,
            $overridereason,
            $overrideexpiresat,
            (int)$USER->id,
            $enablemanualblock,
            $manualblockreason,
            $manualblockexpiresat
        );

        redirect($baseurl, get_string('applyeligibilityupdatedmsg', 'local_jobportal'), null, \core\output\notification::NOTIFY_SUCCESS);
    }

    if ($action === 'changeshortlist') {
        $shortliststatus = optional_param('shortliststatus', '', PARAM_ALPHANUMEXT);
        if (!isset($shortlistoptions[$shortliststatus])) {
            redirect($baseurl, get_string('error:invalidshortliststatus', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
        }

        $oldshortliststatus = local_jobportal_get_application_shortlist_status($application);
        if (!local_jobportal_is_shortlist_transition_allowed($oldshortliststatus, $shortliststatus)) {
            $a = (object)array(
                'from' => local_jobportal_get_shortlist_status_options()[$oldshortliststatus],
                'to' => local_jobportal_get_shortlist_status_options()[$shortliststatus],
            );
            redirect($baseurl, get_string('error:invalidshortlisttransition', 'local_jobportal', $a), null, \core\output\notification::NOTIFY_ERROR);
        }

        $shortlistnote = trim(optional_param('shortlistnote', '', PARAM_TEXT));
        if (local_jobportal_shortlist_transition_requires_note($oldshortliststatus, $shortliststatus) && $shortlistnote === '') {
            redirect($baseurl, get_string('error:transitionnoterequired', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
        }
        local_jobportal_apply_shortlist_change($application, $shortliststatus, $shortliststages, $shortlistnote, $USER->id);

        redirect($baseurl, get_string('shortliststatusupdated', 'local_jobportal'), null, \core\output\notification::NOTIFY_SUCCESS);
    }

    if ($action === 'updateresumereview') {
        $profile = $DB->get_record('local_jobportal_profiles', array('userid' => $application->userid), '*', IGNORE_MISSING);
        if (!$profile) {
            redirect($baseurl, get_string('error:noprofileforapplicant', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
        }

        if (!local_jobportal_get_profile_resume_url((int)$profile->id, $context)) {
            redirect($baseurl, get_string('error:resumenotuploaded', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
        }

        $resumestatus = optional_param('resumestatus', '', PARAM_ALPHANUMEXT);
        if (!isset($resumestatusoptions[$resumestatus])) {
            redirect($baseurl, get_string('error:invalidresumestatus', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
        }

        $ratingraw = trim(optional_param('resumerating', '', PARAM_RAW_TRIMMED));
        $rating = null;
        if ($ratingraw !== '') {
            if (!preg_match('/^\d+$/', $ratingraw)) {
                redirect($baseurl, get_string('error:invalidresumerating', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
            }
            $rating = (int)$ratingraw;
            if ($rating < 1 || $rating > 5) {
                redirect($baseurl, get_string('error:invalidresumerating', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
            }
        }

        $feedback = trim(optional_param('resumefeedback', '', PARAM_TEXT));
        if ($resumestatus === 'needsrework' && $feedback === '') {
            redirect($baseurl, get_string('error:feedbackrequiredforrework', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
        }

        local_jobportal_save_resume_reviewer_decision(
            (int)$profile->id,
            (int)$USER->id,
            $resumestatus,
            $rating,
            $feedback !== '' ? $feedback : null,
            'managerreview',
            $context
        );

        redirect($baseurl, get_string('resumereviewupdated', 'local_jobportal'), null, \core\output\notification::NOTIFY_SUCCESS);
    }

    if ($action === 'changepoststage' || $action === 'changestage') {
        if (local_jobportal_get_application_shortlist_status($application) !== 'shortlisted') {
            redirect($baseurl, get_string('error:poststageonlyforshortlisted', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
        }

        $stageid = optional_param('stageid', 0, PARAM_INT);
        if (empty($stageid) || !isset($poststages[$stageid])) {
            redirect($baseurl, get_string('error:invalidstage', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
        }

        $stage = $poststages[$stageid];
        $currentstage = local_jobportal_get_application_stage($application, $stages);
        $currentstageshortname = $currentstage ? $currentstage->shortname : '';
        if (local_jobportal_is_terminal_post_stage($currentstageshortname)) {
            redirect($baseurl, get_string('error:terminalstagelocked', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
        }
        if (!local_jobportal_is_post_stage_transition_allowed($currentstageshortname, $stage->shortname, false)) {
            $a = (object)array(
                'from' => $currentstage ? format_string($currentstage->displayname) : get_string('poststagenotset', 'local_jobportal'),
                'to' => format_string($stage->displayname),
            );
            redirect($baseurl, get_string('error:invalidstagetransition', 'local_jobportal', $a), null, \core\output\notification::NOTIFY_ERROR);
        }
        $scheduleinput = local_jobportal_parse_schedule_inputs($stage, $baseurl);
        local_jobportal_validate_schedule_meta_for_stage(
            $application,
            $stage,
            $scheduleinput['scheduledat'],
            $scheduleinput['schedulemeta'],
            $baseurl
        );
        $stagenote = trim(optional_param('stagenote', '', PARAM_TEXT));
        local_jobportal_apply_post_stage_change_with_schedule(
            $application,
            $stage,
            $scheduleinput['scheduledat'],
            $stagenote,
            $USER->id,
            $scheduleinput['schedulemeta']
        );

        redirect($baseurl, get_string('applicationstatusupdated', 'local_jobportal'), null, \core\output\notification::NOTIFY_SUCCESS);
    }

    if ($action === 'updateroundevent') {
        if (local_jobportal_get_application_shortlist_status($application) !== 'shortlisted') {
            redirect($baseurl, get_string('error:poststageonlyforshortlisted', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
        }

        $eventid = optional_param('eventid', 0, PARAM_INT);
        if (empty($eventid)) {
            redirect($baseurl, get_string('error:invalidroundevent', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
        }

        $roundevent = $DB->get_record_sql(
            "SELECT e.*, s.shortname, s.displayname, s.hasscheduledate
               FROM {local_jobportal_appstage_events} e
               JOIN {local_jobportal_stages} s ON s.id = e.stageid
              WHERE e.id = :eventid
                AND e.applicationid = :applicationid",
            array(
                'eventid' => (int)$eventid,
                'applicationid' => (int)$application->id,
            ),
            IGNORE_MISSING
        );
        if (!$roundevent || empty($roundevent->hasscheduledate) || !isset($poststages[(int)$roundevent->stageid])) {
            redirect($baseurl, get_string('error:invalidroundevent', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
        }

        $stage = $poststages[(int)$roundevent->stageid];
        $scheduleinput = local_jobportal_parse_schedule_inputs($stage, $baseurl);
        local_jobportal_validate_schedule_meta_for_stage(
            $application,
            $stage,
            $scheduleinput['scheduledat'],
            $scheduleinput['schedulemeta'],
            $baseurl,
            $roundevent
        );
        $roundnote = trim(optional_param('roundnote', '', PARAM_TEXT));

        local_jobportal_update_existing_round_event(
            $application,
            $roundevent,
            $stage,
            $scheduleinput,
            $roundnote,
            $USER->id
        );

        redirect($baseurl, get_string('roundeventupdated', 'local_jobportal'), null, \core\output\notification::NOTIFY_SUCCESS);
    }

    if ($action === 'reopenpoststage') {
        if (local_jobportal_get_application_shortlist_status($application) !== 'shortlisted') {
            redirect($baseurl, get_string('error:poststageonlyforshortlisted', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
        }

        $currentstage = local_jobportal_get_application_stage($application, $stages);
        if (!$currentstage || !local_jobportal_is_terminal_post_stage($currentstage->shortname)) {
            redirect($baseurl, get_string('error:reopenonlyterminal', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
        }

        $stageid = optional_param('stageid', 0, PARAM_INT);
        if (empty($stageid) || !isset($poststages[$stageid])) {
            redirect($baseurl, get_string('error:invalidstage', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
        }
        $stage = $poststages[$stageid];
        if (!local_jobportal_is_post_stage_transition_allowed($currentstage->shortname, $stage->shortname, true)) {
            $a = (object)array(
                'from' => format_string($currentstage->displayname),
                'to' => format_string($stage->displayname),
            );
            redirect($baseurl, get_string('error:invalidstagetransition', 'local_jobportal', $a), null, \core\output\notification::NOTIFY_ERROR);
        }

        $scheduleinput = local_jobportal_parse_schedule_inputs($stage, $baseurl);
        local_jobportal_validate_schedule_meta_for_stage(
            $application,
            $stage,
            $scheduleinput['scheduledat'],
            $scheduleinput['schedulemeta'],
            $baseurl
        );

        $reopennote = trim(optional_param('reopennote', '', PARAM_TEXT));
        if ($reopennote === '') {
            redirect($baseurl, get_string('error:transitionnoterequired', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
        }
        $stagenote = '[' . get_string('reopenedfrom', 'local_jobportal', format_string($currentstage->displayname)) . '] ' . $reopennote;

        local_jobportal_apply_post_stage_change_with_schedule(
            $application,
            $stage,
            $scheduleinput['scheduledat'],
            $stagenote,
            $USER->id,
            $scheduleinput['schedulemeta']
        );

        redirect($baseurl, get_string('applicationreopened', 'local_jobportal'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}
