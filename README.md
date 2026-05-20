# Moodle Job Portal Plugin (`local_jobportal`)

Job Portal for Moodle to manage company-driven hiring workflows.

- Students browse jobs, apply, and track progress.
- Managers manage companies, post jobs, review applicants, and run resume-review workflows.
- Plugin includes dashboards, bulk operations, stage timelines, and deployment scripts.

## Role Guides

- Manager guide: `README_MANAGERS.md`
- Student guide: `README_STUDENTS.md`
- Installation/setup: `INSTALLATION.md`
- Developer/technical details: `DEVELOPER_GUIDE.md`

## Main Features

### Student side

- Job listing with search/filter and detailed job view.
- Resume-based apply flow (resume comes from profile, no upload during apply).
- `My Applications` with shortlist + post-shortlist timeline.
- `My Profile` with resume review status, rating, and feedback.
- Offer-highlight banner on navigation.

### Manager side

- Company profile management (logo, website, description).
- Job posting/editing/cloning with structured salary models.
- Application management with table view + bulk updates.
- Separate single-application page for detailed review/edit.
- Resume queue, reviewer assignment, and reviewer inbox (`My Resume Reviews`).
- Manager dashboards:
  - `dashboard.php` (funnel/stage analytics)
  - `jobsdashboard.php` (job-post health metrics)
- XLS export (full/filtered applicants).

### Workflow support

- Shortlist states: `pending`, `internalshortlisted`, `shortlisted`, `notshortlisted`.
- Post-shortlist stages: `testscheduled`, `interviewscheduled`, `offermade`, `accepted`, `rejected`.
- Multi-round scheduling for test/interview stages.
- Schedule status: `scheduled`, `rescheduled`, `completed`, `cancelled`, `noshow`, `excused`.
- Round outcome (when completed): `cleared` / `notcleared`.
- Internal-only stage visibility for manager-only timeline steps.

## Apply Eligibility and Blocking

Student apply eligibility is enforced by policy + lock rules.

- Offer-stage lock: student is locked when any application reaches:
  - `offermade`, `accepted`, or `rejected`
- No-show lock (configurable): any test/interview round marked `noshow` locks applying.
  - Mark that round as `excused` to clear no-show impact.
- Manual manager block: manager can block a student from applying.
- Manager override: manager can temporarily allow applying despite non-manual locks.

Lock precedence:
1. Manual block
2. Offer-stage lock
3. No-show lock

## Salary Models

Supported salary types in job post form:

- `Fixed`
- `Range`
- `Progressive` (stage-based rows)
- `Undisclosed`
- `Custom / Text`

Progressive salary rows are structured and stored in `local_jobportal_job_salary_stages`.

## Installation (Quick)

1. Place plugin in Moodle local plugins directory:
   - `moodle/local/jobportal`
2. Run Moodle upgrade:
   - Site admin -> Notifications
   - or CLI: `php admin/cli/upgrade.php --non-interactive`
3. Verify plugin appears in local plugins.
4. Configure permissions and plugin settings.

See `INSTALLATION.md` for full setup.

## Capabilities

Defined in `db/access.php`:

- `local/jobportal:viewjobs`
- `local/jobportal:apply`
- `local/jobportal:postjobs`
- `local/jobportal:managejobs`
- `local/jobportal:viewapplications`
- `local/jobportal:managecompanyprofile`
- `local/jobportal:reviewresumes`
- `local/jobportal:assignresumereviewers`

## Admin Settings

Configured from Site administration -> Plugins -> Local plugins -> Job Portal.

### Preset filters

- Enable/disable each jobs preset (open, closing soon, deadline today/tomorrow, no apps, stale, no activity).
- Configure day thresholds for day-based presets.

### Student offer messages

- Custom messages for `Offer Made`, `Offer Accepted`, `Offer Rejected`.
- Supports `{jobtitle}` and `{company}` placeholders.

### Student Job Access Policy

- Student jobs feed mode (`openjobs` / `alljobs`)
- Require resume approved
- Block applying on any no-show
- Max active applications
- Weekly application limit
- Not-shortlisted cooldown controls

## Cluster Deployment Script

Script: `scripts/deploy_cluster.sh`

Basic run:

```bash
./scripts/deploy_cluster.sh --config ./scripts/deploy_cluster.env
```

Useful options:

- `--dry-run`
- `--with-maintenance` (default behavior is skip maintenance)
- `--skip-upgrade`
- `--seed`
- `--seed-args "..."`
- `--skip-purge`

Config template: `scripts/deploy_cluster.env.example`

## Seed Test Data

CLI seeder:

```bash
php local/jobportal/cli/seed_test_data.php
```

Example:

```bash
php local/jobportal/cli/seed_test_data.php --companies=20 --jobspercompany=4 --prefix=QASeed
```

Seeder creates India-focused sample companies/jobs and supports structured salary generation.

## Key Pages

- `index.php` - All jobs
- `view.php` - Job details
- `post.php` - Post/edit/clone job
- `applications.php` - Applicants list + bulk updates
- `application.php` - Single application detail/edit page
- `myapplications.php` - Student applications
- `profile.php` - Student profile
- `companyprofile.php` - Manage companies
- `company.php` - Company detail page
- `dashboard.php` - Manager analytics dashboard
- `jobsdashboard.php` - Job-post dashboard
- `resume_queue.php` - Resume queue
- `resume_review.php` - Resume review center
- `myreviews.php` - Reviewer inbox
- `stages.php` - Stage visibility management

## Database Tables

Main tables (see `db/install.xml`):

- `local_jobportal_companies`
- `local_jobportal_jobs`
- `local_jobportal_job_salary_stages`
- `local_jobportal_stages`
- `local_jobportal_applications`
- `local_jobportal_apply_overrides`
- `local_jobportal_appstage_events`
- `local_jobportal_appnotes`
- `local_jobportal_profiles`
- `local_jobportal_resume_review_hist`
- `local_jobportal_resume_assignments`

## Troubleshooting

- Permission errors (`nopermissions`): check role capabilities.
- Upgrade errors: check `version.php`, `db/install.xml`, `db/upgrade.php` consistency.
- SQL param errors: ensure placeholder count/type matches params.
- Resume apply issues: verify resume exists in profile and policy allows apply.

## License

GPL v3+
