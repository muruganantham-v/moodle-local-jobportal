<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');
require_once(__DIR__ . '/locallib.php');

require_login();

$context = context_system::instance();
require_capability('local/jobportal:managejobs', $context);

local_jobportal_ensure_default_stages();
$baseurl = new moodle_url('/local/jobportal/stages.php');

if (optional_param('save', 0, PARAM_BOOL) && confirm_sesskey()) {
    $internalvalues = optional_param_array('internal', array(), PARAM_INT);
    $stages = local_jobportal_get_recruitment_stages(false);
    $updated = 0;

    foreach ($stages as $stage) {
        $newinternal = isset($internalvalues[$stage->id]) ? 1 : 0;
        if ((int)$stage->isinternal === $newinternal) {
            continue;
        }

        $record = new stdClass();
        $record->id = (int)$stage->id;
        $record->isinternal = $newinternal;
        $record->timemodified = time();
        $DB->update_record('local_jobportal_stages', $record);
        $updated++;
    }

    redirect(
        $baseurl,
        get_string('stagesupdated', 'local_jobportal', $updated),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$stages = local_jobportal_get_recruitment_stages(false);

$PAGE->set_context($context);
$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('managestages', 'local_jobportal'));
$PAGE->set_heading(get_string('managestages', 'local_jobportal'));
local_jobportal_require_styles();

echo $OUTPUT->header();
echo local_jobportal_render_navigation($context, 'stages');

echo html_writer::start_tag('div', array('class' => 'card mb-3'));
echo html_writer::start_tag('div', array('class' => 'card-body'));
echo html_writer::tag('h4', get_string('managestages', 'local_jobportal'), array('class' => 'card-title'));
echo html_writer::tag('p', get_string('managestagesdesc', 'local_jobportal'), array('class' => 'text-muted mb-3'));

if (empty($stages)) {
    echo html_writer::tag('p', get_string('nostagesconfigured', 'local_jobportal'), array('class' => 'alert alert-info mb-0'));
} else {
    echo html_writer::start_tag('form', array('method' => 'post', 'action' => $baseurl));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'save', 'value' => 1));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));

    echo html_writer::start_tag('div', array('class' => 'table-responsive'));
    echo html_writer::start_tag('table', array('class' => 'table table-sm table-striped table-bordered jp-table jp-data-table jp-stages-table'));
    echo html_writer::start_tag('thead');
    echo html_writer::tag(
        'tr',
        html_writer::tag('th', get_string('stage', 'local_jobportal')) .
        html_writer::tag('th', get_string('status', 'local_jobportal')) .
        html_writer::tag('th', get_string('sortorder', 'local_jobportal')) .
        html_writer::tag('th', get_string('internalstage', 'local_jobportal'))
    );
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($stages as $stage) {
        $stagebadge = ((int)$stage->isactive === 1) ?
            html_writer::tag('span', get_string('active', 'local_jobportal'), array('class' => 'badge badge-success')) :
            html_writer::tag('span', get_string('inactive', 'local_jobportal'), array('class' => 'badge badge-secondary'));

        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', format_string($stage->displayname) . html_writer::tag('div', s($stage->shortname), array('class' => 'small text-muted')));
        echo html_writer::tag('td', $stagebadge);
        echo html_writer::tag('td', (int)$stage->sortorder);
        echo html_writer::tag(
            'td',
            html_writer::checkbox(
                'internal[' . (int)$stage->id . ']',
                1,
                !empty($stage->isinternal),
                '',
                array('class' => 'mr-2')
            )
        );
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_tag('div');

    echo html_writer::tag('button', get_string('savestagevisibility', 'local_jobportal'), array('type' => 'submit', 'class' => 'btn btn-primary'));
    echo html_writer::end_tag('form');
}

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

echo $OUTPUT->footer();
