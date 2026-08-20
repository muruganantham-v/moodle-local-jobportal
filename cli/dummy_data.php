<?php
define('CLI_SCRIPT', true);
require('C:\Users\Admin\Pictures\MoodleWindowsInstaller-latest-500\server\moodle\config.php');
require_once($CFG->dirroot . '/local/jobportal/locallib.php');
require_once($CFG->libdir . '/clilib.php');

cli_heading('Job Portal Dummy Data Generator');

if (empty($CFG->version)) {
    cli_error('Database is not initialized');
}

$admin = $DB->get_record('user', array('username' => 'admin'));
if (!$admin) {
    cli_error('Admin user not found.');
}

cli_heading('Creating Companies...');
$companies = [];
$companynames = ['Tech Corp', 'Global Solutions', 'Innovatech', 'DataWorks', 'CloudSync'];
foreach ($companynames as $name) {
    $record = new stdClass();
    $record->name = $name;
    $record->description = 'A leading company in ' . $name;
    $record->website = 'https://www.example.com/' . strtolower(str_replace(' ', '', $name));
    $record->userid = $admin->id;
    $record->timecreated = time();
    $record->timemodified = time();
    
    $existing = $DB->get_record('local_jobportal_companies', ['name' => $name]);
    if ($existing) {
        $companies[] = $existing->id;
        cli_writeln("Company $name already exists.");
    } else {
        $id = $DB->insert_record('local_jobportal_companies', $record);
        $companies[] = $id;
        cli_writeln("Created company $name (ID: $id)");
    }
}

cli_heading('Creating Jobs...');
$jobs = [];
$jobtitles = ['Software Engineer', 'Data Scientist', 'Product Manager', 'UX Designer', 'DevOps Specialist'];
foreach ($companies as $index => $cid) {
    for ($i = 0; $i < 3; $i++) {
        $title = $jobtitles[array_rand($jobtitles)] . ' - ' . rand(1, 100);
        $record = new stdClass();
        $record->companyid = $cid;
        $record->title = $title;
        $record->description = '<p>This is a dummy job description for ' . $title . '.</p>';
        $record->status = 1;
        $record->timecreated = time();
        $record->timemodified = time();
        $record->postedby = $admin->id;
        
        $id = $DB->insert_record('local_jobportal_jobs', $record);
        $jobs[] = $id;
        cli_writeln("Created job $title (ID: $id)");
    }
}

cli_heading('Creating Dummy Users (Students)...');
$students = [];
for ($i = 1; $i <= 10; $i++) {
    $username = 'student' . $i;
    $user = $DB->get_record('user', ['username' => $username]);
    if (!$user) {
        $record = new stdClass();
        $record->username = $username;
        $record->password = hash_internal_user_password('Password123!');
        $record->firstname = 'Student';
        $record->lastname = 'User ' . $i;
        $record->email = $username . '@example.com';
        $record->confirmed = 1;
        $record->mnethostid = $CFG->mnet_localhost_id;
        $record->city = 'Test City';
        $record->country = 'US';
        $id = $DB->insert_record('user', $record);
        $user = $DB->get_record('user', ['id' => $id]);
        cli_writeln("Created user $username");
    }
    $students[] = $user;
    
    // Create profile
    $profile = $DB->get_record('local_jobportal_profiles', ['userid' => $user->id]);
    if (!$profile) {
        $precord = new stdClass();
        $precord->userid = $user->id;
        $precord->skills = 'PHP, JavaScript, HTML, CSS';
        $precord->experience = '1 year of internship';
        $precord->education = 'B.S. Computer Science';
        $precord->resumestatus = 'approved';
        $precord->resumereviewedby = $admin->id;
        $precord->resumereviewedat = time();
        $precord->resumerating = rand(3, 5);
        $precord->resumefeedback = 'Looks great!';
        $precord->timecreated = time();
        $precord->timemodified = time();
        $DB->insert_record('local_jobportal_profiles', $precord);
    }
}

cli_heading('Creating Applications...');
$statuses = ['applied', 'shortlisted', 'rejected', 'withdrawn'];
$shortlist_statuses = ['pending', 'shortlisted', 'rejected', 'waitlisted'];
foreach ($students as $student) {
    // Apply to 2-4 random jobs
    $num_apps = rand(2, 4);
    $applied_jobs = (array)array_rand($jobs, $num_apps);
    
    foreach ($applied_jobs as $jobkey) {
        $jobid = $jobs[$jobkey];
        
        $existing = $DB->get_record('local_jobportal_applications', ['userid' => $student->id, 'jobid' => $jobid]);
        if (!$existing) {
            $record = new stdClass();
            $record->jobid = $jobid;
            $record->userid = $student->id;
            $record->status = $statuses[array_rand($statuses)];
            $record->shortliststatus = $shortlist_statuses[array_rand($shortlist_statuses)];
            $record->timecreated = time();
            $record->timemodified = time();
            
            $DB->insert_record('local_jobportal_applications', $record);
            cli_writeln("Created application for student {$student->username} to job ID $jobid");
        }
    }
}

cli_writeln('Dummy data generation complete!');
exit(0);
