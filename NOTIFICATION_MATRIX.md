# Job Portal Notification Event-to-Template Matrix

Use these placeholders in templates:

- `{{site_name}}`
- `{{job_title}}`
- `{{company_name}}`
- `{{student_name}}`
- `{{manager_name}}`
- `{{stage_label}}`
- `{{scheduled_at}}`
- `{{job_url}}`
- `{{applications_url}}`
- `{{profile_url}}`
- `{{timestamp}}`
- `{{note_public}}`

## Matrix

| Event Key | Audience | Trigger (Exact) | Channel | Subject Template | Body Template |
|---|---|---|---|---|---|
| `app_submitted_student` | Student | New record in `local_jobportal_applications` | In-app + Email | `Application received: {{job_title}}` | `Hi {{student_name}}, your application for {{job_title}} at {{company_name}} was received on {{timestamp}}. Track it here: {{job_url}}` |
| `app_submitted_manager` | Managers (`viewapplications`) | New application for a job | In-app + Email | `New applicant: {{job_title}}` | `A new application was received for {{job_title}} ({{company_name}}). Open: {{applications_url}}` |
| `stage_changed_student` | Student | Stage change where stage `isinternal=0` and not a scheduling event | In-app + Email | `Status updated: {{job_title}}` | `Your application stage is now: {{stage_label}} for {{job_title}} at {{company_name}}.` |
| `stage_changed_manager_digest` | Managers | Any stage update (single/bulk) | In-app (optional email digest) | `Stage updates applied` | `{{manager_name}} updated {{count}} application(s) to {{stage_label}} for {{job_title}}.` |
| `schedule_set_student` | Student | Stage with `hasscheduledate=1` and `isinternal=0` set/updated | In-app + Email | `Scheduled: {{stage_label}} for {{job_title}}` | `Your {{stage_label}} is scheduled for {{scheduled_at}} ({{company_name}}).` |
| `final_not_shortlisted` | Student | Stage = `notshortlisted` | In-app + Email | `Update: {{job_title}}` | `Thank you for applying. You were not shortlisted for {{job_title}} at {{company_name}}.` |
| `final_offer_accepted_stage` | Student | Stage = `accepted` (display: Offer Accepted) | In-app + Email | `Offer accepted status: {{job_title}}` | `Your application status is now Offer Accepted for {{job_title}} at {{company_name}}.` |
| `final_offer_rejected_stage` | Student | Stage = `rejected` (display: Offer Rejected) | In-app + Email | `Offer update: {{job_title}}` | `Your application status is now Offer Rejected for {{job_title}} at {{company_name}}.` |
| `deadline_reminder_manager_3d` | Managers | Job deadline in 3 days and job active | In-app + Email | `Deadline approaching (3 days): {{job_title}}` | `Application deadline for {{job_title}} is approaching on {{scheduled_at}}.` |
| `deadline_reminder_manager_1d` | Managers | Job deadline in 1 day and job active | In-app + Email | `Deadline tomorrow: {{job_title}}` | `Deadline for {{job_title}} is tomorrow ({{scheduled_at}}).` |
| `stale_application_manager` | Managers | No stage change for X days (e.g., 5) on active applications | In-app | `No activity: {{job_title}}` | `{{count}} application(s) for {{job_title}} have no stage movement for {{days}} days.` |
| `upcoming_schedule_manager_daily` | Managers | Daily cron: tests/interviews in next 24h | In-app + Email digest | `Upcoming schedules (24h)` | `You have {{count}} scheduled events in next 24h for {{job_title}}.` |
| `bulk_update_summary_manager` | Acting manager | Bulk update completes | In-app | `Bulk update completed` | `Updated {{success_count}} application(s) to {{stage_label}}. Failed: {{failed_count}}.` |
| `profile_resume_missing_student` | Student | Apply attempt blocked due to missing resume | In-app | `Resume required before applying` | `Please upload your resume in profile before applying: {{profile_url}}` |

## Rules

- Never notify students for internal-only stages (`isinternal=1`).
- Never notify students for manager-only notes/comments.
- De-dup key: `{{application_id}} + {{event_key}} + {{stage_id}} + {{scheduled_at}}`.
- Suppress duplicate sends within 10 minutes.
- Respect channel preferences (in-app only / email only / both).
