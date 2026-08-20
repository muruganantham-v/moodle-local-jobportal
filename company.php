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

// Hero Header
echo html_writer::start_tag('div', array('class' => 'jp-page-hero mb-4'));
echo html_writer::start_tag('div', array('class' => 'jp-hero-content container-fluid py-4'));
echo html_writer::start_tag('div', array('class' => 'd-flex align-items-center'));

if ($logo) {
    echo html_writer::empty_tag('img', array(
        'src' => $logo->out(false),
        'alt' => format_string($company->name),
        'class' => 'img-thumbnail rounded-circle shadow-sm mr-4 bg-white',
        'style' => 'width: 100px; height: 100px; object-fit: contain;',
    ));
}

echo html_writer::start_tag('div');
echo html_writer::tag('h2', format_string($company->name), array('class' => 'text-white mb-1 font-weight-bold'));
if (!empty($company->website)) {
    if (filter_var($company->website, FILTER_VALIDATE_URL)) {
        $companyurl = new moodle_url($company->website);
        echo html_writer::link($companyurl, '🌐 ' . s($company->website), array('target' => '_blank', 'rel' => 'noopener', 'class' => 'text-white-50'));
    } else {
        echo html_writer::tag('span', '🌐 ' . s($company->website), array('class' => 'text-white-50'));
    }
}
echo html_writer::end_tag('div');

if ($canedit) {
    echo html_writer::start_tag('div', array('class' => 'ml-auto'));
    echo html_writer::link(
        new moodle_url('/local/jobportal/companyprofile.php', array('id' => $company->id)),
        get_string('editcompanyprofile', 'local_jobportal'),
        array('class' => 'btn btn-light font-weight-bold shadow-sm jp-action-pill')
    );
    echo html_writer::end_tag('div');
}
echo html_writer::end_tag('div'); // d-flex
echo html_writer::end_tag('div'); // jp-hero-content
echo html_writer::end_tag('div'); // jp-page-hero

echo html_writer::start_tag('div', array('class' => 'container-fluid'));
echo html_writer::start_tag('div', array('class' => 'row'));

// Left Column - About Company & Open Jobs
echo html_writer::start_tag('div', array('class' => 'col-lg-8 mb-4'));

// About Card
echo html_writer::start_tag('div', array('class' => 'card jp-card border-0 shadow-sm mb-4 h-100'));
echo html_writer::start_tag('div', array('class' => 'card-body p-4'));
echo html_writer::tag('h5', get_string('aboutcompany', 'local_jobportal'), array('class' => 'card-title font-weight-bold mb-3'));
if (!empty($company->description)) {
    echo html_writer::tag('p', format_text($company->description, FORMAT_PLAIN), array('class' => 'card-text text-muted'));
} else {
    echo html_writer::tag('p', get_string('nocompanydescription', 'local_jobportal'), array('class' => 'text-muted font-italic'));
}
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

// Open Positions Card
echo html_writer::start_tag('div', array('class' => 'card jp-card border-0 shadow-sm'));
echo html_writer::start_tag('div', array('class' => 'card-body p-4'));
echo html_writer::tag('h5', '🚀 ' . get_string('openpositions', 'local_jobportal'), array('class' => 'card-title font-weight-bold mb-3'));
if (empty($jobs)) {
    echo html_writer::tag('p', get_string('nocompanyjobs', 'local_jobportal'), array('class' => 'text-muted mb-0'));
} else {
    echo html_writer::start_tag('div', array('class' => 'list-group list-group-flush'));
    foreach ($jobs as $job) {
        $joburl = new moodle_url('/local/jobportal/view.php', array('id' => $job->id));
        echo html_writer::link(
            $joburl,
            html_writer::tag('div', format_string($job->title), array('class' => 'font-weight-bold text-dark')),
            array('class' => 'list-group-item list-group-item-action border-0 px-0 py-3 d-flex align-items-center')
        );
    }
    echo html_writer::end_tag('div');
}
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

echo html_writer::end_tag('div'); // col-lg-8

// Right Column - Stats
echo html_writer::start_tag('div', array('class' => 'col-lg-4 mb-4'));
if ($canviewcompanystats) {
    echo html_writer::start_tag('div', array('class' => 'card jp-card border-0 shadow-sm'));
    echo html_writer::start_tag('div', array('class' => 'card-body p-4'));
    echo html_writer::tag('h5', '📊 ' . get_string('companystats', 'local_jobportal'), array('class' => 'card-title font-weight-bold mb-4'));
    
    echo html_writer::start_tag('div', array('class' => 'd-flex justify-content-between mb-3 border-bottom pb-2'));
    echo html_writer::tag('span', get_string('jobsposted', 'local_jobportal'), array('class' => 'text-muted'));
    echo html_writer::tag('span', (int)$stats->jobsposted, array('class' => 'font-weight-bold'));
    echo html_writer::end_tag('div');
    
    echo html_writer::start_tag('div', array('class' => 'd-flex justify-content-between mb-3 border-bottom pb-2'));
    echo html_writer::tag('span', get_string('activejobs', 'local_jobportal'), array('class' => 'text-muted'));
    echo html_writer::tag('span', (int)$stats->activejobs, array('class' => 'font-weight-bold text-success'));
    echo html_writer::end_tag('div');
    
    echo html_writer::start_tag('div', array('class' => 'd-flex justify-content-between'));
    echo html_writer::tag('span', get_string('applicationsreceived', 'local_jobportal'), array('class' => 'text-muted'));
    echo html_writer::tag('span', (int)$stats->applicationsreceived, array('class' => 'font-weight-bold text-primary'));
    echo html_writer::end_tag('div');
    
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
}
echo html_writer::end_tag('div'); // col-lg-4

echo html_writer::end_tag('div'); // row
echo html_writer::end_tag('div'); // container-fluid

echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/jobportal/index.php'),
        '← ' . get_string('alljobs', 'local_jobportal'),
        array('class' => 'btn btn-outline-secondary jp-action-pill mt-3 ml-3')
    ),
    'mb-4'
);

echo $OUTPUT->footer();
