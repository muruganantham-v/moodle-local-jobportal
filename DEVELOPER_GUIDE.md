# Developer Guide
## Moodle Job Portal (`local_jobportal`)

This guide summarizes architecture, data model, and extension points for this plugin.

## Plugin Structure

```text
local/jobportal/
├── db/
│   ├── access.php
│   ├── install.php
│   ├── install.xml
│   └── upgrade.php
├── lang/en/
│   └── local_jobportal.php
├── amd/src/
│   ├── applications_ui.js
│   ├── index_filters.js
│   ├── resume_preview.js
│   └── view_drive_state.js
├── amd/build/
│   └── *.min.js
├── cli/
│   └── seed_test_data.php
├── scripts/
│   ├── deploy_cluster.sh
│   └── deploy_cluster.env.example
├── partials/
│   ├── application_detail_actions.php
│   └── application_detail_section.php
├── locallib.php
├── lib.php
├── settings.php
├── styles.css
└── page controllers (*.php)
```

## Main Controllers

- `index.php`: All jobs (manager + student views)
- `view.php`: Job detail
- `post.php`: Create/edit/clone job
- `applications.php`: Applicant list + filters + bulk updates
- `application.php`: Single application detail page
- `apply.php`: Student apply endpoint
- `myapplications.php`: Student application tracking
- `profile.php`: Student profile + resume upload
- `companyprofile.php`: Company management (manager)
- `company.php`: Company detail page
- `dashboard.php`: Manager funnel analytics
- `jobsdashboard.php`: Job-post analytics
- `stages.php`: Stage visibility toggles
- `resume_queue.php`: Resume queue
- `resume_review.php`: Resume review center
- `myreviews.php`: Reviewer inbox

## Core Domain Model

### Job and drive

- Jobs are stored in `local_jobportal_jobs`.
- Drive state is separate from legacy active/inactive status.
- Drive state options:
  - `applicationsopen`
  - `applicationsclosed`
  - `selectioninprogress`
  - `completed`
  - `archived`
  - `onhold`
  - `cancelled`

### Salary model

Supported salary models:

- `fixed`
- `range`
- `progressive`
- `undisclosed`
- `custom`

Progressive stages are normalized in `local_jobportal_job_salary_stages`.

### Application lifecycle

#### Shortlist state (`shortliststatus`)

- `pending`
- `internalshortlisted`
- `shortlisted`
- `notshortlisted`

#### Post-shortlist stage (`status`)

Canonical active flow:

- `testscheduled`
- `interviewscheduled`
- `offermade`
- `accepted`
- `rejected`

Legacy done stages (`testdone`, `interviewdone`) are treated as backward compatibility only.

### Stage events (timeline)

`local_jobportal_appstage_events` stores transition history and round schedule metadata.

Schedule status:

- `scheduled`, `rescheduled`, `completed`, `cancelled`, `noshow`, `excused`

Round outcome:

- `pending`, `cleared`, `notcleared`

Validation rule:

- outcome must be `pending` unless status is `completed`.

## Student Apply Eligibility

Primary resolver:

- `local_jobportal_get_student_apply_lock_info()` in `locallib.php`

Lock precedence:

1. Manual block (`local_jobportal_apply_overrides.isblocked`)
2. Offer-stage lock (`offermade|accepted|rejected`)
3. No-show lock (any schedulable stage event with `noshow`, policy-controlled)

Controls:

- Manager manual block + expiry + reason
- Manager override + expiry + reason
- Mark no-show round as `excused`

Settings-driven policy (`settings.php` + `local_jobportal_get_student_job_access_policy()`):

- feed mode
- require approved resume
- block on no-show
- max active applications
- weekly limit
- cooldown after repeated not shortlisted

## Resume Review Model

Tables:

- `local_jobportal_profiles`
- `local_jobportal_resume_assignments`
- `local_jobportal_resume_review_hist`

Key idea:

- Assignment decisions are tied to resume signature/version.
- Profile-level status is recomputed from assignment summary.

## Navigation Rendering

`local_jobportal_render_navigation()` in `locallib.php` composes role-aware quick links.

Important behavior:

- Managers do not get student-only links (`My Applications`, `My Profile`) in nav.

## Frontend/JS Pattern

- CSS is loaded via `local_jobportal_require_styles()`.
- JS behavior uses AMD modules via `$PAGE->requires->js_call_amd(...)`.
- Keep behavioral JS in `amd/src/*.js` (not inline script blocks).

Current modules:

- `local_jobportal/index_filters`
- `local_jobportal/applications_ui`
- `local_jobportal/resume_preview`
- `local_jobportal/view_drive_state`

## Database Tables

Defined in `db/install.xml`:

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

## Upgrade and Versioning Rules

- Bump `version.php` on every schema/config migration.
- Add upgrade step in `db/upgrade.php` with monotonic version guard.
- Use XMLDB APIs and idempotent checks (`field_exists`, `table_exists`, etc.).
- For renamed settings keys, migrate old value then remove legacy key.

## Coding Guidelines for This Plugin

- Always set context + login on page controllers.
- Use Moodle params API correctly:
  - scalar: `optional_param(...)`
  - arrays: `optional_param_array(...)`
- Use named SQL params consistently (no mixed param styles).
- Prefer helper methods in `locallib.php` to avoid duplicated business logic.
- Preserve backward compatibility when changing lifecycle semantics.

## Common Extension Tasks

### Add a new admin setting

1. Add setting control in `settings.php`.
2. Add language strings.
3. Read setting in helper (usually `locallib.php`).
4. Add migration in `db/upgrade.php` if renaming/removing keys.

### Add a new schedule status

1. Update options in `local_jobportal_get_schedule_status_options()`.
2. Update validation in `applications.php`.
3. Update student/manager timeline rendering and strings.
4. Confirm apply-lock logic impact (if relevant).

### Add a new table field

1. Add in `db/install.xml`.
2. Add upgrade step in `db/upgrade.php`.
3. Bump `version.php`.
4. Update save/load paths and docs.

## Local Validation Checklist

Run syntax checks after edits:

```bash
php -l locallib.php
php -l settings.php
php -l applications.php
php -l application.php
php -l apply.php
php -l view.php
php -l db/upgrade.php
php -l lang/en/local_jobportal.php
```

Smoke-test flows:

1. Post/edit/clone job
2. Apply from student account
3. Applications list filters + bulk update
4. Single application page edits
5. Resume queue/review/update
6. Apply lock scenarios (offer, no-show, manual block/override)

## Deployment

Cluster deploy helper:

```bash
./scripts/deploy_cluster.sh --config ./scripts/deploy_cluster.env
```

Supports dry-run, maintenance toggle, upgrade, cache purge, and optional seeding.
