<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

/**
 * Get one company record.
 *
 * @param int $companyid
 * @return stdClass|false
 */
function local_jobportal_get_company($companyid) {
    global $DB;

    return $DB->get_record('local_jobportal_companies', array('id' => (int)$companyid));
}

/**
 * Find an existing company by name (case-insensitive).
 *
 * @param string $name
 * @param int $excludeid Optional company ID to exclude.
 * @return stdClass|false
 */
function local_jobportal_find_company_by_name($name, $excludeid = 0) {
    global $DB;

    $trimmed = trim($name);
    if ($trimmed === '') {
        return false;
    }

    $params = array('name' => core_text::strtolower($trimmed));
    $wheresql = 'LOWER(name) = :name';
    if (!empty($excludeid)) {
        $wheresql .= ' AND id <> :excludeid';
        $params['excludeid'] = (int)$excludeid;
    }

    return $DB->get_record_select('local_jobportal_companies', $wheresql, $params, '*', IGNORE_MULTIPLE);
}

/**
 * Get company options for form selectors.
 *
 * @return array<int,string>
 */
function local_jobportal_get_company_options() {
    global $DB;

    $companies = $DB->get_records('local_jobportal_companies', null, 'name ASC', 'id, name');
    $options = array();
    $namecounts = array();
    $systemcontext = context_system::instance();

    foreach ($companies as $company) {
        $normalized = core_text::strtolower(trim((string)$company->name));
        if (!isset($namecounts[$normalized])) {
            $namecounts[$normalized] = 0;
        }
        $namecounts[$normalized]++;
    }

    foreach ($companies as $company) {
        $label = format_string($company->name, true, array('context' => $systemcontext));
        $normalized = core_text::strtolower(trim((string)$company->name));
        if (!empty($namecounts[$normalized]) && $namecounts[$normalized] > 1) {
            $label .= ' (#' . (int)$company->id . ')';
        }
        $options[(int)$company->id] = $label;
    }

    return $options;
}

/**
 * Build company statistics.
 *
 * @param int $companyid
 * @return stdClass
 */
function local_jobportal_get_company_stats($companyid) {
    global $DB;

    $stats = new stdClass();
    $stats->jobsposted = (int)$DB->count_records('local_jobportal_jobs', array('companyid' => (int)$companyid));
    $stats->activejobs = (int)$DB->count_records(
        'local_jobportal_jobs',
        array('companyid' => (int)$companyid, 'status' => 1)
    );
    $stats->applicationsreceived = (int)$DB->count_records_sql(
        "SELECT COUNT(a.id)
           FROM {local_jobportal_jobs} j
      LEFT JOIN {local_jobportal_applications} a ON a.jobid = j.id
          WHERE j.companyid = :companyid",
        array('companyid' => (int)$companyid)
    );

    return $stats;
}

/**
 * Job drive state options.
 *
 * @return array<string,string>
 */
function local_jobportal_get_drive_state_options() {
    return array(
        'applicationsopen' => get_string('drivestate_applicationsopen', 'local_jobportal'),
        'applicationsclosed' => get_string('drivestate_applicationsclosed', 'local_jobportal'),
        'selectioninprogress' => get_string('drivestate_selectioninprogress', 'local_jobportal'),
        'completed' => get_string('drivestate_completed', 'local_jobportal'),
        'archived' => get_string('drivestate_archived', 'local_jobportal'),
        'onhold' => get_string('drivestate_onhold', 'local_jobportal'),
        'cancelled' => get_string('drivestate_cancelled', 'local_jobportal'),
    );
}

/**
 * Job drive completion outcomes.
 *
 * @return array<string,string>
 */
function local_jobportal_get_drive_outcome_options() {
    return array(
        'offersmade' => get_string('driveoutcome_offersmade', 'local_jobportal'),
        'noselection' => get_string('driveoutcome_noselection', 'local_jobportal'),
    );
}

/**
 * Normalize a drive state to supported values.
 *
 * @param string $state
 * @return string
 */
function local_jobportal_normalize_drive_state($state) {
    $normalized = core_text::strtolower(trim((string)$state));
    $allowed = array_keys(local_jobportal_get_drive_state_options());
    if (!in_array($normalized, $allowed, true)) {
        return 'applicationsopen';
    }
    return $normalized;
}

/**
 * Normalize a drive outcome to supported values.
 *
 * @param string $outcome
 * @return string
 */
function local_jobportal_normalize_drive_outcome($outcome) {
    $normalized = core_text::strtolower(trim((string)$outcome));
    $allowed = array_keys(local_jobportal_get_drive_outcome_options());
    if (!in_array($normalized, $allowed, true)) {
        return '';
    }
    return $normalized;
}

/**
 * Resolve effective drive state for a job record.
 *
 * @param stdClass $job
 * @return string
 */
function local_jobportal_get_job_drive_state($job) {
    $hasstoredstate = property_exists($job, 'drivestate') && trim((string)$job->drivestate) !== '';
    $storedstate = $hasstoredstate ? local_jobportal_normalize_drive_state($job->drivestate) : '';

    if (property_exists($job, 'status') && (int)$job->status === 0) {
        if (in_array($storedstate, array('completed', 'archived', 'cancelled'), true)) {
            return $storedstate;
        }
        return 'archived';
    }
    if ($hasstoredstate) {
        return $storedstate;
    }
    if (!empty($job->deadline) && (int)$job->deadline < time()) {
        return 'applicationsclosed';
    }
    return 'applicationsopen';
}

/**
 * Resolve human-readable label for a drive state.
 *
 * @param string $state
 * @return string
 */
function local_jobportal_get_job_drive_state_label($state) {
    $state = local_jobportal_normalize_drive_state($state);
    $options = local_jobportal_get_drive_state_options();
    return isset($options[$state]) ? $options[$state] : $options['applicationsopen'];
}

/**
 * Resolve human-readable label for a drive outcome.
 *
 * @param string $outcome
 * @return string
 */
function local_jobportal_get_job_drive_outcome_label($outcome) {
    $outcome = local_jobportal_normalize_drive_outcome($outcome);
    $options = local_jobportal_get_drive_outcome_options();
    if ($outcome === '' || !isset($options[$outcome])) {
        return '-';
    }
    return $options[$outcome];
}

/**
 * Resolve badge class for a drive state.
 *
 * @param string $state
 * @return string
 */
function local_jobportal_get_job_drive_state_badge_class($state) {
    $state = local_jobportal_normalize_drive_state($state);
    switch ($state) {
        case 'applicationsopen':
            return 'badge badge-success';
        case 'applicationsclosed':
            return 'badge badge-secondary';
        case 'selectioninprogress':
            return 'badge badge-primary';
        case 'completed':
            return 'badge badge-info';
        case 'archived':
            return 'badge badge-dark';
        case 'onhold':
            return 'badge badge-warning';
        case 'cancelled':
            return 'badge badge-danger';
        default:
            return 'badge badge-secondary';
    }
}

/**
 * Validate drive-state transition.
 *
 * @param string $fromstate
 * @param string $tostate
 * @return bool
 */
function local_jobportal_is_drive_transition_allowed($fromstate, $tostate) {
    $fromstate = local_jobportal_normalize_drive_state($fromstate);
    $tostate = local_jobportal_normalize_drive_state($tostate);
    if ($fromstate === $tostate) {
        return true;
    }

    $matrix = array(
        'applicationsopen' => array('applicationsclosed', 'onhold', 'cancelled'),
        'applicationsclosed' => array('applicationsopen', 'selectioninprogress', 'onhold', 'cancelled'),
        'selectioninprogress' => array('applicationsclosed', 'completed', 'onhold', 'cancelled'),
        'completed' => array('selectioninprogress', 'archived', 'onhold'),
        'archived' => array('completed'),
        'onhold' => array('applicationsopen', 'applicationsclosed', 'selectioninprogress', 'cancelled'),
        'cancelled' => array('onhold'),
    );

    return isset($matrix[$fromstate]) && in_array($tostate, $matrix[$fromstate], true);
}

/**
 * Whether the job currently accepts fresh applications.
 *
 * @param stdClass $job
 * @return bool
 */
function local_jobportal_job_accepts_applications($job) {
    if (property_exists($job, 'status') && (int)$job->status !== 1) {
        return false;
    }
    if (local_jobportal_get_job_drive_state($job) !== 'applicationsopen') {
        return false;
    }
    if (!empty($job->deadline) && (int)$job->deadline < time()) {
        return false;
    }
    return true;
}

/**
 * Get URL for an uploaded company logo.
 *
 * @param int $companyid
 * @param context_system|null $context
 * @return moodle_url|null
 */
function local_jobportal_get_company_logo_url($companyid, $context = null) {
    if (!$context) {
        $context = context_system::instance();
    }

    $fs = get_file_storage();
    $files = $fs->get_area_files(
        $context->id,
        'local_jobportal',
        'company_logo',
        (int)$companyid,
        'itemid, filepath, filename',
        false
    );

    if (empty($files)) {
        return null;
    }

    $file = reset($files);
    return moodle_url::make_pluginfile_url(
        $context->id,
        'local_jobportal',
        'company_logo',
        (int)$companyid,
        $file->get_filepath(),
        $file->get_filename()
    );
}

/**
 * Format job type for display.
 *
 * @param string $jobtype
 * @return string
 */
function local_jobportal_format_jobtype($jobtype) {
    $supported = array('fulltime', 'parttime', 'internship', 'contract', 'freelance');
    $normalized = core_text::strtolower(trim((string)$jobtype));
    if (in_array($normalized, $supported, true)) {
        return get_string($normalized, 'local_jobportal');
    }
    return format_string($jobtype, true, array('context' => context_system::instance()));
}

/**
 * Salary model options used in job forms.
 *
 * @return array<string,string>
 */
function local_jobportal_get_salary_model_options() {
    return array(
        'fixed' => get_string('salarymodel_fixed', 'local_jobportal'),
        'range' => get_string('salarymodel_range', 'local_jobportal'),
        'progressive' => get_string('salarymodel_progressive', 'local_jobportal'),
        'undisclosed' => get_string('salarymodel_undisclosed', 'local_jobportal'),
        'custom' => get_string('salarymodel_custom', 'local_jobportal'),
    );
}

/**
 * Salary period options.
 *
 * @return array<string,string>
 */
function local_jobportal_get_salary_period_options() {
    return array(
        'annual' => get_string('salaryperiod_annual', 'local_jobportal'),
        'monthly' => get_string('salaryperiod_monthly', 'local_jobportal'),
    );
}

/**
 * Format a numeric salary amount with currency.
 *
 * @param float|int|string|null $amount
 * @param string $currency
 * @return string
 */
function local_jobportal_format_salary_amount($amount, $currency = 'INR') {
    if ($amount === null || $amount === '') {
        return '';
    }
    $amount = (float)$amount;
    $decimals = (abs($amount - round($amount)) < 0.00001) ? 0 : 2;
    $currency = trim((string)$currency);
    if ($currency === '') {
        $currency = 'INR';
    }
    return $currency . ' ' . number_format($amount, $decimals, '.', ',');
}

/**
 * Normalize a salary amount to annual value.
 *
 * @param float|int|string|null $amount
 * @param string $period
 * @return float|null
 */
function local_jobportal_normalize_salary_to_annual($amount, $period) {
    if ($amount === null || $amount === '' || !is_numeric($amount)) {
        return null;
    }
    $amount = (float)$amount;
    if ($amount < 0) {
        return null;
    }
    $period = core_text::strtolower(trim((string)$period));
    if ($period === 'monthly') {
        return $amount * 12.0;
    }
    return $amount;
}

/**
 * Parse progressive salary lines into structured stages.
 * Expected format (one stage per line): Label|Amount|annual|Condition(optional)
 *
 * @param string $text
 * @return array{stages:array<int,array<string,mixed>>,errors:array<int,string>}
 */
function local_jobportal_parse_salary_progression_text($text) {
    $lines = preg_split('/\r\n|\r|\n/', (string)$text);
    $stages = array();
    $errors = array();
    $allowedperiods = array('annual', 'monthly');
    $sortorder = 1;

    foreach ($lines as $index => $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }

        $parts = array_map('trim', explode('|', $line));
        if (count($parts) < 3) {
            $errors[] = get_string('error:salaryprogressionline', 'local_jobportal', $index + 1);
            continue;
        }

        $label = $parts[0];
        $amountraw = $parts[1];
        $period = core_text::strtolower($parts[2]);
        $conditiontext = '';
        if (count($parts) > 3) {
            $conditiontext = trim(implode('|', array_slice($parts, 3)));
        }

        if ($label === '') {
            $errors[] = get_string('error:salaryprogressionline', 'local_jobportal', $index + 1);
            continue;
        }
        if (!is_numeric($amountraw) || (float)$amountraw <= 0) {
            $errors[] = get_string('error:salaryprogressionamount', 'local_jobportal', $index + 1);
            continue;
        }
        if (!in_array($period, $allowedperiods, true)) {
            $errors[] = get_string('error:salaryprogressionperiod', 'local_jobportal', $index + 1);
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

    return array('stages' => $stages, 'errors' => $errors);
}

/**
 * Get salary stages for a job.
 *
 * @param int $jobid
 * @return array<int,stdClass>
 */
function local_jobportal_get_job_salary_stages($jobid) {
    global $DB;

    return $DB->get_records('local_jobportal_job_salary_stages', array('jobid' => (int)$jobid), 'sortorder ASC, id ASC');
}

/**
 * Replace salary stages for a job with provided rows.
 *
 * @param int $jobid
 * @param array<int,array<string,mixed>> $stages
 * @return void
 */
function local_jobportal_replace_job_salary_stages($jobid, $stages) {
    global $DB;

    $jobid = (int)$jobid;
    $DB->delete_records('local_jobportal_job_salary_stages', array('jobid' => $jobid));

    if (empty($stages)) {
        return;
    }

    $now = time();
    $sortorder = 1;
    foreach ($stages as $stage) {
        if (empty($stage['stagelabel']) || empty($stage['amount']) || empty($stage['period'])) {
            continue;
        }

        $record = new stdClass();
        $record->jobid = $jobid;
        $record->stagelabel = trim((string)$stage['stagelabel']);
        $record->amount = (float)$stage['amount'];
        $record->period = core_text::strtolower(trim((string)$stage['period']));
        $record->conditiontext = !empty($stage['conditiontext']) ? trim((string)$stage['conditiontext']) : null;
        $record->sortorder = !empty($stage['sortorder']) ? (int)$stage['sortorder'] : $sortorder;
        $record->timecreated = $now;
        $record->timemodified = $now;
        $DB->insert_record('local_jobportal_job_salary_stages', $record);
        $sortorder++;
    }
}

/**
 * Build human-readable salary display text.
 *
 * @param string $salarymodel
 * @param string $currency
 * @param string $period
 * @param float|int|string|null $salarymin
 * @param float|int|string|null $salarymax
 * @param string $displaytext
 * @param array<int,stdClass|array<string,mixed>> $stages
 * @return string
 */
function local_jobportal_build_salary_display($salarymodel, $currency, $period, $salarymin, $salarymax, $displaytext = '', $stages = array()) {
    $displaytext = trim((string)$displaytext);
    if ($displaytext !== '') {
        return $displaytext;
    }

    $salarymodel = core_text::strtolower(trim((string)$salarymodel));
    if ($salarymodel === 'undisclosed') {
        return get_string('salaryundisclosed', 'local_jobportal');
    }

    $period = core_text::strtolower(trim((string)$period));
    if ($period !== 'monthly' && $period !== 'annual') {
        $period = 'annual';
    }
    $periodlabel = get_string('salaryperiod_' . $period, 'local_jobportal');

    if ($salarymodel === 'fixed' && $salarymin !== null && $salarymin !== '' && is_numeric($salarymin)) {
        return local_jobportal_format_salary_amount($salarymin, $currency) . ' / ' . $periodlabel;
    }

    if ($salarymodel === 'range' && $salarymin !== null && $salarymax !== null && is_numeric($salarymin) && is_numeric($salarymax)) {
        return local_jobportal_format_salary_amount($salarymin, $currency) . ' - ' .
            local_jobportal_format_salary_amount($salarymax, $currency) . ' / ' . $periodlabel;
    }

    if ($salarymodel === 'progressive' && !empty($stages)) {
        $parts = array();
        foreach ($stages as $stage) {
            $label = '';
            $amount = null;
            $stageperiod = 'annual';
            if (is_object($stage)) {
                $label = !empty($stage->stagelabel) ? $stage->stagelabel : '';
                $amount = $stage->amount ?? null;
                $stageperiod = !empty($stage->period) ? $stage->period : 'annual';
            } else if (is_array($stage)) {
                $label = !empty($stage['stagelabel']) ? $stage['stagelabel'] : '';
                $amount = $stage['amount'] ?? null;
                $stageperiod = !empty($stage['period']) ? $stage['period'] : 'annual';
            }

            $stageperiod = core_text::strtolower(trim((string)$stageperiod));
            if ($stageperiod !== 'monthly' && $stageperiod !== 'annual') {
                $stageperiod = 'annual';
            }
            $chunk = local_jobportal_format_salary_amount($amount, $currency);
            if ($chunk === '') {
                continue;
            }
            $chunk .= ' / ' . get_string('salaryperiod_' . $stageperiod, 'local_jobportal');
            if ($label !== '') {
                $chunk = $label . ': ' . $chunk;
            }
            $parts[] = $chunk;
        }

        if (!empty($parts)) {
            return implode('; ', $parts);
        }
        return get_string('salaryprogressivegeneric', 'local_jobportal');
    }

    return '';
}

/**
 * Resolve job salary text for list/detail views.
 *
 * @param stdClass $job
 * @param array<int,stdClass>|null $stages
 * @param bool $compactprogressive
 * @return string
 */
function local_jobportal_get_job_salary_display($job, $stages = null, $compactprogressive = false) {
    $salarymodel = !empty($job->salarymodel) ? core_text::strtolower((string)$job->salarymodel) : 'custom';
    $compactprogressive = (bool)$compactprogressive;
    $isprogressive = ($salarymodel === 'progressive');

    if (!empty($job->salary) && !($compactprogressive && $isprogressive)) {
        return trim((string)$job->salary);
    }

    if ($salarymodel === 'undisclosed') {
        return get_string('salaryundisclosed', 'local_jobportal');
    }

    if ($stages === null && !empty($job->id) && $isprogressive) {
        $stages = local_jobportal_get_job_salary_stages((int)$job->id);
    }
    if ($stages === null) {
        $stages = array();
    }

    if ($compactprogressive && $isprogressive) {
        $currency = !empty($job->salarycurrency) ? $job->salarycurrency : 'INR';
        $annualperiodlabel = get_string('salaryperiod_annual', 'local_jobportal');
        $annualmin = (isset($job->salaryminannual) && is_numeric($job->salaryminannual)) ? (float)$job->salaryminannual : null;
        $annualmax = (isset($job->salarymaxannual) && is_numeric($job->salarymaxannual)) ? (float)$job->salarymaxannual : null;

        if (($annualmin === null || $annualmax === null) && !empty($stages)) {
            $annuals = array();
            foreach ($stages as $stage) {
                if (is_object($stage)) {
                    $amount = $stage->amount ?? null;
                    $period = $stage->period ?? 'annual';
                } else if (is_array($stage)) {
                    $amount = $stage['amount'] ?? null;
                    $period = $stage['period'] ?? 'annual';
                } else {
                    continue;
                }
                $annual = local_jobportal_normalize_salary_to_annual($amount, $period);
                if ($annual !== null) {
                    $annuals[] = $annual;
                }
            }
            if (!empty($annuals)) {
                if ($annualmin === null) {
                    $annualmin = min($annuals);
                }
                if ($annualmax === null) {
                    $annualmax = max($annuals);
                }
            }
        }

        if ($annualmin !== null && $annualmax !== null && $annualmin > $annualmax) {
            $tmp = $annualmin;
            $annualmin = $annualmax;
            $annualmax = $tmp;
        }

        $summary = '';
        if ($annualmin !== null && $annualmax !== null) {
            if (abs($annualmin - $annualmax) < 0.00001) {
                $summary = local_jobportal_format_salary_amount($annualmin, $currency);
            } else {
                $summary = local_jobportal_format_salary_amount($annualmin, $currency) . ' - ' .
                    local_jobportal_format_salary_amount($annualmax, $currency);
            }
        } else if ($annualmin !== null) {
            $summary = local_jobportal_format_salary_amount($annualmin, $currency);
        } else if ($annualmax !== null) {
            $summary = local_jobportal_format_salary_amount($annualmax, $currency);
        }

        if ($summary !== '') {
            return $summary . ' / ' . $annualperiodlabel . ' (' .
                get_string('salarymodel_progressive', 'local_jobportal') . ')';
        }
        return get_string('salaryprogressivegeneric', 'local_jobportal');
    }

    return local_jobportal_build_salary_display(
        $salarymodel,
        !empty($job->salarycurrency) ? $job->salarycurrency : 'INR',
        !empty($job->salaryperiod) ? $job->salaryperiod : 'annual',
        $job->salarymin ?? null,
        $job->salarymax ?? null,
        '',
        $stages
    );
}

/**
 * Resume status options.
 *
 * @return array<string,string>
 */
function local_jobportal_get_resume_status_options() {
    return array(
        'notsubmitted' => get_string('resumestatus_notsubmitted', 'local_jobportal'),
        'submitted' => get_string('resumestatus_submitted', 'local_jobportal'),
        'underreview' => get_string('resumestatus_underreview', 'local_jobportal'),
        'needsrework' => get_string('resumestatus_needsrework', 'local_jobportal'),
        'approved' => get_string('resumestatus_approved', 'local_jobportal'),
    );
}

/**
 * Normalize a resume status to supported values.
 *
 * @param string $status
 * @return string
 */
function local_jobportal_normalize_resume_status($status) {
    $normalized = core_text::strtolower(trim((string)$status));
    $allowed = array_keys(local_jobportal_get_resume_status_options());
    if (!in_array($normalized, $allowed, true)) {
        return 'notsubmitted';
    }
    return $normalized;
}

/**
 * Resolve resume status badge class.
 *
 * @param string $status
 * @return string
 */
function local_jobportal_resume_status_badge_class($status) {
    $status = local_jobportal_normalize_resume_status($status);
    switch ($status) {
        case 'approved':
            return 'badge badge-success';
        case 'needsrework':
            return 'badge badge-danger';
        case 'underreview':
            return 'badge badge-primary';
        case 'submitted':
            return 'badge badge-warning';
        default:
            return 'badge badge-secondary';
    }
}

/**
 * Resolve shortlist decision badge class.
 *
 * @param string $shortliststatus
 * @return string
 */
function local_jobportal_shortlist_badge_class($shortliststatus) {
    switch (local_jobportal_normalize_shortlist_status($shortliststatus)) {
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
 * Resolve post-shortlisting stage badge class.
 *
 * @param string $shortname
 * @return string
 */
function local_jobportal_post_stage_badge_class($shortname) {
    $shortname = core_text::strtolower(trim((string)$shortname));
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
 * Build a deterministic signature of files in a profile resume area.
 *
 * @param int $profileid
 * @param context_system|null $context
 * @return string
 */
function local_jobportal_get_profile_resume_signature($profileid, $context = null) {
    if (!$context) {
        $context = context_system::instance();
    }

    $fs = get_file_storage();
    $files = $fs->get_area_files(
        $context->id,
        'local_jobportal',
        'profile_resume',
        (int)$profileid,
        'filepath, filename, id',
        false
    );

    if (empty($files)) {
        return '';
    }

    $parts = array();
    foreach ($files as $file) {
        $parts[] = $file->get_filepath() . $file->get_filename() . ':' . $file->get_contenthash();
    }

    sort($parts, SORT_STRING);
    return sha1(implode('|', $parts));
}

/**
 * Persist one resume review history event.
 *
 * @param int $profileid
 * @param int $userid
 * @param string $status
 * @param int|null $rating
 * @param string|null $feedback
 * @param string $action
 * @return void
 */
function local_jobportal_log_resume_review_history($profileid, $userid, $status, $rating = null, $feedback = null, $action = 'managerreview') {
    global $DB;

    $record = new stdClass();
    $record->profileid = (int)$profileid;
    $record->userid = (int)$userid;
    $record->status = local_jobportal_normalize_resume_status($status);
    $record->rating = $rating === null || $rating === '' ? null : (int)$rating;
    $record->feedback = $feedback !== null && trim((string)$feedback) !== '' ? trim((string)$feedback) : null;
    $record->action = core_text::strtolower(trim((string)$action));
    if ($record->action === '') {
        $record->action = 'managerreview';
    }
    $record->timecreated = time();

    $DB->insert_record('local_jobportal_resume_review_hist', $record);
}

/**
 * Get stored resume file for a profile.
 *
 * @param int $profileid
 * @param context_system|null $context
 * @return stored_file|null
 */
function local_jobportal_get_profile_resume_file($profileid, $context = null) {
    if (!$context) {
        $context = context_system::instance();
    }

    $fs = get_file_storage();
    $files = $fs->get_area_files(
        $context->id,
        'local_jobportal',
        'profile_resume',
        (int)$profileid,
        'itemid, filepath, filename',
        false
    );

    if (empty($files)) {
        return null;
    }

    return reset($files);
}

/**
 * Check if a resume file can be previewed inline in browser.
 *
 * @param stored_file $file
 * @return bool
 */
function local_jobportal_resume_file_is_previewable($file) {
    $filename = core_text::strtolower((string)$file->get_filename());
    $mimetype = core_text::strtolower((string)$file->get_mimetype());

    return $mimetype === 'application/pdf' || substr($filename, -4) === '.pdf';
}

/**
 * Get URL for a profile resume file.
 *
 * @param int $profileid
 * @param context_system|null $context
 * @param bool $forcedownload
 * @return moodle_url|null
 */
function local_jobportal_get_profile_resume_url($profileid, $context = null, $forcedownload = false) {
    if (!$context) {
        $context = context_system::instance();
    }

    $file = local_jobportal_get_profile_resume_file((int)$profileid, $context);
    if (!$file) {
        return null;
    }
    return moodle_url::make_pluginfile_url(
        $context->id,
        'local_jobportal',
        'profile_resume',
        (int)$profileid,
        $file->get_filepath(),
        $file->get_filename(),
        $forcedownload
    );
}

/**
 * Check whether a user has uploaded a resume in their profile.
 *
 * @param int $userid
 * @return bool
 */
function local_jobportal_user_has_profile_resume($userid) {
    global $DB;

    $profile = $DB->get_record('local_jobportal_profiles', array('userid' => (int)$userid), 'id');
    if (!$profile) {
        return false;
    }

    return local_jobportal_get_profile_resume_url((int)$profile->id) !== null;
}

/**
 * Resolve student job access policy from plugin settings.
 *
 * @return stdClass
 */
function local_jobportal_get_student_job_access_policy() {
    $config = get_config('local_jobportal');

    $feedmode = isset($config->studentpolicy_feedmode) ? core_text::strtolower(trim((string)$config->studentpolicy_feedmode)) : 'openjobs';
    if (!in_array($feedmode, array('openjobs', 'alljobs'), true)) {
        $feedmode = 'openjobs';
    }

    $maxactive = isset($config->studentpolicy_maxactiveapplications) ? (int)$config->studentpolicy_maxactiveapplications : 0;
    if ($maxactive < 0) {
        $maxactive = 0;
    }

    $weeklylimit = isset($config->studentpolicy_weeklyapplicationlimit) ? (int)$config->studentpolicy_weeklyapplicationlimit : 0;
    if ($weeklylimit < 0) {
        $weeklylimit = 0;
    }

    $cooldownenabled = !empty($config->studentpolicy_notshortlistedcooldownenabled);
    $cooldowntriggercount = isset($config->studentpolicy_notshortlistedtriggercount) ? (int)$config->studentpolicy_notshortlistedtriggercount : 3;
    if ($cooldowntriggercount < 1) {
        $cooldowntriggercount = 1;
    }
    $cooldowndays = isset($config->studentpolicy_notshortlistedcooldowndays) ? (int)$config->studentpolicy_notshortlistedcooldowndays : 14;
    if ($cooldowndays < 1) {
        $cooldowndays = 1;
    } else if ($cooldowndays > 365) {
        $cooldowndays = 365;
    }

    if (isset($config->studentpolicy_blocknoshow)) {
        $blocknoshow = !empty($config->studentpolicy_blocknoshow);
    } else if (isset($config->studentpolicy_blockinterviewnoshow)) {
        // Backward-compatibility with older config key before 2026020607.
        $blocknoshow = !empty($config->studentpolicy_blockinterviewnoshow);
    } else {
        $blocknoshow = true;
    }

    return (object)array(
        'feedmode' => $feedmode,
        'requireresumeapproved' => !empty($config->studentpolicy_requireresumeapproved),
        'blocknoshow' => $blocknoshow,
        'maxactiveapplications' => $maxactive,
        'weeklyapplicationlimit' => $weeklylimit,
        'notshortlistedcooldownenabled' => $cooldownenabled,
        'notshortlistedtriggercount' => $cooldowntriggercount,
        'notshortlistedcooldowndays' => $cooldowndays,
    );
}

/**
 * Get normalized resume status for a user.
 *
 * @param int $userid
 * @return string
 */
function local_jobportal_get_user_resume_status($userid) {
    global $DB;

    $profile = $DB->get_record('local_jobportal_profiles', array('userid' => (int)$userid), 'resumestatus');
    if (!$profile) {
        return 'notsubmitted';
    }
    return local_jobportal_normalize_resume_status($profile->resumestatus);
}

/**
 * Count active applications for a user.
 *
 * @param int $userid
 * @return int
 */
function local_jobportal_count_student_active_applications($userid) {
    global $DB;

    $sql = "userid = :userid
            AND shortliststatus <> :notshortlisted
            AND status <> :accepted
            AND status <> :rejected";
    $params = array(
        'userid' => (int)$userid,
        'notshortlisted' => 'notshortlisted',
        'accepted' => 'accepted',
        'rejected' => 'rejected',
    );
    return (int)$DB->count_records_select('local_jobportal_applications', $sql, $params);
}

/**
 * Evaluate student apply blockers from job access policy.
 *
 * @param int $userid
 * @param stdClass|null $policy
 * @return array<string,mixed>
 */
function local_jobportal_get_student_apply_policy_blockers($userid, $policy = null) {
    global $DB;

    if ($policy === null) {
        $policy = local_jobportal_get_student_job_access_policy();
    }

    $userid = (int)$userid;
    $now = time();
    $blockers = array();

    if (!empty($policy->requireresumeapproved)) {
        $resumestatus = local_jobportal_get_user_resume_status($userid);
        $hasresume = local_jobportal_user_has_profile_resume($userid);
        if ($resumestatus !== 'approved' || !$hasresume) {
            $statusoptions = local_jobportal_get_resume_status_options();
            $statuslabel = isset($statusoptions[$resumestatus]) ? $statusoptions[$resumestatus] : $resumestatus;
            $blockers['resumeapproved'] = array(
                'status' => $resumestatus,
                'statuslabel' => $statuslabel,
            );
        }
    }

    if (!empty($policy->maxactiveapplications)) {
        $activecount = local_jobportal_count_student_active_applications($userid);
        if ($activecount >= (int)$policy->maxactiveapplications) {
            $blockers['maxactive'] = array(
                'current' => $activecount,
                'limit' => (int)$policy->maxactiveapplications,
            );
        }
    }

    if (!empty($policy->weeklyapplicationlimit)) {
        $weekstart = $now - (7 * DAYSECS);
        $weeklycount = (int)$DB->count_records_select(
            'local_jobportal_applications',
            'userid = :userid AND timecreated >= :weekstart',
            array('userid' => $userid, 'weekstart' => $weekstart)
        );
        if ($weeklycount >= (int)$policy->weeklyapplicationlimit) {
            $blockers['weeklylimit'] = array(
                'current' => $weeklycount,
                'limit' => (int)$policy->weeklyapplicationlimit,
            );
        }
    }

    if (!empty($policy->notshortlistedcooldownenabled)) {
        $cutoff = $now - (((int)$policy->notshortlistedcooldowndays) * DAYSECS);
        $notshortlistedcount = (int)$DB->count_records_select(
            'local_jobportal_applications',
            'userid = :userid AND shortliststatus = :status AND timemodified >= :cutoff',
            array(
                'userid' => $userid,
                'status' => 'notshortlisted',
                'cutoff' => $cutoff,
            )
        );
        if ($notshortlistedcount >= (int)$policy->notshortlistedtriggercount) {
            $blockers['cooldown'] = array(
                'current' => $notshortlistedcount,
                'trigger' => (int)$policy->notshortlistedtriggercount,
                'days' => (int)$policy->notshortlistedcooldowndays,
            );
        }
    }

    return $blockers;
}

/**
 * Resume approval mode options.
 *
 * @return array<string,string>
 */
function local_jobportal_get_resume_approval_mode_options() {
    return array(
        'anyone' => get_string('resumeapproval_anyone', 'local_jobportal'),
        'allrequired' => get_string('resumeapproval_allrequired', 'local_jobportal'),
    );
}

/**
 * Normalize resume approval mode.
 *
 * @param string $mode
 * @return string
 */
function local_jobportal_normalize_resume_approval_mode($mode) {
    $normalized = core_text::strtolower(trim((string)$mode));
    $allowed = array_keys(local_jobportal_get_resume_approval_mode_options());
    if (!in_array($normalized, $allowed, true)) {
        return 'allrequired';
    }
    return $normalized;
}

/**
 * Resume assignment status options.
 *
 * @return array<string,string>
 */
function local_jobportal_get_resume_assignment_status_options() {
    return array(
        'assigned' => get_string('resumeassign_assigned', 'local_jobportal'),
        'inreview' => get_string('resumeassign_inreview', 'local_jobportal'),
        'approved' => get_string('resumeassign_approved', 'local_jobportal'),
        'needsrework' => get_string('resumeassign_needsrework', 'local_jobportal'),
    );
}

/**
 * Normalize resume assignment status.
 *
 * @param string $status
 * @return string
 */
function local_jobportal_normalize_resume_assignment_status($status) {
    $normalized = core_text::strtolower(trim((string)$status));
    $allowed = array_keys(local_jobportal_get_resume_assignment_status_options());
    if (!in_array($normalized, $allowed, true)) {
        return 'assigned';
    }
    return $normalized;
}

/**
 * Resolve badge class for resume assignment status.
 *
 * @param string $status
 * @return string
 */
function local_jobportal_resume_assignment_badge_class($status) {
    $status = local_jobportal_normalize_resume_assignment_status($status);
    if ($status === 'approved') {
        return 'badge badge-success';
    }
    if ($status === 'needsrework') {
        return 'badge badge-danger';
    }
    if ($status === 'inreview') {
        return 'badge badge-primary';
    }
    return 'badge badge-secondary';
}

/**
 * Convert resume status into reviewer assignment status.
 *
 * @param string $resumestatus
 * @return string
 */
function local_jobportal_resume_status_to_assignment_status($resumestatus) {
    $resumestatus = local_jobportal_normalize_resume_status($resumestatus);
    if ($resumestatus === 'approved') {
        return 'approved';
    }
    if ($resumestatus === 'needsrework') {
        return 'needsrework';
    }
    if ($resumestatus === 'underreview') {
        return 'inreview';
    }
    return 'assigned';
}

/**
 * Get reviewers who can review resumes.
 *
 * @param context_system|null $context
 * @return array<int,stdClass>
 */
function local_jobportal_get_resume_reviewers($context = null) {
    if (!$context) {
        $context = context_system::instance();
    }

    $users = get_users_by_capability(
        $context,
        'local/jobportal:reviewresumes',
        'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.email',
        'u.lastname ASC, u.firstname ASC'
    );
    if (!$users) {
        return array();
    }

    return $users;
}

/**
 * Get reviewer options for forms.
 *
 * @param context_system|null $context
 * @return array<int,string>
 */
function local_jobportal_get_resume_reviewer_options($context = null) {
    $reviewers = local_jobportal_get_resume_reviewers($context);
    $options = array();
    foreach ($reviewers as $reviewer) {
        $label = fullname($reviewer);
        if (!empty($reviewer->email)) {
            $label .= ' (' . $reviewer->email . ')';
        }
        $options[(int)$reviewer->id] = $label;
    }
    return $options;
}

/**
 * Get assignment summary for a profile and resume version.
 *
 * @param stdClass $profile
 * @param string $resumesignature
 * @return stdClass
 */
function local_jobportal_get_resume_assignment_summary($profile, $resumesignature) {
    global $DB;

    $summary = (object)array(
        'total' => 0,
        'assigned' => 0,
        'inreview' => 0,
        'approved' => 0,
        'needsrework' => 0,
        'pending' => 0,
        'mode' => local_jobportal_normalize_resume_approval_mode(
            property_exists($profile, 'resumeapprovalmode') ? $profile->resumeapprovalmode : 'allrequired'
        ),
        'status' => 'notsubmitted',
    );

    if ($resumesignature === '') {
        return $summary;
    }

    $summary->status = 'submitted';
    $sql = "SELECT status, COUNT(1) AS totalcount
              FROM {local_jobportal_resume_assignments}
             WHERE profileid = :profileid
               AND resumesignature = :resumesignature
          GROUP BY status";
    $rows = $DB->get_records_sql($sql, array(
        'profileid' => (int)$profile->id,
        'resumesignature' => $resumesignature,
    ));
    foreach ($rows as $row) {
        $status = local_jobportal_normalize_resume_assignment_status($row->status);
        $summary->{$status} = (int)$row->totalcount;
        $summary->total += (int)$row->totalcount;
    }

    $summary->pending = (int)$summary->assigned + (int)$summary->inreview;

    if ($summary->needsrework > 0) {
        $summary->status = 'needsrework';
    } else if ($summary->mode === 'allrequired') {
        if ($summary->total > 0 && $summary->approved >= $summary->total) {
            $summary->status = 'approved';
        } else if ($summary->total > 0) {
            $summary->status = 'underreview';
        }
    } else {
        if ($summary->approved > 0) {
            $summary->status = 'approved';
        } else if ($summary->total > 0) {
            $summary->status = 'underreview';
        }
    }

    return $summary;
}

/**
 * Fetch assignments for a profile and resume version.
 *
 * @param int $profileid
 * @param string $resumesignature
 * @return array<int,stdClass>
 */
function local_jobportal_get_resume_assignments_for_version($profileid, $resumesignature) {
    global $DB;

    if ($resumesignature === '') {
        return array();
    }

    $sql = "SELECT a.*, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.email,
                   ua.firstname AS assignerfirstname, ua.lastname AS assignerlastname
              FROM {local_jobportal_resume_assignments} a
              JOIN {user} u ON u.id = a.reviewerid
              JOIN {user} ua ON ua.id = a.assignedby
             WHERE a.profileid = :profileid
               AND a.resumesignature = :resumesignature
          ORDER BY a.timemodified DESC, a.id DESC";
    return $DB->get_records_sql($sql, array(
        'profileid' => (int)$profileid,
        'resumesignature' => $resumesignature,
    ));
}

/**
 * Recompute profile resume status from current reviewer assignments.
 *
 * @param int $profileid
 * @param context_system|null $context
 * @return stdClass
 */
function local_jobportal_refresh_profile_resume_review($profileid, $context = null) {
    global $DB;

    if (!$context) {
        $context = context_system::instance();
    }

    $profile = $DB->get_record('local_jobportal_profiles', array('id' => (int)$profileid), '*', MUST_EXIST);
    $resumesignature = local_jobportal_get_profile_resume_signature((int)$profileid, $context);
    $summary = local_jobportal_get_resume_assignment_summary($profile, $resumesignature);

    $update = new stdClass();
    $update->id = (int)$profile->id;
    $update->resumestatus = $summary->status;
    $update->timemodified = time();
    $update->resumerating = null;
    $update->resumefeedback = null;
    $update->resumereviewedby = null;
    $update->resumereviewedat = null;

    if ($resumesignature !== '' && in_array($summary->status, array('approved', 'needsrework'), true)) {
        $sql = "SELECT *
                  FROM {local_jobportal_resume_assignments}
                 WHERE profileid = :profileid
                   AND resumesignature = :resumesignature
                   AND status = :status
              ORDER BY timereviewed DESC, timemodified DESC, id DESC";
        $latest = $DB->get_record_sql($sql, array(
            'profileid' => (int)$profile->id,
            'resumesignature' => $resumesignature,
            'status' => $summary->status,
        ), IGNORE_MISSING);

        if ($latest) {
            $update->resumerating = $latest->rating === null ? null : (int)$latest->rating;
            $update->resumefeedback = $latest->feedback;
            $update->resumereviewedby = (int)$latest->reviewerid;
            $update->resumereviewedat = !empty($latest->timereviewed) ? (int)$latest->timereviewed : (int)$latest->timemodified;
        }
    }

    $changed = false;
    foreach (array('resumestatus', 'resumerating', 'resumefeedback', 'resumereviewedby', 'resumereviewedat') as $field) {
        $oldvalue = property_exists($profile, $field) ? $profile->{$field} : null;
        $newvalue = $update->{$field};
        if ($oldvalue != $newvalue) {
            $changed = true;
            break;
        }
    }

    if ($changed) {
        $DB->update_record('local_jobportal_profiles', $update);
        $profile = $DB->get_record('local_jobportal_profiles', array('id' => (int)$profileid), '*', MUST_EXIST);
    }

    $summary->statuslabel = local_jobportal_get_resume_status_options()[$summary->status];
    $summary->statusbadge = local_jobportal_resume_status_badge_class($summary->status);

    return (object)array(
        'profile' => $profile,
        'resumesignature' => $resumesignature,
        'summary' => $summary,
    );
}

/**
 * Assign reviewers to current resume version.
 *
 * @param int $profileid
 * @param array<int,int> $reviewerids
 * @param int $assignedby
 * @param context_system|null $context
 * @return int
 */
function local_jobportal_assign_resume_reviewers($profileid, $reviewerids, $assignedby, $context = null) {
    global $DB;

    if (!$context) {
        $context = context_system::instance();
    }

    $resumesignature = local_jobportal_get_profile_resume_signature((int)$profileid, $context);
    if ($resumesignature === '') {
        return 0;
    }

    $reviewerids = array_values(array_unique(array_filter(array_map('intval', $reviewerids))));
    $desiredreviewers = array();
    foreach ($reviewerids as $reviewerid) {
        if ($reviewerid > 0) {
            $desiredreviewers[(int)$reviewerid] = true;
        }
    }

    $existingassignments = $DB->get_records(
        'local_jobportal_resume_assignments',
        array('profileid' => (int)$profileid, 'resumesignature' => $resumesignature),
        '',
        'id, reviewerid'
    );

    $existingbyreviewer = array();
    $removalids = array();
    foreach ($existingassignments as $assignment) {
        $reviewerid = (int)$assignment->reviewerid;
        $existingbyreviewer[$reviewerid] = $assignment;
        if (!isset($desiredreviewers[$reviewerid])) {
            $removalids[] = (int)$assignment->id;
        }
    }

    $changedcount = 0;
    if (!empty($removalids)) {
        $DB->delete_records_list('local_jobportal_resume_assignments', 'id', $removalids);
        $changedcount += count($removalids);
    }

    $now = time();
    $assignedcount = 0;
    foreach (array_keys($desiredreviewers) as $reviewerid) {
        if (isset($existingbyreviewer[$reviewerid])) {
            continue;
        }

        $record = new stdClass();
        $record->profileid = (int)$profileid;
        $record->resumesignature = $resumesignature;
        $record->reviewerid = (int)$reviewerid;
        $record->assignedby = (int)$assignedby;
        $record->status = 'assigned';
        $record->rating = null;
        $record->feedback = null;
        $record->timeassigned = $now;
        $record->timereviewed = null;
        $record->timecreated = $now;
        $record->timemodified = $now;
        $DB->insert_record('local_jobportal_resume_assignments', $record);
        $assignedcount++;
        $changedcount++;
    }

    if ($changedcount > 0) {
        local_jobportal_refresh_profile_resume_review((int)$profileid, $context);
    }

    return $assignedcount;
}

/**
 * Save one reviewer decision for current resume version.
 *
 * @param int $profileid
 * @param int $reviewerid
 * @param string $resumestatus
 * @param int|null $rating
 * @param string|null $feedback
 * @param string $action
 * @param context_system|null $context
 * @return stdClass
 */
function local_jobportal_save_resume_reviewer_decision(
    $profileid,
    $reviewerid,
    $resumestatus,
    $rating = null,
    $feedback = null,
    $action = 'reviewerreview',
    $context = null
) {
    global $DB;

    if (!$context) {
        $context = context_system::instance();
    }

    $resumesignature = local_jobportal_get_profile_resume_signature((int)$profileid, $context);
    if ($resumesignature === '') {
        throw new moodle_exception('error:resumenotuploaded', 'local_jobportal');
    }

    $resumestatus = local_jobportal_normalize_resume_status($resumestatus);
    $assignmentstatus = local_jobportal_resume_status_to_assignment_status($resumestatus);
    $now = time();

    $existing = $DB->get_record('local_jobportal_resume_assignments', array(
        'profileid' => (int)$profileid,
        'resumesignature' => $resumesignature,
        'reviewerid' => (int)$reviewerid,
    ));

    if ($existing) {
        $record = new stdClass();
        $record->id = (int)$existing->id;
        $record->status = $assignmentstatus;
        $record->rating = $rating === null || $rating === '' ? null : (int)$rating;
        $record->feedback = $feedback !== null && trim((string)$feedback) !== '' ? trim((string)$feedback) : null;
        $record->timereviewed = in_array($assignmentstatus, array('approved', 'needsrework'), true) ? $now : null;
        $record->timemodified = $now;
        $DB->update_record('local_jobportal_resume_assignments', $record);
    } else {
        $record = new stdClass();
        $record->profileid = (int)$profileid;
        $record->resumesignature = $resumesignature;
        $record->reviewerid = (int)$reviewerid;
        $record->assignedby = (int)$reviewerid;
        $record->status = $assignmentstatus;
        $record->rating = $rating === null || $rating === '' ? null : (int)$rating;
        $record->feedback = $feedback !== null && trim((string)$feedback) !== '' ? trim((string)$feedback) : null;
        $record->timeassigned = $now;
        $record->timereviewed = in_array($assignmentstatus, array('approved', 'needsrework'), true) ? $now : null;
        $record->timecreated = $now;
        $record->timemodified = $now;
        $DB->insert_record('local_jobportal_resume_assignments', $record);
    }

    local_jobportal_log_resume_review_history(
        (int)$profileid,
        (int)$reviewerid,
        $resumestatus,
        $rating,
        $feedback,
        $action
    );

    return local_jobportal_refresh_profile_resume_review((int)$profileid, $context);
}

/**
 * Default recruitment stage definitions.
 *
 * @return array<int,array<string,mixed>>
 */
function local_jobportal_default_stage_definitions() {
    return array(
        array('shortname' => 'pending', 'displayname' => get_string('pending', 'local_jobportal'), 'sortorder' => 10, 'isterminal' => 0, 'isactive' => 1, 'isinternal' => 0, 'hasscheduledate' => 0),
        array('shortname' => 'internalshortlisted', 'displayname' => get_string('internalshortlisted', 'local_jobportal'), 'sortorder' => 15, 'isterminal' => 0, 'isactive' => 1, 'isinternal' => 1, 'hasscheduledate' => 0),
        array('shortname' => 'screening', 'displayname' => get_string('screening', 'local_jobportal'), 'sortorder' => 20, 'isterminal' => 0, 'isactive' => 1, 'isinternal' => 0, 'hasscheduledate' => 0),
        array('shortname' => 'shortlisted', 'displayname' => get_string('shortlisted', 'local_jobportal'), 'sortorder' => 25, 'isterminal' => 0, 'isactive' => 1, 'isinternal' => 0, 'hasscheduledate' => 0),
        array('shortname' => 'notshortlisted', 'displayname' => get_string('notshortlisted', 'local_jobportal'), 'sortorder' => 26, 'isterminal' => 1, 'isactive' => 1, 'isinternal' => 0, 'hasscheduledate' => 0),
        array('shortname' => 'testscheduled', 'displayname' => get_string('testscheduled', 'local_jobportal'), 'sortorder' => 30, 'isterminal' => 0, 'isactive' => 1, 'isinternal' => 0, 'hasscheduledate' => 1),
        array('shortname' => 'interviewscheduled', 'displayname' => get_string('interviewscheduled', 'local_jobportal'), 'sortorder' => 50, 'isterminal' => 0, 'isactive' => 1, 'isinternal' => 0, 'hasscheduledate' => 1),
        array('shortname' => 'offermade', 'displayname' => get_string('offermade', 'local_jobportal'), 'sortorder' => 70, 'isterminal' => 0, 'isactive' => 1, 'isinternal' => 0, 'hasscheduledate' => 0),
        array('shortname' => 'accepted', 'displayname' => get_string('accepted', 'local_jobportal'), 'sortorder' => 80, 'isterminal' => 1, 'isactive' => 1, 'isinternal' => 0, 'hasscheduledate' => 0),
        array('shortname' => 'rejected', 'displayname' => get_string('rejected', 'local_jobportal'), 'sortorder' => 90, 'isterminal' => 1, 'isactive' => 1, 'isinternal' => 0, 'hasscheduledate' => 0),
    );
}

/**
 * Ensure default recruitment stages exist.
 *
 * @return void
 */
function local_jobportal_ensure_default_stages() {
    global $DB;

    try {
        $DB->get_records('local_jobportal_stages', null, '', 'id', 0, 1);
    } catch (dml_exception $e) {
        return;
    }

    $definitions = local_jobportal_default_stage_definitions();
    foreach ($definitions as $definition) {
        $existing = $DB->get_record('local_jobportal_stages', array('shortname' => $definition['shortname']));
        if ($existing) {
            continue;
        }

        $record = new stdClass();
        $record->shortname = $definition['shortname'];
        $record->displayname = $definition['displayname'];
        $record->sortorder = (int)$definition['sortorder'];
        $record->isterminal = (int)$definition['isterminal'];
        $record->isactive = (int)$definition['isactive'];
        $record->isinternal = (int)$definition['isinternal'];
        $record->hasscheduledate = (int)$definition['hasscheduledate'];
        $record->timecreated = time();
        $record->timemodified = time();
        $DB->insert_record('local_jobportal_stages', $record);
    }
}

/**
 * Get recruitment stages.
 *
 * @param bool $activeonly
 * @return array<int,stdClass>
 */
function local_jobportal_get_recruitment_stages($activeonly = true) {
    global $DB;

    local_jobportal_ensure_default_stages();
    $conditions = $activeonly ? array('isactive' => 1) : null;

    try {
        return $DB->get_records('local_jobportal_stages', $conditions, 'sortorder ASC, id ASC');
    } catch (dml_exception $e) {
        return array();
    }
}

/**
 * Get recruitment stage options for forms.
 *
 * @param bool $activeonly
 * @param bool $includeinternal
 * @param bool $markinternal
 * @return array<int,string>
 */
function local_jobportal_get_recruitment_stage_options($activeonly = true, $includeinternal = true, $markinternal = false) {
    $stages = local_jobportal_get_recruitment_stages($activeonly);
    $options = array();
    $systemcontext = context_system::instance();
    foreach ($stages as $stage) {
        if (!$includeinternal && !empty($stage->isinternal)) {
            continue;
        }

        $label = format_string($stage->displayname, true, array('context' => $systemcontext));
        if ($markinternal && !empty($stage->isinternal)) {
            $label .= ' (' . get_string('internalstage', 'local_jobportal') . ')';
        }
        $options[(int)$stage->id] = $label;
    }
    return $options;
}

/**
 * Get shortlist status options.
 *
 * @return array<string,string>
 */
function local_jobportal_get_shortlist_status_options() {
    return array(
        'pending' => get_string('pending', 'local_jobportal'),
        'internalshortlisted' => get_string('internalshortlisted', 'local_jobportal'),
        'shortlisted' => get_string('shortlisted', 'local_jobportal'),
        'notshortlisted' => get_string('notshortlisted', 'local_jobportal'),
    );
}

/**
 * Normalize shortlist status to a supported value.
 *
 * @param string $status
 * @return string
 */
function local_jobportal_normalize_shortlist_status($status) {
    $normalized = core_text::strtolower(trim((string)$status));
    $allowed = array_keys(local_jobportal_get_shortlist_status_options());
    if (!in_array($normalized, $allowed, true)) {
        return 'pending';
    }
    return $normalized;
}

/**
 * Schedule status options for stage events.
 *
 * @return array<string,string>
 */
function local_jobportal_get_schedule_status_options() {
    return array(
        'scheduled' => get_string('schedulestatus_scheduled', 'local_jobportal'),
        'rescheduled' => get_string('schedulestatus_rescheduled', 'local_jobportal'),
        'completed' => get_string('schedulestatus_completed', 'local_jobportal'),
        'cancelled' => get_string('schedulestatus_cancelled', 'local_jobportal'),
        'noshow' => get_string('schedulestatus_noshow', 'local_jobportal'),
        'excused' => get_string('schedulestatus_excused', 'local_jobportal'),
    );
}

/**
 * Normalize schedule status.
 *
 * @param string $status
 * @return string
 */
function local_jobportal_normalize_schedule_status($status) {
    $normalized = core_text::strtolower(trim((string)$status));
    $allowed = array_keys(local_jobportal_get_schedule_status_options());
    if (!in_array($normalized, $allowed, true)) {
        return 'scheduled';
    }
    return $normalized;
}

/**
 * Resolve schedule status label.
 *
 * @param string $status
 * @return string
 */
function local_jobportal_get_schedule_status_label($status) {
    $status = local_jobportal_normalize_schedule_status($status);
    $options = local_jobportal_get_schedule_status_options();
    return isset($options[$status]) ? $options[$status] : $options['scheduled'];
}

/**
 * Round outcome options for schedulable stage events.
 *
 * @return array<string,string>
 */
function local_jobportal_get_round_outcome_options() {
    return array(
        'pending' => get_string('roundoutcome_pending', 'local_jobportal'),
        'cleared' => get_string('roundoutcome_cleared', 'local_jobportal'),
        'notcleared' => get_string('roundoutcome_notcleared', 'local_jobportal'),
    );
}

/**
 * Normalize round outcome.
 *
 * @param string $outcome
 * @return string
 */
function local_jobportal_normalize_round_outcome($outcome) {
    $normalized = core_text::strtolower(trim((string)$outcome));
    $allowed = array_keys(local_jobportal_get_round_outcome_options());
    if (!in_array($normalized, $allowed, true)) {
        return 'pending';
    }
    return $normalized;
}

/**
 * Resolve round outcome label.
 *
 * @param string $outcome
 * @return string
 */
function local_jobportal_get_round_outcome_label($outcome) {
    $outcome = local_jobportal_normalize_round_outcome($outcome);
    $options = local_jobportal_get_round_outcome_options();
    return isset($options[$outcome]) ? $options[$outcome] : $options['pending'];
}

/**
 * Schedule mode options for stage events.
 *
 * @return array<string,string>
 */
function local_jobportal_get_schedule_mode_options() {
    return array(
        'online' => get_string('schedulemode_online', 'local_jobportal'),
        'offline' => get_string('schedulemode_offline', 'local_jobportal'),
        'hybrid' => get_string('schedulemode_hybrid', 'local_jobportal'),
    );
}

/**
 * Normalize schedule mode.
 *
 * @param string $mode
 * @return string
 */
function local_jobportal_normalize_schedule_mode($mode) {
    $normalized = core_text::strtolower(trim((string)$mode));
    $allowed = array_keys(local_jobportal_get_schedule_mode_options());
    if (!in_array($normalized, $allowed, true)) {
        return '';
    }
    return $normalized;
}

/**
 * Resolve schedule mode label.
 *
 * @param string $mode
 * @return string
 */
function local_jobportal_get_schedule_mode_label($mode) {
    $mode = local_jobportal_normalize_schedule_mode($mode);
    if ($mode === '') {
        return '';
    }
    $options = local_jobportal_get_schedule_mode_options();
    return isset($options[$mode]) ? $options[$mode] : '';
}

/**
 * Resolve shortlist status for an application.
 *
 * @param stdClass $application
 * @return string
 */
function local_jobportal_get_application_shortlist_status($application) {
    if (property_exists($application, 'shortliststatus') && trim((string)$application->shortliststatus) !== '') {
        return local_jobportal_normalize_shortlist_status($application->shortliststatus);
    }

    $status = core_text::strtolower(trim((string)$application->status));
    if ($status === 'internalshortlisted') {
        return 'internalshortlisted';
    }
    if ($status === 'notshortlisted') {
        return 'notshortlisted';
    }
    // Legacy statuses kept for older records after Done stages were removed from flow.
    if ($status === 'testdone' || $status === 'interviewdone') {
        return 'shortlisted';
    }

    $shortlistedstates = array_merge(array('shortlisted'), local_jobportal_get_post_shortlist_stage_shortnames());
    if (in_array($status, $shortlistedstates, true)) {
        return 'shortlisted';
    }

    return 'pending';
}

/**
 * Resolve shortlist status that can be shown to applicants.
 *
 * @param stdClass $application
 * @return string
 */
function local_jobportal_get_applicant_visible_shortlist_status($application) {
    $status = local_jobportal_get_application_shortlist_status($application);
    if ($status === 'internalshortlisted') {
        return 'pending';
    }
    return $status;
}

/**
 * Post-shortlist stage shortnames.
 *
 * @return array<int,string>
 */
function local_jobportal_get_post_shortlist_stage_shortnames() {
    return array(
        'testscheduled',
        'interviewscheduled',
        'offermade',
        'accepted',
        'rejected',
    );
}

/**
 * Get post-shortlist recruitment stages.
 *
 * @param bool $activeonly
 * @param bool $includeinternal
 * @return array<int,stdClass>
 */
function local_jobportal_get_post_shortlist_stages($activeonly = true, $includeinternal = true) {
    $allowed = array_flip(local_jobportal_get_post_shortlist_stage_shortnames());
    $stages = local_jobportal_get_recruitment_stages($activeonly);
    $result = array();

    foreach ($stages as $stage) {
        if (!isset($allowed[$stage->shortname])) {
            continue;
        }
        if (!$includeinternal && !empty($stage->isinternal)) {
            continue;
        }
        $result[(int)$stage->id] = $stage;
    }

    return $result;
}

/**
 * Get post-shortlist stage options for forms.
 *
 * @param bool $activeonly
 * @param bool $includeinternal
 * @param bool $markinternal
 * @return array<int,string>
 */
function local_jobportal_get_post_shortlist_stage_options($activeonly = true, $includeinternal = true, $markinternal = false) {
    $stages = local_jobportal_get_post_shortlist_stages($activeonly, $includeinternal);
    $options = array();
    $systemcontext = context_system::instance();

    foreach ($stages as $stage) {
        $label = format_string($stage->displayname, true, array('context' => $systemcontext));
        if ($markinternal && !empty($stage->isinternal)) {
            $label .= ' (' . get_string('internalstage', 'local_jobportal') . ')';
        }
        $options[(int)$stage->id] = $label;
    }

    return $options;
}

/**
 * Resolve stage object for an application.
 *
 * @param stdClass $application
 * @param array<int,stdClass>|null $stages
 * @return stdClass|null
 */
function local_jobportal_get_application_stage($application, $stages = null) {
    global $DB;

    if (local_jobportal_get_application_shortlist_status($application) !== 'shortlisted') {
        return null;
    }

    if ($stages === null) {
        $stages = local_jobportal_get_recruitment_stages(false);
    }

    $allowed = array_flip(local_jobportal_get_post_shortlist_stage_shortnames());

    if (!empty($application->currentstageid) && isset($stages[(int)$application->currentstageid])) {
        $stage = $stages[(int)$application->currentstageid];
        if (isset($allowed[$stage->shortname])) {
            return $stage;
        }
    }

    if (!empty($application->status)) {
        $legacy = core_text::strtolower(trim((string)$application->status));
        if (!isset($allowed[$legacy])) {
            return null;
        }

        foreach ($stages as $stage) {
            if ($stage->shortname === $legacy) {
                return $stage;
            }
        }

        $stage = $DB->get_record('local_jobportal_stages', array('shortname' => $legacy));
        if ($stage) {
            return $stage;
        }
    }

    return null;
}

/**
 * Get stage events visible to applicants (non-internal only).
 *
 * @param array<int,stdClass> $events
 * @param array<int,stdClass>|null $stages
 * @return array<int,stdClass>
 */
function local_jobportal_get_applicant_visible_stage_events($events, $stages = null) {
    if ($stages === null) {
        $stages = local_jobportal_get_recruitment_stages(false);
    }

    $allowed = array_flip(local_jobportal_get_post_shortlist_stage_shortnames());
    $visible = array();
    foreach ($events as $event) {
        $stageid = !empty($event->stageid) ? (int)$event->stageid : 0;
        if (empty($stageid) || !isset($stages[$stageid])) {
            continue;
        }
        if (!isset($allowed[$stages[$stageid]->shortname])) {
            continue;
        }
        if (!empty($stages[$stageid]->isinternal)) {
            continue;
        }
        $visible[] = $event;
    }

    return $visible;
}

/**
 * Resolve the latest non-internal stage that can be shown to applicants.
 *
 * @param stdClass $application
 * @param array<int,stdClass> $events
 * @param array<int,stdClass>|null $stages
 * @return stdClass|null
 */
function local_jobportal_get_applicant_visible_stage($application, $events = array(), $stages = null) {
    if (local_jobportal_get_application_shortlist_status($application) !== 'shortlisted') {
        return null;
    }

    if ($stages === null) {
        $stages = local_jobportal_get_recruitment_stages(false);
    }

    $allowed = array_flip(local_jobportal_get_post_shortlist_stage_shortnames());
    for ($index = count($events) - 1; $index >= 0; $index--) {
        $event = $events[$index];
        $stageid = !empty($event->stageid) ? (int)$event->stageid : 0;
        if (empty($stageid) || !isset($stages[$stageid])) {
            continue;
        }
        if (empty($stages[$stageid]->isinternal) && isset($allowed[$stages[$stageid]->shortname])) {
            return $stages[$stageid];
        }
    }

    $stage = local_jobportal_get_application_stage($application, $stages);
    if ($stage && empty($stage->isinternal)) {
        return $stage;
    }

    return null;
}

/**
 * Application statuses that lock new applications for a student.
 *
 * @return array<int,string>
 */
function local_jobportal_get_apply_lock_trigger_statuses() {
    return array('offermade', 'accepted', 'rejected');
}

/**
 * Get localized stage label used in apply-lock messaging.
 *
 * @param string $status
 * @return string
 */
function local_jobportal_get_apply_lock_stage_label($status) {
    $status = core_text::strtolower(trim((string)$status));
    $labels = array(
        'offermade' => get_string('offermade', 'local_jobportal'),
        'accepted' => get_string('accepted', 'local_jobportal'),
        'rejected' => get_string('rejected', 'local_jobportal'),
    );
    if (isset($labels[$status])) {
        return $labels[$status];
    }
    if ($status === '') {
        return '-';
    }
    return $status;
}

/**
 * Get apply override record for a student.
 *
 * @param int $userid
 * @return stdClass|false
 */
function local_jobportal_get_student_apply_override($userid) {
    global $DB;

    try {
        return $DB->get_record('local_jobportal_apply_overrides', array('userid' => (int)$userid), '*', IGNORE_MISSING);
    } catch (dml_exception $e) {
        return false;
    }
}

/**
 * Whether an apply override is currently active.
 *
 * @param stdClass|false $override
 * @param int|null $now
 * @return bool
 */
function local_jobportal_is_student_apply_override_active($override, $now = null) {
    if (empty($override) || empty($override->isenabled)) {
        return false;
    }

    if ($now === null) {
        $now = time();
    }

    if (!empty($override->expiresat) && (int)$override->expiresat < (int)$now) {
        return false;
    }
    return true;
}

/**
 * Whether a manager manual apply block is currently active.
 *
 * @param stdClass|false $override
 * @param int|null $now
 * @return bool
 */
function local_jobportal_is_student_apply_manual_block_active($override, $now = null) {
    if (empty($override) || empty($override->isblocked)) {
        return false;
    }

    if ($now === null) {
        $now = time();
    }

    if (!empty($override->blockexpiresat) && (int)$override->blockexpiresat < (int)$now) {
        return false;
    }
    return true;
}

/**
 * Create or update apply override for a student.
 *
 * @param int $userid
 * @param int|bool $enabled
 * @param string|null $reason
 * @param int|null $expiresat
 * @param int $setby
 * @param int|bool|null $blockenabled
 * @param string|null $blockreason
 * @param int|null $blockexpiresat
 * @return stdClass|false
 */
function local_jobportal_save_student_apply_override(
    $userid,
    $enabled,
    $reason,
    $expiresat,
    $setby,
    $blockenabled = null,
    $blockreason = null,
    $blockexpiresat = null
) {
    global $DB;

    $userid = (int)$userid;
    $enabled = !empty($enabled) ? 1 : 0;
    $setby = (int)$setby;
    $now = time();

    $reason = trim((string)$reason);
    if ($reason === '' || !$enabled) {
        $reason = null;
    }

    if (!$enabled) {
        $expiresat = null;
    } else if ($expiresat !== null && $expiresat !== '') {
        $expiresat = (int)$expiresat;
        if ($expiresat <= 0) {
            $expiresat = null;
        }
    } else {
        $expiresat = null;
    }

    $existing = local_jobportal_get_student_apply_override($userid);

    if ($blockenabled === null) {
        $blockenabled = ($existing && !empty($existing->isblocked)) ? 1 : 0;
        $blockreason = ($existing && isset($existing->blockreason) && $existing->blockreason !== '') ? (string)$existing->blockreason : null;
        $blockexpiresat = ($existing && !empty($existing->blockexpiresat)) ? (int)$existing->blockexpiresat : null;
    } else {
        $blockenabled = !empty($blockenabled) ? 1 : 0;
        $blockreason = trim((string)$blockreason);
        if ($blockreason === '' || !$blockenabled) {
            $blockreason = null;
        }

        if (!$blockenabled) {
            $blockexpiresat = null;
        } else if ($blockexpiresat !== null && $blockexpiresat !== '') {
            $blockexpiresat = (int)$blockexpiresat;
            if ($blockexpiresat <= 0) {
                $blockexpiresat = null;
            }
        } else {
            $blockexpiresat = null;
        }
    }

    if ($existing) {
        $update = new stdClass();
        $update->id = (int)$existing->id;
        $update->isenabled = $enabled;
        $update->reason = $reason;
        $update->expiresat = $expiresat;
        $update->isblocked = $blockenabled;
        $update->blockreason = $blockreason;
        $update->blockexpiresat = $blockexpiresat;
        $update->setby = $setby;
        $update->timemodified = $now;
        $DB->update_record('local_jobportal_apply_overrides', $update);
    } else {
        $record = new stdClass();
        $record->userid = $userid;
        $record->isenabled = $enabled;
        $record->reason = $reason;
        $record->expiresat = $expiresat;
        $record->isblocked = $blockenabled;
        $record->blockreason = $blockreason;
        $record->blockexpiresat = $blockexpiresat;
        $record->setby = $setby;
        $record->timecreated = $now;
        $record->timemodified = $now;
        $DB->insert_record('local_jobportal_apply_overrides', $record);
    }

    return local_jobportal_get_student_apply_override($userid);
}

/**
 * Resolve whether a student is locked from applying to new jobs.
 *
 * @param int $userid
 * @return stdClass
 */
function local_jobportal_get_student_apply_lock_info($userid) {
    global $DB;

    $userid = (int)$userid;
    $now = time();

    $info = (object)array(
        'userid' => $userid,
        'locked' => false,
        'lockreason' => '',
        'triggerapplicationid' => 0,
        'triggerjobid' => 0,
        'triggerstatus' => '',
        'triggerstatuslabel' => '',
        'triggereventid' => 0,
        'overrideexists' => false,
        'overrideenabled' => false,
        'overrideactive' => false,
        'overrideexpiresat' => null,
        'overridereason' => '',
        'manualblockenabled' => false,
        'manualblockactive' => false,
        'manualblockexpiresat' => null,
        'manualblockreason' => '',
    );

    $override = local_jobportal_get_student_apply_override($userid);
    if ($override) {
        $info->overrideexists = true;
        $info->overrideenabled = !empty($override->isenabled);
        $info->overrideactive = local_jobportal_is_student_apply_override_active($override, $now);
        $info->overrideexpiresat = !empty($override->expiresat) ? (int)$override->expiresat : null;
        $info->overridereason = !empty($override->reason) ? (string)$override->reason : '';
        $info->manualblockenabled = !empty($override->isblocked);
        $info->manualblockactive = local_jobportal_is_student_apply_manual_block_active($override, $now);
        $info->manualblockexpiresat = !empty($override->blockexpiresat) ? (int)$override->blockexpiresat : null;
        $info->manualblockreason = !empty($override->blockreason) ? (string)$override->blockreason : '';
    }

    if ($info->manualblockactive) {
        $info->locked = true;
        $info->lockreason = 'manualblock';
        return $info;
    }

    try {
        $triggerstatuses = local_jobportal_get_apply_lock_trigger_statuses();
        if (!empty($triggerstatuses)) {
            list($statussql, $statusparams) = $DB->get_in_or_equal($triggerstatuses, SQL_PARAMS_NAMED, 'lockstatus');
            $sqlparams = array_merge(
                array(
                    'userid' => $userid,
                ),
                $statusparams
            );
            $triggerrecord = $DB->get_record_sql(
                "SELECT id, jobid, status
                   FROM {local_jobportal_applications}
                  WHERE userid = :userid
                    AND status $statussql
               ORDER BY timemodified DESC, timecreated DESC, id DESC",
                $sqlparams,
                IGNORE_MISSING
            );

            if ($triggerrecord) {
                $info->triggerapplicationid = (int)$triggerrecord->id;
                $info->triggerjobid = (int)$triggerrecord->jobid;
                $info->triggerstatus = core_text::strtolower(trim((string)$triggerrecord->status));
                $info->triggerstatuslabel = local_jobportal_get_apply_lock_stage_label($info->triggerstatus);
            }
        }
    } catch (dml_exception $e) {
        return $info;
    }

    if (!empty($info->triggerapplicationid) && !$info->overrideactive) {
        $info->locked = true;
        $info->lockreason = 'offerstage';
        return $info;
    }

    if (!$info->overrideactive) {
        $policy = local_jobportal_get_student_job_access_policy();
        if (!empty($policy->blocknoshow)) {
            try {
                $noshowrecord = $DB->get_record_sql(
                    "SELECT e.id, e.applicationid, a.jobid
                       FROM {local_jobportal_appstage_events} e
                       JOIN {local_jobportal_applications} a ON a.id = e.applicationid
                       JOIN {local_jobportal_stages} s ON s.id = e.stageid
                      WHERE a.userid = :userid
                        AND s.hasscheduledate = :hasscheduledate
                        AND e.schedulestatus = :noshow
                   ORDER BY e.timecreated DESC, e.id DESC",
                    array(
                        'userid' => $userid,
                        'hasscheduledate' => 1,
                        'noshow' => 'noshow',
                    ),
                    IGNORE_MISSING
                );
                if ($noshowrecord) {
                    $info->triggereventid = (int)$noshowrecord->id;
                    $info->triggerapplicationid = (int)$noshowrecord->applicationid;
                    $info->triggerjobid = (int)$noshowrecord->jobid;
                    $info->triggerstatus = 'noshow';
                    $info->triggerstatuslabel = get_string('schedulestatus_noshow', 'local_jobportal');
                    $info->locked = true;
                    $info->lockreason = 'noshow';
                }
            } catch (dml_exception $e) {
                // Ignore lock lookup failures and leave eligibility as-is.
            }
        }
    }

    return $info;
}

/**
 * Build apply-lock message text for student-facing screens.
 *
 * @param stdClass $applylockinfo
 * @param bool $iserror
 * @return string
 */
function local_jobportal_get_student_apply_lock_message($applylockinfo, $iserror = false) {
    if (empty($applylockinfo) || empty($applylockinfo->locked)) {
        return '';
    }

    $reason = !empty($applylockinfo->lockreason) ? (string)$applylockinfo->lockreason : 'offerstage';
    if ($reason === 'manualblock') {
        return get_string($iserror ? 'error:applylockedmanualblock' : 'applylockednotice_manualblock', 'local_jobportal');
    }

    if ($reason === 'noshow') {
        $a = (object)array(
            'jobid' => !empty($applylockinfo->triggerjobid) ? (int)$applylockinfo->triggerjobid : '-',
        );
        return get_string($iserror ? 'error:applylockednoshow' : 'applylockednotice_noshow', 'local_jobportal', $a);
    }

    $a = (object)array(
        'stage' => !empty($applylockinfo->triggerstatuslabel) ? $applylockinfo->triggerstatuslabel : '-',
        'jobid' => !empty($applylockinfo->triggerjobid) ? (int)$applylockinfo->triggerjobid : '-',
    );
    return get_string($iserror ? 'error:applylockedoffer' : 'applylockednotice', 'local_jobportal', $a);
}

/**
 * Whether a stage shortname is an offer-stage status.
 *
 * @param string $shortname
 * @return bool
 */
function local_jobportal_is_offer_stage_shortname($shortname) {
    $shortname = core_text::strtolower(trim((string)$shortname));
    return in_array($shortname, local_jobportal_get_apply_lock_trigger_statuses(), true);
}

/**
 * Resolve a student's current highlighted offer status.
 *
 * Prioritizes Accepted, then Offer Made, then Offer Rejected.
 *
 * @param int $userid
 * @return stdClass
 */
function local_jobportal_get_student_offer_highlight($userid) {
    global $DB;

    $userid = (int)$userid;
    $info = (object)array(
        'hasoffer' => false,
        'status' => '',
        'statuslabel' => '',
        'jobid' => 0,
        'jobtitle' => '',
        'company' => '',
        'offerdate' => 0,
        'timemodified' => 0,
    );

    try {
        $statuses = local_jobportal_get_apply_lock_trigger_statuses();
        list($statussql, $statusparams) = $DB->get_in_or_equal($statuses, SQL_PARAMS_NAMED, 'offstat');
        $sql = "SELECT a.id, a.jobid, a.status, a.offermadeat, a.timemodified,
                       j.title AS jobtitle, j.company, c.name AS companyname
                  FROM {local_jobportal_applications} a
                  JOIN {local_jobportal_jobs} j ON j.id = a.jobid
             LEFT JOIN {local_jobportal_companies} c ON c.id = j.companyid
                 WHERE a.userid = :userid
                   AND a.status $statussql
              ORDER BY CASE a.status
                           WHEN 'accepted' THEN 1
                           WHEN 'offermade' THEN 2
                           WHEN 'rejected' THEN 3
                           ELSE 9
                       END ASC,
                       a.timemodified DESC,
                       a.id DESC";
        $params = array_merge(array('userid' => $userid), $statusparams);
        $record = $DB->get_record_sql($sql, $params, IGNORE_MISSING);
        if (!$record) {
            return $info;
        }

        $status = core_text::strtolower(trim((string)$record->status));
        $company = !empty($record->companyname) ? $record->companyname : $record->company;
        $info->hasoffer = true;
        $info->status = $status;
        $info->statuslabel = local_jobportal_get_apply_lock_stage_label($status);
        $info->jobid = (int)$record->jobid;
        $info->jobtitle = (string)$record->jobtitle;
        $info->company = (string)$company;
        $info->offerdate = !empty($record->offermadeat) ? (int)$record->offermadeat : (!empty($record->timemodified) ? (int)$record->timemodified : 0);
        $info->timemodified = !empty($record->timemodified) ? (int)$record->timemodified : 0;
    } catch (dml_exception $e) {
        return $info;
    }

    return $info;
}

/**
 * Resolve student-facing emotional copy template for offer-stage statuses.
 *
 * @param string $status
 * @return string
 */
function local_jobportal_get_offer_status_emotion_template($status) {
    $pluginconfig = get_config('local_jobportal');
    $status = core_text::strtolower(trim((string)$status));
    $map = array(
        'offermade' => array(
            'setting' => 'offer_message_offermade',
            'defaultstring' => 'offeremotion_offermade',
        ),
        'accepted' => array(
            'setting' => 'offer_message_accepted',
            'defaultstring' => 'offeremotion_accepted',
        ),
        'rejected' => array(
            'setting' => 'offer_message_rejected',
            'defaultstring' => 'offeremotion_rejected',
        ),
    );
    if (!isset($map[$status])) {
        return '';
    }

    $settingkey = $map[$status]['setting'];
    if (isset($pluginconfig->$settingkey)) {
        $custom = trim((string)$pluginconfig->$settingkey);
        if ($custom !== '') {
            return $custom;
        }
    }

    return get_string($map[$status]['defaultstring'], 'local_jobportal');
}

/**
 * Resolve student-facing emotional copy for offer-stage statuses as plain text.
 *
 * Supports placeholders: {jobtitle}, {company}.
 *
 * @param string $status
 * @param string $jobtitle
 * @param string $company
 * @return string
 */
function local_jobportal_get_offer_status_emotion($status, $jobtitle = '', $company = '') {
    $template = local_jobportal_get_offer_status_emotion_template($status);
    if ($template === '') {
        return '';
    }

    return str_replace(
        array('{jobtitle}', '{company}'),
        array(trim((string)$jobtitle), trim((string)$company)),
        $template
    );
}

/**
 * Resolve student-facing emotional copy for offer-stage statuses as safe HTML.
 *
 * Supports placeholders: {jobtitle}, {company}.
 *
 * @param string $status
 * @param string $jobtitle
 * @param string $company
 * @return string
 */
function local_jobportal_get_offer_status_emotion_html($status, $jobtitle = '', $company = '') {
    $template = local_jobportal_get_offer_status_emotion_template($status);
    if ($template === '') {
        return '';
    }

    $jobtitletoken = html_writer::span(s(trim((string)$jobtitle)), 'jp-offer-msg-token jp-offer-msg-token--jobtitle');
    $companytoken = html_writer::span(s(trim((string)$company)), 'jp-offer-msg-token jp-offer-msg-token--company');

    $safehtml = s($template);
    $safehtml = str_replace(s('{jobtitle}'), $jobtitletoken, $safehtml);
    $safehtml = str_replace(s('{company}'), $companytoken, $safehtml);

    return $safehtml;
}

/**
 * Require plugin-wide stylesheet.
 *
 * @return void
 */
function local_jobportal_require_styles() {
    global $PAGE;

    $PAGE->requires->css(new moodle_url('/local/jobportal/styles.css'));
    $PAGE->requires->js_call_amd('local_jobportal/filters_toggle', 'init');
}

/**
 * Render standard bulletproof filter toggle button.
 *
 * @param string $targetid Target HTML element ID (without #)
 * @param string $storagekey LocalStorage key
 * @return string HTML button
 */
function local_jobportal_render_filter_toggle_button($targetid, $storagekey) {
    $hidetext = '👁️ ' . get_string('hidefilters', 'local_jobportal');
    $showtext = '👁️ ' . get_string('showfilters', 'local_jobportal');
    $onclick = "var t=document.getElementById('" . s($targetid) . "');if(t){var h=t.style.display==='none'||t.classList.contains('jp-collapsed');t.style.display=h?'':'none';if(h){t.classList.remove('jp-collapsed');this.innerHTML='" . s($hidetext) . "';this.classList.remove('btn-primary');this.classList.add('btn-outline-secondary');}else{t.classList.add('jp-collapsed');this.innerHTML='" . s($showtext) . "';this.classList.add('btn-primary');this.classList.remove('btn-outline-secondary');}try{localStorage.setItem('" . s($storagekey) . "',h?'0':'1');}catch(e){}}return false;";

    return html_writer::tag(
        'button',
        $hidetext,
        array(
            'type' => 'button',
            'class' => 'btn btn-sm btn-outline-secondary jp-toggle-filters-btn',
            'data-target' => '#' . $targetid,
            'data-storage-key' => $storagekey,
            'data-show-text' => $showtext,
            'data-hide-text' => $hidetext,
            'aria-expanded' => 'true',
            'onclick' => $onclick,
        )
    );
}

/**
 * Build manager "All Jobs" URL using saved index preferences.
 *
 * @param int $userid
 * @return moodle_url
 */
function local_jobportal_get_manager_index_url($userid) {
    $userid = (int)$userid;
    if ($userid <= 0) {
        return new moodle_url('/local/jobportal/index.php');
    }

    $prefix = 'local_jobportal_index_';
    $params = array();

    $search = trim((string)get_user_preferences($prefix . 'search', '', $userid));
    if ($search !== '') {
        $params['search'] = $search;
    }

    $perpage = (int)get_user_preferences($prefix . 'perpage', 25, $userid);
    if (in_array($perpage, array(25, 50, 100), true) && $perpage !== 25) {
        $params['perpage'] = $perpage;
    }

    $companyid = (int)get_user_preferences($prefix . 'companyid', 0, $userid);
    if ($companyid > 0) {
        $params['companyid'] = $companyid;
    }

    $jobstatus = trim((string)get_user_preferences($prefix . 'jobstatus', 'all', $userid));
    if ($jobstatus !== '' && $jobstatus !== 'all') {
        $params['jobstatus'] = $jobstatus;
    }

    $jobtype = trim((string)get_user_preferences($prefix . 'jobtype', 'all', $userid));
    if ($jobtype !== '' && $jobtype !== 'all') {
        $params['jobtype'] = $jobtype;
    }

    $salarymode = trim((string)get_user_preferences($prefix . 'salarymode', 'all', $userid));
    if ($salarymode !== '' && $salarymode !== 'all') {
        $params['salarymode'] = $salarymode;
    }

    $salarymin = trim((string)get_user_preferences($prefix . 'salarymin', '', $userid));
    if ($salarymin !== '') {
        $params['salarymin'] = $salarymin;
    }
    $salarymax = trim((string)get_user_preferences($prefix . 'salarymax', '', $userid));
    if ($salarymax !== '') {
        $params['salarymax'] = $salarymax;
    }

    $hasapps = trim((string)get_user_preferences($prefix . 'hasapps', 'all', $userid));
    if ($hasapps !== '' && $hasapps !== 'all') {
        $params['hasapps'] = $hasapps;
    }

    $staledays = (int)get_user_preferences($prefix . 'staledays', 14, $userid);
    if ($staledays > 0 && $staledays !== 14) {
        $params['staledays'] = $staledays;
    }

    $listedfrom = trim((string)get_user_preferences($prefix . 'listedfrom', '', $userid));
    if ($listedfrom !== '') {
        $params['listedfrom'] = $listedfrom;
    }
    $listedto = trim((string)get_user_preferences($prefix . 'listedto', '', $userid));
    if ($listedto !== '') {
        $params['listedto'] = $listedto;
    }
    $deadlinefrom = trim((string)get_user_preferences($prefix . 'deadlinefrom', '', $userid));
    if ($deadlinefrom !== '') {
        $params['deadlinefrom'] = $deadlinefrom;
    }
    $deadlineto = trim((string)get_user_preferences($prefix . 'deadlineto', '', $userid));
    if ($deadlineto !== '') {
        $params['deadlineto'] = $deadlineto;
    }

    $sortby = trim((string)get_user_preferences($prefix . 'sortby', 'listed', $userid));
    if ($sortby !== '' && $sortby !== 'listed') {
        $params['sortby'] = $sortby;
    }
    $sortdir = core_text::strtolower(trim((string)get_user_preferences($prefix . 'sortdir', 'desc', $userid)));
    if ($sortdir === 'asc') {
        $params['sortdir'] = 'asc';
    }

    $preset = trim((string)get_user_preferences($prefix . 'preset', '', $userid));
    if ($preset !== '') {
        $params['preset'] = $preset;
    }

    $cols = trim((string)get_user_preferences($prefix . 'cols', '', $userid));
    if ($cols !== '') {
        $params['cols'] = $cols;
    }

    return new moodle_url('/local/jobportal/index.php', $params);
}

/**
 * Render a quick navigation bar for Job Portal pages.
 *
 * @param context $context
 * @param string $current
 * @param array<int,array<string,mixed>> $extralinks
 * @return string
 */
function local_jobportal_render_navigation($context, $current = '', $extralinks = array()) {
    global $USER;

    $ismanager = has_capability('local/jobportal:postjobs', $context) ||
        has_capability('local/jobportal:managejobs', $context) ||
        has_capability('local/jobportal:viewapplications', $context) ||
        has_capability('local/jobportal:managecompanyprofile', $context) ||
        has_capability('local/jobportal:reviewresumes', $context) ||
        has_capability('local/jobportal:assignresumereviewers', $context);
    $canreviewresumes = has_capability('local/jobportal:reviewresumes', $context);
    $canassignresumereviewers = has_capability('local/jobportal:assignresumereviewers', $context);

    $indexurl = new moodle_url('/local/jobportal/index.php');
    if ($ismanager && !empty($USER->id)) {
        $indexurl = local_jobportal_get_manager_index_url((int)$USER->id);
    }

    $links = array(
        array(
            'key' => 'index',
            'label' => get_string('alljobs', 'local_jobportal'),
            'url' => $indexurl,
        ),
    );

    if (has_capability('local/jobportal:apply', $context) && !$ismanager) {
        $links[] = array(
            'key' => 'myapplications',
            'label' => get_string('myapplications', 'local_jobportal'),
            'url' => new moodle_url('/local/jobportal/myapplications.php'),
        );
        $links[] = array(
            'key' => 'profile',
            'label' => get_string('myprofile', 'local_jobportal'),
            'url' => new moodle_url('/local/jobportal/profile.php'),
        );
    }

    if (has_capability('local/jobportal:postjobs', $context)) {
        $links[] = array(
            'key' => 'post',
            'label' => get_string('postjob', 'local_jobportal'),
            'url' => new moodle_url('/local/jobportal/post.php'),
        );
    }

    if (has_capability('local/jobportal:managejobs', $context)) {
        $links[] = array(
            'key' => 'dashboard',
            'label' => get_string('managerdashboard', 'local_jobportal'),
            'url' => new moodle_url('/local/jobportal/dashboard.php'),
        );
        $links[] = array(
            'key' => 'jobsdashboard',
            'label' => get_string('jobpostsdashboard', 'local_jobportal'),
            'url' => new moodle_url('/local/jobportal/jobsdashboard.php'),
        );
        $links[] = array(
            'key' => 'stages',
            'label' => get_string('managestages', 'local_jobportal'),
            'url' => new moodle_url('/local/jobportal/stages.php'),
        );
    }

    if (has_capability('local/jobportal:managecompanyprofile', $context)) {
        $links[] = array(
            'key' => 'companies',
            'label' => get_string('managecompanies', 'local_jobportal'),
            'url' => new moodle_url('/local/jobportal/companyprofile.php'),
        );
    }

    if ($canassignresumereviewers) {
        $links[] = array(
            'key' => 'resumequeue',
            'label' => get_string('resumequeue', 'local_jobportal'),
            'url' => new moodle_url('/local/jobportal/resume_queue.php'),
        );
    }

    if ($canreviewresumes) {
        $links[] = array(
            'key' => 'myresumereviews',
            'label' => get_string('myresumereviews', 'local_jobportal'),
            'url' => new moodle_url('/local/jobportal/myreviews.php'),
        );
    }

    $studentofferhighlight = null;
    if (has_capability('local/jobportal:apply', $context) && !$ismanager && $current !== 'myapplications' && !empty($USER->id)) {
        $studentofferhighlight = local_jobportal_get_student_offer_highlight((int)$USER->id);
    }

    foreach ($extralinks as $item) {
        if (empty($item['url']) || empty($item['label'])) {
            continue;
        }
        $links[] = $item;
    }

    $html = html_writer::start_tag('div', array('class' => 'card mb-3 jp-quick-nav jp-quick-nav--sticky'));
    $html .= html_writer::start_tag('div', array('class' => 'card-body py-2'));
    $html .= html_writer::start_div('jp-quick-nav-links');

    foreach ($links as $link) {
        $iscurrent = !empty($link['key']) && $link['key'] === $current;
        $class = $iscurrent ? 'btn btn-sm btn-primary' : 'btn btn-sm btn-outline-primary';
        $html .= html_writer::link($link['url'], $link['label'], array('class' => $class));
    }

    $html .= html_writer::end_div();
    $html .= html_writer::end_tag('div');
    $html .= html_writer::end_tag('div');

    if (!empty($studentofferhighlight->hasoffer)) {
        $statusclass = preg_replace('/[^a-z0-9_-]/i', '', (string)$studentofferhighlight->status);
        $toneclass = 'jp-offer-tone-' . $statusclass;
        $emotionhtml = local_jobportal_get_offer_status_emotion_html(
            $statusclass,
            (string)$studentofferhighlight->jobtitle,
            (string)$studentofferhighlight->company
        );
        $jobtitle = format_string($studentofferhighlight->jobtitle);
        $company = format_string($studentofferhighlight->company);
        $updated = !empty($studentofferhighlight->timemodified) ? userdate((int)$studentofferhighlight->timemodified, '%d/%m/%Y %H:%M') : '-';
        $jobcompanytext = $jobtitle;
        if (trim($company) !== '') {
            $jobcompanytext .= ' | ' . $company;
        }
        $statusbadge = html_writer::tag('span', $studentofferhighlight->statuslabel, array(
            'class' => 'jp-offer-status-inline jp-offer-status-inline--' . $statusclass,
        ));

        $html .= html_writer::start_div('card mb-3 jp-offer-global-banner ' . $toneclass);
        $html .= html_writer::start_div('card-body py-2');
        $html .= html_writer::start_div('jp-offer-banner-main');
        $html .= html_writer::start_div('jp-offer-banner-left');
        $html .= html_writer::div($jobcompanytext . ' ' . $statusbadge, 'jp-offer-banner-job');
        if ($emotionhtml !== '') {
            $html .= html_writer::div($emotionhtml, 'jp-offer-banner-emotion jp-offer-banner-emotion--' . $statusclass);
        }
        $html .= html_writer::div(get_string('offerhighlightupdated', 'local_jobportal', $updated), 'jp-offer-banner-meta');
        $html .= html_writer::end_div();
        $html .= html_writer::end_div();
        $html .= html_writer::end_div();
        $html .= html_writer::end_div();
    }

    return $html;
}
