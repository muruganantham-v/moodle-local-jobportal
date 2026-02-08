<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');
require_once(__DIR__ . '/locallib.php');

require_login();

/**
 * Get badge class for shortlist decision.
 *
 * @param string $shortliststatus
 * @return string
 */
function local_jobportal_shortlist_badge_class($shortliststatus) {
    switch ($shortliststatus) {
        case 'internalshortlisted':
            return 'badge badge-info';
        case 'shortlisted':
            return 'badge badge-success';
        case 'notshortlisted':
            return 'badge badge-danger';
        case 'pending':
            return 'badge badge-warning';
        default:
            return 'badge badge-secondary';
    }
}

/**
 * Get badge class for post-shortlist stage.
 *
 * @param string $shortname
 * @return string
 */
function local_jobportal_post_stage_badge_class($shortname) {
    switch ($shortname) {
        case 'accepted':
            return 'badge badge-success';
        case 'rejected':
            return 'badge badge-danger';
        case 'offermade':
            return 'badge badge-primary';
        case 'pending':
            return 'badge badge-warning';
        case '':
            return 'badge badge-secondary';
        default:
            return 'badge badge-info';
    }
}

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
        $reopenallowed = array('testscheduled', 'testdone', 'interviewscheduled', 'offermade');
        return in_array($to, $reopenallowed, true);
    }

    $matrix = array(
        '' => array('testscheduled', 'interviewscheduled'),
        'testscheduled' => array('testscheduled', 'testdone', 'interviewscheduled'),
        'testdone' => array('testscheduled', 'interviewscheduled', 'offermade'),
        'interviewscheduled' => array('interviewscheduled', 'offermade'),
        // Legacy support for records already in Interview Done.
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

    if (in_array($schedulestatus, array('completed', 'cancelled', 'noshow'), true)) {
        $skiphistorycheck = false;
        if (!empty($existingevent->id)) {
            $existingstatus = !empty($existingevent->schedulestatus) ?
                local_jobportal_normalize_schedule_status($existingevent->schedulestatus) : 'scheduled';
            if (in_array($existingstatus, array('completed', 'cancelled', 'noshow'), true)) {
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
    if (in_array($schedulestatus, array('cancelled', 'noshow'), true) && $roundoutcome !== 'pending') {
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
    } else if ($stage->shortname === 'interviewdone' && empty($application->interviewcompletedat)) {
        $update->interviewcompletedat = $now;
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
$appsort = optional_param('appsort', 'appliedon', PARAM_ALPHANUMEXT);
$appsortdir = core_text::strtolower(trim(optional_param('appsortdir', 'desc', PARAM_ALPHA)));
if ($page < 0) {
    $page = 0;
}
$perpage = 10;

$context = context_system::instance();
require_capability('local/jobportal:viewapplications', $context);
$PAGE->set_context($context);

$job = $DB->get_record('local_jobportal_jobs', array('id' => $jobid), '*', MUST_EXIST);

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
$allowedappsorts = array('appliedon', 'name', 'shortliststatus', 'poststage', 'resumestatus', 'resumerating');
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

$appfilterparams = array();
if ($appsearch !== '') {
    $appfilterparams['appsearch'] = $appsearch;
}
if ($filtershortlist !== 'all') {
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
);
$appsortdiroptions = array(
    'asc' => get_string('sortdirection', 'local_jobportal') . ': ' . get_string('sortasc', 'local_jobportal'),
    'desc' => get_string('sortdirection', 'local_jobportal') . ': ' . get_string('sortdesc', 'local_jobportal'),
);

$baseurlparams = array('jobid' => $jobid);
$baseurlparams = array_merge($baseurlparams, $appfilterparams);
if (!empty($page)) {
    $baseurlparams['page'] = $page;
}
$baseurl = new moodle_url('/local/jobportal/applications.php', $baseurlparams);
$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('applicationsfor', 'local_jobportal', format_string($job->title)));
$PAGE->set_heading(get_string('applicationsfor', 'local_jobportal', format_string($job->title)));
local_jobportal_require_styles();
$dateformat = '%d/%m/%Y';
$datetimeformat = '%d/%m/%Y %H:%M';
$datetimesecondsformat = '%d/%m/%Y %H:%M:%S';

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

if ($filtershortlist !== 'all') {
    $sqlparams['filtershortlist'] = $filtershortlist;
    $where[] = 'a.shortliststatus = :filtershortlist';
}

if ($filterpoststage === 'notset') {
    $where[] = 'a.currentstageid IS NULL';
} else if ($filterpoststage !== 'all') {
    $sqlparams['filterpoststage'] = (int)$filterpoststage;
    $where[] = 'a.currentstageid = :filterpoststage';
}

if ($filterresumestatus !== 'all') {
    $sqlparams['filterresumestatus'] = $filterresumestatus;
    $where[] = "COALESCE(NULLIF(p.resumestatus, ''), 'notsubmitted') = :filterresumestatus";
}

if ($filterhasresume !== 'all') {
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

if ($filterappliedfrom !== null) {
    $sqlparams['filterappliedfrom'] = (int)$filterappliedfrom;
    $where[] = 'a.timecreated >= :filterappliedfrom';
}
if ($filterappliedto !== null) {
    $sqlparams['filterappliedto'] = (int)$filterappliedto;
    $where[] = 'a.timecreated <= :filterappliedto';
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
                if (isset($poststages[$eventstageid]) && !empty($poststages[$eventstageid]->hasscheduledate)) {
                    if (!isset($stageeventcounts[$eventstageid])) {
                        $stageeventcounts[$eventstageid] = 0;
                    }
                    $stageeventcounts[$eventstageid]++;
                    $line .= ' - ' . get_string('roundlabel', 'local_jobportal', $stageeventcounts[$eventstageid]);
                }
                if (!empty($event->isinternal)) {
                    $line .= ' (' . get_string('internalstage', 'local_jobportal') . ')';
                }
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
echo html_writer::tag('h3', format_string($job->title));
echo html_writer::tag('h5', format_string($job->company), array('class' => 'mt-2 mb-0'));
echo html_writer::end_div();

// Statistics Cards
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

    if ($filteredapplicationscount > $perpage) {
        echo $OUTPUT->paging_bar($filteredapplicationscount, $page, $perpage, $pagingurl);
    }

    $PAGE->requires->js_init_code("
        (function() {
            var toggle = document.getElementById('jp-select-all');
            if (toggle) {
                toggle.addEventListener('change', function() {
                    var boxes = document.querySelectorAll('.jp-app-select');
                    boxes.forEach(function(box) {
                        box.checked = toggle.checked;
                    });
                });
            }

            var expandButtons = document.querySelectorAll('.jp-expand-all');
            expandButtons.forEach(function(button) {
                button.addEventListener('click', function() {
                    var appid = button.getAttribute('data-appid');
                    if (!appid) {
                        return;
                    }
                    var sections = document.querySelectorAll('.jp-section-' + appid);
                    sections.forEach(function(section) {
                        section.classList.add('show');
                    });
                });
            });

            var collapseButtons = document.querySelectorAll('.jp-collapse-all');
            collapseButtons.forEach(function(button) {
                button.addEventListener('click', function() {
                    var appid = button.getAttribute('data-appid');
                    if (!appid) {
                        return;
                    }
                    var sections = document.querySelectorAll('.jp-section-' + appid);
                    sections.forEach(function(section) {
                        section.classList.remove('show');
                    });
                });
            });

            var previewPanel = document.getElementById('jp-resume-preview-panel');
            var previewFrame = document.getElementById('jp-resume-preview-frame');
            var previewClose = document.getElementById('jp-resume-preview-close');
            var previewTriggers = document.querySelectorAll('.jp-resume-preview-trigger');

            previewTriggers.forEach(function(trigger) {
                trigger.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (!previewPanel || !previewFrame) {
                        return;
                    }
                    var url = trigger.getAttribute('data-resume-url');
                    if (!url) {
                        return;
                    }
                    previewFrame.setAttribute('src', url);
                    previewPanel.classList.remove('d-none');
                    previewPanel.scrollIntoView({behavior: 'smooth', block: 'start'});
                });
            });

            if (previewClose) {
                previewClose.addEventListener('click', function() {
                    if (previewFrame) {
                        previewFrame.setAttribute('src', 'about:blank');
                    }
                    if (previewPanel) {
                        previewPanel.classList.add('d-none');
                    }
                });
            }

            var stageScheduleMap = " . json_encode($stageschedulablemap) . ";
            function findControl(form, name) {
                if (!form || !name) {
                    return null;
                }
                return form.querySelector('[name=\"' + name + '\"]');
            }
            function setControlVisible(control, visible) {
                if (!control) {
                    return;
                }
                var wrapper = control.closest('.jp-inline-col-select, .jp-inline-col-date, .jp-inline-col-note');
                if (wrapper) {
                    wrapper.style.display = visible ? '' : 'none';
                } else {
                    control.style.display = visible ? '' : 'none';
                }
                if (typeof control.disabled !== 'undefined') {
                    control.disabled = !visible;
                }
            }
            function setControlValue(control, value) {
                if (!control) {
                    return;
                }
                control.value = value;
            }
            function bindScheduleControls(form, config) {
                if (!form) {
                    return;
                }
                var stageControl = findControl(form, config.stageField || '');
                var selectionControl = findControl(form, config.selectionField || config.stageField || '');
                var dateControl = findControl(form, config.dateField);
                var statusControl = findControl(form, config.statusField);
                var outcomeControl = findControl(form, config.outcomeField);
                var modeControl = findControl(form, config.modeField);
                var durationControl = findControl(form, config.durationField);
                var linkControl = findControl(form, config.linkField);
                var venueControl = findControl(form, config.venueField);
                if (!dateControl && !statusControl && !outcomeControl && !modeControl && !durationControl && !linkControl && !venueControl) {
                    return;
                }

                var sync = function() {
                    var selected = true;
                    if (selectionControl) {
                        selected = selectionControl.value !== '';
                    }

                    var schedulable = false;
                    if (selected) {
                        if (config.forceSchedulable) {
                            schedulable = true;
                        } else if (stageControl && stageControl.value !== '' &&
                                Object.prototype.hasOwnProperty.call(stageScheduleMap, stageControl.value)) {
                            schedulable = !!stageScheduleMap[stageControl.value];
                        }
                    }

                    if (!schedulable) {
                        setControlValue(statusControl, 'scheduled');
                        setControlValue(outcomeControl, 'pending');
                        setControlValue(modeControl, '');
                        setControlValue(dateControl, '');
                        setControlValue(durationControl, '');
                        setControlValue(linkControl, '');
                        setControlValue(venueControl, '');
                    }

                    var status = statusControl ? statusControl.value : 'scheduled';
                    var isplanning = status === 'scheduled' || status === 'rescheduled';
                    var iscompleted = status === 'completed';
                    var mode = modeControl ? modeControl.value : '';

                    setControlVisible(statusControl, schedulable);
                    setControlVisible(dateControl, schedulable && isplanning);
                    setControlVisible(durationControl, schedulable && isplanning);
                    setControlVisible(modeControl, schedulable && isplanning);
                    setControlVisible(outcomeControl, schedulable && iscompleted);

                    if (!(schedulable && iscompleted)) {
                        setControlValue(outcomeControl, 'pending');
                    }
                    if (!(schedulable && isplanning)) {
                        setControlValue(modeControl, '');
                        setControlValue(dateControl, '');
                        setControlValue(durationControl, '');
                    }

                    var showLink = schedulable && isplanning && (mode === 'online' || mode === 'hybrid');
                    var showVenue = schedulable && isplanning && (mode === 'offline' || mode === 'hybrid');
                    setControlVisible(linkControl, showLink);
                    setControlVisible(venueControl, showVenue);
                    if (!showLink) {
                        setControlValue(linkControl, '');
                    }
                    if (!showVenue) {
                        setControlValue(venueControl, '');
                    }
                };

                if (selectionControl) {
                    selectionControl.addEventListener('change', sync);
                }
                if (statusControl) {
                    statusControl.addEventListener('change', sync);
                }
                if (modeControl) {
                    modeControl.addEventListener('change', sync);
                }
                sync();
            }

            var bulkForm = document.querySelector('.jp-bulk-section form[method=\"post\"]');
            if (bulkForm) {
                bindScheduleControls(bulkForm, {
                    stageField: 'stageid',
                    selectionField: 'stageid',
                    dateField: 'scheduleddatetime',
                    statusField: 'schedulestatus',
                    outcomeField: 'roundoutcome',
                    modeField: 'schedulemode',
                    durationField: 'scheduleduration',
                    linkField: 'schedulelink',
                    venueField: 'schedulevenue'
                });
                bindScheduleControls(bulkForm, {
                    stageField: 'round_stageid',
                    selectionField: 'round_stageid',
                    dateField: 'round_scheduleddatetime',
                    statusField: 'round_schedulestatus',
                    outcomeField: 'round_roundoutcome',
                    modeField: 'round_schedulemode',
                    durationField: 'round_scheduleduration',
                    linkField: 'round_schedulelink',
                    venueField: 'round_schedulevenue'
                });
            }

            document.querySelectorAll('form').forEach(function(form) {
                var actionInput = findControl(form, 'action');
                var action = actionInput ? actionInput.value : '';
                if (action === 'changepoststage' || action === 'reopenpoststage') {
                    bindScheduleControls(form, {
                        stageField: 'stageid',
                        selectionField: 'stageid',
                        dateField: 'scheduleddatetime',
                        statusField: 'schedulestatus',
                        outcomeField: 'roundoutcome',
                        modeField: 'schedulemode',
                        durationField: 'scheduleduration',
                        linkField: 'schedulelink',
                        venueField: 'schedulevenue'
                    });
                } else if (action === 'updateroundevent') {
                    bindScheduleControls(form, {
                        selectionField: 'eventid',
                        forceSchedulable: true,
                        dateField: 'scheduleddatetime',
                        statusField: 'schedulestatus',
                        outcomeField: 'roundoutcome',
                        modeField: 'schedulemode',
                        durationField: 'scheduleduration',
                        linkField: 'schedulelink',
                        venueField: 'schedulevenue'
                    });
                }
            });

            var roundVisibilityToggles = document.querySelectorAll('.jp-round-show-closed');
            function findSelectOption(select, value) {
                if (!select) {
                    return null;
                }
                for (var i = 0; i < select.options.length; i++) {
                    if (select.options[i].value === value) {
                        return select.options[i];
                    }
                }
                return null;
            }
            roundVisibilityToggles.forEach(function(toggle) {
                var targetid = toggle.getAttribute('data-target');
                if (!targetid) {
                    return;
                }
                var select = document.getElementById(targetid);
                if (!select) {
                    return;
                }
                var closedraw = toggle.getAttribute('data-closed-options') || '{}';
                var closedoptions = {};
                try {
                    closedoptions = JSON.parse(closedraw);
                } catch (e) {
                    closedoptions = {};
                }
                var closedids = Object.keys(closedoptions);
                var syncClosedOptions = function() {
                    var showclosed = !!toggle.checked;
                    var removedselected = false;
                    if (showclosed) {
                        closedids.forEach(function(id) {
                            if (!findSelectOption(select, id)) {
                                var opt = document.createElement('option');
                                opt.value = id;
                                opt.text = closedoptions[id];
                                opt.setAttribute('data-round-closed', '1');
                                select.appendChild(opt);
                            }
                        });
                        return;
                    }
                    closedids.forEach(function(id) {
                        var existing = findSelectOption(select, id);
                        if (!existing) {
                            return;
                        }
                        if (existing.selected) {
                            removedselected = true;
                        }
                        existing.remove();
                    });
                    if (removedselected) {
                        select.value = '';
                    }
                };
                toggle.addEventListener('change', syncClosedOptions);
                syncClosedOptions();
            });
        })();
    ");

    echo html_writer::start_tag('div', array('class' => 'jp-bulk-section'));
    echo html_writer::start_tag('div', array('class' => ''));
    echo html_writer::tag('h5', get_string('bulkupdates', 'local_jobportal'));
    echo html_writer::tag('p', get_string('bulkupdatesdesc', 'local_jobportal'), array('class' => 'text-muted mb-4'));

    $resetfilterurl = new moodle_url('/local/jobportal/applications.php', array('jobid' => $jobid));
    echo html_writer::start_tag('form', array('method' => 'get', 'action' => new moodle_url('/local/jobportal/applications.php'), 'class' => 'mb-3'));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'jobid', 'value' => $jobid));
    echo html_writer::tag('h6', get_string('applicantfilters', 'local_jobportal'), array('class' => 'mb-2'));
    echo html_writer::start_div('jp-inline-row mb-2');
    echo html_writer::start_div('jp-inline-col-note');
    echo html_writer::empty_tag('input', array(
        'type' => 'text',
        'name' => 'appsearch',
        'value' => $appsearch,
        'class' => 'form-control',
        'placeholder' => get_string('search'),
    ));
    echo html_writer::end_div();
    echo html_writer::start_div('jp-inline-col-select');
    echo html_writer::select($shortlistfilteroptions, 'filtershortlist', $filtershortlist, false, array('class' => 'custom-select jp-select-control'));
    echo html_writer::end_div();
    echo html_writer::start_div('jp-inline-col-select');
    echo html_writer::select($stagefilteroptions, 'filterpoststage', $filterpoststage, false, array('class' => 'custom-select jp-select-control'));
    echo html_writer::end_div();
    echo html_writer::start_div('jp-inline-col-select');
    echo html_writer::select($resumestatusfilteroptions, 'filterresumestatus', $filterresumestatus, false, array('class' => 'custom-select jp-select-control'));
    echo html_writer::end_div();
    echo html_writer::start_div('jp-inline-col-select');
    echo html_writer::select($hasresumefilteroptions, 'filterhasresume', $filterhasresume, false, array('class' => 'custom-select jp-select-control'));
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::start_div('jp-inline-row mb-2');
    echo html_writer::start_div('jp-inline-col-date');
    echo html_writer::empty_tag('input', array(
        'type' => 'date',
        'name' => 'filterappliedfrom',
        'value' => $filterappliedfromraw,
        'class' => 'form-control',
        'title' => get_string('appliedfromfilter', 'local_jobportal'),
        'aria-label' => get_string('appliedfromfilter', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::start_div('jp-inline-col-date');
    echo html_writer::empty_tag('input', array(
        'type' => 'date',
        'name' => 'filterappliedto',
        'value' => $filterappliedtoraw,
        'class' => 'form-control',
        'title' => get_string('appliedtofilter', 'local_jobportal'),
        'aria-label' => get_string('appliedtofilter', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::start_div('jp-inline-col-select');
    echo html_writer::select($appsortoptions, 'appsort', $appsort, false, array('class' => 'custom-select jp-select-control'));
    echo html_writer::end_div();
    echo html_writer::start_div('jp-inline-col-select');
    echo html_writer::select($appsortdiroptions, 'appsortdir', $appsortdir, false, array('class' => 'custom-select jp-select-control'));
    echo html_writer::end_div();
    echo html_writer::start_div('jp-inline-col-note');
    echo html_writer::tag('button', get_string('apply'), array('type' => 'submit', 'class' => 'btn btn-outline-primary btn-sm mr-2'));
    echo html_writer::link($resetfilterurl, get_string('resetfilters', 'local_jobportal'), array('class' => 'btn btn-outline-secondary btn-sm'));
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::div(get_string('applicantsshowncount', 'local_jobportal', $filteredapplicationscount), 'text-muted small');
    echo html_writer::end_tag('form');

    echo html_writer::start_tag('form', array('method' => 'post', 'action' => new moodle_url('/local/jobportal/applications.php')));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'jobid', 'value' => $jobid));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'page', 'value' => $page));
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
    echo html_writer::tag('th', get_string('resumelink', 'local_jobportal'), array('class' => 'jp-col-resume text-center'));
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
        echo html_writer::tag('td', $resumecontent, array('class' => 'jp-col-resume text-center'));
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_tag('div');

    echo html_writer::tag('h6', get_string('bulkshortliststatus', 'local_jobportal'), array('class' => 'mt-3'));
    echo html_writer::div(get_string('bulknoterequiredhint', 'local_jobportal'), 'text-muted small mb-2');
    echo html_writer::start_div('jp-inline-row mb-2');
    echo html_writer::start_div('jp-inline-col-select');
    echo html_writer::select($shortlistoptions, 'shortliststatus', '', false, array('class' => 'custom-select jp-select-control'));
    echo html_writer::end_div();
    echo html_writer::start_div('jp-inline-col-note');
    echo html_writer::tag('textarea', '', array(
        'name' => 'shortlistnote',
        'rows' => 2,
        'class' => 'form-control',
        'placeholder' => get_string('shortlistnoteplaceholder', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::tag(
        'button',
        get_string('updateselectedshortlist', 'local_jobportal'),
        array('type' => 'submit', 'name' => 'action', 'value' => 'bulkchangeshortlist', 'class' => 'btn btn-secondary btn-sm mr-2')
    );

    echo html_writer::tag('h6', get_string('bulkpostshortliststage', 'local_jobportal'), array('class' => 'mt-4'));
    echo html_writer::div(get_string('multipleroundshelp', 'local_jobportal'), 'text-muted small mb-2');
    echo html_writer::div(get_string('bulknoterequiredhint', 'local_jobportal'), 'text-muted small mb-2');
    echo html_writer::start_div('jp-inline-row mb-2');
    echo html_writer::start_div('jp-inline-col-select');
    echo html_writer::select(
        $poststageoptions,
        'stageid',
        '',
        array('' => get_string('selectstage', 'local_jobportal')),
        array('class' => 'custom-select jp-select-control')
    );
    echo html_writer::end_div();
    echo html_writer::start_div('jp-inline-col-date');
    echo html_writer::empty_tag('input', array('type' => 'datetime-local', 'name' => 'scheduleddatetime', 'class' => 'form-control'));
    echo html_writer::end_div();
    echo html_writer::start_div('jp-inline-col-select');
    echo local_jobportal_render_select_with_tooltip(
        $schedulestatusoptions,
        'schedulestatus',
        'scheduled',
        false,
        array('class' => 'custom-select jp-select-control'),
        get_string('schedulestatus', 'local_jobportal'),
        get_string('schedulestatustooltip', 'local_jobportal')
    );
    echo html_writer::end_div();
    echo html_writer::start_div('jp-inline-col-select');
    echo local_jobportal_render_select_with_tooltip(
        $roundoutcomeoptions,
        'roundoutcome',
        'pending',
        false,
        array('class' => 'custom-select jp-select-control'),
        get_string('roundoutcome', 'local_jobportal'),
        get_string('roundoutcometooltip', 'local_jobportal')
    );
    echo html_writer::end_div();
    echo html_writer::start_div('jp-inline-col-select');
    echo html_writer::select(
        $schedulemodeoptions,
        'schedulemode',
        '',
        array('' => get_string('schedulemode', 'local_jobportal')),
        array('class' => 'custom-select jp-select-control')
    );
    echo html_writer::end_div();
    echo html_writer::start_div('jp-inline-col-date');
    echo html_writer::empty_tag('input', array(
        'type' => 'number',
        'name' => 'scheduleduration',
        'class' => 'form-control',
        'min' => 1,
        'step' => 5,
        'placeholder' => get_string('scheduledurationminutes', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::start_div('jp-inline-row mb-2');
    echo html_writer::start_div('jp-inline-col-note');
    echo html_writer::empty_tag('input', array(
        'type' => 'text',
        'name' => 'schedulelink',
        'class' => 'form-control',
        'placeholder' => get_string('schedulelink', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::start_div('jp-inline-col-note');
    echo html_writer::empty_tag('input', array(
        'type' => 'text',
        'name' => 'schedulevenue',
        'class' => 'form-control',
        'placeholder' => get_string('schedulevenue', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::start_div('jp-inline-row mb-2');
    echo html_writer::start_div('jp-inline-col-note');
    echo html_writer::tag('textarea', '', array(
        'name' => 'stagenote',
        'rows' => 2,
        'class' => 'form-control',
        'placeholder' => get_string('stagenoteplaceholder', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::tag(
        'button',
        get_string('updateselectedapplications', 'local_jobportal'),
        array('type' => 'submit', 'name' => 'action', 'value' => 'bulkchangepoststage', 'class' => 'btn btn-info btn-sm')
    );
    if (!empty($schedulableroundstageoptions)) {
        echo html_writer::tag('h6', get_string('bulkupdateroundevent', 'local_jobportal'), array('class' => 'mt-4'));
        echo html_writer::div(get_string('bulkupdateroundeventhelp', 'local_jobportal'), 'text-muted small mb-2');
        echo html_writer::start_div('jp-inline-row mb-2');
        echo html_writer::start_div('jp-inline-col-select');
        echo html_writer::select(
            $schedulableroundstageoptions,
            'round_stageid',
            '',
            array('' => get_string('selectstage', 'local_jobportal')),
            array('class' => 'custom-select jp-select-control')
        );
        echo html_writer::end_div();
        echo html_writer::start_div('jp-inline-col-date');
        echo html_writer::empty_tag('input', array('type' => 'datetime-local', 'name' => 'round_scheduleddatetime', 'class' => 'form-control'));
        echo html_writer::end_div();
        echo html_writer::start_div('jp-inline-col-select');
        echo local_jobportal_render_select_with_tooltip(
            $schedulestatusoptions,
            'round_schedulestatus',
            'scheduled',
            false,
            array('class' => 'custom-select jp-select-control'),
            get_string('schedulestatus', 'local_jobportal'),
            get_string('schedulestatustooltip', 'local_jobportal')
        );
        echo html_writer::end_div();
        echo html_writer::start_div('jp-inline-col-select');
        echo local_jobportal_render_select_with_tooltip(
            $roundoutcomeoptions,
            'round_roundoutcome',
            'pending',
            false,
            array('class' => 'custom-select jp-select-control'),
            get_string('roundoutcome', 'local_jobportal'),
            get_string('roundoutcometooltip', 'local_jobportal')
        );
        echo html_writer::end_div();
        echo html_writer::start_div('jp-inline-col-select');
        echo html_writer::select(
            $schedulemodeoptions,
            'round_schedulemode',
            '',
            array('' => get_string('schedulemode', 'local_jobportal')),
            array('class' => 'custom-select jp-select-control')
        );
        echo html_writer::end_div();
        echo html_writer::start_div('jp-inline-col-date');
        echo html_writer::empty_tag('input', array(
            'type' => 'number',
            'name' => 'round_scheduleduration',
            'class' => 'form-control',
            'min' => 1,
            'step' => 5,
            'placeholder' => get_string('scheduledurationminutes', 'local_jobportal'),
        ));
        echo html_writer::end_div();
        echo html_writer::end_div();
        echo html_writer::start_div('jp-inline-row mb-2');
        echo html_writer::start_div('jp-inline-col-note');
        echo html_writer::empty_tag('input', array(
            'type' => 'text',
            'name' => 'round_schedulelink',
            'class' => 'form-control',
            'placeholder' => get_string('schedulelink', 'local_jobportal'),
        ));
        echo html_writer::end_div();
        echo html_writer::start_div('jp-inline-col-note');
        echo html_writer::empty_tag('input', array(
            'type' => 'text',
            'name' => 'round_schedulevenue',
            'class' => 'form-control',
            'placeholder' => get_string('schedulevenue', 'local_jobportal'),
        ));
        echo html_writer::end_div();
        echo html_writer::end_div();
        echo html_writer::start_div('jp-inline-row mb-2');
        echo html_writer::start_div('jp-inline-col-note');
        echo html_writer::tag('textarea', '', array(
            'name' => 'round_roundnote',
            'rows' => 2,
            'class' => 'form-control',
            'placeholder' => get_string('roundnoteplaceholder', 'local_jobportal'),
        ));
        echo html_writer::end_div();
        echo html_writer::end_div();
        echo html_writer::tag(
            'button',
            get_string('bulkupdateroundevent', 'local_jobportal'),
            array('type' => 'submit', 'name' => 'action', 'value' => 'bulkupdateroundevent', 'class' => 'btn btn-outline-info btn-sm')
        );
    }
    echo html_writer::end_tag('form');
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');

    foreach ($applications as $app) {
        $shortliststatus = local_jobportal_get_application_shortlist_status($app);
        $shortlistlabel = isset($shortlistoptions[$shortliststatus]) ?
            $shortlistoptions[$shortliststatus] : get_string('pending', 'local_jobportal');

        $stage = local_jobportal_get_application_stage($app, $stages);
        $currentstageid = $stage ? (int)$stage->id : 0;
        $poststageshortname = $stage ? $stage->shortname : '';
        $currentstageisterminal = $stage ? local_jobportal_is_terminal_post_stage($poststageshortname) : false;
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

        $resumepreviewurl = null;
        $resumedownloadurl = null;
        $resumecanpreview = false;
        if (!empty($app->profileid)) {
            $resumefile = local_jobportal_get_profile_resume_file((int)$app->profileid, $context);
            if ($resumefile) {
                $resumedownloadurl = local_jobportal_get_profile_resume_url((int)$app->profileid, $context, true);
                if (local_jobportal_resume_file_is_previewable($resumefile)) {
                    $resumecanpreview = true;
                    $resumepreviewurl = local_jobportal_get_profile_resume_url((int)$app->profileid, $context, false);
                }
            }
        }
        $resumestatus = !empty($app->resumestatus) ? local_jobportal_normalize_resume_status($app->resumestatus) : 'notsubmitted';
        $resumestatuslabel = isset($resumestatusoptions[$resumestatus]) ? $resumestatusoptions[$resumestatus] : $resumestatus;
        $resumestatusbadge = local_jobportal_resume_status_badge_class($resumestatus);
        $resumeratinglabel = !empty($app->resumerating) ? ((int)$app->resumerating . '/5') : '-';
        $resumehistory = !empty($app->profileid) && isset($resumehistorybyprofile[(int)$app->profileid]) ?
            $resumehistorybyprofile[(int)$app->profileid] : array();

        $completedstageids = array();
        $stageeventcounts = array();
        $stageroundsbyeventid = array();
        if (!empty($eventsbyapp[$app->id])) {
            foreach ($eventsbyapp[$app->id] as $event) {
                $eventstageid = (int)$event->stageid;
                if (isset($poststages[$eventstageid])) {
                    $completedstageids[$eventstageid] = true;
                    if (!isset($stageeventcounts[$eventstageid])) {
                        $stageeventcounts[$eventstageid] = 0;
                    }
                    $stageeventcounts[$eventstageid]++;
                    if (!empty($poststages[$eventstageid]->hasscheduledate)) {
                        $stageroundsbyeventid[(int)$event->id] = $stageeventcounts[$eventstageid];
                    }
                }
            }
        }
        if (!empty($currentstageid)) {
            $completedstageids[$currentstageid] = true;
        }

        $roundeventopenoptions = array();
        $roundeventclosedoptions = array();
        if (!empty($eventsbyapp[$app->id])) {
            foreach ($eventsbyapp[$app->id] as $event) {
                $eventstageid = (int)$event->stageid;
                if (empty($poststages[$eventstageid]) || empty($poststages[$eventstageid]->hasscheduledate)) {
                    continue;
                }
                $optionlabel = format_string($event->displayname);
                if (!empty($stageroundsbyeventid[(int)$event->id])) {
                    $optionlabel .= ' - ' . get_string('roundlabel', 'local_jobportal', (int)$stageroundsbyeventid[(int)$event->id]);
                }
                if (!empty($event->scheduledat)) {
                    $optionlabel .= ' - ' . userdate($event->scheduledat, $datetimeformat);
                }
                $eventstatus = !empty($event->schedulestatus) ?
                    local_jobportal_normalize_schedule_status($event->schedulestatus) : 'scheduled';
                $optionlabel .= ' - ' . local_jobportal_get_schedule_status_label($eventstatus);
                $eventoutcome = !empty($event->roundoutcome) ?
                    local_jobportal_normalize_round_outcome($event->roundoutcome) : 'pending';
                if ($eventstatus === 'completed' || $eventoutcome !== 'pending') {
                    $optionlabel .= ' - ' . local_jobportal_get_round_outcome_label($eventoutcome);
                }
                if (in_array($eventstatus, array('scheduled', 'rescheduled'), true)) {
                    $roundeventopenoptions[(int)$event->id] = $optionlabel;
                } else {
                    $roundeventclosedoptions[(int)$event->id] = $optionlabel;
                }
            }
        }

        $transitionoptions = array();
        $reopentransitionoptions = array();
        foreach ($poststageoptions as $stageidkey => $label) {
            $stageid = (int)$stageidkey;
            if (empty($poststages[$stageid])) {
                continue;
            }
            $targetstageshortname = $poststages[$stageid]->shortname;
            if (!local_jobportal_is_post_stage_transition_allowed($poststageshortname, $targetstageshortname, false)) {
                continue;
            }
            $allowreselectcurrent = ($stageid === $currentstageid) &&
                !empty($poststages[$stageid]) &&
                !empty($poststages[$stageid]->hasscheduledate);
            if ($stageid === $currentstageid && !$allowreselectcurrent) {
                continue;
            }
            if ($allowreselectcurrent) {
                $nextround = !empty($stageeventcounts[$stageid]) ? ((int)$stageeventcounts[$stageid] + 1) : 1;
                $label .= ' (' . get_string('schedulenextround', 'local_jobportal', $nextround) . ')';
            }
            $transitionoptions[$stageid] = $label;
        }
        if ($currentstageisterminal) {
            foreach ($poststageoptions as $stageidkey => $label) {
                $stageid = (int)$stageidkey;
                if (empty($poststages[$stageid])) {
                    continue;
                }
                $targetstageshortname = $poststages[$stageid]->shortname;
                if (!local_jobportal_is_post_stage_transition_allowed($poststageshortname, $targetstageshortname, true)) {
                    continue;
                }
                $reopentransitionoptions[$stageid] = $label;
            }
        }
        $shortlisttransitionoptions = array();
        foreach ($shortlistoptions as $targetshortliststatus => $targetlabel) {
            if ($targetshortliststatus === $shortliststatus) {
                continue;
            }
            if (!local_jobportal_is_shortlist_transition_allowed($shortliststatus, $targetshortliststatus)) {
                continue;
            }
            $shortlisttransitionoptions[$targetshortliststatus] = $targetlabel;
        }
        if (empty($shortlisttransitionoptions)) {
            $shortlisttransitionoptions = $shortlistoptions;
        }

        $phone = !empty($app->phone1) ? $app->phone1 : $app->phone2;
        $applicantsectionid = 'jp-applicant-' . (int)$app->id;
        $resumesectionid = 'jp-resume-' . (int)$app->id;
        $recruitsectionid = 'jp-recruit-' . (int)$app->id;
        $notessectionid = 'jp-notes-' . (int)$app->id;

        echo html_writer::start_tag('div', array('class' => 'jp-app-card'));
        echo html_writer::start_tag('div', array('class' => 'jp-app-header'));

        echo html_writer::tag('h5', fullname($app));
        echo html_writer::tag('h6', s($app->email));

        $statusline = html_writer::tag(
            'span',
            $shortlistlabel,
            array('class' => local_jobportal_shortlist_badge_class($shortliststatus) . ' mr-2')
        );
        if ($shortliststatus === 'shortlisted') {
            $statusline .= html_writer::tag(
                'span',
                $poststagename,
                array('class' => local_jobportal_post_stage_badge_class($poststageshortname) . ' mr-2')
            );
        }
        echo html_writer::tag(
            'p',
            $statusline .
            html_writer::tag('span', '📅 ' . get_string('appliedon', 'local_jobportal') . ': ' . userdate($app->timecreated, $dateformat), array('class' => 'text-muted')),
            array('class' => 'mb-0')
        );
        echo html_writer::end_div();

        echo html_writer::start_tag('div', array('class' => 'jp-section-btns'));
        echo html_writer::tag(
            'button',
            '👤 ' . get_string('sectionapplicantdetails', 'local_jobportal'),
            array(
                'type' => 'button',
                'class' => 'jp-section-btn',
                'data-toggle' => 'collapse',
                'data-target' => '#' . $applicantsectionid,
                'aria-controls' => $applicantsectionid,
                'aria-expanded' => 'true',
            )
        );
        echo html_writer::tag(
            'button',
            '📄 ' . get_string('sectionresumereview', 'local_jobportal'),
            array(
                'type' => 'button',
                'class' => 'jp-section-btn',
                'data-toggle' => 'collapse',
                'data-target' => '#' . $resumesectionid,
                'aria-controls' => $resumesectionid,
                'aria-expanded' => 'false',
            )
        );
        echo html_writer::tag(
            'button',
            '📋 ' . get_string('sectionrecruitment', 'local_jobportal'),
            array(
                'type' => 'button',
                'class' => 'jp-section-btn',
                'data-toggle' => 'collapse',
                'data-target' => '#' . $recruitsectionid,
                'aria-controls' => $recruitsectionid,
                'aria-expanded' => 'true',
            )
        );
        echo html_writer::tag(
            'button',
            '📝 ' . get_string('sectionnotes', 'local_jobportal'),
            array(
                'type' => 'button',
                'class' => 'jp-section-btn',
                'data-toggle' => 'collapse',
                'data-target' => '#' . $notessectionid,
                'aria-controls' => $notessectionid,
                'aria-expanded' => 'false',
            )
        );
        echo html_writer::tag(
            'button',
            '⬇ ' . get_string('expandallsections', 'local_jobportal'),
            array(
                'type' => 'button',
                'class' => 'jp-section-btn jp-expand-all',
                'data-appid' => (int)$app->id,
            )
        );
        echo html_writer::tag(
            'button',
            '⬆ ' . get_string('collapseallsections', 'local_jobportal'),
            array(
                'type' => 'button',
                'class' => 'jp-section-btn jp-collapse-all',
                'data-appid' => (int)$app->id,
            )
        );
        echo html_writer::end_tag('div');

        echo html_writer::start_tag('div', array('id' => $applicantsectionid, 'class' => 'collapse show jp-app-section jp-section-' . (int)$app->id));
        echo html_writer::start_tag('div', array('class' => 'jp-section-content'));
        echo html_writer::tag('h6', get_string('sectionapplicantdetails', 'local_jobportal'), array('class' => 'text-uppercase text-muted small mb-3'));
        
        echo html_writer::start_div('jp-info-grid');
        
        echo html_writer::start_div('jp-info-card');
        echo html_writer::div(get_string('applicantphone', 'local_jobportal'), 'jp-info-label');
        echo html_writer::div(s($phone ?: '-'), 'jp-info-value');
        echo html_writer::end_div();
        
        echo html_writer::start_div('jp-info-card');
        echo html_writer::div(get_string('applicantcity', 'local_jobportal'), 'jp-info-label');
        echo html_writer::div(s($app->city ?: '-'), 'jp-info-value');
        echo html_writer::end_div();
        
        echo html_writer::start_div('jp-info-card');
        echo html_writer::div(get_string('totalapplications', 'local_jobportal'), 'jp-info-label');
        echo html_writer::div((int)$app->totalapplications, 'jp-info-value');
        echo html_writer::end_div();
        
        echo html_writer::start_div('jp-info-card');
        echo html_writer::div(get_string('resumereviewstatus', 'local_jobportal'), 'jp-info-label');
        echo html_writer::div(html_writer::tag('span', $resumestatuslabel, array('class' => $resumestatusbadge)), 'jp-info-value');
        echo html_writer::end_div();

        echo html_writer::start_div('jp-info-card');
        echo html_writer::div(get_string('resumerating', 'local_jobportal'), 'jp-info-label');
        echo html_writer::div(s($resumeratinglabel), 'jp-info-value');
        echo html_writer::end_div();
        
        if ($resumedownloadurl) {
            echo html_writer::start_div('jp-info-card');
            echo html_writer::div(get_string('resume', 'local_jobportal'), 'jp-info-label');
            $resumebuttons = '';
            if ($resumecanpreview && $resumepreviewurl) {
                $resumebuttons .= html_writer::tag(
                    'button',
                    '👁️',
                    array(
                        'type' => 'button',
                        'class' => 'btn btn-outline-secondary jp-icon-action jp-resume-preview-trigger',
                        'data-resume-url' => $resumepreviewurl->out(false),
                        'title' => get_string('previewresume', 'local_jobportal'),
                        'aria-label' => get_string('previewresume', 'local_jobportal'),
                    )
                );
            }
            if ($resumedownloadurl) {
                $resumebuttons .= html_writer::link(
                    $resumedownloadurl,
                    '⬇️',
                    array(
                        'class' => 'btn btn-outline-primary jp-icon-action',
                        'target' => '_blank',
                        'rel' => 'noopener',
                        'title' => get_string('downloadresume', 'local_jobportal'),
                        'aria-label' => get_string('downloadresume', 'local_jobportal'),
                    )
                );
            }
            echo html_writer::div(
                $resumebuttons,
                'jp-info-value jp-resume-actions'
            );
            echo html_writer::end_div();
        }
        
        echo html_writer::end_div();
        if (!empty($app->portfolio)) {
            echo html_writer::tag('h6', get_string('portfolio', 'local_jobportal') . ':', array('class' => 'mt-3 mb-2'));
            $portfoliolink = s($app->portfolio);
            if (filter_var($app->portfolio, FILTER_VALIDATE_URL)) {
                $portfoliolink = html_writer::link(new moodle_url($app->portfolio), '🔗 ' . s($app->portfolio), array('target' => '_blank', 'rel' => 'noopener', 'class' => 'btn btn-outline-info btn-sm'));
            }
            echo html_writer::tag('p', $portfoliolink);
        }
        echo html_writer::end_div();

        if (!empty($app->skills)) {
            echo html_writer::tag('h6', get_string('skills', 'local_jobportal') . ':', array('class' => 'mt-2'));
            echo html_writer::tag('p', format_text($app->skills, FORMAT_PLAIN), array('class' => 'mb-2'));
        }
        if (!empty($app->experience)) {
            echo html_writer::tag('h6', get_string('experience', 'local_jobportal') . ':', array('class' => 'mt-2'));
            echo html_writer::tag('p', format_text($app->experience, FORMAT_PLAIN), array('class' => 'mb-2'));
        }
        if (!empty($app->education)) {
            echo html_writer::tag('h6', get_string('education', 'local_jobportal') . ':', array('class' => 'mt-2'));
            echo html_writer::tag('p', format_text($app->education, FORMAT_PLAIN), array('class' => 'mb-2'));
        }
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');

        echo html_writer::start_tag('div', array('id' => $resumesectionid, 'class' => 'collapse jp-app-section jp-section-' . (int)$app->id));
        echo html_writer::start_tag('div', array('class' => 'border rounded p-3 mb-3'));
        echo html_writer::tag('h6', get_string('resumereview', 'local_jobportal'), array('class' => 'mt-3'));
        if (!empty($app->resumerating)) {
            echo html_writer::tag('p', html_writer::tag('strong', get_string('resumerating', 'local_jobportal') . ': ') . (int)$app->resumerating . '/5', array('class' => 'mb-1'));
        }
        if (!empty($app->resumefeedback)) {
            echo html_writer::tag('p', html_writer::tag('strong', get_string('resumefeedback', 'local_jobportal') . ': ') . s($app->resumefeedback), array('class' => 'mb-1'));
        }
        if (!empty($app->resumereviewedat)) {
            echo html_writer::tag('p', html_writer::tag('strong', get_string('lastreviewed', 'local_jobportal') . ': ') . userdate($app->resumereviewedat, $dateformat), array('class' => 'mb-2'));
        }

        if (!empty($app->profileid) && $resumedownloadurl) {
            echo html_writer::start_tag('form', array('method' => 'post', 'action' => new moodle_url('/local/jobportal/applications.php'), 'class' => 'mb-3'));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'jobid', 'value' => $jobid));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'page', 'value' => $page));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'appid', 'value' => $app->id));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'updateresumereview'));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
            echo html_writer::start_tag('div', array('class' => 'row'));
            echo html_writer::start_tag('div', array('class' => 'col-md-4 mb-2'));
            echo html_writer::select($resumestatusoptions, 'resumestatus', $resumestatus, false, array('class' => 'custom-select custom-select-sm'));
            echo html_writer::end_tag('div');
            echo html_writer::start_tag('div', array('class' => 'col-md-2 mb-2'));
            echo html_writer::empty_tag('input', array(
                'type' => 'number',
                'name' => 'resumerating',
                'value' => !empty($app->resumerating) ? (int)$app->resumerating : '',
                'class' => 'form-control form-control-sm',
                'min' => 1,
                'max' => 5,
                'step' => 1,
                'placeholder' => '1-5',
            ));
            echo html_writer::end_tag('div');
            echo html_writer::start_tag('div', array('class' => 'col-md-6 mb-2'));
            echo html_writer::tag('textarea', !empty($app->resumefeedback) ? s($app->resumefeedback) : '', array(
                'name' => 'resumefeedback',
                'rows' => 2,
                'class' => 'form-control form-control-sm',
                'placeholder' => get_string('resumefeedbackplaceholder', 'local_jobportal'),
            ));
            echo html_writer::end_tag('div');
            echo html_writer::end_tag('div');
            echo html_writer::tag('button', get_string('updateresumereview', 'local_jobportal'), array('type' => 'submit', 'class' => 'btn btn-outline-primary btn-sm'));
            echo html_writer::end_tag('form');
        } else {
            echo html_writer::tag('p', get_string('resumenotuploaded', 'local_jobportal'), array('class' => 'text-muted mb-2'));
        }

        echo html_writer::tag('h6', get_string('resumereviewhistory', 'local_jobportal'), array('class' => 'mt-2'));
        if (empty($resumehistory)) {
            echo html_writer::tag('p', get_string('noreviewhistory', 'local_jobportal'), array('class' => 'text-muted'));
        } else {
            echo html_writer::start_tag('ul', array('class' => 'list-group list-group-flush mb-3'));
            $historyshown = 0;
            foreach ($resumehistory as $historyitem) {
                $actionkey = 'resumeaction_' . $historyitem->action;
                $actionlabel = get_string_manager()->string_exists($actionkey, 'local_jobportal') ?
                    get_string($actionkey, 'local_jobportal') : format_string($historyitem->action);
                $hstatus = local_jobportal_normalize_resume_status($historyitem->status);
                $hstatuslabel = isset($resumestatusoptions[$hstatus]) ? $resumestatusoptions[$hstatus] : $hstatus;
                $line = userdate($historyitem->timecreated, $dateformat) .
                    ' - ' . s($actionlabel) .
                    ' - ' . s($hstatuslabel) .
                    ' [' . fullname($historyitem) . ']';
                if (!empty($historyitem->rating)) {
                    $line .= ' (' . get_string('resumerating', 'local_jobportal') . ': ' . (int)$historyitem->rating . '/5)';
                }
                if (!empty($historyitem->feedback)) {
                    $line .= ' - ' . s($historyitem->feedback);
                }
                echo html_writer::tag('li', $line, array('class' => 'list-group-item py-1'));
                $historyshown++;
                if ($historyshown >= 5) {
                    break;
                }
            }
            echo html_writer::end_tag('ul');
        }
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');

        echo html_writer::start_tag('div', array('id' => $recruitsectionid, 'class' => 'collapse show jp-app-section jp-section-' . (int)$app->id));
        echo html_writer::start_tag('div', array('class' => 'jp-section-content'));
        echo html_writer::tag('h6', get_string('recruitmentchecklist', 'local_jobportal'), array('class' => 'jp-form-title'));
        if ($shortliststatus !== 'shortlisted') {
            echo html_writer::tag('p', 'ℹ️ ' . get_string('poststageonlyaftershortlist', 'local_jobportal'), array('class' => 'alert alert-info'));
        } else if (empty($activepoststages)) {
            echo html_writer::tag('p', 'ℹ️ ' . get_string('nostagesconfigured', 'local_jobportal'), array('class' => 'alert alert-info'));
        } else {
            echo html_writer::start_tag('ul', array('class' => 'jp-checklist'));
            foreach ($activepoststages as $stageitem) {
                $done = isset($completedstageids[(int)$stageitem->id]);
                $itemclass = $done ? 'jp-checklist-item completed' : 'jp-checklist-item';
                $icon = $done ? '✅' : '⬜';
                $stagelabel = format_string($stageitem->displayname);
                $stageeventcount = !empty($stageeventcounts[(int)$stageitem->id]) ? (int)$stageeventcounts[(int)$stageitem->id] : 0;
                if (!empty($stageitem->hasscheduledate) && $stageeventcount > 0) {
                    $stagelabel .= ' (' . get_string('roundscount', 'local_jobportal', $stageeventcount) . ')';
                }
                if (!empty($stageitem->isinternal)) {
                    $stagelabel .= ' (' . get_string('internalstage', 'local_jobportal') . ')';
                }
                echo html_writer::tag('li',
                    html_writer::span($icon, 'jp-checklist-icon') . $stagelabel,
                    array('class' => $itemclass));
            }
            echo html_writer::end_tag('ul');
        }

        echo html_writer::tag('h6', get_string('stagetimeline', 'local_jobportal'), array('class' => 'jp-form-title mt-4'));
        if (empty($eventsbyapp[$app->id])) {
            echo html_writer::tag('p', 'ℹ️ ' . get_string('nostagehistory', 'local_jobportal'), array('class' => 'alert alert-info'));
        } else {
            echo html_writer::start_tag('div', array('class' => 'jp-timeline'));
            foreach ($eventsbyapp[$app->id] as $event) {
                echo html_writer::start_div('jp-timeline-item');
                echo html_writer::tag('strong', userdate($event->timecreated, $datetimeformat));
                $eventtext = format_string($event->displayname);
                if (!empty($stageroundsbyeventid[(int)$event->id])) {
                    $eventtext .= ' - ' . get_string('roundlabel', 'local_jobportal', (int)$stageroundsbyeventid[(int)$event->id]);
                }
                if (!empty($event->isinternal)) {
                    $eventtext .= ' (' . get_string('internalstage', 'local_jobportal') . ')';
                }
                echo html_writer::tag('div', $eventtext, array('class' => 'mt-1'));
                if (!empty($event->scheduledat)) {
                    echo html_writer::div('📅 ' . get_string('scheduledfor', 'local_jobportal') . ': ' .
                        userdate($event->scheduledat, $datetimeformat), 'text-primary mt-1');
                }
                if (!empty($event->schedulestatus)) {
                    echo html_writer::div(
                        get_string(
                            'schedulestatusvalue',
                            'local_jobportal',
                            local_jobportal_get_schedule_status_label($event->schedulestatus)
                        ),
                        'text-primary mt-1'
                    );
                }
                $eventoutcome = !empty($event->roundoutcome) ? local_jobportal_normalize_round_outcome($event->roundoutcome) : 'pending';
                $eventstatus = !empty($event->schedulestatus) ? local_jobportal_normalize_schedule_status($event->schedulestatus) : 'scheduled';
                if ($eventstatus === 'completed' || $eventoutcome !== 'pending') {
                    echo html_writer::div(
                        get_string(
                            'roundoutcomevalue',
                            'local_jobportal',
                            local_jobportal_get_round_outcome_label($eventoutcome)
                        ),
                        'text-primary mt-1'
                    );
                }
                if (!empty($event->schedulemode)) {
                    echo html_writer::div(
                        get_string(
                            'schedulemodevalue',
                            'local_jobportal',
                            local_jobportal_get_schedule_mode_label($event->schedulemode)
                        ),
                        'text-primary mt-1'
                    );
                }
                if (!empty($event->scheduleduration)) {
                    echo html_writer::div(
                        get_string('scheduledurationvalue', 'local_jobportal', (int)$event->scheduleduration),
                        'text-primary mt-1'
                    );
                }
                if (!empty($event->schedulevenue)) {
                    echo html_writer::div(
                        get_string('schedulevenuevalue', 'local_jobportal', s($event->schedulevenue)),
                        'text-primary mt-1'
                    );
                }
                if (!empty($event->schedulelink)) {
                    $rawlink = trim((string)$event->schedulelink);
                    if (preg_match('#^https?://#i', $rawlink)) {
                        $linkhtml = html_writer::link(
                            $rawlink,
                            s($rawlink),
                            array('target' => '_blank', 'rel' => 'noopener')
                        );
                    } else {
                        $linkhtml = s($rawlink);
                    }
                    echo html_writer::div(
                        get_string('schedulelink', 'local_jobportal') . ': ' . $linkhtml,
                        'text-primary mt-1'
                    );
                }
                if (!empty($event->notes)) {
                    echo html_writer::div(s($event->notes), 'text-muted mt-1');
                }
                echo html_writer::div('[' . fullname($event) . ']', 'text-muted small mt-1');
                echo html_writer::end_div();
            }
            echo html_writer::end_tag('div');
        }

        echo html_writer::start_div('jp-form-card');
        echo html_writer::start_tag('form', array('method' => 'post', 'action' => new moodle_url('/local/jobportal/applications.php')));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'jobid', 'value' => $jobid));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'page', 'value' => $page));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'appid', 'value' => $app->id));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'changeshortlist'));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
        echo html_writer::tag('label', get_string('shortliststatus', 'local_jobportal'), array('class' => 'jp-form-title'));
        echo html_writer::start_div('jp-inline-row mb-3');
        echo html_writer::start_div('jp-inline-col-select');
        echo html_writer::select($shortlisttransitionoptions, 'shortliststatus', '', false, array('class' => 'custom-select jp-select-control'));
        echo html_writer::end_div();
        echo html_writer::start_div('jp-inline-col-note');
        echo html_writer::tag('textarea', '', array(
            'name' => 'shortlistnote',
            'rows' => 2,
            'class' => 'form-control',
            'placeholder' => get_string('shortlistnoteplaceholder', 'local_jobportal'),
        ));
        echo html_writer::end_div();
        echo html_writer::end_div();
        echo html_writer::tag('button', get_string('updateshortliststatus', 'local_jobportal'), array('type' => 'submit', 'class' => 'jp-btn-gradient'));
        echo html_writer::end_tag('form');
        echo html_writer::end_div();

        if ($shortliststatus === 'shortlisted' && $currentstageisterminal) {
            echo html_writer::tag('p', 'ℹ️ ' . get_string('reopenstagehelp', 'local_jobportal'), array('class' => 'alert alert-warning'));
            if (!empty($reopentransitionoptions)) {
                echo html_writer::start_div('jp-form-card');
                echo html_writer::start_tag('form', array('method' => 'post', 'action' => new moodle_url('/local/jobportal/applications.php')));
                echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'jobid', 'value' => $jobid));
                echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'page', 'value' => $page));
                echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'appid', 'value' => $app->id));
                echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'reopenpoststage'));
                echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
                echo html_writer::tag('label', get_string('reopenstage', 'local_jobportal'), array('class' => 'jp-form-title'));
                echo html_writer::start_div('jp-inline-row mb-3');
                echo html_writer::start_div('jp-inline-col-select');
                echo html_writer::select(
                    $reopentransitionoptions,
                    'stageid',
                    '',
                    array('' => get_string('selectstage', 'local_jobportal')),
                    array('class' => 'custom-select jp-select-control')
                );
                echo html_writer::end_div();
                echo html_writer::start_div('jp-inline-col-date');
                echo html_writer::empty_tag('input', array('type' => 'datetime-local', 'name' => 'scheduleddatetime', 'class' => 'form-control'));
                echo html_writer::end_div();
                echo html_writer::start_div('jp-inline-col-select');
                echo local_jobportal_render_select_with_tooltip(
                    $schedulestatusoptions,
                    'schedulestatus',
                    'scheduled',
                    false,
                    array('class' => 'custom-select jp-select-control'),
                    get_string('schedulestatus', 'local_jobportal'),
                    get_string('schedulestatustooltip', 'local_jobportal')
                );
                echo html_writer::end_div();
                echo html_writer::start_div('jp-inline-col-select');
                echo local_jobportal_render_select_with_tooltip(
                    $roundoutcomeoptions,
                    'roundoutcome',
                    'pending',
                    false,
                    array('class' => 'custom-select jp-select-control'),
                    get_string('roundoutcome', 'local_jobportal'),
                    get_string('roundoutcometooltip', 'local_jobportal')
                );
                echo html_writer::end_div();
                echo html_writer::start_div('jp-inline-col-select');
                echo html_writer::select(
                    $schedulemodeoptions,
                    'schedulemode',
                    '',
                    array('' => get_string('schedulemode', 'local_jobportal')),
                    array('class' => 'custom-select jp-select-control')
                );
                echo html_writer::end_div();
                echo html_writer::start_div('jp-inline-col-date');
                echo html_writer::empty_tag('input', array(
                    'type' => 'number',
                    'name' => 'scheduleduration',
                    'class' => 'form-control',
                    'min' => 1,
                    'step' => 5,
                    'placeholder' => get_string('scheduledurationminutes', 'local_jobportal'),
                ));
                echo html_writer::end_div();
                echo html_writer::end_div();
                echo html_writer::start_div('jp-inline-row mb-3');
                echo html_writer::start_div('jp-inline-col-note');
                echo html_writer::empty_tag('input', array(
                    'type' => 'text',
                    'name' => 'schedulelink',
                    'class' => 'form-control',
                    'placeholder' => get_string('schedulelink', 'local_jobportal'),
                ));
                echo html_writer::end_div();
                echo html_writer::start_div('jp-inline-col-note');
                echo html_writer::empty_tag('input', array(
                    'type' => 'text',
                    'name' => 'schedulevenue',
                    'class' => 'form-control',
                    'placeholder' => get_string('schedulevenue', 'local_jobportal'),
                ));
                echo html_writer::end_div();
                echo html_writer::end_div();
                echo html_writer::start_div('jp-inline-row mb-3');
                echo html_writer::start_div('jp-inline-col-note');
                echo html_writer::tag('textarea', '', array(
                    'name' => 'reopennote',
                    'rows' => 2,
                    'class' => 'form-control',
                    'placeholder' => get_string('reopennoteplaceholder', 'local_jobportal'),
                ));
                echo html_writer::end_div();
                echo html_writer::end_div();
                echo html_writer::tag('button', get_string('reopenapplication', 'local_jobportal'), array('type' => 'submit', 'class' => 'jp-btn-gradient'));
                echo html_writer::end_tag('form');
                echo html_writer::end_div();
            }
        } else if ($shortliststatus === 'shortlisted' && !empty($transitionoptions)) {
            echo html_writer::start_div('jp-form-card');
            echo html_writer::start_tag('form', array('method' => 'post', 'action' => new moodle_url('/local/jobportal/applications.php')));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'jobid', 'value' => $jobid));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'page', 'value' => $page));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'appid', 'value' => $app->id));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'changepoststage'));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
            echo html_writer::tag('label', get_string('postshortliststage', 'local_jobportal'), array('class' => 'jp-form-title'));
            echo html_writer::div(get_string('multipleroundshelp', 'local_jobportal'), 'text-muted small mb-2');
            echo html_writer::start_div('jp-inline-row mb-3');
            echo html_writer::start_div('jp-inline-col-select');
            echo html_writer::select(
                $transitionoptions,
                'stageid',
                '',
                array('' => get_string('selectstage', 'local_jobportal')),
                array('class' => 'custom-select jp-select-control')
            );
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-col-date');
            echo html_writer::empty_tag('input', array('type' => 'datetime-local', 'name' => 'scheduleddatetime', 'class' => 'form-control'));
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-col-select');
            echo local_jobportal_render_select_with_tooltip(
                $schedulestatusoptions,
                'schedulestatus',
                'scheduled',
                false,
                array('class' => 'custom-select jp-select-control'),
                get_string('schedulestatus', 'local_jobportal'),
                get_string('schedulestatustooltip', 'local_jobportal')
            );
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-col-select');
            echo local_jobportal_render_select_with_tooltip(
                $roundoutcomeoptions,
                'roundoutcome',
                'pending',
                false,
                array('class' => 'custom-select jp-select-control'),
                get_string('roundoutcome', 'local_jobportal'),
                get_string('roundoutcometooltip', 'local_jobportal')
            );
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-col-select');
            echo html_writer::select(
                $schedulemodeoptions,
                'schedulemode',
                '',
                array('' => get_string('schedulemode', 'local_jobportal')),
                array('class' => 'custom-select jp-select-control')
            );
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-col-date');
            echo html_writer::empty_tag('input', array(
                'type' => 'number',
                'name' => 'scheduleduration',
                'class' => 'form-control',
                'min' => 1,
                'step' => 5,
                'placeholder' => get_string('scheduledurationminutes', 'local_jobportal'),
            ));
            echo html_writer::end_div();
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-row mb-3');
            echo html_writer::start_div('jp-inline-col-note');
            echo html_writer::empty_tag('input', array(
                'type' => 'text',
                'name' => 'schedulelink',
                'class' => 'form-control',
                'placeholder' => get_string('schedulelink', 'local_jobportal'),
            ));
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-col-note');
            echo html_writer::empty_tag('input', array(
                'type' => 'text',
                'name' => 'schedulevenue',
                'class' => 'form-control',
                'placeholder' => get_string('schedulevenue', 'local_jobportal'),
            ));
            echo html_writer::end_div();
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-row mb-3');
            echo html_writer::start_div('jp-inline-col-note');
            echo html_writer::tag('textarea', '', array(
                'name' => 'stagenote',
                'rows' => 2,
                'class' => 'form-control',
                'placeholder' => get_string('stagenoteplaceholder', 'local_jobportal'),
            ));
            echo html_writer::end_div();
            echo html_writer::end_div();
            echo html_writer::tag('button', get_string('updatestage', 'local_jobportal'), array('type' => 'submit', 'class' => 'jp-btn-gradient'));
            echo html_writer::end_tag('form');
            echo html_writer::end_div();
        } else if ($shortliststatus === 'shortlisted') {
            echo html_writer::tag('p', 'ℹ️ ' . get_string('notransitionsavailable', 'local_jobportal'), array('class' => 'alert alert-info'));
        } else if ($shortliststatus !== 'shortlisted') {
            echo html_writer::tag('p', 'ℹ️ ' . get_string('poststageonlyaftershortlist', 'local_jobportal'), array('class' => 'alert alert-info'));
        }

        if ($shortliststatus === 'shortlisted' && (!empty($roundeventopenoptions) || !empty($roundeventclosedoptions))) {
            echo html_writer::start_div('jp-form-card');
            echo html_writer::start_tag('form', array('method' => 'post', 'action' => new moodle_url('/local/jobportal/applications.php')));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'jobid', 'value' => $jobid));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'page', 'value' => $page));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'appid', 'value' => $app->id));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'updateroundevent'));
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
            echo html_writer::tag('label', get_string('updateroundevent', 'local_jobportal'), array('class' => 'jp-form-title'));
            echo html_writer::div(get_string('roundselectorhelp', 'local_jobportal'), 'text-muted small mb-2');
            $roundselectid = 'jp-round-event-select-' . (int)$app->id;
            $roundtoggleid = 'jp-round-show-closed-' . (int)$app->id;
            echo html_writer::start_div('jp-inline-row mb-3');
            echo html_writer::start_div('jp-inline-col-note');
            echo html_writer::select(
                $roundeventopenoptions,
                'eventid',
                '',
                array('' => get_string('selectroundevent', 'local_jobportal')),
                array('class' => 'custom-select jp-select-control', 'id' => $roundselectid)
            );
            echo html_writer::end_div();
            echo html_writer::end_div();
            if (!empty($roundeventclosedoptions)) {
                $closedoptionsjson = json_encode($roundeventclosedoptions);
                if ($closedoptionsjson === false) {
                    $closedoptionsjson = '{}';
                }
                echo html_writer::start_div('mb-3');
                echo html_writer::empty_tag('input', array(
                    'type' => 'checkbox',
                    'id' => $roundtoggleid,
                    'class' => 'mr-2 jp-round-show-closed',
                    'data-target' => $roundselectid,
                    'data-closed-options' => $closedoptionsjson,
                ));
                echo html_writer::tag(
                    'label',
                    get_string('showclosedrounds', 'local_jobportal'),
                    array('for' => $roundtoggleid, 'class' => 'mb-0')
                );
                echo html_writer::end_div();
            }
            echo html_writer::start_div('jp-inline-row mb-3');
            echo html_writer::start_div('jp-inline-col-date');
            echo html_writer::empty_tag('input', array('type' => 'datetime-local', 'name' => 'scheduleddatetime', 'class' => 'form-control'));
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-col-select');
            echo local_jobportal_render_select_with_tooltip(
                $schedulestatusoptions,
                'schedulestatus',
                'scheduled',
                false,
                array('class' => 'custom-select jp-select-control'),
                get_string('schedulestatus', 'local_jobportal'),
                get_string('schedulestatustooltip', 'local_jobportal')
            );
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-col-select');
            echo local_jobportal_render_select_with_tooltip(
                $roundoutcomeoptions,
                'roundoutcome',
                'pending',
                false,
                array('class' => 'custom-select jp-select-control'),
                get_string('roundoutcome', 'local_jobportal'),
                get_string('roundoutcometooltip', 'local_jobportal')
            );
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-col-select');
            echo html_writer::select(
                $schedulemodeoptions,
                'schedulemode',
                '',
                array('' => get_string('schedulemode', 'local_jobportal')),
                array('class' => 'custom-select jp-select-control')
            );
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-col-date');
            echo html_writer::empty_tag('input', array(
                'type' => 'number',
                'name' => 'scheduleduration',
                'class' => 'form-control',
                'min' => 1,
                'step' => 5,
                'placeholder' => get_string('scheduledurationminutes', 'local_jobportal'),
            ));
            echo html_writer::end_div();
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-row mb-3');
            echo html_writer::start_div('jp-inline-col-note');
            echo html_writer::empty_tag('input', array(
                'type' => 'text',
                'name' => 'schedulelink',
                'class' => 'form-control',
                'placeholder' => get_string('schedulelink', 'local_jobportal'),
            ));
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-col-note');
            echo html_writer::empty_tag('input', array(
                'type' => 'text',
                'name' => 'schedulevenue',
                'class' => 'form-control',
                'placeholder' => get_string('schedulevenue', 'local_jobportal'),
            ));
            echo html_writer::end_div();
            echo html_writer::end_div();
            echo html_writer::start_div('jp-inline-row mb-3');
            echo html_writer::start_div('jp-inline-col-note');
            echo html_writer::tag('textarea', '', array(
                'name' => 'roundnote',
                'rows' => 2,
                'class' => 'form-control',
                'placeholder' => get_string('roundnoteplaceholder', 'local_jobportal'),
            ));
            echo html_writer::end_div();
            echo html_writer::end_div();
            echo html_writer::tag('button', get_string('updateroundevent', 'local_jobportal'), array('type' => 'submit', 'class' => 'jp-btn-gradient'));
            echo html_writer::end_tag('form');
            echo html_writer::end_div();
        } else if ($shortliststatus === 'shortlisted') {
            echo html_writer::tag('p', 'ℹ️ ' . get_string('noroundeventsavailable', 'local_jobportal'), array('class' => 'alert alert-info'));
        }
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');

        echo html_writer::start_tag('div', array('id' => $notessectionid, 'class' => 'collapse jp-app-section jp-section-' . (int)$app->id));
        echo html_writer::start_tag('div', array('class' => 'jp-section-content'));
        echo html_writer::tag('h6', get_string('recruiternotes', 'local_jobportal'), array('class' => 'jp-form-title'));
        if (empty($notesbyapp[$app->id])) {
            echo html_writer::tag('p', 'ℹ️ ' . get_string('nonotesyet', 'local_jobportal'), array('class' => 'alert alert-info'));
        } else {
            foreach ($notesbyapp[$app->id] as $note) {
                echo html_writer::start_div('jp-note-item');
                echo html_writer::div(
                    userdate($note->timecreated, $dateformat) . ' - ' . fullname($note),
                    'jp-note-meta'
                );
                echo html_writer::div(s($note->note), 'jp-note-text');
                echo html_writer::end_div();
            }
        }

        echo html_writer::start_div('jp-form-card');
        echo html_writer::start_tag('form', array('method' => 'post', 'action' => new moodle_url('/local/jobportal/applications.php')));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'jobid', 'value' => $jobid));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'page', 'value' => $page));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'appid', 'value' => $app->id));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'addnote'));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
        echo html_writer::tag('textarea', '', array(
            'name' => 'note',
            'rows' => 2,
            'class' => 'form-control mb-3',
            'placeholder' => get_string('addnoteplaceholder', 'local_jobportal'),
        ));
        echo html_writer::tag('button', '➕ ' . get_string('addnote', 'local_jobportal'), array('type' => 'submit', 'class' => 'jp-btn-gradient'));
        echo html_writer::end_tag('form');
        echo html_writer::end_div();
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');

        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');
    }

    if ($filteredapplicationscount > $perpage) {
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
