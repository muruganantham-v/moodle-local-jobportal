<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');
require_once(__DIR__ . '/locallib.php');

require_login();

$id = required_param('id', PARAM_INT);

$context = context_system::instance();
require_capability('local/jobportal:viewjobs', $context);

$company = $DB->get_record('local_jobportal_companies', array('id' => $id), '*', MUST_EXIST);
$stats = local_jobportal_get_company_stats($company->id);
$logo = local_jobportal_get_company_logo_url($company->id, $context);
$jobs = $DB->get_records(
    'local_jobportal_jobs',
    array('companyid' => $company->id, 'status' => 1),
    'timecreated DESC',
    '*',
    0,
    5
);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/jobportal/company.php', array('id' => $id)));
$PAGE->set_title(format_string($company->name));
$PAGE->set_heading(format_string($company->name));
local_jobportal_require_styles();

$canedit = has_capability('local/jobportal:managecompanyprofile', $context);
$canviewcompanystats = has_capability('local/jobportal:managejobs', $context);

echo $OUTPUT->header();
echo local_jobportal_render_navigation(
    $context,
    'company',
    array(
        array(
            'key' => 'company',
            'label' => get_string('viewcompanyprofile', 'local_jobportal'),
            'url' => new moodle_url('/local/jobportal/company.php', array('id' => $company->id)),
        ),
    )
);

echo html_writer::start_tag('div', array('class' => 'company-profile'));

echo html_writer::start_tag('div', array('class' => 'card mb-4'));
echo html_writer::start_tag('div', array('class' => 'card-body'));

if ($logo) {
    echo html_writer::empty_tag('img', array(
        'src' => $logo->out(false),
        'alt' => format_string($company->name),
        'class' => 'img-thumbnail mb-3',
        'style' => 'max-width: 160px; height: auto;',
    ));
}

echo html_writer::tag('h2', format_string($company->name), array('class' => 'card-title'));

if (!empty($company->website)) {
    if (filter_var($company->website, FILTER_VALIDATE_URL)) {
        $companyurl = new moodle_url($company->website);
        echo html_writer::tag(
            'p',
            html_writer::link($companyurl, s($company->website), array('target' => '_blank', 'rel' => 'noopener')),
            array('class' => 'mb-3')
        );
    } else {
        echo html_writer::tag('p', s($company->website), array('class' => 'mb-3'));
    }
}

if (!empty($company->description)) {
    echo html_writer::tag('p', format_text($company->description, FORMAT_PLAIN), array('class' => 'card-text'));
} else {
    echo html_writer::tag('p', get_string('nocompanydescription', 'local_jobportal'), array('class' => 'text-muted'));
}

if ($canedit) {
    echo html_writer::link(
        new moodle_url('/local/jobportal/companyprofile.php', array('id' => $company->id)),
        get_string('editcompanyprofile', 'local_jobportal'),
        array('class' => 'btn btn-warning mt-2')
    );
}

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

if ($canviewcompanystats) {
    echo html_writer::start_tag('div', array('class' => 'card mb-4'));
    echo html_writer::start_tag('div', array('class' => 'card-body'));
    echo html_writer::tag('h5', get_string('companystats', 'local_jobportal'), array('class' => 'card-title'));
    echo html_writer::start_tag('ul', array('class' => 'list-group list-group-flush'));
    echo html_writer::tag(
        'li',
        get_string('jobsposted', 'local_jobportal') . ': ' . $stats->jobsposted,
        array('class' => 'list-group-item')
    );
    echo html_writer::tag(
        'li',
        get_string('activejobs', 'local_jobportal') . ': ' . $stats->activejobs,
        array('class' => 'list-group-item')
    );
    echo html_writer::tag(
        'li',
        get_string('applicationsreceived', 'local_jobportal') . ': ' . $stats->applicationsreceived,
        array('class' => 'list-group-item')
    );
    echo html_writer::end_tag('ul');
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
}

echo html_writer::start_tag('div', array('class' => 'card mb-3'));
echo html_writer::start_tag('div', array('class' => 'card-body'));
echo html_writer::tag('h5', get_string('openpositions', 'local_jobportal'), array('class' => 'card-title'));

if (empty($jobs)) {
    echo html_writer::tag('p', get_string('nocompanyjobs', 'local_jobportal'), array('class' => 'text-muted mb-0'));
} else {
    echo html_writer::start_tag('ul', array('class' => 'list-group list-group-flush'));
    foreach ($jobs as $job) {
        echo html_writer::tag(
            'li',
            html_writer::link(
                new moodle_url('/local/jobportal/view.php', array('id' => $job->id)),
                format_string($job->title)
            ),
            array('class' => 'list-group-item')
        );
    }
    echo html_writer::end_tag('ul');
}

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

echo html_writer::end_tag('div');

echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/jobportal/index.php'),
        '← ' . get_string('alljobs', 'local_jobportal')
    ),
    'mt-3'
);

echo $OUTPUT->footer();
