<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/locallib.php');

/**
 * Company profile form.
 */
class company_profile_form extends moodleform {
    /**
     * Define form fields.
     */
    public function definition() {
        $mform = $this->_form;
        $companyid = isset($this->_customdata['companyid']) ? (int)$this->_customdata['companyid'] : 0;

        $mform->addElement(
            'header',
            'companyprofileheader',
            $companyid ? get_string('editcompanyprofile', 'local_jobportal') : get_string('addcompanyprofile', 'local_jobportal')
        );

        $mform->addElement('text', 'name', get_string('companyname', 'local_jobportal'), 'size="60"');
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $mform->addElement(
            'textarea',
            'description',
            get_string('companydescription', 'local_jobportal'),
            'wrap="virtual" rows="8" cols="60"'
        );
        $mform->setType('description', PARAM_TEXT);

        $mform->addElement('text', 'website', get_string('companywebsite', 'local_jobportal'), 'size="60"');
        $mform->setType('website', PARAM_URL);

        $mform->addElement(
            'filemanager',
            'logo',
            get_string('companylogo', 'local_jobportal'),
            null,
            array(
                'subdirs' => 0,
                'maxbytes' => 2097152,
                'maxfiles' => 1,
                'accepted_types' => array('web_image'),
            )
        );

        $mform->addElement('hidden', 'id', $companyid);
        $mform->setType('id', PARAM_INT);

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Validate form values.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (!empty($data['website']) && !filter_var($data['website'], FILTER_VALIDATE_URL)) {
            $errors['website'] = get_string('invalidurl', 'local_jobportal');
        }

        $duplicate = local_jobportal_find_company_by_name($data['name'], !empty($data['id']) ? (int)$data['id'] : 0);
        if ($duplicate) {
            $errors['name'] = get_string('error:companyexists', 'local_jobportal');
        }

        return $errors;
    }
}

require_login();

$id = optional_param('id', 0, PARAM_INT);
$companypage = optional_param('companypage', 0, PARAM_INT);
if ($companypage < 0) {
    $companypage = 0;
}
$companiesperpage = 15;

$context = context_system::instance();
require_capability('local/jobportal:managecompanyprofile', $context);

$company = null;
if ($id) {
    $company = $DB->get_record('local_jobportal_companies', array('id' => $id), '*', MUST_EXIST);
}

$PAGE->set_context($context);
$urlparams = array('id' => $id);
if (!empty($companypage)) {
    $urlparams['companypage'] = $companypage;
}
$PAGE->set_url(new moodle_url('/local/jobportal/companyprofile.php', $urlparams));
$PAGE->set_title(get_string('managecompanies', 'local_jobportal'));
$PAGE->set_heading(get_string('managecompanies', 'local_jobportal'));
local_jobportal_require_styles();

$mform = new company_profile_form(null, array('companyid' => $id));

if ($company) {
    $draftitemid = file_get_submitted_draft_itemid('logo');
    file_prepare_draft_area(
        $draftitemid,
        $context->id,
        'local_jobportal',
        'company_logo',
        $company->id,
        array('subdirs' => 0, 'maxfiles' => 1)
    );
    $company->logo = $draftitemid;
    $mform->set_data($company);
}

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/local/jobportal/index.php'));
} else if ($data = $mform->get_data()) {
    $record = new stdClass();
    $record->name = trim($data->name);
    $record->description = $data->description;
    $record->website = !empty($data->website) ? $data->website : null;
    $record->timemodified = time();

    if (!empty($data->id)) {
        $current = $DB->get_record('local_jobportal_companies', array('id' => (int)$data->id), '*', MUST_EXIST);
        $record->id = $current->id;
        $record->userid = $current->userid;
        $DB->update_record('local_jobportal_companies', $record);
        $companyid = $current->id;
        $message = get_string('companyprofileupdated', 'local_jobportal');
    } else {
        $record->userid = $USER->id;
        $record->timecreated = time();
        $companyid = $DB->insert_record('local_jobportal_companies', $record);
        $message = get_string('companyprofilecreated', 'local_jobportal');
    }

    file_save_draft_area_files(
        $data->logo,
        $context->id,
        'local_jobportal',
        'company_logo',
        $companyid,
        array(
            'subdirs' => 0,
            'maxfiles' => 1,
            'accepted_types' => array('web_image'),
        )
    );

    redirect(
        new moodle_url('/local/jobportal/companyprofile.php', array('id' => $companyid)),
        $message,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo local_jobportal_render_navigation($context, 'companies');

echo html_writer::start_tag('div', array('class' => 'jp-page-hero mb-4'));
echo html_writer::start_tag('div', array('class' => 'jp-hero-content container-fluid py-4'));
echo html_writer::tag('h2', get_string('managecompanies', 'local_jobportal'), array('class' => 'text-white font-weight-bold mb-0'));
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', array('class' => 'container-fluid'));
echo html_writer::start_tag('div', array('class' => 'row mb-4'));

// Form section (Left)
echo html_writer::start_tag('div', array('class' => 'col-lg-8'));
echo html_writer::start_tag('div', array('class' => 'card jp-form-section border-0 shadow-sm mb-4'));
echo html_writer::start_tag('div', array('class' => 'card-body p-4'));

echo html_writer::start_tag('div', array('class' => 'd-flex justify-content-between align-items-center mb-3 border-bottom pb-3'));
echo html_writer::tag('h5', '🏢 ' . ($company ? get_string('editcompanyprofile', 'local_jobportal') : get_string('addcompanyprofile', 'local_jobportal')), array('class' => 'card-title font-weight-bold mb-0'));
echo html_writer::link(
    new moodle_url('/local/jobportal/companyprofile.php'),
    '➕ ' . get_string('addcompanyprofile', 'local_jobportal'),
    array('class' => 'btn btn-outline-primary btn-sm jp-action-pill')
);
echo html_writer::end_tag('div');

$mform->display();

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');
echo html_writer::end_tag('div'); // col-lg-8

// Company Stats Preview (Right)
echo html_writer::start_tag('div', array('class' => 'col-lg-4'));
if ($company) {
    $stats = local_jobportal_get_company_stats($company->id);

    echo html_writer::start_tag('div', array('class' => 'card jp-card border-0 shadow-sm mb-4 h-100'));
    echo html_writer::start_tag('div', array('class' => 'card-body p-4'));
    echo html_writer::tag('h5', format_string($company->name), array('class' => 'card-title font-weight-bold mb-3'));
    echo html_writer::tag('p', get_string('companyprofilesetup', 'local_jobportal'), array('class' => 'text-muted small mb-4'));
    
    echo html_writer::start_tag('div', array('class' => 'd-flex justify-content-between mb-3 border-bottom pb-2'));
    echo html_writer::tag('span', get_string('jobsposted', 'local_jobportal'), array('class' => 'text-muted'));
    echo html_writer::tag('span', (int)$stats->jobsposted, array('class' => 'font-weight-bold'));
    echo html_writer::end_tag('div');
    
    echo html_writer::start_tag('div', array('class' => 'd-flex justify-content-between mb-3 border-bottom pb-2'));
    echo html_writer::tag('span', get_string('activejobs', 'local_jobportal'), array('class' => 'text-muted'));
    echo html_writer::tag('span', (int)$stats->activejobs, array('class' => 'font-weight-bold text-success'));
    echo html_writer::end_tag('div');
    
    echo html_writer::start_tag('div', array('class' => 'd-flex justify-content-between mb-4'));
    echo html_writer::tag('span', get_string('applicationsreceived', 'local_jobportal'), array('class' => 'text-muted'));
    echo html_writer::tag('span', (int)$stats->applicationsreceived, array('class' => 'font-weight-bold text-primary'));
    echo html_writer::end_tag('div');

    echo html_writer::link(new moodle_url('/local/jobportal/company.php', array('id' => $company->id)), '👁️ ' . get_string('viewcompanyprofile', 'local_jobportal'), array('class' => 'btn btn-outline-secondary w-100 jp-action-pill'));
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
}
echo html_writer::end_tag('div'); // col-lg-4

echo html_writer::end_tag('div'); // row

// Companies List Table
$totalcompanies = (int)$DB->count_records('local_jobportal_companies');
$companies = $DB->get_records('local_jobportal_companies', null, 'name ASC', '*', $companypage * $companiesperpage, $companiesperpage);

echo html_writer::start_tag('div', array('class' => 'card jp-card border-0 shadow-sm mb-4'));
echo html_writer::start_tag('div', array('class' => 'card-body p-4'));
echo html_writer::tag('h5', '📋 ' . get_string('companies', 'local_jobportal') . ' (' . $totalcompanies . ')', array('class' => 'card-title font-weight-bold mb-4'));

if (empty($companies)) {
    echo html_writer::tag('p', get_string('nocompanies', 'local_jobportal'), array('class' => 'alert alert-info'));
} else {
    $pagingparams = array();
    if (!empty($id)) {
        $pagingparams['id'] = $id;
    }
    $pagingurl = new moodle_url('/local/jobportal/companyprofile.php', $pagingparams);
    if ($totalcompanies > $companiesperpage) {
        echo html_writer::start_div('mb-3');
        echo $OUTPUT->paging_bar($totalcompanies, $companypage, $companiesperpage, $pagingurl, 'companypage');
        echo html_writer::end_div();
    }

    $table = new html_table();
    $table->head = array(
        get_string('companyname', 'local_jobportal'),
        get_string('jobsposted', 'local_jobportal'),
        get_string('applicationsreceived', 'local_jobportal'),
        get_string('actions'),
    );
    $table->attributes['class'] = 'table table-sm table-striped table-bordered jp-table jp-data-table jp-companies-table w-100';

    foreach ($companies as $item) {
        $stats = local_jobportal_get_company_stats($item->id);
        $actions = array();
        $actions[] = html_writer::link(new moodle_url('/local/jobportal/company.php', array('id' => $item->id)), get_string('view'));
        $actions[] = html_writer::link(new moodle_url('/local/jobportal/companyprofile.php', array('id' => $item->id)), get_string('edit'));
        $actions[] = html_writer::link(new moodle_url('/local/jobportal/post.php', array('companyid' => $item->id)), get_string('postjob', 'local_jobportal'));

        $table->data[] = array(
            html_writer::tag('span', format_string($item->name), array('class' => 'font-weight-bold text-dark')),
            html_writer::tag('span', $stats->jobsposted, array('class' => 'badge badge-secondary px-2 py-1')),
            html_writer::tag('span', $stats->applicationsreceived, array('class' => 'badge badge-info px-2 py-1')),
            implode(' <span class="text-muted mx-1">|</span> ', $actions),
        );
    }

    echo html_writer::start_div('table-responsive');
    echo html_writer::table($table);
    echo html_writer::end_div();

    if ($totalcompanies > $companiesperpage) {
        echo html_writer::start_div('mt-3');
        echo $OUTPUT->paging_bar($totalcompanies, $companypage, $companiesperpage, $pagingurl, 'companypage');
        echo html_writer::end_div();
    }
}

echo html_writer::end_tag('div'); // card-body
echo html_writer::end_tag('div'); // card

echo html_writer::end_tag('div'); // container-fluid

echo $OUTPUT->footer();
