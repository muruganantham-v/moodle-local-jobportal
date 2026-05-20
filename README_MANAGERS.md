# Job Portal Manager Guide

This guide is for managers/reviewers operating hiring workflows in `local_jobportal`.

## Required Capabilities

Your role should include:

- `local/jobportal:postjobs`
- `local/jobportal:managejobs`
- `local/jobportal:viewapplications`
- `local/jobportal:managecompanyprofile`
- `local/jobportal:reviewresumes`
- `local/jobportal:assignresumereviewers` (only for reviewer assignment)

If you get `nopermissions`, ask site admin to update role permissions.

## Navigation (Manager)

Quick navigation typically includes:

- `All Jobs`
- `Post a Job`
- `Manager Dashboard`
- `Job Posts Dashboard`
- `Manage Stages`
- `Manage Companies`
- `Resume Queue`
- `My Resume Reviews` (if reviewer)

## Recommended Workflow

1. Create/update company profile (`companyprofile.php`).
2. Post job (`post.php`) and link it to company.
3. Track jobs from `index.php` and dashboards.
4. Open applicant list from `applications.php`.
5. Use bulk updates for shortlist/stage operations.
6. Open `application.php` for detailed applicant-level actions.
7. Run resume review flow from `resume_queue.php` / `resume_review.php`.

## Application Lifecycle

### Shortlist statuses

- `Pending`
- `Internal Shortlisted`
- `Shortlisted`
- `Not Shortlisted`

### Post-shortlist stages

- `Test Scheduled`
- `Interview Scheduled`
- `Offer Made`
- `Offer Accepted`
- `Offer Rejected`

Only shortlisted applications can move into post-shortlist stages.

## Scheduling and Rounds

For schedulable stages (test/interview), each round supports:

- Schedule status:
  - `Scheduled`, `Rescheduled`, `Completed`, `Cancelled`, `No Show`, `Excused`
- Round outcome:
  - `Cleared`, `Not Cleared` (only when status is `Completed`)
- Optional metadata:
  - datetime, mode, link, venue, duration, notes

Use `Update Existing Round` to close/update a specific prior round.

## No-show and Apply Lock Controls

Students can be blocked from new applications by:

- Offer-stage reached (`Offer Made`, `Offer Accepted`, `Offer Rejected`)
- Any `No Show` round (if policy enabled)
- Manual manager block

In the applicant detail section, use **Application Eligibility Override**:

- `Allow student to apply for new jobs` (override)
- `Block student from applying for new jobs` (manual block)
- Optional reason + expiry for both

Notes:

- Manual block has highest priority.
- Marking a no-show round as `Excused` removes no-show impact.

## Resume Review Workflow

- Queue page: `resume_queue.php`
- Review center: `resume_review.php`
- Reviewer inbox: `myreviews.php`

Flow:

1. Assign reviewers (if permitted).
2. Reviewers submit decision, rating, and feedback.
3. Final profile resume status is recomputed from assignments.
4. History is recorded in resume review history.

## Dashboards and Filters

### All Jobs (`index.php`)

- Advanced filters (company, status, dates, salary, etc.)
- Sort + column picker
- Presets (configurable in settings)
- Bulk actions (open/close/extend/clone)

### Applications (`applications.php`)

- Applicant filters and sorting
- Bulk updates
- Export filtered applicants to XLS

### Dashboards

- `dashboard.php`: funnel and stage analytics
- `jobsdashboard.php`: per-job metrics and health

## Stage Visibility

Manage stage visibility from `stages.php`.

- Internal-only stages are hidden from students.
- Managers still see full timeline/details.

## Common Troubleshooting

- Student cannot apply:
  - Check profile resume, policy limits, locks, and manual block state.
- Cannot complete/cancel/no-show a round:
  - Ensure a prior scheduled/rescheduled round exists for that stage.
- Upgrade/coding errors:
  - Verify `version.php`, `db/install.xml`, and `db/upgrade.php` alignment.
