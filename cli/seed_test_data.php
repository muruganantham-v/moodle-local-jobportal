<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/../locallib.php');

$help = "Seed test companies and jobs for local_jobportal.

Options:
--companies=INT         Number of companies to create (default: 10)
--jobspercompany=INT    Number of jobs per company (default: 3)
--managerusername=TEXT  Use this existing username as owner/poster (optional)
--prefix=TEXT           Suffix tag appended to generated records (default: TestSeed)
--daysback=INT          Spread created dates over last N days (default: 30)
--help, -h              Show this help

Examples:
php local/jobportal/cli/seed_test_data.php
php local/jobportal/cli/seed_test_data.php --companies=20 --jobspercompany=5
php local/jobportal/cli/seed_test_data.php --managerusername=manager
";

list($options, $unrecognized) = cli_get_params(
    array(
        'help' => false,
        'companies' => 10,
        'jobspercompany' => 3,
        'managerusername' => '',
        'prefix' => 'TestSeed',
        'daysback' => 30,
    ),
    array(
        'h' => 'help',
    )
);

if (!empty($unrecognized)) {
    cli_error('Unknown options: ' . implode(', ', $unrecognized));
}

if (!empty($options['help'])) {
    echo $help;
    exit(0);
}

$companiescount = max(1, min(500, (int)$options['companies']));
$jobspercompany = max(1, min(50, (int)$options['jobspercompany']));
$daysback = max(1, min(365, (int)$options['daysback']));
$prefix = trim((string)$options['prefix']);
if ($prefix === '') {
    $prefix = 'TestSeed';
}
$managerusername = trim((string)$options['managerusername']);

$seeduser = null;

if ($managerusername !== '') {
    $seeduser = $DB->get_record(
        'user',
        array('username' => $managerusername, 'deleted' => 0),
        'id, username, firstname, lastname',
        IGNORE_MISSING
    );
    if (!$seeduser) {
        cli_error('managerusername was provided but no matching active user was found.');
    }
} else {
    $seeduser = $DB->get_record_sql(
        "SELECT u.id, u.username, u.firstname, u.lastname
           FROM {role_assignments} ra
           JOIN {role} r ON r.id = ra.roleid
           JOIN {user} u ON u.id = ra.userid
          WHERE r.shortname = :managershortname
            AND u.deleted = 0
            AND u.suspended = 0
       ORDER BY ra.id ASC",
        array('managershortname' => 'manager'),
        IGNORE_MULTIPLE
    );
}

if (!$seeduser) {
    $seeduser = $DB->get_record(
        'user',
        array('id' => 2, 'deleted' => 0),
        'id, username, firstname, lastname',
        IGNORE_MISSING
    );
}

if (!$seeduser) {
    $seeduser = $DB->get_record_sql(
        "SELECT id, username, firstname, lastname
           FROM {user}
          WHERE deleted = 0
       ORDER BY id ASC",
        null,
        IGNORE_MULTIPLE
    );
}

if (!$seeduser) {
    cli_error('No active Moodle user found to assign as company owner/job poster.');
}

$jobtypes = array('fulltime', 'parttime', 'internship', 'contract', 'freelance');
$basecompanies = array(
    'Tata Elxsi',
    'KPIT Technologies',
    'L&T Technology Services',
    'Bosch Global Software Technologies',
    'Cyient',
    'HCLTech Engineering and R&D Services',
    'Wipro Engineering Edge',
    'Infosys Engineering Services',
    'Tech Mahindra Engineering',
    'Sasken Technologies',
    'HARMAN India',
    'Continental Automotive India',
    'Visteon India',
    'Valeo India',
    'NXP Semiconductors India',
    'Renesas Electronics India',
    'STMicroelectronics India',
    'Texas Instruments India',
    'Siemens EDA India',
    'Qualcomm India',
);
$locations = array(
    'Bengaluru, Karnataka, India',
    'Pune, Maharashtra, India',
    'Hyderabad, Telangana, India',
    'Chennai, Tamil Nadu, India',
    'Noida, Uttar Pradesh, India',
    'Gurugram, Haryana, India',
    'Coimbatore, Tamil Nadu, India',
    'Mysuru, Karnataka, India',
    'Thiruvananthapuram, Kerala, India',
    'Ahmedabad, Gujarat, India',
    'Remote - India',
);
$roles = array(
    'Embedded Software Engineer',
    'Firmware Engineer',
    'BSP Engineer',
    'Device Driver Engineer',
    'AUTOSAR Engineer',
    'Embedded Validation Engineer',
    'Hardware Design Engineer',
    'VLSI Verification Engineer',
    'Functional Safety Engineer',
    'Systems Engineer - Embedded',
);

$runstamp = gmdate('YmdHis');
$seedtag = preg_replace('/[^A-Za-z0-9]+/', '', $prefix);
if ($seedtag === '') {
    $seedtag = 'Seed';
}

mt_srand();
$now = time();
$companiescreated = 0;
$jobscreated = 0;

$transaction = $DB->start_delegated_transaction();

for ($c = 1; $c <= $companiescount; $c++) {
    $basecompany = $basecompanies[($c - 1) % count($basecompanies)];
    $companyname = $basecompany . ' - ' . $prefix . ' ' . $runstamp . '-' . str_pad((string)$c, 2, '0', STR_PAD_LEFT);
    $companycreated = $now - mt_rand(0, $daysback * DAYSECS);
    $webslug = preg_replace('/[^a-z0-9]+/', '-', core_text::strtolower($basecompany));
    $webslug = trim($webslug, '-');
    if ($webslug === '') {
        $webslug = 'company-' . $c;
    }

    $company = new stdClass();
    $company->userid = (int)$seeduser->id;
    $company->name = $companyname;
    $company->description = 'Test company profile generated for QA and workflow validation using Indian embedded hiring samples.';
    $company->website = 'https://careers.' . $webslug . '.example.in/' . strtolower($seedtag);
    $company->timecreated = $companycreated;
    $company->timemodified = $companycreated;

    $companyid = (int)$DB->insert_record('local_jobportal_companies', $company);
    $companiescreated++;

    for ($j = 1; $j <= $jobspercompany; $j++) {
        $jobindex = (($c - 1) * $jobspercompany) + $j;
        $role = $roles[$jobindex % count($roles)];
        $jobcreated = $now - mt_rand(0, $daysback * DAYSECS);
        $isactive = (mt_rand(1, 100) <= 85) ? 1 : 0;
        $deadline = null;
        if (mt_rand(1, 100) <= 80) {
            $deadline = $jobcreated + (mt_rand(10, 90) * DAYSECS);
        }

        $job = new stdClass();
        $job->title = $role . ' - Batch ' . $runstamp . '-' . str_pad((string)$jobindex, 3, '0', STR_PAD_LEFT);
        $job->description = 'This is seeded test data for validating job listing and application workflows.';
        $job->company = $companyname;
        $job->companyid = $companyid;
        $job->location = $locations[$jobindex % count($locations)];
        $job->jobtype = $jobtypes[$jobindex % count($jobtypes)];
        $job->salarycurrency = 'INR';
        $job->salaryperiod = 'annual';
        $job->salarymodel = 'fixed';
        $job->salarymin = null;
        $job->salarymax = null;
        $job->salaryminannual = null;
        $job->salarymaxannual = null;
        $salarystages = array();

        $salarymodepick = mt_rand(1, 100);
        if ($salarymodepick <= 55) {
            $job->salarymodel = 'fixed';
            $fixed = mt_rand(300000 / 50000, 1400000 / 50000) * 50000;
            $job->salarymin = $fixed;
            $job->salarymax = $fixed;
            $job->salaryminannual = $fixed;
            $job->salarymaxannual = $fixed;
        } else if ($salarymodepick <= 85) {
            $job->salarymodel = 'range';
            $rangemin = mt_rand(300000 / 50000, 1000000 / 50000) * 50000;
            $rangemax = $rangemin + (mt_rand(1, 8) * 50000);
            $job->salarymin = $rangemin;
            $job->salarymax = $rangemax;
            $job->salaryminannual = $rangemin;
            $job->salarymaxannual = $rangemax;
        } else {
            $job->salarymodel = 'progressive';
            $job->salaryperiod = 'annual';
            $initialmonthly = mt_rand(25000 / 5000, 50000 / 5000) * 5000;
            $laterannual = mt_rand(400000 / 50000, 1100000 / 50000) * 50000;
            $salarystages = array(
                array(
                    'stagelabel' => 'Initial',
                    'amount' => $initialmonthly,
                    'period' => 'monthly',
                    'conditiontext' => 'Training period',
                    'sortorder' => 1,
                ),
                array(
                    'stagelabel' => 'Performance',
                    'amount' => $laterannual,
                    'period' => 'annual',
                    'conditiontext' => 'Post-confirmation',
                    'sortorder' => 2,
                ),
            );
            $job->salaryminannual = $initialmonthly * 12;
            $job->salarymaxannual = max($initialmonthly * 12, $laterannual);
        }
        $job->salary = local_jobportal_build_salary_display(
            $job->salarymodel,
            $job->salarycurrency,
            $job->salaryperiod,
            $job->salarymin,
            $job->salarymax,
            '',
            $salarystages
        );
        $job->deadline = $deadline;
        $job->requirements = "Sample requirements:\n- Basic communication skills\n- Embedded systems fundamentals\n- C/C++ and debugging exposure";
        $job->status = $isactive;
        $job->postedby = (int)$seeduser->id;
        $job->timecreated = $jobcreated;
        $job->timemodified = $jobcreated;

        $newjobid = (int)$DB->insert_record('local_jobportal_jobs', $job);
        if ($job->salarymodel === 'progressive') {
            local_jobportal_replace_job_salary_stages($newjobid, $salarystages);
        }
        $jobscreated++;
    }
}

$transaction->allow_commit();

cli_writeln('Seed complete for local_jobportal.');
cli_writeln('User used for ownership/posting: ' . $seeduser->username . ' (ID ' . $seeduser->id . ')');
cli_writeln('Companies created: ' . $companiescreated);
cli_writeln('Jobs created: ' . $jobscreated);
