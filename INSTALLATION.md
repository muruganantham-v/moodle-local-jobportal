# Installation and Setup
## Moodle Job Portal (`local_jobportal`)

This guide covers fresh install, upgrade, and cluster deployment.

## Prerequisites

- Moodle 4.1+
- Admin access to Moodle
- Shell/FTP access to Moodle codebase
- DB user with schema update privileges

## 1) Install Plugin Files

Place plugin at:

- `moodle/local/jobportal`

Example:

```bash
cd /path/to/moodle/local
cp -R /path/to/jobportal ./jobportal
```

## 2) Run Moodle Upgrade

From UI:

- Site administration -> Notifications

Or CLI:

```bash
php admin/cli/upgrade.php --non-interactive
```

## 3) Verify Installation

- Plugin listed under Local plugins.
- URL opens: `/local/jobportal/index.php`
- DB tables created (see `db/install.xml`), including:
  - jobs, applications, profiles
  - companies, stages, stage events, notes
  - apply overrides
  - resume review history + assignments

## 4) Configure Permissions

Capabilities are in `db/access.php`.

Common defaults:

- Students: view/apply
- Managers: post/manage/view applications/company profile
- Editing teachers: review resumes (default)

Review/adjust at:

- Site administration -> Users -> Permissions -> Define roles

## 5) Configure Plugin Settings

Location:

- Site administration -> Plugins -> Local plugins -> Job Portal

Settings sections:

- Preset filters
- Student offer messages
- Student Job Access Policy

Key policy settings:

- Jobs feed mode (`Only open drives` / `All jobs`)
- Require approved resume
- Block applying on any no-show
- Max active applications
- Weekly application limit
- Not-shortlisted cooldown

## 6) Optional: Seed Test Data

CLI:

```bash
php local/jobportal/cli/seed_test_data.php
```

Example:

```bash
php local/jobportal/cli/seed_test_data.php --companies=20 --jobspercompany=5 --prefix=QASeed
```

## 7) Optional: Cluster Deployment

Use included script:

```bash
./scripts/deploy_cluster.sh --config ./scripts/deploy_cluster.env
```

Setup:

1. Copy `scripts/deploy_cluster.env.example` -> `scripts/deploy_cluster.env`
2. Set `SERVERS`, `PRIMARY_SERVER`, `DEPLOY_USER`, `REMOTE_MOODLE_DIR`

Useful options:

- `--dry-run`
- `--with-maintenance` (maintenance is skipped by default)
- `--skip-upgrade`
- `--seed`
- `--seed-args "..."`
- `--skip-purge`

## Upgrade Notes (Existing Sites)

For each release:

1. Deploy updated files.
2. Run Moodle upgrade.
3. Purge caches.
4. Validate key workflows:
   - post/edit/view jobs
   - apply flow
   - applications table + application detail page
   - resume queue/review

If a release renamed settings keys, migration is handled in `db/upgrade.php`.

## Recommended Post-Install Checks

- Open `All Jobs`, `Post a Job`, `View Applications`, `My Profile`.
- Confirm role-based navigation is correct.
- Confirm manager-only links are hidden for students.
- Confirm student apply lock behavior (offer/no-show/manual block) is correct.

## Troubleshooting

### `nopermissions` errors

- Fix role capabilities in Moodle role settings.

### Upgrade errors (coding/db)

- Ensure `version.php`, `db/install.xml`, and `db/upgrade.php` are in sync.
- Re-run upgrade after fixes.

### SQL placeholder errors

- Check named placeholders and params count/types in query code.

### Apply blocked unexpectedly

- Check:
  - policy settings
  - offer-stage lock
  - no-show lock
  - manual block/override in application detail

## Useful Commands

Syntax checks:

```bash
php -l index.php
php -l applications.php
php -l application.php
php -l locallib.php
php -l settings.php
php -l db/upgrade.php
```
