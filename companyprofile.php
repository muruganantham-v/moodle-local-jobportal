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

echo html_writer::start_tag('div', array('class' => 'mb-3'));
echo html_writer::link(
    new moodle_url('/local/jobportal/companyprofile.php'),
    get_string('addcompanyprofile', 'local_jobportal'),
    array('class' => 'btn btn-primary')
);
echo html_writer::end_tag('div');

if ($company) {
    $stats = local_jobportal_get_company_stats($company->id);

    echo html_writer::start_tag('div', array('class' => 'card mb-4'));
    echo html_writer::start_tag('div', array('class' => 'card-body'));
    echo html_writer::tag('h5', format_string($company->name), array('class' => 'card-title'));
    echo html_writer::tag('p', get_string('companyprofilesetup', 'local_jobportal'), array('class' => 'text-muted mb-2'));
    echo html_writer::start_tag('ul', array('class' => 'list-group list-group-flush'));
    echo html_writer::tag('li', get_string('jobsposted', 'local_jobportal') . ': ' . $stats->jobsposted, array('class' => 'list-group-item'));
    echo html_writer::tag('li', get_string('activejobs', 'local_jobportal') . ': ' . $stats->activejobs, array('class' => 'list-group-item'));
    echo html_writer::tag('li', get_string('applicationsreceived', 'local_jobportal') . ': ' . $stats->applicationsreceived, array('class' => 'list-group-item'));
    echo html_writer::end_tag('ul');
    echo html_writer::div(
        html_writer::link(new moodle_url('/local/jobportal/company.php', array('id' => $company->id)), get_string('viewcompanyprofile', 'local_jobportal')),
        'mt-3'
    );
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
}

$mform->display();

$totalcompanies = (int)$DB->count_records('local_jobportal_companies');
$companies = $DB->get_records('local_jobportal_companies', null, 'name ASC', '*', $companypage * $companiesperpage, $companiesperpage);
echo html_writer::tag('h4', get_string('companies', 'local_jobportal'), array('class' => 'mt-4'));

if (empty($companies)) {
    echo html_writer::tag('p', get_string('nocompanies', 'local_jobportal'), array('class' => 'alert alert-info'));
} else {
    $pagingparams = array();
    if (!empty($id)) {
        $pagingparams['id'] = $id;
    }
    $pagingurl = new moodle_url('/local/jobportal/companyprofile.php', $pagingparams);
    if ($totalcompanies > $companiesperpage) {
        echo $OUTPUT->paging_bar($totalcompanies, $companypage, $companiesperpage, $pagingurl, 'companypage');
    }

    $table = new html_table();
    $table->head = array(
        get_string('companyname', 'local_jobportal'),
        get_string('jobsposted', 'local_jobportal'),
        get_string('applicationsreceived', 'local_jobportal'),
        get_string('actions'),
    );
    $table->attributes['class'] = 'table table-sm table-striped table-bordered jp-table jp-data-table jp-companies-table';

    foreach ($companies as $item) {
        $stats = local_jobportal_get_company_stats($item->id);
        $actions = array();
        $actions[] = html_writer::link(new moodle_url('/local/jobportal/company.php', array('id' => $item->id)), get_string('view'));
        $actions[] = html_writer::link(new moodle_url('/local/jobportal/companyprofile.php', array('id' => $item->id)), get_string('edit'));
        $actions[] = html_writer::link(new moodle_url('/local/jobportal/post.php', array('companyid' => $item->id)), get_string('postjob', 'local_jobportal'));

        $table->data[] = array(
            format_string($item->name),
            $stats->jobsposted,
            $stats->applicationsreceived,
            implode(' | ', $actions),
        );
    }

    echo html_writer::table($table);

    if ($totalcompanies > $companiesperpage) {
        echo $OUTPUT->paging_bar($totalcompanies, $companypage, $companiesperpage, $pagingurl, 'companypage');
    }
}

echo $OUTPUT->footer();
