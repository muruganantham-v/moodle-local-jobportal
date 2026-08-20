<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');
require_once($CFG->libdir.'/tablelib.php');
require_once(__DIR__ . '/locallib.php');

/**
 * Jobs listing table for manager view.
 */
class local_jobportal_jobs_table extends table_sql {
    /** @var bool */
    protected $canpost = false;
    /** @var bool */
    protected $canviewapplications = false;
    /** @var string */
    protected $dateformat = '%d/%m/%Y';
    /** @var string */
    protected $datetimeformat = '%d/%m/%Y %H:%M';
    /** @var int */
    protected $now = 0;
    /** @var string */
    protected $sortby = 'listed';
    /** @var string */
    protected $sortdir = 'desc';
    /** @var array */
    protected $sortbaseparams = array();

    /**
     * local_jobportal_jobs_table constructor.
     *
     * @param string $uniqueid
     * @param array $selectedcols
     * @param array $columnoptions
     * @param bool $canpost
     * @param bool $canviewapplications
     * @param string $dateformat
     * @param string $datetimeformat
     * @param int $now
     * @param array $sortbaseparams
     * @param string $sortby
     * @param string $sortdir
     */
    public function __construct(
        $uniqueid,
        array $selectedcols,
        array $columnoptions,
        $canpost,
        $canviewapplications,
        $dateformat,
        $datetimeformat,
        $now,
        array $sortbaseparams,
        $sortby,
        $sortdir
    ) {
        parent::__construct($uniqueid);

        $this->canpost = (bool)$canpost;
        $this->canviewapplications = (bool)$canviewapplications;
        $this->dateformat = $dateformat;
        $this->datetimeformat = $datetimeformat;
        $this->now = (int)$now;
        $this->sortbaseparams = $sortbaseparams;
        $this->sortby = $sortby;
        $this->sortdir = strtolower($sortdir) === 'asc' ? 'asc' : 'desc';

        $columns = array_merge(array('select'), $selectedcols, array('actions'));
        $headers = array(get_string('select', 'moodle'));
        foreach ($selectedcols as $key) {
            $label = isset($columnoptions[$key]) ? $columnoptions[$key] : $key;
            $headers[] = $this->build_sort_header($key, $label);
        }
        $headers[] = get_string('actions');

        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->set_attribute('class', 'table table-sm table-striped table-bordered jp-table jp-data-table jp-jobs-table');
        $this->set_attribute('id', 'jp-jobs-table');

        $this->sortable(false);
        $this->collapsible(false);
        $this->no_sorting('select');
        $this->no_sorting('actions');
        $this->apply_column_layout($selectedcols);
    }

    /**
     * Select checkbox column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_select($row) {
        return html_writer::empty_tag('input', array(
            'type' => 'checkbox',
            'name' => 'jobids[]',
            'value' => (int)$row->id,
            'class' => 'jp-job-select',
        ));
    }

    /**
     * Job id column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_jobid($row) {
        return html_writer::link(
            new moodle_url('/local/jobportal/view.php', array('id' => (int)$row->id)),
            (string)(int)$row->id
        );
    }

    /**
     * Job title column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_title($row) {
        return html_writer::link(
            new moodle_url('/local/jobportal/view.php', array('id' => (int)$row->id)),
            format_string($row->title)
        );
    }

    /**
     * Linked company profile name column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_company($row) {
        $companyname = !empty($row->companyprofilename) ? $row->companyprofilename : $row->company;
        if (!empty($row->companyprofileid)) {
            return html_writer::link(
                new moodle_url('/local/jobportal/company.php', array('id' => (int)$row->companyprofileid)),
                s($companyname)
            );
        }
        return s($companyname);
    }

    /**
     * Job status column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_status($row) {
        list($label, $badge) = $this->get_status_badge($row);
        return html_writer::tag('span', $label, array('class' => $badge));
    }

    /**
     * Job type column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_jobtype($row) {
        return local_jobportal_format_jobtype($row->jobtype);
    }

    /**
     * Location column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_location($row) {
        return !empty($row->location) ? s($row->location) : '-';
    }

    /**
     * Salary column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_salary($row) {
        $salarydisplay = local_jobportal_get_job_salary_display($row, null, true);
        return $salarydisplay !== '' ? s($salarydisplay) : '-';
    }

    /**
     * Listed date column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_listed($row) {
        return userdate($row->timecreated, $this->dateformat);
    }

    /**
     * Application deadline column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_deadline($row) {
        return !empty($row->deadline) ? userdate($row->deadline, $this->datetimeformat) : '-';
    }

    /**
     * Total applications column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_applications($row) {
        return (string)(int)$row->applicationscount;
    }

    /**
     * Shortlisted count column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_shortlisted($row) {
        return (string)(int)$row->shortlistedcount;
    }

    /**
     * Offer conversion column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_offerconversion($row) {
        $applicationscount = (int)$row->applicationscount;
        $acceptedcount = (int)$row->acceptedcount;
        if ($applicationscount <= 0) {
            return '0%';
        }
        return format_float(($acceptedcount / $applicationscount) * 100, 1) . '%';
    }

    /**
     * Last application date column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_lastapplication($row) {
        return !empty($row->lastapplicationat) ? userdate($row->lastapplicationat, $this->dateformat) : '-';
    }

    /**
     * Last activity date/time column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_lastactivity($row) {
        return !empty($row->lastactivityat) ? userdate($row->lastactivityat, $this->datetimeformat) : '-';
    }

    /**
     * Days since last application column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_dayssincelastapplication($row) {
        if (empty($row->lastapplicationat)) {
            return '-';
        }
        return (string)max(0, (int)floor(($this->now - (int)$row->lastapplicationat) / DAYSECS));
    }

    /**
     * Days inactive (since last activity) column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_daysinactive($row) {
        if (empty($row->lastactivityat)) {
            return '-';
        }
        return (string)max(0, (int)floor(($this->now - (int)$row->lastactivityat) / DAYSECS));
    }

    /**
     * Last updated date column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_updated($row) {
        return userdate($row->timemodified, $this->dateformat);
    }

    /**
     * Actions column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_actions($row) {
        $line1 = array();
        $line2 = array();

        $viewjob = html_writer::link(
            new moodle_url('/local/jobportal/view.php', array('id' => (int)$row->id)),
            get_string('viewjob', 'local_jobportal')
        );
        $line1[] = $viewjob;

        if ($this->canviewapplications) {
            $line1[] = html_writer::link(
                new moodle_url('/local/jobportal/applications.php', array('jobid' => (int)$row->id)),
                get_string('viewapplications', 'local_jobportal')
            );
        }

        if ($this->canpost) {
            $line2[] = html_writer::link(
                new moodle_url('/local/jobportal/post.php', array('id' => (int)$row->id)),
                get_string('editjob', 'local_jobportal')
            );
            $line2[] = html_writer::link(
                new moodle_url('/local/jobportal/post.php', array('cloneid' => (int)$row->id)),
                get_string('clonejob', 'local_jobportal')
            );
        }

        $lines = array();
        if (!empty($line1)) {
            $lines[] = html_writer::div(implode(' | ', $line1), 'jp-job-actions-line');
        }
        if (!empty($line2)) {
            $lines[] = html_writer::div(implode(' | ', $line2), 'jp-job-actions-line');
        }

        return html_writer::div(implode('', $lines), 'jp-job-actions');
    }

    /**
     * Resolve status label and badge class.
     *
     * @param stdClass $row
     * @return array
     */
    protected function get_status_badge($row) {
        $state = local_jobportal_get_job_drive_state($row);
        $label = local_jobportal_get_job_drive_state_label($state);
        $badge = local_jobportal_get_job_drive_state_badge_class($state);
        return array($label, $badge);
    }

    /**
     * Apply per-column width/alignment for better readability.
     *
     * @param array $selectedcols
     * @return void
     */
    protected function apply_column_layout(array $selectedcols) {
        $this->column_style('select', 'width', '44px');
        $this->column_style('select', 'text-align', 'center');
        $this->column_style('actions', 'min-width', '220px');
        $this->column_style('actions', 'width', '220px');
        $this->column_style('actions', 'white-space', 'normal');

        $styles = array(
            'jobid' => array('width' => '84px', 'text-align' => 'center'),
            'title' => array('min-width' => '220px'),
            'company' => array('min-width' => '200px'),
            'status' => array('min-width' => '120px', 'text-align' => 'center'),
            'jobtype' => array('min-width' => '120px', 'text-align' => 'center'),
            'location' => array('min-width' => '160px'),
            'salary' => array('min-width' => '180px'),
            'listed' => array('width' => '110px', 'text-align' => 'center'),
            'deadline' => array('width' => '150px', 'text-align' => 'center'),
            'applications' => array('width' => '100px', 'text-align' => 'center'),
            'shortlisted' => array('width' => '100px', 'text-align' => 'center'),
            'offerconversion' => array('width' => '120px', 'text-align' => 'center'),
            'lastapplication' => array('width' => '130px', 'text-align' => 'center'),
            'dayssincelastapplication' => array('width' => '140px', 'text-align' => 'center'),
            'lastactivity' => array('width' => '150px', 'text-align' => 'center'),
            'daysinactive' => array('width' => '110px', 'text-align' => 'center'),
            'updated' => array('width' => '110px', 'text-align' => 'center'),
        );

        foreach ($selectedcols as $col) {
            if (empty($styles[$col])) {
                continue;
            }
            foreach ($styles[$col] as $property => $value) {
                $this->column_style($col, $property, $value);
            }
        }
    }

    /**
     * Build clickable header label for supported sort columns.
     *
     * @param string $key
     * @param string $label
     * @return string
     */
    protected function build_sort_header($key, $label) {
        $sortable = array(
            'jobid',
            'title',
            'company',
            'listed',
            'deadline',
            'updated',
            'location',
            'salary',
            'applications',
            'shortlisted',
            'offerconversion',
            'dayssincelastapplication',
            'lastactivity',
            'daysinactive',
        );
        if (!in_array($key, $sortable, true)) {
            return $label;
        }

        $nextdir = 'asc';
        $indicator = '';
        if ($this->sortby === $key) {
            if ($this->sortdir === 'asc') {
                $nextdir = 'desc';
                $indicator = ' ↑';
            } else {
                $nextdir = 'asc';
                $indicator = ' ↓';
            }
        }

        $params = $this->sortbaseparams;
        $params['page'] = 0;
        $params['sortby'] = $key;
        $params['sortdir'] = $nextdir;

        return html_writer::link(
            new moodle_url('/local/jobportal/index.php', $params),
            $label . $indicator,
            array('class' => 'jp-sort-header')
        );
    }
}

/**
 * Check whether a request parameter exists in GET or POST.
 *
 * @param string $name
 * @return bool
 */
function local_jobportal_request_param_exists($name) {
    return array_key_exists($name, $_GET) || array_key_exists($name, $_POST);
}

require_login();

$context = context_system::instance();
require_capability('local/jobportal:viewjobs', $context);

$ismanager = has_capability('local/jobportal:managejobs', $context);
$canapply = has_capability('local/jobportal:apply', $context);
$canpost = has_capability('local/jobportal:postjobs', $context);
$canmanagecompanies = has_capability('local/jobportal:managecompanyprofile', $context);
$canviewapplications = has_capability('local/jobportal:viewapplications', $context);

$search = trim(optional_param('search', '', PARAM_TEXT));
$page = optional_param('page', 0, PARAM_INT);
if ($page < 0) {
    $page = 0;
}

$dateformat = '%d/%m/%Y';
$datetimeformat = '%d/%m/%Y %H:%M';
$now = time();

$perpage = 12;
$pageurlparams = array();

if ($ismanager) {
    $managerprefprefix = 'local_jobportal_index_';
    $managerprefkeys = array(
        'search',
        'perpage',
        'companyid',
        'jobstatus',
        'jobtype',
        'salarymode',
        'salarymin',
        'salarymax',
        'hasapps',
        'staledays',
        'listedfrom',
        'listedto',
        'deadlinefrom',
        'deadlineto',
        'sortby',
        'sortdir',
        'preset',
        'cols',
    );
    $resetfiltersprefs = optional_param('resetfilters', 0, PARAM_BOOL);
    if ($resetfiltersprefs) {
        foreach ($managerprefkeys as $prefkey) {
            unset_user_preference($managerprefprefix . $prefkey, $USER->id);
        }
    }

    $readmanagerparam = function($name, $default, $type) use ($resetfiltersprefs, $managerprefprefix) {
        $provided = local_jobportal_request_param_exists($name);
        if ($provided) {
            $value = optional_param($name, $default, $type);
        } else if ($resetfiltersprefs) {
            $value = $default;
        } else {
            $value = get_user_preferences($managerprefprefix . $name, $default, $USER->id);
        }
        return array($value, $provided);
    };

    $pluginconfig = get_config('local_jobportal');
    $getpresetenabled = function($key, $default = true) use ($pluginconfig) {
        if (!isset($pluginconfig->$key)) {
            return $default;
        }
        return (int)$pluginconfig->$key === 1;
    };
    $getpresetdays = function($key, $default = 14) use ($pluginconfig) {
        if (!isset($pluginconfig->$key) || !is_numeric($pluginconfig->$key)) {
            return $default;
        }
        $value = (int)$pluginconfig->$key;
        if ($value < 1) {
            return 1;
        } else if ($value > 365) {
            return 365;
        }
        return $value;
    };
    $presetopenenabled = $getpresetenabled('preset_open_enabled', true);
    $presetclosingsoonenabled = $getpresetenabled('preset_closingsoon_enabled', true);
    $presetdeadlinetodayenabled = $getpresetenabled('preset_deadlinetoday_enabled', true);
    $presetdeadlinetomorrowenabled = $getpresetenabled('preset_deadlinetomorrow_enabled', true);
    $presetnoappsenabled = $getpresetenabled('preset_noapps_enabled', true);
    $presetstaleenabled = $getpresetenabled('preset_stale_enabled', true);
    $presetnoactivityenabled = $getpresetenabled('preset_noactivity_enabled', true);
    $noappspresetdays = $getpresetdays('preset_noapps_days', 14);
    $stalepresetdays = $getpresetdays('preset_stale_days', 14);
    $noactivitypresetdays = $getpresetdays('preset_noactivity_days', 14);

    $perpageoptions = array(25, 50, 100);
    list($search, $searchprovided) = $readmanagerparam('search', '', PARAM_TEXT);
    $search = trim($search);

    list($perpage, $perpageprovided) = $readmanagerparam('perpage', 25, PARAM_INT);
    if (!in_array($perpage, $perpageoptions, true)) {
        $perpage = 25;
    }

    list($companyid, $companyidprovided) = $readmanagerparam('companyid', 0, PARAM_INT);
    list($jobstatus, $jobstatusprovided) = $readmanagerparam('jobstatus', 'all', PARAM_ALPHANUMEXT);
    list($jobtype, $jobtypeprovided) = $readmanagerparam('jobtype', 'all', PARAM_ALPHANUMEXT);
    list($salarymode, $salarymodeprovided) = $readmanagerparam('salarymode', 'all', PARAM_ALPHANUMEXT);
    list($salaryminraw, $salaryminprovided) = $readmanagerparam('salarymin', '', PARAM_RAW_TRIMMED);
    list($salarymaxraw, $salarymaxprovided) = $readmanagerparam('salarymax', '', PARAM_RAW_TRIMMED);
    $salaryminraw = trim((string)$salaryminraw);
    $salarymaxraw = trim((string)$salarymaxraw);
    list($hasapps, $hasappsprovided) = $readmanagerparam('hasapps', 'all', PARAM_ALPHANUMEXT);
    list($staledays, $staledaysprovided) = $readmanagerparam('staledays', $stalepresetdays, PARAM_INT);
    if ($staledays < 1) {
        $staledays = 1;
    } else if ($staledays > 365) {
        $staledays = 365;
    }

    list($listedfrom, $listedfromprovided) = $readmanagerparam('listedfrom', '', PARAM_TEXT);
    list($listedto, $listedtoprovided) = $readmanagerparam('listedto', '', PARAM_TEXT);
    list($deadlinefrom, $deadlinefromprovided) = $readmanagerparam('deadlinefrom', '', PARAM_TEXT);
    list($deadlineto, $deadlinetoprovided) = $readmanagerparam('deadlineto', '', PARAM_TEXT);
    $listedfrom = trim($listedfrom);
    $listedto = trim($listedto);
    $deadlinefrom = trim($deadlinefrom);
    $deadlineto = trim($deadlineto);

    list($sortby, $sortbyprovided) = $readmanagerparam('sortby', 'listed', PARAM_ALPHANUMEXT);
    list($sortdir, $sortdirprovided) = $readmanagerparam('sortdir', 'desc', PARAM_ALPHA);
    $sortdir = strtolower($sortdir) === 'asc' ? 'asc' : 'desc';

    list($preset, $presetprovided) = $readmanagerparam('preset', '', PARAM_ALPHANUMEXT);
    $cols = array();
    $colsprovided = local_jobportal_request_param_exists('cols');
    if ((isset($_GET['cols']) && is_array($_GET['cols'])) || (isset($_POST['cols']) && is_array($_POST['cols']))) {
        $cols = optional_param_array('cols', array(), PARAM_ALPHANUMEXT);
    }
    $colstring = '';
    if (empty($cols)) {
        if ($colsprovided && isset($_GET['cols']) && is_string($_GET['cols'])) {
            $colstring = trim($_GET['cols']);
        } else if ($colsprovided && isset($_POST['cols']) && is_string($_POST['cols'])) {
            $colstring = trim($_POST['cols']);
        } else if (!$resetfiltersprefs) {
            $colstring = trim((string)get_user_preferences($managerprefprefix . 'cols', '', $USER->id));
        }
    }

    $statusaliases = array(
        'active' => 'applicationsopen',
        'closed' => 'applicationsclosed',
        'inactive' => 'archived',
    );
    if (isset($statusaliases[$jobstatus])) {
        $jobstatus = $statusaliases[$jobstatus];
    }

    $allowedstatuses = array(
        'all',
        'applicationsopen',
        'applicationsclosed',
        'selectioninprogress',
        'completed',
        'archived',
        'onhold',
        'cancelled',
        'expired',
        'closingsoon',
        'stale',
    );
    if (!in_array($jobstatus, $allowedstatuses, true)) {
        $jobstatus = 'all';
    }

    $jobtypes = array('fulltime', 'parttime', 'internship', 'contract', 'freelance');
    if ($jobtype !== 'all' && !in_array($jobtype, $jobtypes, true)) {
        $jobtype = 'all';
    }

    $allowedsalarymodes = array('all', 'lt', 'between', 'gt', 'undisclosed');
    if (!in_array($salarymode, $allowedsalarymodes, true)) {
        $salarymode = 'all';
    }
    $salarymin = null;
    $salarymax = null;
    if ($salaryminraw !== '' && is_numeric($salaryminraw)) {
        $salarymin = (float)$salaryminraw;
    } else {
        $salaryminraw = '';
    }
    if ($salarymaxraw !== '' && is_numeric($salarymaxraw)) {
        $salarymax = (float)$salarymaxraw;
    } else {
        $salarymaxraw = '';
    }
    if ($salarymode === 'between') {
        if ($salarymin === null || $salarymax === null) {
            $salarymode = 'all';
        } else if ($salarymin > $salarymax) {
            $tmp = $salarymin;
            $salarymin = $salarymax;
            $salarymax = $tmp;
            $salaryminraw = (string)$salarymin;
            $salarymaxraw = (string)$salarymax;
        }
    } else if ($salarymode === 'lt') {
        // Backward compatibility: accept old links that used salarymin for "less than".
        if ($salarymax === null && $salarymin !== null) {
            $salarymax = $salarymin;
            $salarymaxraw = $salaryminraw;
        }
        if ($salarymax === null) {
            $salarymode = 'all';
        }
        $salarymin = null;
        $salaryminraw = '';
    } else if ($salarymode === 'gt') {
        // Backward compatibility: accept old links that used salarymax for "greater than".
        if ($salarymin === null && $salarymax !== null) {
            $salarymin = $salarymax;
            $salaryminraw = $salarymaxraw;
        }
        if ($salarymin === null) {
            $salarymode = 'all';
        }
        $salarymax = null;
        $salarymaxraw = '';
    } else {
        $salarymin = null;
        $salarymax = null;
        $salaryminraw = '';
        $salarymaxraw = '';
    }

    $allowedapps = array('all', 'yes', 'no');
    if (!in_array($hasapps, $allowedapps, true)) {
        $hasapps = 'all';
    }

    $presetoptions = array(
        '' => get_string('presetcustom', 'local_jobportal'),
    );
    if ($presetopenenabled) {
        $presetoptions['open'] = get_string('presetopenjobs', 'local_jobportal');
    }
    if ($presetclosingsoonenabled) {
        $presetoptions['closingsoon'] = get_string('presetclosingsoon', 'local_jobportal');
    }
    if ($presetdeadlinetodayenabled) {
        $presetoptions['deadlinetoday'] = get_string('presetdeadlinetoday', 'local_jobportal');
    }
    if ($presetdeadlinetomorrowenabled) {
        $presetoptions['deadlinetomorrow'] = get_string('presetdeadlinetomorrow', 'local_jobportal');
    }
    if ($presetnoappsenabled) {
        $presetoptions['noapps14'] = get_string('presetnoappsdays', 'local_jobportal', $noappspresetdays);
    }
    if ($presetstaleenabled) {
        $presetoptions['stale14'] = get_string('presetstaledays', 'local_jobportal', $stalepresetdays);
    }
    if ($presetnoactivityenabled) {
        $presetoptions['noactivity14'] = get_string('presetnoactivitydays', 'local_jobportal', $noactivitypresetdays);
    }
    if (!isset($presetoptions[$preset])) {
        $preset = '';
    }

    $deadlinetodaydate = date('Y-m-d', $now);
    $deadlinetomorrowdate = date('Y-m-d', $now + DAYSECS);
    $noappsaged = false;
    $noactivityaged = false;
    if ($preset === 'open') {
        $hasconflict = ($jobstatusprovided && $jobstatus !== 'applicationsopen') ||
            ($salarymodeprovided && $salarymode !== 'all') ||
            ($salaryminprovided && $salaryminraw !== '') ||
            ($salarymaxprovided && $salarymaxraw !== '');
        if ($hasconflict) {
            $preset = '';
        } else {
            $jobstatus = 'applicationsopen';
        }
    } else if ($preset === 'closingsoon') {
        $hasconflict = ($jobstatusprovided && $jobstatus !== 'closingsoon') ||
            ($salarymodeprovided && $salarymode !== 'all') ||
            ($salaryminprovided && $salaryminraw !== '') ||
            ($salarymaxprovided && $salarymaxraw !== '');
        if ($hasconflict) {
            $preset = '';
        } else {
            $jobstatus = 'closingsoon';
        }
    } else if ($preset === 'deadlinetoday') {
        $hasconflict = ($jobstatusprovided && $jobstatus !== 'applicationsopen') ||
            ($deadlinefromprovided && $deadlinefrom !== $deadlinetodaydate) ||
            ($deadlinetoprovided && $deadlineto !== $deadlinetodaydate) ||
            ($salarymodeprovided && $salarymode !== 'all') ||
            ($salaryminprovided && $salaryminraw !== '') ||
            ($salarymaxprovided && $salarymaxraw !== '');
        if ($hasconflict) {
            $preset = '';
        } else {
            $jobstatus = 'applicationsopen';
            $deadlinefrom = $deadlinetodaydate;
            $deadlineto = $deadlinetodaydate;
        }
    } else if ($preset === 'deadlinetomorrow') {
        $hasconflict = ($jobstatusprovided && $jobstatus !== 'applicationsopen') ||
            ($deadlinefromprovided && $deadlinefrom !== $deadlinetomorrowdate) ||
            ($deadlinetoprovided && $deadlineto !== $deadlinetomorrowdate) ||
            ($salarymodeprovided && $salarymode !== 'all') ||
            ($salaryminprovided && $salaryminraw !== '') ||
            ($salarymaxprovided && $salarymaxraw !== '');
        if ($hasconflict) {
            $preset = '';
        } else {
            $jobstatus = 'applicationsopen';
            $deadlinefrom = $deadlinetomorrowdate;
            $deadlineto = $deadlinetomorrowdate;
        }
    } else if ($preset === 'noapps14') {
        $hasconflict = ($hasappsprovided && $hasapps !== 'no') ||
            ($staledaysprovided && $staledays !== $noappspresetdays) ||
            ($salarymodeprovided && $salarymode !== 'all') ||
            ($salaryminprovided && $salaryminraw !== '') ||
            ($salarymaxprovided && $salarymaxraw !== '');
        if ($hasconflict) {
            $preset = '';
        } else {
            $hasapps = 'no';
            $staledays = $noappspresetdays;
            $noappsaged = true;
        }
    } else if ($preset === 'stale14') {
        $hasconflict = ($jobstatusprovided && $jobstatus !== 'stale') ||
            ($staledaysprovided && $staledays !== $stalepresetdays) ||
            ($salarymodeprovided && $salarymode !== 'all') ||
            ($salaryminprovided && $salaryminraw !== '') ||
            ($salarymaxprovided && $salarymaxraw !== '');
        if ($hasconflict) {
            $preset = '';
        } else {
            $jobstatus = 'stale';
            $staledays = $stalepresetdays;
        }
    } else if ($preset === 'noactivity14') {
        $hasconflict = ($jobstatusprovided && $jobstatus !== 'all') ||
            ($hasappsprovided && $hasapps !== 'all') ||
            ($staledaysprovided && $staledays !== $noactivitypresetdays) ||
            ($salarymodeprovided && $salarymode !== 'all') ||
            ($salaryminprovided && $salaryminraw !== '') ||
            ($salarymaxprovided && $salarymaxraw !== '');
        if ($hasconflict) {
            $preset = '';
        } else {
            $staledays = $noactivitypresetdays;
            $noactivityaged = true;
        }
    }

    if ($preset !== '') {
        $page = 0;
    }

    $staledaysbaseline = $stalepresetdays;
    if ($preset === 'noapps14') {
        $staledaysbaseline = $noappspresetdays;
    } else if ($preset === 'noactivity14') {
        $staledaysbaseline = $noactivitypresetdays;
    }

    $showstaledays = ($jobstatus === 'stale' || $preset === 'noapps14' || $preset === 'stale14' || $preset === 'noactivity14');
    $advancedopen = ($listedfrom !== '' || $listedto !== '' || $deadlinefrom !== '' || $deadlineto !== '' || $hasapps !== 'all' ||
        $staledays !== $staledaysbaseline || $salarymode !== 'all' || $salaryminraw !== '' || $salarymaxraw !== '');

    $listedfromts = 0;
    if ($listedfrom !== '') {
        $listedfromts = strtotime($listedfrom . ' 00:00:00');
        if (!$listedfromts) {
            $listedfromts = 0;
            $listedfrom = '';
        }
    }
    $listedtots = 0;
    if ($listedto !== '') {
        $listedtots = strtotime($listedto . ' 23:59:59');
        if (!$listedtots) {
            $listedtots = 0;
            $listedto = '';
        }
    }
    $deadlinefromts = 0;
    if ($deadlinefrom !== '') {
        $deadlinefromts = strtotime($deadlinefrom . ' 00:00:00');
        if (!$deadlinefromts) {
            $deadlinefromts = 0;
            $deadlinefrom = '';
        }
    }
    $deadlinetots = 0;
    if ($deadlineto !== '') {
        $deadlinetots = strtotime($deadlineto . ' 23:59:59');
        if (!$deadlinetots) {
            $deadlinetots = 0;
            $deadlineto = '';
        }
    }

    $columnoptions = array(
        'jobid' => get_string('jobid', 'local_jobportal'),
        'title' => get_string('jobtitle', 'local_jobportal'),
        'company' => get_string('company', 'local_jobportal'),
        'status' => get_string('status', 'local_jobportal'),
        'jobtype' => get_string('jobtype', 'local_jobportal'),
        'location' => get_string('location', 'local_jobportal'),
        'salary' => get_string('salary', 'local_jobportal'),
        'listed' => get_string('joblistedon', 'local_jobportal'),
        'deadline' => get_string('deadline', 'local_jobportal'),
        'applications' => get_string('totalapplications', 'local_jobportal'),
        'shortlisted' => get_string('shortlisted', 'local_jobportal'),
        'offerconversion' => get_string('offerconversion', 'local_jobportal'),
        'lastapplication' => get_string('lastapplication', 'local_jobportal'),
        'dayssincelastapplication' => get_string('dayssincelastapplication', 'local_jobportal'),
        'lastactivity' => get_string('lastactivity', 'local_jobportal'),
        'daysinactive' => get_string('daysinactive', 'local_jobportal'),
        'updated' => get_string('lastupdated', 'local_jobportal'),
    );
    if (empty($cols) && $colstring !== '') {
        $cols = array_filter(array_map('trim', explode(',', $colstring)));
    }
    $selectedcols = array_values(array_intersect($cols, array_keys($columnoptions)));
    if (empty($selectedcols)) {
        $selectedcols = array_keys($columnoptions);
    }

    set_user_preference($managerprefprefix . 'search', $search, $USER->id);
    set_user_preference($managerprefprefix . 'perpage', $perpage, $USER->id);
    set_user_preference($managerprefprefix . 'companyid', $companyid, $USER->id);
    set_user_preference($managerprefprefix . 'jobstatus', $jobstatus, $USER->id);
    set_user_preference($managerprefprefix . 'jobtype', $jobtype, $USER->id);
    set_user_preference($managerprefprefix . 'salarymode', $salarymode, $USER->id);
    set_user_preference($managerprefprefix . 'salarymin', $salaryminraw, $USER->id);
    set_user_preference($managerprefprefix . 'salarymax', $salarymaxraw, $USER->id);
    set_user_preference($managerprefprefix . 'hasapps', $hasapps, $USER->id);
    set_user_preference($managerprefprefix . 'staledays', $staledays, $USER->id);
    set_user_preference($managerprefprefix . 'listedfrom', $listedfrom, $USER->id);
    set_user_preference($managerprefprefix . 'listedto', $listedto, $USER->id);
    set_user_preference($managerprefprefix . 'deadlinefrom', $deadlinefrom, $USER->id);
    set_user_preference($managerprefprefix . 'deadlineto', $deadlineto, $USER->id);
    set_user_preference($managerprefprefix . 'sortby', $sortby, $USER->id);
    set_user_preference($managerprefprefix . 'sortdir', $sortdir, $USER->id);
    set_user_preference($managerprefprefix . 'preset', $preset, $USER->id);
    set_user_preference($managerprefprefix . 'cols', implode(',', $selectedcols), $USER->id);

    $pageurlparams = array(
        'search' => $search,
        'page' => $page,
        'perpage' => $perpage,
        'companyid' => $companyid,
        'jobstatus' => $jobstatus,
        'jobtype' => $jobtype,
        'salarymode' => $salarymode,
        'salarymin' => $salaryminraw,
        'salarymax' => $salarymaxraw,
        'hasapps' => $hasapps,
        'staledays' => $staledays,
        'listedfrom' => $listedfrom,
        'listedto' => $listedto,
        'deadlinefrom' => $deadlinefrom,
        'deadlineto' => $deadlineto,
        'sortby' => $sortby,
        'sortdir' => $sortdir,
        'preset' => $preset,
        'cols' => implode(',', $selectedcols),
    );
} else {
    if ($search !== '') {
        $pageurlparams['search'] = $search;
    }
    if (!empty($page)) {
        $pageurlparams['page'] = $page;
    }
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/jobportal/index.php', $pageurlparams));
$PAGE->set_title(get_string('jobportal', 'local_jobportal'));
$PAGE->set_heading(get_string('alljobs', 'local_jobportal'));
local_jobportal_require_styles();

if ($ismanager) {
    $PAGE->requires->js_call_amd('local_jobportal/index_filters', 'init');
}

if ($ismanager && data_submitted() && optional_param('bulk', 0, PARAM_BOOL) && confirm_sesskey()) {
    $bulkaction = optional_param('bulkaction', '', PARAM_ALPHANUMEXT);
    $jobids = optional_param_array('jobids', array(), PARAM_INT);
    $jobids = array_filter(array_map('intval', $jobids));

    if (empty($jobids)) {
        redirect($PAGE->url, get_string('bulknojobsselected', 'local_jobportal'), null, \core\output\notification::NOTIFY_WARNING);
    }

    $validactions = array('open', 'close', 'extenddeadline', 'clone');
    if (!in_array($bulkaction, $validactions, true)) {
        redirect($PAGE->url, get_string('error:invalidaction', 'local_jobportal'), null, \core\output\notification::NOTIFY_ERROR);
    }

    $jobs = $DB->get_records_list('local_jobportal_jobs', 'id', $jobids);
    if (empty($jobs)) {
        redirect($PAGE->url, get_string('bulknojobsselected', 'local_jobportal'), null, \core\output\notification::NOTIFY_WARNING);
    }

    $updated = 0;
    $now = time();

    if ($bulkaction === 'open' || $bulkaction === 'close') {
        $newdrivestate = $bulkaction === 'open' ? 'applicationsopen' : 'applicationsclosed';
        foreach ($jobs as $job) {
            $update = new stdClass();
            $update->id = (int)$job->id;
            $update->status = 1;
            $update->drivestate = $newdrivestate;
            $update->driveoutcome = null;
            $update->drivenote = null;
            $update->drivestateupdatedby = (int)$USER->id;
            $update->drivestateupdatedat = $now;
            $update->timemodified = $now;
            $DB->update_record('local_jobportal_jobs', $update);
            $updated++;
        }
        redirect($PAGE->url, get_string('bulkjobsupdated', 'local_jobportal', $updated), null, \core\output\notification::NOTIFY_SUCCESS);
    }

    if ($bulkaction === 'extenddeadline') {
        $extenddays = optional_param('extenddays', 0, PARAM_INT);
        if ($extenddays < 1) {
            redirect($PAGE->url, get_string('bulkextenddaysrequired', 'local_jobportal'), null, \core\output\notification::NOTIFY_WARNING);
        }
        $extendseconds = $extenddays * DAYSECS;
        foreach ($jobs as $job) {
            $deadline = !empty($job->deadline) ? (int)$job->deadline : ($now + $extendseconds);
            if (!empty($job->deadline)) {
                $deadline = (int)$job->deadline + $extendseconds;
            }
            $update = new stdClass();
            $update->id = (int)$job->id;
            $update->deadline = $deadline;
            $update->timemodified = $now;
            $DB->update_record('local_jobportal_jobs', $update);
            $updated++;
        }
        redirect($PAGE->url, get_string('bulkjobsupdated', 'local_jobportal', $updated), null, \core\output\notification::NOTIFY_SUCCESS);
    }

    if ($bulkaction === 'clone') {
        foreach ($jobs as $job) {
            $suffix = ' (Copy)';
            $title = (string)$job->title;
            if (core_text::strlen($title . $suffix) > 255) {
                $title = core_text::substr($title, 0, 255 - core_text::strlen($suffix));
            }

            $clone = new stdClass();
            $clone->title = $title . $suffix;
            $clone->companyid = !empty($job->companyid) ? (int)$job->companyid : null;
            $clone->company = $job->company;
            $clone->description = $job->description;
            $clone->location = $job->location;
            $clone->jobtype = $job->jobtype;
            $clone->salary = $job->salary;
            $clone->salarymodel = !empty($job->salarymodel) ? $job->salarymodel : 'custom';
            $clone->salarycurrency = !empty($job->salarycurrency) ? $job->salarycurrency : 'INR';
            $clone->salaryperiod = !empty($job->salaryperiod) ? $job->salaryperiod : 'annual';
            $clone->salarymin = isset($job->salarymin) ? $job->salarymin : null;
            $clone->salarymax = isset($job->salarymax) ? $job->salarymax : null;
            $clone->salaryminannual = isset($job->salaryminannual) ? $job->salaryminannual : null;
            $clone->salarymaxannual = isset($job->salarymaxannual) ? $job->salarymaxannual : null;
            $clone->requirements = $job->requirements;
            $clone->deadline = null;
            $clone->status = 1;
            $clone->drivestate = 'applicationsopen';
            $clone->driveoutcome = null;
            $clone->drivenote = null;
            $clone->drivestateupdatedby = null;
            $clone->drivestateupdatedat = null;
            $clone->postedby = $USER->id;
            $clone->timecreated = $now;
            $clone->timemodified = $now;

            $newjobid = (int)$DB->insert_record('local_jobportal_jobs', $clone);
            $salarystages = local_jobportal_get_job_salary_stages((int)$job->id);
            $newstages = array();
            foreach ($salarystages as $stage) {
                $newstages[] = array(
                    'stagelabel' => $stage->stagelabel,
                    'amount' => $stage->amount,
                    'period' => $stage->period,
                    'conditiontext' => !empty($stage->conditiontext) ? $stage->conditiontext : '',
                    'sortorder' => $stage->sortorder,
                );
            }
            local_jobportal_replace_job_salary_stages($newjobid, $newstages);
            $updated++;
        }
        redirect($PAGE->url, get_string('bulkjobscloned', 'local_jobportal', $updated), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

echo $OUTPUT->header();
echo local_jobportal_render_navigation($context, 'index');

if ($ismanager) {
    $companyoptions = local_jobportal_get_company_options();
    $companyoptions = array(0 => get_string('allcompanies', 'local_jobportal')) + $companyoptions;
    if (!isset($companyoptions[$companyid])) {
        $companyid = 0;
    }

    $jobstatusoptions = array(
        'all' => get_string('allstatuses', 'local_jobportal'),
        'applicationsopen' => get_string('drivestate_applicationsopen', 'local_jobportal'),
        'applicationsclosed' => get_string('drivestate_applicationsclosed', 'local_jobportal'),
        'selectioninprogress' => get_string('drivestate_selectioninprogress', 'local_jobportal'),
        'completed' => get_string('drivestate_completed', 'local_jobportal'),
        'onhold' => get_string('drivestate_onhold', 'local_jobportal'),
        'cancelled' => get_string('drivestate_cancelled', 'local_jobportal'),
        'archived' => get_string('drivestate_archived', 'local_jobportal'),
        'expired' => get_string('jobstatusexpired', 'local_jobportal'),
        'closingsoon' => get_string('jobstatusclosingsoon', 'local_jobportal'),
        'stale' => get_string('jobstatusstale', 'local_jobportal'),
    );

    $jobtypeoptions = array(
        'all' => get_string('alljobtypes', 'local_jobportal'),
        'fulltime' => get_string('fulltime', 'local_jobportal'),
        'parttime' => get_string('parttime', 'local_jobportal'),
        'internship' => get_string('internship', 'local_jobportal'),
        'contract' => get_string('contract', 'local_jobportal'),
        'freelance' => get_string('freelance', 'local_jobportal'),
    );
    $salarymodeoptions = array(
        'all' => get_string('salaryfilterall', 'local_jobportal'),
        'lt' => get_string('salaryfilterlt', 'local_jobportal'),
        'between' => get_string('salaryfilterbetween', 'local_jobportal'),
        'gt' => get_string('salaryfiltergt', 'local_jobportal'),
        'undisclosed' => get_string('salaryfilterundisclosed', 'local_jobportal'),
    );

    $hasappsoptions = array(
        'all' => get_string('alloptions', 'local_jobportal'),
        'yes' => get_string('hasapplications_yes', 'local_jobportal'),
        'no' => get_string('hasapplications_no', 'local_jobportal'),
    );

    $sortoptions = array(
        'listed' => get_string('joblistedon', 'local_jobportal'),
        'deadline' => get_string('deadline', 'local_jobportal'),
        'updated' => get_string('lastupdated', 'local_jobportal'),
        'jobid' => get_string('jobid', 'local_jobportal'),
        'location' => get_string('location', 'local_jobportal'),
        'salary' => get_string('salary', 'local_jobportal'),
        'applications' => get_string('totalapplications', 'local_jobportal'),
        'shortlisted' => get_string('shortlisted', 'local_jobportal'),
        'offerconversion' => get_string('offerconversion', 'local_jobportal'),
        'dayssincelastapplication' => get_string('dayssincelastapplication', 'local_jobportal'),
        'lastactivity' => get_string('lastactivity', 'local_jobportal'),
        'daysinactive' => get_string('daysinactive', 'local_jobportal'),
        'company' => get_string('company', 'local_jobportal'),
        'title' => get_string('jobtitle', 'local_jobportal'),
    );
    if (!isset($sortoptions[$sortby])) {
        $sortby = 'listed';
    }

    $sortdiroptions = array(
        'asc' => get_string('sortasc', 'local_jobportal'),
        'desc' => get_string('sortdesc', 'local_jobportal'),
    );

    $perpagechoices = array(
        25 => '25',
        50 => '50',
        100 => '100',
    );

    $columnorder = array_keys($columnoptions);

    $baseurl = new moodle_url('/local/jobportal/index.php');
    $resetfiltersurl = new moodle_url('/local/jobportal/index.php', array('resetfilters' => 1));
    $presetchipurls = array();
    foreach ($presetoptions as $presetkey => $presetlabel) {
        if ($presetkey === '') {
            continue;
        }
        $presetparams = $pageurlparams;
        unset($presetparams['page']);
        $presetparams['preset'] = $presetkey;
        $presetparams['jobstatus'] = 'all';
        $presetparams['salarymode'] = 'all';
        $presetparams['salarymin'] = '';
        $presetparams['salarymax'] = '';
        $presetparams['hasapps'] = 'all';
        $presetparams['staledays'] = $stalepresetdays;
        $presetparams['listedfrom'] = '';
        $presetparams['listedto'] = '';
        $presetparams['deadlinefrom'] = '';
        $presetparams['deadlineto'] = '';
        if ($presetkey === 'open') {
            $presetparams['jobstatus'] = 'applicationsopen';
        } else if ($presetkey === 'closingsoon') {
            $presetparams['jobstatus'] = 'closingsoon';
        } else if ($presetkey === 'deadlinetoday') {
            $presetparams['jobstatus'] = 'applicationsopen';
            $presetparams['deadlinefrom'] = date('Y-m-d', $now);
            $presetparams['deadlineto'] = date('Y-m-d', $now);
        } else if ($presetkey === 'deadlinetomorrow') {
            $presetparams['jobstatus'] = 'applicationsopen';
            $presetparams['deadlinefrom'] = date('Y-m-d', $now + DAYSECS);
            $presetparams['deadlineto'] = date('Y-m-d', $now + DAYSECS);
        } else if ($presetkey === 'noapps14') {
            $presetparams['hasapps'] = 'no';
            $presetparams['staledays'] = $noappspresetdays;
        } else if ($presetkey === 'stale14') {
            $presetparams['jobstatus'] = 'stale';
            $presetparams['staledays'] = $stalepresetdays;
        } else if ($presetkey === 'noactivity14') {
            $presetparams['staledays'] = $noactivitypresetdays;
        }
        $presetchipurls[$presetkey] = new moodle_url('/local/jobportal/index.php', $presetparams);
    }
    $clearpresetparams = $pageurlparams;
    unset($clearpresetparams['page']);
    $clearpresetparams['preset'] = '';
    $clearpreseturl = new moodle_url('/local/jobportal/index.php', $clearpresetparams);

    $activefilterchips = array();
    $chipbaseparams = $pageurlparams;
    unset($chipbaseparams['page']);
    $buildchip = function(string $label, array $overrides, bool $clearpreset = true) use ($chipbaseparams): array {
        $params = $chipbaseparams;
        $params['page'] = 0;
        foreach ($overrides as $key => $value) {
            $params[$key] = $value;
        }
        if ($clearpreset) {
            $params['preset'] = '';
        }
        return array(
            'label' => $label,
            'url' => new moodle_url('/local/jobportal/index.php', $params),
        );
    };
    if ($search !== '') {
        $activefilterchips[] = $buildchip(get_string('search') . ': ' . $search, array('search' => ''));
    }
    if (!empty($companyid) && isset($companyoptions[$companyid])) {
        $activefilterchips[] = $buildchip(get_string('company', 'local_jobportal') . ': ' . $companyoptions[$companyid], array('companyid' => 0));
    }
    if ($jobstatus !== 'all' && isset($jobstatusoptions[$jobstatus])) {
        $activefilterchips[] = $buildchip(get_string('status', 'local_jobportal') . ': ' . $jobstatusoptions[$jobstatus], array('jobstatus' => 'all'));
    }
    if ($jobtype !== 'all' && isset($jobtypeoptions[$jobtype])) {
        $activefilterchips[] = $buildchip(get_string('jobtype', 'local_jobportal') . ': ' . $jobtypeoptions[$jobtype], array('jobtype' => 'all'));
    }
    if ($salarymode !== 'all' && isset($salarymodeoptions[$salarymode])) {
        $salarylabel = get_string('salaryfilter', 'local_jobportal') . ': ' . $salarymodeoptions[$salarymode];
        if ($salarymode === 'between' && $salaryminraw !== '' && $salarymaxraw !== '') {
            $salarylabel .= ' (' . $salaryminraw . ' - ' . $salarymaxraw . ')';
        } else if ($salarymode === 'lt' && $salarymaxraw !== '') {
            $salarylabel .= ' (' . $salarymaxraw . ')';
        } else if ($salarymode === 'gt' && $salaryminraw !== '') {
            $salarylabel .= ' (' . $salaryminraw . ')';
        }
        $activefilterchips[] = $buildchip($salarylabel, array('salarymode' => 'all', 'salarymin' => '', 'salarymax' => ''));
    }
    if ($hasapps !== 'all' && isset($hasappsoptions[$hasapps])) {
        $activefilterchips[] = $buildchip(get_string('hasapplications', 'local_jobportal') . ': ' . $hasappsoptions[$hasapps], array('hasapps' => 'all'));
    }
    if ($listedfrom !== '') {
        $activefilterchips[] = $buildchip(get_string('listedfrom', 'local_jobportal') . ': ' . $listedfrom, array('listedfrom' => ''));
    }
    if ($listedto !== '') {
        $activefilterchips[] = $buildchip(get_string('listedto', 'local_jobportal') . ': ' . $listedto, array('listedto' => ''));
    }
    if ($deadlinefrom !== '') {
        $activefilterchips[] = $buildchip(get_string('deadlinefrom', 'local_jobportal') . ': ' . $deadlinefrom, array('deadlinefrom' => ''));
    }
    if ($deadlineto !== '') {
        $activefilterchips[] = $buildchip(get_string('deadlineto', 'local_jobportal') . ': ' . $deadlineto, array('deadlineto' => ''));
    }
    if ($showstaledays && $staledays !== $staledaysbaseline) {
        $activefilterchips[] = $buildchip(
            get_string('staledays', 'local_jobportal') . ': ' . $staledays,
            array('staledays' => $staledaysbaseline)
        );
    }
    if ($preset !== '' && isset($presetoptions[$preset])) {
        $activefilterchips[] = $buildchip(
            get_string('presetfilters', 'local_jobportal') . ': ' . $presetoptions[$preset],
            array('preset' => ''),
            false
        );
    }

    $columngroups = array(
        get_string('jobinformation', 'local_jobportal') => array('jobid', 'title', 'company', 'status', 'jobtype', 'location', 'salary', 'listed', 'deadline', 'updated'),
        get_string('funnelanalytics', 'local_jobportal') => array(
            'applications',
            'shortlisted',
            'offerconversion',
            'lastapplication',
            'dayssincelastapplication',
            'lastactivity',
            'daysinactive',
        ),
    );

    echo html_writer::start_div('card mb-3 jp-filter-card jp-sticky-filters', array('id' => 'jp-jobs-filter-card'));
    echo html_writer::start_div('card-body');
    echo html_writer::start_div('d-flex justify-content-between align-items-center mb-2');
    echo html_writer::start_div('d-flex align-items-center gap-2');
    echo html_writer::tag('h5', get_string('jobfilters', 'local_jobportal'), array('class' => 'card-title mb-0'));
    if (!empty($activefilterchips)) {
        echo html_writer::tag('span', get_string('filtersapplied', 'local_jobportal', count($activefilterchips)), array('class' => 'badge badge-primary ml-2 jp-filter-active-count'));
    }
    echo html_writer::end_div();
    echo local_jobportal_render_filter_toggle_button('jp-jobs-filter-content-wrap', 'jp_jobs_filters_hidden');
    echo html_writer::end_div();

    echo html_writer::start_div('jp-filter-content-wrap', array('id' => 'jp-jobs-filter-content-wrap'));

    echo html_writer::start_div('jp-preset-chipbar mb-3');
    echo html_writer::tag('span', get_string('presetfilters', 'local_jobportal'), array('class' => 'jp-chipbar-label'));
    foreach ($presetoptions as $presetkey => $presetlabel) {
        if ($presetkey === '') {
            continue;
        }
        $presetclass = 'jp-preset-chip';
        if ($preset === $presetkey) {
            $presetclass .= ' jp-active';
        }
        echo html_writer::link($presetchipurls[$presetkey], $presetlabel, array('class' => $presetclass));
    }
    if ($preset !== '') {
        echo html_writer::link($clearpreseturl, get_string('clearpreset', 'local_jobportal'), array('class' => 'jp-preset-clear'));
    }
    echo html_writer::end_div();

    echo html_writer::start_tag('form', array(
        'method' => 'get',
        'action' => $baseurl,
        'class' => 'jp-filter-form',
        'novalidate' => 'novalidate',
    ));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'preset', 'id' => 'jp-preset', 'value' => $preset));

    echo html_writer::start_div('row');
    echo html_writer::start_div('col-md-4 mb-2');
    echo html_writer::tag('label', get_string('search'), array('for' => 'jp-search', 'class' => 'small text-muted d-block'));
    echo html_writer::empty_tag('input', array(
        'type' => 'text',
        'id' => 'jp-search',
        'name' => 'search',
        'value' => $search,
        'placeholder' => get_string('search'),
        'class' => 'form-control',
    ));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-3 mb-2');
    echo html_writer::tag('label', get_string('company', 'local_jobportal'), array('for' => 'jp-companyid', 'class' => 'small text-muted d-block'));
    echo html_writer::select($companyoptions, 'companyid', $companyid, false, array('class' => 'custom-select', 'id' => 'jp-companyid'));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-2 mb-2');
    echo html_writer::tag('label', get_string('status', 'local_jobportal'), array('for' => 'jp-jobstatus', 'class' => 'small text-muted d-block'));
    echo html_writer::select($jobstatusoptions, 'jobstatus', $jobstatus, false, array('class' => 'custom-select', 'id' => 'jp-jobstatus'));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-3 mb-2');
    echo html_writer::tag('label', get_string('jobtype', 'local_jobportal'), array('for' => 'jp-jobtype', 'class' => 'small text-muted d-block'));
    echo html_writer::select($jobtypeoptions, 'jobtype', $jobtype, false, array('class' => 'custom-select', 'id' => 'jp-jobtype'));
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_tag('details', array('class' => 'jp-filter-details mt-2', 'open' => $advancedopen ? 'open' : null));
    echo html_writer::tag('summary', get_string('advancedfilters', 'local_jobportal'));
    echo html_writer::start_div('row mt-2');
    echo html_writer::start_div('col-md-3 mb-2');
    echo html_writer::tag('label', get_string('listedfrom', 'local_jobportal'), array('for' => 'jp-listedfrom', 'class' => 'small text-muted d-block'));
    echo html_writer::empty_tag('input', array(
        'type' => 'date',
        'id' => 'jp-listedfrom',
        'name' => 'listedfrom',
        'value' => $listedfrom,
        'class' => 'form-control',
        'placeholder' => get_string('listedfrom', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-3 mb-2');
    echo html_writer::tag('label', get_string('listedto', 'local_jobportal'), array('for' => 'jp-listedto', 'class' => 'small text-muted d-block'));
    echo html_writer::empty_tag('input', array(
        'type' => 'date',
        'id' => 'jp-listedto',
        'name' => 'listedto',
        'value' => $listedto,
        'class' => 'form-control',
        'placeholder' => get_string('listedto', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-3 mb-2');
    echo html_writer::tag('label', get_string('deadlinefrom', 'local_jobportal'), array('for' => 'jp-deadlinefrom', 'class' => 'small text-muted d-block'));
    echo html_writer::empty_tag('input', array(
        'type' => 'date',
        'id' => 'jp-deadlinefrom',
        'name' => 'deadlinefrom',
        'value' => $deadlinefrom,
        'class' => 'form-control',
        'placeholder' => get_string('deadlinefrom', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-3 mb-2');
    echo html_writer::tag('label', get_string('deadlineto', 'local_jobportal'), array('for' => 'jp-deadlineto', 'class' => 'small text-muted d-block'));
    echo html_writer::empty_tag('input', array(
        'type' => 'date',
        'id' => 'jp-deadlineto',
        'name' => 'deadlineto',
        'value' => $deadlineto,
        'class' => 'form-control',
        'placeholder' => get_string('deadlineto', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('row');
    echo html_writer::start_div('col-md-3 mb-2');
    echo html_writer::tag('label', get_string('hasapplications', 'local_jobportal'), array('for' => 'jp-hasapps', 'class' => 'small text-muted d-block'));
    echo html_writer::select($hasappsoptions, 'hasapps', $hasapps, false, array('class' => 'custom-select', 'id' => 'jp-hasapps'));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-3 mb-2');
    echo html_writer::tag('label', get_string('salaryfilter', 'local_jobportal'), array('for' => 'jp-salarymode', 'class' => 'small text-muted d-block'));
    echo html_writer::select($salarymodeoptions, 'salarymode', $salarymode, false, array('class' => 'custom-select', 'id' => 'jp-salarymode'));
    echo html_writer::end_div();
    $showsalarymin = ($salarymode === 'gt' || $salarymode === 'between');
    $showsalarymax = ($salarymode === 'lt' || $salarymode === 'between');
    $salaryminwrapstyle = $showsalarymin ? '' : 'display:none;';
    $salarymaxwrapstyle = $showsalarymax ? '' : 'display:none;';
    $salarymindisplay = $salaryminraw !== '' ? $salaryminraw : '300000';
    $salarymaxdisplay = $salarymaxraw !== '' ? $salarymaxraw : '300000';
    echo html_writer::start_div('col-md-3 mb-2', array('id' => 'jp-salarymin-wrap', 'style' => $salaryminwrapstyle));
    echo html_writer::tag('label', get_string('salarymin', 'local_jobportal'), array('for' => 'jp-salarymin', 'class' => 'small text-muted d-block'));
    echo html_writer::empty_tag('input', array(
        'type' => 'number',
        'step' => '50000',
        'id' => 'jp-salarymin',
        'name' => 'salarymin',
        'value' => $salarymindisplay,
        'class' => 'form-control',
        'min' => 0,
        'placeholder' => get_string('salarymin', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-3 mb-2', array('id' => 'jp-salarymax-wrap', 'style' => $salarymaxwrapstyle));
    echo html_writer::tag('label', get_string('salarymax', 'local_jobportal'), array('for' => 'jp-salarymax', 'class' => 'small text-muted d-block'));
    echo html_writer::empty_tag('input', array(
        'type' => 'number',
        'step' => '50000',
        'id' => 'jp-salarymax',
        'name' => 'salarymax',
        'value' => $salarymaxdisplay,
        'class' => 'form-control',
        'min' => 0,
        'placeholder' => get_string('salarymax', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('row');
    $stalewrapstyle = $showstaledays ? '' : 'display:none;';
    echo html_writer::start_div('col-md-3 mb-2', array('id' => 'jp-staledays-wrap', 'style' => $stalewrapstyle));
    echo html_writer::tag('label', get_string('staledays', 'local_jobportal'), array('for' => 'jp-staledays', 'class' => 'small text-muted d-block'));
    echo html_writer::empty_tag('input', array(
        'type' => 'number',
        'id' => 'jp-staledays',
        'name' => 'staledays',
        'value' => $staledays,
        'class' => 'form-control',
        'min' => 1,
        'max' => 365,
        'placeholder' => get_string('staledays', 'local_jobportal'),
    ));
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_tag('details');

    echo html_writer::start_div('row');
    echo html_writer::start_div('col-md-3 mb-2');
    echo html_writer::tag('label', get_string('sortby', 'local_jobportal'), array('for' => 'jp-sortby', 'class' => 'small text-muted d-block'));
    echo html_writer::select($sortoptions, 'sortby', $sortby, false, array('class' => 'custom-select', 'id' => 'jp-sortby'));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-2 mb-2');
    echo html_writer::tag('label', get_string('sortdirection', 'local_jobportal'), array('for' => 'jp-sortdir', 'class' => 'small text-muted d-block'));
    echo html_writer::select($sortdiroptions, 'sortdir', $sortdir, false, array('class' => 'custom-select', 'id' => 'jp-sortdir'));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-2 mb-2');
    echo html_writer::tag('label', get_string('perpage', 'local_jobportal'), array('for' => 'jp-perpage', 'class' => 'small text-muted d-block'));
    echo html_writer::select($perpagechoices, 'perpage', $perpage, false, array('class' => 'custom-select', 'id' => 'jp-perpage'));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-5 mb-2 jp-filter-actions pt-md-4');
    echo html_writer::tag('button', get_string('filter'), array('type' => 'submit', 'class' => 'btn btn-primary mr-2'));
    echo html_writer::link($resetfiltersurl, get_string('resetfilters', 'local_jobportal'), array('class' => 'btn btn-outline-secondary'));
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_tag('details', array('class' => 'jp-column-picker mt-2'));
    echo html_writer::tag('summary', get_string('selectcolumns', 'local_jobportal'));
    echo html_writer::empty_tag('input', array(
        'type' => 'search',
        'id' => 'jp-column-search',
        'class' => 'form-control form-control-sm mb-2',
        'placeholder' => get_string('searchcolumns', 'local_jobportal'),
    ));
    foreach ($columngroups as $grouplabel => $keys) {
        echo html_writer::tag('div', $grouplabel, array('class' => 'jp-column-group-title'));
        foreach ($keys as $key) {
            if (!isset($columnoptions[$key])) {
                continue;
            }
            $checked = in_array($key, $selectedcols, true);
            $label = $columnoptions[$key];
            $itemattrs = array('class' => 'jp-column-item', 'data-col-label' => core_text::strtolower(strip_tags($label)));
            echo html_writer::start_tag('div', $itemattrs);
            echo html_writer::checkbox('cols[]', $key, $checked, $label, array('class' => 'mr-2 jp-column-checkbox'));
            echo html_writer::end_div();
        }
    }
    echo html_writer::end_tag('details');

    echo html_writer::end_tag('form');
    echo html_writer::end_div(); // end jp-filter-content-wrap
    if (!empty($activefilterchips)) {
        echo html_writer::start_div('jp-active-filters mt-3');
        echo html_writer::tag('span', get_string('activefilters', 'local_jobportal'), array('class' => 'jp-chipbar-label'));
        foreach ($activefilterchips as $chip) {
            echo html_writer::link($chip['url'], $chip['label'] . ' x', array('class' => 'jp-active-filter-chip'));
        }
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
    echo html_writer::end_div();

    if ($canpost) {
        echo html_writer::link(
            new moodle_url('/local/jobportal/post.php'),
            get_string('postjob', 'local_jobportal'),
            array('class' => 'btn btn-success mb-3')
        );
    }

    if ($canmanagecompanies) {
        echo ' ';
        echo html_writer::link(
            new moodle_url('/local/jobportal/companyprofile.php'),
            get_string('managecompanies', 'local_jobportal'),
            array('class' => 'btn btn-outline-primary mb-3')
        );
    }

    if (has_capability('local/jobportal:managejobs', $context)) {
        echo ' ';
        echo html_writer::link(
            new moodle_url('/local/jobportal/dashboard.php'),
            get_string('managerdashboard', 'local_jobportal'),
            array('class' => 'btn btn-outline-dark mb-3')
        );
        echo ' ';
        echo html_writer::link(
            new moodle_url('/local/jobportal/jobsdashboard.php'),
            get_string('jobpostsdashboard', 'local_jobportal'),
            array('class' => 'btn btn-outline-secondary mb-3')
        );
    }

    $params = array(
        'shortlistedstatus1' => 'shortlisted',
        'shortlistedstatus2' => 'shortlisted',
        'shortlistedstatus3' => 'shortlisted',
        'offermadestatus' => 'offermade',
        'acceptedstatus' => 'accepted',
    );
    $where = array('1=1');
    $having = array();
    $lastactivityhavingexpr = "GREATEST(
        COALESCE(MIN(j.timemodified), 0),
        COALESCE(MIN(j.timecreated), 0),
        COALESCE(MAX(
            GREATEST(
                COALESCE(a.timemodified, 0),
                COALESCE(a.timecreated, 0),
                COALESCE((
                    SELECT MAX(e.timecreated)
                      FROM {local_jobportal_appstage_events} e
                     WHERE e.applicationid = a.id
                ), 0),
                COALESCE((
                    SELECT MAX(n.timecreated)
                      FROM {local_jobportal_appnotes} n
                     WHERE n.applicationid = a.id
                ), 0)
            )
        ), 0)
    )";

    if ($search !== '') {
        $where[] = '(' .
            $DB->sql_like('j.title', ':searchtitle', false) .
            ' OR ' . $DB->sql_like('j.company', ':searchcompany', false) .
            ' OR ' . $DB->sql_like('j.location', ':searchlocation', false) .
            ' OR ' . $DB->sql_like('j.description', ':searchdesc', false) .
            ' OR ' . $DB->sql_like('c.name', ':searchcompanyprofile', false) .
            ')';
        $params['searchtitle'] = '%' . $search . '%';
        $params['searchcompany'] = '%' . $search . '%';
        $params['searchlocation'] = '%' . $search . '%';
        $params['searchdesc'] = '%' . $search . '%';
        $params['searchcompanyprofile'] = '%' . $search . '%';
    }

    if (!empty($companyid)) {
        $where[] = 'j.companyid = :companyid';
        $params['companyid'] = $companyid;
    }

    if ($jobtype !== 'all') {
        $where[] = 'j.jobtype = :jobtype';
        $params['jobtype'] = $jobtype;
    }

    if ($salarymode === 'lt' && $salarymax !== null) {
        $where[] = 'j.salaryminannual IS NOT NULL';
        $where[] = 'j.salaryminannual < :salarylt';
        $params['salarylt'] = $salarymax;
    } else if ($salarymode === 'gt' && $salarymin !== null) {
        $where[] = 'j.salarymaxannual IS NOT NULL';
        $where[] = 'j.salarymaxannual > :salarygt';
        $params['salarygt'] = $salarymin;
    } else if ($salarymode === 'between' && $salarymin !== null && $salarymax !== null) {
        $where[] = 'j.salarymaxannual IS NOT NULL';
        $where[] = 'j.salaryminannual IS NOT NULL';
        $where[] = 'j.salarymaxannual >= :salarymin';
        $where[] = 'j.salaryminannual <= :salarymax';
        $params['salarymin'] = $salarymin;
        $params['salarymax'] = $salarymax;
    } else if ($salarymode === 'undisclosed') {
        $where[] = 'LOWER(j.salarymodel) = :salarymodeundisclosed';
        $params['salarymodeundisclosed'] = 'undisclosed';
    }

    if (!empty($listedfromts)) {
        $where[] = 'j.timecreated >= :listedfrom';
        $params['listedfrom'] = $listedfromts;
    }

    if (!empty($listedtots)) {
        $where[] = 'j.timecreated <= :listedto';
        $params['listedto'] = $listedtots;
    }

    if (!empty($noappsaged) && $hasapps === 'no') {
        $where[] = 'j.timecreated <= :noappslistedbefore';
        $params['noappslistedbefore'] = $now - ($staledays * DAYSECS);
    }

    if (!empty($deadlinefromts)) {
        $where[] = 'j.deadline >= :deadlinefrom';
        $params['deadlinefrom'] = $deadlinefromts;
    }

    if (!empty($deadlinetots)) {
        $where[] = 'j.deadline <= :deadlineto';
        $params['deadlineto'] = $deadlinetots;
    }

    switch ($jobstatus) {
        case 'applicationsopen':
            $where[] = 'j.drivestate = :stateopen';
            $where[] = '(j.deadline IS NULL OR j.deadline = 0 OR j.deadline >= :nowactive)';
            $params['stateopen'] = 'applicationsopen';
            $params['nowactive'] = $now;
            break;
        case 'applicationsclosed':
            $where[] = 'j.drivestate = :stateclosed';
            $params['stateclosed'] = 'applicationsclosed';
            break;
        case 'selectioninprogress':
            $where[] = 'j.drivestate = :stateselection';
            $params['stateselection'] = 'selectioninprogress';
            break;
        case 'completed':
            $where[] = 'j.drivestate = :statecompleted';
            $params['statecompleted'] = 'completed';
            break;
        case 'archived':
            $where[] = 'j.drivestate = :statearchived';
            $params['statearchived'] = 'archived';
            break;
        case 'onhold':
            $where[] = 'j.drivestate = :stateonhold';
            $params['stateonhold'] = 'onhold';
            break;
        case 'cancelled':
            $where[] = 'j.drivestate = :statecancelled';
            $params['statecancelled'] = 'cancelled';
            break;
        case 'expired':
            $where[] = 'j.deadline > 0';
            $where[] = 'j.deadline < :nowexpired';
            $params['nowexpired'] = $now;
            break;
        case 'closingsoon':
            $where[] = 'j.drivestate = :stateopenclosing';
            $where[] = 'j.deadline >= :nowclosing';
            $where[] = 'j.deadline <= :closingsoon';
            $params['stateopenclosing'] = 'applicationsopen';
            $params['nowclosing'] = $now;
            $params['closingsoon'] = $now + (7 * DAYSECS);
            break;
        case 'stale':
            $staleconds = $staledays * DAYSECS;
            // Use aggregate-safe expression for grouped queries across MySQL SQL modes.
            $having[] = '(:stalenow - COALESCE(MAX(a.timecreated), MIN(j.timecreated))) >= :staleseconds';
            $params['stalenow'] = $now;
            $params['staleseconds'] = $staleconds;
            break;
        default:
            break;
    }

    if (!empty($noactivityaged)) {
        $activityseconds = $staledays * DAYSECS;
        $having[] = '(:activitynow - ' . $lastactivityhavingexpr . ') >= :activityseconds';
        $params['activitynow'] = $now;
        $params['activityseconds'] = $activityseconds;
    }

    if ($hasapps === 'yes') {
        $having[] = 'COUNT(a.id) > 0';
    } else if ($hasapps === 'no') {
        $having[] = 'COUNT(a.id) = 0';
    }

    $selectfields = "j.*, c.id AS companyprofileid, c.name AS companyprofilename,
            COUNT(a.id) AS applicationscount,
            SUM(CASE WHEN a.shortliststatus = :shortlistedstatus1 THEN 1 ELSE 0 END) AS shortlistedcount,
            SUM(CASE WHEN a.shortliststatus = :shortlistedstatus2 AND a.status = :offermadestatus THEN 1 ELSE 0 END) AS offermadecount,
            SUM(CASE WHEN a.shortliststatus = :shortlistedstatus3 AND a.status = :acceptedstatus THEN 1 ELSE 0 END) AS acceptedcount,
            MAX(a.timecreated) AS lastapplicationat,
            GREATEST(
                COALESCE(MIN(j.timemodified), 0),
                COALESCE(MIN(j.timecreated), 0),
                COALESCE(MAX(
                    GREATEST(
                        COALESCE(a.timemodified, 0),
                        COALESCE(a.timecreated, 0),
                        COALESCE((
                            SELECT MAX(e.timecreated)
                              FROM {local_jobportal_appstage_events} e
                             WHERE e.applicationid = a.id
                        ), 0),
                        COALESCE((
                            SELECT MAX(n.timecreated)
                              FROM {local_jobportal_appnotes} n
                             WHERE n.applicationid = a.id
                        ), 0)
                    )
                ), 0)
            ) AS lastactivityat";

    $fromsql = "{local_jobportal_jobs} j
         LEFT JOIN {local_jobportal_companies} c ON c.id = j.companyid
         LEFT JOIN {local_jobportal_applications} a ON a.jobid = j.id";

    $whereclause = implode(' AND ', $where);
    $groupsql = ' GROUP BY j.id, c.id';
    $havingsql = empty($having) ? '' : (' HAVING ' . implode(' AND ', $having));

    $sortdirection = $sortdir === 'asc' ? 'ASC' : 'DESC';
    $ordersql = '';
    if ($sortby === 'offerconversion') {
        $ordersql = " ORDER BY (CASE WHEN applicationscount = 0 THEN 0 ELSE acceptedcount / applicationscount END) $sortdirection";
    } else if ($sortby === 'dayssincelastapplication') {
        if ($sortdirection === 'ASC') {
            $ordersql = ' ORDER BY (lastapplicationat IS NULL) ASC, lastapplicationat DESC';
        } else {
            $ordersql = ' ORDER BY (lastapplicationat IS NULL) ASC, lastapplicationat ASC';
        }
    } else if ($sortby === 'daysinactive') {
        if ($sortdirection === 'ASC') {
            $ordersql = ' ORDER BY lastactivityat DESC';
        } else {
            $ordersql = ' ORDER BY lastactivityat ASC';
        }
    } else if ($sortby === 'deadline') {
        $ordersql = " ORDER BY (j.deadline IS NULL OR j.deadline = 0) ASC, j.deadline $sortdirection";
    } else {
        $sortmap = array(
            'listed' => 'j.timecreated',
            'updated' => 'j.timemodified',
            'jobid' => 'j.id',
            'location' => 'j.location',
            'salary' => 'COALESCE(j.salaryminannual, j.salarymaxannual, 0)',
            'applications' => 'applicationscount',
            'shortlisted' => 'shortlistedcount',
            'lastactivity' => 'lastactivityat',
            'company' => 'companyprofilename',
            'title' => 'j.title',
        );
        $orderby = isset($sortmap[$sortby]) ? $sortmap[$sortby] : 'j.timecreated';
        $ordersql = " ORDER BY {$orderby} $sortdirection";
    }
    $ordersql .= ', j.timecreated DESC';

    $countsql = 'SELECT COUNT(1) FROM (SELECT j.id FROM ' . $fromsql . ' WHERE ' . $whereclause . $groupsql . $havingsql . ') jobcount';
    $totaljobs = (int)$DB->count_records_sql($countsql, $params);

    if ($totaljobs === 0) {
        echo html_writer::tag('p', get_string('nojobs', 'local_jobportal'), array('class' => 'alert alert-info'));
    } else {
        $pagingparams = $pageurlparams;
        unset($pagingparams['page']);
        $pagingurl = new moodle_url('/local/jobportal/index.php', $pagingparams);

        $start = ($page * $perpage) + 1;
        $end = min($totaljobs, ($page * $perpage) + $perpage);
        $showing = (object)array('start' => $start, 'end' => $end, 'total' => $totaljobs);
        echo html_writer::div(get_string('showingresults', 'local_jobportal', $showing), 'text-muted mb-2');

        echo html_writer::start_tag('form', array('method' => 'post', 'action' => $PAGE->url, 'class' => 'jp-bulk-form'));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'bulk', 'value' => 1));
        echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));

        $bulkactionoptions = array(
            '' => get_string('bulkaction', 'local_jobportal'),
            'open' => get_string('bulkopen', 'local_jobportal'),
            'close' => get_string('bulkclose', 'local_jobportal'),
            'extenddeadline' => get_string('bulkextenddeadline', 'local_jobportal'),
            'clone' => get_string('bulkclone', 'local_jobportal'),
        );

        echo html_writer::start_div('jp-bulk-actions mb-2');
        echo html_writer::start_div('form-check mr-2 mb-0 jp-bulk-selectall');
        echo html_writer::checkbox('selectall', 1, false, '', array('id' => 'jp-select-all', 'class' => 'form-check-input'));
        echo html_writer::tag('label', get_string('selectall', 'moodle'), array('for' => 'jp-select-all', 'class' => 'form-check-label mb-0'));
        echo html_writer::end_div();
        echo html_writer::select($bulkactionoptions, 'bulkaction', '', false, array('class' => 'custom-select custom-select-sm'));
        echo html_writer::empty_tag('input', array(
            'type' => 'number',
            'name' => 'extenddays',
            'class' => 'form-control form-control-sm',
            'min' => 1,
            'placeholder' => get_string('extenddays', 'local_jobportal'),
        ));
        echo html_writer::tag('button', get_string('applybulkaction', 'local_jobportal'), array('type' => 'submit', 'class' => 'btn btn-sm btn-outline-primary'));
        echo html_writer::end_div();

        echo html_writer::start_tag('div', array('class' => 'table-responsive'));
        $tablewhere = $whereclause . $groupsql . $havingsql . $ordersql;
        $jobstable = new local_jobportal_jobs_table(
            'local-jobportal-jobs',
            $selectedcols,
            $columnoptions,
            $canpost,
            $canviewapplications,
            $dateformat,
            $datetimeformat,
            $now,
            $pagingparams,
            $sortby,
            $sortdir
        );
        $jobstable->define_baseurl($pagingurl);
        $jobstable->set_count_sql($countsql, $params);
        $jobstable->set_sql($selectfields, $fromsql, $tablewhere, $params);
        $jobstable->out($perpage, false);
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('form');
    }
} else {
    // Wrap student view in a modern layout
    echo html_writer::start_tag('div', array('class' => 'jp-student-dashboard'));

    // Hero Section
    echo html_writer::start_tag('div', array('class' => 'jp-page-hero mb-4'));
    echo html_writer::start_tag('div', array('class' => 'jp-page-hero-content text-center'));
    echo html_writer::tag('h2', get_string('jobportal', 'local_jobportal'), array('class' => 'jp-hero-title mb-2'));
    echo html_writer::tag('p', 'Find your next career opportunity', array('class' => 'jp-hero-subtitle mb-4 text-white-50'));

    // Pill-shaped search bar
    echo html_writer::start_tag('form', array('method' => 'get', 'action' => '', 'class' => 'jp-hero-search-form d-flex justify-content-center'));
    echo html_writer::start_tag('div', array('class' => 'input-group jp-search-pill'));
    echo html_writer::empty_tag('input', array(
        'type' => 'text',
        'name' => 'search',
        'id' => 'search',
        'value' => $search,
        'class' => 'form-control jp-search-input',
        'placeholder' => get_string('search') . ' jobs, companies, skills...'
    ));
    echo html_writer::start_tag('div', array('class' => 'input-group-append'));
    echo html_writer::tag('button', '🔍 ' . get_string('search'), array(
        'type' => 'submit',
        'class' => 'btn btn-primary jp-search-btn'
    ));
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('form');
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');

    // Quick Action Bar
    echo html_writer::start_tag('div', array('class' => 'jp-quick-actions mb-4 d-flex justify-content-center flex-wrap gap-2'));
    
    if ($canpost) {
        echo html_writer::link(
            new moodle_url('/local/jobportal/post.php'),
            '➕ ' . get_string('postjob', 'local_jobportal'),
            array('class' => 'btn btn-success jp-action-pill')
        );
    }

    if ($canapply) {
        echo html_writer::link(
            new moodle_url('/local/jobportal/myapplications.php'),
            '📋 ' . get_string('myapplications', 'local_jobportal'),
            array('class' => 'btn btn-outline-primary jp-action-pill')
        );
        echo html_writer::link(
            new moodle_url('/local/jobportal/profile.php'),
            '👤 ' . get_string('myprofile', 'local_jobportal'),
            array('class' => 'btn btn-outline-secondary jp-action-pill')
        );
    }

    if ($canmanagecompanies) {
        echo html_writer::link(
            new moodle_url('/local/jobportal/companyprofile.php'),
            '🏢 ' . get_string('managecompanies', 'local_jobportal'),
            array('class' => 'btn btn-outline-info jp-action-pill')
        );
    }

    if (has_capability('local/jobportal:managejobs', $context)) {
        echo html_writer::link(
            new moodle_url('/local/jobportal/dashboard.php'),
            '📊 ' . get_string('managerdashboard', 'local_jobportal'),
            array('class' => 'btn btn-outline-dark jp-action-pill')
        );
        echo html_writer::link(
            new moodle_url('/local/jobportal/jobsdashboard.php'),
            '⚙️ ' . get_string('jobpostsdashboard', 'local_jobportal'),
            array('class' => 'btn btn-outline-secondary jp-action-pill')
        );
    }
    echo html_writer::end_tag('div');


    $enforcestudentpolicy = $canapply;
    $studentpolicy = $enforcestudentpolicy ? local_jobportal_get_student_job_access_policy() : null;
    $studentpolicyblockers = array();
    if ($enforcestudentpolicy) {
        $studentpolicyblockers = local_jobportal_get_student_apply_policy_blockers((int)$USER->id, $studentpolicy);
    }

    if ($enforcestudentpolicy && $studentpolicy->feedmode === 'openjobs') {
        echo html_writer::tag(
            'div',
            'ℹ️ ' . get_string('studentpolicyonlyopenjobsnotice', 'local_jobportal'),
            array('class' => 'jp-notification-banner jp-notification-info')
        );
    }
    if (!empty($studentpolicyblockers['resumeapproved'])) {
        echo html_writer::tag(
            'div',
            '⚠️ ' . get_string(
                'studentpolicyresumeapprovedrequired',
                'local_jobportal',
                $studentpolicyblockers['resumeapproved']['statuslabel']
            ) . ' <a href="' . new moodle_url('/local/jobportal/profile.php') . '">Update Resume</a>',
            array('class' => 'jp-notification-banner jp-notification-warning')
        );
    }
    if (!empty($studentpolicyblockers['maxactive'])) {
        echo html_writer::tag(
            'div',
            '✋ ' . get_string('studentpolicyapplyblockedmaxactive', 'local_jobportal', (object)$studentpolicyblockers['maxactive']),
            array('class' => 'jp-notification-banner jp-notification-warning')
        );
    }
    if (!empty($studentpolicyblockers['weeklylimit'])) {
        echo html_writer::tag(
            'div',
            '✋ ' . get_string('studentpolicyapplyblockedweeklylimit', 'local_jobportal', (object)$studentpolicyblockers['weeklylimit']),
            array('class' => 'jp-notification-banner jp-notification-warning')
        );
    }
    if (!empty($studentpolicyblockers['cooldown'])) {
        echo html_writer::tag(
            'div',
            '⏳ ' . get_string('studentpolicyapplyblockedcooldown', 'local_jobportal', (object)$studentpolicyblockers['cooldown']),
            array('class' => 'jp-notification-banner jp-notification-warning')
        );
    }

    // Get jobs from database.
    $fromsql = " FROM {local_jobportal_jobs} j
            LEFT JOIN {local_jobportal_companies} c ON c.id = j.companyid
            WHERE j.status = 1";
    $params = array();

    if ($enforcestudentpolicy && $studentpolicy->feedmode === 'openjobs') {
        $fromsql .= " AND j.drivestate = :studentstateopen
                      AND (j.deadline IS NULL OR j.deadline = 0 OR j.deadline >= :studentnow)";
        $params['studentstateopen'] = 'applicationsopen';
        $params['studentnow'] = $now;
    }
    if ($enforcestudentpolicy && !empty($studentpolicyblockers['resumeapproved'])) {
        $fromsql .= " AND 1 = 0";
    }

    if (!empty($search)) {
        $fromsql .= " AND (j.title LIKE :search1 OR j.company LIKE :search2 OR j.description LIKE :search3 OR c.name LIKE :search4)";
        $params['search1'] = '%' . $search . '%';
        $params['search2'] = '%' . $search . '%';
        $params['search3'] = '%' . $search . '%';
        $params['search4'] = '%' . $search . '%';
    }

    $totalsql = "SELECT COUNT(1)" . $fromsql;
    $totaljobs = (int)$DB->count_records_sql($totalsql, $params);

    $sql = "SELECT j.*, c.id AS companyprofileid, c.name AS companyprofilename" . $fromsql . " ORDER BY j.timecreated DESC";
    $jobs = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);
    $userapplicationsbyjob = array();
    $usereventsbyapp = array();
    $stages = array();

    if ($canapply && !empty($jobs)) {
        $jobids = array_keys($jobs);
        list($jobinsql, $jobinparams) = $DB->get_in_or_equal($jobids, SQL_PARAMS_NAMED);
        $appsql = "SELECT id, jobid, status, shortliststatus, currentstageid, timecreated
                     FROM {local_jobportal_applications}
                    WHERE userid = :userid
                      AND jobid $jobinsql";
        $appparams = array_merge(array('userid' => $USER->id), $jobinparams);
        $applications = $DB->get_records_sql($appsql, $appparams);

        foreach ($applications as $application) {
            $userapplicationsbyjob[(int)$application->jobid] = $application;
        }

        if (!empty($applications)) {
            local_jobportal_ensure_default_stages();
            $stages = local_jobportal_get_recruitment_stages(false);

            $appids = array_keys($applications);
            list($appinsql, $appinparams) = $DB->get_in_or_equal($appids, SQL_PARAMS_NAMED);
            $eventsql = "SELECT id, applicationid, stageid, scheduledat, timecreated
                           FROM {local_jobportal_appstage_events}
                          WHERE applicationid $appinsql
                       ORDER BY timecreated ASC";
            $events = $DB->get_records_sql($eventsql, $appinparams);

            foreach ($events as $event) {
                if (!isset($usereventsbyapp[$event->applicationid])) {
                    $usereventsbyapp[$event->applicationid] = array();
                }
                $usereventsbyapp[$event->applicationid][] = $event;
            }
        }
    }

    if (empty($jobs)) {
        echo html_writer::tag('p', get_string('nojobs', 'local_jobportal'), array('class' => 'alert alert-info'));
    } else {
        $pagingparams = array();
        if ($search !== '') {
            $pagingparams['search'] = $search;
        }
        $pagingurl = new moodle_url('/local/jobportal/index.php', $pagingparams);
        if ($totaljobs > $perpage) {
            echo $OUTPUT->paging_bar($totaljobs, $page, $perpage, $pagingurl);
        }

        // Display jobs
        echo html_writer::start_tag('div', array('class' => 'job-listings'));

        foreach ($jobs as $job) {
            $joburl = new moodle_url('/local/jobportal/view.php', array('id' => $job->id));
            $companyname = !empty($job->companyprofilename) ? $job->companyprofilename : $job->company;
            $companylogo = null;
            if (!empty($job->companyprofileid)) {
                $companylogo = local_jobportal_get_company_logo_url((int)$job->companyprofileid, $context);
            }
            $companyinitials = '';
            if ($companyname !== '') {
                $companyparts = preg_split('/\s+/', trim((string)$companyname));
                if (!empty($companyparts)) {
                    $first = core_text::substr($companyparts[0], 0, 1);
                    $second = !empty($companyparts[1]) ? core_text::substr($companyparts[1], 0, 1) : '';
                    $companyinitials = core_text::strtoupper($first . $second);
                }
            }
            if ($companyinitials === '') {
                $companyinitials = '?';
            }
            $salarydisplay = local_jobportal_get_job_salary_display($job, null, true);
            $salaryvalue = $salarydisplay !== '' ? format_string($salarydisplay) : '-';
            $locationvalue = !empty($job->location) ? format_string($job->location) : '-';
            $deadlinevalue = !empty($job->deadline) ? userdate($job->deadline, $datetimeformat) : '-';
            $listedvalue = userdate($job->timecreated, $dateformat);
            $description = shorten_text(trim(strip_tags($job->description)), 320);

            $isapplied = !empty($userapplicationsbyjob[$job->id]);
            $drivestate = local_jobportal_get_job_drive_state($job);
            $drivestatelabel = local_jobportal_get_job_drive_state_label($drivestate);
            $drivestatebadgeclass = local_jobportal_get_job_drive_state_badge_class($drivestate);
            $isexpired = !empty($job->deadline) && ((int)$job->deadline < $now) && $drivestate === 'applicationsopen';
            $isclosingtoday = !empty($job->deadline) && $drivestate === 'applicationsopen' && !$isexpired &&
                (date('Y-m-d', (int)$job->deadline) === date('Y-m-d', $now));
            $isclosingsoon = !empty($job->deadline) && $drivestate === 'applicationsopen' && !$isexpired && !$isclosingtoday &&
                ((int)$job->deadline <= ($now + (7 * DAYSECS)));

            echo html_writer::start_tag('div', array('class' => 'card jp-job-card'));
            echo html_writer::start_tag('div', array('class' => 'card-body jp-job-card-body'));

            echo html_writer::start_div('jp-job-card-head');
            echo html_writer::tag('h5', format_string($job->title), array('class' => 'jp-job-card-title'));
            echo html_writer::start_div('jp-job-company-head');
            if ($companylogo) {
                echo html_writer::empty_tag('img', array(
                    'src' => $companylogo->out(false),
                    'alt' => format_string($companyname),
                    'class' => 'jp-job-company-logo',
                    'loading' => 'lazy',
                ));
            } else {
                echo html_writer::tag('div', s($companyinitials), array('class' => 'jp-job-company-fallback'));
            }
            echo html_writer::start_div('jp-job-company-meta');
            echo html_writer::tag('h6', format_string($companyname), array('class' => 'card-subtitle mb-1 text-muted'));
            if (!empty($job->location)) {
                echo html_writer::div(format_string($job->location), 'jp-job-company-location');
            }
            echo html_writer::end_div();
            echo html_writer::end_div();
            echo html_writer::end_div();

            echo html_writer::start_div('jp-job-card-badges');
            echo html_writer::tag('span', $drivestatelabel, array('class' => $drivestatebadgeclass));
            if ($isexpired) {
                echo html_writer::tag('span', get_string('jobstatusexpired', 'local_jobportal'), array('class' => 'badge badge-danger'));
            } else if ($isclosingtoday) {
                echo html_writer::tag('span', get_string('presetdeadlinetoday', 'local_jobportal'), array('class' => 'badge badge-warning'));
            } else if ($isclosingsoon) {
                echo html_writer::tag('span', get_string('jobstatusclosingsoon', 'local_jobportal'), array('class' => 'badge badge-info'));
            }
            if ($isapplied) {
                echo html_writer::tag('span', get_string('applied', 'local_jobportal'), array('class' => 'badge badge-primary'));
            }
            echo html_writer::end_div();

            echo html_writer::start_div('jp-job-card-meta-grid');
            echo html_writer::div(
                html_writer::div(get_string('salary', 'local_jobportal'), 'jp-job-card-meta-label') .
                    html_writer::div($salaryvalue, 'jp-job-card-meta-value'),
                'jp-job-card-meta-item'
            );
            echo html_writer::div(
                html_writer::div(get_string('deadline', 'local_jobportal'), 'jp-job-card-meta-label') .
                    html_writer::div($deadlinevalue, 'jp-job-card-meta-value'),
                'jp-job-card-meta-item'
            );
            echo html_writer::div(
                html_writer::div(get_string('joblistedon', 'local_jobportal'), 'jp-job-card-meta-label') .
                    html_writer::div($listedvalue, 'jp-job-card-meta-value'),
                'jp-job-card-meta-item'
            );
            echo html_writer::div(
                html_writer::div(get_string('location', 'local_jobportal'), 'jp-job-card-meta-label') .
                    html_writer::div($locationvalue, 'jp-job-card-meta-value'),
                'jp-job-card-meta-item'
            );
            echo html_writer::end_div();

            echo html_writer::div(s($description), 'jp-job-card-desc');

            if ($isapplied) {
                $application = $userapplicationsbyjob[$job->id];
                $events = !empty($usereventsbyapp[$application->id]) ? $usereventsbyapp[$application->id] : array();
                $shortliststatus = local_jobportal_get_applicant_visible_shortlist_status($application);
                $shortlistoptions = local_jobportal_get_shortlist_status_options();
                $shortlistlabel = isset($shortlistoptions[$shortliststatus]) ?
                    $shortlistoptions[$shortliststatus] : get_string('pending', 'local_jobportal');
                $shortlistclass = 'badge badge-secondary';
                if ($shortliststatus === 'pending') {
                    $shortlistclass = 'badge badge-warning';
                } else if ($shortliststatus === 'shortlisted') {
                    $shortlistclass = 'badge badge-success';
                } else if ($shortliststatus === 'notshortlisted') {
                    $shortlistclass = 'badge badge-danger';
                }

                $visiblestage = local_jobportal_get_applicant_visible_stage($application, $events, $stages);
                $poststatuslabel = get_string('poststagenotset', 'local_jobportal');
                if ($shortliststatus !== 'shortlisted') {
                    $poststatuslabel = '-';
                } else if ($visiblestage) {
                    $poststatuslabel = format_string($visiblestage->displayname);
                }
                $poststatusclass = 'badge badge-secondary';
                $offerhighlighthtml = '';
                if ($visiblestage) {
                    switch ($visiblestage->shortname) {
                        case 'accepted':
                            $poststatusclass = 'badge badge-success';
                            break;
                        case 'rejected':
                            $poststatusclass = 'badge badge-danger';
                            break;
                        case 'offermade':
                            $poststatusclass = 'badge badge-primary';
                            break;
                        default:
                            $poststatusclass = 'badge badge-info';
                            break;
                    }
                    if (local_jobportal_is_offer_stage_shortname($visiblestage->shortname)) {
                        $offerchipattrs = array(
                            'class' => 'jp-offer-chip jp-offer-chip--' . $visiblestage->shortname . ' jp-job-offer-chip',
                        );
                        $offeremotion = local_jobportal_get_offer_status_emotion(
                            $visiblestage->shortname,
                            (string)$job->title,
                            (string)$companyname
                        );
                        if ($offeremotion !== '') {
                            $offerchipattrs['title'] = $offeremotion;
                        }
                        $offerhighlighthtml = html_writer::tag(
                            'span',
                            get_string('offerstatus', 'local_jobportal') . ': ' .
                                local_jobportal_get_apply_lock_stage_label($visiblestage->shortname),
                            $offerchipattrs
                        );
                    }
                }

                echo html_writer::start_div('jp-job-user-status');
                echo html_writer::div(get_string('yourapplicationstatus', 'local_jobportal'), 'jp-job-user-status-label');
                echo html_writer::start_div('jp-job-user-status-chips');
                if ($offerhighlighthtml !== '') {
                    echo $offerhighlighthtml;
                }
                echo html_writer::tag('span', $shortlistlabel, array('class' => $shortlistclass));
                if ($shortliststatus === 'shortlisted') {
                    echo html_writer::tag(
                        'span',
                        get_string('postshortliststage', 'local_jobportal') . ': ' . $poststatuslabel,
                        array('class' => $poststatusclass)
                    );
                }
                echo html_writer::end_div();
                echo html_writer::end_div();
            }

            echo html_writer::start_div('jp-job-card-actions');
            echo html_writer::link($joburl, get_string('viewjob', 'local_jobportal'), array('class' => 'btn btn-primary'));
            if (!empty($job->companyprofileid)) {
                echo html_writer::link(
                    new moodle_url('/local/jobportal/company.php', array('id' => $job->companyprofileid)),
                    get_string('viewcompanyprofile', 'local_jobportal'),
                    array('class' => 'btn btn-outline-secondary')
                );
            }
            echo html_writer::end_div();

            echo html_writer::end_tag('div');
            echo html_writer::end_tag('div');
        }

        echo html_writer::end_tag('div');

        if ($totaljobs > $perpage) {
            echo $OUTPUT->paging_bar($totaljobs, $page, $perpage, $pagingurl);
        }
    }
}

echo $OUTPUT->footer();
