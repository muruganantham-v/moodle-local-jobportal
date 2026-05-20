<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade hook for local_jobportal.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_jobportal_upgrade($oldversion) {
    global $DB;

    if ($oldversion < 2026013102) {
        $context = context_system::instance();
        $userrole = $DB->get_record('role', array('shortname' => 'user'));

        if ($userrole) {
            $capabilities = array(
                'local/jobportal:viewjobs',
                'local/jobportal:apply',
            );

            foreach ($capabilities as $capability) {
                $existing = $DB->get_record('role_capabilities', array(
                    'contextid' => $context->id,
                    'roleid' => $userrole->id,
                    'capability' => $capability,
                ), 'id, permission');

                if (!$existing || (int)$existing->permission === CAP_INHERIT) {
                    assign_capability($capability, CAP_ALLOW, $userrole->id, $context->id, true);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026013102, 'local', 'jobportal');
    }

    if ($oldversion < 2026013103) {
        $dbman = $DB->get_manager();

        $table = new xmldb_table('local_jobportal_companies');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('website', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $table->add_key('userid', XMLDB_KEY_FOREIGN_UNIQUE, array('userid'), 'user', array('id'));

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Ensure newly declared capabilities are loaded before assignment.
        update_capabilities('local_jobportal');

        $context = context_system::instance();
        $managerrole = $DB->get_record('role', array('shortname' => 'manager'));

        if ($managerrole) {
            $capability = 'local/jobportal:managecompanyprofile';
            if (get_capability_info($capability)) {
                $existing = $DB->get_record('role_capabilities', array(
                    'contextid' => $context->id,
                    'roleid' => $managerrole->id,
                    'capability' => $capability,
                ), 'id, permission');

                if (!$existing || (int)$existing->permission === CAP_INHERIT) {
                    assign_capability($capability, CAP_ALLOW, $managerrole->id, $context->id, true);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026013103, 'local', 'jobportal');
    }

    if ($oldversion < 2026013104) {
        $dbman = $DB->get_manager();

        $companytable = new xmldb_table('local_jobportal_companies');
        if ($dbman->table_exists($companytable)) {
            $olduseridkey = new xmldb_key('userid', XMLDB_KEY_FOREIGN_UNIQUE, array('userid'), 'user', array('id'));
            if ($dbman->find_key_name($companytable, $olduseridkey)) {
                $dbman->drop_key($companytable, $olduseridkey);
            }

            $newuseridkey = new xmldb_key('userid', XMLDB_KEY_FOREIGN, array('userid'), 'user', array('id'));
            if (!$dbman->find_key_name($companytable, $newuseridkey)) {
                $dbman->add_key($companytable, $newuseridkey);
            }

            $nameindex = new xmldb_index('companynameidx', XMLDB_INDEX_NOTUNIQUE, array('name'));
            if (!$dbman->index_exists($companytable, $nameindex)) {
                $dbman->add_index($companytable, $nameindex);
            }
        }

        $jobtable = new xmldb_table('local_jobportal_jobs');
        $companyidfield = new xmldb_field('companyid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'company');
        if (!$dbman->field_exists($jobtable, $companyidfield)) {
            $dbman->add_field($jobtable, $companyidfield);
        }

        $existingcompanies = $DB->get_records('local_jobportal_companies', null, 'id ASC', 'id, name');
        $companymap = array();
        foreach ($existingcompanies as $existingcompany) {
            $normalized = core_text::strtolower(trim((string)$existingcompany->name));
            if ($normalized !== '' && !isset($companymap[$normalized])) {
                $companymap[$normalized] = (int)$existingcompany->id;
            }
        }

        $jobfields = 'id, company, companyid, postedby, timecreated, timemodified';
        $jobs = $DB->get_recordset('local_jobportal_jobs', null, 'id ASC', $jobfields);
        foreach ($jobs as $job) {
            if (!empty($job->companyid)) {
                continue;
            }

            $companyname = trim((string)$job->company);
            if ($companyname === '') {
                continue;
            }

            $normalized = core_text::strtolower($companyname);
            if (!isset($companymap[$normalized])) {
                $company = new stdClass();
                $company->userid = (int)$job->postedby;
                $company->name = $companyname;
                $company->description = null;
                $company->website = null;
                $company->timecreated = !empty($job->timecreated) ? (int)$job->timecreated : time();
                $company->timemodified = !empty($job->timemodified) ? (int)$job->timemodified : time();
                $companymap[$normalized] = (int)$DB->insert_record('local_jobportal_companies', $company);
            }

            $DB->set_field('local_jobportal_jobs', 'companyid', $companymap[$normalized], array('id' => $job->id));
        }
        $jobs->close();

        $companykey = new xmldb_key('companyid', XMLDB_KEY_FOREIGN, array('companyid'), 'local_jobportal_companies', array('id'));
        if (!$dbman->find_key_name($jobtable, $companykey)) {
            $dbman->add_key($jobtable, $companykey);
        }

        $companyidindex = new xmldb_index('companyididx', XMLDB_INDEX_NOTUNIQUE, array('companyid'));
        if (!$dbman->index_exists($jobtable, $companyidindex)) {
            $dbman->add_index($jobtable, $companyidindex);
        }

        upgrade_plugin_savepoint(true, 2026013104, 'local', 'jobportal');
    }

    if ($oldversion < 2026013105) {
        $dbman = $DB->get_manager();

        $apptable = new xmldb_table('local_jobportal_applications');
        $newfields = array(
            new xmldb_field('screeningat', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'status'),
            new xmldb_field('interviewscheduledat', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'screeningat'),
            new xmldb_field('interviewcompletedat', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'interviewscheduledat'),
            new xmldb_field('offermadeat', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'interviewcompletedat'),
        );

        foreach ($newfields as $field) {
            if (!$dbman->field_exists($apptable, $field)) {
                $dbman->add_field($apptable, $field);
            }
        }

        $notestable = new xmldb_table('local_jobportal_appnotes');
        $notestable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $notestable->add_field('applicationid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $notestable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $notestable->add_field('note', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $notestable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $notestable->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $notestable->add_key('appnoteappfk', XMLDB_KEY_FOREIGN, array('applicationid'), 'local_jobportal_applications', array('id'));
        $notestable->add_key('appnoteuserfk', XMLDB_KEY_FOREIGN, array('userid'), 'user', array('id'));

        if (!$dbman->table_exists($notestable)) {
            $dbman->create_table($notestable);
        }

        // Map legacy "reviewed" state to "screening".
        $DB->execute(
            "UPDATE {local_jobportal_applications}
                SET status = :newstatus,
                    screeningat = CASE WHEN screeningat IS NULL OR screeningat = 0 THEN timemodified ELSE screeningat END
              WHERE status = :oldstatus",
            array('newstatus' => 'screening', 'oldstatus' => 'reviewed')
        );

        upgrade_plugin_savepoint(true, 2026013105, 'local', 'jobportal');
    }

    if ($oldversion < 2026013106) {
        $dbman = $DB->get_manager();

        $stagestable = new xmldb_table('local_jobportal_stages');
        $stagestable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $stagestable->add_field('shortname', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $stagestable->add_field('displayname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $stagestable->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);
        $stagestable->add_field('isterminal', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, 0);
        $stagestable->add_field('isactive', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, 1);
        $stagestable->add_field('isinternal', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, 0);
        $stagestable->add_field('hasscheduledate', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, 0);
        $stagestable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $stagestable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $stagestable->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $stagestable->add_index('stageshortnameuniq', XMLDB_INDEX_UNIQUE, array('shortname'));

        if (!$dbman->table_exists($stagestable)) {
            $dbman->create_table($stagestable);
        }

        $appeventstable = new xmldb_table('local_jobportal_appstage_events');
        $appeventstable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $appeventstable->add_field('applicationid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $appeventstable->add_field('stageid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $appeventstable->add_field('changedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $appeventstable->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $appeventstable->add_field('scheduledat', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $appeventstable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $appeventstable->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $appeventstable->add_key('appeventappfk', XMLDB_KEY_FOREIGN, array('applicationid'), 'local_jobportal_applications', array('id'));
        $appeventstable->add_key('appeventstagefk', XMLDB_KEY_FOREIGN, array('stageid'), 'local_jobportal_stages', array('id'));
        $appeventstable->add_key('appeventuserfk', XMLDB_KEY_FOREIGN, array('changedby'), 'user', array('id'));

        if (!$dbman->table_exists($appeventstable)) {
            $dbman->create_table($appeventstable);
        }

        $applicationtable = new xmldb_table('local_jobportal_applications');
        $currentstagefield = new xmldb_field('currentstageid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'status');
        if (!$dbman->field_exists($applicationtable, $currentstagefield)) {
            $dbman->add_field($applicationtable, $currentstagefield);
        }

        $currentstagekey = new xmldb_key('appcurrentstagefk', XMLDB_KEY_FOREIGN, array('currentstageid'), 'local_jobportal_stages', array('id'));
        if (!$dbman->find_key_name($applicationtable, $currentstagekey)) {
            $dbman->add_key($applicationtable, $currentstagekey);
        }

        $definitions = array(
            array('shortname' => 'pending', 'displayname' => 'Pending', 'sortorder' => 10, 'isterminal' => 0, 'isactive' => 1, 'isinternal' => 0, 'hasscheduledate' => 0),
            array('shortname' => 'screening', 'displayname' => 'Screening', 'sortorder' => 20, 'isterminal' => 0, 'isactive' => 1, 'isinternal' => 0, 'hasscheduledate' => 0),
            array('shortname' => 'shortlisted', 'displayname' => 'Shortlisted', 'sortorder' => 25, 'isterminal' => 0, 'isactive' => 1, 'isinternal' => 0, 'hasscheduledate' => 0),
            array('shortname' => 'notshortlisted', 'displayname' => 'Not Shortlisted', 'sortorder' => 26, 'isterminal' => 1, 'isactive' => 1, 'isinternal' => 0, 'hasscheduledate' => 0),
            array('shortname' => 'testscheduled', 'displayname' => 'Test Scheduled', 'sortorder' => 30, 'isterminal' => 0, 'isactive' => 1, 'isinternal' => 0, 'hasscheduledate' => 1),
            array('shortname' => 'testdone', 'displayname' => 'Test Done', 'sortorder' => 40, 'isterminal' => 0, 'isactive' => 1, 'isinternal' => 0, 'hasscheduledate' => 0),
            array('shortname' => 'interviewscheduled', 'displayname' => 'Interview Scheduled', 'sortorder' => 50, 'isterminal' => 0, 'isactive' => 1, 'isinternal' => 0, 'hasscheduledate' => 1),
            array('shortname' => 'interviewdone', 'displayname' => 'Interview Done', 'sortorder' => 60, 'isterminal' => 0, 'isactive' => 1, 'isinternal' => 0, 'hasscheduledate' => 0),
            array('shortname' => 'offermade', 'displayname' => 'Offer Made', 'sortorder' => 70, 'isterminal' => 0, 'isactive' => 1, 'isinternal' => 0, 'hasscheduledate' => 0),
            array('shortname' => 'accepted', 'displayname' => 'Offer Accepted', 'sortorder' => 80, 'isterminal' => 1, 'isactive' => 1, 'isinternal' => 0, 'hasscheduledate' => 0),
            array('shortname' => 'rejected', 'displayname' => 'Offer Rejected', 'sortorder' => 90, 'isterminal' => 1, 'isactive' => 1, 'isinternal' => 0, 'hasscheduledate' => 0),
        );

        $stageids = array();
        foreach ($definitions as $definition) {
            $existing = $DB->get_record('local_jobportal_stages', array('shortname' => $definition['shortname']));
            if ($existing) {
                $stageids[$definition['shortname']] = (int)$existing->id;
                continue;
            }

            $record = new stdClass();
            $record->shortname = $definition['shortname'];
            $record->displayname = $definition['displayname'];
            $record->sortorder = $definition['sortorder'];
            $record->isterminal = $definition['isterminal'];
            $record->isactive = $definition['isactive'];
            $record->isinternal = $definition['isinternal'];
            $record->hasscheduledate = $definition['hasscheduledate'];
            $record->timecreated = time();
            $record->timemodified = time();
            $stageids[$definition['shortname']] = (int)$DB->insert_record('local_jobportal_stages', $record);
        }

        foreach ($definitions as $definition) {
            if (!isset($stageids[$definition['shortname']])) {
                $stage = $DB->get_record('local_jobportal_stages', array('shortname' => $definition['shortname']), 'id');
                if ($stage) {
                    $stageids[$definition['shortname']] = (int)$stage->id;
                }
            }
        }

        $legacymap = array(
            'pending' => 'pending',
            'reviewed' => 'screening',
            'screening' => 'screening',
            'interviewscheduled' => 'interviewscheduled',
            'interviewdone' => 'interviewdone',
            'offermade' => 'offermade',
            'accepted' => 'accepted',
            'rejected' => 'rejected',
            'notshortlisted' => 'notshortlisted',
        );

        $fields = 'id, userid, status, currentstageid, timecreated, timemodified, screeningat, interviewscheduledat, interviewcompletedat, offermadeat';
        $applications = $DB->get_recordset('local_jobportal_applications', null, 'id ASC', $fields);
        foreach ($applications as $application) {
            $status = !empty($application->status) ? core_text::strtolower($application->status) : 'pending';
            if (!isset($legacymap[$status])) {
                $status = 'pending';
            }
            $normalized = $legacymap[$status];

            $stageid = !empty($application->currentstageid) ? (int)$application->currentstageid : 0;
            if (empty($stageid) && isset($stageids[$normalized])) {
                $stageid = (int)$stageids[$normalized];
            }

            $updates = new stdClass();
            $updates->id = (int)$application->id;
            $needsupdate = false;
            if (!empty($stageid) && (int)$application->currentstageid !== $stageid) {
                $updates->currentstageid = $stageid;
                $needsupdate = true;
            }
            if ($application->status !== $normalized) {
                $updates->status = $normalized;
                $needsupdate = true;
            }
            if ($needsupdate) {
                $DB->update_record('local_jobportal_applications', $updates);
            }

            $hashistory = $DB->record_exists('local_jobportal_appstage_events', array('applicationid' => $application->id));
            if ($hashistory || empty($stageid)) {
                continue;
            }

            $eventstoinsert = array();
            if (isset($stageids['pending'])) {
                $eventstoinsert[] = array(
                    'stageid' => $stageids['pending'],
                    'scheduledat' => null,
                    'timecreated' => !empty($application->timecreated) ? (int)$application->timecreated : time(),
                    'notes' => null,
                );
            }
            if (!empty($application->screeningat) && isset($stageids['screening'])) {
                $eventstoinsert[] = array(
                    'stageid' => $stageids['screening'],
                    'scheduledat' => null,
                    'timecreated' => (int)$application->screeningat,
                    'notes' => null,
                );
            }
            if (!empty($application->interviewscheduledat) && isset($stageids['interviewscheduled'])) {
                $eventstoinsert[] = array(
                    'stageid' => $stageids['interviewscheduled'],
                    'scheduledat' => (int)$application->interviewscheduledat,
                    'timecreated' => (int)$application->interviewscheduledat,
                    'notes' => null,
                );
            }
            if (!empty($application->interviewcompletedat) && isset($stageids['interviewdone'])) {
                $eventstoinsert[] = array(
                    'stageid' => $stageids['interviewdone'],
                    'scheduledat' => null,
                    'timecreated' => (int)$application->interviewcompletedat,
                    'notes' => null,
                );
            }
            if (!empty($application->offermadeat) && isset($stageids['offermade'])) {
                $eventstoinsert[] = array(
                    'stageid' => $stageids['offermade'],
                    'scheduledat' => null,
                    'timecreated' => (int)$application->offermadeat,
                    'notes' => null,
                );
            }

            $already = array();
            foreach ($eventstoinsert as $event) {
                if (in_array($event['stageid'], $already, true)) {
                    continue;
                }
                $record = new stdClass();
                $record->applicationid = (int)$application->id;
                $record->stageid = (int)$event['stageid'];
                $record->changedby = !empty($application->userid) ? (int)$application->userid : 2;
                $record->notes = $event['notes'];
                $record->scheduledat = $event['scheduledat'];
                $record->timecreated = (int)$event['timecreated'];
                $DB->insert_record('local_jobportal_appstage_events', $record);
                $already[] = $event['stageid'];
            }

            if (!in_array($stageid, $already, true)) {
                $finalevent = new stdClass();
                $finalevent->applicationid = (int)$application->id;
                $finalevent->stageid = (int)$stageid;
                $finalevent->changedby = !empty($application->userid) ? (int)$application->userid : 2;
                $finalevent->notes = null;
                $finalevent->scheduledat = null;
                $finalevent->timecreated = !empty($application->timemodified) ? (int)$application->timemodified : time();
                $DB->insert_record('local_jobportal_appstage_events', $finalevent);
            }
        }
        $applications->close();

        upgrade_plugin_savepoint(true, 2026013106, 'local', 'jobportal');
    }

    if ($oldversion < 2026013107) {
        $dbman = $DB->get_manager();

        $stagestable = new xmldb_table('local_jobportal_stages');
        $isinternalfield = new xmldb_field('isinternal', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, 0, 'isactive');
        if (!$dbman->field_exists($stagestable, $isinternalfield)) {
            $dbman->add_field($stagestable, $isinternalfield);
        }

        $DB->execute("UPDATE {local_jobportal_stages} SET isinternal = 0 WHERE isinternal IS NULL");

        upgrade_plugin_savepoint(true, 2026013107, 'local', 'jobportal');
    }

    if ($oldversion < 2026013108) {
        $shortlisted = $DB->get_record('local_jobportal_stages', array('shortname' => 'shortlisted'));
        if (!$shortlisted) {
            $record = new stdClass();
            $record->shortname = 'shortlisted';
            $record->displayname = 'Shortlisted';
            $record->sortorder = 25;
            $record->isterminal = 0;
            $record->isactive = 1;
            $record->isinternal = 0;
            $record->hasscheduledate = 0;
            $record->timecreated = time();
            $record->timemodified = time();
            $DB->insert_record('local_jobportal_stages', $record);
        }

        upgrade_plugin_savepoint(true, 2026013108, 'local', 'jobportal');
    }

    if ($oldversion < 2026013109) {
        $now = time();

        $notshortlisted = $DB->get_record('local_jobportal_stages', array('shortname' => 'notshortlisted'));
        if (!$notshortlisted) {
            $record = new stdClass();
            $record->shortname = 'notshortlisted';
            $record->displayname = 'Not Shortlisted';
            $record->sortorder = 26;
            $record->isterminal = 1;
            $record->isactive = 1;
            $record->isinternal = 0;
            $record->hasscheduledate = 0;
            $record->timecreated = $now;
            $record->timemodified = $now;
            $DB->insert_record('local_jobportal_stages', $record);
        }

        $accepted = $DB->get_record('local_jobportal_stages', array('shortname' => 'accepted'));
        if ($accepted) {
            $acceptedupdate = new stdClass();
            $acceptedupdate->id = (int)$accepted->id;
            $acceptedupdate->displayname = 'Offer Accepted';
            $acceptedupdate->sortorder = 80;
            $acceptedupdate->isterminal = 1;
            $acceptedupdate->timemodified = $now;
            $DB->update_record('local_jobportal_stages', $acceptedupdate);
        }

        $rejected = $DB->get_record('local_jobportal_stages', array('shortname' => 'rejected'));
        if ($rejected) {
            $rejectedupdate = new stdClass();
            $rejectedupdate->id = (int)$rejected->id;
            $rejectedupdate->displayname = 'Offer Rejected';
            $rejectedupdate->sortorder = 90;
            $rejectedupdate->isterminal = 1;
            $rejectedupdate->timemodified = $now;
            $DB->update_record('local_jobportal_stages', $rejectedupdate);
        }

        upgrade_plugin_savepoint(true, 2026013109, 'local', 'jobportal');
    }

    if ($oldversion < 2026013110) {
        $dbman = $DB->get_manager();

        $applicationtable = new xmldb_table('local_jobportal_applications');
        $shortlistfield = new xmldb_field(
            'shortliststatus',
            XMLDB_TYPE_CHAR,
            '30',
            null,
            XMLDB_NOTNULL,
            null,
            'pending',
            'status'
        );

        if (!$dbman->field_exists($applicationtable, $shortlistfield)) {
            $dbman->add_field($applicationtable, $shortlistfield);
        }

        $postshortliststages = array(
            'testscheduled',
            'testdone',
            'interviewscheduled',
            'interviewdone',
            'offermade',
            'accepted',
            'rejected',
        );
        $shortlistedstates = array_merge(array('shortlisted'), $postshortliststages);

        $stagesbyid = array();
        $stages = $DB->get_records('local_jobportal_stages', null, '', 'id, shortname');
        foreach ($stages as $stage) {
            $stagesbyid[(int)$stage->id] = core_text::strtolower((string)$stage->shortname);
        }

        $applications = $DB->get_recordset('local_jobportal_applications', null, 'id ASC', 'id, status, shortliststatus, currentstageid');
        foreach ($applications as $application) {
            $status = core_text::strtolower(trim((string)$application->status));
            $shortliststatus = 'pending';

            if ($status === 'notshortlisted') {
                $shortliststatus = 'notshortlisted';
            } else if (in_array($status, $shortlistedstates, true)) {
                $shortliststatus = 'shortlisted';
            }

            $updaterecord = new stdClass();
            $updaterecord->id = (int)$application->id;
            $needsupdate = false;

            if ((string)$application->shortliststatus !== $shortliststatus) {
                $updaterecord->shortliststatus = $shortliststatus;
                $needsupdate = true;
            }

            $currentstageid = !empty($application->currentstageid) ? (int)$application->currentstageid : 0;
            $currentstageshortname = $currentstageid && isset($stagesbyid[$currentstageid]) ? $stagesbyid[$currentstageid] : '';

            if ($shortliststatus !== 'shortlisted') {
                if ($status !== 'pending') {
                    $updaterecord->status = 'pending';
                    $needsupdate = true;
                }
                if (!empty($currentstageid)) {
                    $updaterecord->currentstageid = null;
                    $needsupdate = true;
                }
            } else {
                $hasvalidpoststage = in_array($status, $postshortliststages, true);
                if (!$hasvalidpoststage && $status !== 'pending') {
                    $updaterecord->status = 'pending';
                    $needsupdate = true;
                }

                if (!empty($currentstageid) && !in_array($currentstageshortname, $postshortliststages, true)) {
                    $updaterecord->currentstageid = null;
                    $needsupdate = true;
                }
            }

            if ($needsupdate) {
                $DB->update_record('local_jobportal_applications', $updaterecord);
            }
        }
        $applications->close();

        upgrade_plugin_savepoint(true, 2026013110, 'local', 'jobportal');
    }

    if ($oldversion < 2026013111) {
        $dbman = $DB->get_manager();

        $profiletable = new xmldb_table('local_jobportal_profiles');
        $newfields = array(
            new xmldb_field('resumestatus', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'notsubmitted', 'resume'),
            new xmldb_field('resumerating', XMLDB_TYPE_INTEGER, '2', null, null, null, null, 'resumestatus'),
            new xmldb_field('resumefeedback', XMLDB_TYPE_TEXT, null, null, null, null, null, 'resumerating'),
            new xmldb_field('resumereviewedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'resumefeedback'),
            new xmldb_field('resumereviewedat', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'resumereviewedby'),
        );
        foreach ($newfields as $field) {
            if (!$dbman->field_exists($profiletable, $field)) {
                $dbman->add_field($profiletable, $field);
            }
        }

        $reviewedbykey = new xmldb_key('profresumereviewbyfk', XMLDB_KEY_FOREIGN, array('resumereviewedby'), 'user', array('id'));
        if (!$dbman->find_key_name($profiletable, $reviewedbykey)) {
            $dbman->add_key($profiletable, $reviewedbykey);
        }

        $historytable = new xmldb_table('local_jobportal_resume_review_hist');
        $historytable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $historytable->add_field('profileid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $historytable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $historytable->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'submitted');
        $historytable->add_field('rating', XMLDB_TYPE_INTEGER, '2', null, null, null, null);
        $historytable->add_field('feedback', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $historytable->add_field('action', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'managerreview');
        $historytable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $historytable->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $historytable->add_key('resrevprofilefk', XMLDB_KEY_FOREIGN, array('profileid'), 'local_jobportal_profiles', array('id'));
        $historytable->add_key('resrevuserfk', XMLDB_KEY_FOREIGN, array('userid'), 'user', array('id'));
        $historytable->add_index('resrevprofiletimeidx', XMLDB_INDEX_NOTUNIQUE, array('profileid', 'timecreated'));

        if (!$dbman->table_exists($historytable)) {
            $dbman->create_table($historytable);
        }

        $allowed = array('notsubmitted', 'submitted', 'underreview', 'needsrework', 'approved');
        $context = context_system::instance();
        $fs = get_file_storage();
        $profiles = $DB->get_recordset('local_jobportal_profiles', null, '', 'id, resumestatus');
        foreach ($profiles as $profile) {
            $status = core_text::strtolower(trim((string)$profile->resumestatus));
            if (!in_array($status, $allowed, true)) {
                $status = 'notsubmitted';
            }

            $hasresume = false;
            $files = $fs->get_area_files($context->id, 'local_jobportal', 'profile_resume', (int)$profile->id, 'id', false);
            if (!empty($files)) {
                $hasresume = true;
            }

            $newstatus = $status;
            if ($hasresume && $status === 'notsubmitted') {
                $newstatus = 'submitted';
            } else if (!$hasresume) {
                $newstatus = 'notsubmitted';
            }

            if ($newstatus !== (string)$profile->resumestatus) {
                $update = new stdClass();
                $update->id = (int)$profile->id;
                $update->resumestatus = $newstatus;
                $DB->update_record('local_jobportal_profiles', $update);
            }
        }
        $profiles->close();

        upgrade_plugin_savepoint(true, 2026013111, 'local', 'jobportal');
    }

    if ($oldversion < 2026020500) {
        $dbman = $DB->get_manager();

        $profiletable = new xmldb_table('local_jobportal_profiles');
        $approvalmodefield = new xmldb_field(
            'resumeapprovalmode',
            XMLDB_TYPE_CHAR,
            '20',
            null,
            XMLDB_NOTNULL,
            null,
            'anyone',
            'resumereviewedat'
        );
        if (!$dbman->field_exists($profiletable, $approvalmodefield)) {
            $dbman->add_field($profiletable, $approvalmodefield);
        }

        $assignmenttable = new xmldb_table('local_jobportal_resume_assignments');
        $assignmenttable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $assignmenttable->add_field('profileid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $assignmenttable->add_field('resumesignature', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
        $assignmenttable->add_field('reviewerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $assignmenttable->add_field('assignedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $assignmenttable->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'assigned');
        $assignmenttable->add_field('rating', XMLDB_TYPE_INTEGER, '2', null, null, null, null);
        $assignmenttable->add_field('feedback', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $assignmenttable->add_field('timeassigned', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $assignmenttable->add_field('timereviewed', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $assignmenttable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $assignmenttable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $assignmenttable->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $assignmenttable->add_key('resasgprofilefk', XMLDB_KEY_FOREIGN, array('profileid'), 'local_jobportal_profiles', array('id'));
        $assignmenttable->add_key('resasgreviewerfk', XMLDB_KEY_FOREIGN, array('reviewerid'), 'user', array('id'));
        $assignmenttable->add_key('resasgassignerfk', XMLDB_KEY_FOREIGN, array('assignedby'), 'user', array('id'));
        $assignmenttable->add_index('resasgprofversrevuniq', XMLDB_INDEX_UNIQUE, array('profileid', 'resumesignature', 'reviewerid'));
        $assignmenttable->add_index('resasgreviewerstatusidx', XMLDB_INDEX_NOTUNIQUE, array('reviewerid', 'status'));

        if (!$dbman->table_exists($assignmenttable)) {
            $dbman->create_table($assignmenttable);
        }

        $DB->execute(
            "UPDATE {local_jobportal_profiles}
                SET resumeapprovalmode = :defaultmode
              WHERE resumeapprovalmode IS NULL OR resumeapprovalmode = ''",
            array('defaultmode' => 'allrequired')
        );

        // Ensure new capabilities are available before role assignment.
        update_capabilities('local_jobportal');
        $context = context_system::instance();

        $rolesettings = array(
            'manager' => array(
                'local/jobportal:reviewresumes',
                'local/jobportal:assignresumereviewers',
            ),
            'editingteacher' => array(
                'local/jobportal:reviewresumes',
            ),
        );

        foreach ($rolesettings as $shortname => $capabilities) {
            $role = $DB->get_record('role', array('shortname' => $shortname));
            if (!$role) {
                continue;
            }
            foreach ($capabilities as $capability) {
                if (!get_capability_info($capability)) {
                    continue;
                }

                $existing = $DB->get_record('role_capabilities', array(
                    'contextid' => $context->id,
                    'roleid' => $role->id,
                    'capability' => $capability,
                ), 'id, permission');

                if (!$existing || (int)$existing->permission === CAP_INHERIT) {
                    assign_capability($capability, CAP_ALLOW, $role->id, $context->id, true);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026020500, 'local', 'jobportal');
    }

    if ($oldversion < 2026020501) {
        $DB->execute(
            "UPDATE {local_jobportal_profiles}
                SET resumeapprovalmode = :allrequired
              WHERE resumeapprovalmode IS NULL
                 OR resumeapprovalmode = ''
                 OR resumeapprovalmode = :anyone",
            array(
                'allrequired' => 'allrequired',
                'anyone' => 'anyone',
            )
        );

        upgrade_plugin_savepoint(true, 2026020501, 'local', 'jobportal');
    }

    if ($oldversion < 2026020502) {
        $now = time();
        $stage = $DB->get_record('local_jobportal_stages', array('shortname' => 'internalshortlisted'));
        if (!$stage) {
            $record = new stdClass();
            $record->shortname = 'internalshortlisted';
            $record->displayname = 'Internal Shortlisted';
            $record->sortorder = 15;
            $record->isterminal = 0;
            $record->isactive = 1;
            $record->isinternal = 1;
            $record->hasscheduledate = 0;
            $record->timecreated = $now;
            $record->timemodified = $now;
            $DB->insert_record('local_jobportal_stages', $record);
        }

        upgrade_plugin_savepoint(true, 2026020502, 'local', 'jobportal');
    }

    if ($oldversion < 2026020503) {
        $dbman = $DB->get_manager();

        $jobtable = new xmldb_table('local_jobportal_jobs');
        $jobindexes = array(
            new xmldb_index('jobstatusidx', XMLDB_INDEX_NOTUNIQUE, array('status')),
            new xmldb_index('jobtimecreatedidx', XMLDB_INDEX_NOTUNIQUE, array('timecreated')),
            new xmldb_index('jobdeadlineidx', XMLDB_INDEX_NOTUNIQUE, array('deadline')),
            new xmldb_index('jobtimemodifiedidx', XMLDB_INDEX_NOTUNIQUE, array('timemodified')),
            new xmldb_index('jobstatuscreatedidx', XMLDB_INDEX_NOTUNIQUE, array('status', 'timecreated')),
        );
        foreach ($jobindexes as $index) {
            if (!$dbman->index_exists($jobtable, $index)) {
                $dbman->add_index($jobtable, $index);
            }
        }

        $apptable = new xmldb_table('local_jobportal_applications');
        $appindexes = array(
            new xmldb_index('appjobidtimeidx', XMLDB_INDEX_NOTUNIQUE, array('jobid', 'timecreated')),
            new xmldb_index('appshortlistidx', XMLDB_INDEX_NOTUNIQUE, array('shortliststatus')),
            new xmldb_index('appstatusidx', XMLDB_INDEX_NOTUNIQUE, array('status')),
        );
        foreach ($appindexes as $index) {
            if (!$dbman->index_exists($apptable, $index)) {
                $dbman->add_index($apptable, $index);
            }
        }

        upgrade_plugin_savepoint(true, 2026020503, 'local', 'jobportal');
    }

    if ($oldversion < 2026020504) {
        $dbman = $DB->get_manager();

        $jobtable = new xmldb_table('local_jobportal_jobs');
        $jobfields = array(
            new xmldb_field('salarymodel', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'custom', 'salary'),
            new xmldb_field('salarycurrency', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'INR', 'salarymodel'),
            new xmldb_field('salaryperiod', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'annual', 'salarycurrency'),
            new xmldb_field('salarymin', XMLDB_TYPE_NUMBER, '12, 2', null, null, null, null, 'salaryperiod'),
            new xmldb_field('salarymax', XMLDB_TYPE_NUMBER, '12, 2', null, null, null, null, 'salarymin'),
            new xmldb_field('salaryminannual', XMLDB_TYPE_NUMBER, '12, 2', null, null, null, null, 'salarymax'),
            new xmldb_field('salarymaxannual', XMLDB_TYPE_NUMBER, '12, 2', null, null, null, null, 'salaryminannual'),
        );
        foreach ($jobfields as $field) {
            if (!$dbman->field_exists($jobtable, $field)) {
                $dbman->add_field($jobtable, $field);
            }
        }

        $jobindexes = array(
            new xmldb_index('jobsalaryminannidx', XMLDB_INDEX_NOTUNIQUE, array('salaryminannual')),
            new xmldb_index('jobsalarymaxannidx', XMLDB_INDEX_NOTUNIQUE, array('salarymaxannual')),
        );
        foreach ($jobindexes as $index) {
            if (!$dbman->index_exists($jobtable, $index)) {
                $dbman->add_index($jobtable, $index);
            }
        }

        $stagetable = new xmldb_table('local_jobportal_job_salary_stages');
        $stagetable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $stagetable->add_field('jobid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $stagetable->add_field('stagelabel', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $stagetable->add_field('amount', XMLDB_TYPE_NUMBER, '12, 2', null, XMLDB_NOTNULL, null, null);
        $stagetable->add_field('period', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'annual');
        $stagetable->add_field('conditiontext', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $stagetable->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);
        $stagetable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $stagetable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $stagetable->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $stagetable->add_key('jobsalarystagejobfk', XMLDB_KEY_FOREIGN, array('jobid'), 'local_jobportal_jobs', array('id'));
        $stagetable->add_index('jobsalarystagejobsortidx', XMLDB_INDEX_NOTUNIQUE, array('jobid', 'sortorder'));
        if (!$dbman->table_exists($stagetable)) {
            $dbman->create_table($stagetable);
        }

        $parselegacysalary = function($salarytext) {
            $salarytext = trim((string)$salarytext);
            if ($salarytext === '') {
                return null;
            }

            $normalized = preg_replace('/,/', '', $salarytext);
            $lower = core_text::strtolower($normalized);

            $currency = 'INR';
            if (strpos($lower, 'usd') !== false || strpos($lower, '$') !== false) {
                $currency = 'USD';
            } else if (strpos($lower, 'eur') !== false) {
                $currency = 'EUR';
            } else if (strpos($lower, 'gbp') !== false) {
                $currency = 'GBP';
            }

            $period = (strpos($lower, 'month') !== false || strpos($lower, '/m') !== false || strpos($lower, 'monthly') !== false)
                ? 'monthly'
                : 'annual';

            $multiplier = 1.0;
            if (strpos($lower, 'lpa') !== false || strpos($lower, 'lakh') !== false || strpos($lower, 'lac') !== false) {
                $multiplier = 100000.0;
            } else if (preg_match('/\\bk\\b/', $lower)) {
                $multiplier = 1000.0;
            }

            preg_match_all('/[0-9]+(?:\\.[0-9]+)?/', $lower, $matches);
            if (empty($matches[0])) {
                return null;
            }

            $values = array_map('floatval', $matches[0]);
            $model = count($values) > 1 ? 'range' : 'fixed';

            if ($model === 'fixed') {
                $min = $values[0] * $multiplier;
                $max = $min;
            } else {
                $first = $values[0] * $multiplier;
                $second = $values[1] * $multiplier;
                $min = min($first, $second);
                $max = max($first, $second);
            }

            $factor = $period === 'monthly' ? 12.0 : 1.0;
            return array(
                'salarymodel' => $model,
                'salarycurrency' => $currency,
                'salaryperiod' => $period,
                'salarymin' => $min,
                'salarymax' => $max,
                'salaryminannual' => $min * $factor,
                'salarymaxannual' => $max * $factor,
            );
        };

        $jobs = $DB->get_recordset(
            'local_jobportal_jobs',
            null,
            'id ASC',
            'id, salary, salarymodel, salarycurrency, salaryperiod, salarymin, salarymax, salaryminannual, salarymaxannual'
        );
        foreach ($jobs as $job) {
            $model = core_text::strtolower(trim((string)$job->salarymodel));
            $validmodels = array('fixed', 'range', 'progressive', 'undisclosed', 'custom');
            if (!in_array($model, $validmodels, true)) {
                $model = 'custom';
            }

            $currency = core_text::strtoupper(trim((string)$job->salarycurrency));
            if ($currency === '') {
                $currency = 'INR';
            }

            $period = core_text::strtolower(trim((string)$job->salaryperiod));
            if ($period !== 'monthly' && $period !== 'annual') {
                $period = 'annual';
            }

            $min = $job->salarymin !== null && $job->salarymin !== '' ? (float)$job->salarymin : null;
            $max = $job->salarymax !== null && $job->salarymax !== '' ? (float)$job->salarymax : null;
            $minannual = $job->salaryminannual !== null && $job->salaryminannual !== '' ? (float)$job->salaryminannual : null;
            $maxannual = $job->salarymaxannual !== null && $job->salarymaxannual !== '' ? (float)$job->salarymaxannual : null;

            $parsed = null;
            if ($minannual === null || $maxannual === null) {
                $parsed = $parselegacysalary($job->salary);
            }

            if ($parsed && ($model === 'custom' || $model === '')) {
                $model = $parsed['salarymodel'];
            }
            if ($parsed && $currency === 'INR') {
                $currency = $parsed['salarycurrency'];
            }
            if ($parsed && $period === 'annual') {
                $period = $parsed['salaryperiod'];
            }
            if ($min === null && $parsed) {
                $min = $parsed['salarymin'];
            }
            if ($max === null && $parsed) {
                $max = $parsed['salarymax'];
            }
            if ($minannual === null && $parsed) {
                $minannual = $parsed['salaryminannual'];
            }
            if ($maxannual === null && $parsed) {
                $maxannual = $parsed['salarymaxannual'];
            }

            if ($model === 'fixed' && $min !== null && $max === null) {
                $max = $min;
            }
            if ($model === 'fixed' && $max !== null && $min === null) {
                $min = $max;
            }
            if ($minannual === null && $min !== null) {
                $minannual = $period === 'monthly' ? ($min * 12.0) : $min;
            }
            if ($maxannual === null && $max !== null) {
                $maxannual = $period === 'monthly' ? ($max * 12.0) : $max;
            }
            if ($minannual !== null && $maxannual !== null && $minannual > $maxannual) {
                $tmp = $minannual;
                $minannual = $maxannual;
                $maxannual = $tmp;
            }
            if ($min !== null && $max !== null && $min > $max) {
                $tmp = $min;
                $min = $max;
                $max = $tmp;
            }

            $update = new stdClass();
            $update->id = (int)$job->id;
            $update->salarymodel = $model;
            $update->salarycurrency = $currency;
            $update->salaryperiod = $period;
            $update->salarymin = $min;
            $update->salarymax = $max;
            $update->salaryminannual = $minannual;
            $update->salarymaxannual = $maxannual;
            $DB->update_record('local_jobportal_jobs', $update);
        }
        $jobs->close();

        upgrade_plugin_savepoint(true, 2026020504, 'local', 'jobportal');
    }

    if ($oldversion < 2026020600) {
        $dbman = $DB->get_manager();
        $eventtable = new xmldb_table('local_jobportal_appstage_events');

        $schedulefields = array(
            new xmldb_field('schedulestatus', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'scheduled', 'scheduledat'),
            new xmldb_field('schedulemode', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'schedulestatus'),
            new xmldb_field('schedulelink', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'schedulemode'),
            new xmldb_field('schedulevenue', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'schedulelink'),
            new xmldb_field('scheduleduration', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'schedulevenue'),
        );
        foreach ($schedulefields as $field) {
            if (!$dbman->field_exists($eventtable, $field)) {
                $dbman->add_field($eventtable, $field);
            }
        }

        $DB->execute(
            "UPDATE {local_jobportal_appstage_events}
                SET schedulestatus = :status
              WHERE schedulestatus IS NULL OR schedulestatus = ''",
            array('status' => 'scheduled')
        );

        upgrade_plugin_savepoint(true, 2026020600, 'local', 'jobportal');
    }

    if ($oldversion < 2026020601) {
        $dbman = $DB->get_manager();
        $eventtable = new xmldb_table('local_jobportal_appstage_events');
        $roundoutcomefield = new xmldb_field('roundoutcome', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending', 'schedulestatus');
        if (!$dbman->field_exists($eventtable, $roundoutcomefield)) {
            $dbman->add_field($eventtable, $roundoutcomefield);
        }

        $DB->execute(
            "UPDATE {local_jobportal_appstage_events}
                SET roundoutcome = :outcome
              WHERE roundoutcome IS NULL OR roundoutcome = ''",
            array('outcome' => 'pending')
        );

        upgrade_plugin_savepoint(true, 2026020601, 'local', 'jobportal');
    }

    if ($oldversion < 2026020602) {
        $interviewdone = $DB->get_record('local_jobportal_stages', array('shortname' => 'interviewdone'));
        if ($interviewdone && (int)$interviewdone->isactive !== 0) {
            $update = new stdClass();
            $update->id = (int)$interviewdone->id;
            $update->isactive = 0;
            $update->timemodified = time();
            $DB->update_record('local_jobportal_stages', $update);
        }

        upgrade_plugin_savepoint(true, 2026020602, 'local', 'jobportal');
    }

    if ($oldversion < 2026020603) {
        $dbman = $DB->get_manager();
        $jobtable = new xmldb_table('local_jobportal_jobs');

        $fields = array(
            new xmldb_field('drivestate', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'applicationsopen', 'status'),
            new xmldb_field('driveoutcome', XMLDB_TYPE_CHAR, '30', null, null, null, null, 'drivestate'),
            new xmldb_field('drivenote', XMLDB_TYPE_TEXT, null, null, null, null, null, 'driveoutcome'),
            new xmldb_field('drivestateupdatedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'drivenote'),
            new xmldb_field('drivestateupdatedat', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'drivestateupdatedby'),
        );
        foreach ($fields as $field) {
            if (!$dbman->field_exists($jobtable, $field)) {
                $dbman->add_field($jobtable, $field);
            }
        }

        $stateuserkey = new xmldb_key('jobdrivestateuserfk', XMLDB_KEY_FOREIGN, array('drivestateupdatedby'), 'user', array('id'));
        if (!$dbman->find_key_name($jobtable, $stateuserkey)) {
            $dbman->add_key($jobtable, $stateuserkey);
        }

        $indexes = array(
            new xmldb_index('jobdrivestateidx', XMLDB_INDEX_NOTUNIQUE, array('drivestate')),
            new xmldb_index('jobdriveoutcomeidx', XMLDB_INDEX_NOTUNIQUE, array('driveoutcome')),
        );
        foreach ($indexes as $index) {
            if (!$dbman->index_exists($jobtable, $index)) {
                $dbman->add_index($jobtable, $index);
            }
        }

        $now = time();
        $DB->execute(
            "UPDATE {local_jobportal_jobs}
                SET drivestate = :archived
              WHERE status = :inactive
                AND (drivestate IS NULL OR drivestate = '' OR drivestate = :openstate)",
            array(
                'archived' => 'archived',
                'inactive' => 0,
                'openstate' => 'applicationsopen',
            )
        );
        $DB->execute(
            "UPDATE {local_jobportal_jobs}
                SET drivestate = :closed
              WHERE status = :active
                AND deadline IS NOT NULL
                AND deadline > 0
                AND deadline < :now
                AND (drivestate IS NULL OR drivestate = '' OR drivestate = :openstate)",
            array(
                'closed' => 'applicationsclosed',
                'active' => 1,
                'now' => $now,
                'openstate' => 'applicationsopen',
            )
        );
        $DB->execute(
            "UPDATE {local_jobportal_jobs}
                SET drivestate = :openstate
              WHERE drivestate IS NULL OR drivestate = ''",
            array('openstate' => 'applicationsopen')
        );
        $DB->execute(
            "UPDATE {local_jobportal_jobs}
                SET driveoutcome = NULL
              WHERE driveoutcome = ''"
        );

        upgrade_plugin_savepoint(true, 2026020603, 'local', 'jobportal');
    }

    if ($oldversion < 2026020604) {
        $legacydone = array('testdone', 'interviewdone');
        $now = time();
        foreach ($legacydone as $shortname) {
            $stage = $DB->get_record('local_jobportal_stages', array('shortname' => $shortname));
            if ($stage && (int)$stage->isactive !== 0) {
                $update = new stdClass();
                $update->id = (int)$stage->id;
                $update->isactive = 0;
                $update->timemodified = $now;
                $DB->update_record('local_jobportal_stages', $update);
            }
        }

        upgrade_plugin_savepoint(true, 2026020604, 'local', 'jobportal');
    }

    if ($oldversion < 2026020605) {
        $dbman = $DB->get_manager();

        $table = new xmldb_table('local_jobportal_apply_overrides');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('isenabled', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, 0);
        $table->add_field('reason', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('expiresat', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('setby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $table->add_key('applyovruseruniqfk', XMLDB_KEY_FOREIGN_UNIQUE, array('userid'), 'user', array('id'));
        $table->add_key('applyovrsetbyfk', XMLDB_KEY_FOREIGN, array('setby'), 'user', array('id'));

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026020605, 'local', 'jobportal');
    }

    if ($oldversion < 2026020606) {
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_jobportal_apply_overrides');

        $fields = array(
            new xmldb_field('isblocked', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, 0, 'expiresat'),
            new xmldb_field('blockreason', XMLDB_TYPE_TEXT, null, null, null, null, null, 'isblocked'),
            new xmldb_field('blockexpiresat', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'blockreason'),
        );
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2026020606, 'local', 'jobportal');
    }

    if ($oldversion < 2026020607) {
        $oldvalue = get_config('local_jobportal', 'studentpolicy_blockinterviewnoshow');
        $newvalue = get_config('local_jobportal', 'studentpolicy_blocknoshow');

        if ($newvalue === false && $oldvalue !== false) {
            set_config('studentpolicy_blocknoshow', empty($oldvalue) ? 0 : 1, 'local_jobportal');
        }
        if ($oldvalue !== false) {
            unset_config('studentpolicy_blockinterviewnoshow', 'local_jobportal');
        }

        upgrade_plugin_savepoint(true, 2026020607, 'local', 'jobportal');
    }

    return true;
}
