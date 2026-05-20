# Job Portal Student Guide

This guide is for students using Moodle Job Portal (`local_jobportal`).

## Required Capabilities

Your role should include:

- `local/jobportal:viewjobs`
- `local/jobportal:apply`

If access/apply is missing, contact your Moodle admin.

## Main Pages

- `All Jobs` (`index.php`)
- `My Applications` (`myapplications.php`)
- `My Profile` (`profile.php`)

## Step 1: Complete Profile (Required)

Open `My Profile` and update:

- Skills
- Experience
- Education
- Portfolio URL (optional)
- Resume upload

Important:

- Apply flow uses resume from profile.
- You cannot apply without an uploaded resume.

## Step 2: Browse and Apply

1. Open `All Jobs`.
2. Search/filter jobs.
3. Open a job detail page and review all details.
4. Click `Apply Now`.

## Step 3: Track Progress

In `My Applications`, you can track:

- Job and company details
- Applied date
- Shortlist status
- Stage timeline (upcoming/history)
- Scheduled round details when shared

Some timeline stages may be internal-only and hidden from student view.

## Offer Highlight

When your application reaches an offer state, a highlighted banner appears in navigation context.

Possible offer states:

- `Offer Made`
- `Offer Accepted`
- `Offer Rejected`

## Resume Review Visibility

In `My Profile`, you can see:

- Resume status
- Rating
- Feedback
- Review history

Reviewer identity is not shown to students.

## Why Apply Can Be Blocked

Depending on site policy, applying may be blocked when:

- Resume approval is required and your resume is not approved
- You reached active application limit
- You reached weekly application limit
- You are in not-shortlisted cooldown
- One application is at offer state (`Offer Made/Accepted/Rejected`)
- Any test/interview round is marked `No Show`
- Manager placed a manual apply block

If no-show was a valid exception, manager can mark that round as `Excused`.

## Troubleshooting

- `Apply` button missing:
  - Job may be closed/expired, or you already applied.
- Cannot apply:
  - Check profile resume and ask manager/admin to verify policy/lock state.
- Status not changing:
  - Recruitment updates are manager-driven.
