# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Is

`local_jobportal` is a Moodle local plugin (v1.8.7, requires Moodle 4.1+) that manages company-driven hiring workflows. Students browse jobs, apply, and track progress; managers post jobs, review applicants, and run resume-review workflows.

Plugin component name: `local_jobportal`
Installed path: `moodle/local/jobportal`

## Common Commands

**Syntax check after any PHP edit:**
```bash
php -l locallib.php
php -l settings.php
php -l applications.php
php -l db/upgrade.php
```

**Run Moodle upgrade (after schema changes):**
```bash
php admin/cli/upgrade.php --non-interactive
```

**Seed test data:**
```bash
php local/jobportal/cli/seed_test_data.php --companies=20 --jobspercompany=4 --prefix=QASeed
```

**Deploy to cluster:**
```bash
./scripts/deploy_cluster.sh --config ./scripts/deploy_cluster.env
# Options: --dry-run, --with-maintenance, --skip-upgrade, --seed, --skip-purge
```

**Rebuild AMD JS modules** (run from Moodle root, requires Grunt):
```bash
grunt amd --root=local/jobportal
```

## Architecture

### Request Flow

Every page controller (`*.php`) follows this pattern:
1. `require_login()` + set `$PAGE->context` (always system context)
2. `require_capability()` check
3. Call helpers from `locallib.php` for data/business logic
4. Output via Moodle's `$OUTPUT` APIs

### Key Files

- **`locallib.php`** — all business logic helpers. Add shared logic here, never duplicate across controllers.
- **`lib.php`** — Moodle hook callbacks (only `pluginfile` for file serving: `company_logo` and `profile_resume` file areas).
- **`settings.php`** — admin settings (preset filters, offer messages, student job access policy).
- **`db/install.xml`** — authoritative schema definition.
- **`db/upgrade.php`** — monotonic version-gated migration steps.
- **`db/access.php`** — capability definitions (all at `CONTEXT_SYSTEM`).
- **`partials/`** — reusable PHP view fragments included by page controllers.
- **`amd/src/`** — AMD JS modules (source); compiled output goes to `amd/build/`.

### Core Domain Model

**Companies → Jobs → Applications** is the main hierarchy.

**Job drive state** (separate from legacy active/inactive):
`applicationsopen` | `applicationsclosed` | `selectioninprogress` | `completed` | `archived` | `onhold` | `cancelled`

**Application lifecycle** has two parallel fields:
- `shortliststatus`: `pending` → `internalshortlisted` | `shortlisted` | `notshortlisted`
- `status` (post-shortlist): `testscheduled` → `interviewscheduled` → `offermade` → `accepted` | `rejected`

**Stage events** (`local_jobportal_appstage_events`) record the timeline with schedule status (`scheduled`, `rescheduled`, `completed`, `cancelled`, `noshow`, `excused`) and round outcome (`pending`, `cleared`, `notcleared`). Outcome must remain `pending` unless status is `completed`.

**Apply lock** (resolved by `local_jobportal_get_student_apply_lock_info()` in `locallib.php`):
1. Manual block (`local_jobportal_apply_overrides.isblocked`) — highest precedence
2. Offer-stage lock (any application at `offermade|accepted|rejected`)
3. No-show lock (policy-controlled)

**Resume review** links profile records, assignments, and history; assignment decisions are tied to resume signature/version.

### Frontend JS

Load CSS via `local_jobportal_require_styles()`. Load JS via `$PAGE->requires->js_call_amd(...)`. Do not use inline script blocks — keep all behaviour in `amd/src/`.

Current AMD modules: `index_filters`, `applications_ui`, `resume_preview`, `view_drive_state`.

## Coding Rules

- Always set context and require login at the top of every page controller.
- Use `optional_param()` for scalars, `optional_param_array()` for arrays. Never mix positional and named SQL params — use named params throughout.
- All shared business logic belongs in `locallib.php`.
- Every schema change requires: update `db/install.xml` + add upgrade step in `db/upgrade.php` + bump `version.php`.
- When renaming admin settings keys, migrate the old value in `db/upgrade.php` then delete the legacy key.
- Legacy done stages (`testdone`, `interviewdone`) exist for backward compatibility only — do not add new logic against them.

## Capabilities Quick Reference

| Capability | Default roles |
|---|---|
| `viewjobs` | user, student, teacher, editingteacher, manager |
| `apply` | user, student |
| `postjobs` | manager |
| `managejobs` | manager |
| `viewapplications` | manager |
| `managecompanyprofile` | manager |
| `reviewresumes` | manager, editingteacher |
| `assignresumereviewers` | manager |
