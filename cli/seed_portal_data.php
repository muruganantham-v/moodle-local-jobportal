<?php
// This file is part of Moodle - http://moodle.org/

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/../locallib.php');

$help = "Seed comprehensive test data for local_jobportal including profiles, resume assignments, applications, and stages.

Options:
--companies=INT         Number of companies to create (default: 6)
--jobspercompany=INT    Number of jobs per company (default: 2)
--clear                 Truncate all jobportal tables and delete file storage before seeding (default: true)
--help, -h              Show this help
";

list($options, $unrecognized) = cli_get_params(
    array(
        'help' => false,
        'companies' => 6,
        'jobspercompany' => 2,
        'clear' => true,
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

global $DB, $CFG;

$companiescount = max(1, min(100, (int)$options['companies']));
$jobspercompany = max(1, min(20, (int)$options['jobspercompany']));
$clear = !empty($options['clear']);

$syscontext = context_system::instance();

if ($clear) {
    cli_writeln('Clearing existing portal data and files...');
    $DB->execute("DELETE FROM {local_jobportal_companies}");
    $DB->execute("DELETE FROM {local_jobportal_jobs}");
    $DB->execute("DELETE FROM {local_jobportal_job_salary_stages}");
    $DB->execute("DELETE FROM {local_jobportal_applications}");
    $DB->execute("DELETE FROM {local_jobportal_appstage_events}");
    $DB->execute("DELETE FROM {local_jobportal_appnotes}");
    $DB->execute("DELETE FROM {local_jobportal_profiles}");
    $DB->execute("DELETE FROM {local_jobportal_resume_assignments}");
    $DB->execute("DELETE FROM {local_jobportal_resume_review_hist}");
    $DB->execute("DELETE FROM {local_jobportal_apply_overrides}");
    
    // Delete files in file storage
    $fs = get_file_storage();
    $fs->delete_area_files($syscontext->id, 'local_jobportal', 'profile_resume');
    $fs->delete_area_files($syscontext->id, 'local_jobportal', 'company_logo');
    cli_writeln('Data cleared.');
}

// 1. Identify users
$allusers = $DB->get_records_sql(
    "SELECT id, username, firstname, lastname, email
       FROM {user}
      WHERE deleted = 0 AND id > 1
   ORDER BY id ASC"
);

if (count($allusers) < 5) {
    cli_error('Not enough users in the Moodle database to seed test data. Please create at least 10 users first.');
}

// Separate managers and students
// We'll treat admin (ID 2) and the last user as managers/reviewers, others as students.
$managers = array();
$students = array();

$adminuser = $DB->get_record('user', array('id' => 2));
if ($adminuser) {
    $managers[] = $adminuser;
}

foreach ($allusers as $u) {
    if ($u->id == 2) {
        continue;
    }
    // Make the user "muruga" (ID 43 or any other name) a reviewer/manager as well if present
    if (strpos(strtolower($u->username), 'muruga') !== false || strpos(strtolower($u->username), 'admin') !== false) {
        $managers[] = $u;
    } else {
        $students[] = $u;
    }
}

// If no managers found besides admin, take the last student as a manager
if (count($managers) < 2 && count($students) > 1) {
    $managers[] = array_pop($students);
}

cli_writeln('Reviewers / Managers found: ' . count($managers) . ' (' . implode(', ', array_map(function($m) { return $m->username; }, $managers)) . ')');
cli_writeln('Students found: ' . count($students));

// Ensure stages exist (usually seeded by db/install.php, but let's fetch them)
$stagerecords = $DB->get_records('local_jobportal_stages', null, 'sortorder ASC');
$stages = array();
foreach ($stagerecords as $stage) {
    $stages[$stage->shortname] = $stage;
}

if (empty($stages)) {
    cli_writeln('No recruitment stages found. Attempting to run installation hooks...');
    require_once(__DIR__ . '/../db/install.php');
    xmldb_local_jobportal_install();
    
    // Fetch stages again
    $stagerecords = $DB->get_records('local_jobportal_stages', null, 'sortorder ASC');
    foreach ($stagerecords as $stage) {
        $stages[$stage->shortname] = $stage;
    }
}

if (empty($stages)) {
    cli_error('No recruitment stages found. Please make sure the plugin installation is complete.');
}

// 2. Seed Companies
$companynames = array(
    'Tata Elxsi', 'KPIT Technologies', 'L&T Technology Services', 
    'Bosch Global Software', 'Cyient', 'Wipro Engineering', 'Infosys Engineering'
);
$locations = array(
    'Bengaluru, Karnataka', 'Pune, Maharashtra', 'Hyderabad, Telangana', 
    'Chennai, Tamil Nadu', 'Noida, Uttar Pradesh', 'Remote'
);
$roles = array(
    'Embedded Software Engineer', 'Firmware Engineer', 'BSP Engineer',
    'Device Driver Engineer', 'AUTOSAR Engineer', 'VLSI Verification Engineer'
);

$now = time();
$seededcompanies = array();

cli_writeln('Seeding companies...');
for ($i = 0; $i < $companiescount; $i++) {
    $name = $companynames[$i % count($companynames)] . ' Ltd';
    if ($clear === false) {
        $name .= ' - Seed ' . mt_rand(100, 999);
    }
    
    $company = new stdClass();
    $company->userid = (int)$managers[0]->id;
    $company->name = $name;
    $company->description = 'A leading engineering services provider specializing in embedded software systems, AUTOSAR compliance, device drivers, and VLSI designs.';
    $company->website = 'https://www.' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name)) . '.example.com';
    $company->timecreated = $now - (mt_rand(10, 50) * DAYSECS);
    $company->timemodified = $now;
    
    $company->id = $DB->insert_record('local_jobportal_companies', $company);
    $seededcompanies[] = $company;
}

// 3. Seed Jobs
$seededjobs = array();
cli_writeln('Seeding jobs...');
foreach ($seededcompanies as $comp) {
    for ($j = 0; $j < $jobspercompany; $j++) {
        $title = $roles[mt_rand(0, count($roles) - 1)] . ' (Experience ' . mt_rand(1, 5) . ' Yrs)';
        
        $job = new stdClass();
        $job->title = $title;
        $job->description = "We are seeking a talented engineer to join our automotive design and embedded systems development division. Responsibilities include:\n- Developing C/C++ applications for RTOS.\n- Working with logic analyzers and hardware debuggers.\n- AUTOSAR stack configuration and development.";
        $job->company = $comp->name;
        $job->companyid = $comp->id;
        $job->location = $locations[mt_rand(0, count($locations) - 1)];
        $job->jobtype = mt_rand(0, 1) ? 'fulltime' : 'internship';
        
        // Setup salary
        $job->salarymin = null;
        $job->salarymax = null;
        $salarymodel = mt_rand(0, 2);
        if ($salarymodel === 0) {
            $job->salarymodel = 'fixed';
            $job->salarymin = mt_rand(4, 10) * 100000;
            $job->salarymax = $job->salarymin;
            $job->salaryminannual = $job->salarymin;
            $job->salarymaxannual = $job->salarymin;
        } else if ($salarymodel === 1) {
            $job->salarymodel = 'range';
            $job->salarymin = mt_rand(4, 7) * 100000;
            $job->salarymax = $job->salarymin + (mt_rand(2, 5) * 100000);
            $job->salaryminannual = $job->salarymin;
            $job->salarymaxannual = $job->salarymax;
        } else {
            $job->salarymodel = 'progressive';
            $job->salaryminannual = 360000;
            $job->salarymaxannual = 600000;
        }
        $job->salarycurrency = 'INR';
        $job->salaryperiod = 'annual';
        $job->salary = local_jobportal_build_salary_display($job->salarymodel, $job->salarycurrency, $job->salaryperiod, $job->salarymin, $job->salarymax, '');
        
        $job->deadline = $now + (mt_rand(-5, 20) * DAYSECS);
        $job->requirements = "- Proficient in C language programming.\n- Understanding of microcontroller architecture.\n- Excellent problem-solving skills.";
        $job->status = 1;
        
        // Job drive states mix
        $drivestates = array('applicationsopen', 'applicationsopen', 'applicationsopen', 'applicationsclosed', 'selectioninprogress', 'completed');
        $job->drivestate = $drivestates[mt_rand(0, count($drivestates) - 1)];
        if ($job->drivestate === 'completed') {
            $job->driveoutcome = mt_rand(0, 1) ? 'offersmade' : 'noselection';
            $job->drivenote = 'Seeded complete drive outcomes.';
        }
        
        $job->postedby = $comp->userid;
        $job->timecreated = $comp->timecreated + (mt_rand(1, 5) * DAYSECS);
        $job->timemodified = $now;
        
        $job->id = $DB->insert_record('local_jobportal_jobs', $job);
        
        // If progressive, add stages
        if ($job->salarymodel === 'progressive') {
            $stages_data = array(
                array('stagelabel' => 'Training', 'amount' => 30000.00, 'period' => 'monthly', 'conditiontext' => 'First 6 months', 'sortorder' => 1),
                array('stagelabel' => 'Confirm', 'amount' => 600000.00, 'period' => 'annual', 'conditiontext' => 'Post confirmation', 'sortorder' => 2),
            );
            local_jobportal_replace_job_salary_stages($job->id, $stages_data);
            
            // Re-build and save display
            $display = local_jobportal_build_salary_display($job->salarymodel, $job->salarycurrency, $job->salaryperiod, $job->salarymin, $job->salarymax, '', $stages_data);
            $DB->set_field('local_jobportal_jobs', 'salary', $display, array('id' => $job->id));
        }
        
        $seededjobs[] = $job;
    }
}

// 4. Seed Student Profiles & Resume Reviews
cli_writeln('Seeding student profiles and resume review status...');
$fs = get_file_storage();
$seededprofiles = array();

// Divide students into different resume review statuses
$statuses_to_seed = array(
    'notsubmitted', // No resume uploaded
    'submitted',    // Uploaded, not assigned
    'inreview',     // Assigned, not decided
    'approved',     // Assigned, approved
    'needsrework'   // Assigned, needs rework
);

$student_count = count($students);
for ($k = 0; $k < $student_count; $k++) {
    $student = $students[$k];
    $status = $statuses_to_seed[$k % count($statuses_to_seed)];
    
    $profile = new stdClass();
    $profile->userid = $student->id;
    $profile->skills = 'Embedded C, ARM Cortex, FreeRTOS, GPIO, SPI, I2C, UART, debugging';
    $profile->experience = 'Academic projects on home automation and line-follower robotic vehicles using ARM microcontrollers.';
    $profile->education = 'B.E. in Electronics and Communication Engineering';
    $profile->resumestatus = $status;
    $profile->portfolio = 'https://github.com/student' . $student->id;
    $profile->resumeapprovalmode = 'allrequired';
    $profile->timecreated = $now - (mt_rand(10, 40) * DAYSECS);
    $profile->timemodified = $now;
    
    // Insert profile first to get an ID
    $profile->id = $DB->insert_record('local_jobportal_profiles', $profile);
    
    // Add resume file if status is not 'notsubmitted'
    if ($status !== 'notsubmitted') {
        $filerecord = array(
            'contextid' => $syscontext->id,
            'component' => 'local_jobportal',
            'filearea' => 'profile_resume',
            'itemid' => $profile->id,
            'filepath' => '/',
            'filename' => 'resume_' . $student->username . '.pdf',
            'userid' => $student->id
        );
        $fs->create_file_from_string($filerecord, 'Dummy resume content for student ' . $student->firstname . ' ' . $student->lastname);
        
        // Generate and save signature
        $sig = local_jobportal_get_profile_resume_signature($profile->id, $syscontext);
        $DB->set_field('local_jobportal_profiles', 'resume', '1', array('id' => $profile->id)); // Mark resume column as present
        
        // Populate assignment / history
        if ($status !== 'submitted') {
            $reviewer = $managers[$k % count($managers)];
            
            $asg = new stdClass();
            $asg->profileid = $profile->id;
            $asg->resumesignature = $sig;
            $asg->reviewerid = $reviewer->id;
            $asg->assignedby = $managers[0]->id;
            $asg->status = local_jobportal_resume_status_to_assignment_status($status);
            $asg->timeassigned = $profile->timecreated + HOURSECS;
            $asg->timecreated = $asg->timeassigned;
            $asg->timemodified = $now;
            
            if ($status === 'approved' || $status === 'needsrework') {
                $asg->rating = ($status === 'approved') ? mt_rand(4, 5) : mt_rand(1, 2);
                $asg->feedback = ($status === 'approved') ? 'Well written, highlights hardware skills perfectly.' : 'Formatting is poor. Please rewrite your experience details.';
                $asg->timereviewed = $asg->timeassigned + (mt_rand(1, 24) * HOURSECS);
                
                // Update profile record fields
                $DB->update_record('local_jobportal_profiles', array(
                    'id' => $profile->id,
                    'resumerating' => $asg->rating,
                    'resumefeedback' => $asg->feedback,
                    'resumereviewedby' => $reviewer->id,
                    'resumereviewedat' => $asg->timereviewed,
                    'timemodified' => $now
                ));
                
                // Add to history
                local_jobportal_log_resume_review_history($profile->id, $reviewer->id, $status, $asg->rating, $asg->feedback, 'managerreview');
            }
            
            $DB->insert_record('local_jobportal_resume_assignments', $asg);
        } else {
            // Log submission in history
            local_jobportal_log_resume_review_history($profile->id, $student->id, 'submitted', null, 'Resume uploaded by student.', 'studentupload');
        }
    }
    
    $seededprofiles[] = $profile;
}

// 5. Seed Applications & Interview Timeline Events
cli_writeln('Seeding job applications and selection round events...');
$appscreated = 0;
$eventscreated = 0;

// Filter profiles who have submitted a resume
$eligible_profiles = array_filter($seededprofiles, function($p) {
    return $p->resumestatus !== 'notsubmitted';
});

$eligible_profiles = array_values($eligible_profiles);

// We want to create around 30 applications across the jobs
$jobs_count = count($seededjobs);
$elig_count = count($eligible_profiles);

for ($a = 0; $a < min(30, $elig_count * 2); $a++) {
    $profile = $eligible_profiles[$a % $elig_count];
    $job = $seededjobs[($a + mt_rand(0, $jobs_count - 1)) % $jobs_count];
    
    // Check if application already exists (prevent unique violation)
    if ($DB->record_exists('local_jobportal_applications', array('jobid' => $job->id, 'userid' => $profile->userid))) {
        continue;
    }
    
    $app = new stdClass();
    $app->jobid = $job->id;
    $app->userid = $profile->userid;
    $app->coverletter = 'I am highly interested in this embedded systems engineer role. I have extensive academic projects related to RTOS scheduling and ARM processors, which match the core requirements of your organization.';
    $app->resume = '1';
    
    // Choose application lifecycle mix
    $lifecycle_roll = mt_rand(1, 100);
    $app->timecreated = $job->timecreated + (mt_rand(1, 5) * DAYSECS);
    $app->timemodified = $now;
    
    if ($lifecycle_roll <= 20) {
        // Pending Shortlisting
        $app->shortliststatus = 'pending';
        $app->status = 'pending';
        $app->currentstageid = $stages['pending']->id;
    } else if ($lifecycle_roll <= 35) {
        // Internal Shortlisted (Manager only)
        $app->shortliststatus = 'internalshortlisted';
        $app->status = 'pending';
        $app->currentstageid = $stages['internalshortlisted']->id;
    } else if ($lifecycle_roll <= 50) {
        // Not Shortlisted (Rejected early)
        $app->shortliststatus = 'notshortlisted';
        $app->status = 'pending';
        $app->currentstageid = $stages['notshortlisted']->id;
    } else {
        // Shortlisted!
        $app->shortliststatus = 'shortlisted';
        $app->currentstageid = $stages['shortlisted']->id;
        
        // Progress into post-shortlist stages
        $stage_roll = mt_rand(1, 100);
        if ($stage_roll <= 30) {
            // Test scheduled
            $app->status = 'testscheduled';
            $app->currentstageid = $stages['testscheduled']->id;
            $app->screeningat = $app->timecreated + DAYSECS;
        } else if ($stage_roll <= 60) {
            // Interview scheduled
            $app->status = 'interviewscheduled';
            $app->currentstageid = $stages['interviewscheduled']->id;
            $app->screeningat = $app->timecreated + DAYSECS;
            $app->interviewscheduledat = $app->screeningat + (2 * DAYSECS);
        } else if ($stage_roll <= 75) {
            // Offer made
            $app->status = 'offermade';
            $app->currentstageid = $stages['offermade']->id;
            $app->screeningat = $app->timecreated + DAYSECS;
            $app->interviewscheduledat = $app->screeningat + (2 * DAYSECS);
            $app->interviewcompletedat = $app->interviewscheduledat + DAYSECS;
            $app->offermadeat = $app->interviewcompletedat + DAYSECS;
        } else if ($stage_roll <= 90) {
            // Offer accepted
            $app->status = 'accepted';
            $app->currentstageid = $stages['accepted']->id;
            $app->screeningat = $app->timecreated + DAYSECS;
            $app->interviewscheduledat = $app->screeningat + (2 * DAYSECS);
            $app->interviewcompletedat = $app->interviewscheduledat + DAYSECS;
            $app->offermadeat = $app->interviewcompletedat + DAYSECS;
        } else {
            // Offer rejected
            $app->status = 'rejected';
            $app->currentstageid = $stages['rejected']->id;
            $app->screeningat = $app->timecreated + DAYSECS;
            $app->interviewscheduledat = $app->screeningat + (2 * DAYSECS);
            $app->interviewcompletedat = $app->interviewscheduledat + DAYSECS;
            $app->offermadeat = $app->interviewcompletedat + DAYSECS;
        }
    }
    
    $app->id = $DB->insert_record('local_jobportal_applications', $app);
    $appscreated++;
    
    // Seed timeline events (appstage_events) based on current stages
    $acting_manager = $managers[mt_rand(0, count($managers) - 1)];
    
    // 1. Initial Apply Event (Pending Stage)
    $ev = new stdClass();
    $ev->applicationid = $app->id;
    $ev->stageid = $stages['pending']->id;
    $ev->changedby = $profile->userid;
    $ev->notes = 'Application submitted by candidate.';
    $ev->timecreated = $app->timecreated;
    $DB->insert_record('local_jobportal_appstage_events', $ev);
    $eventscreated++;
    
    // 2. Shortlisting Events
    if ($app->shortliststatus === 'internalshortlisted') {
        $ev = new stdClass();
        $ev->applicationid = $app->id;
        $ev->stageid = $stages['internalshortlisted']->id;
        $ev->changedby = $acting_manager->id;
        $ev->notes = 'Candidate has strong microcontrollers foundation. Shortlisting internally.';
        $ev->timecreated = $app->timecreated + HOURSECS;
        $DB->insert_record('local_jobportal_appstage_events', $ev);
        $eventscreated++;
    } else if ($app->shortliststatus === 'notshortlisted') {
        $ev = new stdClass();
        $ev->applicationid = $app->id;
        $ev->stageid = $stages['notshortlisted']->id;
        $ev->changedby = $acting_manager->id;
        $ev->notes = 'Lack of AUTOSAR or firmware project experience.';
        $ev->timecreated = $app->timecreated + (2 * HOURSECS);
        $DB->insert_record('local_jobportal_appstage_events', $ev);
        $eventscreated++;
    } else if ($app->shortliststatus === 'shortlisted') {
        $ev = new stdClass();
        $ev->applicationid = $app->id;
        $ev->stageid = $stages['shortlisted']->id;
        $ev->changedby = $acting_manager->id;
        $ev->notes = 'Shortlisted candidate for further evaluation.';
        $ev->timecreated = $app->timecreated + (2 * HOURSECS);
        $DB->insert_record('local_jobportal_appstage_events', $ev);
        $eventscreated++;
        
        // Progress into post-shortlist stage events
        if ($app->status === 'testscheduled' || $app->status === 'interviewscheduled' || $app->status === 'offermade' || $app->status === 'accepted' || $app->status === 'rejected') {
            // First Test round (scheduled / completed / noshow)
            $ev_test = new stdClass();
            $ev_test->applicationid = $app->id;
            $ev_test->stageid = $stages['testscheduled']->id;
            $ev_test->changedby = $acting_manager->id;
            $ev_test->scheduledat = $app->timecreated + (2 * DAYSECS);
            $ev_test->schedulemode = 'online';
            $ev_test->schedulelink = 'https://moodle.example.com/mod/quiz/view.php?id=1';
            $ev_test->scheduleduration = 60;
            $ev_test->timecreated = $app->timecreated + DAYSECS;
            
            if ($app->status === 'testscheduled') {
                $ev_test->schedulestatus = mt_rand(0, 1) ? 'scheduled' : 'noshow';
                $ev_test->roundoutcome = 'pending';
                if ($ev_test->schedulestatus === 'noshow' && mt_rand(0, 1)) {
                    $ev_test->schedulestatus = 'excused';
                    $ev_test->notes = 'Candidate missed test due to university exams. Excused.';
                } else {
                    $ev_test->notes = 'Written test on Embedded C and ARM Architecture scheduled.';
                }
            } else {
                $ev_test->schedulestatus = 'completed';
                $ev_test->roundoutcome = 'cleared';
                $ev_test->notes = 'Candidate cleared written test with 85% score.';
            }
            $DB->insert_record('local_jobportal_appstage_events', $ev_test);
            $eventscreated++;
        }
        
        if ($app->status === 'interviewscheduled' || $app->status === 'offermade' || $app->status === 'accepted' || $app->status === 'rejected') {
            // Interview scheduled / completed
            $ev_int = new stdClass();
            $ev_int->applicationid = $app->id;
            $ev_int->stageid = $stages['interviewscheduled']->id;
            $ev_int->changedby = $acting_manager->id;
            $ev_int->scheduledat = $app->timecreated + (4 * DAYSECS);
            $ev_int->schedulemode = 'offline';
            $ev_int->schedulevenue = 'Tech Hub Conference Room 3';
            $ev_int->scheduleduration = 45;
            $ev_int->timecreated = $app->timecreated + (3 * DAYSECS);
            
            if ($app->status === 'interviewscheduled') {
                $ev_int->schedulestatus = 'scheduled';
                $ev_int->roundoutcome = 'pending';
                $ev_int->notes = 'Technical Interview round with engineering team scheduled.';
            } else {
                $ev_int->schedulestatus = 'completed';
                $ev_int->roundoutcome = 'cleared';
                $ev_int->notes = 'Excellent interview performance. Cleared by panel.';
            }
            $DB->insert_record('local_jobportal_appstage_events', $ev_int);
            $eventscreated++;
        }
        
        if ($app->status === 'offermade' || $app->status === 'accepted' || $app->status === 'rejected') {
            // Offer Made Event
            $ev_off = new stdClass();
            $ev_off->applicationid = $app->id;
            $ev_off->stageid = $stages['offermade']->id;
            $ev_off->changedby = $acting_manager->id;
            $ev_off->notes = 'Offer letter sent via email. CTC offered: 6 LPA + benefits.';
            $ev_off->timecreated = $app->timecreated + (5 * DAYSECS);
            $DB->insert_record('local_jobportal_appstage_events', $ev_off);
            $eventscreated++;
        }
        
        if ($app->status === 'accepted') {
            // Offer Accepted Event
            $ev_acc = new stdClass();
            $ev_acc->applicationid = $app->id;
            $ev_acc->stageid = $stages['accepted']->id;
            $ev_acc->changedby = $profile->userid;
            $ev_acc->notes = 'Candidate accepted the offer. Tentative joining date: next month.';
            $ev_acc->timecreated = $app->timecreated + (6 * DAYSECS);
            $DB->insert_record('local_jobportal_appstage_events', $ev_acc);
            $eventscreated++;
        } else if ($app->status === 'rejected') {
            // Offer Rejected Event
            $ev_rej = new stdClass();
            $ev_rej->applicationid = $app->id;
            $ev_rej->stageid = $stages['rejected']->id;
            $ev_rej->changedby = $profile->userid;
            $ev_rej->notes = 'Candidate declined the offer due to another competing offer.';
            $ev_rej->timecreated = $app->timecreated + (6 * DAYSECS);
            $DB->insert_record('local_jobportal_appstage_events', $ev_rej);
            $eventscreated++;
        }
    }
    
    // Add manager notes to applications (appnotes)
    if (mt_rand(1, 100) <= 60) {
        $note = new stdClass();
        $note->applicationid = $app->id;
        $note->userid = $acting_manager->id;
        $note->note = 'Note by Manager: Good communication skills and solid academic record. Needs verification of embedded C capabilities in written tests.';
        $note->timecreated = $app->timecreated + HOURSECS;
        $DB->insert_record('local_jobportal_appnotes', $note);
    }
}

cli_writeln("\nSeeding Complete!");
cli_writeln("-----------------------------------------");
cli_writeln("Companies seeded: " . count($seededcompanies));
cli_writeln("Jobs seeded:      " . count($seededjobs));
cli_writeln("Profiles seeded:  " . count($seededprofiles));
cli_writeln("Applications:     " . $appscreated);
cli_writeln("Stage Events:     " . $eventscreated);
cli_writeln("-----------------------------------------");
