<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');
require_once(__DIR__ . '/locallib.php');

require_login();

$context = context_system::instance();
require_capability('local/jobportal:apply', $context);

$page = optional_param('page', 0, PARAM_INT);
if ($page < 0) {
    $page = 0;
}
$perpage = 10;

$PAGE->set_context($context);
$pageurlparams = array();
if (!empty($page)) {
    $pageurlparams['page'] = $page;
}
$PAGE->set_url(new moodle_url('/local/jobportal/myapplications.php', $pageurlparams));
$PAGE->set_title(get_string('myapplications', 'local_jobportal'));
$PAGE->set_heading(get_string('myapplications', 'local_jobportal'));
local_jobportal_require_styles();
$dateformat = '%d/%m/%Y';
$datetimeformat = '%d/%m/%Y %H:%M';

echo $OUTPUT->header();
echo local_jobportal_render_navigation($context, 'myapplications');

// Wrap page
echo html_writer::start_tag('div', array('class' => 'local-jobportal-page'));

// Calculate Stats
$stats = $DB->get_record_sql("
    SELECT
        COUNT(id) AS total,
        SUM(CASE WHEN shortliststatus = 'shortlisted' THEN 1 ELSE 0 END) AS shortlisted
    FROM {local_jobportal_applications}
    WHERE userid = :userid
", array('userid' => $USER->id));
$totalapps = $stats->total ?? 0;
$shortlistedapps = $stats->shortlisted ?? 0;

// HERO SECTION
echo html_writer::start_tag('div', array('class' => 'jp-page-hero mb-4'));
echo html_writer::start_div('container-fluid');
echo html_writer::start_div('row align-items-center');

echo html_writer::start_div('col-md-6');
echo html_writer::tag('h2', get_string('myapplications', 'local_jobportal'), array('class' => 'jp-hero-title mb-2'));
echo html_writer::tag('p', 'Track your job applications and recruitment progress.', array('class' => 'jp-hero-subtitle mb-0'));
echo html_writer::end_div();

echo html_writer::start_div('col-md-6 text-md-right mt-3 mt-md-0');
echo html_writer::start_div('d-flex justify-content-md-end gap-3');
echo html_writer::start_div('text-center px-3 border-right border-white-50');
echo html_writer::tag('div', $totalapps, array('class' => 'h3 text-white mb-0 font-weight-bold'));
echo html_writer::tag('div', 'Total Applied', array('class' => 'small text-white-50 text-uppercase font-weight-bold'));
echo html_writer::end_div();
echo html_writer::start_div('text-center px-3');
echo html_writer::tag('div', $shortlistedapps, array('class' => 'h3 text-success mb-0 font-weight-bold'));
echo html_writer::tag('div', 'Shortlisted', array('class' => 'small text-white-50 text-uppercase font-weight-bold'));
echo html_writer::end_div();
echo html_writer::end_div(); // d-flex
echo html_writer::end_div(); // col-md-6

echo html_writer::end_div(); // row
echo html_writer::end_div(); // container-fluid
echo html_writer::end_tag('div'); // jp-page-hero

echo html_writer::start_div('container-fluid');

if (!empty($offerhighlight->hasoffer)) {
    $statusclass = preg_replace('/[^a-z0-9_-]/i', '', (string)$offerhighlight->status);
    $toneclass = 'jp-offer-tone-' . $statusclass;
    $emotionhtml = local_jobportal_get_offer_status_emotion_html(
        $statusclass,
        (string)$offerhighlight->jobtitle,
        (string)$offerhighlight->company
    );
    $jobcompany = trim(format_string($offerhighlight->company));
    $jobtitle = format_string($offerhighlight->jobtitle);
    $updated = !empty($offerhighlight->timemodified) ? userdate((int)$offerhighlight->timemodified, $datetimeformat) : '-';
    $statusbadge = html_writer::tag('span', $offerhighlight->statuslabel, array(
        'class' => 'badge badge-light',
    ));

    echo html_writer::start_div('jp-notification-banner mb-4');
    echo html_writer::tag('div', '🎉 ' . $jobtitle . ($jobcompany !== '' ? ' | ' . $jobcompany : '') . ' - ' . $statusbadge, array('class' => 'font-weight-bold'));
    echo html_writer::tag('div', get_string('offerhighlightupdated', 'local_jobportal', $updated), array('class' => 'small mt-1'));
    echo html_writer::end_div();
}

if (empty($applications)) {
    echo html_writer::tag('p', get_string('noapplications', 'local_jobportal'), 
        array('class' => 'alert alert-info'));
} else {
    $pagingurl = new moodle_url('/local/jobportal/myapplications.php');
    if ($totalapplications > $perpage) {
        echo $OUTPUT->paging_bar($totalapplications, $page, $perpage, $pagingurl);
    }

    echo html_writer::start_tag('div', array('class' => 'jp-myapp-list'));
    
    foreach ($applications as $app) {
        $appevents = !empty($eventsbyapp[$app->id]) ? $eventsbyapp[$app->id] : array();
        $visibleevents = local_jobportal_get_applicant_visible_stage_events($appevents, $stages);
        $shortliststatus = local_jobportal_get_applicant_visible_shortlist_status($app);
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
        $visiblestage = local_jobportal_get_applicant_visible_stage($app, $appevents, $stages);
        $poststagedisplay = get_string('poststagenotset', 'local_jobportal');
        if ($shortliststatus !== 'shortlisted') {
            $poststagedisplay = '-';
        } else if ($visiblestage) {
            $poststagedisplay = format_string($visiblestage->displayname);
        }
        $poststageclass = 'badge badge-secondary';
        if ($visiblestage) {
            switch ($visiblestage->shortname) {
                case 'accepted':
                    $poststageclass = 'badge badge-success';
                    break;
                case 'rejected':
                    $poststageclass = 'badge badge-danger';
                    break;
                case 'offermade':
                    $poststageclass = 'badge badge-primary';
                    break;
                default:
                    $poststageclass = 'badge badge-info';
                    break;
            }
        }
        $offerstage = ($visiblestage && local_jobportal_is_offer_stage_shortname($visiblestage->shortname)) ?
            $visiblestage->shortname : '';
        $offerstatuslabel = $offerstage !== '' ? local_jobportal_get_apply_lock_stage_label($offerstage) : '';

        $joburl = new moodle_url('/local/jobportal/view.php', array('id' => $app->jobid));
        $poststagelabel = ($shortliststatus === 'shortlisted') ?
            html_writer::tag('span', $poststagedisplay, array('class' => $poststageclass . ' jp-myapp-badge')) :
            html_writer::tag('span', $poststagedisplay, array('class' => 'jp-myapp-stage-muted'));

        echo html_writer::start_div('jp-myapp-card');
        echo html_writer::start_div('jp-myapp-head');
        echo html_writer::start_div('jp-myapp-title-wrap');
        echo html_writer::tag('h5', format_string($app->title), array('class' => 'jp-myapp-title'));
        echo html_writer::div(format_string($app->company), 'jp-myapp-company');
        echo html_writer::end_div();
        echo html_writer::start_div('jp-myapp-status-wrap');
        if ($offerstage !== '') {
            echo html_writer::div(
                html_writer::tag('span', get_string('offerstatus', 'local_jobportal'), array('class' => 'jp-myapp-status-label')) .
                html_writer::tag('span', $offerstatuslabel, array('class' => 'jp-offer-chip jp-offer-chip--' . $offerstage)),
                'jp-myapp-status-row jp-myapp-status-row-offer'
            );
        }
        echo html_writer::div(
            html_writer::tag('span', get_string('shortliststatus', 'local_jobportal'), array('class' => 'jp-myapp-status-label')) .
            html_writer::tag('span', $shortlistlabel, array('class' => $shortlistclass . ' jp-myapp-badge')),
            'jp-myapp-status-row'
        );
        echo html_writer::div(
            html_writer::tag('span', get_string('postshortliststage', 'local_jobportal'), array('class' => 'jp-myapp-status-label')) .
            $poststagelabel,
            'jp-myapp-status-row'
        );
        echo html_writer::end_div();
        echo html_writer::end_div();

        echo html_writer::start_div('jp-myapp-meta-grid');
        echo html_writer::start_div('jp-myapp-meta-item');
        echo html_writer::div(get_string('appliedon', 'local_jobportal'), 'jp-myapp-meta-label');
        echo html_writer::div(userdate($app->timecreated, $dateformat), 'jp-myapp-meta-value');
        echo html_writer::end_div();

        echo html_writer::start_div('jp-myapp-meta-item');
        echo html_writer::div(get_string('joblistedon', 'local_jobportal'), 'jp-myapp-meta-label');
        echo html_writer::div(userdate($app->joblistedon, $dateformat), 'jp-myapp-meta-value');
        echo html_writer::end_div();

        if (!empty($app->location)) {
            echo html_writer::start_div('jp-myapp-meta-item');
            echo html_writer::div(get_string('location', 'local_jobportal'), 'jp-myapp-meta-label');
            echo html_writer::div(format_string($app->location), 'jp-myapp-meta-value');
            echo html_writer::end_div();
        }
        echo html_writer::end_div();

        echo html_writer::start_div('jp-myapp-timeline');
        echo html_writer::start_tag('details', array('class' => 'jp-myapp-timeline-toggle'));
        echo html_writer::tag(
            'summary',
            html_writer::tag('span', get_string('showtimeline', 'local_jobportal'), array('class' => 'jp-myapp-timeline-label-show')) .
            html_writer::tag('span', get_string('hidetimeline', 'local_jobportal'), array('class' => 'jp-myapp-timeline-label-hide')),
            array('class' => 'jp-myapp-timeline-summary')
        );
        echo html_writer::start_div('jp-myapp-timeline-body');
        echo html_writer::tag('h6', get_string('stagetimeline', 'local_jobportal'), array('class' => 'jp-myapp-section-title'));
        if (empty($visibleevents)) {
            if ($shortliststatus === 'shortlisted') {
                echo html_writer::tag('p', get_string('poststagenotset', 'local_jobportal'), array('class' => 'jp-myapp-empty'));
            } else {
                echo html_writer::tag('p', get_string('applicationinprogress', 'local_jobportal'), array('class' => 'jp-myapp-empty'));
            }
        } else {
            $upcomingevents = array();
            $historyevents = array();
            $nowts = time();
            $stageeventcounts = array();
            $stageroundsbyeventid = array();
            foreach ($visibleevents as $event) {
                if (empty($event->hasscheduledate)) {
                    continue;
                }
                $stageid = (int)$event->stageid;
                if (!isset($stageeventcounts[$stageid])) {
                    $stageeventcounts[$stageid] = 0;
                }
                $stageeventcounts[$stageid]++;
                $stageroundsbyeventid[(int)$event->id] = $stageeventcounts[$stageid];
            }
            foreach ($visibleevents as $event) {
                $schedulestatus = !empty($event->schedulestatus) ?
                    local_jobportal_normalize_schedule_status($event->schedulestatus) : 'scheduled';
                $scheduledat = !empty($event->scheduledat) ? (int)$event->scheduledat : 0;
                $isupcoming = !empty($scheduledat) &&
                    $scheduledat >= $nowts &&
                    in_array($schedulestatus, array('scheduled', 'rescheduled'), true);
                if ($isupcoming) {
                    $upcomingevents[] = $event;
                } else {
                    $historyevents[] = $event;
                }
            }

            usort($upcomingevents, function($a, $b) {
                $atime = !empty($a->scheduledat) ? (int)$a->scheduledat : PHP_INT_MAX;
                $btime = !empty($b->scheduledat) ? (int)$b->scheduledat : PHP_INT_MAX;
                if ($atime !== $btime) {
                    return $atime <=> $btime;
                }
                $acreated = !empty($a->timecreated) ? (int)$a->timecreated : 0;
                $bcreated = !empty($b->timecreated) ? (int)$b->timecreated : 0;
                if ($acreated !== $bcreated) {
                    return $acreated <=> $bcreated;
                }
                return ((int)$a->id) <=> ((int)$b->id);
            });

            usort($historyevents, function($a, $b) {
                $acreated = !empty($a->timecreated) ? (int)$a->timecreated : 0;
                $bcreated = !empty($b->timecreated) ? (int)$b->timecreated : 0;
                if ($acreated !== $bcreated) {
                    return $bcreated <=> $acreated;
                }
                return ((int)$b->id) <=> ((int)$a->id);
            });

            $renderevent = function($event) use ($datetimeformat, $stageroundsbyeventid) {
                $eventmeta = userdate($event->timecreated, $datetimeformat);
                $eventlink = '';
                if (!empty($event->hasscheduledate)) {
                    if (!empty($event->scheduledat)) {
                        $eventmeta .= ' | ' . get_string('scheduledfor', 'local_jobportal') . ': ' .
                            userdate($event->scheduledat, $datetimeformat);
                    }
                    if (!empty($event->schedulestatus)) {
                        $eventmeta .= ' | ' . get_string(
                            'schedulestatusvalue',
                            'local_jobportal',
                            local_jobportal_get_schedule_status_label($event->schedulestatus)
                        );
                    }
                    $eventoutcome = !empty($event->roundoutcome) ? local_jobportal_normalize_round_outcome($event->roundoutcome) : 'pending';
                    $eventstatus = !empty($event->schedulestatus) ? local_jobportal_normalize_schedule_status($event->schedulestatus) : 'scheduled';
                    if ($eventstatus === 'completed' || $eventoutcome !== 'pending') {
                        $eventmeta .= ' | ' . get_string(
                            'roundoutcomevalue',
                            'local_jobportal',
                            local_jobportal_get_round_outcome_label($eventoutcome)
                        );
                    }
                    if (!empty($event->schedulemode)) {
                        $eventmeta .= ' | ' . get_string(
                            'schedulemodevalue',
                            'local_jobportal',
                            local_jobportal_get_schedule_mode_label($event->schedulemode)
                        );
                    }
                    if (!empty($event->scheduleduration)) {
                        $eventmeta .= ' | ' . get_string('scheduledurationvalue', 'local_jobportal', (int)$event->scheduleduration);
                    }
                    if (!empty($event->schedulevenue)) {
                        $eventmeta .= ' | ' . get_string('schedulevenuevalue', 'local_jobportal', s(trim((string)$event->schedulevenue)));
                    }
                    if (!empty($event->schedulelink)) {
                        $rawlink = trim((string)$event->schedulelink);
                        if (preg_match('#^https?://#i', $rawlink)) {
                            $eventlink = html_writer::div(
                                html_writer::link($rawlink, get_string('schedulelink', 'local_jobportal'), array('target' => '_blank', 'rel' => 'noopener')),
                                'jp-myapp-timeline-meta'
                            );
                        } else {
                            $eventlink = html_writer::div(
                                get_string('schedulelinkvalue', 'local_jobportal', s($rawlink)),
                                'jp-myapp-timeline-meta'
                            );
                        }
                    }
                }
                $stagename = format_string($event->displayname);
                if (!empty($stageroundsbyeventid[(int)$event->id])) {
                    $stagename .= ' - ' . get_string('roundlabel', 'local_jobportal', (int)$stageroundsbyeventid[(int)$event->id]);
                }
                return html_writer::tag(
                    'li',
                    html_writer::div($stagename, 'jp-myapp-timeline-stage') .
                    html_writer::div($eventmeta, 'jp-myapp-timeline-meta') .
                    $eventlink,
                    array('class' => 'jp-myapp-timeline-item')
                );
            };

            if (!empty($upcomingevents)) {
                echo html_writer::tag('h6', get_string('timelineupcoming', 'local_jobportal'), array('class' => 'jp-myapp-section-title'));
                echo html_writer::start_tag('ul', array('class' => 'jp-myapp-timeline-list'));
                foreach ($upcomingevents as $event) {
                    echo $renderevent($event);
                }
                echo html_writer::end_tag('ul');
            }

            if (!empty($historyevents)) {
                echo html_writer::tag('h6', get_string('timelinehistory', 'local_jobportal'), array('class' => 'jp-myapp-section-title mt-3'));
                echo html_writer::start_tag('ul', array('class' => 'jp-myapp-timeline-list'));
                foreach ($historyevents as $event) {
                    echo $renderevent($event);
                }
                echo html_writer::end_tag('ul');
            }
        }
        echo html_writer::end_div();
        echo html_writer::end_tag('details');
        echo html_writer::end_div();

        echo html_writer::start_div('jp-myapp-actions');
        echo html_writer::link($joburl, get_string('viewjob', 'local_jobportal'), array('class' => 'btn btn-sm btn-primary'));
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
    
    echo html_writer::end_tag('div');

    if ($totalapplications > $perpage) {
        echo $OUTPUT->paging_bar($totalapplications, $page, $perpage, $pagingurl);
    }
}

// Back link
echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/jobportal/index.php'),
        '← ' . get_string('alljobs', 'local_jobportal')
    ),
    'mt-3 mb-4'
);

echo html_writer::end_div(); // End container-fluid
echo html_writer::end_tag('div'); // End local-jobportal-page

echo $OUTPUT->footer();
