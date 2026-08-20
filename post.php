<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/locallib.php');

/**
 * Job posting form.
 */
class job_post_form extends moodleform {
    /**
     * Define form fields.
     */
    public function definition() {
        $mform = $this->_form;
        $companyoptions = isset($this->_customdata['companyoptions']) ? $this->_customdata['companyoptions'] : array();
        $companyprofileurl = isset($this->_customdata['companyprofileurl']) ? $this->_customdata['companyprofileurl'] : null;
        $headerlabel = isset($this->_customdata['headerlabel']) ? $this->_customdata['headerlabel'] : get_string('postjob', 'local_jobportal');

        $mform->addElement('header', 'jobheader', $headerlabel);

        // Job title.
        $mform->addElement('text', 'title', get_string('jobtitle', 'local_jobportal'), 'size="50"');
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');

        // Company picker.
        $mform->addElement(
            'autocomplete',
            'companyid',
            get_string('company', 'local_jobportal'),
            $companyoptions,
            array(
                'noselectionstring' => get_string('selectcompany', 'local_jobportal'),
                'multiple' => false,
            )
        );
        $mform->setType('companyid', PARAM_INT);
        $mform->setDefault('companyid', '');
        $mform->addRule('companyid', null, 'required', null, 'client');
        if ($companyprofileurl) {
            $mform->addElement(
                'static',
                'companyhelp',
                '',
                get_string('companyselectorhelp', 'local_jobportal', $companyprofileurl->out(false))
            );
        }

        // Description.
        $mform->addElement(
            'editor',
            'description',
            get_string('description', 'local_jobportal'),
            null,
            array('maxfiles' => 0)
        );
        $mform->setType('description', PARAM_RAW);
        $mform->addRule('description', null, 'required', null, 'client');

        // Location.
        $mform->addElement('text', 'location', get_string('location', 'local_jobportal'), 'size="50"');
        $mform->setType('location', PARAM_TEXT);

        // Job type.
        $jobtypes = array(
            'fulltime' => get_string('fulltime', 'local_jobportal'),
            'parttime' => get_string('parttime', 'local_jobportal'),
            'internship' => get_string('internship', 'local_jobportal'),
            'contract' => get_string('contract', 'local_jobportal'),
            'freelance' => get_string('freelance', 'local_jobportal'),
        );
        $mform->addElement('select', 'jobtype', get_string('jobtype', 'local_jobportal'), $jobtypes);
        $mform->addRule('jobtype', null, 'required', null, 'client');

        // Structured salary details.
        $salarymodels = local_jobportal_get_salary_model_options();
        $salaryperiods = local_jobportal_get_salary_period_options();
        $mform->addElement('select', 'salarymodel', get_string('salarymodel', 'local_jobportal'), $salarymodels);
        $mform->setDefault('salarymodel', 'fixed');

        $mform->addElement('text', 'salarycurrency', get_string('salarycurrency', 'local_jobportal'), 'size="12"');
        $mform->setType('salarycurrency', PARAM_ALPHANUMEXT);
        $mform->setDefault('salarycurrency', 'INR');

        $mform->addElement('select', 'salaryperiod', get_string('salaryperiod', 'local_jobportal'), $salaryperiods);
        $mform->setDefault('salaryperiod', 'annual');

        $mform->addElement('text', 'salaryfixedamount', get_string('salaryfixedamount', 'local_jobportal'), 'size="20"');
        $mform->setType('salaryfixedamount', PARAM_RAW_TRIMMED);

        $mform->addElement('text', 'salaryminamount', get_string('salarymin', 'local_jobportal'), 'size="20"');
        $mform->setType('salaryminamount', PARAM_RAW_TRIMMED);

        $mform->addElement('text', 'salarymaxamount', get_string('salarymax', 'local_jobportal'), 'size="20"');
        $mform->setType('salarymaxamount', PARAM_RAW_TRIMMED);

        // Keep this as a static label (not a form header) so later fields are not grouped under progressive stages.
        $mform->addElement('static', 'salaryprogressiveheader', get_string('salaryprogression', 'local_jobportal'), '');
        $mform->addElement('static', 'salaryprogressionhelp', '', get_string('salaryprogressionhelp', 'local_jobportal'));
        $salarystagerepeats = isset($this->_customdata['salarystagerepeats']) ? (int)$this->_customdata['salarystagerepeats'] : 2;
        if ($salarystagerepeats < 2) {
            $salarystagerepeats = 2;
        }

        $repeatarray = array();
        $repeatarray[] = $mform->createElement('text', 'salarystagelabel', get_string('salarystagelabel', 'local_jobportal'), 'size="24"');
        $repeatarray[] = $mform->createElement('text', 'salarystageamount', get_string('salarystageamount', 'local_jobportal'), 'size="12"');
        $repeatarray[] = $mform->createElement('select', 'salarystageperiod', get_string('salaryperiod', 'local_jobportal'), $salaryperiods);
        $repeatarray[] = $mform->createElement('text', 'salarystagecondition', get_string('salarystagecondition', 'local_jobportal'), 'size="32"');

        $repeatoptions = array();
        $repeatoptions['salarystagelabel']['type'] = PARAM_TEXT;
        $repeatoptions['salarystageamount']['type'] = PARAM_RAW_TRIMMED;
        $repeatoptions['salarystageperiod']['type'] = PARAM_ALPHA;
        $repeatoptions['salarystageperiod']['default'] = 'annual';
        $repeatoptions['salarystagecondition']['type'] = PARAM_TEXT;
        $effectiverepeats = (int)$this->repeat_elements(
            $repeatarray,
            $salarystagerepeats,
            $repeatoptions,
            'salarystagerepeats',
            'addsalarystage',
            1,
            get_string('addsalarystage', 'local_jobportal'),
            true
        );
        if ($effectiverepeats < $salarystagerepeats) {
            $effectiverepeats = $salarystagerepeats;
        }

        $mform->addElement('textarea', 'salary', get_string('salarydisplaytext', 'local_jobportal'), 'rows="2" cols="70"');
        $mform->setType('salary', PARAM_TEXT);

        // Conditional salary inputs by selected model.
        $mform->hideIf('salarycurrency', 'salarymodel', 'eq', 'custom');
        $mform->hideIf('salarycurrency', 'salarymodel', 'eq', 'undisclosed');
        $mform->hideIf('salaryperiod', 'salarymodel', 'eq', 'custom');
        $mform->hideIf('salaryperiod', 'salarymodel', 'eq', 'undisclosed');
        $mform->hideIf('salaryperiod', 'salarymodel', 'eq', 'progressive');
        $mform->hideIf('salaryfixedamount', 'salarymodel', 'neq', 'fixed');
        $mform->hideIf('salaryminamount', 'salarymodel', 'neq', 'range');
        $mform->hideIf('salarymaxamount', 'salarymodel', 'neq', 'range');
        $mform->hideIf('salaryprogressiveheader', 'salarymodel', 'neq', 'progressive');
        $mform->hideIf('salaryprogressionhelp', 'salarymodel', 'neq', 'progressive');
        for ($i = 0; $i < $effectiverepeats; $i++) {
            $mform->hideIf('salarystagelabel[' . $i . ']', 'salarymodel', 'neq', 'progressive');
            $mform->hideIf('salarystageamount[' . $i . ']', 'salarymodel', 'neq', 'progressive');
            $mform->hideIf('salarystageperiod[' . $i . ']', 'salarymodel', 'neq', 'progressive');
            $mform->hideIf('salarystagecondition[' . $i . ']', 'salarymodel', 'neq', 'progressive');
        }
        $mform->hideIf('addsalarystage', 'salarymodel', 'neq', 'progressive');

        // Requirements.
        $mform->addElement(
            'editor',
            'requirements',
            get_string('requirements', 'local_jobportal'),
            null,
            array('maxfiles' => 0)
        );
        $mform->setType('requirements', PARAM_RAW);

        // Deadline.
        $mform->addElement(
            'date_time_selector',
            'deadline',
            get_string('deadline', 'local_jobportal'),
            array('optional' => true, 'step' => 5)
        );
        $mform->setDefault('deadline', time() + DAYSECS);

        // Status.
        $mform->addElement(
            'advcheckbox',
            'status',
            get_string('status', 'local_jobportal'),
            get_string('active', 'local_jobportal'),
            array(),
            array(0, 1)
        );
        $mform->setDefault('status', 1);

        // Hidden fields.
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $this->add_action_buttons();
    }

    /**
     * Validate submitted data.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (!empty($data['deadline']) && $data['deadline'] < time()) {
            $errors['deadline'] = get_string('error:pastdeadline', 'local_jobportal');
        }

        if (empty($data['companyid'])) {
            $errors['companyid'] = get_string('error:selectcompany', 'local_jobportal');
        }

        $salarymodel = !empty($data['salarymodel']) ? core_text::strtolower(trim((string)$data['salarymodel'])) : 'custom';
        $currency = trim((string)($data['salarycurrency'] ?? ''));
        if (in_array($salarymodel, array('fixed', 'range', 'progressive'), true) && $currency === '') {
            $errors['salarycurrency'] = get_string('error:salarycurrencyrequired', 'local_jobportal');
        }

        if ($salarymodel === 'fixed') {
            $fixed = trim((string)($data['salaryfixedamount'] ?? ''));
            if ($fixed === '' || !is_numeric($fixed) || (float)$fixed <= 0) {
                $errors['salaryfixedamount'] = get_string('error:salaryfixedamount', 'local_jobportal');
            }
        } else if ($salarymodel === 'range') {
            $min = trim((string)($data['salaryminamount'] ?? ''));
            $max = trim((string)($data['salarymaxamount'] ?? ''));
            if ($min === '' || !is_numeric($min) || (float)$min <= 0) {
                $errors['salaryminamount'] = get_string('error:salarymin', 'local_jobportal');
            }
            if ($max === '' || !is_numeric($max) || (float)$max <= 0) {
                $errors['salarymaxamount'] = get_string('error:salarymax', 'local_jobportal');
            }
            if (!isset($errors['salaryminamount']) && !isset($errors['salarymaxamount']) && (float)$min > (float)$max) {
                $errors['salarymaxamount'] = get_string('error:salaryrangeorder', 'local_jobportal');
            }
        } else if ($salarymodel === 'progressive') {
            $parsed = local_jobportal_parse_salary_progression_rows(
                $data['salarystagelabel'] ?? array(),
                $data['salarystageamount'] ?? array(),
                $data['salarystageperiod'] ?? array(),
                $data['salarystagecondition'] ?? array()
            );
            if (!empty($parsed['fielderrors'])) {
                foreach ($parsed['fielderrors'] as $fieldname => $message) {
                    if (!isset($errors[$fieldname])) {
                        $errors[$fieldname] = $message;
                    }
                }
            }
            if (empty($parsed['stages']) && empty($parsed['fielderrors'])) {
                $errors['salarystagelabel[0]'] = get_string('error:salaryprogressionrequired', 'local_jobportal');
            }
        } else if ($salarymodel === 'custom') {
            $display = trim((string)($data['salary'] ?? ''));
            if ($display === '') {
                $errors['salary'] = get_string('error:salarydisplayrequired', 'local_jobportal');
            }
        }

        return $errors;
    }
}

/**
 * Parse progressive salary rows into structured stages.
 *
 * @param array<int,string>|string $labels
 * @param array<int,string>|string $amounts
 * @param array<int,string>|string $periods
 * @param array<int,string>|string $conditions
 * @return array{stages:array<int,array<string,mixed>>,errors:array<int,string>,fielderrors:array<string,string>}
 */
function local_jobportal_parse_salary_progression_rows($labels, $amounts, $periods, $conditions = array()) {
    if (!is_array($labels)) {
        $labels = array((string)$labels);
    }
    if (!is_array($amounts)) {
        $amounts = array((string)$amounts);
    }
    if (!is_array($periods)) {
        $periods = array((string)$periods);
    }
    if (!is_array($conditions)) {
        $conditions = array((string)$conditions);
    }

    $maxrows = max(count($labels), count($amounts), count($periods), count($conditions));
    $stages = array();
    $errors = array();
    $fielderrors = array();
    $allowedperiods = array('annual', 'monthly');
    $sortorder = 1;

    for ($index = 0; $index < $maxrows; $index++) {
        $label = trim((string)($labels[$index] ?? ''));
        $amountraw = trim((string)($amounts[$index] ?? ''));
        $period = core_text::strtolower(trim((string)($periods[$index] ?? 'annual')));
        $conditiontext = trim((string)($conditions[$index] ?? ''));

        if ($label === '' && $amountraw === '' && $conditiontext === '') {
            continue;
        }

        if ($label === '') {
            $message = get_string('error:salaryprogressionline', 'local_jobportal', $index + 1);
            $errors[] = $message;
            $fielderrors['salarystagelabel[' . $index . ']'] = $message;
            continue;
        }
        if (!is_numeric($amountraw) || (float)$amountraw <= 0) {
            $message = get_string('error:salaryprogressionamount', 'local_jobportal', $index + 1);
            $errors[] = $message;
            $fielderrors['salarystageamount[' . $index . ']'] = $message;
            continue;
        }
        if (!in_array($period, $allowedperiods, true)) {
            $message = get_string('error:salaryprogressionperiod', 'local_jobportal', $index + 1);
            $errors[] = $message;
            $fielderrors['salarystageperiod[' . $index . ']'] = $message;
            continue;
        }

        $stages[] = array(
            'stagelabel' => $label,
            'amount' => (float)$amountraw,
            'period' => $period,
            'conditiontext' => $conditiontext,
            'sortorder' => $sortorder,
        );
        $sortorder++;
    }

    return array('stages' => $stages, 'errors' => $errors, 'fielderrors' => $fielderrors);
}

/**
 * Map job salary fields to form-friendly defaults.
 *
 * @param stdClass $job
 * @return stdClass
 */
function local_jobportal_prepare_job_salary_form_data($job) {
    $job->salarymodel = !empty($job->salarymodel) ? core_text::strtolower((string)$job->salarymodel) : 'custom';
    if (!in_array($job->salarymodel, array('fixed', 'range', 'progressive', 'undisclosed', 'custom'), true)) {
        $job->salarymodel = 'custom';
    }

    $job->salarycurrency = !empty($job->salarycurrency) ? core_text::strtoupper((string)$job->salarycurrency) : 'INR';
    $job->salaryperiod = !empty($job->salaryperiod) ? core_text::strtolower((string)$job->salaryperiod) : 'annual';
    if ($job->salaryperiod !== 'monthly' && $job->salaryperiod !== 'annual') {
        $job->salaryperiod = 'annual';
    }

    $job->salaryfixedamount = '';
    $job->salaryminamount = '';
    $job->salarymaxamount = '';
    $job->salarystagelabel = array('', '');
    $job->salarystageamount = array('', '');
    $job->salarystageperiod = array('annual', 'annual');
    $job->salarystagecondition = array('', '');
    $job->salarystagerepeats = 2;

    if ($job->salarymodel === 'fixed') {
        $amount = $job->salarymin ?? $job->salarymax ?? null;
        if ($amount !== null && $amount !== '') {
            $job->salaryfixedamount = (string)$amount;
        }
    } else if ($job->salarymodel === 'range') {
        if ($job->salarymin !== null && $job->salarymin !== '') {
            $job->salaryminamount = (string)$job->salarymin;
        }
        if ($job->salarymax !== null && $job->salarymax !== '') {
            $job->salarymaxamount = (string)$job->salarymax;
        }
    } else if ($job->salarymodel === 'progressive' && !empty($job->id)) {
        $stages = local_jobportal_get_job_salary_stages((int)$job->id);
        if (!empty($stages)) {
            $job->salarystagelabel = array();
            $job->salarystageamount = array();
            $job->salarystageperiod = array();
            $job->salarystagecondition = array();
            foreach ($stages as $stage) {
                $job->salarystagelabel[] = trim((string)$stage->stagelabel);
                $job->salarystageamount[] = (string)$stage->amount;
                $period = core_text::strtolower((string)$stage->period);
                if ($period !== 'monthly' && $period !== 'annual') {
                    $period = 'annual';
                }
                $job->salarystageperiod[] = $period;
                $job->salarystagecondition[] = !empty($stage->conditiontext) ? trim((string)$stage->conditiontext) : '';
            }
            $job->salarystagerepeats = max(2, count($job->salarystagelabel));
        }
    }

    return $job;
}

require_login();

$id = optional_param('id', 0, PARAM_INT);
$cloneid = optional_param('cloneid', 0, PARAM_INT);
$defaultcompanyid = optional_param('companyid', 0, PARAM_INT);
if (!empty($id)) {
    // Edit mode takes precedence over clone mode.
    $cloneid = 0;
}

$context = context_system::instance();
require_capability('local/jobportal:postjobs', $context);

$PAGE->set_context($context);

$companyoptions = local_jobportal_get_company_options();
$companyoptionsforform = array('' => get_string('selectcompany', 'local_jobportal')) + $companyoptions;

if (empty($companyoptions)) {
    redirect(
        new moodle_url('/local/jobportal/companyprofile.php'),
        get_string('error:nocompanyavailable', 'local_jobportal'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

$pageurlparams = array(
    'id' => $id,
    'cloneid' => $cloneid,
    'companyid' => $defaultcompanyid,
);
$PAGE->set_url(new moodle_url('/local/jobportal/post.php', $pageurlparams));

if ($id) {
    $existingjob = $DB->get_record('local_jobportal_jobs', array('id' => $id), '*', MUST_EXIST);
    $clonejob = null;
    $formheaderlabel = get_string('editjob', 'local_jobportal');
    $PAGE->set_title(get_string('editjob', 'local_jobportal'));
    $PAGE->set_heading(get_string('editjob', 'local_jobportal'));
} else if ($cloneid) {
    $existingjob = null;
    $clonejob = $DB->get_record('local_jobportal_jobs', array('id' => $cloneid), '*', MUST_EXIST);
    $formheaderlabel = get_string('clonejob', 'local_jobportal');
    $PAGE->set_title(get_string('clonejob', 'local_jobportal'));
    $PAGE->set_heading(get_string('clonejob', 'local_jobportal'));
} else {
    $existingjob = null;
    $clonejob = null;
    $formheaderlabel = get_string('postjob', 'local_jobportal');
    $PAGE->set_title(get_string('postjob', 'local_jobportal'));
    $PAGE->set_heading(get_string('postjob', 'local_jobportal'));
}
local_jobportal_require_styles();

$salarystagerepeats = 2;
if ($existingjob && !empty($existingjob->id)) {
    $salarystagerepeats = max(2, count(local_jobportal_get_job_salary_stages((int)$existingjob->id)));
} else if ($clonejob && !empty($clonejob->id)) {
    $salarystagerepeats = max(2, count(local_jobportal_get_job_salary_stages((int)$clonejob->id)));
}
$submittedrepeats = optional_param('salarystagerepeats', 0, PARAM_INT);
if ($submittedrepeats > $salarystagerepeats) {
    $salarystagerepeats = $submittedrepeats;
}

$mform = new job_post_form(
    null,
    array(
        'companyoptions' => $companyoptionsforform,
        'companyprofileurl' => new moodle_url('/local/jobportal/companyprofile.php'),
        'headerlabel' => $formheaderlabel,
        'salarystagerepeats' => $salarystagerepeats,
    )
);

if ($existingjob) {
    $existingjob->description = array('text' => $existingjob->description, 'format' => FORMAT_HTML);
    $existingjob->requirements = array('text' => $existingjob->requirements, 'format' => FORMAT_HTML);
    $existingjob = local_jobportal_prepare_job_salary_form_data($existingjob);
    $mform->set_data($existingjob);
} else if ($clonejob) {
    $clonejob = local_jobportal_prepare_job_salary_form_data($clonejob);
    $clonejob->id = 0;
    $clonejob->description = array('text' => $clonejob->description, 'format' => FORMAT_HTML);
    $clonejob->requirements = array('text' => $clonejob->requirements, 'format' => FORMAT_HTML);
    $clonejob->deadline = 0;
    $clonejob->status = 1;
    $mform->set_data($clonejob);
} else if (!empty($defaultcompanyid) && isset($companyoptions[$defaultcompanyid])) {
    $mform->set_data((object)array(
        'companyid' => $defaultcompanyid,
        'salarymodel' => 'fixed',
        'salarycurrency' => 'INR',
        'salaryperiod' => 'annual',
        'salaryfixedamount' => '300000',
    ));
}

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/local/jobportal/index.php'));
} else if ($data = $mform->get_data()) {
    $selectedcompany = local_jobportal_get_company((int)$data->companyid);
    if (!$selectedcompany) {
        redirect(
            $PAGE->url,
            get_string('error:selectcompany', 'local_jobportal'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $job = new stdClass();
    $job->title = $data->title;
    $job->companyid = (int)$selectedcompany->id;
    $job->company = $selectedcompany->name;
    $job->description = $data->description['text'];
    $job->location = $data->location;
    $job->jobtype = $data->jobtype;
    $salarymodel = !empty($data->salarymodel) ? core_text::strtolower(trim((string)$data->salarymodel)) : 'custom';
    if (!in_array($salarymodel, array('fixed', 'range', 'progressive', 'undisclosed', 'custom'), true)) {
        $salarymodel = 'custom';
    }
    $salarycurrency = !empty($data->salarycurrency) ? core_text::strtoupper(trim((string)$data->salarycurrency)) : 'INR';
    if ($salarycurrency === '') {
        $salarycurrency = 'INR';
    }
    $salaryperiod = !empty($data->salaryperiod) ? core_text::strtolower(trim((string)$data->salaryperiod)) : 'annual';
    if ($salaryperiod !== 'monthly' && $salaryperiod !== 'annual') {
        $salaryperiod = 'annual';
    }

    $salarymin = null;
    $salarymax = null;
    $salaryminannual = null;
    $salarymaxannual = null;
    $salarystages = array();

    if ($salarymodel === 'fixed') {
        $amount = (float)$data->salaryfixedamount;
        $salarymin = $amount;
        $salarymax = $amount;
        $salaryminannual = local_jobportal_normalize_salary_to_annual($amount, $salaryperiod);
        $salarymaxannual = $salaryminannual;
    } else if ($salarymodel === 'range') {
        $minamount = (float)$data->salaryminamount;
        $maxamount = (float)$data->salarymaxamount;
        if ($minamount > $maxamount) {
            $tmp = $minamount;
            $minamount = $maxamount;
            $maxamount = $tmp;
        }
        $salarymin = $minamount;
        $salarymax = $maxamount;
        $salaryminannual = local_jobportal_normalize_salary_to_annual($minamount, $salaryperiod);
        $salarymaxannual = local_jobportal_normalize_salary_to_annual($maxamount, $salaryperiod);
    } else if ($salarymodel === 'progressive') {
        $salaryperiod = 'annual';
        $parsed = local_jobportal_parse_salary_progression_rows(
            $data->salarystagelabel ?? array(),
            $data->salarystageamount ?? array(),
            $data->salarystageperiod ?? array(),
            $data->salarystagecondition ?? array()
        );
        $salarystages = $parsed['stages'];
        $annuals = array();
        foreach ($salarystages as $stage) {
            $annual = local_jobportal_normalize_salary_to_annual($stage['amount'], $stage['period']);
            if ($annual !== null) {
                $annuals[] = $annual;
            }
        }
        if (!empty($annuals)) {
            $salaryminannual = min($annuals);
            $salarymaxannual = max($annuals);
        }
    } else {
        $salaryperiod = 'annual';
    }

    $displaytext = trim((string)$data->salary);
    $job->salary = local_jobportal_build_salary_display(
        $salarymodel,
        $salarycurrency,
        $salaryperiod,
        $salarymin,
        $salarymax,
        $displaytext,
        $salarystages
    );
    $job->salarymodel = $salarymodel;
    $job->salarycurrency = $salarycurrency;
    $job->salaryperiod = $salaryperiod;
    $job->salarymin = $salarymin;
    $job->salarymax = $salarymax;
    $job->salaryminannual = $salaryminannual;
    $job->salarymaxannual = $salarymaxannual;
    $job->requirements = $data->requirements['text'];
    $job->deadline = !empty($data->deadline) ? $data->deadline : null;
    $job->status = $data->status;
    $job->timemodified = time();
    if ($id && $existingjob) {
        $existingdrivestate = local_jobportal_get_job_drive_state($existingjob);
        $newstatus = (int)$job->status;
        if ($newstatus !== 1 && in_array($existingdrivestate, array('applicationsopen', 'applicationsclosed', 'selectioninprogress', 'onhold'), true)) {
            $job->drivestate = 'archived';
            $job->driveoutcome = null;
            $job->drivenote = null;
            $job->drivestateupdatedby = (int)$USER->id;
            $job->drivestateupdatedat = $job->timemodified;
        } else if ($newstatus === 1 && $existingdrivestate === 'archived' && (int)$existingjob->status === 0) {
            $job->drivestate = 'applicationsopen';
            $job->driveoutcome = null;
            $job->drivenote = null;
            $job->drivestateupdatedby = (int)$USER->id;
            $job->drivestateupdatedat = $job->timemodified;
        }
    }

    if ($id) {
        $job->id = $id;
        $DB->update_record('local_jobportal_jobs', $job);
        local_jobportal_replace_job_salary_stages((int)$id, $salarymodel === 'progressive' ? $salarystages : array());
        $message = get_string('jobupdated', 'local_jobportal');
    } else {
        $job->drivestate = 'applicationsopen';
        $job->driveoutcome = null;
        $job->drivenote = null;
        $job->drivestateupdatedby = (int)$USER->id;
        $job->drivestateupdatedat = $job->timemodified;
        $job->postedby = $USER->id;
        $job->timecreated = time();
        $newjobid = (int)$DB->insert_record('local_jobportal_jobs', $job);
        local_jobportal_replace_job_salary_stages($newjobid, $salarymodel === 'progressive' ? $salarystages : array());
        if (!empty($cloneid)) {
            $message = get_string('jobcloned', 'local_jobportal');
        } else {
            $message = get_string('jobposted', 'local_jobportal');
        }
    }

    redirect(
        new moodle_url('/local/jobportal/index.php'),
        $message,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo local_jobportal_render_navigation($context, 'post');

echo html_writer::start_tag('div', array('class' => 'local-jobportal-page'));
echo html_writer::start_div('jp-page-hero mb-4');
echo html_writer::start_div('container-fluid');
echo html_writer::start_div('row align-items-center mb-2');
echo html_writer::start_div('col-12');
echo html_writer::tag('h2', get_string('postjob', 'local_jobportal'), array('class' => 'jp-hero-title mb-2'));
echo html_writer::tag('p', 'Create or edit a job listing for students to apply.', array('class' => 'jp-hero-subtitle mb-0'));
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div(); // jp-page-hero

echo html_writer::start_div('container-fluid pb-4');
echo html_writer::start_div('row justify-content-center');
echo html_writer::start_div('col-xl-9 col-lg-10');

echo html_writer::start_tag('div', array('class' => 'card jp-form-section border-0 shadow-sm'));
echo html_writer::start_tag('div', array('class' => 'card-body p-4 jp-post-form-wrapper'));

$mform->display();

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

echo html_writer::end_div(); // col
echo html_writer::end_div(); // row
echo html_writer::end_div(); // container-fluid
echo html_writer::end_tag('div'); // local-jobportal-page

echo $OUTPUT->footer();
