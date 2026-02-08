# Moodle Job Portal Plugin

A comprehensive job portal plugin for Moodle LMS that allows students to browse job opportunities, apply for positions, and manage their applications. Administrators can post jobs and manage applications.

## Role-Specific Guides

- Managers/Reviewers: `README_MANAGERS.md`
- Students: `README_STUDENTS.md`

## Features

### For Students
- Browse available job opportunities
- Search and filter jobs
- View detailed job descriptions
- Apply for jobs using resume from profile
- Track application status
- Create and manage professional profile
- View resume review status, feedback, and rating from managers
- View application history

### For Administrators/Managers
- Create and manage company profiles
- Post new job opportunities
- Edit and manage existing jobs
- View and manage job applications
- Update shortlist status (pending/internal shortlisted/shortlisted/not shortlisted)
- Update post-shortlisting stages (test/interview/offer progression)
- Mark recruitment stages as internal-only (manager-visible, hidden from students)
- Configurable recruitment stage pipeline (supports adding stages like test scheduled/test done)
- Manager dashboard with funnel conversion, stale pipeline alerts, and per-company analytics
- Separate Job Posts dashboard for posting health, stale jobs, and conversion tracking
- Advanced All Jobs filters, sorting, column picker, and bulk actions for large lists
- Add recruiter notes/comments per application
- Review student resumes with status, feedback, and rating
- Separate resume-review workflow with assignment to multiple reviewers
- Resume Queue page for managers (status filters + reviewer progress)
- My Resume Reviews page for reviewers (assigned inbox)
- Multi-reviewer approval workflow (all assigned reviewers must approve)
- Track resume review history (submission, re-submission, manager updates)
- Track interview schedule and completion dates
- Export applicants to XLS for shortlisting
- Set application deadlines

### General Features
- Role-based access control
- File upload support for resumes
- Professional UI with Bootstrap styling
- Mobile-responsive design
- Database-driven architecture

## Installation

1. **Download the Plugin**
   - Extract the plugin files to your Moodle installation

2. **Copy to Moodle Directory**
   ```bash
   cp -r moodle-job-portal /path/to/moodle/local/jobportal
   ```

3. **Install via Moodle Admin**
   - Log in to Moodle as an administrator
   - Navigate to: Site administration → Notifications
   - Moodle will detect the new plugin
   - Click "Upgrade Moodle database now"
   - The plugin will create the necessary database tables:
     - `local_jobportal_jobs`
     - `local_jobportal_applications`
     - `local_jobportal_profiles`

4. **Verify Installation**
   - Go to: Site administration → Plugins → Local plugins
   - You should see "Job Portal" listed

## Production Deployment (Cluster)

For a two-node Moodle web cluster, use:

1. Copy `scripts/deploy_cluster.env.example` to `scripts/deploy_cluster.env`
2. Set your server hostnames, deploy user, and Moodle path
3. Run:
   ```bash
   ./scripts/deploy_cluster.sh --config ./scripts/deploy_cluster.env
   ```
   If you want maintenance mode during deploy:
   ```bash
   ./scripts/deploy_cluster.sh --config ./scripts/deploy_cluster.env --with-maintenance
   ```
   To seed test companies/jobs on primary right after deploy:
   ```bash
   ./scripts/deploy_cluster.sh --config ./scripts/deploy_cluster.env --seed --seed-args "--prefix=QASeed --companies=20 --jobspercompany=4"
   ```

What it does:
- Optionally enables maintenance mode (primary node, with `--with-maintenance`)
- Backs up current plugin on each node
- Rsyncs plugin files to both web nodes
- Runs Moodle CLI upgrade once (primary node)
- Optionally runs seed script on primary node (`--seed`)
- Purges caches on all nodes
- Disables maintenance mode (when enabled)

## Configuration

### Setting Up Permissions

The plugin defines the following capabilities:

- `local/jobportal:viewjobs` - View job listings (Students, Teachers, Managers)
- `local/jobportal:apply` - Apply for jobs (Students only)
- `local/jobportal:postjobs` - Post new jobs (Managers only)
- `local/jobportal:managejobs` - Manage job postings (Managers only)
- `local/jobportal:viewapplications` - View job applications (Managers only)
- `local/jobportal:managecompanyprofile` - Create and manage company profiles (Managers only)
- `local/jobportal:reviewresumes` - Review student resumes (Managers, Editing Teachers by default)
- `local/jobportal:assignresumereviewers` - Assign reviewers (Managers only)

By default:
- **Students** can view and apply for jobs
- **Managers** can post jobs, manage postings, and view applications

To customize permissions:
1. Go to: Site administration → Users → Permissions → Define roles
2. Select the role you want to modify
3. Filter for "jobportal" capabilities
4. Adjust permissions as needed

## Usage

### Accessing the Job Portal

Add a link to the job portal in your Moodle navigation or provide users with the direct URL:
```
https://yourmoodle.com/local/jobportal/
```

### Seed Test Data (CLI)

To quickly create test companies and job postings:

```bash
php local/jobportal/cli/seed_test_data.php
```

Useful options:

```bash
php local/jobportal/cli/seed_test_data.php --companies=20 --jobspercompany=5
php local/jobportal/cli/seed_test_data.php --managerusername=manager --prefix=QASeed --daysback=45
```

Notes:
- Generated companies use a rotating list of Indian embedded/ER&D company names with your prefix appended.
- Generated job locations are India-based cities (plus Remote-India).

### For Students

1. **Browse Jobs**
   - Navigate to the Job Portal
   - View all available job listings
   - Use the search feature to filter jobs

2. **View Job Details**
   - Click on any job to see full details
   - Review job description, requirements, and deadline

3. **Create/Update Profile**
   - Click "My Profile" button
   - Fill in your skills, experience, and education
   - Upload your resume (PDF, DOC, or DOCX format, max 5MB)
   - Check resume review status/feedback from managers
   - Add portfolio URL if applicable

4. **Apply for a Job**
   - Click "Apply Now" on the job detail page
   - Ensure your profile has an uploaded resume
   - Submit the application

5. **Track Applications**
   - Click "My Applications" to view all submitted applications
   - Check shortlist status and post-shortlisting stage

### For Administrators/Managers

1. **Post a New Job**
   - If the company is new, first create it in **Manage Companies**
   - Click "Post a Job" button
   - Fill in job details:
     - Job Title
     - Company (selected from existing company profiles)
     - Description
     - Location
     - Job Type (Full-time, Part-time, Internship, Contract, Freelance)
     - Salary Type:
       - Fixed: one amount (annual/monthly)
       - Range: min/max amount (annual/monthly)
       - Progressive: multiple stages (for example initial stipend, later CTC)
       - Custom/Text: free-text salary note
     - Salary Currency (for structured salary types)
     - Requirements
     - Application Deadline (optional)
   - Set job status (Active/Inactive)
   - Click "Save changes"

2. **Edit Existing Jobs**
   - Click on a job posting
   - Click "Edit Job" button
   - Modify job details
   - Save changes

3. **Manage Applications**
   - Click on a job posting
   - Click "View Applications" button
   - Review applicant details, resume links, and notes
   - Review resume quality and update resume status/rating/feedback
   - Bulk-update shortlist status and post-shortlisting stages

4. **Manage Resume Reviews (Independent of Job Applications)**
   - Open **Resume Queue** to see submitted resumes and current review progress
   - Open a student’s **Resume Review Center**
   - Assign one or multiple reviewers
   - Assigned reviewers submit decisions; final approval requires all assigned reviewers to approve
   - Reviewers can use **My Resume Reviews** to process assigned resumes

## Database Schema

### local_jobportal_jobs
Stores job postings
- id, title, description, company, location, jobtype
- salary (display text), salarymodel, salarycurrency, salaryperiod
- salarymin, salarymax, salaryminannual, salarymaxannual
- deadline, requirements, status, postedby
- timecreated, timemodified

### local_jobportal_job_salary_stages
Stores progressive salary stages for jobs
- id, jobid, stagelabel, amount, period, conditiontext
- sortorder, timecreated, timemodified

### local_jobportal_applications
Stores student applications
- id, jobid, userid, coverletter, resume
- shortliststatus (pending/shortlisted/notshortlisted)
- status (post-shortlisting stage: testscheduled/testdone/interviewscheduled/offermade/accepted/rejected)
- timecreated, timemodified

### local_jobportal_profiles
Stores student profiles
- id, userid, skills, experience, education
- resume, portfolio
- resumestatus, resumerating, resumefeedback
- resumereviewedby, resumereviewedat
- resumeapprovalmode (anyone/allrequired)
- timecreated, timemodified

### local_jobportal_resume_assignments
Stores reviewer assignments/outcomes per resume version
- id, profileid, resumesignature, reviewerid, assignedby
- status (assigned/inreview/approved/needsrework)
- rating, feedback
- timeassigned, timereviewed, timecreated, timemodified

## File Structure

```
local/jobportal/
├── version.php              # Plugin metadata
├── db/
│   ├── install.xml         # Database schema
│   └── access.php          # Capability definitions
├── lang/
│   └── en/
│       └── local_jobportal.php  # Language strings
├── index.php               # Job listings page
├── view.php                # Job detail page
├── apply.php               # Job application form
├── post.php                # Job posting form
├── myapplications.php      # Student applications page
├── profile.php             # Student profile page
├── resume_queue.php        # Manager resume review queue
├── myreviews.php           # Reviewer inbox
├── resume_review.php       # Detailed resume review center
├── applications.php        # Application management page
└── README.md               # This file
```

## Requirements

- Moodle 4.1 or higher
- PHP 7.4 or higher
- MySQL 5.7 or higher / PostgreSQL 9.6 or higher

## Customization

### Styling
The plugin uses Bootstrap classes (included in Moodle). You can customize the appearance by:
1. Adding custom CSS in your theme
2. Modifying the HTML output in the PHP files

### Adding Custom Fields
To add custom fields to jobs or applications:
1. Modify `db/install.xml` to add new database fields
2. Update the corresponding form classes
3. Modify display pages to show the new fields
4. Create an upgrade script in `db/upgrade.php`

### Email Notifications
To add email notifications when applications are submitted or status changes:
1. Create email templates in `lang/en/local_jobportal.php`
2. Add email sending logic using Moodle's `email_to_user()` function

## Support and Contribution

### Reporting Issues
If you encounter any bugs or have feature requests, please document them with:
- Moodle version
- Plugin version
- Steps to reproduce the issue
- Expected vs actual behavior

### Contributing
Contributions are welcome! Areas for enhancement:
- Email notifications
- Advanced search filters
- Job categories
- Company profiles
- Interview scheduling
- Analytics dashboard
- Export applications to CSV
- Resume parsing

## License

This plugin is licensed under the GNU General Public License v3.0, the same license as Moodle.

## Credits

Developed for educational institutions to connect students with employment opportunities.

## Changelog

### Version 1.0 (2026-01-31)
- Initial release
- Core job posting functionality
- Student application system
- Profile management
- Application tracking
- Admin management interface
