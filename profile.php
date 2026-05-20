<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');
require_once($CFG->libdir.'/formslib.php');
require_once(__DIR__ . '/locallib.php');

class profile_form extends moodleform {
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'profileheader', get_string('myprofile', 'local_jobportal'));
        $mform->addElement('static', 'profileintro', '', get_string('profileintro', 'local_jobportal'));

        $mform->addElement('header', 'profilesectionprofessional', get_string('profileprofessionalsection', 'local_jobportal'));

        // Skills.
        $mform->addElement(
            'textarea',
            'skills',
            get_string('skills', 'local_jobportal'),
            'wrap="virtual" rows="4" cols="50" placeholder="' . s(get_string('skillsplaceholder', 'local_jobportal')) . '"'
        );
        $mform->setType('skills', PARAM_TEXT);
        $mform->addHelpButton('skills', 'skills', 'local_jobportal');

        // Experience.
        $mform->addElement(
            'textarea',
            'experience',
            get_string('experience', 'local_jobportal'),
            'wrap="virtual" rows="6" cols="50" placeholder="' . s(get_string('experienceplaceholder', 'local_jobportal')) . '"'
        );
        $mform->setType('experience', PARAM_TEXT);
        $mform->addHelpButton('experience', 'experience', 'local_jobportal');

        // Education.
        $mform->addElement(
            'textarea',
            'education',
            get_string('education', 'local_jobportal'),
            'wrap="virtual" rows="4" cols="50" placeholder="' . s(get_string('educationplaceholder', 'local_jobportal')) . '"'
        );
        $mform->setType('education', PARAM_TEXT);
        $mform->addHelpButton('education', 'education', 'local_jobportal');

        $mform->addElement('header', 'profilesectiondocuments', get_string('profiledocumentssection', 'local_jobportal'));

        // Portfolio URL.
        $mform->addElement(
            'text',
            'portfolio',
            get_string('portfolio', 'local_jobportal'),
            'size="60" placeholder="' . s(get_string('portfolioplaceholder', 'local_jobportal')) . '"'
        );
        $mform->setType('portfolio', PARAM_URL);
        $mform->addHelpButton('portfolio', 'portfolio', 'local_jobportal');

        // Resume upload.
        $mform->addElement('filemanager', 'resume', get_string('resume', 'local_jobportal'),
            null, array(
                'subdirs' => 0,
                'maxbytes' => 5242880, // 5MB
                'maxfiles' => 1,
                'accepted_types' => array('.pdf', '.doc', '.docx')
            ));
        $mform->addElement('static', 'resumehint', '', get_string('resumehint', 'local_jobportal'));

        $this->add_action_buttons();
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $portfolio = trim((string)($data['portfolio'] ?? ''));
        if ($portfolio !== '' && !preg_match('#^https?://#i', $portfolio)) {
            $errors['portfolio'] = get_string('error:portfoliohttps', 'local_jobportal');
        }

        return $errors;
    }
}

require_login();

$context = context_system::instance();
require_capability('local/jobportal:apply', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/jobportal/profile.php'));
$PAGE->set_title(get_string('myprofile', 'local_jobportal'));
$PAGE->set_heading(get_string('myprofile', 'local_jobportal'));
local_jobportal_require_styles();
$dateformat = '%d/%m/%Y';
$datetimeformat = '%d/%m/%Y %I:%M %p';

// Get existing profile if it exists
$profile = $DB->get_record('local_jobportal_profiles', array('userid' => $USER->id));
if ($profile) {
    $refresh = local_jobportal_refresh_profile_resume_review((int)$profile->id, $context);
    $profile = $refresh->profile;
}
$resumestatusoptions = local_jobportal_get_resume_status_options();
$oldresumesignature = '';

$mform = new profile_form();

if ($profile) {
    $oldresumesignature = local_jobportal_get_profile_resume_signature((int)$profile->id, $context);

    // Prepare file manager
    $draftitemid = file_get_submitted_draft_itemid('resume');
    file_prepare_draft_area($draftitemid, $context->id, 'local_jobportal', 'profile_resume', 
        $profile->id, array('subdirs' => 0, 'maxfiles' => 1));
    $profile->resume = $draftitemid;
    
    $mform->set_data($profile);
}

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/local/jobportal/index.php'));
} else if ($data = $mform->get_data()) {
    if ($profile) {
        // Update existing profile.
        $profile->skills = $data->skills;
        $profile->experience = $data->experience;
        $profile->education = $data->education;
        $profile->portfolio = $data->portfolio;
        $profile->timemodified = time();
        
        $DB->update_record('local_jobportal_profiles', $profile);
        $profileid = $profile->id;
    } else {
        // Create new profile.
        $newprofile = new stdClass();
        $newprofile->userid = $USER->id;
        $newprofile->skills = $data->skills;
        $newprofile->experience = $data->experience;
        $newprofile->education = $data->education;
        $newprofile->portfolio = $data->portfolio;
        $newprofile->resumestatus = 'notsubmitted';
        $newprofile->resumeapprovalmode = 'allrequired';
        $newprofile->timecreated = time();
        $newprofile->timemodified = time();
        
        $profileid = $DB->insert_record('local_jobportal_profiles', $newprofile);
    }

    // Handle file upload.
    $draftitemid = $data->resume;
    if ($draftitemid) {
        file_save_draft_area_files($draftitemid, $context->id, 'local_jobportal', 'profile_resume',
            $profileid, array('subdirs' => 0, 'maxfiles' => 1));
    }

    $newresumesignature = local_jobportal_get_profile_resume_signature((int)$profileid, $context);
    if ($oldresumesignature !== $newresumesignature) {
        $updatereview = new stdClass();
        $updatereview->id = (int)$profileid;
        $updatereview->resumerating = null;
        $updatereview->resumefeedback = null;
        $updatereview->resumereviewedby = null;
        $updatereview->resumereviewedat = null;
        $updatereview->timemodified = time();

        if ($newresumesignature === '') {
            $updatereview->resumestatus = 'notsubmitted';
            $historyaction = 'removed';
            $historyfeedback = get_string('resumeremovedbystudent', 'local_jobportal');
        } else if ($oldresumesignature === '') {
            $updatereview->resumestatus = 'submitted';
            $historyaction = 'submitted';
            $historyfeedback = get_string('resumesubmittedbystudent', 'local_jobportal');
        } else {
            $updatereview->resumestatus = 'submitted';
            $historyaction = 'resubmitted';
            $historyfeedback = get_string('resumeresubmittedbystudent', 'local_jobportal');
        }

        $DB->update_record('local_jobportal_profiles', $updatereview);
        local_jobportal_log_resume_review_history($profileid, $USER->id, $updatereview->resumestatus, null, $historyfeedback, $historyaction);
    }

    redirect(new moodle_url('/local/jobportal/profile.php'),
        get_string('profileupdated', 'local_jobportal'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$activeprofile = $profile ?: (object)array(
    'id' => 0,
    'skills' => '',
    'experience' => '',
    'education' => '',
    'portfolio' => '',
    'resumestatus' => 'notsubmitted',
    'resumerating' => null,
    'resumefeedback' => null,
    'resumereviewedby' => null,
    'resumereviewedat' => null,
    'timemodified' => 0,
);

$status = local_jobportal_normalize_resume_status($activeprofile->resumestatus);
$statuslabel = isset($resumestatusoptions[$status]) ? $resumestatusoptions[$status] : $status;
$statusbadge = local_jobportal_resume_status_badge_class($status);
$canseerevieweridentity = has_capability('local/jobportal:reviewresumes', $context) ||
    has_capability('local/jobportal:assignresumereviewers', $context) ||
    has_capability('local/jobportal:viewapplications', $context);
$reviewername = '';
$history = array();
$resumedownloadurl = null;
$resumepreviewurl = null;
$resumecanpreview = false;

if ($profile) {
    if ($canseerevieweridentity && !empty($profile->resumereviewedby)) {
        $reviewer = $DB->get_record(
            'user',
            array('id' => $profile->resumereviewedby),
            'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename'
        );
        if ($reviewer) {
            $reviewername = fullname($reviewer);
        }
    }

    $historysql = "SELECT h.*, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
                     FROM {local_jobportal_resume_review_hist} h
                     JOIN {user} u ON u.id = h.userid
                    WHERE h.profileid = :profileid
                 ORDER BY h.timecreated DESC";
    $history = $DB->get_records_sql($historysql, array('profileid' => (int)$profile->id), 0, 15);

    $resumefile = local_jobportal_get_profile_resume_file((int)$profile->id, $context);
    if ($resumefile) {
        $resumedownloadurl = local_jobportal_get_profile_resume_url((int)$profile->id, $context, true);
        if (local_jobportal_resume_file_is_previewable($resumefile)) {
            $resumecanpreview = true;
            $resumepreviewurl = local_jobportal_get_profile_resume_url((int)$profile->id, $context, false);
        }
    }
}

$completionchecks = array(
    get_string('profilecheck_skills', 'local_jobportal') => trim((string)$activeprofile->skills) !== '',
    get_string('profilecheck_experience', 'local_jobportal') => trim((string)$activeprofile->experience) !== '',
    get_string('profilecheck_education', 'local_jobportal') => trim((string)$activeprofile->education) !== '',
    get_string('profilecheck_portfolio', 'local_jobportal') => trim((string)$activeprofile->portfolio) !== '',
    get_string('profilecheck_resume', 'local_jobportal') => $resumedownloadurl !== null,
);
$completiontotal = count($completionchecks);
$completioncount = 0;
foreach ($completionchecks as $iscomplete) {
    if ($iscomplete) {
        $completioncount++;
    }
}
$completionpercent = $completiontotal > 0 ? (int)round(($completioncount * 100) / $completiontotal) : 0;
$completionlabel = get_string(
    'profilecompletedfields',
    'local_jobportal',
    (object)array('completed' => $completioncount, 'total' => $completiontotal)
);

if ($resumecanpreview && $resumepreviewurl) {
    $PAGE->requires->js_call_amd('local_jobportal/resume_preview', 'init');
}

echo $OUTPUT->header();
echo local_jobportal_render_navigation($context, 'profile');

echo html_writer::start_div('card mb-3 jp-profile-overview');
echo html_writer::start_div('card-body');
echo html_writer::tag('h5', get_string('profileoverview', 'local_jobportal'), array('class' => 'card-title mb-3'));

echo html_writer::start_div('jp-profile-metrics');
echo html_writer::tag(
    'div',
    html_writer::div(get_string('profilecompleteness', 'local_jobportal'), 'jp-profile-metric-label') .
    html_writer::div($completionpercent . '%', 'jp-profile-metric-value') .
    html_writer::div($completionlabel, 'jp-profile-metric-note'),
    array('class' => 'jp-profile-metric')
);
echo html_writer::tag(
    'div',
    html_writer::div(get_string('resumereviewstatus', 'local_jobportal'), 'jp-profile-metric-label') .
    html_writer::div(html_writer::tag('span', $statuslabel, array('class' => $statusbadge)), 'jp-profile-metric-value') .
    html_writer::div(($resumedownloadurl ? get_string('resume', 'local_jobportal') : get_string('resumenotuploaded', 'local_jobportal')), 'jp-profile-metric-note'),
    array('class' => 'jp-profile-metric')
);

$updatedlabel = '-';
if (!empty($activeprofile->timemodified)) {
    $updatedlabel = userdate($activeprofile->timemodified, $datetimeformat);
}
$reviewedlabel = '-';
if (!empty($activeprofile->resumereviewedat)) {
    $reviewedlabel = userdate($activeprofile->resumereviewedat, $datetimeformat);
    if ($reviewername !== '') {
        $reviewedlabel .= ' (' . s($reviewername) . ')';
    }
}
echo html_writer::tag(
    'div',
    html_writer::div(get_string('profileupdatedon', 'local_jobportal'), 'jp-profile-metric-label') .
    html_writer::div($updatedlabel, 'jp-profile-metric-value') .
    html_writer::div(get_string('lastreviewed', 'local_jobportal') . ': ' . $reviewedlabel, 'jp-profile-metric-note'),
    array('class' => 'jp-profile-metric')
);
echo html_writer::end_div();

echo html_writer::start_div('progress mb-3 jp-profile-progress');
echo html_writer::tag(
    'div',
    $completionpercent . '%',
    array(
        'class' => 'progress-bar',
        'role' => 'progressbar',
        'style' => 'width: ' . $completionpercent . '%;',
        'aria-valuenow' => $completionpercent,
        'aria-valuemin' => 0,
        'aria-valuemax' => 100,
    )
);
echo html_writer::end_div();

echo html_writer::start_div('jp-profile-checklist');
foreach ($completionchecks as $label => $iscomplete) {
    $itemclass = $iscomplete ? 'jp-profile-check complete' : 'jp-profile-check';
    $marker = $iscomplete ? '&#10003;' : '&#9675;';
    echo html_writer::tag(
        'div',
        html_writer::tag('span', $marker, array('class' => 'jp-profile-check-icon', 'aria-hidden' => 'true')) .
        html_writer::tag('span', s($label), array('class' => 'jp-profile-check-label')),
        array('class' => $itemclass)
    );
}
echo html_writer::end_div();

if (!$resumedownloadurl) {
    echo html_writer::tag('p', get_string('resumerequirednote', 'local_jobportal'), array('class' => 'alert alert-warning mt-3 mb-0'));
} else {
    echo html_writer::start_div('mt-3');
    if ($resumecanpreview && $resumepreviewurl) {
        echo html_writer::tag(
            'button',
            get_string('previewresume', 'local_jobportal'),
            array(
                'type' => 'button',
                'class' => 'btn btn-outline-secondary btn-sm mr-2 jp-resume-preview-trigger',
                'data-resume-url' => $resumepreviewurl->out(false),
            )
        );
    }
    echo html_writer::link(
        $resumedownloadurl,
        get_string('downloadresume', 'local_jobportal'),
        array('class' => 'btn btn-outline-primary btn-sm')
    );
    echo html_writer::end_div();
}
echo html_writer::end_div();
echo html_writer::end_div();

if ($resumecanpreview && $resumepreviewurl) {
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
}

echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-body');
$historycount = count($history);
$historytitle = get_string('resumereviewhistory', 'local_jobportal') . ' (' . $historycount . ')';
echo html_writer::start_tag('details', array('class' => 'jp-collapsible-history'));
echo html_writer::tag('summary', s($historytitle), array('class' => 'jp-collapsible-summary'));
echo html_writer::start_div('pt-2');

if (empty($history)) {
    echo html_writer::tag('p', get_string('noreviewhistory', 'local_jobportal'), array('class' => 'text-muted mb-0'));
} else {
    $historytable = new html_table();
    $historytable->attributes['class'] = 'table table-sm table-striped table-bordered mb-0 jp-table jp-data-table jp-profile-history-table';
    $historytable->head = array(
        get_string('reviewdate', 'local_jobportal'),
        get_string('actions', 'local_jobportal'),
        get_string('status', 'local_jobportal'),
    );
    if ($canseerevieweridentity) {
        $historytable->head[] = get_string('reviewer', 'local_jobportal');
    }
    $historytable->head[] = get_string('resumerating', 'local_jobportal');
    $historytable->head[] = get_string('resumefeedback', 'local_jobportal');

    foreach ($history as $event) {
        $actionkey = 'resumeaction_' . $event->action;
        $actionlabel = get_string_manager()->string_exists($actionkey, 'local_jobportal') ?
            get_string($actionkey, 'local_jobportal') : format_string($event->action);
        $eventstatus = local_jobportal_normalize_resume_status($event->status);
        $eventstatuslabel = isset($resumestatusoptions[$eventstatus]) ? $resumestatusoptions[$eventstatus] : $eventstatus;

        $row = array(
            userdate($event->timecreated, $datetimeformat),
            s($actionlabel),
            s($eventstatuslabel),
        );
        if ($canseerevieweridentity) {
            $row[] = fullname($event);
        }
        $row[] = $event->rating === null ? '-' : ((int)$event->rating . '/5');
        $row[] = !empty($event->feedback) ? s($event->feedback) : '-';
        $historytable->data[] = $row;
    }

    echo html_writer::table($historytable);
    echo html_writer::tag('p', get_string('resumereviewhistorylatest', 'local_jobportal'), array('class' => 'text-muted small mb-0 mt-2'));
}
echo html_writer::end_div();
echo html_writer::end_tag('details');
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('card mb-3 jp-profile-form-card');
echo html_writer::start_div('card-body');
echo html_writer::tag('h5', get_string('editprofile', 'local_jobportal'), array('class' => 'card-title mb-3'));
$mform->display();
echo html_writer::end_div();
echo html_writer::end_div();

echo $OUTPUT->footer();
