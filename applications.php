<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');
require_once(__DIR__ . '/locallib.php');

require_login();

/**
 * Whether a post-shortlist stage is terminal.
 *
 * @param string $shortname
 * @return bool
 */
function local_jobportal_is_terminal_post_stage($shortname) {
    $shortname = core_text::strtolower(trim((string)$shortname));
    return in_array($shortname, array('accepted', 'rejected'), true);
}

/**
 * Whether shortlist transition is allowed.
 *
 * @param string $from
 * @param string $to
 * @return bool
 */
function local_jobportal_is_shortlist_transition_allowed($from, $to) {
    $from = local_jobportal_normalize_shortlist_status($from);
    $to = local_jobportal_normalize_shortlist_status($to);
    if ($from === $to) {
        return true;
    }

    $matrix = array(
        'pending' => array('internalshortlisted', 'shortlisted', 'notshortlisted'),
        'internalshortlisted' => array('pending', 'shortlisted', 'notshortlisted'),
        'shortlisted' => array('notshortlisted'),
        'notshortlisted' => array('shortlisted'),
    );

    return !empty($matrix[$from]) && in_array($to, $matrix[$from], true);
}

/**
 * Whether shortlist transition requires a reason note.
 *
 * @param string $from
 * @param string $to
 * @return bool
 */
function local_jobportal_shortlist_transition_requires_note($from, $to) {
    $from = local_jobportal_normalize_shortlist_status($from);
    $to = local_jobportal_normalize_shortlist_status($to);
    $pair = $from . ':' . $to;
    return in_array($pair, array('shortlisted:notshortlisted', 'notshortlisted:shortlisted'), true);
}

/**
 * Whether post-shortlist stage transition is allowed.
 *
 * @param string $from
 * @param string $to
 * @param bool $isreopen
 * @return bool
 */
function local_jobportal_is_post_stage_transition_allowed($from, $to, $isreopen = false) {
    $from = core_text::strtolower(trim((string)$from));
    $to = core_text::strtolower(trim((string)$to));

    if ($isreopen) {
        if (!local_jobportal_is_terminal_post_stage($from)) {
            return false;
        }
        $reopenallowed = array('testscheduled', 'interviewscheduled', 'offermade');
        return in_array($to, $reopenallowed, true);
    }

    $matrix = array(
        '' => array('testscheduled', 'interviewscheduled'),
        'testscheduled' => array('testscheduled', 'interviewscheduled', 'offermade'),
        'interviewscheduled' => array('interviewscheduled', 'offermade'),
        // Legacy support for records already in Test Done / Interview Done.
        'testdone' => array('testscheduled', 'interviewscheduled', 'offermade'),
        'interviewdone' => array('interviewscheduled', 'offermade'),
        'offermade' => array('accepted', 'rejected'),
        'accepted' => array(),
        'rejected' => array(),
    );

    if (!array_key_exists($from, $matrix)) {
        $from = '';
    }

    return in_array($to, $matrix[$from], true);
}

/**
 * Render a select control with compact inline tooltip label.
 *
 * @param array<string,string> $options
 * @param string $name
 * @param string $selected
 * @param mixed $nothingoption
 * @param array<string,mixed> $attributes
 * @param string $label
 * @param string $tooltip
 * @return string
 */
function local_jobportal_render_select_with_tooltip($options, $name, $selected, $nothingoption, $attributes, $label, $tooltip) {
    $labelhtml = html_writer::span($label, 'jp-inline-field-label-text');
    $labelhtml .= html_writer::tag('span', '?', array(
        'class' => 'jp-inline-help',
        'title' => $tooltip,
        'aria-label' => $tooltip,
        'tabindex' => '0',
    ));
    return html_writer::div($labelhtml, 'jp-inline-field-label') .
        html_writer::select($options, $name, $selected, $nothingoption, $attributes);
}

/**
 * Validate schedule metadata rules for a stage transition.
 *
 * @param stdClass $application
 * @param stdClass $stage
 * @param int|null $scheduledat
 * @param array<string,mixed> $schedulemeta
 * @param moodle_url $redirecturl
 * @param stdClass|null $existingevent
 * @return void
 */
function local_jobportal_validate_schedule_meta_for_stage(
    $application,
    $stage,
    $scheduledat,
    $schedulemeta,
    $redirecturl,
    $existingevent = null
) {
    global $DB;

    if (empty($stage->hasscheduledate)) {
        return;
    }

    $schedulestatus = local_jobportal_normalize_schedule_status($schedulemeta['schedulestatus'] ?? 'scheduled');
    $roundoutcome = local_jobportal_normalize_round_outcome($schedulemeta['roundoutcome'] ?? 'pending');
    $now = time();

    if (in_array($schedulestatus, array('scheduled', 'rescheduled'), true)) {
        if (empty($scheduledat)) {
            redirect(
                $redirecturl,
                get_string('error:scheduledaterequired', 'local_jobportal', format_string($stage->displayname)),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        if ((int)$scheduledat < $now) {
            redirect($redirecturl, get_string('error:schedulefutureonly', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
        }
        if ($roundoutcome !== 'pending') {
            redirect($redirecturl, get_string('error:roundoutcomependingonscheduled', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
        }
        return;
    }

    if (in_array($schedulestatus, array('completed', 'cancelled', 'noshow', 'excused'), true)) {
        $skiphistorycheck = false;
        if (!empty($existingevent->id)) {
            $existingstatus = !empty($existingevent->schedulestatus) ?
                local_jobportal_normalize_schedule_status($existingevent->schedulestatus) : 'scheduled';
            if (in_array($existingstatus, array('completed', 'cancelled', 'noshow', 'excused'), true)) {
                $skiphistorycheck = true;
            }
        }
        if (!$skiphistorycheck) {
            list($statussql, $statusparams) = $DB->get_in_or_equal(array('scheduled', 'rescheduled'), SQL_PARAMS_NAMED);
            $params = array_merge(
                array(
                    'applicationid' => (int)$application->id,
                    'stageid' => (int)$stage->id,
                ),
                $statusparams
            );
            $condition = "applicationid = :applicationid AND stageid = :stageid AND schedulestatus $statussql";
            $exists = $DB->record_exists_select(
                'local_jobportal_appstage_events',
                $condition,
                $params
            );
            if (!$exists) {
                $statuslabel = local_jobportal_get_schedule_status_label($schedulestatus);
                redirect(
                    $redirecturl,
                    get_string('error:schedulehistoryrequired', 'local_jobportal', $statuslabel),
                    null,
                    \core\output\notification::NOTIFY_ERROR
                );
            }
        }
    }

    if ($schedulestatus === 'completed' && !in_array($roundoutcome, array('cleared', 'notcleared'), true)) {
        redirect($redirecturl, get_string('error:roundoutcomerequiredoncomplete', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
    }
    if (in_array($schedulestatus, array('cancelled', 'noshow', 'excused'), true) && $roundoutcome !== 'pending') {
        redirect($redirecturl, get_string('error:roundoutcomependingonscheduled', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
    }
}

/**
 * Apply shortlist status transition for one application.
 *
 * @param stdClass $application
 * @param string $shortliststatus
 * @param array<string,stdClass> $shortliststages
 * @param string $shortlistnote
 * @param int $changedby
 * @return void
 */
function local_jobportal_apply_shortlist_change($application, $shortliststatus, $shortliststages, $shortlistnote, $changedby) {
    global $DB;

    $shortliststatus = local_jobportal_normalize_shortlist_status($shortliststatus);
    $oldshortliststatus = local_jobportal_get_application_shortlist_status($application);
    $now = time();

    $update = new stdClass();
    $update->id = (int)$application->id;
    $update->shortliststatus = $shortliststatus;
    $update->timemodified = $now;

    // Only shortlisted applications can carry post-shortlist stage state.
    if ($shortliststatus !== 'shortlisted') {
        $update->status = 'pending';
        $update->currentstageid = null;
    } else if ($oldshortliststatus !== 'shortlisted') {
        $update->status = 'pending';
        $update->currentstageid = null;
    }

    $transaction = $DB->start_delegated_transaction();
    $DB->update_record('local_jobportal_applications', $update);

    if (isset($shortliststages[$shortliststatus])) {
        $event = new stdClass();
        $event->applicationid = (int)$application->id;
        $event->stageid = (int)$shortliststages[$shortliststatus]->id;
        $event->changedby = (int)$changedby;
        $event->notes = $shortlistnote !== '' ? $shortlistnote : null;
        $event->scheduledat = null;
        $event->schedulestatus = 'scheduled';
        $event->roundoutcome = 'pending';
        $event->schedulemode = null;
        $event->schedulelink = null;
        $event->schedulevenue = null;
        $event->scheduleduration = null;
        $event->timecreated = $now;
        $DB->insert_record('local_jobportal_appstage_events', $event);
    }

    if ($shortliststatus !== 'shortlisted') {
        $poststages = local_jobportal_get_post_shortlist_stages(false, true);
        if (!empty($poststages)) {
            list($stageidsql, $stageidparams) = $DB->get_in_or_equal(array_keys($poststages), SQL_PARAMS_NAMED);
            list($schedsql, $schedparams) = $DB->get_in_or_equal(array('scheduled', 'rescheduled'), SQL_PARAMS_NAMED);
            $cancelparams = array_merge(
                array(
                    'applicationid' => (int)$application->id,
                    'now' => $now,
                ),
                $stageidparams,
                $schedparams
            );
            $DB->execute(
                "UPDATE {local_jobportal_appstage_events}
                    SET schedulestatus = 'cancelled'
                  WHERE applicationid = :applicationid
                    AND stageid $stageidsql
                    AND scheduledat IS NOT NULL
                    AND scheduledat >= :now
                    AND schedulestatus $schedsql",
                $cancelparams
            );
        }
    }

    if ($shortlistnote !== '') {
        $note = new stdClass();
        $note->applicationid = (int)$application->id;
        $note->userid = (int)$changedby;
        $note->note = '[' . get_string('shortliststatus', 'local_jobportal') . ': ' .
            local_jobportal_get_shortlist_status_options()[$shortliststatus] . '] ' . $shortlistnote;
        $note->timecreated = $now;
        $DB->insert_record('local_jobportal_appnotes', $note);
    }

    $transaction->allow_commit();
}

/**
 * Apply a post-shortlist stage transition for one application.
 *
 * @param stdClass $application
 * @param stdClass $stage
 * @param int|null $scheduledat
 * @param string $stagenote
 * @param int $changedby
 * @return int
 */
function local_jobportal_apply_post_stage_change($application, $stage, $scheduledat, $stagenote, $changedby) {
    global $DB;

    if (local_jobportal_get_application_shortlist_status($application) !== 'shortlisted') {
        throw new coding_exception('Only shortlisted applications can move through post-shortlist stages.');
    }

    $now = time();

    $update = new stdClass();
    $update->id = (int)$application->id;
    $update->currentstageid = (int)$stage->id;
    $update->status = $stage->shortname;
    $update->timemodified = $now;

    if ($stage->shortname === 'interviewscheduled') {
        $update->interviewscheduledat = !empty($scheduledat) ? $scheduledat : $now;
    } else if ($stage->shortname === 'offermade' && empty($application->offermadeat)) {
        $update->offermadeat = $now;
    }

    $transaction = $DB->start_delegated_transaction();
    $DB->update_record('local_jobportal_applications', $update);

    $event = new stdClass();
    $event->applicationid = (int)$application->id;
    $event->stageid = (int)$stage->id;
    $event->changedby = (int)$changedby;
    $event->notes = $stagenote !== '' ? $stagenote : null;
    $event->scheduledat = $scheduledat;
    $event->schedulestatus = 'scheduled';
    $event->roundoutcome = 'pending';
    $event->schedulemode = null;
    $event->schedulelink = null;
    $event->schedulevenue = null;
    $event->scheduleduration = null;
    $event->timecreated = $now;
    $eventid = $DB->insert_record('local_jobportal_appstage_events', $event);

    if ($stagenote !== '') {
        $note = new stdClass();
        $note->applicationid = (int)$application->id;
        $note->userid = (int)$changedby;
        $note->note = '[' . format_string($stage->displayname) . '] ' . $stagenote;
        $note->timecreated = $now;
        $DB->insert_record('local_jobportal_appnotes', $note);
    }

    $transaction->allow_commit();
    return (int)$eventid;
}

/**
 * Apply a post-shortlist stage transition for one application with schedule metadata.
 *
 * @param stdClass $application
 * @param stdClass $stage
 * @param int|null $scheduledat
 * @param string $stagenote
 * @param int $changedby
 * @param array<string,mixed> $schedulemeta
 * @return void
 */
function local_jobportal_apply_post_stage_change_with_schedule(
    $application,
    $stage,
    $scheduledat,
    $stagenote,
    $changedby,
    $schedulemeta = array()
) {
    global $DB;

    $transaction = $DB->start_delegated_transaction();

    $schedulestatus = local_jobportal_normalize_schedule_status($schedulemeta['schedulestatus'] ?? 'scheduled');
    $roundoutcome = local_jobportal_normalize_round_outcome($schedulemeta['roundoutcome'] ?? 'pending');
    $schedulemode = local_jobportal_normalize_schedule_mode($schedulemeta['schedulemode'] ?? '');
    $schedulelink = trim((string)($schedulemeta['schedulelink'] ?? ''));
    $schedulevenue = trim((string)($schedulemeta['schedulevenue'] ?? ''));
    $scheduleduration = null;
    if (isset($schedulemeta['scheduleduration']) && $schedulemeta['scheduleduration'] !== '' && $schedulemeta['scheduleduration'] !== null) {
        $scheduleduration = (int)$schedulemeta['scheduleduration'];
        if ($scheduleduration <= 0) {
            $scheduleduration = null;
        }
    }

    if (empty($stage->hasscheduledate)) {
        $schedulestatus = 'scheduled';
        $roundoutcome = 'pending';
        $schedulemode = '';
        $schedulelink = '';
        $schedulevenue = '';
        $scheduleduration = null;
    }

    $eventid = local_jobportal_apply_post_stage_change($application, $stage, $scheduledat, $stagenote, $changedby);
    if (empty($eventid)) {
        $transaction->allow_commit();
        return;
    }

    $updateevent = new stdClass();
    $updateevent->id = (int)$eventid;
    $updateevent->schedulestatus = $schedulestatus;
    $updateevent->roundoutcome = !empty($stage->hasscheduledate) ? $roundoutcome : 'pending';
    $updateevent->schedulemode = $schedulemode !== '' ? $schedulemode : null;
    $updateevent->schedulelink = $schedulelink !== '' ? $schedulelink : null;
    $updateevent->schedulevenue = $schedulevenue !== '' ? $schedulevenue : null;
    $updateevent->scheduleduration = $scheduleduration;
    $DB->update_record('local_jobportal_appstage_events', $updateevent);

    $transaction->allow_commit();
}

/**
 * Update a specific existing round event in place.
 *
 * @param stdClass $application
 * @param stdClass $event
 * @param stdClass $stage
 * @param array{scheduledat:int|null,schedulemeta:array<string,mixed>} $scheduleinput
 * @param string $roundnote
 * @param int $changedby
 * @return void
 */
function local_jobportal_update_existing_round_event($application, $event, $stage, $scheduleinput, $roundnote, $changedby) {
    global $DB;

    $scheduledat = $scheduleinput['scheduledat'] ?? null;
    $schedulemeta = $scheduleinput['schedulemeta'] ?? array();
    $schedulestatus = local_jobportal_normalize_schedule_status($schedulemeta['schedulestatus'] ?? 'scheduled');
    $roundoutcome = local_jobportal_normalize_round_outcome($schedulemeta['roundoutcome'] ?? 'pending');
    $schedulemode = local_jobportal_normalize_schedule_mode($schedulemeta['schedulemode'] ?? '');
    $schedulelink = trim((string)($schedulemeta['schedulelink'] ?? ''));
    $schedulevenue = trim((string)($schedulemeta['schedulevenue'] ?? ''));
    $scheduleduration = null;
    if (isset($schedulemeta['scheduleduration']) && $schedulemeta['scheduleduration'] !== '' && $schedulemeta['scheduleduration'] !== null) {
        $scheduleduration = (int)$schedulemeta['scheduleduration'];
        if ($scheduleduration <= 0) {
            $scheduleduration = null;
        }
    }

    if ($scheduledat === null && !empty($event->scheduledat)) {
        $scheduledat = (int)$event->scheduledat;
    }
    if ($schedulemode === '' && !empty($event->schedulemode)) {
        $schedulemode = local_jobportal_normalize_schedule_mode($event->schedulemode);
    }
    if ($schedulelink === '' && !empty($event->schedulelink)) {
        $schedulelink = trim((string)$event->schedulelink);
    }
    if ($schedulevenue === '' && !empty($event->schedulevenue)) {
        $schedulevenue = trim((string)$event->schedulevenue);
    }
    if ($scheduleduration === null && !empty($event->scheduleduration)) {
        $scheduleduration = (int)$event->scheduleduration;
    }

    $transaction = $DB->start_delegated_transaction();

    $updateevent = new stdClass();
    $updateevent->id = (int)$event->id;
    $updateevent->scheduledat = $scheduledat;
    $updateevent->schedulestatus = $schedulestatus;
    $updateevent->roundoutcome = $roundoutcome;
    $updateevent->schedulemode = $schedulemode !== '' ? $schedulemode : null;
    $updateevent->schedulelink = $schedulelink !== '' ? $schedulelink : null;
    $updateevent->schedulevenue = $schedulevenue !== '' ? $schedulevenue : null;
    $updateevent->scheduleduration = $scheduleduration;
    $updateevent->changedby = (int)$changedby;
    if ($roundnote !== '') {
        $updateevent->notes = $roundnote;
    }
    $DB->update_record('local_jobportal_appstage_events', $updateevent);

    if ($roundnote !== '') {
        $note = new stdClass();
        $note->applicationid = (int)$application->id;
        $note->userid = (int)$changedby;
        $note->note = '[' . get_string('roundupdate', 'local_jobportal') . ': ' . format_string($stage->displayname) . '] ' . $roundnote;
        $note->timecreated = time();
        $DB->insert_record('local_jobportal_appnotes', $note);
    }

    $transaction->allow_commit();
}

/**
 * Parse schedule inputs from request and validate.
 *
 * @param stdClass $stage
 * @param moodle_url $redirecturl
 * @param string $prefix
 * @return array{scheduledat:int|null,schedulemeta:array<string,mixed>}
 */
function local_jobportal_parse_schedule_inputs($stage, $redirecturl, $prefix = '') {
    $scheduledraw = optional_param($prefix . 'scheduleddatetime', '', PARAM_RAW_TRIMMED);
    $schedulestatusraw = core_text::strtolower(trim(optional_param($prefix . 'schedulestatus', 'scheduled', PARAM_ALPHANUMEXT)));
    $roundoutcomeraw = core_text::strtolower(trim(optional_param($prefix . 'roundoutcome', 'pending', PARAM_ALPHANUMEXT)));
    $schedulemoderaw = optional_param($prefix . 'schedulemode', '', PARAM_ALPHA);
    $schedulelinkraw = trim(optional_param($prefix . 'schedulelink', '', PARAM_RAW_TRIMMED));
    $schedulevenueraw = trim(optional_param($prefix . 'schedulevenue', '', PARAM_TEXT));
    $durationraw = trim(optional_param($prefix . 'scheduleduration', '', PARAM_RAW_TRIMMED));

    if (empty($stage->hasscheduledate)) {
        $hasscheduledetails = (
            $scheduledraw !== '' ||
            $schedulemoderaw !== '' ||
            $schedulelinkraw !== '' ||
            $schedulevenueraw !== '' ||
            $durationraw !== '' ||
            $schedulestatusraw !== 'scheduled' ||
            $roundoutcomeraw !== 'pending'
        );
        if ($hasscheduledetails) {
            redirect(
                $redirecturl,
                get_string('error:schedulefornonschedulablestage', 'local_jobportal'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        return array(
            'scheduledat' => null,
            'schedulemeta' => array(
                'schedulestatus' => 'scheduled',
                'roundoutcome' => 'pending',
                'schedulemode' => '',
                'schedulelink' => '',
                'schedulevenue' => '',
                'scheduleduration' => null,
            ),
        );
    }

    $scheduledat = null;
    if ($scheduledraw !== '') {
        $scheduledat = strtotime($scheduledraw);
        if (empty($scheduledat)) {
            redirect($redirecturl, get_string('error:scheduledatetimeinvalid', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
        }
    }

    $schedulestatusoptions = local_jobportal_get_schedule_status_options();
    $schedulestatus = $schedulestatusraw;
    if (!isset($schedulestatusoptions[$schedulestatus])) {
        redirect($redirecturl, get_string('error:invalidschedulestatus', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
    }
    $roundoutcomeoptions = local_jobportal_get_round_outcome_options();
    $roundoutcome = $roundoutcomeraw;
    if (!isset($roundoutcomeoptions[$roundoutcome])) {
        redirect($redirecturl, get_string('error:invalidroundoutcome', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
    }
    if (in_array($schedulestatus, array('scheduled', 'rescheduled'), true) && empty($scheduledat)) {
        redirect(
            $redirecturl,
            get_string('error:scheduledaterequired', 'local_jobportal', format_string($stage->displayname)),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $schedulemode = local_jobportal_normalize_schedule_mode($schedulemoderaw);
    $schedulelink = $schedulelinkraw;
    $schedulevenue = $schedulevenueraw;
    $scheduleduration = null;
    if ($durationraw !== '') {
        if (!preg_match('/^\d+$/', $durationraw) || (int)$durationraw <= 0) {
            redirect($redirecturl, get_string('error:invalidscheduleduration', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
        }
        $scheduleduration = (int)$durationraw;
    }

    if ($schedulelink !== '') {
        // Accept host/path input by auto-prepending https scheme.
        if (!preg_match('#^[a-z][a-z0-9+\-.]*://#i', $schedulelink)) {
            $schedulelink = 'https://' . $schedulelink;
        }
        if (!preg_match('#^https?://#i', $schedulelink) || !filter_var($schedulelink, FILTER_VALIDATE_URL)) {
            redirect($redirecturl, get_string('invalidurl', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
        }
        $schedulelink = core_text::substr($schedulelink, 0, 255);
    }
    if ($schedulevenue !== '') {
        $schedulevenue = core_text::substr($schedulevenue, 0, 255);
    }

    if (!empty($scheduledat) && $schedulemode === 'online' && $schedulelink === '') {
        redirect($redirecturl, get_string('error:schedulelinkrequired', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
    }
    if (!empty($scheduledat) && $schedulemode === 'offline' && $schedulevenue === '') {
        redirect($redirecturl, get_string('error:schedulevenuerequired', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
    }

    $schedulemeta = array(
        'schedulestatus' => $schedulestatus,
        'roundoutcome' => $roundoutcome,
        'schedulemode' => $schedulemode,
        'schedulelink' => $schedulelink,
        'schedulevenue' => $schedulevenue,
        'scheduleduration' => $scheduleduration,
    );

    return array(
        'scheduledat' => $scheduledat,
        'schedulemeta' => $schedulemeta,
    );
}

$jobid = required_param('jobid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$appid = optional_param('appid', 0, PARAM_INT);
$showapp = optional_param('showapp', 0, PARAM_INT);
$standalone = optional_param('standalone', 0, PARAM_BOOL);
$isstandalone = !empty($standalone);
$appids = optional_param_array('appids', array(), PARAM_INT);
$export = optional_param('export', '', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$appsearch = trim(optional_param('appsearch', '', PARAM_RAW_TRIMMED));
$filtershortlist = optional_param('filtershortlist', 'all', PARAM_ALPHANUMEXT);
$filterpoststage = optional_param('filterpoststage', 'all', PARAM_ALPHANUMEXT);
$filterresumestatus = optional_param('filterresumestatus', 'all', PARAM_ALPHANUMEXT);
$filterhasresume = optional_param('filterhasresume', 'all', PARAM_ALPHA);
$filterappliedfromraw = trim(optional_param('filterappliedfrom', '', PARAM_RAW_TRIMMED));
$filterappliedtoraw = trim(optional_param('filterappliedto', '', PARAM_RAW_TRIMMED));
$filterinactivefordaysraw = trim(optional_param('filterinactivefordays', '', PARAM_RAW_TRIMMED));
$appsort = optional_param('appsort', 'appliedon', PARAM_ALPHANUMEXT);
$appsortdir = core_text::strtolower(trim(optional_param('appsortdir', 'desc', PARAM_ALPHA)));
$activeappfilterscount = 0;
if ($appsearch !== '') {
    $activeappfilterscount++;
}
if ($filtershortlist !== 'all') {
    $activeappfilterscount++;
}
if ($filterpoststage !== 'all') {
    $activeappfilterscount++;
}
if ($filterresumestatus !== 'all') {
    $activeappfilterscount++;
}
if ($filterhasresume !== 'all') {
    $activeappfilterscount++;
}
if ($filterappliedfromraw !== '') {
    $activeappfilterscount++;
}
if ($filterappliedtoraw !== '') {
    $activeappfilterscount++;
}
if ($filterinactivefordaysraw !== '') {
    $activeappfilterscount++;
}
if ($page < 0) {
    $page = 0;
}
$perpage = 10;

$context = context_system::instance();
require_capability('local/jobportal:viewapplications', $context);
$PAGE->set_context($context);

$job = $DB->get_record('local_jobportal_jobs', array('id' => $jobid), '*', MUST_EXIST);
$headercompany = !empty($job->companyid) ? local_jobportal_get_company((int)$job->companyid) : false;
$headercompanyname = $headercompany ? format_string($headercompany->name) : format_string($job->company);
$headercompanylogo = $headercompany ? local_jobportal_get_company_logo_url((int)$headercompany->id, $context) : null;
$companyinitials = '';
if ($headercompanyname !== '') {
    $companyparts = preg_split('/\s+/', trim($headercompanyname));
    if (!empty($companyparts)) {
        $first = core_text::substr($companyparts[0], 0, 1);
        $second = !empty($companyparts[1]) ? core_text::substr($companyparts[1], 0, 1) : '';
        $companyinitials = core_text::strtoupper($first . $second);
    }
}
if ($companyinitials === '') {
    $companyinitials = '?';
}

local_jobportal_ensure_default_stages();
$stages = local_jobportal_get_recruitment_stages(false);
$shortlistoptions = local_jobportal_get_shortlist_status_options();
$shortliststages = array();
foreach ($stages as $stage) {
    if (isset($shortlistoptions[$stage->shortname])) {
        $shortliststages[$stage->shortname] = $stage;
    }
}
$poststages = local_jobportal_get_post_shortlist_stages(false, true);
$activepoststages = local_jobportal_get_post_shortlist_stages(true, true);
$poststageoptions = local_jobportal_get_post_shortlist_stage_options(true, true, true);
$schedulableroundstageoptions = array();
foreach ($poststages as $poststageitem) {
    if (empty($poststageitem->hasscheduledate)) {
        continue;
    }
    $schedulableroundstageoptions[(int)$poststageitem->id] = format_string($poststageitem->displayname);
}
$resumestatusoptions = local_jobportal_get_resume_status_options();
$schedulestatusoptions = local_jobportal_get_schedule_status_options();
$roundoutcomeoptions = local_jobportal_get_round_outcome_options();
$schedulemodeoptions = local_jobportal_get_schedule_mode_options();
$stagefilteroptions = array(
    'all' => get_string('postshortliststage', 'local_jobportal') . ': ' . get_string('alloptions', 'local_jobportal'),
);
$stagefilteroptions['notset'] = get_string('poststagenotset', 'local_jobportal');
foreach ($poststageoptions as $stageid => $stagename) {
    $stagefilteroptions[(string)(int)$stageid] = $stagename;
}
$stageschedulablemap = array();
foreach ($poststages as $poststageitem) {
    $stageschedulablemap[(string)(int)$poststageitem->id] = !empty($poststageitem->hasscheduledate);
}

if ($filtershortlist !== 'all' && !isset($shortlistoptions[$filtershortlist])) {
    $filtershortlist = 'all';
}
if ($filterresumestatus !== 'all' && !isset($resumestatusoptions[$filterresumestatus])) {
    $filterresumestatus = 'all';
}
if (!in_array($filterhasresume, array('all', 'yes', 'no'), true)) {
    $filterhasresume = 'all';
}
if ($filterpoststage !== 'all' && $filterpoststage !== 'notset' && !isset($stagefilteroptions[$filterpoststage])) {
    $filterpoststage = 'all';
}
$allowedappsorts = array('appliedon', 'name', 'shortliststatus', 'poststage', 'resumestatus', 'resumerating', 'lastactivity');
if (!in_array($appsort, $allowedappsorts, true)) {
    $appsort = 'appliedon';
}
if (!in_array($appsortdir, array('asc', 'desc'), true)) {
    $appsortdir = 'desc';
}

$filterappliedfrom = null;
if ($filterappliedfromraw !== '') {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterappliedfromraw)) {
        $fromts = strtotime($filterappliedfromraw . ' 00:00:00');
        if ($fromts !== false) {
            $filterappliedfrom = (int)$fromts;
        } else {
            $filterappliedfromraw = '';
        }
    } else {
        $filterappliedfromraw = '';
    }
}

$filterappliedto = null;
if ($filterappliedtoraw !== '') {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterappliedtoraw)) {
        $tots = strtotime($filterappliedtoraw . ' 23:59:59');
        if ($tots !== false) {
            $filterappliedto = (int)$tots;
        } else {
            $filterappliedtoraw = '';
        }
    } else {
        $filterappliedtoraw = '';
    }
}
if ($filterappliedfrom !== null && $filterappliedto !== null && $filterappliedfrom > $filterappliedto) {
    $tmp = $filterappliedfrom;
    $filterappliedfrom = $filterappliedto;
    $filterappliedto = $tmp;
    $tmpraw = $filterappliedfromraw;
    $filterappliedfromraw = $filterappliedtoraw;
    $filterappliedtoraw = $tmpraw;
}

$filterinactivefordays = null;
if ($filterinactivefordaysraw !== '') {
    if (preg_match('/^\d+$/', $filterinactivefordaysraw)) {
        $filterinactivefordays = (int)$filterinactivefordaysraw;
        if ($filterinactivefordays < 1) {
            $filterinactivefordays = null;
            $filterinactivefordaysraw = '';
        } else if ($filterinactivefordays > 3650) {
            $filterinactivefordays = 3650;
            $filterinactivefordaysraw = (string)$filterinactivefordays;
        }
    } else {
        $filterinactivefordaysraw = '';
    }
}

$appfilterparams = array();
if (empty($showapp) && $appsearch !== '') {
    $appfilterparams['appsearch'] = $appsearch;
}
if (empty($showapp) && $filtershortlist !== 'all') {
    $appfilterparams['filtershortlist'] = $filtershortlist;
}
if ($filterpoststage !== 'all') {
    $appfilterparams['filterpoststage'] = $filterpoststage;
}
if ($filterresumestatus !== 'all') {
    $appfilterparams['filterresumestatus'] = $filterresumestatus;
}
if ($filterhasresume !== 'all') {
    $appfilterparams['filterhasresume'] = $filterhasresume;
}
if ($filterappliedfromraw !== '') {
    $appfilterparams['filterappliedfrom'] = $filterappliedfromraw;
}
if ($filterappliedtoraw !== '') {
    $appfilterparams['filterappliedto'] = $filterappliedtoraw;
}
if ($filterinactivefordaysraw !== '') {
    $appfilterparams['filterinactivefordays'] = $filterinactivefordaysraw;
}
if ($appsort !== 'appliedon') {
    $appfilterparams['appsort'] = $appsort;
}
if ($appsortdir !== 'desc') {
    $appfilterparams['appsortdir'] = $appsortdir;
}
$hasappfilters = !empty($appfilterparams);
$shortlistfilteroptions = array('all' => get_string('shortliststatus', 'local_jobportal') . ': ' . get_string('alloptions', 'local_jobportal')) +
    $shortlistoptions;
$resumestatusfilteroptions = array(
    'all' => get_string('resumereviewstatus', 'local_jobportal') . ': ' . get_string('alloptions', 'local_jobportal'),
) + $resumestatusoptions;
$hasresumefilteroptions = array(
    'all' => get_string('resume', 'local_jobportal') . ': ' . get_string('alloptions', 'local_jobportal'),
    'yes' => get_string('resume', 'local_jobportal') . ': ' . get_string('hasapplications_yes', 'local_jobportal'),
    'no' => get_string('resume', 'local_jobportal') . ': ' . get_string('hasapplications_no', 'local_jobportal'),
);
$appsortoptions = array(
    'appliedon' => get_string('sortby', 'local_jobportal') . ': ' . get_string('appliedon', 'local_jobportal'),
    'name' => get_string('sortby', 'local_jobportal') . ': ' . get_string('applicantname', 'local_jobportal'),
    'shortliststatus' => get_string('sortby', 'local_jobportal') . ': ' . get_string('shortliststatus', 'local_jobportal'),
    'poststage' => get_string('sortby', 'local_jobportal') . ': ' . get_string('postshortliststage', 'local_jobportal'),
    'resumestatus' => get_string('sortby', 'local_jobportal') . ': ' . get_string('resumereviewstatus', 'local_jobportal'),
    'resumerating' => get_string('sortby', 'local_jobportal') . ': ' . get_string('resumerating', 'local_jobportal'),
    'lastactivity' => get_string('sortby', 'local_jobportal') . ': ' . get_string('lastactivity', 'local_jobportal'),
);
$appsortdiroptions = array(
    'asc' => get_string('sortdirection', 'local_jobportal') . ': ' . get_string('sortasc', 'local_jobportal'),
    'desc' => get_string('sortdirection', 'local_jobportal') . ': ' . get_string('sortdesc', 'local_jobportal'),
);

$listpath = '/local/jobportal/applications.php';
$pagepath = $isstandalone ? '/local/jobportal/application.php' : $listpath;

$baseurlparams = array('jobid' => $jobid);
$baseurlparams = array_merge($baseurlparams, $appfilterparams);
if (!empty($showapp)) {
    $baseurlparams['showapp'] = $showapp;
}
if ($isstandalone) {
    $baseurlparams['standalone'] = 1;
    if (!empty($showapp)) {
        $baseurlparams['appid'] = $showapp;
    }
}
if (!empty($page)) {
    $baseurlparams['page'] = $page;
}
$baseurl = new moodle_url($pagepath, $baseurlparams);
$pageactionurl = new moodle_url($pagepath);
$standalonevalue = $isstandalone ? 1 : 0;

// Detail view is single-record workflow; keep list-style XLS export disabled here.
if (!empty($showapp)) {
    $export = '';
}

$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('applicationsfor', 'local_jobportal', format_string($job->title)));
$PAGE->set_heading(get_string('applicationsfor', 'local_jobportal', format_string($job->title)));
local_jobportal_require_styles();
$dateformat = '%d/%m/%Y';
$datetimeformat = '%d/%m/%Y %H:%M';
$datetimesecondsformat = '%d/%m/%Y %H:%M:%S';

require(__DIR__ . '/partials/application_detail_actions.php');

if ($action === 'bulkchangeshortlist' && confirm_sesskey()) {
    if (empty($appids)) {
        redirect($baseurl, get_string('error:noapplicationsselected', 'local_jobportal'), null, \core\output\notification::NOTIFY_WARNING);
    }

    $shortliststatus = optional_param('shortliststatus', '', PARAM_ALPHANUMEXT);
    if (!isset($shortlistoptions[$shortliststatus])) {
        redirect($baseurl, get_string('error:invalidshortliststatus', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
    }

    $shortlistnote = trim(optional_param('shortlistnote', '', PARAM_TEXT));
    if ($shortlistnote === '') {
        redirect($baseurl, get_string('error:bulknoterequired', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
    }
    $applicationsforupdate = $DB->get_records_list('local_jobportal_applications', 'id', $appids);
    $updatedcount = 0;

    foreach ($applicationsforupdate as $application) {
        if ((int)$application->jobid !== (int)$jobid) {
            continue;
        }

        $oldshortliststatus = local_jobportal_get_application_shortlist_status($application);
        if (!local_jobportal_is_shortlist_transition_allowed($oldshortliststatus, $shortliststatus)) {
            $a = (object)array(
                'from' => local_jobportal_get_shortlist_status_options()[$oldshortliststatus],
                'to' => local_jobportal_get_shortlist_status_options()[$shortliststatus],
            );
            redirect($baseurl, get_string('error:invalidshortlisttransition', 'local_jobportal', $a), null, \core\output\notification::NOTIFY_ERROR);
        }
    }

    foreach ($applicationsforupdate as $application) {
        if ((int)$application->jobid !== (int)$jobid) {
            continue;
        }

        local_jobportal_apply_shortlist_change($application, $shortliststatus, $shortliststages, $shortlistnote, $USER->id);
        $updatedcount++;
    }

    if ($updatedcount === 0) {
        redirect($baseurl, get_string('error:noapplicationsselected', 'local_jobportal'), null, \core\output\notification::NOTIFY_WARNING);
    }

    redirect($baseurl, get_string('bulkshortliststatusupdated', 'local_jobportal', $updatedcount), null, \core\output\notification::NOTIFY_SUCCESS);
}

if (($action === 'bulkchangepoststage' || $action === 'bulkchangestage') && confirm_sesskey()) {
    if (empty($appids)) {
        redirect($baseurl, get_string('error:noapplicationsselected', 'local_jobportal'), null, \core\output\notification::NOTIFY_WARNING);
    }

    $stageid = optional_param('stageid', 0, PARAM_INT);
    if (empty($stageid) || !isset($poststages[$stageid])) {
        redirect($baseurl, get_string('error:invalidstage', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
    }

    $stage = $poststages[$stageid];
    $scheduleinput = local_jobportal_parse_schedule_inputs($stage, $baseurl);
    $stagenote = trim(optional_param('stagenote', '', PARAM_TEXT));
    if ($stagenote === '') {
        redirect($baseurl, get_string('error:bulknoterequired', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
    }
    $applicationsforupdate = $DB->get_records_list('local_jobportal_applications', 'id', $appids);
    $eligibleapplications = array();

    foreach ($applicationsforupdate as $application) {
        if ((int)$application->jobid !== (int)$jobid) {
            continue;
        }
        if (local_jobportal_get_application_shortlist_status($application) !== 'shortlisted') {
            continue;
        }

        $currentstage = local_jobportal_get_application_stage($application, $stages);
        $currentstageshortname = $currentstage ? $currentstage->shortname : '';
        if (!local_jobportal_is_post_stage_transition_allowed($currentstageshortname, $stage->shortname, false)) {
            $a = (object)array(
                'from' => $currentstage ? format_string($currentstage->displayname) : get_string('poststagenotset', 'local_jobportal'),
                'to' => format_string($stage->displayname),
            );
            redirect($baseurl, get_string('error:invalidstagetransition', 'local_jobportal', $a), null, \core\output\notification::NOTIFY_ERROR);
        }

        local_jobportal_validate_schedule_meta_for_stage(
            $application,
            $stage,
            $scheduleinput['scheduledat'],
            $scheduleinput['schedulemeta'],
            $baseurl
        );
        $eligibleapplications[] = $application;
    }

    $updatedcount = 0;
    foreach ($eligibleapplications as $application) {
        local_jobportal_apply_post_stage_change_with_schedule(
            $application,
            $stage,
            $scheduleinput['scheduledat'],
            $stagenote,
            $USER->id,
            $scheduleinput['schedulemeta']
        );
        $updatedcount++;
    }

    if ($updatedcount === 0) {
        redirect($baseurl, get_string('error:noshortlistedselected', 'local_jobportal'), null, \core\output\notification::NOTIFY_WARNING);
    }

    redirect($baseurl, get_string('bulkpoststageupdated', 'local_jobportal', $updatedcount), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'bulkupdateroundevent' && confirm_sesskey()) {
    if (empty($appids)) {
        redirect($baseurl, get_string('error:noapplicationsselected', 'local_jobportal'), null, \core\output\notification::NOTIFY_WARNING);
    }

    $stageid = optional_param('round_stageid', 0, PARAM_INT);
    if (empty($stageid) || !isset($poststages[$stageid]) || empty($poststages[$stageid]->hasscheduledate)) {
        redirect($baseurl, get_string('error:invalidstage', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
    }
    $stage = $poststages[$stageid];
    $scheduleinput = local_jobportal_parse_schedule_inputs($stage, $baseurl, 'round_');
    $roundnote = trim(optional_param('round_roundnote', '', PARAM_TEXT));

    $applicationsforupdate = $DB->get_records_list('local_jobportal_applications', 'id', $appids);
    $updatedcount = 0;
    foreach ($applicationsforupdate as $application) {
        if ((int)$application->jobid !== (int)$jobid) {
            continue;
        }
        if (local_jobportal_get_application_shortlist_status($application) !== 'shortlisted') {
            continue;
        }

        $roundevent = $DB->get_record_sql(
            "SELECT e.*, s.shortname, s.displayname, s.hasscheduledate
               FROM {local_jobportal_appstage_events} e
               JOIN {local_jobportal_stages} s ON s.id = e.stageid
              WHERE e.applicationid = :applicationid
                AND e.stageid = :stageid
           ORDER BY e.timecreated DESC, e.id DESC",
            array(
                'applicationid' => (int)$application->id,
                'stageid' => (int)$stageid,
            ),
            IGNORE_MISSING
        );
        if (!$roundevent) {
            continue;
        }

        local_jobportal_validate_schedule_meta_for_stage(
            $application,
            $stage,
            $scheduleinput['scheduledat'],
            $scheduleinput['schedulemeta'],
            $baseurl,
            $roundevent
        );
        local_jobportal_update_existing_round_event(
            $application,
            $roundevent,
            $stage,
            $scheduleinput,
            $roundnote,
            $USER->id
        );
        $updatedcount++;
    }

    if ($updatedcount === 0) {
        redirect($baseurl, get_string('error:noroundeventsselected', 'local_jobportal'), null, \core\output\notification::NOTIFY_WARNING);
    }

    redirect($baseurl, get_string('bulkroundeventupdated', 'local_jobportal', $updatedcount), null, \core\output\notification::NOTIFY_SUCCESS);
}

$where = array('a.jobid = :jobid');
$sqlparams = array('jobid' => $jobid);
$now = time();
$lastactivityexpr = "GREATEST(
    COALESCE(a.timemodified, 0),
    COALESCE((SELECT MAX(e.timecreated) FROM {local_jobportal_appstage_events} e WHERE e.applicationid = a.id), 0),
    COALESCE((SELECT MAX(n.timecreated) FROM {local_jobportal_appnotes} n WHERE n.applicationid = a.id), 0)
)";

if ($appsearch !== '') {
    $searchparam = '%' . $DB->sql_like_escape(core_text::strtolower($appsearch)) . '%';
    $searchwheres = array();
    $searchcolumns = array(
        'firstname' => 'LOWER(u.firstname)',
        'lastname' => 'LOWER(u.lastname)',
        'email' => 'LOWER(u.email)',
        'phone1' => 'LOWER(u.phone1)',
        'phone2' => 'LOWER(u.phone2)',
    );
    foreach ($searchcolumns as $suffix => $columnsql) {
        $paramname = 'appsearch' . $suffix;
        $sqlparams[$paramname] = $searchparam;
        $searchwheres[] = $DB->sql_like($columnsql, ':' . $paramname, false);
    }
    $where[] = '(' . implode(' OR ', $searchwheres) . ')';
}

if (!empty($showapp)) {
    $sqlparams['showapp'] = (int)$showapp;
    $where[] = 'a.id = :showapp';
}

if ($filtershortlist !== 'all') {
    $sqlparams['filtershortlist'] = $filtershortlist;
    $where[] = 'a.shortliststatus = :filtershortlist';
}

if (empty($showapp) && $filterpoststage === 'notset') {
    $where[] = 'a.currentstageid IS NULL';
} else if (empty($showapp) && $filterpoststage !== 'all') {
    $sqlparams['filterpoststage'] = (int)$filterpoststage;
    $where[] = 'a.currentstageid = :filterpoststage';
}

if (empty($showapp) && $filterresumestatus !== 'all') {
    $sqlparams['filterresumestatus'] = $filterresumestatus;
    $where[] = "COALESCE(NULLIF(p.resumestatus, ''), 'notsubmitted') = :filterresumestatus";
}

if (empty($showapp) && $filterhasresume !== 'all') {
    $sqlparams['resumecontextid'] = (int)$context->id;
    $sqlparams['resumecomponent'] = 'local_jobportal';
    $sqlparams['resumefilearea'] = 'profile_resume';
    $resumeexistssql = "EXISTS (
            SELECT 1
              FROM {files} fr
             WHERE fr.contextid = :resumecontextid
               AND fr.component = :resumecomponent
               AND fr.filearea = :resumefilearea
               AND fr.itemid = p.id
               AND fr.filename <> '.'
        )";
    if ($filterhasresume === 'yes') {
        $where[] = 'p.id IS NOT NULL AND ' . $resumeexistssql;
    } else {
        $where[] = '(p.id IS NULL OR NOT ' . $resumeexistssql . ')';
    }
}

if (empty($showapp) && $filterappliedfrom !== null) {
    $sqlparams['filterappliedfrom'] = (int)$filterappliedfrom;
    $where[] = 'a.timecreated >= :filterappliedfrom';
}
if (empty($showapp) && $filterappliedto !== null) {
    $sqlparams['filterappliedto'] = (int)$filterappliedto;
    $where[] = 'a.timecreated <= :filterappliedto';
}
if (empty($showapp) && $filterinactivefordays !== null) {
    $sqlparams['filterinactivebefore'] = $now - ($filterinactivefordays * DAYSECS);
    $where[] = $lastactivityexpr . ' <= :filterinactivebefore';
}

$sortsql = 'a.timecreated ' . $appsortdir . ', a.id DESC';
if ($appsort === 'name') {
    $sortsql = 'u.firstname ' . $appsortdir . ', u.lastname ' . $appsortdir . ', a.timecreated DESC';
} else if ($appsort === 'shortliststatus') {
    $sortsql = "COALESCE(NULLIF(a.shortliststatus, ''), 'pending') " . $appsortdir . ', a.timecreated DESC';
} else if ($appsort === 'poststage') {
    $sortsql = 'CASE WHEN s.sortorder IS NULL THEN 1 ELSE 0 END ASC, s.sortorder ' . $appsortdir . ', a.timecreated DESC';
} else if ($appsort === 'resumestatus') {
    $sortsql = "COALESCE(NULLIF(p.resumestatus, ''), 'notsubmitted') " . $appsortdir . ', a.timecreated DESC';
} else if ($appsort === 'resumerating') {
    $sortsql = 'p.resumerating ' . $appsortdir . ', a.timecreated DESC';
} else if ($appsort === 'lastactivity') {
    $sortsql = $lastactivityexpr . ' ' . $appsortdir . ', a.timecreated DESC';
}

$wheresql = implode(' AND ', $where);
$fromsql = "FROM {local_jobportal_applications} a
      JOIN {user} u ON a.userid = u.id
 LEFT JOIN {local_jobportal_profiles} p ON p.userid = u.id
 LEFT JOIN {local_jobportal_stages} s ON s.id = a.currentstageid";

$sql = "SELECT a.*, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
               u.email, u.phone1, u.phone2, u.city,
               p.id AS profileid, p.skills, p.experience, p.education, p.portfolio, p.timemodified AS profiletimemodified,
               p.resumestatus, p.resumerating, p.resumefeedback, p.resumereviewedby, p.resumereviewedat,
               {$lastactivityexpr} AS lastactivityat,
               (SELECT COUNT(a2.id) FROM {local_jobportal_applications} a2 WHERE a2.userid = a.userid) AS totalapplications
          $fromsql
         WHERE $wheresql
      ORDER BY $sortsql";

$countsql = "SELECT COUNT(1)
               $fromsql
              WHERE $wheresql";
$filteredapplicationscount = (int)$DB->count_records_sql($countsql, $sqlparams);
$jobapplicationscount = (int)$DB->count_records('local_jobportal_applications', array('jobid' => $jobid));
if ($export === 'xls') {
    $applications = $DB->get_records_sql($sql, $sqlparams);
} else {
    $applications = $DB->get_records_sql($sql, $sqlparams, $page * $perpage, $perpage);
}

$eventsbyapp = array();
$notesbyapp = array();
$resumehistorybyprofile = array();
if (!empty($applications)) {
    $appids = array_keys($applications);
    list($insql, $inparams) = $DB->get_in_or_equal($appids, SQL_PARAMS_NAMED);

    $eventsql = "SELECT e.*, s.shortname, s.displayname, s.isinternal, s.hasscheduledate,
                        u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
                   FROM {local_jobportal_appstage_events} e
                   JOIN {local_jobportal_stages} s ON s.id = e.stageid
                   JOIN {user} u ON u.id = e.changedby
                  WHERE e.applicationid $insql
               ORDER BY e.timecreated ASC, e.id ASC";
    $events = $DB->get_records_sql($eventsql, $inparams);
    foreach ($events as $event) {
        if (!isset($eventsbyapp[$event->applicationid])) {
            $eventsbyapp[$event->applicationid] = array();
        }
        $eventsbyapp[$event->applicationid][] = $event;
    }

    $notesql = "SELECT n.*, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
                  FROM {local_jobportal_appnotes} n
                  JOIN {user} u ON u.id = n.userid
                 WHERE n.applicationid $insql
              ORDER BY n.timecreated DESC";
    $notes = $DB->get_records_sql($notesql, $inparams);
    foreach ($notes as $note) {
        if (!isset($notesbyapp[$note->applicationid])) {
            $notesbyapp[$note->applicationid] = array();
        }
        $notesbyapp[$note->applicationid][] = $note;
    }

    $profileids = array();
    foreach ($applications as $app) {
        if (!empty($app->profileid)) {
            $profileids[(int)$app->profileid] = (int)$app->profileid;
        }
    }
    if (!empty($profileids)) {
        list($profileinsql, $profileparams) = $DB->get_in_or_equal(array_values($profileids), SQL_PARAMS_NAMED);
        $historysql = "SELECT h.*, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
                         FROM {local_jobportal_resume_review_hist} h
                         JOIN {user} u ON u.id = h.userid
                        WHERE h.profileid $profileinsql
                     ORDER BY h.timecreated DESC";
        $historyevents = $DB->get_records_sql($historysql, $profileparams);
        foreach ($historyevents as $historyevent) {
            $profileid = (int)$historyevent->profileid;
            if (!isset($resumehistorybyprofile[$profileid])) {
                $resumehistorybyprofile[$profileid] = array();
            }
            $resumehistorybyprofile[$profileid][] = $historyevent;
        }
    }
}

if ($export === 'xls') {
    require_once($CFG->libdir . '/excellib.class.php');

    $filename = clean_filename('job-applicants-' . $jobid . '-' . gmdate('Ymd-His') . '.xls');
    $workbook = new MoodleExcelWorkbook('-');
    $workbook->send($filename);
    $sheet = $workbook->add_worksheet('Applicants');
    $headerformat = $workbook->add_format();
    $headerformat->set_bold(1);

    $headers = array(
        get_string('applicantname', 'local_jobportal'),
        get_string('email'),
        get_string('shortliststatus', 'local_jobportal'),
        get_string('postshortliststage', 'local_jobportal'),
        get_string('appliedon', 'local_jobportal'),
        get_string('lastactivity', 'local_jobportal'),
        get_string('resumelink', 'local_jobportal'),
        get_string('resumerating', 'local_jobportal'),
        get_string('skills', 'local_jobportal'),
        get_string('experience', 'local_jobportal'),
        get_string('education', 'local_jobportal'),
        get_string('portfolio', 'local_jobportal'),
        get_string('applicantphone', 'local_jobportal'),
        get_string('applicantcity', 'local_jobportal'),
        get_string('profileupdatedon', 'local_jobportal'),
        get_string('totalapplications', 'local_jobportal'),
        get_string('stagetimeline', 'local_jobportal'),
        get_string('recruiternotes', 'local_jobportal'),
    );
    foreach ($headers as $index => $header) {
        $sheet->write_string(0, $index, $header, $headerformat);
    }

    $row = 1;
    foreach ($applications as $app) {
        $shortliststatus = local_jobportal_get_application_shortlist_status($app);
        $shortlistlabel = isset($shortlistoptions[$shortliststatus]) ?
            $shortlistoptions[$shortliststatus] : get_string('pending', 'local_jobportal');

        $stage = local_jobportal_get_application_stage($app, $stages);
        $poststagename = get_string('poststagenotset', 'local_jobportal');
        if ($shortliststatus !== 'shortlisted') {
            $poststagename = '-';
        } else if ($stage) {
            $poststagename = format_string($stage->displayname);
            if (!empty($stage->isinternal)) {
                $poststagename .= ' (' . get_string('internalstage', 'local_jobportal') . ')';
            }
        }

        $resumeurl = '';
        if (!empty($app->profileid)) {
            $url = local_jobportal_get_profile_resume_url((int)$app->profileid, $context, true);
            if ($url) {
                $resumeurl = $url->out(false);
            }
        }

        $timeline = array();
        if (!empty($eventsbyapp[$app->id])) {
            $stageeventcounts = array();
            foreach ($eventsbyapp[$app->id] as $event) {
                $line = userdate($event->timecreated, $datetimeformat) . ' - ' . $event->displayname;
                $eventstageid = (int)$event->stageid;
                $isschedulableevent = !empty($event->hasscheduledate) ||
                    (isset($poststages[$eventstageid]) && !empty($poststages[$eventstageid]->hasscheduledate));
                if ($isschedulableevent) {
                    if (!isset($stageeventcounts[$eventstageid])) {
                        $stageeventcounts[$eventstageid] = 0;
                    }
                    $stageeventcounts[$eventstageid]++;
                    $line .= ' - ' . get_string('roundlabel', 'local_jobportal', $stageeventcounts[$eventstageid]);
                }
                if (!empty($event->isinternal)) {
                    $line .= ' (' . get_string('internalstage', 'local_jobportal') . ')';
                }
                if ($isschedulableevent) {
                    if (!empty($event->scheduledat)) {
                        $line .= ' (' . get_string('scheduledfor', 'local_jobportal') . ': ' .
                            userdate($event->scheduledat, $datetimeformat) . ')';
                    }
                    if (!empty($event->schedulestatus)) {
                        $line .= ' (' . get_string('schedulestatusvalue', 'local_jobportal',
                            local_jobportal_get_schedule_status_label($event->schedulestatus)) . ')';
                    }
                    $eventoutcome = !empty($event->roundoutcome) ? local_jobportal_normalize_round_outcome($event->roundoutcome) : 'pending';
                    $eventstatus = !empty($event->schedulestatus) ? local_jobportal_normalize_schedule_status($event->schedulestatus) : 'scheduled';
                    if ($eventstatus === 'completed' || $eventoutcome !== 'pending') {
                        $line .= ' (' . get_string('roundoutcomevalue', 'local_jobportal',
                            local_jobportal_get_round_outcome_label($eventoutcome)) . ')';
                    }
                    if (!empty($event->schedulemode)) {
                        $line .= ' (' . get_string('schedulemodevalue', 'local_jobportal',
                            local_jobportal_get_schedule_mode_label($event->schedulemode)) . ')';
                    }
                    if (!empty($event->scheduleduration)) {
                        $line .= ' (' . get_string('scheduledurationvalue', 'local_jobportal', (int)$event->scheduleduration) . ')';
                    }
                    if (!empty($event->schedulevenue)) {
                        $line .= ' (' . get_string('schedulevenuevalue', 'local_jobportal', trim((string)$event->schedulevenue)) . ')';
                    }
                    if (!empty($event->schedulelink)) {
                        $line .= ' (' . get_string('schedulelinkvalue', 'local_jobportal', trim((string)$event->schedulelink)) . ')';
                    }
                }
                if (!empty($event->notes)) {
                    $line .= ' - ' . $event->notes;
                }
                $timeline[] = $line;
            }
        }

        $notelines = array();
        if (!empty($notesbyapp[$app->id])) {
            foreach ($notesbyapp[$app->id] as $note) {
                $notelines[] = userdate($note->timecreated, $datetimeformat) . ' - ' . fullname($note) . ': ' . $note->note;
            }
        }

        $phone = !empty($app->phone1) ? $app->phone1 : $app->phone2;
        $values = array(
            fullname($app),
            $app->email,
            $shortlistlabel,
            $poststagename,
            userdate($app->timecreated, $datetimesecondsformat),
            !empty($app->lastactivityat) ? userdate((int)$app->lastactivityat, $datetimesecondsformat) : '',
            $resumeurl,
            !empty($app->resumerating) ? ((int)$app->resumerating . '/5') : '',
            trim((string)$app->skills),
            trim((string)$app->experience),
            trim((string)$app->education),
            trim((string)$app->portfolio),
            trim((string)$phone),
            trim((string)$app->city),
            !empty($app->profiletimemodified) ? userdate($app->profiletimemodified, $datetimesecondsformat) : '',
            (int)$app->totalapplications,
            implode(' | ', $timeline),
            implode(' | ', $notelines),
        );

        foreach ($values as $index => $value) {
            $sheet->write_string($row, $index, (string)$value);
        }
        $row++;
    }

    $workbook->close();
    exit;
}

echo $OUTPUT->header();
echo local_jobportal_render_navigation(
    $context,
    'applications',
    array(
        array(
            'key' => 'view',
            'label' => get_string('viewjob', 'local_jobportal'),
            'url' => new moodle_url('/local/jobportal/view.php', array('id' => $job->id)),
        ),
        array(
            'key' => 'applications',
            'label' => get_string('viewapplications', 'local_jobportal'),
            'url' => new moodle_url('/local/jobportal/applications.php', array('jobid' => $jobid)),
        ),
    )
);

// Enhanced Job Header
echo html_writer::start_div('jp-header-card');
echo html_writer::start_div('jp-header-main');
echo html_writer::start_div('jp-header-main-left');
echo html_writer::start_div('jp-header-brand');
if ($headercompanylogo) {
    echo html_writer::empty_tag('img', array(
        'src' => $headercompanylogo->out(false),
        'alt' => $headercompanyname,
        'class' => 'jp-header-company-logo',
        'loading' => 'lazy',
    ));
} else {
    echo html_writer::tag('div', s($companyinitials), array('class' => 'jp-header-company-fallback'));
}
echo html_writer::start_div('jp-header-brand-text');
echo html_writer::tag('h3', format_string($job->title));
echo html_writer::tag('h5', $headercompanyname, array('class' => 'mt-2 mb-0'));
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('jp-header-main-right');
echo html_writer::tag('div', get_string('jobid', 'local_jobportal') . ': ' . (int)$job->id, array('class' => 'jp-header-meta-item'));
echo html_writer::tag(
    'div',
    get_string('joblistedon', 'local_jobportal') . ': ' . userdate((int)$job->timecreated, $dateformat),
    array('class' => 'jp-header-meta-item')
);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

if (!empty($showapp)) {
    $backtolistparams = array_merge(array('jobid' => $jobid), $appfilterparams);
    if (!empty($page)) {
        $backtolistparams['page'] = $page;
    }
    echo html_writer::div(
        html_writer::link(
            new moodle_url('/local/jobportal/applications.php', $backtolistparams),
            get_string('backtoapplicationslist', 'local_jobportal'),
            array('class' => 'btn btn-outline-secondary btn-sm')
        ),
        'mb-3'
    );
}

if (empty($showapp)) {
    // Statistics cards are list-level aggregates, not single-application context.
    $shortlistedcount = $DB->count_records('local_jobportal_applications', array('jobid' => $jobid, 'shortliststatus' => 'shortlisted'));
    $pendingcount = $DB->count_records('local_jobportal_applications', array('jobid' => $jobid, 'shortliststatus' => 'pending'));
    $internalshortlistedcount = $DB->count_records(
        'local_jobportal_applications',
        array('jobid' => $jobid, 'shortliststatus' => 'internalshortlisted')
    );
    $notshortlistedcount = $DB->count_records('local_jobportal_applications', array('jobid' => $jobid, 'shortliststatus' => 'notshortlisted'));
    $offermadecount = $DB->count_records(
        'local_jobportal_applications',
        array('jobid' => $jobid, 'shortliststatus' => 'shortlisted', 'status' => 'offermade')
    );
    $offeracceptedcount = $DB->count_records(
        'local_jobportal_applications',
        array('jobid' => $jobid, 'shortliststatus' => 'shortlisted', 'status' => 'accepted')
    );
    $offerrejectedcount = $DB->count_records(
        'local_jobportal_applications',
        array('jobid' => $jobid, 'shortliststatus' => 'shortlisted', 'status' => 'rejected')
    );
    $conversionbase = $offermadecount + $offeracceptedcount + $offerrejectedcount;
    $conversionpercent = $jobapplicationscount > 0 ?
        format_float(($conversionbase / $jobapplicationscount) * 100, 1) . '%' : '0%';

    $statcards = array(
        array('value' => $jobapplicationscount, 'label' => get_string('totalapplications', 'local_jobportal')),
        array('value' => $shortlistedcount, 'label' => get_string('shortlisted', 'local_jobportal')),
    );
    if ($pendingcount > 0) {
        $statcards[] = array('value' => $pendingcount, 'label' => get_string('pending', 'local_jobportal'));
    }
    if ($internalshortlistedcount > 0) {
        $statcards[] = array('value' => $internalshortlistedcount, 'label' => get_string('internalshortlisted', 'local_jobportal'));
    }
    if ($notshortlistedcount > 0) {
        $statcards[] = array('value' => $notshortlistedcount, 'label' => get_string('notshortlisted', 'local_jobportal'));
    }
    if ($offermadecount > 0) {
        $statcards[] = array('value' => $offermadecount, 'label' => get_string('offermade', 'local_jobportal'));
    }
    if ($offeracceptedcount > 0) {
        $statcards[] = array('value' => $offeracceptedcount, 'label' => get_string('accepted', 'local_jobportal'));
    }
    if ($offerrejectedcount > 0) {
        $statcards[] = array('value' => $offerrejectedcount, 'label' => get_string('rejected', 'local_jobportal'));
    }
    $statcards[] = array('value' => $conversionpercent, 'label' => get_string('offerconversion', 'local_jobportal'));

    echo html_writer::start_div('jp-stat-cards');
    foreach ($statcards as $statcard) {
        echo html_writer::start_div('jp-stat-card');
        echo html_writer::div($statcard['value'], 'jp-stat-value');
        echo html_writer::div($statcard['label'], 'jp-stat-label');
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
}

if (empty($applications)) {
    $emptystring = $hasappfilters ? get_string('noapplicationsmatchfilters', 'local_jobportal') : get_string('noapplicationsyet', 'local_jobportal');
    echo html_writer::tag('p', $emptystring, array('class' => 'alert alert-info'));
    if ($hasappfilters) {
        $resetfilterurl = new moodle_url('/local/jobportal/applications.php', array('jobid' => $jobid));
        echo html_writer::div(
            html_writer::link($resetfilterurl, get_string('resetfilters', 'local_jobportal'), array('class' => 'btn btn-outline-secondary btn-sm')),
            'mb-3'
        );
    }
} else {
    $pagingurl = new moodle_url('/local/jobportal/applications.php', array_merge(array('jobid' => $jobid), $appfilterparams));

    if (empty($showapp)) {
        echo html_writer::start_div('jp-actions-bar');
        echo html_writer::tag('strong', get_string('actions', 'local_jobportal') . ':', array('class' => 'mr-3'));
        echo html_writer::link(
            new moodle_url('/local/jobportal/applications.php', array_merge(array('jobid' => $jobid, 'export' => 'xls'), $appfilterparams)),
            '📊 ' . get_string('exportfilteredxls', 'local_jobportal'),
            array('class' => 'btn btn-outline-primary btn-sm mr-2')
        );
        echo html_writer::link(
            new moodle_url('/local/jobportal/applications.php', array('jobid' => $jobid, 'export' => 'xls')),
            '📁 ' . get_string('exportallxls', 'local_jobportal'),
            array('class' => 'btn btn-outline-secondary btn-sm')
        );
        echo html_writer::end_div();
    }

    echo html_writer::start_div('jp-resume-preview-panel card mb-3 d-none w-100', array('id' => 'jp-resume-preview-panel'));
    echo html_writer::start_div('card-body');
    echo html_writer::start_div('d-flex justify-content-between align-items-center mb-2');
    echo html_writer::tag('h5', get_string('previewresume', 'local_jobportal'), array('class' => 'mb-0'));
    echo html_writer::tag(
        'button',
        get_string('close', 'local_jobportal'),
        array('type' => 'button', 'id' => 'jp-resume-preview-close', 'class' => 'btn btn-sm btn-outline-secondary')
    );
    echo html_writer::end_div();
    echo html_writer::tag('iframe', '', array(
        'id' => 'jp-resume-preview-frame',
        'class' => 'jp-resume-preview-frame',
        'src' => 'about:blank',
        'title' => get_string('previewresume', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::end_div();

    if (empty($showapp) && $filteredapplicationscount > $perpage) {
        echo $OUTPUT->paging_bar($filteredapplicationscount, $page, $perpage, $pagingurl);
    }

    $PAGE->requires->js_call_amd('local_jobportal/applications_ui', 'init', array($stageschedulablemap));

    if (empty($showapp)) {
        echo html_writer::start_tag('div', array('class' => 'jp-bulk-section'));
        echo html_writer::start_tag('div', array('class' => ''));
        echo html_writer::tag('h5', get_string('bulkupdates', 'local_jobportal'));
        echo html_writer::tag('p', get_string('bulkupdatesdesc', 'local_jobportal'), array('class' => 'text-muted mb-4'));

    $resetfilterurl = new moodle_url('/local/jobportal/applications.php', array('jobid' => $jobid));
    
    echo html_writer::start_tag('div', array('class' => 'card jp-form-section border-0 shadow-sm mb-4 jp-filter-card', 'id' => 'jp-apps-filter-card'));
    echo html_writer::start_tag('div', array('class' => 'card-body p-4'));
    echo html_writer::start_div('d-flex justify-content-between align-items-center mb-3');
    echo html_writer::start_div('d-flex align-items-center gap-2');
    echo html_writer::tag('h5', '🔍 ' . get_string('applicantfilters', 'local_jobportal'), array('class' => 'card-title font-weight-bold mb-0'));
    if ($activeappfilterscount > 0) {
        echo html_writer::tag('span', get_string('filtersapplied', 'local_jobportal', $activeappfilterscount), array('class' => 'badge badge-primary ml-2 jp-filter-active-count'));
    }
    echo html_writer::end_div();
    echo html_writer::tag(
        'button',
        '👁️ ' . get_string('hidefilters', 'local_jobportal'),
        array(
            'type' => 'button',
            'class' => 'btn btn-sm btn-outline-secondary jp-toggle-filters-btn',
            'data-target' => '#jp-apps-filter-content-wrap',
            'data-storage-key' => 'jp_apps_filters_hidden',
            'data-show-text' => '👁️ ' . get_string('showfilters', 'local_jobportal'),
            'data-hide-text' => '👁️ ' . get_string('hidefilters', 'local_jobportal'),
            'aria-expanded' => 'true',
        )
    );
    echo html_writer::end_div();

    echo html_writer::start_div('jp-filter-content-wrap', array('id' => 'jp-apps-filter-content-wrap'));

    echo html_writer::start_tag('form', array('method' => 'get', 'action' => $pageactionurl, 'class' => 'mb-0'));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'jobid', 'value' => $jobid));
    
    echo html_writer::start_div('row mb-2');
    echo html_writer::start_div('col-md-3 mb-2');
    echo html_writer::empty_tag('input', array(
        'type' => 'text',
        'name' => 'appsearch',
        'value' => $appsearch,
        'class' => 'form-control bg-light border-0',
        'placeholder' => get_string('search'),
    ));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-2 mb-2');
    echo html_writer::select($shortlistfilteroptions, 'filtershortlist', $filtershortlist, false, array('class' => 'custom-select bg-light border-0 w-100'));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-2 mb-2');
    echo html_writer::select($stagefilteroptions, 'filterpoststage', $filterpoststage, false, array('class' => 'custom-select bg-light border-0 w-100'));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-2 mb-2');
    echo html_writer::select($resumestatusfilteroptions, 'filterresumestatus', $filterresumestatus, false, array('class' => 'custom-select bg-light border-0 w-100'));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-3 mb-2');
    echo html_writer::select($hasresumefilteroptions, 'filterhasresume', $filterhasresume, false, array('class' => 'custom-select bg-light border-0 w-100'));
    echo html_writer::end_div();
    echo html_writer::end_div();
    
    echo html_writer::start_div('row mb-3');
    echo html_writer::start_div('col-md-3 mb-2');
    echo html_writer::empty_tag('input', array(
        'type' => 'date',
        'name' => 'filterappliedfrom',
        'value' => $filterappliedfromraw,
        'class' => 'form-control bg-light border-0',
        'title' => get_string('appliedfromfilter', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-3 mb-2');
    echo html_writer::empty_tag('input', array(
        'type' => 'date',
        'name' => 'filterappliedto',
        'value' => $filterappliedtoraw,
        'class' => 'form-control bg-light border-0',
        'title' => get_string('appliedtofilter', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-2 mb-2');
    echo html_writer::empty_tag('input', array(
        'type' => 'number',
        'name' => 'filterinactivefordays',
        'value' => $filterinactivefordaysraw,
        'class' => 'form-control bg-light border-0',
        'min' => 1,
        'placeholder' => get_string('noactivitydaysfilter', 'local_jobportal'),
        'title' => get_string('noactivitydaysfilter', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-2 mb-2');
    echo html_writer::select($appsortoptions, 'appsort', $appsort, false, array('class' => 'custom-select bg-light border-0 w-100'));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-2 mb-2');
    echo html_writer::select($appsortdiroptions, 'appsortdir', $appsortdir, false, array('class' => 'custom-select bg-light border-0 w-100'));
    echo html_writer::end_div();
    echo html_writer::end_div();
    
    echo html_writer::start_div('d-flex justify-content-between align-items-center');
    echo html_writer::div(get_string('applicantsshowncount', 'local_jobportal', $filteredapplicationscount), 'text-muted small font-weight-bold');
    echo html_writer::start_div('d-flex gap-2');
    echo html_writer::tag('button', get_string('apply'), array('type' => 'submit', 'class' => 'btn btn-primary px-4 jp-action-pill'));
    echo html_writer::link($resetfilterurl, '✖', array('class' => 'btn btn-outline-secondary jp-action-pill', 'title' => get_string('resetfilters', 'local_jobportal')));
    echo html_writer::end_div();
    echo html_writer::end_div();
    
    echo html_writer::end_tag('form');
    echo html_writer::end_div(); // end jp-apps-filter-content-wrap
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');

    echo html_writer::start_tag('form', array('method' => 'post', 'action' => $pageactionurl));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'jobid', 'value' => $jobid));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'page', 'value' => $page));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'showapp', 'value' => $showapp));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'standalone', 'value' => $standalonevalue));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
    foreach ($appfilterparams as $filtername => $filtervalue) {
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => $filtername, 'value' => $filtervalue));
    }

    echo html_writer::start_tag('div', array('class' => 'table-responsive'));
    echo html_writer::start_tag('table', array('class' => 'table table-sm table-striped table-bordered jp-table jp-data-table jp-applicants-table'));
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag(
        'th',
        html_writer::checkbox('selectall', 1, false, '', array('id' => 'jp-select-all')),
        array('class' => 'jp-col-select text-center')
    );
    echo html_writer::tag('th', get_string('applicantname', 'local_jobportal'), array('class' => 'jp-col-name'));
    echo html_writer::tag('th', get_string('email'), array('class' => 'jp-col-email'));
    echo html_writer::tag('th', get_string('applicantphone', 'local_jobportal'), array('class' => 'jp-col-phone'));
    echo html_writer::tag('th', get_string('applicantcity', 'local_jobportal'), array('class' => 'jp-col-city'));
    echo html_writer::tag('th', get_string('shortliststatus', 'local_jobportal'), array('class' => 'jp-col-shortlist'));
    echo html_writer::tag('th', get_string('postshortliststage', 'local_jobportal'), array('class' => 'jp-col-poststage'));
    echo html_writer::tag('th', get_string('resumereviewstatus', 'local_jobportal'), array('class' => 'jp-col-resumestatus'));
    echo html_writer::tag('th', get_string('resumerating', 'local_jobportal'), array('class' => 'jp-col-rating text-center'));
    echo html_writer::tag('th', get_string('appliedon', 'local_jobportal'), array('class' => 'jp-col-applied text-center'));
    echo html_writer::tag('th', get_string('lastactivity', 'local_jobportal'), array('class' => 'jp-col-lastactivity text-center'));
    echo html_writer::tag('th', get_string('resumelink', 'local_jobportal'), array('class' => 'jp-col-resume text-center'));
    echo html_writer::tag('th', get_string('actions'), array('class' => 'jp-col-actions text-center'));
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($applications as $app) {
        $shortliststatus = local_jobportal_get_application_shortlist_status($app);
        $shortlistlabel = isset($shortlistoptions[$shortliststatus]) ?
            $shortlistoptions[$shortliststatus] : get_string('pending', 'local_jobportal');

        $stage = local_jobportal_get_application_stage($app, $stages);
        $poststagename = '-';
        if ($shortliststatus === 'shortlisted') {
            $poststagename = get_string('poststagenotset', 'local_jobportal');
            if ($stage) {
                $poststagename = format_string($stage->displayname);
                if (!empty($stage->isinternal)) {
                    $poststagename .= ' (' . get_string('internalstage', 'local_jobportal') . ')';
                }
            }
        }
        $resumestatus = !empty($app->resumestatus) ? local_jobportal_normalize_resume_status($app->resumestatus) : 'notsubmitted';
        $resumestatuslabel = isset($resumestatusoptions[$resumestatus]) ? $resumestatusoptions[$resumestatus] : $resumestatus;
        $resumestatusbadge = local_jobportal_resume_status_badge_class($resumestatus);
        $resumeratinglabel = !empty($app->resumerating) ? ((int)$app->resumerating . '/5') : '-';
        $lastactivitylabel = !empty($app->lastactivityat) ? userdate((int)$app->lastactivityat, $datetimeformat) : '-';

        $resumecontent = get_string('resumenotuploaded', 'local_jobportal');
        if (!empty($app->profileid)) {
            $resumefile = local_jobportal_get_profile_resume_file((int)$app->profileid, $context);
            $resumedownloadurl = local_jobportal_get_profile_resume_url((int)$app->profileid, $context, true);
            if ($resumefile && $resumedownloadurl) {
                $resumelinks = array();
                if (local_jobportal_resume_file_is_previewable($resumefile)) {
                    $resumepreviewurl = local_jobportal_get_profile_resume_url((int)$app->profileid, $context, false);
                    if ($resumepreviewurl) {
                        $resumelinks[] = html_writer::tag(
                            'button',
                            '👁️',
                            array(
                                'type' => 'button',
                                'class' => 'btn btn-link jp-icon-action jp-resume-preview-trigger',
                                'data-resume-url' => $resumepreviewurl->out(false),
                                'title' => get_string('previewresume', 'local_jobportal'),
                                'aria-label' => get_string('previewresume', 'local_jobportal'),
                            )
                        );
                    }
                }
                $resumelinks[] = html_writer::link(
                    $resumedownloadurl,
                    '⬇️',
                    array(
                        'class' => 'btn btn-link jp-icon-action',
                        'target' => '_blank',
                        'rel' => 'noopener',
                        'title' => get_string('downloadresume', 'local_jobportal'),
                        'aria-label' => get_string('downloadresume', 'local_jobportal'),
                    )
                );
                $resumecontent = html_writer::div(implode('', $resumelinks), 'jp-resume-actions');
            }
        }

        $phone = !empty($app->phone1) ? $app->phone1 : $app->phone2;
        $openapplicationparams = array('appid' => (int)$app->id);
        if (!empty($page)) {
            $openapplicationparams['page'] = $page;
        }
        $openapplicationparams = array_merge($openapplicationparams, $appfilterparams);
        $openapplicationurl = new moodle_url('/local/jobportal/application.php', $openapplicationparams);
        $studentprofileurl = new moodle_url('/local/jobportal/student_profile.php', array('userid' => (int)$app->userid));
        $actionscontent = html_writer::div(
            html_writer::link(
                $openapplicationurl,
                get_string('openapplication', 'local_jobportal'),
                array('class' => 'btn btn-outline-primary btn-sm')
            ),
            'mb-1'
        );
        $actionscontent .= html_writer::link(
            $studentprofileurl,
            get_string('viewstudentprofile', 'local_jobportal'),
            array('class' => 'btn btn-outline-secondary btn-sm')
        );

        echo html_writer::start_tag('tr');
        echo html_writer::tag(
            'td',
            html_writer::checkbox('appids[]', $app->id, false, '', array('class' => 'jp-app-select')),
            array('class' => 'jp-col-select text-center')
        );
        echo html_writer::tag('td', fullname($app), array('class' => 'jp-col-name'));
        echo html_writer::tag('td', s($app->email), array('class' => 'jp-col-email'));
        echo html_writer::tag('td', s($phone ?: '-'), array('class' => 'jp-col-phone'));
        echo html_writer::tag('td', s($app->city ?: '-'), array('class' => 'jp-col-city'));
        echo html_writer::tag(
            'td',
            html_writer::tag('span', $shortlistlabel, array('class' => local_jobportal_shortlist_badge_class($shortliststatus))),
            array('class' => 'jp-col-shortlist')
        );
        echo html_writer::tag('td', $poststagename, array('class' => 'jp-col-poststage'));
        echo html_writer::tag(
            'td',
            html_writer::tag('span', $resumestatuslabel, array('class' => $resumestatusbadge)),
            array('class' => 'jp-col-resumestatus')
        );
        echo html_writer::tag('td', s($resumeratinglabel), array('class' => 'jp-col-rating text-center'));
        echo html_writer::tag('td', userdate($app->timecreated, $dateformat), array('class' => 'jp-col-applied text-center'));
        echo html_writer::tag('td', s($lastactivitylabel), array('class' => 'jp-col-lastactivity text-center'));
        echo html_writer::tag('td', $resumecontent, array('class' => 'jp-col-resume text-center'));
        echo html_writer::tag('td', $actionscontent, array('class' => 'jp-col-actions text-center'));
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_tag('div');

    echo html_writer::start_tag('div', array('class' => 'row'));
    
    // Bulk Shortlist Card
    echo html_writer::start_tag('div', array('class' => 'col-lg-6 mb-4'));
    echo html_writer::start_tag('div', array('class' => 'card jp-form-section border-0 shadow-sm h-100'));
    echo html_writer::start_tag('div', array('class' => 'card-body p-4'));
    echo html_writer::tag('h6', '📋 ' . get_string('bulkshortliststatus', 'local_jobportal'), array('class' => 'card-title font-weight-bold mb-3'));
    echo html_writer::div(get_string('bulknoterequiredhint', 'local_jobportal'), 'text-muted small mb-3');
    echo html_writer::start_div('mb-3');
    echo html_writer::select($shortlistoptions, 'shortliststatus', '', false, array('class' => 'custom-select bg-light border-0 w-100'));
    echo html_writer::end_div();
    echo html_writer::start_div('mb-3');
    echo html_writer::tag('textarea', '', array(
        'name' => 'shortlistnote',
        'rows' => 2,
        'class' => 'form-control bg-light border-0',
        'placeholder' => get_string('shortlistnoteplaceholder', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::tag(
        'button',
        get_string('updateselectedshortlist', 'local_jobportal'),
        array('type' => 'submit', 'name' => 'action', 'value' => 'bulkchangeshortlist', 'class' => 'btn btn-secondary jp-action-pill')
    );
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div'); // col
    
    // Bulk Post Shortlist Stage Card
    echo html_writer::start_tag('div', array('class' => 'col-lg-6 mb-4'));
    echo html_writer::start_tag('div', array('class' => 'card jp-form-section border-0 shadow-sm h-100'));
    echo html_writer::start_tag('div', array('class' => 'card-body p-4'));
    echo html_writer::tag('h6', '🔄 ' . get_string('bulkpostshortliststage', 'local_jobportal'), array('class' => 'card-title font-weight-bold mb-3'));
    echo html_writer::div(get_string('multipleroundshelp', 'local_jobportal'), 'text-muted small mb-1');
    echo html_writer::div(get_string('bulknoterequiredhint', 'local_jobportal'), 'text-muted small mb-3');
    
    echo html_writer::start_div('row mb-2');
    echo html_writer::start_div('col-sm-6 mb-2');
    echo html_writer::select(
        $poststageoptions,
        'stageid',
        '',
        array('' => get_string('selectstage', 'local_jobportal')),
        array('class' => 'custom-select bg-light border-0 w-100')
    );
    echo html_writer::end_div();
    echo html_writer::start_div('col-sm-6 mb-2');
    echo html_writer::empty_tag('input', array('type' => 'datetime-local', 'name' => 'scheduleddatetime', 'class' => 'form-control bg-light border-0'));
    echo html_writer::end_div();
    echo html_writer::end_div();
    
    echo html_writer::start_div('row mb-2');
    echo html_writer::start_div('col-sm-4 mb-2');
    echo local_jobportal_render_select_with_tooltip(
        $schedulestatusoptions,
        'schedulestatus',
        'scheduled',
        false,
        array('class' => 'custom-select bg-light border-0 w-100'),
        get_string('schedulestatus', 'local_jobportal'),
        get_string('schedulestatustooltip', 'local_jobportal')
    );
    echo html_writer::end_div();
    echo html_writer::start_div('col-sm-4 mb-2');
    echo local_jobportal_render_select_with_tooltip(
        $roundoutcomeoptions,
        'roundoutcome',
        'pending',
        false,
        array('class' => 'custom-select bg-light border-0 w-100'),
        get_string('roundoutcome', 'local_jobportal'),
        get_string('roundoutcometooltip', 'local_jobportal')
    );
    echo html_writer::end_div();
    echo html_writer::start_div('col-sm-4 mb-2');
    echo html_writer::select(
        $schedulemodeoptions,
        'schedulemode',
        '',
        array('' => get_string('schedulemode', 'local_jobportal')),
        array('class' => 'custom-select bg-light border-0 w-100')
    );
    echo html_writer::end_div();
    echo html_writer::end_div();
    
    echo html_writer::start_div('row mb-2');
    echo html_writer::start_div('col-sm-6 mb-2');
    echo html_writer::empty_tag('input', array(
        'type' => 'number',
        'name' => 'scheduleduration',
        'class' => 'form-control bg-light border-0',
        'min' => 1,
        'step' => 5,
        'placeholder' => get_string('scheduledurationminutes', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::start_div('col-sm-6 mb-2');
    echo html_writer::empty_tag('input', array(
        'type' => 'text',
        'name' => 'schedulelink',
        'class' => 'form-control bg-light border-0',
        'placeholder' => get_string('schedulelink', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::end_div();
    
    echo html_writer::start_div('row mb-2');
    echo html_writer::start_div('col-sm-6 mb-2');
    echo html_writer::empty_tag('input', array(
        'type' => 'text',
        'name' => 'schedulevenue',
        'class' => 'form-control bg-light border-0',
        'placeholder' => get_string('schedulevenue', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::start_div('col-sm-6 mb-2');
    echo html_writer::tag('textarea', '', array(
        'name' => 'stagenote',
        'rows' => 1,
        'class' => 'form-control bg-light border-0',
        'placeholder' => get_string('stagenoteplaceholder', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::end_div();
    
    echo html_writer::tag(
        'button',
        get_string('updateselectedapplications', 'local_jobportal'),
        array('type' => 'submit', 'name' => 'action', 'value' => 'bulkchangepoststage', 'class' => 'btn btn-info jp-action-pill')
    );
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div'); // col
    echo html_writer::end_tag('div'); // row
    
    if (!empty($schedulableroundstageoptions)) {
        echo html_writer::start_tag('div', array('class' => 'card jp-form-section border-0 shadow-sm mb-4'));
        echo html_writer::start_tag('div', array('class' => 'card-body p-4'));
        echo html_writer::tag('h6', '📅 ' . get_string('bulkupdateroundevent', 'local_jobportal'), array('class' => 'card-title font-weight-bold mb-3'));
        echo html_writer::div(get_string('bulkupdateroundeventhelp', 'local_jobportal'), 'text-muted small mb-3');
        
        echo html_writer::start_div('row mb-2');
        echo html_writer::start_div('col-sm-6 mb-2');
        echo html_writer::select(
            $schedulableroundstageoptions,
            'round_stageid',
            '',
            array('' => get_string('selectstage', 'local_jobportal')),
            array('class' => 'custom-select bg-light border-0 w-100')
        );
        echo html_writer::end_div();
        echo html_writer::start_div('col-sm-6 mb-2');
        echo html_writer::empty_tag('input', array('type' => 'datetime-local', 'name' => 'round_scheduleddatetime', 'class' => 'form-control bg-light border-0'));
        echo html_writer::end_div();
        echo html_writer::end_div();
        
        echo html_writer::start_div('row mb-2');
        echo html_writer::start_div('col-sm-3 mb-2');
        echo local_jobportal_render_select_with_tooltip(
            $schedulestatusoptions,
            'round_schedulestatus',
            'scheduled',
            false,
            array('class' => 'custom-select bg-light border-0 w-100'),
            get_string('schedulestatus', 'local_jobportal'),
            get_string('schedulestatustooltip', 'local_jobportal')
        );
        echo html_writer::end_div();
        echo html_writer::start_div('col-sm-3 mb-2');
        echo local_jobportal_render_select_with_tooltip(
            $roundoutcomeoptions,
            'round_roundoutcome',
            'pending',
            false,
            array('class' => 'custom-select bg-light border-0 w-100'),
            get_string('roundoutcome', 'local_jobportal'),
            get_string('roundoutcometooltip', 'local_jobportal')
        );
        echo html_writer::end_div();
        echo html_writer::start_div('col-sm-3 mb-2');
        echo html_writer::select(
            $schedulemodeoptions,
            'round_schedulemode',
            '',
            array('' => get_string('schedulemode', 'local_jobportal')),
            array('class' => 'custom-select bg-light border-0 w-100')
        );
        echo html_writer::end_div();
        echo html_writer::start_div('col-sm-3 mb-2');
        echo html_writer::empty_tag('input', array(
            'type' => 'number',
            'name' => 'round_scheduleduration',
            'class' => 'form-control bg-light border-0',
            'min' => 1,
            'step' => 5,
            'placeholder' => get_string('scheduledurationminutes', 'local_jobportal'),
        ));
        echo html_writer::end_div();
        echo html_writer::end_div();
        
        echo html_writer::start_div('row mb-2');
        echo html_writer::start_div('col-sm-6 mb-2');
        echo html_writer::empty_tag('input', array(
            'type' => 'text',
            'name' => 'round_schedulelink',
            'class' => 'form-control bg-light border-0',
            'placeholder' => get_string('schedulelink', 'local_jobportal'),
        ));
        echo html_writer::end_div();
        echo html_writer::start_div('col-sm-6 mb-2');
        echo html_writer::empty_tag('input', array(
            'type' => 'text',
            'name' => 'round_schedulevenue',
            'class' => 'form-control bg-light border-0',
            'placeholder' => get_string('schedulevenue', 'local_jobportal'),
        ));
        echo html_writer::end_div();
        echo html_writer::end_div();
        
        echo html_writer::start_div('row mb-3');
        echo html_writer::start_div('col-12');
        echo html_writer::tag('textarea', '', array(
            'name' => 'round_roundnote',
            'rows' => 2,
            'class' => 'form-control bg-light border-0',
            'placeholder' => get_string('roundnoteplaceholder', 'local_jobportal'),
        ));
        echo html_writer::end_div();
        echo html_writer::end_div();
        
        echo html_writer::tag(
            'button',
            get_string('bulkupdateroundevent', 'local_jobportal'),
            array('type' => 'submit', 'name' => 'action', 'value' => 'bulkupdateroundevent', 'class' => 'btn btn-outline-info jp-action-pill')
        );
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');
    }
        echo html_writer::end_tag('form');
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');
    }

    if (!empty($showapp)) {
        require(__DIR__ . '/partials/application_detail_section.php');
    }

    if (empty($showapp) && $filteredapplicationscount > $perpage) {
        echo $OUTPUT->paging_bar($filteredapplicationscount, $page, $perpage, $pagingurl);
    }
}

echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/jobportal/view.php', array('id' => $jobid)),
        '← ' . get_string('backtojob', 'local_jobportal')
    ),
    'mt-3'
);

echo $OUTPUT->footer();
