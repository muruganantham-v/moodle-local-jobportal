<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

/**
 * Post installation hook
 */
function xmldb_local_jobportal_install() {
    global $DB;

    // Set up default permissions for authenticated users.
    $userrole = $DB->get_record('role', array('shortname' => 'user'));
    if ($userrole) {
        $context = context_system::instance();

        assign_capability('local/jobportal:viewjobs', CAP_ALLOW, $userrole->id, $context->id, true);
        assign_capability('local/jobportal:apply', CAP_ALLOW, $userrole->id, $context->id, true);
    }

    // Set up default permissions for the student role
    $studentrole = $DB->get_record('role', array('shortname' => 'student'));
    if ($studentrole) {
        $context = context_system::instance();
        
        // Assign view and apply capabilities to students
        assign_capability('local/jobportal:viewjobs', CAP_ALLOW, $studentrole->id, $context->id, true);
        assign_capability('local/jobportal:apply', CAP_ALLOW, $studentrole->id, $context->id, true);
    }
    
    // Set up manager permissions
    $managerrole = $DB->get_record('role', array('shortname' => 'manager'));
    if ($managerrole) {
        $context = context_system::instance();
        
        assign_capability('local/jobportal:viewjobs', CAP_ALLOW, $managerrole->id, $context->id, true);
        assign_capability('local/jobportal:apply', CAP_ALLOW, $managerrole->id, $context->id, true);
        assign_capability('local/jobportal:postjobs', CAP_ALLOW, $managerrole->id, $context->id, true);
        assign_capability('local/jobportal:managejobs', CAP_ALLOW, $managerrole->id, $context->id, true);
        assign_capability('local/jobportal:viewapplications', CAP_ALLOW, $managerrole->id, $context->id, true);
        assign_capability('local/jobportal:managecompanyprofile', CAP_ALLOW, $managerrole->id, $context->id, true);
        assign_capability('local/jobportal:reviewresumes', CAP_ALLOW, $managerrole->id, $context->id, true);
        assign_capability('local/jobportal:assignresumereviewers', CAP_ALLOW, $managerrole->id, $context->id, true);
    }
    
    // Set up teacher permissions (optional - can view jobs)
    $teacherrole = $DB->get_record('role', array('shortname' => 'editingteacher'));
    if ($teacherrole) {
        $context = context_system::instance();
        assign_capability('local/jobportal:viewjobs', CAP_ALLOW, $teacherrole->id, $context->id, true);
        assign_capability('local/jobportal:reviewresumes', CAP_ALLOW, $teacherrole->id, $context->id, true);
    }

    // Seed default recruitment stages.
    $definitions = array(
        array('shortname' => 'pending', 'displayname' => 'Pending', 'sortorder' => 10, 'isterminal' => 0, 'isinternal' => 0, 'hasscheduledate' => 0),
        array('shortname' => 'internalshortlisted', 'displayname' => 'Internal Shortlisted', 'sortorder' => 15, 'isterminal' => 0, 'isinternal' => 1, 'hasscheduledate' => 0),
        array('shortname' => 'screening', 'displayname' => 'Screening', 'sortorder' => 20, 'isterminal' => 0, 'isinternal' => 0, 'hasscheduledate' => 0),
        array('shortname' => 'shortlisted', 'displayname' => 'Shortlisted', 'sortorder' => 25, 'isterminal' => 0, 'isinternal' => 0, 'hasscheduledate' => 0),
        array('shortname' => 'notshortlisted', 'displayname' => 'Not Shortlisted', 'sortorder' => 26, 'isterminal' => 1, 'isinternal' => 0, 'hasscheduledate' => 0),
        array('shortname' => 'testscheduled', 'displayname' => 'Test Scheduled', 'sortorder' => 30, 'isterminal' => 0, 'isinternal' => 0, 'hasscheduledate' => 1),
        array('shortname' => 'interviewscheduled', 'displayname' => 'Interview Scheduled', 'sortorder' => 50, 'isterminal' => 0, 'isinternal' => 0, 'hasscheduledate' => 1),
        array('shortname' => 'offermade', 'displayname' => 'Offer Made', 'sortorder' => 70, 'isterminal' => 0, 'isinternal' => 0, 'hasscheduledate' => 0),
        array('shortname' => 'accepted', 'displayname' => 'Offer Accepted', 'sortorder' => 80, 'isterminal' => 1, 'isinternal' => 0, 'hasscheduledate' => 0),
        array('shortname' => 'rejected', 'displayname' => 'Offer Rejected', 'sortorder' => 90, 'isterminal' => 1, 'isinternal' => 0, 'hasscheduledate' => 0),
    );

    foreach ($definitions as $definition) {
        if ($DB->record_exists('local_jobportal_stages', array('shortname' => $definition['shortname']))) {
            continue;
        }
        $record = new stdClass();
        $record->shortname = $definition['shortname'];
        $record->displayname = $definition['displayname'];
        $record->sortorder = $definition['sortorder'];
        $record->isterminal = $definition['isterminal'];
        $record->isactive = 1;
        $record->isinternal = $definition['isinternal'];
        $record->hasscheduledate = $definition['hasscheduledate'];
        $record->timecreated = time();
        $record->timemodified = time();
        $DB->insert_record('local_jobportal_stages', $record);
    }
    
    return true;
}
