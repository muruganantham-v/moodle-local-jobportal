# Job Portal Manager Guide

This guide is for managers/reviewers who manage companies, jobs, applications, and resume reviews.

## Access and Permissions

Your role should have the relevant capabilities:

- `local/jobportal:postjobs`
- `local/jobportal:managejobs`
- `local/jobportal:viewapplications`
- `local/jobportal:managecompanyprofile`
- `local/jobportal:reviewresumes` (for reviewer actions)
- `local/jobportal:assignresumereviewers` (for assigning reviewers)

If you see a "nopermissions" error, ask the Moodle admin to update role permissions.

## Navigation Map (Manager)

Use Job Portal quick navigation:

- **All Jobs**: job listing and entry point
- **Post a Job**: create new job
- **Manager Dashboard**: funnel, stale pipeline, company analytics
- **Job Posts Dashboard**: per-job health and conversion metrics
- **Manage Stages**: configure recruitment stages and internal-only flags
- **Manage Companies**: create/update company profiles
- **Resume Queue**: resume review queue (assigners)
- **My Resume Reviews**: assigned reviews (reviewers)

## Typical Workflow

1. **Create company profile** (new client/company)
   - Go to **Manage Companies**.
   - Add company name, logo, website, and description.

2. **Create job post**
   - Go to **Post a Job**.
   - Select company from autocomplete.
   - Fill title, description, location, type, requirements, deadline, status.
   - Configure salary using one of:
     - **Fixed**: one amount and period (annual/monthly)
     - **Range**: min/max and period (annual/monthly)
     - **Progressive**: stage-based lines like `Initial|25000|monthly|First 3 months`
     - **Custom / Text**: free-text salary note when structure is not possible

3. **Clone existing job post (new feature)**
   - Open job details and click **Clone Job**, or use Job Posts Dashboard action.
   - Edit copied details as needed and save.
   - Clone creates a **new** job record (it does not copy applications/history).

4. **Use All Jobs (manager filters)**
   - Filter by company, status, job type, listed/deadline ranges, has applications, and stale days.
   - Sort by listed date, deadline, last updated, applications, shortlisted, offer conversion, or days since last application.
   - Use column picker to show/hide table columns.
   - Use bulk actions to open/close jobs, extend deadlines, or clone in batch.

5. **Manage applications**
   - Open job -> **View Applications**.
   - Review applicant cards/table, resume preview/download, notes, and timeline.
   - Update:
     - **Shortlist status**: Pending / Internal Shortlisted / Shortlisted / Not Shortlisted
     - **Post-shortlisting stage** (for shortlisted only): test/interview/offer stages
   - Use bulk actions for large applicant batches.
   - Export applicants with **Export XLS**.

6. **Resume review workflow**
   - **Resume Queue** shows submitted profiles.
   - Open review -> assign reviewers (if you have assign capability).
   - Reviewers submit decision, rating, and feedback in **My Resume Reviews** or Review Center.
   - Resume review history is tracked per profile/resume version.

## Stage and Visibility Rules

- Only shortlisted candidates can move through post-shortlisting stages.
- Stages can be marked **Internal only** in **Manage Stages**.
  - Internal stages are visible to managers.
  - Students do not see internal stages in their timeline.

## Important Operational Rules

- You can delete a job only when it has **zero applications**.
- Application/queue pages are paginated.
- Date format in plugin UI is `dd/mm/yyyy` (or `dd/mm/yyyy HH:MM` where needed).

## Troubleshooting

- **Capability not found during upgrade**
  - Verify capability exists in `db/access.php`.
  - Ensure plugin version is bumped and run Moodle upgrade.

- **Invalid query params error**
  - Usually due to SQL placeholder mismatch in custom query edits.
  - Check named placeholders and params array counts.

- **Student cannot apply**
  - Confirm student has uploaded resume in profile and has `local/jobportal:apply`.
